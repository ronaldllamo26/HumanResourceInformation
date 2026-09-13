<?php

namespace App\Http\Controllers;

use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Services\PayrollReadinessChecker;
use App\Services\PayrollService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 4 — payroll periods, runs, and the approval workflow.
 */
class PayrollController extends Controller
{
    public function __construct(
        private readonly PayrollService $payroll,
        private readonly PayrollReadinessChecker $readiness,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        // Employees have no business on the runs screen — send them to the one
        // payroll page that is theirs.
        if (! $request->user()->can('viewAny', PayrollRun::class)) {
            return redirect()->route('hr.payroll.payslips');
        }

        /*
         * Set by the dashboard's Payroll card, which counts periods by the
         * stage their run is at. There is no control for it on this screen —
         * the chip beside the table is what makes the narrowing visible, since
         * a list silently filtered is a list nobody can explain.
         *
         * `released` is not a run status: it is approved *or* paid, the same
         * pair `PayrollRun::REPORTABLE` holds for every downstream screen.
         * Naming it here rather than restating the two statuses is what keeps
         * this screen from growing a private opinion about what "released"
         * means.
         */
        $runStatus = $request->query('run_status');

        $periods = PayrollPeriod::with(['runs' => fn ($query) => $query->latest('id')])
            ->when($runStatus, fn ($query, $status) => $query->whereHas(
                'runs',
                fn ($run) => $status === 'released'
                    ? $run->reportable()
                    : $run->where('status', $status),
            ))
            ->orderByDesc('start_date')
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('HR/Payroll/Index', [
            'periods' => [
                'data' => $periods->map(fn (PayrollPeriod $period) => [
                    'id' => $period->id,
                    'name' => $period->name,
                    'start_date' => $period->start_date->toDateString(),
                    'end_date' => $period->end_date->toDateString(),
                    'pay_date' => $period->pay_date->toDateString(),
                    'frequency' => $period->frequency,
                    'status' => $period->status,
                    'run' => $period->runs->first() ? [
                        'id' => $period->runs->first()->id,
                        'run_number' => $period->runs->first()->run_number,
                        'status' => $period->runs->first()->status,
                        'employee_count' => $period->runs->first()->employee_count,
                        'total_net' => (float) $period->runs->first()->total_net,
                    ] : null,
                ]),
                'meta' => [
                    'from' => $periods->firstItem(),
                    'to' => $periods->lastItem(),
                    'total' => $periods->total(),
                    'links' => $periods->linkCollection()->toArray(),
                ],
            ],
            'suggestion' => $this->suggestNextPeriod(),
            'filters' => ['run_status' => $runStatus],
            'can' => ['create' => $request->user()->can('create', PayrollRun::class)],
        ]);
    }

    /** HR defines the cut-off dates a run will be computed against. */
    public function storePeriod(Request $request): RedirectResponse
    {
        Gate::authorize('create', PayrollRun::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'pay_date' => ['required', 'date', 'after_or_equal:end_date'],
            'frequency' => ['required', Rule::in(['monthly', 'semi_monthly', 'weekly'])],
        ], [
            'pay_date.after_or_equal' => 'The pay date cannot fall before the period ends.',
        ]);

        $overlaps = PayrollPeriod::where('start_date', '<=', $validated['end_date'])
            ->where('end_date', '>=', $validated['start_date'])
            ->exists();

        if ($overlaps) {
            return back()->withErrors([
                'start_date' => 'Another payroll period already covers these dates.',
            ]);
        }

        PayrollPeriod::create($validated + ['status' => PayrollPeriod::STATUS_DRAFT]);

