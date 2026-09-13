<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Services\BulkDocumentFiler;
use App\Services\DocumentScanner;
use App\Services\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 1 — filing a stack of scanned 201-file documents in one pass.
 *
 * Two steps, and the split is the whole design. `examine` reads the files and
 * proposes who each belongs to, writing nothing; `store` files what a person
 * confirmed. Filing a document under the wrong employee is the one thing the
 * single-document upload refuses outright, and doing it forty at a time
 * unattended would be that same mistake at scale.
 */
class DocumentBatchController extends Controller
{
    /** Matches StoreEmployeeDocumentRequest — one file's limits, applied per file. */
    private const PER_FILE_RULES = ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp'];

    /**
     * Enough to clear a drawer, few enough to survive one request.
     *
     * Each file is a separate model call, so a 60-file batch on the local
     * driver is minutes of wall clock. The cap keeps a batch inside a request
     * rather than inviting a timeout halfway through a stack somebody has
     * already fed through the scanner.
     */
    private const MAX_FILES = 20;

    public function __construct(private readonly EmployeeService $employees) {}

    public function create(Request $request, DocumentScanner $scanner): Response
    {
        Gate::authorize('fileDocumentBatch', Employee::class);

        return Inertia::render('HR/Employees/DocumentBatch', [
            'documentTypes' => EmployeeDocument::TYPES,
            'expiringTypes' => array_values(config('credentials.expiring_types', [])),
            'maxFiles' => self::MAX_FILES,
            // Without a driver the screen is honest about it rather than
            // offering a scan that would come back empty for every file.
            'scannerEnabled' => $scanner->isEnabled(),
            'employees' => $this->assignable($request),
        ]);
    }

    /**
     * Reads the batch, files what clears every gate, and returns what did not.
     *
     * The route is still named `examine` because that is what it does to every
     * file; what changed is that a reading every check agrees on is now filed
     * here rather than waiting for somebody to retype it. The gates are
     * `config('scanner.autofile')` and live in `BulkDocumentFiler` — not in
     * this method, because what may be filed unattended is a rule about
     * documents and a copy of it in an HTTP layer would be a second place to
     * loosen it.
     *
     * JSON, like the single-document scan: the browser cannot re-attach files
     * to a re-rendered form, so an Inertia response here would leave a review
     * table above an empty file input.
     */
    public function examine(Request $request, BulkDocumentFiler $filer): JsonResponse
    {
        Gate::authorize('fileDocumentBatch', Employee::class);

        $request->validate([
            'files' => ['required', 'array', 'max:'.self::MAX_FILES],
            'files.*' => ['required', ...self::PER_FILE_RULES],
        ], [
            'files.max' => 'Up to '.self::MAX_FILES.' files at a time.',
            'files.*.mimes' => 'Images only — a PDF is uploaded on the employee’s own record.',
        ]);

        $result = $filer->process(
            array_values($request->file('files')),
            $this->scoped($request),
        );

        return response()->json([
            'filed' => $result['filed'],
            /*
             * Only the held rows come back. Each keeps its `index` into the
             * batch the browser still holds, which is what lets the review
             * screen re-upload exactly those files — a held row is a smaller
             * batch, not a gap in the old one, and `store()` pairs files with
             * assignments by position.
             */
            'documents' => $result['documents'],
        ]);
    }

    /**
     * Files what the person confirmed.
     *
     * The files are uploaded again rather than a batch being held server-side
     * between the two requests: nothing the browser sends in between decides
     * where a document lands, and the scope is re-derived here rather than
     * trusted from the form.
     */
    public function store(Request $request, BulkDocumentFiler $filer): RedirectResponse
    {
        Gate::authorize('fileDocumentBatch', Employee::class);

        $request->validate([
            'files' => ['required', 'array', 'max:'.self::MAX_FILES],
            'files.*' => ['required', ...self::PER_FILE_RULES],
            'assignments' => ['required', 'array'],
            'assignments.*.employee_id' => ['nullable', 'integer'],
            'assignments.*.type' => ['nullable', 'string'],
            'assignments.*.title' => ['nullable', 'string', 'max:255'],
            'assignments.*.issued_at' => ['nullable', 'date'],
            'assignments.*.expires_at' => ['nullable', 'date'],
        ]);

        $result = $filer->file(
            array_values($request->file('files')),
            $request->input('assignments', []),
            $this->scoped($request),
        );

        if ($result['filed'] === 0) {
            return back()->with('error', 'Nothing was filed — every document still needs an employee.');
        }

        $message = "Filed {$result['filed']} document".($result['filed'] === 1 ? '' : 's').'.';

        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} left unassigned.";
        }

        return redirect()->route('hr.employees.index')->with('success', $message);
    }

    /**
     * The people this user may file against.
     *
     * `scopedQuery()` rather than every employee, so the match cannot propose
     * — and `file()` cannot accept — somebody outside what this user can see.
     * Module 1's own narrowing, reused rather than restated.
     */
    private function scoped(Request $request)
    {
        return $this->employees->scopedQuery($request->user())
            ->get([
                'id', 'employee_number', 'first_name', 'middle_name', 'last_name', 'suffix',
                'drivers_license_number', 'sss_number', 'philhealth_number', 'pagibig_number', 'tin',
            ]);
    }

    /** The same people, as the picker on the review table needs them. */
    private function assignable(Request $request): array
    {
        return $this->scoped($request)
            ->sortBy('last_name')
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'full_name' => $employee->full_name,
            ])
            ->values()
            ->all();
    }
}
