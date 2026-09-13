<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverResource;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Services\DeploymentReadinessChecker;
use App\Services\EmployeeService;
use App\Services\LeaveService;
use App\Services\PayrollService;
use App\Services\TimekeepingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * What Core 2 publishes to the rest of ISMERS.
 *
 * This system is the record of **people, time, leave, and pay**. Every other
 * core needs some of that and none of it should be copied: a headcount kept in
 * two places disagrees within a month, and the disagreement surfaces on a
 * remittance or a dispatch sheet rather than on a screen somebody is watching.
 *
 * So the shape of every endpoint here is the same: an answer this system is
 * uniquely able to give, computed by the service that already gives it to our
 * own screens. Nothing is re-derived for the API — `DeploymentReadinessChecker`,
 * `LicenseVerifier`, and `PayrollRun::scopeReportable()` are the same objects
 * behind `/hr/deployment`, the employee screen, and Compliance. If a consumer
 * and one of our screens ever disagreed about the same driver, one of them
 * would be running its own copy of the rules, and that is the thing this
 * controller exists to prevent.
 *
 * **Everything here is read-only.** The one inbound door is
 * `Api\EndorsementController` (Core 1 proposes a hire) and
 * `Api\LoanController` (Core 3 posts a loan for payroll to deduct). Nothing
 * else may write into this system over the wire.
 */
class IntegrationController extends Controller
{
    public function __construct(
        private readonly EmployeeService $employees,
        private readonly DeploymentReadinessChecker $readiness,
        private readonly TimekeepingService $timekeeping,
        private readonly LeaveService $leave,
        private readonly PayrollService $payroll,
    ) {}

    /**
     * GET /api/v1/drivers — for **Fleet & Transportation Management**.
     *
     * Who may lawfully be put behind the wheel, and of what. A dispatcher
     * assigning a run needs the DL codes (the legal ceiling on vehicle class),
     * the conditions (4 is daylight only — that driver cannot take a night
     * run), and whether the licence has lapsed at all.
     *
     * None of that is derivable from an employee record: it is the LTO card,
     * read through `LicenseVerifier`, which is the same object the employee
     * screen and Record Checks use. Filter by `client_id` so a site
     * dispatcher sees only their own, and by `available=1` for the ones who
     * may drive today.
     */
    public function drivers(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Employee::class);

        $drivers = $this->employees->scopedQuery($request->user())
            ->with(['client:id,name', 'position:id,title'])
            ->whereNotNull('drivers_license_number')
            ->when(
                $request->query('client_id'),
                fn ($query, $value) => $query->where('client_id', $value),
            )
            ->when(
                filter_var($request->query('available'), FILTER_VALIDATE_BOOLEAN),
                fn ($query) => $query
                    ->where('status', 'active')
                    ->where(fn ($inner) => $inner
                        ->whereNull('license_expiry')
                        ->orWhereDate('license_expiry', '>=', now()->toDateString()),
                    ),
            )
            ->orderBy('last_name')
            ->get();