        return back()->with('success', 'Payroll period created.');
    }

    public function show(Request $request, PayrollRun $payrollRun): Response
    {
        Gate::authorize('view', $payrollRun);

        $payrollRun->load(['period', 'processor:id,name', 'approver:id,name']);

        $clientFilter = $request->integer('client_id') ?: null;
        $categoryFilter = $request->string('employment_category')->trim()->value() ?: null;

        $payslips = $payrollRun->payslips()
            ->with('employee:id,employee_number,first_name,middle_name,last_name,suffix,client_id,employment_category')
            ->join('employees', 'employees.id', '=', 'payslips.employee_id')
            ->when($clientFilter, fn ($query, $id) => $query->where('employees.client_id', $id))
            ->when($categoryFilter, fn ($query, $value) => $query->where('employees.employment_category', $value))
            ->orderBy('employees.last_name')
            ->select('payslips.*')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('HR/Payroll/Run', [
            'breakdown' => $this->breakdownByClient($payrollRun),
            'filters' => [
                'client_id' => $clientFilter,
                'employment_category' => $categoryFilter,
            ],
            'run' => [
                'id' => $payrollRun->id,
                'period_id' => $payrollRun->payroll_period_id,
                'run_number' => $payrollRun->run_number,
                'status' => $payrollRun->status,
                'employee_count' => $payrollRun->employee_count,
                'total_gross' => (float) $payrollRun->total_gross,
                'total_deductions' => (float) $payrollRun->total_deductions,
                'total_net' => (float) $payrollRun->total_net,
                'processed_by' => $payrollRun->processor?->name,
                'processed_at' => $payrollRun->processed_at?->toIso8601String(),
                'approved_by' => $payrollRun->approver?->name,
                'approved_at' => $payrollRun->approved_at?->toIso8601String(),
                'remarks' => $payrollRun->remarks,
                'period' => [
                    'name' => $payrollRun->period->name,
                    'start_date' => $payrollRun->period->start_date->toDateString(),
                    'end_date' => $payrollRun->period->end_date->toDateString(),
                    'pay_date' => $payrollRun->period->pay_date->toDateString(),
                    'frequency' => $payrollRun->period->frequency,
                ],
            ],
            'payslips' => [
                'data' => $payslips->map(fn ($payslip) => [
                    'id' => $payslip->id,
                    'payslip_number' => $payslip->payslip_number,
                    'employee' => [
                        'full_name' => $payslip->employee->full_name,
                        'employee_number' => $payslip->employee->employee_number,
                    ],
                    'days_worked' => (float) $payslip->days_worked,
                    'overtime_hours' => (float) $payslip->overtime_hours,
                    'gross_pay' => (float) $payslip->gross_pay,
                    'deductions_total' => (float) $payslip->deductions_total,
                    'net_pay' => (float) $payslip->net_pay,
                ]),
                'meta' => [
                    'from' => $payslips->firstItem(),
                    'to' => $payslips->lastItem(),
                    'total' => $payslips->total(),
                    'links' => $payslips->linkCollection()->toArray(),
                ],
            ],
            // What the DTR looked like when this run was computed. Only worth
            // showing while the run can still be recomputed — once it is
            // approved the figures are history, and the panel would be
            // advising a fix that can no longer be applied.
            'readiness' => in_array($payrollRun->status, [
                PayrollRun::STATUS_DRAFT,
                PayrollRun::STATUS_FOR_APPROVAL,
            ], true)
                ? $this->readiness->check($payrollRun->period)
                : null,
            'can' => [
                'recompute' => $request->user()->can('update', $payrollRun),
                'submit' => $request->user()->can('submit', $payrollRun),
                'approve' => $request->user()->can('approve', $payrollRun),
                'markPaid' => $request->user()->can('markPaid', $payrollRun),
                'cancel' => $request->user()->can('cancel', $payrollRun),
            ],
        ]);
    }

    /** Computes (or recomputes) the draft run for a period. */
    public function generate(Request $request, PayrollPeriod $payrollPeriod): RedirectResponse
    {
        Gate::authorize('create', PayrollRun::class);

        if ($payrollPeriod->runs()->whereIn('status', [
            PayrollRun::STATUS_APPROVED,
            PayrollRun::STATUS_PAID,
        ])->exists()) {
            return back()->with('error', 'This period already has an approved run.');
        }

        $run = $this->payroll->generate($payrollPeriod, $request->user());

        return redirect()
            ->route('hr.payroll.run', $run)
            ->with('success', "Computed {$run->employee_count} payslip(s) for {$payrollPeriod->name}.");
    }

    public function submit(PayrollRun $payrollRun): RedirectResponse
    {
        Gate::authorize('submit', $payrollRun);

        $this->payroll->submitForApproval($payrollRun);

        return back()->with('success', 'Run submitted for approval.');
    }

    public function approve(Request $request, PayrollRun $payrollRun): RedirectResponse
    {
        Gate::authorize('approve', $payrollRun);

        $validated = $request->validate(['remarks' => ['nullable', 'string', 'max:1000']]);

        $this->payroll->approve($payrollRun, $request->user(), $validated['remarks'] ?? null);

        return back()->with('success', 'Payroll approved and loan balances updated.');
    }

    public function markPaid(PayrollRun $payrollRun): RedirectResponse
    {
        Gate::authorize('markPaid', $payrollRun);

        $this->payroll->markPaid($payrollRun);

        return back()->with('success', 'Payroll marked as paid.');
    }

    public function cancel(Request $request, PayrollRun $payrollRun): RedirectResponse
    {
        Gate::authorize('cancel', $payrollRun);

        $validated = $request->validate(['remarks' => ['nullable', 'string', 'max:1000']]);

        $this->payroll->cancel($payrollRun, $validated['remarks'] ?? null);

        return back()->with('success', 'Payroll run cancelled.');
    }

    /**
     * What this run costs per client, plus the agency's own staff.
     *
     * The figure a manpower agency actually needs from a payroll run: each
     * client is billed for the people deployed to them, and internal staff are
     * PrimePower's own overhead. One row per group rather than a separate run
     * per client — the run is still one statutory filing, and splitting it
     * would mean four SSS remittances for one month.
     *
     * Grouped in SQL rather than over a loaded collection: a run holds a row
     * per employee, and this screen already paginates them for that reason.
     *
     * @return array<int, array<string, mixed>>
     */
    private function breakdownByClient(PayrollRun $payrollRun): array
    {
        $rows = $payrollRun->payslips()
            ->join('employees', 'employees.id', '=', 'payslips.employee_id')
            ->leftJoin('clients', 'clients.id', '=', 'employees.client_id')
            ->groupBy('employees.client_id', 'clients.name', 'clients.code')
            ->selectRaw('employees.client_id')
            ->selectRaw('clients.name as client_name')
            ->selectRaw('clients.code as client_code')
            ->selectRaw('count(*) as employee_count')
            ->selectRaw('coalesce(sum(payslips.gross_pay), 0) as gross')
            ->selectRaw('coalesce(sum(payslips.deductions_total), 0) as deductions')
            ->selectRaw('coalesce(sum(payslips.net_pay), 0) as net')
            ->get();

        return $rows
            ->map(fn ($row) => [
                'client_id' => $row->client_id,
                // A null client is internal staff — labelled here rather than
                // left as "—", because "who is this cost for" is the question
                // the row exists to answer.
                'label' => $row->client_name ?? 'Internal Staff (PrimePower)',
                'code' => $row->client_code,
                'employee_count' => (int) $row->employee_count,
                'gross' => (float) $row->gross,
                'deductions' => (float) $row->deductions,
                'net' => (float) $row->net,
            ])
            // Internal staff last: the client rows are what gets billed out.
            ->sortBy(fn (array $row) => [$row['client_id'] === null ? 1 : 0, $row['label']])
            ->values()
            ->all();
    }

    /**
     * Proposes the next semi-monthly cut-off so HR is not typing dates by hand.
     *
     * @return array<string, string>
     */
    private function suggestNextPeriod(): array
    {
        $latest = PayrollPeriod::orderByDesc('end_date')->first();

        $start = $latest
            ? $latest->end_date->copy()->addDay()
            : Carbon::today()->startOfMonth();

        // Cut-offs land on the 15th and the end of the month.
        $end = $start->day <= 15
            ? $start->copy()->setDay(15)
            : $start->copy()->endOfMonth();

        return [
            'name' => $start->format('M j').' – '.$end->format('j, Y'),
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'pay_date' => $end->copy()->addDays(5)->toDateString(),
            'frequency' => 'semi_monthly',
        ];
    }
}
