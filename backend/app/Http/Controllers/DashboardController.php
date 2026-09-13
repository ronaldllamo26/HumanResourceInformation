<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\PerformanceReview;
use App\Models\User;
use App\Services\CredentialExpiryScanner;
use App\Services\EmployeeService;
use App\Services\LeaveService;
use App\Services\PerformanceScorer;
use App\Services\TimekeepingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * What the payroll tile shows a role that may not read company figures.
     *
     * A zeroed shape rather than a null, because the tile is still drawn — an
     * employee sees "Latest Payroll —", the same as before a run exists, and
     * learns nothing about what the company paid.
     */
    private const NO_PAYROLL = [
        'total_net' => 0,
        'period' => null,
        'status' => null,
    ];

    /** The window the "New Hires" tile counts over, and links with. */
    private const NEW_HIRE_DAYS = 30;

    public function __construct(
        private readonly EmployeeService $employees,
        private readonly CredentialExpiryScanner $credentials,
        private readonly LeaveService $leave,
        private readonly PerformanceScorer $scorer,
    ) {}

    public function __invoke(Request $request): mixed
    {
        $scoped = $this->employees->scopedQuery($request->user());
        $today = Carbon::today();

        /*
         * Company-wide figures — total payroll, everyone's leave, the status
         * mix — are HR's view of the organisation, not an employee's view of
         * themselves. `EmployeePolicy::viewSensitive` already draws this line
         * for salary on a record; the dashboard has to draw the same one, or
         * a rank-and-file login reads the month's total net off the landing
         * page.
         */
        $canViewCompanyFigures = $request->user()->isHrAdmin();

        $data = [
            'can' => ['viewCompanyFigures' => $canViewCompanyFigures],
            'profile' => $this->profile($request->user(), $today),
            'statistics' => $this->statistics($scoped),
            'headcountByDepartment' => $this->headcountByDepartment(),
            'headcountTrend' => $this->headcountTrend($today),
            'statusMix' => $this->statusMix(),
            'attendanceToday' => $this->attendanceToday($today),
            'leaveToday' => $this->leaveToday($today),
            'approvals' => $this->approvals($request),
            'payroll' => $canViewCompanyFigures ? $this->latestPayroll() : self::NO_PAYROLL,
            'leaveSummary' => $canViewCompanyFigures ? $this->leaveSummary($today) : null,
            'payrollSummary' => $canViewCompanyFigures ? $this->payrollSummary() : null,
            'onboardingSummary' => $this->onboardingSummary($scoped, $today),
            'recentHires' => (clone $scoped)
                ->whereNotNull('date_hired')
                ->orderByDesc('date_hired')
                ->limit(6)
                ->get()
                ->map(fn (Employee $employee) => [
                    'id' => $employee->id,
                    'full_name' => $employee->full_name,
                    'position' => $employee->position?->title,
                    'date_hired' => $employee->date_hired?->toDateString(),
                ]),
        ];

        if ($request->is('api/*') && ! $request->header('X-Inertia')) {
            return response()->json($data);
        }

        return Inertia::render('Dashboard', $data);
    }

    /**
     * The signed-in person's own record, and the ways into it.
     *
     * Everything else on this screen is the company looking at itself: how
     * many people, whose leave is waiting, what payroll came to. None of it
     * answers the first question somebody actually has on landing here, which
     * is *where do I go* — and for a rank-and-file login, which is most of the
     * workforce, none of the figures above are even theirs to act on.
     *
     * **`employee` is null for a login with no 201 file, and that is a real
     * case rather than a defensive check.** An administrator need not be an
     * employee at all — a pure system account has no record, no department,
     * and no payslip — so the card falls back to the account itself and drops
     * the links that would 404 for them.
     *
     * **Salary is here, and it is here because it is the reader's own.**
     * `EmployeePolicy::viewSensitive` returns true for HR *and* for the person
     * the record belongs to — an employee has always been able to open their
     * own 201 file and read their own rate. The gate is asked rather than
     * assumed, so the day somebody widens this card to another person's record
     * the compensation block stops being drawn on its own. Government numbers
     * and the bank account stay out regardless: they are what a stolen dump is
     * worth stealing, and nothing on a landing page needs them.
     *
     * @return array<string, mixed>
     */
    private function profile(User $user, Carbon $today): array
    {
        $employee = $user->employee()
            // `full_name` is built from four columns, so a narrower select
            // would silently drop the middle initial and the suffix.
            ->with([
                'department:id,name',
                'position:id,title',
                'client:id,name',
                'supervisor:id,first_name,middle_name,last_name,suffix',
            ])
            ->first();

        return [
            // The employee record names the person; the account only names the
            // login. They are meant to agree and nothing reconciles them, so
            // the record wins where there is one.
            'name' => $employee?->full_name ?? $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'employee' => $employee === null ? null : [
                'id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'photo_url' => $employee->photo_path ? asset('storage/'.$employee->photo_path) : null,
                'position' => $employee->position?->title,
                'department' => $employee->department?->name,
                'client' => $employee->client?->name,
                'employment_status' => $employee->employment_status,
                'employment_category' => $employee->employment_category,
                'date_hired' => $employee->date_hired?->toDateString(),
                'supervisor' => $employee->supervisor?->full_name,

                // Asked of the policy, not inferred from "it is their own
                // record" — see the note above.
                'compensation' => $user->can('viewSensitive', $employee) ? [
                    'basic_salary' => (float) $employee->basic_salary,
                    'pay_frequency' => $employee->pay_frequency,
                ] : null,

                'attendance' => $this->ownAttendance($employee, $today),
            ],
        ];
    }

    /**
     * How the reader's own month is going: days in, days missed.
     *
     * Counted from `attendance_logs` in one grouped query rather than a row
     * per day, and "came in" is `TimekeepingService::PRESENT_STATUSES` rather
     * than a fourth private copy of that list — a day somebody was late for is
     * still a day they were there, and four screens already agree on that.
     *
     * @return array{month: string, from: string, to: string, present: int, absent: int}
     */
    private function ownAttendance(Employee $employee, Carbon $today): array
    {
        $from = $today->copy()->startOfMonth();
        $to = $today->copy()->endOfMonth();

        $counts = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('log_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'month' => $today->format('F Y'),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'present' => (int) collect(TimekeepingService::PRESENT_STATUSES)
                ->sum(fn (string $status) => (int) ($counts[$status] ?? 0)),
            'absent' => (int) ($counts[AttendanceLog::STATUS_ABSENT] ?? 0),
        ];
    }

    /**
     * Active headcount at the close of each of the last twelve months.
     *
     * Cumulative rather than hires-per-month: with a workforce this size most
     * individual months would be a zero, and a chart that is mostly zero shows
     * nothing. Read from `date_hired` against the whole table, so it does not
     * depend on attendance having been recorded.
     *
     * @return array<int, array{label: string, value: int}>
     */
    private function headcountTrend(Carbon $today): array
    {
        // One query, then counted in PHP — twelve separate COUNTs would be
        // twelve round trips for a figure this small.
        $hires = Employee::whereNotNull('date_hired')
            ->pluck('date_hired')
            ->map(fn ($date) => Carbon::parse($date));

        $separations = Employee::whereNotNull('date_separated')
            ->pluck('date_separated')
            ->map(fn ($date) => Carbon::parse($date));

        $months = [];

        for ($offset = 11; $offset >= 0; $offset--) {
            $endOfMonth = $today->copy()->subMonths($offset)->endOfMonth();

            $months[] = [
                'label' => $endOfMonth->format('M'),
                'value' => $hires->filter(fn (Carbon $date) => $date->lte($endOfMonth))->count()
                    - $separations->filter(fn (Carbon $date) => $date->lte($endOfMonth))->count(),
            ];
        }

        return $months;
    }

    /**
     * Leave activity this month, plus the request most recently filed.
     *
     * @return array<string, mixed>
     */
    private function leaveSummary(Carbon $today): array
    {
        $monthStart = $today->copy()->startOfMonth();

        $counts = LeaveRequest::where('created_at', '>=', $monthStart)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $latest = LeaveRequest::with(['employee:id,first_name,middle_name,last_name,suffix', 'leaveType:id,name'])
            ->latest('id')
            ->first();

        return [
            'pending' => (int) ($counts[LeaveRequest::STATUS_PENDING] ?? 0),
            'approved' => (int) ($counts[LeaveRequest::STATUS_APPROVED] ?? 0),
            'rejected' => (int) ($counts[LeaveRequest::STATUS_REJECTED] ?? 0),

            /*
             * The month these figures were counted over, published so the
             * tiles can carry it into their links rather than the page
             * deriving a second opinion about when "this month" started. A
             * tile counting 6 that opens a list of 31 has replaced the
             * question it raised.
             */
            'filed_from' => $monthStart->toDateString(),

            'latest' => $latest === null ? null : [
                'id' => $latest->id,
                /*
                 * There is no screen for a single leave request — the list is
                 * where one is read and decided on. So the preview opens that
                 * person's leave rather than a record that does not exist.
                 */
                'employee_id' => $latest->employee_id,
                'title' => $latest->employee?->full_name ?? 'Unknown employee',
                'subtitle' => trim(sprintf(
                    '%s · %s',
                    $latest->leaveType?->name ?? 'Leave',
                    $latest->start_date?->format('M j, Y') ?? '',
                ), ' ·'),
                'status' => $latest->status,
            ],
        ];
    }

    /**
     * Where payroll stands, and the run most recently touched.
     *
     * @return array<string, mixed>
     */
    private function payrollSummary(): array
    {
        $latest = PayrollRun::with('period')->latest('id')->first();

        return [
            /*
             * Counted as *periods carrying a run at that stage*, because that
             * is what `/hr/payroll` lists — one row per period, showing its
             * latest run. Counting runs instead would read correctly and open
             * a shorter list the moment any period was ever run twice, which
             * is the failure that is invisible until it matters.
             */
            'draft' => $this->periodsWithRunAt(PayrollRun::STATUS_DRAFT),
            'for_approval' => $this->periodsWithRunAt(PayrollRun::STATUS_FOR_APPROVAL),
            // Only finalised runs are money that has actually moved — the same
            // rule PayrollRun::REPORTABLE holds for every downstream screen.
            'released' => $this->periodsWithRunAt('released'),
            'latest' => $latest === null ? null : [
                'id' => $latest->id,
                'title' => $latest->period?->name ?? 'Payroll run',
                'subtitle' => 'Net '.$this->peso((float) $latest->total_net),
                'status' => $latest->status,
            ],
        ];
    }

    /**
     * Periods whose run sits at one stage — the figures the Payroll card
     * shows, counted by the same clause `PayrollController::index` filters on
     * so the tile and the list it opens cannot disagree.
     */
    private function periodsWithRunAt(string $status): int
    {
        return PayrollPeriod::whereHas(
            'runs',
            fn ($run) => $status === 'released'
                ? $run->reportable()
                : $run->where('status', $status),
        )->count();
    }

    /**
     * The peso figure as this system writes it everywhere else.
     *
     * The dashboard printed a bare "Net 762,899.62" — a number with no unit on
     * a screen that also shows headcounts, day counts, and percentages.
     */
    private function peso(float $amount): string
    {
        return '₱'.number_format($amount, 2);
    }

    /**
     * The 201-file health figures.
     *
     * `expiring` is read from `CredentialExpiryScanner` rather than counted
     * here, and that is a correction rather than a preference. This method
     * used to apply a flat 60-day window of its own, while the Credentials
     * screen applies a window *per document type* — 60 days for an LTO licence
     * because a renewal needs the lead time, 30 for a certificate because it
     * does not. So the two screens reported different numbers for the same
     * documents, and the tile opened a list that did not match it. One scan
     * over the scoped set is one query; the saving was never worth two screens
     * disagreeing about the same licence.
     *
     * `OnboardingChecker` is still deliberately not called: it walks every
     * employee to build a findings list, which is the right shape for its own
     * screen and the wrong one for a tile that needs a count.
     *
     * @return array<string, mixed>
     */
    private function onboardingSummary($scoped, Carbon $today): array
    {
        $newHires = (clone $scoped)
            ->where('date_hired', '>=', $today->copy()->subDays(self::NEW_HIRE_DAYS))
            ->count();

        $expiring = $this->credentials
            ->scan(EmployeeDocument::whereIn('employee_id', (clone $scoped)->select('employees.id')))
            ->where('status', CredentialExpiryScanner::STATUS_EXPIRING)
            ->count();

        $withoutDocuments = (clone $scoped)
            ->where('status', 'active')
            ->whereDoesntHave('documents')
            ->count();

        $latest = (clone $scoped)
            ->whereNotNull('date_hired')
            ->orderByDesc('date_hired')
            ->first();

        return [
            'new_hires' => $newHires,
            'new_hire_days' => self::NEW_HIRE_DAYS,
            'expiring' => $expiring,
            'without_documents' => $withoutDocuments,
            'latest' => $latest === null ? null : [
                'id' => $latest->id,
                'title' => $latest->full_name,
                'subtitle' => $latest->position?->title ?? 'No position assigned',
                'status' => $latest->employment_status,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function statistics($scoped): array
    {
        $base = $this->employees->statistics($scoped);

        // The company-wide average from the most recent scored cycle.
        $average = PerformanceReview::whereIn('status', [
            PerformanceReview::STATUS_SUBMITTED,
            PerformanceReview::STATUS_ACKNOWLEDGED,
        ])->whereNotNull('overall_rating')->avg('overall_rating');

        $average = $average !== null ? round((float) $average, 2) : null;

        $band = $this->scorer->band($average);

        return [
            ...$base,
            'average_rating' => $average,
            'performance_band' => $band['label'] ?? null,
            // The band already knows where the score sits on the ramp; the
            // dashboard reads it rather than deriving its own cut-offs.
            'performance_band_variant' => $band['variant'] ?? null,
            'headcount_change' => $this->headcountChange($scoped),
        ];
    }

    /**
     * Net joiners over the last 30 days — the delta shown under the headcount
     * tile.
     *
     * Returned as a signed integer with no percentage: against a workforce of
     * a few dozen, one hire is a swing of several percent, and a figure that
     * jumps like that reads as volatility rather than as information.
     */
    private function headcountChange($scoped): int
    {
        $since = Carbon::today()->subDays(30);

        $joined = (clone $scoped)->where('date_hired', '>=', $since)->count();
        $left = (clone $scoped)->where('date_separated', '>=', $since)->count();

        return $joined - $left;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function headcountByDepartment()
    {
        return Department::query()
            ->withCount(['employees' => fn ($query) => $query->where('status', 'active')])
            ->orderByDesc('employees_count')
            ->get(['id', 'name'])
            ->map(fn (Department $department) => [
                // Carried so the bar can open the people it measured. The bar
                // counts active records, so the link says `status=active` too
                // — the same narrowing, not merely the same department.
                'id' => $department->id,
                'name' => $department->name,
                'count' => $department->employees_count,
            ]);
    }

    /**
     * Employment status mix for the donut. Capped at four slices because the
     * chart palette is only validated for four.
     *
     * @return array<int, array{label: string, count: int}>
     */
    private function statusMix(): array
    {
        $counts = Employee::selectRaw('employment_status, count(*) as total')
            ->groupBy('employment_status')
            ->pluck('total', 'employment_status');

        $regular = (int) ($counts['regular'] ?? 0);
        $probationary = (int) ($counts['probationary'] ?? 0);
        $contractual = (int) ($counts['contractual'] ?? 0) + (int) ($counts['project-based'] ?? 0);
        $separated = (int) ($counts['resigned'] ?? 0) + (int) ($counts['terminated'] ?? 0);

        /*
         * Each slice carries the filter that returns exactly the records it
         * counted, written here beside the grouping rather than restated in
         * the component. Two of them cover a pair of statuses — a slice that
         * added `contractual` and `project-based` and then opened only the
         * contractual ones would be answering a different question from the
         * one it asked.
         */
        return [
            ['label' => 'Regular', 'count' => $regular, 'filter' => 'regular'],
            ['label' => 'Probationary', 'count' => $probationary, 'filter' => 'probationary'],
            ['label' => 'Contractual', 'count' => $contractual, 'filter' => 'contractual,project-based'],
            ['label' => 'Separated', 'count' => $separated, 'filter' => 'resigned,terminated'],
        ];
    }

    /** @return array<string, int> */
    private function attendanceToday(Carbon $today): array
    {
        $logs = AttendanceLog::whereDate('log_date', $today)
            ->selectRaw("sum(case when status in ('present','late','undertime') then 1 else 0 end) as present")
            ->selectRaw('sum(case when late_minutes > 0 then 1 else 0 end) as late')
            ->selectRaw("sum(case when status = 'absent' then 1 else 0 end) as absent")
            ->first();

        $present = (int) $logs->present;
        $absent = (int) $logs->absent;

        // "Expected" is whoever has a record for today; without one there is
        // nothing to measure a rate against.
        $expected = $present + $absent;

        return [
            'present' => $present,
            'late' => (int) $logs->late,
            'absent' => $absent,
            'expected' => $expected,
            'rate' => $expected > 0 ? (int) round($present / $expected * 100) : 0,
        ];
    }

    /** @return array<string, mixed> */
    private function leaveToday(Carbon $today): array
    {
        $away = LeaveRequest::with('leaveType:id,code')
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->overlapping($today->toDateString(), $today->toDateString())
            ->get();

        $byType = $away->groupBy(fn (LeaveRequest $request) => $request->leaveType?->code ?? '—')
            ->map->count()
            ->map(fn ($count, $code) => "{$count} {$code}")
            ->values()
            ->implode(' · ');

        return ['count' => $away->count(), 'summary' => $byType];
    }

    /** What the signed-in user still has to act on. @return array<string, int> */
    private function approvals(Request $request): array
    {
        return [
            'leave' => $this->leave->pendingApprovalsFor($request->user()),
            'overtime' => $request->user()->isHrAdmin()
                ? OvertimeRequest::where('status', OvertimeRequest::STATUS_PENDING)->count()
                : 0,
            'reviews' => PerformanceReview::where('reviewer_id', $request->user()->id)
                ->where('status', PerformanceReview::STATUS_DRAFT)
                ->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function latestPayroll(): array
    {
        $run = PayrollRun::with('period')->latest('id')->first();

        return [
            'total_net' => $run ? (float) $run->total_net : 0,
            'period' => $run?->period?->name,
            'status' => $run?->status,
        ];
    }
}
