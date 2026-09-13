<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * How many leave credits an employee has actually earned by a given date.
 *
 * The screen used to hand every active employee a full year's entitlement on
 * 1 January, which is wrong in the direction that costs money: someone hired
 * in November started with the same fifteen days as someone who had worked
 * the whole year, and could file all of them in December.
 *
 * Credits are earned per completed month of service instead — a 15-day type
 * accrues 1.25 days a month. An employee hired mid-year accrues from their
 * hire date; nobody accrues past the end of the year being computed.
 *
 * Database-free and unit tested, the same shape as the other calculators: it
 * takes dates and a number, and returns a number.
 */
class LeaveAccrualCalculator
{
    /**
     * Credits earned in `$year` as at `$asOf`.
     *
     * @param  float  $annualCredits  the leave type's full-year entitlement
     * @param  Carbon|null  $hiredOn  null means "present for the whole year"
     */
    public function earned(float $annualCredits, ?Carbon $hiredOn, int $year, Carbon $asOf): float
    {
        if ($annualCredits <= 0) {
            return 0.0;
        }

        $months = $this->monthsServed($hiredOn, $year, $asOf);

        if ($months <= 0) {
            return 0.0;
        }

        $perMonth = $annualCredits / (int) config('leave.accrual_months', 12);

        // Never more than the annual entitlement, however the dates fall.
        return round(
            min($perMonth * $months, $annualCredits),
            (int) config('leave.decimals', 2),
        );
    }

    /**
     * Whole calendar months of service inside the year, up to `$asOf`.
     *
     * A month is earned once the employee has been on staff for **all** of it.
     * Counting anniversaries instead would be off by a day at the year
     * boundary — 1 January to 31 December is 364 days, one short of twelve
     * anniversary months, so a full-year employee would end the year at 11/12
     * of their entitlement.
     *
     * Someone hired on 20 January therefore earns nothing for January: they
     * were not there for the whole of it.
     */
    public function monthsServed(?Carbon $hiredOn, int $year, Carbon $asOf): int
    {
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();

        // Accrual cannot run past the year being computed.
        $until = $asOf->copy()->startOfDay()->min($yearEnd);

        if ($until->lt($yearStart)) {
            return 0;
        }

        $start = $hiredOn?->copy()->startOfDay()->max($yearStart) ?? $yearStart;

        if ($start->gt($until)) {
            return 0;
        }

        // The waiting period delays the *first* credit; it does not forfeit
        // the months served during it, so the count below still runs from the
        // start date once the wait has passed.
        $waiting = (int) config('leave.months_before_accrual', 0);

        if ($waiting > 0 && $hiredOn !== null
            && $until->lt($hiredOn->copy()->addMonthsNoOverflow($waiting))) {
            return 0;
        }

        // First month that can be served in full: the start month when hired
        // on the 1st, otherwise the next one.
        $firstMonth = $start->day === 1
            ? $start->copy()->startOfMonth()
            : $start->copy()->addMonthNoOverflow()->startOfMonth();

        // Last month served in full: this one if we are at its final day,
        // otherwise the previous one.
        $lastMonth = $until->isLastOfMonth()
            ? $until->copy()->startOfMonth()
            : $until->copy()->subMonthNoOverflow()->startOfMonth();

        if ($firstMonth->gt($lastMonth)) {
            return 0;
        }

        return (int) $firstMonth->diffInMonths($lastMonth) + 1;
    }
}
