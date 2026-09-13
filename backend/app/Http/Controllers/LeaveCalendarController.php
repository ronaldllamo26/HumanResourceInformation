<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\LeaveRequest;
use App\Services\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 3 — team leave calendar. Shows who is away, so approvers can spot a
 * month where half the drivers booked the same week.
 */
class LeaveCalendarController extends Controller
{
    public function __construct(private readonly LeaveService $leave) {}

    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAny', LeaveRequest::class);

        $month = $request->query('month')
            ? Carbon::parse($request->query('month').'-01')
            : Carbon::today()->startOfMonth();

        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        $query = $this->leave->scopedQuery($request->user())
            ->when(
                $request->query('department_id'),
                fn ($q, $value) => $q->whereHas('employee', fn ($inner) => $inner->where('department_id', $value)),
            );

        return Inertia::render('HR/Leave/Calendar', [
            'month' => $month->format('Y-m'),
            'monthLabel' => $month->format('F Y'),
            'previousMonth' => $month->copy()->subMonth()->format('Y-m'),
            'nextMonth' => $month->copy()->addMonth()->format('Y-m'),
            // Blank days before the 1st, so the grid starts on the right weekday.
            'leadingBlanks' => $from->dayOfWeekIso - 1,
            'daysInMonth' => $from->daysInMonth,
            'entries' => $this->leave->calendarEntries($query, $from, $to),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'filters' => ['department_id' => $request->query('department_id')],
        ]);
    }
}
