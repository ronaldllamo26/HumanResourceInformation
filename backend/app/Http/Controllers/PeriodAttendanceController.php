<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePeriodAttendanceRequest;
use App\Models\AttendanceLog;
use App\Models\Client;
use App\Models\Department;
use App\Models\PayrollPeriod;
use App\Services\TimekeepingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 2 — the cutoff DTR sheet.
 *
 * Records counts how many days each employee came in over a cutoff; this
 * screen answers the question the agency's clients actually ask, which is
 * "here is a fortnight of attendance for the people you deployed to us".
 * Encoding that one modal at a time is 600 openings for forty people, so the
 * whole cutoff is one grid and one save.
 */
class PeriodAttendanceController extends Controller
{
    public function __construct(private readonly TimekeepingService $timekeeping) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AttendanceLog::class);

        $periods = PayrollPeriod::orderByDesc('start_date')->take(24)->get();
        $period = $this->resolvePeriod($request, $periods);

        $filters = [
            'department_id' => $request->query('department_id'),
            'client_id' => $request->query('client_id'),
            'search' => $request->query('search'),
        ];

        $sheet = $this->timekeeping->periodSheet(
            $request->user(),
            Carbon::parse($period['start_date'])->startOfDay(),
            Carbon::parse($period['end_date'])->startOfDay(),
            $filters,
        );

        return Inertia::render('HR/Timekeeping/Period', [
            'period' => $period,
            'periods' => $periods->map(fn (PayrollPeriod $row) => [
                'id' => $row->id,
                'name' => $row->name,
                'start_date' => $row->start_date->toDateString(),
                'end_date' => $row->end_date->toDateString(),
                'frequency' => $row->frequency,
            ]),
            'days' => $sheet['days'],
            'rows' => $sheet['rows'],
            'filters' => $filters,
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            // Deactivated clients still appear: staff deployed under one still
            // have a DTR to encode. Only *new* deployments are closed off.
            'clients' => Client::orderBy('name')->get(['id', 'name', 'is_active']),
            'statuses' => AttendanceLog::STATUSES,
            'today' => today()->toDateString(),
            'can' => [
                'manage' => $request->user()->can('create', AttendanceLog::class),
            ],
        ]);
    }

    public function store(StorePeriodAttendanceRequest $request): RedirectResponse
    {
        $result = $this->timekeeping->saveSheet(
            $request->user(),
            Carbon::parse($request->validated('from'))->startOfDay(),
            Carbon::parse($request->validated('to'))->startOfDay(),
            $request->validated('cells'),
        );

        return back()->with(
            $result['locked'] > 0 ? 'error' : 'success',
            $this->outcome($result),
        );
    }

    /**
     * The cutoff the sheet covers.
     *
     * Driven by payroll_periods rather than by a date range of its own, so the
     * DTR and the run that pays from it cannot come to cover different days.
     *
     * Where no period exists — a fresh install, or a cutoff nobody has opened
     * yet — the calendar half-month stands in and the screen says so. Refusing
     * to open would invert the order of the work: attendance is encoded while
     * the fortnight is running, and the payroll period is created when it is
     * time to pay.
     *
     * @param  Collection<int, PayrollPeriod>  $periods
     * @return array<string, mixed>
     */
    private function resolvePeriod(Request $request, Collection $periods): array
    {
        $chosen = $request->query('period_id')
            ? $periods->firstWhere('id', (int) $request->query('period_id'))
            : $periods->first(fn (PayrollPeriod $period) => $period->start_date->lte(today())
                && $period->end_date->gte(today()));

        if ($chosen !== null) {
            return [
                'id' => $chosen->id,
                'name' => $chosen->name,
                'start_date' => $chosen->start_date->toDateString(),
                'end_date' => $chosen->end_date->toDateString(),
                'frequency' => $chosen->frequency,
                'is_payroll_period' => true,
            ];
        }

        $today = today();
        $firstHalf = $today->day <= 15;

        $start = $firstHalf
            ? $today->copy()->startOfMonth()
            : $today->copy()->startOfMonth()->addDays(15);

        $end = $firstHalf
            ? $today->copy()->startOfMonth()->addDays(14)
            : $today->copy()->endOfMonth();

        return [
            'id' => null,
            'name' => $start->format('M j').' – '.$end->format('M j, Y'),
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'frequency' => 'semi_monthly',
            'is_payroll_period' => false,
        ];
    }

    /**
     * What the save did, in the order it matters.
     *
     * The locked count is stated rather than swallowed: those are days the
     * sheet declined to touch because a punch is already on them, and a save
     * that quietly did less than it appeared to is how somebody comes to
     * believe a correction was made.
     *
     * @param  array{saved: int, cleared: int, locked: int, skipped: int}  $result
     */
    private function outcome(array $result): string
    {
        if ($result['saved'] === 0 && $result['cleared'] === 0 && $result['locked'] === 0) {
            return 'Nothing to save — no day on the sheet changed.';
        }

        $parts = [];

        if ($result['saved'] > 0) {
            $parts[] = "{$result['saved']} day(s) recorded";
        }

        if ($result['cleared'] > 0) {
            $parts[] = "{$result['cleared']} cleared";
        }

        $message = $parts === []
            ? 'No day was changed.'
            : ucfirst(implode(', ', $parts)).'.';

        if ($result['locked'] > 0) {
            $message .= " {$result['locked']} day(s) left untouched — they already carry clocked times, "
                .'which only Records may change.';
        }

        return $message;
    }
}