        return DriverResource::collection($drivers);
    }

    /**
     * GET /api/v1/deployment-readiness — for **Core 1** and **Fleet**.
     *
     * Can this person be sent to a client tomorrow? No single module answers
     * it: it needs credentials, 201-file completeness, and employment standing
     * at once, which is exactly what `DeploymentReadinessChecker` composes for
     * `/hr/deployment`.
     *
     * `blocked` is not a louder warning. A driver whose licence has lapsed may
     * not lawfully drive, so it is the one status here that means "this would
     * be wrong" rather than "somebody should look".
     */
    public function deploymentReadiness(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Employee::class);

        $rows = $this->readiness->scan($this->employees->scopedQuery($request->user()))
            ->when(
                $request->query('status'),
                fn ($items, $value) => $items->where('status', $value),
            )
            ->when(
                $request->query('client_id'),
                fn ($items, $value) => $items->where('client_id', (int) $value),
            )
            ->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total' => $rows->count(),
                'ready' => $rows->where('status', DeploymentReadinessChecker::STATUS_READY)->count(),
                'warning' => $rows->where('status', DeploymentReadinessChecker::STATUS_WARNING)->count(),
                'blocked' => $rows->where('status', DeploymentReadinessChecker::STATUS_BLOCKED)->count(),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/v1/payroll/runs — for **Financial Management (Transaction Core)**.
     *
     * The disbursement register: what is owed, to whom, and how it breaks
     * down. Only **approved and paid** runs are listed, read from
     * `PayrollRun::scopeReportable()` rather than from a condition written
     * again here — a draft is still being corrected, and Finance disbursing
     * against one would be paying a figure this system has not agreed to yet.
     *
     * That is the same rule 13th-month pay, Compliance, and final pay all
     * read. There is exactly one definition of "already earned" in this
     * system, and it is on the model.
     */
    public function payrollRuns(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PayrollRun::class);

        $runs = PayrollRun::reportable()
            ->with('period:id,name,start_date,end_date,pay_date')
            ->when(
                $request->query('from'),
                fn ($query, $value) => $query->whereHas(
                    'period',
                    fn ($inner) => $inner->whereDate('end_date', '>=', $value),
                ),
            )
            ->when(
                $request->query('to'),
                fn ($query, $value) => $query->whereHas(
                    'period',
                    fn ($inner) => $inner->whereDate('start_date', '<=', $value),
                ),
            )
            ->latest('id')
            ->get()
            ->map(fn (PayrollRun $run) => [
                'id' => $run->id,
                'run_number' => $run->run_number,
                'status' => $run->status,
                'period' => [
                    'name' => $run->period?->name,
                    'start_date' => $run->period?->start_date?->toDateString(),
                    'end_date' => $run->period?->end_date?->toDateString(),
                    'pay_date' => $run->period?->pay_date?->toDateString(),
                ],
                'employee_count' => $run->employee_count,
                'total_gross' => (float) $run->total_gross,
                'total_deductions' => (float) $run->total_deductions,
                'total_net' => (float) $run->total_net,
                'approved_at' => $run->approved_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $runs]);
    }

    /**
     * GET /api/v1/payroll/runs/{run}/register — for **Financial Management**.
     *
     * The per-employee lines behind one run's totals, which is what a
     * disbursement file is built from. Bank details ride only for a caller
     * whose token may see them — the same `viewSensitive` line the employee
     * screen draws, applied to a machine rather than a person.
     */
    public function payrollRegister(Request $request, PayrollRun $run): JsonResponse
    {
        Gate::authorize('view', $run);

        abort_unless(
            in_array($run->status, PayrollRun::REPORTABLE, true),
            409,
            'This run is still a draft. Only approved or paid runs are disbursable.',
        );

        $lines = $run->payslips()
            ->with('employee:id,employee_number,first_name,middle_name,last_name,suffix,bank_name,bank_account_number')
            ->get()
            ->map(function ($payslip) use ($request) {
                $employee = $payslip->employee;
                $maySeeBank = $employee && Gate::forUser($request->user())->allows('viewSensitive', $employee);

                return array_filter([
                    'payslip_number' => $payslip->payslip_number,
                    'employee_id' => $payslip->employee_id,
                    'employee_number' => $employee?->employee_number,
                    'full_name' => $employee?->full_name,
                    'gross_pay' => (float) $payslip->gross_pay,
                    'deductions_total' => (float) $payslip->deductions_total,
                    'net_pay' => (float) $payslip->net_pay,

                    // Absent, not null, for a caller who may not see them.
                    'bank_name' => $maySeeBank ? $employee?->bank_name : null,
                    'bank_account_number' => $maySeeBank ? $employee?->bank_account_number : null,
                ], fn ($value) => $value !== null);
            });

        return response()->json([
            'data' => $lines,
            'meta' => [
                'run_number' => $run->run_number,
                'status' => $run->status,
                'employee_count' => $run->employee_count,
                'total_net' => (float) $run->total_net,
                // A control total the receiving system can check its own sum
                // against, the same one every Compliance export carries.
                'control_total' => round((float) $lines->sum('net_pay'), 2),
            ],
        ]);
    }

    /**
     * GET /api/v1/payroll/contributions — for **Core 3 (Government Contribution
     * & Compliance)**.
     *
     * What was withheld and what the employer owes, per employee, for a
     * period. Core 3 files the remittances; this system computed them, and
     * these figures are **read back from stored payslips rather than
     * recomputed** — otherwise a new SSS circular in `config/payroll.php`
     * would silently rewrite what was already remitted.
     *
     * An employee missing the relevant government number is included with a
     * null, deliberately: the filing cannot cover them until it is on their
     * 201 file, and dropping them would hide that from the system whose job
     * it is to notice.
     */
    public function contributions(Request $request, PayrollRun $run): JsonResponse
    {
        Gate::authorize('view', $run);

        abort_unless(
            in_array($run->status, PayrollRun::REPORTABLE, true),
            409,
            'This run is still a draft. Only approved or paid runs are reportable.',
        );

        $lines = $run->payslips()
            ->with('employee:id,employee_number,first_name,middle_name,last_name,suffix,sss_number,philhealth_number,pagibig_number,tin')
            ->get()
            ->map(fn ($payslip) => [
                'employee_id' => $payslip->employee_id,
                'employee_number' => $payslip->employee?->employee_number,
                'full_name' => $payslip->employee?->full_name,
                'numbers' => [
                    'sss' => $payslip->employee?->sss_number,
                    'philhealth' => $payslip->employee?->philhealth_number,
                    'pagibig' => $payslip->employee?->pagibig_number,
                    'tin' => $payslip->employee?->tin,
                ],
                'employee_share' => [
                    'sss' => (float) $payslip->sss_employee,
                    'philhealth' => (float) $payslip->philhealth_employee,
                    'pagibig' => (float) $payslip->pagibig_employee,
                    'withholding_tax' => (float) $payslip->withholding_tax,
                ],
                'employer_share' => [
                    'sss' => (float) $payslip->sss_employer,
                    'philhealth' => (float) $payslip->philhealth_employer,
                    'pagibig' => (float) $payslip->pagibig_employer,
                ],
            ]);

        return response()->json([
            'data' => $lines,
            'meta' => [
                'run_number' => $run->run_number,
                'period' => $run->period?->name,
                'employee_count' => $lines->count(),
                // Flagged rather than filtered out: the filing cannot include
                // them until the number is on the 201 file.
                'missing_numbers' => $lines
                    ->filter(fn (array $line) => collect($line['numbers'])->contains(null))
                    ->pluck('employee_number')
                    ->values(),
            ],
        ]);
    }

    /**
     * POST /api/v1/payroll/runs/{run}/disbursement — for **Financial
     * Management (Accounts Payable)**.
     *
     * The other end of `/register`. Finance takes the disbursement list, the
     * bank credits it, and this says so — which is what moves a run from
     * `approved` to `paid` and lets every employee's payslip screen open.
     *
     * **The loop was open before this.** `/register` handed Finance a list and
     * nothing came back, so a run sat at `approved` until somebody in HR
     * remembered to tick it — and "approved" and "the money actually arrived"
     * are different facts that the system was reporting as one.
     *
     * **The amount is checked, not trusted.** Finance sends what the bank
     * credited and this compares it against the run's own total. They must
     * agree: a file that disbursed less than the register said is somebody
     * unpaid, and marking the run `paid` over it would bury that. `409` and a
     * stated difference is the only honest answer — the same reason `/register`
     * carries a `control_total` for Finance to check *before* they send it.
     */
    public function confirmDisbursement(Request $request, PayrollRun $run): JsonResponse
    {
        $validated = $request->validate([
            /*
             * The bank's own reference. Required, and it is the whole audit
             * trail for "which transfer paid this run" — without it the only
             * record that the money moved is a status column.
             */
            'bank_reference' => ['required', 'string', 'max:120'],

            // What the bank actually credited, checked below against the run.
            'amount' => ['required', 'numeric', 'min:0'],

            'disbursed_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        /*
         * Already confirmed: the stored reference is returned with 200 rather
         * than the run being marked paid twice. A timeout on Finance's side is
         * indistinguishable from a failure, so they resend — and the same
         * contract the three write doors offer applies here.
         */
        if ($run->status === PayrollRun::STATUS_PAID) {
            return response()->json([
                'data' => [
                    'run_number' => $run->run_number,
                    'status' => $run->status,
                    'already_confirmed' => true,
                    'bank_reference' => $run->disbursement_reference,
                    'disbursed_at' => $run->disbursed_at?->toIso8601String(),
                ],
            ], 200);
        }

        /*
         * The status is checked *before* the ability, and the order is
         * deliberate.
         *
         * `PayrollRunPolicy::markPaid` couples who may do this with the run
         * being approved, so authorising first would answer **403** for a
         * draft — and for Finance that is the wrong answer. They *are*
         * allowed; the run is simply not ready, which is "retry later". The
         * cost is that an under-privileged token learns a run's status from
         * this endpoint, and that is a payroll run's stage rather than
         * anybody's personal data.
         *
         * A draft is still being corrected and a cancelled one was withdrawn.
         * A bank transfer against either is a fact somebody needs to look at,
         * not a status this system should quietly accept.
         */
        abort_unless(
            $run->status === PayrollRun::STATUS_APPROVED,
            409,
            "This run is {$run->status}. Only an approved run can be confirmed as disbursed.",
        );

        /*
         * The ability this system already had for exactly this act, rather
         * than a new one — and rather than `approve`, which is a *different*
         * decision and is coupled to `for_approval`. Reaching for `approve`
         * here was the first attempt and it refused every caller, which is
         * how the existing one was found.
         */
        Gate::authorize('markPaid', $run);

        $expected = round((float) $run->total_net, 2);
        $credited = round((float) $validated['amount'], 2);

        // A centavo of tolerance, because the two figures are sums of rounded
        // currency reached by two systems — not one number twice.
        abort_if(
            abs($expected - $credited) >= 0.01,
            409,
            "The amount credited ({$credited}) does not match this run's net total ({$expected}). "
                .'Difference: '.round($credited - $expected, 2).'. Nothing has been marked paid.',
        );

        $this->payroll->markPaid($run);

        $run->update([
            'disbursement_reference' => $validated['bank_reference'],
            'disbursed_at' => $validated['disbursed_at'],
            'disbursement_notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'data' => [
                'run_number' => $run->run_number,
                'status' => $run->fresh()->status,
                'already_confirmed' => false,
                'bank_reference' => $validated['bank_reference'],
                'disbursed_at' => $run->fresh()->disbursed_at?->toIso8601String(),
                'amount' => $credited,
            ],
        ]);
    }

    /**
     * GET /api/v1/payroll/journal-summary/{period} — for **Financial
     * Management (General Ledger, Accounts Payable, Tax)**.
     *
     * The one thing Finance cannot get from any other endpoint here: payroll
     * as a **journal entry**, debits and credits, ready to post. `/runs`
     * answers "which runs exist", `/register` answers "who gets paid what",
     * and `/contributions` answers "what do we owe the agencies". None of them
     * is a journal, and Finance cannot post a period to the ledger without one.
     *
     * **Keyed by period rather than by run, and that is deliberate.** A ledger
     * is posted per accounting period; a run is this system's own unit of work,
     * and there can be more than one in a period. Asking Finance to add up
     * runs themselves would be asking them to re-derive a total this system
     * already holds — and the day their sum disagrees with ours, the
     * disagreement surfaces in a trial balance rather than on a screen.
     *
     * **Every figure is read back from stored payslips, never recomputed** —
     * the same rule `contributions()` and `ComplianceReportBuilder` follow. A
     * new SSS circular in `config/payroll.php` must not silently rewrite an
     * entry that was already posted to the ledger.
     *
     * **Only reportable runs are included.** A draft is still being corrected,
     * and a journal entry built from one is a number Finance would post and
     * then have to reverse.
     */
    public function journalSummary(Request $request, PayrollPeriod $period): JsonResponse
    {
        /*
         * Gated on the run policy rather than a new ability. The question
         * "may this caller read payroll money" has one answer in this system
         * and it already lives there; a second gate would be a second answer
         * waiting to disagree.
         */
        Gate::authorize('viewAny', PayrollRun::class);

        $runs = PayrollRun::query()
            ->where('payroll_period_id', $period->id)
            ->reportable()
            ->with('payslips')
            ->get();

        /*
         * 409 rather than 404 or an empty entry, the same distinction
         * `payrollRegister()` draws. The period exists and its runs are simply
         * not finalised yet — that is "retry later", not "wrong id", and
         * Finance needs to tell them apart. An empty journal would be worse
         * than either: a period posted as zero reads as a month nobody was
         * paid.
         */
        abort_if(
            $runs->isEmpty(),
            409,
            'No approved or paid run exists for this period yet. A draft is still being corrected.',
        );

        $payslips = $runs->flatMap->payslips;

        $sum = fn (string $column) => round((float) $payslips->sum($column), 2);

        /*
         * Time not worked is a **contra to salary expense**, not a payable.
         * Nobody is owed the money somebody lost to lateness — the company
         * simply spent less. Filing it as a liability would put four figures on
         * the balance sheet that will never be paid to anyone, which is the
         * kind of error that is found in an audit rather than in a reconciliation.
         */
        $timeNotWorked = round(
            $sum('late_deduction')
            + $sum('undertime_deduction')
            + $sum('absence_deduction')
            + $sum('unpaid_leave_deduction'),
            2,
        );

        $employerTotal = round(
            $sum('sss_employer') + $sum('philhealth_employer') + $sum('pagibig_employer'),
            2,
        );

        $debits = [
            // The earnings side, split the way the ledger wants it rather than
            // as one "salaries" line: an accountant asking "what did overtime
            // cost us this month" should not have to open a payslip.
            ['account' => 'Basic Pay Expense', 'amount' => $sum('basic_pay')],
            ['account' => 'Overtime Expense', 'amount' => $sum('overtime_pay')],
            ['account' => 'Night Differential Expense', 'amount' => $sum('night_diff_pay')],
            ['account' => 'Holiday Premium Expense', 'amount' => $sum('holiday_pay')],
            ['account' => 'Allowances Expense', 'amount' => $sum('allowances_total')],

            // The employer's own share, which is a cost to the company and not
            // withheld from anybody. It appears again as a payable below —
            // debit the expense, credit what is owed — so the two net out.
            ['account' => 'SSS Contributions Expense (Employer)', 'amount' => $sum('sss_employer')],
            ['account' => 'PhilHealth Contributions Expense (Employer)', 'amount' => $sum('philhealth_employer')],
            ['account' => 'Pag-IBIG Contributions Expense (Employer)', 'amount' => $sum('pagibig_employer')],
        ];

        $credits = array_values(array_filter([
            // Reduces the expense above rather than owing anybody — see the
            // note on $timeNotWorked.
            ['account' => 'Salaries Expense — Time Not Worked (contra)', 'amount' => $timeNotWorked],

            // What the agencies are owed: the employee's withholding and the
            // employer's share land in one payable each, because one cheque
            // goes to each agency.
            ['account' => 'SSS Payable', 'amount' => round($sum('sss_employee') + $sum('sss_employer'), 2)],
            ['account' => 'PhilHealth Payable', 'amount' => round($sum('philhealth_employee') + $sum('philhealth_employer'), 2)],
            ['account' => 'Pag-IBIG Payable', 'amount' => round($sum('pagibig_employee') + $sum('pagibig_employer'), 2)],
            ['account' => 'Withholding Tax Payable (BIR)', 'amount' => $sum('withholding_tax')],

            /*
             * A loan repayment is the company collecting on a receivable, not
             * earning anything. Core 3 approved the loan and answers to the
             * employee for it; this figure is what payroll took off the
             * payslip, and Core 3's balance has to move by exactly this.
             */
            ['account' => 'Employee Loans Receivable', 'amount' => $sum('loans_deduction')],
            ['account' => 'Other Deductions Payable', 'amount' => $sum('other_deductions')],

            // What the bank actually disburses. This is the figure Accounts
            // Payable pays out, and it is the same total `/register` lists per
            // employee.
            ['account' => 'Net Pay Payable', 'amount' => $sum('net_pay')],
        ], fn (array $line) => $line['amount'] != 0.0));

        $totalDebits = round(array_sum(array_column($debits, 'amount')), 2);
        $totalCredits = round(array_sum(array_column($credits, 'amount')), 2);

        return response()->json([
            'data' => [
                'debits' => array_values(array_filter($debits, fn ($l) => $l['amount'] != 0.0)),
                'credits' => $credits,
            ],
            'meta' => [
                'period' => $period->name,
                'start_date' => $period->start_date?->toDateString(),
                'end_date' => $period->end_date?->toDateString(),
                'pay_date' => $period->pay_date?->toDateString(),

                // Which runs this entry was built from, so a reconciliation
                // that disagrees has somewhere to start.
                'runs' => $runs->map(fn (PayrollRun $run) => [
                    'run_number' => $run->run_number,
                    'status' => $run->status,
                    'employee_count' => $run->employee_count,
                ])->values(),

                'employee_count' => $payslips->count(),
                'total_debits' => $totalDebits,
                'total_credits' => $totalCredits,

                /*
                 * **The reason this endpoint reports rather than just returns.**
                 *
                 * A journal entry that does not balance cannot be posted, and
                 * the figures here are read back from stored payslips — so if
                 * a payslip was ever written with `net_pay` that did not equal
                 * `gross_pay - deductions_total`, this is where it shows. Saying
                 * so is the difference between Finance catching it now and
                 * finding it in a trial balance at month end.
                 *
                 * Compared with a tolerance because these are two sums of
                 * rounded currency, not one number twice: a centavo of drift
                 * across four hundred payslips is arithmetic, not a defect.
                 */
                'balanced' => abs($totalDebits - $totalCredits) < 0.01,
                'out_of_balance_by' => round($totalDebits - $totalCredits, 2),

                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/v1/analytics/workforce — for **Core 4 (Reports & Dashboards)**
     * and **Business Intelligence**.
     *
     * Aggregates only: no names, no salaries, no government numbers. A
     * dashboard needs shapes, not people, and an endpoint that hands over the
     * directory to draw a bar chart is an endpoint that will one day be the
     * way the directory left.
     */
    public function workforce(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Employee::class);

        $scoped = $this->employees->scopedQuery($request->user());

        $from = Carbon::parse($request->query('from', now()->startOfMonth()->toDateString()));
        $to = Carbon::parse($request->query('to', now()->endOfMonth()->toDateString()));

        $attendance = $this->timekeeping->summary(
            AttendanceLog::whereIn('employee_id', (clone $scoped)->select('employees.id'))
                ->whereBetween('log_date', [$from->toDateString(), $to->toDateString()]),
        );

        return response()->json([
            'data' => [
                'headcount' => $this->employees->statistics(clone $scoped),

                'by_category' => (clone $scoped)
                    ->selectRaw('employment_category, count(*) as total')
                    ->groupBy('employment_category')
                    ->pluck('total', 'employment_category'),

                'by_client' => (clone $scoped)
                    ->join('clients', 'clients.id', '=', 'employees.client_id')
                    ->selectRaw('clients.name, count(*) as total')
                    ->groupBy('clients.name')
                    ->pluck('total', 'name'),

                'attendance' => $attendance,

                'leave' => $this->leave->summary(
                    LeaveRequest::whereIn(
                        'employee_id',
                        (clone $scoped)->select('employees.id'),
                    )->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from),
                ),
            ],
            'meta' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'generated_at' => now()->toIso8601String(),
                // What this figure is scoped to, so a consumer knows whether
                // it is looking at the whole workforce or one supervisor's.
                'scope' => $request->user()->isHrAdmin() ? 'organisation' : 'scoped_to_caller',
            ],
        ]);
    }
}
