<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeDocumentRequest;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Department;
use App\Models\DocumentScan;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeEndorsement;
use App\Models\Position;
use App\Services\DataAccessLogger;
use App\Services\DocumentScanner;
use App\Services\EmployeeService;
use App\Services\EndorsementService;
use App\Services\LicenseVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Module 1 — Employee Information Management (Inertia entry point).
 * Mirrors App\Http\Controllers\Api\EmployeeController via EmployeeService.
 */
class EmployeeController extends Controller
{
    private const SORTABLE = [
        'employee_number', 'last_name', 'first_name',
        'date_hired', 'employment_status', 'status',
    ];

    public function __construct(
        private readonly EmployeeService $employees,
        private readonly EndorsementService $endorsements,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Employee::class);

        $filters = $request->only([
            'search', 'department_id', 'employment_status', 'status',
            'employment_category', 'client_id',

            // Set by the dashboard tiles, and not by any dropdown here — see
            // Employee::scopeFilter and the chips on the filter row.
            'hired_within', 'without_documents',
        ]);

        $sort = in_array($request->query('sort'), self::SORTABLE, true)
            ? $request->query('sort')
            : 'last_name';
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';

        $query = $this->employees->scopedQuery($request->user())->filter($filters);

        $employees = (clone $query)
            ->orderBy($sort, $direction)
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('HR/Employees/Index', [
            // Infinite scroll, not page buttons: on a partial reload (the
            // WhenVisible sentinel asking for the next `page`) the new rows
            // are appended to `data` instead of replacing it. A full visit —
            // first load, or a filter/sort change — ignores this and renders
            // fresh, so switching filters correctly starts back at page 1
            // instead of showing a merged mix of two result sets.
            'employees' => Inertia::merge(tap(
                EmployeeResource::collection($employees),
                fn ($collection) => $collection->collection->each->masked(),
            ))->append('data', 'id'),
            'statistics' => $this->employees->statistics($this->employees->scopedQuery($request->user())),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            // Counted, not just listed: "how many are with this client" is the
            // question the agency is actually asked, and putting it in the
            // filter saves opening five screens to answer it.
            'clients' => Client::withCount(['employees' => fn ($query) => $query->where('status', 'active')])
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(fn (Client $client) => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'code' => $client->code,
                    'employees_count' => $client->employees_count,
                ]),
            'categories' => Employee::CATEGORIES,
            'filters' => $filters,
            'sort' => ['key' => $sort, 'direction' => $direction],
            'can' => [
                'create' => $request->user()->can('create', Employee::class),
                'fileDocuments' => $request->user()->can('fileDocumentBatch', Employee::class),
            ],
        ]);
    }

    /**
     * The employee form — reached by accepting a Core 1 endorsement.
     *
     * There is no longer a free-standing "add anybody" door. PrimePower does
     * not hire into this system directly: Core 1 recruits, sends the hire
     * over, and somebody here approves it. Leaving the bare form reachable
     * would have made that rule cosmetic — the endorsement queue would be one
     * way in among two, and the second one keeps no record of who was accepted
     * or why.
     *
     * Bulk import is the exception and stays open, because it is a different
     * act: digitising a workforce that already exists is not hiring, and there
     * is no endorsement for somebody who has worked here for six years.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        Gate::authorize('create', Employee::class);

        /*
         * Two ways in: approving a Core 1 endorsement, or adding somebody
         * directly. A direct add has no endorsement to carry the decision, so
         * the form asks for the reason instead and `store()` records it.
         */
        if (! $request->filled('endorsement')) {
            $prefill = [];
            if ($request->filled('client_id')) {
                $prefill['client_id'] = (string) $request->input('client_id');
                $prefill['employment_category'] = Employee::CATEGORY_EXTERNAL;
                $client = Client::find($request->input('client_id'));
                if ($client?->wage_region) {
                    $prefill['wage_region'] = $client->wage_region;
                }
            }

            return Inertia::render('HR/Employees/Create', [
                'options' => $this->formOptions(),
                'endorsement' => null,
                'prefill' => $prefill,
                'can' => ['scanForm' => app(DocumentScanner::class)->isEnabled()],
            ]);
        }

        $endorsement = EmployeeEndorsement::find($request->integer('endorsement'));

        if (! $endorsement) {
            return redirect()
                ->route('hr.endorsements.index')
                ->with('info', 'That endorsement no longer exists.');
        }

        // Re-asked at the form rather than trusted from the link: a decision
        // already taken must not be re-taken, and the ability says so.
        Gate::authorize('decide', $endorsement);

        return Inertia::render('HR/Employees/Create', [
            'options' => $this->formOptions(),

            /*
             * Read back from the row server-side. The link carries an id and
             * nothing else about the person — the same shape as `scan_id` on a
             * document upload, and for the same reason: a form must not be
             * able to assert what it was given.
             */
            'endorsement' => [
                'id' => $endorsement->id,
                'reference' => $endorsement->reference,
                'source' => $endorsement->source,
                'full_name' => $endorsement->fullName(),
                'position_title' => $endorsement->position_title,
                'client_name' => $endorsement->client_name,
            ],
            'prefill' => $this->endorsements->formDefaults($endorsement),

            'can' => [
                // A dark feature is not a broken one: with no driver
                // configured the button is never drawn and the endpoint 404s,
                // exactly as on the document scanner.
                'scanForm' => app(DocumentScanner::class)->isEnabled(),
            ],
        ]);
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        /*
         * The endorsement being answered, re-read from the database and
         * re-authorized. The form posts an id and nothing else about it, so
         * what is approved is what this system stored at receipt rather than
         * whatever the browser sends back — the same rule the document
         * scanner's `scan_id` follows.
         *
         * Resolved *before* the employee is created so a stale or already
         * decided endorsement fails here, rather than after a person has been
         * put on the payroll with nothing to attach them to.
         */
        if (! $request->filled('endorsement_id')) {
            return $this->storeDirectHire($request);
        }

        $endorsement = EmployeeEndorsement::find($request->integer('endorsement_id'));

        if (! $endorsement) {
            return redirect()
                ->route('hr.endorsements.index')
                ->with('info', 'That endorsement no longer exists.');
        }

        Gate::authorize('decide', $endorsement);

        $employee = $this->employees->create(
            $request->validated(),
            $request->file('photo'),
        );

        $this->endorsements->approve($endorsement, $employee, $request->user());

        $message = "Employee {$employee->employee_number} created from endorsement {$endorsement->reference}.";

        if ($this->employees->generatedPassword) {
            // The username too, since that is what the employee signs in with.
            $username = $employee->user?->username;
            $message .= " Login: {$username} / temporary password: {$this->employees->generatedPassword}";
        }

        return redirect()
            ->route('hr.employees.show', $employee)
            ->with('success', $message);
    }

    /**
     * The signed-in user's own employee 201 profile and records.
     */
    public function myProfile(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $employee = $user?->employee;

        if (! $employee && $user) {
            $emails = array_values(array_filter([
                $user->email,
                $user->otp_email,
                $user->username,
            ]));

            $matchedEmployee = null;
            if (! empty($emails)) {
                $matchedEmployee = Employee::whereNull('user_id')
                    ->whereIn('email', $emails)
                    ->first();
            }

            if ($matchedEmployee) {
                $matchedEmployee->update(['user_id' => $user->id]);
                $employee = $matchedEmployee;
            }
        }

        if (! $employee) {
            return redirect()
                ->route('hr.employees.index')
                ->with('info', 'No employee 201 record is linked to this account.');
        }

        return $this->show($request, $employee, isMyProfile: true);
    }

    public function show(Request $request, Employee $employee, bool $isMyProfile = false): Response
    {
        Gate::authorize('view', $employee);

        // Somebody else opening a 201 file is a read of personal data and
        // is recorded like a document preview. A person opening their own
        // record is not a finding, and logging it would bury the ones that are.
        if ($employee->user_id !== $request->user()->id) {
            app(DataAccessLogger::class)->accessed($employee, 'view', [
                'employee' => $employee->full_name,
            ]);
        }

        $employee->load([
            'department:id,name',
            'client:id,code,name,wage_region',
            'position:id,title,department_id',
            'supervisor:id,first_name,middle_name,last_name,suffix',
            'documents.uploader:id,name',
            'educations',
            'trainings',
            'skills',
        ]);

        return Inertia::render('HR/Employees/Show', [
            'employee' => (new EmployeeResource($employee))->masked(),
            'isMyProfile' => $isMyProfile || $employee->user_id === $request->user()?->id,

            /*
             * The education ladder and the proficiency grades, sent as the
             * config states them rather than restated in the component. The
             * order of the levels is what makes "highest attainment" mean
             * anything, and a second copy in JavaScript would eventually
             * disagree with the one the server ranks by.
             */
            'educationLevels' => config('qualifications.education_levels'),
            'levelsWithCourse' => array_values(config('qualifications.levels_with_course')),
            'proficiencyLevels' => config('qualifications.proficiency_levels'),
            // Which document types the upload form should offer an expiry date
            // for. Read from config rather than hard-coded in the component so
            // the form and CredentialExpiryScanner cannot disagree about which
            // documents are the ones that lapse.
            'expiringTypes' => array_values(config('credentials.expiring_types', [])),
            // The one list of document types. The upload form used to keep its
            // own copy, which is a second place for `psa` to be forgotten.
            'documentTypes' => EmployeeDocument::TYPES,

            /*
             * What can honestly be said about the licence.
             *
             * Split in two on purpose, and labelled as such on the screen:
             * `checks` is structure this system verified itself, `verification`
             * is a person's answer from the LTMS portal. LTO publishes no API
             * to call, so the second half cannot be automated — and a green
             * tick nobody can account for would be worse than none.
             */
            'licence' => [
                'checks' => app(LicenseVerifier::class)->check($employee),
                'verification' => array_merge(
                    app(LicenseVerifier::class)->verificationState($employee),
                    [
                        'note' => $employee->license_verification_note,
                        'at' => $employee->license_verified_at?->toDateString(),
                        'by' => $employee->licenseVerifiedBy?->name,
                    ],
                ),
                'dl_codes' => collect(app(LicenseVerifier::class)->dlCodes($employee))
                    ->map(fn (string $code) => [
                        'code' => $code,
                        'label' => config("licenses.dl_codes.{$code}"),
                    ])
                    ->all(),
                'conditions' => collect(explode(',', (string) $employee->license_conditions))
                    ->map(fn (string $c) => trim($c))
                    ->filter()
                    ->map(fn (string $code) => [
                        'code' => $code,
                        'label' => config("licenses.conditions.{$code}"),
                    ])
                    ->values()
                    ->all(),
                'ltms_url' => config('licenses.ltms_url'),
            ],

            'subordinates' => $employee->subordinates()
                ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'position_id'])
                ->map(fn (Employee $sub) => [
                    'id' => $sub->id,
                    'full_name' => $sub->full_name,
                ]),
            'can' => [
                'update' => $request->user()->can('update', $employee),
                'delete' => $request->user()->can('delete', $employee),
                'manageDocuments' => $request->user()->can('manageDocuments', $employee),
                'viewSensitive' => $request->user()->can('viewSensitive', $employee),
                // Without a configured API key the Scan button is not drawn
                // at all, rather than offered and then failing.
                'scanDocuments' => app(DocumentScanner::class)->isEnabled()
                    && $request->user()->can('manageDocuments', $employee),
            ],
        ]);
    }

    public function edit(Employee $employee, DataAccessLogger $access): Response
    {
        Gate::authorize('update', $employee);

        // The edit form carries every number in full, so opening it is a read
        // of all of them at once.
        $access->accessed($employee, 'edit_form', ['employee' => $employee->full_name]);

        return Inertia::render('HR/Employees/Edit', [
            'employee' => new EmployeeResource($employee),
            'options' => $this->formOptions($employee->id),
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $this->employees->update($employee, $request->validated(), $request->file('photo'));

        return redirect()
            ->route('hr.employees.show', $employee)
            ->with('success', 'Employee record updated.');
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        Gate::authorize('delete', $employee);

        $this->employees->delete($employee);

        return redirect()
            ->route('hr.employees.index')
            ->with('success', "Employee {$employee->employee_number} archived.");
    }

    public function storeDocument(StoreEmployeeDocumentRequest $request, Employee $employee): RedirectResponse
    {
        $document = $this->employees->storeDocument(
            $employee,
            $request->validated(),
            $request->file('file'),
        );

        /*
         * Close the measurement, if this upload answers one.
         *
         * Scoped to the employee whose record this is, so a scan id from
         * somewhere else cannot attach a stranger's measurement to this
         * document. Nothing about the proposal is taken from the request —
         * only which row to complete.
         */
        if ($scanId = $request->integer('scan_id')) {
            DocumentScan::where('employee_id', $employee->id)
                ->whereNull('employee_document_id')
                ->find($scanId)
                ?->recordOutcome($document, $request->validated());
        }

        return back()->with('success', 'Document uploaded.');
    }

    /**
     * Reads a scanned document and proposes the fields, without saving
     * anything. Same gate as the upload it precedes — anyone who cannot file
     * a document has no business spending a request reading one.
     *
     * Returns JSON rather than an Inertia response: this fills a form the
     * user is still editing, so re-rendering the page would throw their
     * half-typed input away.
     */
    public function scanDocument(
        Request $request,
        Employee $employee,
        DocumentScanner $scanner,
    ): JsonResponse {
        Gate::authorize('manageDocuments', $employee);

        abort_unless($scanner->isEnabled(), 404);

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf'],
        ]);

        $started = microtime(true);
        $result = $scanner->scan($request->file('file'), $employee);
        $elapsed = (int) round((microtime(true) - $started) * 1000);

        /*
         * The proposal is recorded here, not when the upload succeeds.
         *
         * A scan the user then abandoned is a real outcome — usually the
         * reading was poor enough to start over — and counting only the scans
         * that ended in a filed document would flatter every figure on the
         * accuracy screen. The row is written now and completed later, if
         * there is a later.
         *
         * A failed call writes nothing: there is no proposal to measure, and
         * a driver that is down is already logged where every other failure
         * is.
         */
        $scan = $result === null ? null : DocumentScan::create([
            'employee_id' => $employee->id,
            'scanned_by' => $request->user()->id,
            'driver' => (string) config('scanner.driver'),
            /*
             * Every driver keeps its model under `scanner.{driver}.model`
             * except `anthropic`, which predates that shape and keeps it at
             * `scanner.model` — so that is the fallback rather than a special
             * case. Recorded per scan because Scanner Accuracy compares
             * readings across drivers, and a row with no model on it cannot
             * say which one produced the number.
             */
            /*
             * The model that answered, not the one in config — with a
             * fallback chain those are no longer the same thing, and Scanner
             * Accuracy compares readings *by model*. Recording the configured
             * one while a fallback did the work would blame a model that
             * never saw the document.
             */
            'model' => $scanner->modelUsed() ?? (string) config(
                'scanner.'.config('scanner.driver').'.model',
                config('scanner.model'),
            ),
            'duration_ms' => $elapsed,
            'proposed' => $result,
        ]);

        return response()->json([
            'scanned' => $result !== null,
            'fields' => $result,
            // Returned so the upload that follows can say which proposal it
            // is answering. It identifies a measurement, nothing more — the
            // values themselves are read back from the row server-side, never
            // from the form.
            'scan_id' => $scan?->id,
        ]);
    }

    /**
     * Reads a filled-in paper 201 form and proposes the employee record.
     *
     * The counterpart to the CSV import: a spreadsheet arrives in bulk, a
     * filing cabinet does not. Gated on `create` — proposing a record is a
     * step towards creating one, and nothing else about it is privileged.
     *
     * Nothing is written. The Create form is filled, HR corrects it, and
     * StoreEmployeeRequest validates the save exactly as it does a hand-typed
     * one — the same shape as the document scanner it shares a driver with.
     */
    public function scanEmployeeForm(Request $request, DocumentScanner $scanner): JsonResponse
    {
        Gate::authorize('create', Employee::class);

        abort_unless($scanner->isEnabled(), 404);

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $fields = $scanner->scanEmployeeForm($request->file('file'));

        return response()->json([
            'scanned' => $fields !== null,
            'fields' => $fields,
        ]);
    }

    /**
     * Streams a 201-file document from the private disk after an authorization
     * check — these are never reachable by direct URL.
     */
    public function downloadDocument(
        Employee $employee,
        EmployeeDocument $document,
        DataAccessLogger $access,
    ): StreamedResponse {
        Gate::authorize('view', $employee);

        abort_if($document->employee_id !== $employee->id, 404);

        $disk = Storage::disk(EmployeeService::DOCUMENT_DISK);

        abort_unless($disk->exists($document->file_path), 404);

        // Logged after the checks, so a refused attempt is not recorded as an
        // access — and before the stream, because a download that starts is a
        // copy on someone's machine whether or not it finishes.
        $access->accessed($document, 'download', [
            'employee' => $employee->full_name,
            'document' => $document->title,
        ]);

        return $disk->download($document->file_path, $document->file_name);
    }

    /**
     * The same file, served `inline` so the browser renders it instead of
     * saving it — a separate route rather than a query flag, so the download
     * link keeps forcing a download and neither can change the other by
     * accident. Behind the same `view` gate; these are still private files.
     */
    /**
     * One hidden number, in full, for the person who pressed Show.
     *
     * The screen only ever receives the last four characters; the full value
     * crosses to a browser here, one field at a time, and each crossing is
     * logged with the field named. Asked of the same gate that decides whether
     * the masked value is drawn at all, so Show can never reveal more than the
     * card already admitted existed.
     */
    public function reveal(Request $request, Employee $employee, DataAccessLogger $access): JsonResponse
    {
        $field = (string) $request->validate([
            'field' => ['required', Rule::in(Employee::MASKABLE)],
        ])['field'];

        Gate::authorize(
            in_array($field, Employee::MASKABLE_WITH_VIEW, true) ? 'view' : 'viewSensitive',
            $employee,
        );

        $access->accessed($employee, 'reveal', [
            'field' => $field,
            'employee' => $employee->full_name,
        ]);

        return response()
            ->json(['field' => $field, 'value' => $employee->{$field}])
            ->header('Cache-Control', 'no-store');
    }

    public function previewDocument(
        Employee $employee,
        EmployeeDocument $document,
        DataAccessLogger $access,
    ): StreamedResponse {
        Gate::authorize('view', $employee);

        abort_if($document->employee_id !== $employee->id, 404);

        $disk = Storage::disk(EmployeeService::DOCUMENT_DISK);

        abort_unless($disk->exists($document->file_path), 404);

        // Recorded separately from a download: reading an ID on screen and
        // taking a copy of it away are different acts, and the log should not
        // flatten them into one word.
        $access->accessed($document, 'preview', [
            'employee' => $employee->full_name,
            'document' => $document->title,
        ]);

        return $disk->response($document->file_path, $document->file_name, [
            // Belt and braces: the stored mime type is what the browser is
            // told to render, and nosniff stops it guessing something else
            // out of a file a user uploaded.
            'Content-Type' => $document->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroyDocument(Employee $employee, EmployeeDocument $document): RedirectResponse
    {
        Gate::authorize('manageDocuments', $employee);

        abort_if($document->employee_id !== $employee->id, 404);

        $this->employees->deleteDocument($document);

        return back()->with('success', 'Document deleted.');
    }

    /**
     * Records that somebody checked this licence on the LTMS portal.
     *
     * **LTO publishes no API an employer can call.** LTMS is citizen-facing —
     * a holder signs in to manage their own licence — and the commercial
     * "LTO verification APIs" that advertise otherwise are private wrappers
     * whose data source the agency does not vouch for. So this endpoint does
     * not verify anything itself; it records that a named person did, on a
     * date, and what the portal told them.
     *
     * That is deliberately a weaker claim than a green tick, and a much
     * stronger one than a green tick nobody can account for: the audit log
     * carries who said it, and the note carries the portal's own words —
     * "active", "suspended until March", and "no record found" are three
     * different answers and only one of them is good news.
     */
    public function verifyLicense(Request $request, Employee $employee): RedirectResponse
    {
        Gate::authorize('update', $employee);

        $validated = $request->validate([
            'license_verification_note' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'license_verification_note.required' => 'Say what the LTMS portal showed.',
        ]);

        // Auditable records the change, so who checked and when survives on
        // the row *and* in the log.
        $employee->update([
            'license_verified_at' => now(),
            'license_verified_by' => $request->user()->id,
            'license_verification_note' => $validated['license_verification_note'],
        ]);

        return back()->with('success', 'Licence check recorded.');
    }

    /**
     * Moves one employee to another position.
     *
     * Lives here, and is gated on `update` for *this employee*, because that
     * is what it is: a change to somebody's record. The Positions screen it is
     * reached from sits behind `manageOrganization`, which is the right gate
     * for *shaping* the org chart — deciding a title exists, or that it no
     * longer does. Filing a person against one is a different act, and the two
     * abilities happen to be held by the same roles today only because nobody
     * has yet needed them apart. Asking the wrong one would work until that
     * changed, and then quietly let the wrong person move somebody.
     *
     * The department follows the position rather than being sent separately.
     * A position belongs to a department, so a record filed under Operations
     * while holding a Finance title is not a state anybody chose — it is one
     * they would have had to be asked about twice to reach. The screen names
     * the department move before the click.
     */
    public function updatePosition(Request $request, Employee $employee): RedirectResponse
    {
        Gate::authorize('update', $employee);

        $validated = $request->validate([
            /*
             * `exists` is not enough on its own: a deactivated position is
             * kept so history keeps what it was filed under, not so somebody
             * new can be filed against it — the same rule the endorsement
             * form's position matching applies.
             */
            'position_id' => [
                'required',
                Rule::exists('positions', 'id')->where('is_active', true),
            ],
        ], [
            'position_id.exists' => 'That position is not one somebody can be moved to.',
        ]);

        $position = Position::findOrFail($validated['position_id']);
        $from = $employee->position?->title ?? 'no position';

        /*
         * Pay is deliberately untouched. `basic_salary` is a cache of what the
         * `salary_adjustments` history says, and money reads that history
         * through `SalaryAdjustmentService::rateAsOf()` — so writing a new
         * rate here would put a figure on the record that no adjustment
         * explains, and payroll would keep paying the old one anyway. A raise
         * that goes with a move is a decision recorded on Salaries &
         * Adjustments, with its date and its reason.
         */
        $employee->update([
            'position_id' => $position->id,
            'department_id' => $position->department_id,
        ]);

        return back()->with(
            'success',
            "{$employee->full_name} moved from {$from} to {$position->title}. Pay is unchanged — record a raise on Salaries & Adjustments if one goes with it.",
        );
    }

    /**
     * Deploying somebody to a client, or bringing them back in-house.
     *
     * Reached from the Clients screen, and gated the same way the position
     * move is: on `update` for the *employee*, not on `manageOrganization`.
     * That gate is for shaping the client list — deciding a client exists.
     * Filing a person against one is a different act, and the two abilities
     * are held by the same roles today only because nobody has needed them
     * apart.
     *
     * **The category moves with the client, and that is not tidiness.**
     * `client_id` is prohibited on internal staff rather than ignored, so
     * setting one without setting the category writes a record the employee
     * form would refuse to save — and clearing a client while leaving the
     * category external leaves somebody deployed to nobody. A stale client on
     * a person brought in-house keeps them in that client's billing and
     * headcount, which is an error nobody would think to go looking for.
     *
     * Department and position are deliberately untouched. A driver deployed to
     * a client is still a driver; where they are sent is not what they do.
     */
    public function updateDeployment(Request $request, Employee $employee): RedirectResponse
    {
        Gate::authorize('update', $employee);

        $validated = $request->validate([
            /*
             * Nullable is a real choice here — it is how somebody is brought
             * back in-house — but `exists` alone would not do: a deactivated
             * client is kept so payroll and attendance keep what they were
             * filed under, not so somebody new can be sent there.
             */
            'client_id' => [
                'nullable',
                Rule::exists('clients', 'id')->where('is_active', true),
            ],
            'position_id' => ['nullable', 'exists:positions,id'],
            'employment_status' => ['nullable', Rule::in(Employee::EMPLOYMENT_STATUSES)],
            'contract_start' => ['nullable', 'date'],
            'contract_end' => ['nullable', 'date'],
            'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'wage_region' => ['nullable', Rule::in(array_keys(config('payroll.wage_regions')))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'client_id.exists' => 'That client is not one somebody can be deployed to.',
        ]);

        $client = $validated['client_id'] ? Client::findOrFail($validated['client_id']) : null;
        $from = $employee->client?->name ?? 'internal staff';

        $updates = [
            'client_id' => $client?->id,
            'employment_category' => $client
                ? Employee::CATEGORY_EXTERNAL
                : Employee::CATEGORY_INTERNAL,
        ];

        if (array_key_exists('position_id', $validated) && $validated['position_id']) {
            $updates['position_id'] = $validated['position_id'];
        }

        if (array_key_exists('employment_status', $validated) && $validated['employment_status']) {
            $updates['employment_status'] = $validated['employment_status'];
        }

        if (array_key_exists('contract_start', $validated)) {
            $updates['contract_start'] = $validated['contract_start'];
        }

        if (array_key_exists('contract_end', $validated)) {
            $updates['contract_end'] = $validated['contract_end'];
        }

        if (array_key_exists('basic_salary', $validated) && $validated['basic_salary'] !== null) {
            $updates['basic_salary'] = $validated['basic_salary'];
        }

        if (array_key_exists('wage_region', $validated)) {
            $updates['wage_region'] = $validated['wage_region'] ?: ($client?->wage_region ?? null);
        }

        if (array_key_exists('notes', $validated) && $validated['notes'] !== null) {
            $updates['notes'] = $validated['notes'];
        }

        $employee->update($updates);

        return back()->with(
            'success',
            $client
                ? "{$employee->full_name} deployed to {$client->name} with contract and salary rate recorded."
                : "{$employee->full_name} brought in-house from {$from}, and is now internal staff.",
        );
    }

    /**
     * Somebody added without a Core 1 endorsement.
     *
     * Allowed, because not every hire comes through recruitment — a
     * transferee, a rehire, an urgent replacement.
     *
     * **The typed reason is gone, removed on the owner's instruction, and
     * what that costs is worth stating rather than quietly dropping.** An
     * endorsement records three things: that somebody decided, who they were,
     * and why. The `direct_hire` row below still carries the first two — it
     * names the person who added the employee, the employee added, the
     * address and the time — so the act is not invisible. What is no longer
     * captured is the *why*: a rehire, a transfer and an urgent replacement
     * now look identical in the trail, and anybody asking six months later
     * why this employee never went through recruitment has nothing on the
     * record to read. That is a real gap and it is the owner's call to accept
     * it; the row stays because "added directly, by whom" is a different and
     * cheaper fact than the explanation, and losing both was not what was
     * asked for.
     */
    private function storeDirectHire(StoreEmployeeRequest $request): RedirectResponse
    {
        $employee = $this->employees->create($request->validated(), $request->file('photo'));

        AuditLog::create([
            'user_id' => $request->user()->id,
            'auditable_type' => Employee::class,
            'auditable_id' => $employee->id,
            'event' => 'direct_hire',
            'new_values' => ['employee' => $employee->full_name],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $message = "Employee {$employee->employee_number} added directly.";

        if ($this->employees->generatedPassword) {
            $username = $employee->user?->username;
            $message .= " Login: {$username} / temporary password: {$this->employees->generatedPassword}";
        }

        return redirect()->route('hr.employees.show', $employee)->with('success', $message);
    }

    /** Dropdown data shared by the create and edit forms. */
    private function formOptions(?int $excludeEmployeeId = null): array
    {
        return [
            'departments' => Department::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'positions' => Position::where('is_active', true)
                ->orderBy('title')
                ->get(['id', 'title', 'department_id']),
            'supervisors' => Employee::query()
                ->where('status', 'active')
                ->when($excludeEmployeeId, fn ($q, $id) => $q->whereKeyNot($id))
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix'])
                ->map(fn (Employee $e) => ['id' => $e->id, 'full_name' => $e->full_name]),
            'employmentStatuses' => Employee::EMPLOYMENT_STATUSES,
            'statuses' => Employee::STATUSES,
            'documentTypes' => EmployeeDocument::TYPES,

            'categories' => Employee::CATEGORIES,
            'clients' => Client::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'wage_region']),
            // Offered on the form so someone posted away from their client's
            // own site can be measured against the right regional floor.
            'wageRegions' => collect(config('payroll.wage_regions'))
                ->map(fn (array $region, string $key) => [
                    'value' => $key,
                    'label' => $region['label'],
                ])
                ->values(),
        ];
    }
}
