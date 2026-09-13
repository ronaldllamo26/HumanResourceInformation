<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreHolidayRequest;
use App\Models\AttendanceLog;
use App\Models\Holiday;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 2 — the work calendar's holiday list.
 *
 * This is small but load-bearing, and it is shared: LeaveService::workingDays()
 * skips holidays when costing a leave request, AttendanceCalculator marks the
 * day's status from it, and PayrollCalculator pays the Labor Code premium on
 * it. A year with no holidays recorded is not an empty screen — it silently
 * charges employees leave credits for days they should not have been charged
 * for, so the screen warns when the year ahead has none.
 */
class HolidayController extends Controller
{
    public function index(Request $request): Response
    {
        $year = (int) $request->query('year', Carbon::now()->year);

        $holidays = Holiday::query()
            ->whereYear('date', $year)
            ->orderBy('date')
            ->get()
            ->map(fn (Holiday $holiday) => [
                'id' => $holiday->id,
                'name' => $holiday->name,
                'date' => $holiday->date->toDateString(),
                'day_of_week' => $holiday->date->format('l'),
                'type' => $holiday->type,
                'is_nationwide' => $holiday->is_nationwide,
                'pay_multiplier' => $holiday->payMultiplier(),
                // A holiday already worked against is history; deleting it
                // would silently re-cost leave and re-classify attendance.
                'has_attendance' => AttendanceLog::whereDate('log_date', $holiday->date)->exists(),
            ]);

        // Derived in PHP, not SQL: EXTRACT is Postgres, strftime is SQLite, and
        // this table holds a dozen rows a year — not worth a driver branch.
        $years = Holiday::orderBy('date')
            ->pluck('date')
            ->map(fn ($date) => (int) $date->year)
            ->unique()
            ->sortDesc()
            ->values();

        $nextYear = Carbon::now()->year + 1;

        return Inertia::render('HR/Timekeeping/Holidays', [
            'holidays' => $holidays,
            'filters' => ['year' => $year],
            'years' => $years->contains($year) ? $years : $years->prepend($year)->sort()->reverse()->values(),
            'summary' => [
                'total' => $holidays->count(),
                'regular' => $holidays->where('type', Holiday::TYPE_REGULAR)->count(),
                'special' => $holidays->where('type', Holiday::TYPE_SPECIAL)->count(),
            ],
            // Proclamations land late in the year, and nobody remembers to
            // check until leave is already being costed wrongly.
            'nextYear' => [
                'year' => $nextYear,
                'count' => Holiday::whereYear('date', $nextYear)->count(),
            ],
            'types' => [
                ['value' => Holiday::TYPE_REGULAR, 'label' => 'Regular Holiday'],
                ['value' => Holiday::TYPE_SPECIAL, 'label' => 'Special (Non-Working)'],
            ],
            'can' => ['manage' => $request->user()->isHrAdmin()],
        ]);
    }

    public function store(StoreHolidayRequest $request): RedirectResponse
    {
        Holiday::create($request->validated());

        return back()->with('success', 'Holiday added.');
    }

    public function update(StoreHolidayRequest $request, Holiday $holiday): RedirectResponse
    {
        $holiday->update($request->validated());

        return back()->with('success', 'Holiday updated.');
    }

    public function destroy(Request $request, Holiday $holiday): RedirectResponse
    {
        abort_unless($request->user()->isHrAdmin(), 403);

        // Attendance for that day was computed against this holiday. Removing
        // it now would leave those records classified against a rule that no
        // longer exists, so the day has to be corrected first.
        if (AttendanceLog::whereDate('log_date', $holiday->date)->exists()) {
            return back()->with(
                'error',
                'Attendance has already been recorded on this date. Correct those records before removing the holiday.',
            );
        }

        $holiday->delete();

        return back()->with('success', 'Holiday removed.');
    }
}
