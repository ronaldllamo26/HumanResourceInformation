<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 1 master data — the client companies PrimePower deploys to.
 *
 * The agency's external workforce is filed against one of these, and payroll,
 * billing, and every per-client report group by it. Sits beside Departments
 * for the same reason: HR maintains it while filing people, not while
 * configuring the system.
 *
 * Reuses the `manageOrganization` gate rather than inventing a permission —
 * a client is org structure in a manpower agency, the same way a department
 * is, and the people allowed to shape one are the people allowed to shape the
 * other.
 */
class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('manageOrganization', Setting::class);

        $search = $request->string('search')->trim()->value();

        $clients = Client::withCount([
            'employees',
            'employees as active_employees_count' => fn ($query) => $query->where('status', 'active'),
        ])
            ->with([
                /*
                 * Who is on site, so a client card can be opened rather than
                 * only counted. Eager-loaded in one query: a dozen clients
                 * fetched one at a time on expand would be a dozen round trips
                 * for a set the page is about to hold anyway.
                 *
                 * Active only — a client's deployment is who is there now, and
                 * somebody who has left is a record the archive keeps.
                 */
                'employees' => fn ($query) => $query
                    ->where('status', 'active')
                    ->with('position:id,title')
                    ->orderBy('last_name')
                    ->orderBy('first_name')
                    ->select([
                        'id', 'employee_number', 'first_name', 'middle_name',
                        'last_name', 'suffix', 'photo_path', 'position_id', 'client_id',
                    ]),
            ])
            ->search($search)
            ->orderBy('name')
            ->get();

        $regions = collect(config('payroll.wage_regions'));

        return Inertia::render('HR/MasterData/Clients', [
            'clients' => $clients->map(fn (Client $client) => [
                'id' => $client->id,
                'code' => $client->code,
                'name' => $client->name,
                'industry' => $client->industry,
                'wage_region' => $client->wage_region,
                'wage_region_label' => $regions[$client->wage_region]['label'] ?? null,
                'daily_minimum' => $regions[$client->wage_region]['daily_minimum'] ?? null,
                'contact_person' => $client->contact_person,
                'contact_email' => $client->contact_email,
                'contact_number' => $client->contact_number,
                'address' => $client->address,
                'contract_start' => $client->contract_start?->toDateString(),
                'contract_end' => $client->contract_end?->toDateString(),
                'contract_lapsed' => $client->contractHasLapsed(),
                'is_active' => $client->is_active,
                'employees_count' => $client->employees_count,
                'active_employees_count' => $client->active_employees_count,

                /*
                 * A name, a number, a job, a photo — and nothing else.
                 *
                 * This is master data behind `manageOrganization`, answering
                 * "who is on this client's site". It needs no salary, no
                 * government number and no 201 file, so it carries none. The
                 * same narrowing `DirectoryController::card()` makes, for the
                 * same reason: what keeps a screen safe is the field list.
                 */
                'employees' => $client->employees->map(fn (Employee $employee) => [
                    'id' => $employee->id,
                    'employee_number' => $employee->employee_number,
                    'full_name' => $employee->full_name,
                    'position' => $employee->position?->title,
                    'photo_url' => $employee->photo_path
                        ? asset('storage/'.$employee->photo_path)
                        : null,
                ]),
            ]),
            /*
             * Who can be sent somewhere, for the deploy picker.
             *
             * The whole active roster rather than only internal staff: moving
             * somebody between clients is the commoner act, and a list that
             * offered only the undeployed would answer the rarer half of the
             * question. Their current posting rides along so the picker can
             * say what the move is *from* — sending a driver who is already on
             * another client's site is a decision, not a fill-in.
             *
             * The same four fields the cards carry, and for the same reason:
             * this is master data, so it holds no salary and no 201 file.
             */
            'deployable' => Employee::query()
                ->where('status', 'active')
                // Eager-loaded rather than read per row: the picker holds the
                // whole roster, so a lazy relation here is forty queries to
                // draw one list.
                ->with(['client:id,name', 'position:id,title', 'department:id,name'])
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get([
                    'id', 'employee_number', 'first_name', 'middle_name', 'last_name',
                    'suffix', 'client_id', 'position_id', 'department_id',
                ])
                ->map(fn (Employee $employee) => [
                    'id' => $employee->id,
                    'employee_number' => $employee->employee_number,
                    'full_name' => $employee->full_name,
                    /*
                     * The job is the whole basis of the decision — a client
                     * asking for drivers is not asking for whoever is free,
                     * and a picker that showed only names made "can this
                     * person do it" a question you had to leave the screen to
                     * answer.
                     */
                    'position' => $employee->position?->title,
                    'department' => $employee->department?->name,
                    'client_id' => $employee->client_id,
                    'client_name' => $employee->client?->name,
                ]),
            'filters' => ['search' => $search],
            'wageRegions' => $regions
                ->map(fn (array $region, string $key) => [
                    'value' => $key,
                    'label' => $region['label'],
                    'daily_minimum' => $region['daily_minimum'],
                ])
                ->values(),
            // Counted across the whole table, not the filtered page — a
            // summary that moves when you type is not a summary.
            'summary' => [
                'total' => Client::count(),
                'active' => Client::where('is_active', true)->count(),
                'deployed' => Client::has('employees')->count(),
                /*
                 * A lapsed contract with people still on site is the finding
                 * worth surfacing: the deployment is running past what was
                 * signed for. Reported, never enforced — blocking payroll over
                 * a paperwork gap would strand those employees unpaid.
                 */
                'lapsed' => Client::whereNotNull('contract_end')
                    ->whereDate('contract_end', '<', now())
                    ->where('is_active', true)
                    ->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manageOrganization', Setting::class);

        Client::create($this->rules($request));

        return back()->with('success', 'Client created.');
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        Gate::authorize('manageOrganization', Setting::class);

        $client->update($this->rules($request, $client));

        return back()->with('success', 'Client updated.');
    }

    public function destroy(Client $client): RedirectResponse
    {
        Gate::authorize('manageOrganization', Setting::class);

        /*
         * Two different outcomes, and they are not the same thing.
         *
         * A client with people on it is only *deactivated*: it stays on the
         * list, stays selectable in reports, and simply stops being offered
         * for new deployments. Removing it from view would strand the
         * headcount and payslips grouped under it.
         *
         * A client nobody was deployed to is *archived* — soft-deleted, so it
         * leaves the working list but keeps its row. It shows on the archive
         * screen and can be restored. It is never destroyed outright: a
         * mis-click on the wrong row should cost a click to undo, not a
         * retype.
         */
        if ($client->employees()->exists()) {
            $client->update(['is_active' => false]);

            return back()->with('success', 'Client has deployed employees — deactivated instead of archived.');
        }

        $client->delete();

        return back()->with('success', 'Client archived. You can restore it from Archive.');
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, ?Client $client = null): array
    {
        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:24',
                Rule::unique('clients', 'code')->ignore($client?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'wage_region' => ['nullable', Rule::in(array_keys(config('payroll.wage_regions')))],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            'contract_start' => ['nullable', 'date'],
            'contract_end' => ['nullable', 'date', 'after_or_equal:contract_start'],
            'is_active' => ['boolean'],
        ]);

        return [
            ...$validated,
            'code' => strtoupper($validated['code']),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
