<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Employee;
use App\Services\EmployeeImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 1 — bulk import of an existing workforce.
 *
 * Behind `create` on Employee rather than a new permission: importing forty
 * people is creating forty people, and someone who may not create one has no
 * business creating a spreadsheet of them.
 */
class EmployeeImportController extends Controller
{
    public function create(Request $request): Response
    {
        Gate::authorize('create', Employee::class);

        return Inertia::render('HR/Employees/Import', [
            // The template is described from the same constants the importer
            // validates against, so the screen cannot advertise a value the
            // import would then reject.
            'columns' => $this->columns(),
            'clients' => Client::where('is_active', true)->orderBy('name')->get(['name', 'code']),
        ]);
    }

    /**
     * Previews, or commits.
     *
     * One endpoint taking a `commit` flag rather than two, because both do the
     * same read and the same validation — the only difference is whether the
     * rows are written at the end. Splitting them would be two copies of the
     * parsing rules, and the copy that is only reached on commit is the one
     * that would drift.
     *
     * The file is uploaded again to commit rather than a preview being held
     * server-side and trusted: nothing the browser sends between the two
     * requests decides what gets created.
     */
    public function store(Request $request, EmployeeImporter $importer)
    {
        Gate::authorize('create', Employee::class);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt'],
            'commit' => ['sometimes', 'boolean'],
        ], [
            'file.mimes' => 'Upload a CSV file. In Excel: File → Save As → CSV.',
        ]);

        $commit = (bool) ($validated['commit'] ?? false);

        $result = $commit
            ? $importer->import($request->file('file'))
            : $importer->preview($request->file('file'));

        /*
         * A preview answers in JSON, the same way the document scanner does.
         *
         * It has to: the browser cannot re-attach a file to a form it has
         * re-rendered, so an Inertia response here would leave the user with a
         * preview on screen and an empty file input under it. Answering the
         * XHR leaves the page — and the chosen file — exactly as it was, ready
         * for the commit that follows.
         */
        if (! $commit) {
            return response()->json($result);
        }

        $created = count(array_filter($result['rows'], fn ($row) => $row['created'] !== null));

        if ($created === 0) {
            return back()->with('error', $result['errors'][0] ?? 'Nothing in that file could be imported.');
        }

        $message = "Imported {$created} employee".($created === 1 ? '' : 's').'.';

        if ($result['summary']['errors'] > 0) {
            $message .= " {$result['summary']['errors']} row(s) were skipped.";
        }

        return redirect()->route('hr.employees.index')->with('success', $message);
    }

    /**
     * What the file may contain.
     *
     * Read off the model rather than typed out, so adding an employment status
     * changes this screen too. A template that lists a value the validator
     * rejects is worse than no template.
     *
     * @return array<int, array<string, mixed>>
     */
    private function columns(): array
    {
        return [
            ['name' => 'last_name', 'required' => true, 'note' => null],
            ['name' => 'first_name', 'required' => true, 'note' => null],
            ['name' => 'middle_name', 'required' => false, 'note' => null],
            ['name' => 'employee_number', 'required' => false, 'note' => 'Generated when blank.'],
            ['name' => 'date_hired', 'required' => true, 'note' => 'Any readable date — 2026-01-15, 15/01/2026.'],
            ['name' => 'basic_salary', 'required' => true, 'note' => 'Commas and a currency symbol are fine.'],
            [
                'name' => 'employment_category',
                'required' => true,
                'note' => implode(' or ', Employee::CATEGORIES).'. Never guessed — an internal filing keeps someone off a client’s headcount.',
            ],
            ['name' => 'client', 'required' => false, 'note' => 'Name or code. Required for external staff, prohibited for internal.'],
            ['name' => 'employment_status', 'required' => true, 'note' => implode(', ', Employee::EMPLOYMENT_STATUSES)],
            ['name' => 'employment_type', 'required' => false, 'note' => 'full_time or part_time. Defaults to full_time.'],
            ['name' => 'pay_frequency', 'required' => false, 'note' => 'monthly, semi_monthly, weekly, daily. Defaults to semi_monthly.'],
            ['name' => 'record_status', 'required' => false, 'note' => implode(', ', Employee::STATUSES).'. Defaults to active.'],
            ['name' => 'department', 'required' => false, 'note' => 'By name. An unknown one is left unfiled, not created.'],
            ['name' => 'position', 'required' => false, 'note' => 'By title. Same rule as department.'],
            ['name' => 'email', 'required' => false, 'note' => null],
            ['name' => 'mobile', 'required' => false, 'note' => null],
            ['name' => 'sss', 'required' => false, 'note' => 'Missing government numbers are a warning, not a refusal.'],
            ['name' => 'philhealth', 'required' => false, 'note' => null],
            ['name' => 'pagibig', 'required' => false, 'note' => null],
            ['name' => 'tin', 'required' => false, 'note' => null],
        ];
    }
}
