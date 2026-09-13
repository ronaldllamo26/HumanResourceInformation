<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\EmployeeKpi;
use App\Models\Kpi;
use App\Models\PerformanceReview;
use App\Models\ReviewCycle;
use App\Services\PerformanceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Builds a KPI library, rolls out last year's cycle with completed reviews, and
 * opens the current one — so the history, trend, and in-progress screens all
 * have something to show.
 */
class PerformanceSeeder extends Seeder
{
    /** [title, category, unit, weight, department code or null] */
    private const LIBRARY = [
        ['Attendance & Punctuality', 'Conduct', '%', 20, null],
        ['Quality of Work', 'Performance', '%', 25, null],
        ['Teamwork & Communication', 'Conduct', 'rating', 15, null],
        ['Initiative & Accountability', 'Performance', 'rating', 15, null],
        ['Policy Compliance', 'Conduct', '%', 25, null],
        ['Road Safety Record', 'Safety', 'incidents', 30, 'OPS'],
        ['Fuel Efficiency', 'Efficiency', 'km/L', 20, 'OPS'],
        ['On-time Delivery Rate', 'Service', '%', 25, 'OPS'],
        ['Preventive Maintenance Compliance', 'Safety', '%', 30, 'MNT'],
        ['Repair Turnaround Time', 'Efficiency', 'hours', 25, 'MNT'],
    ];

    public function run(PerformanceService $performance): void
    {
        if (ReviewCycle::exists()) {
            return;
        }

        $this->seedLibrary();

        $previous = $this->seedCompletedCycle($performance);
        $current = $this->seedOpenCycle($performance);

        $this->command?->info(
            "Seeded performance: {$previous} completed review(s) in the closed cycle, "
            ."{$current} evaluation(s) open in the current one.",
        );
    }

    private function seedLibrary(): void
    {
        $departments = Department::pluck('id', 'code');

        foreach (self::LIBRARY as [$title, $category, $unit, $weight, $departmentCode]) {
            Kpi::firstOrCreate(
                ['title' => $title],
                [
                    'description' => "Assessed against the {$category} standard for the period.",
                    'category' => $category,
                    'measurement_unit' => $unit,
                    'default_weight' => $weight,
                    'department_id' => $departmentCode ? $departments[$departmentCode] ?? null : null,
                    'is_active' => true,
                ],
            );
        }
    }

    /** Last year's cycle, rolled out, scored, and closed. */
    private function seedCompletedCycle(PerformanceService $performance): int
    {
        $year = Carbon::today()->year - 1;

        $cycle = ReviewCycle::create([
            'name' => "FY{$year} Annual Review",
            'type' => 'annual',
            'period_start' => "{$year}-01-01",
            'period_end' => "{$year}-12-31",
            'review_due_date' => ($year + 1).'-01-31',
            'status' => ReviewCycle::STATUS_DRAFT,
            'description' => 'Company-wide annual performance review.',
        ]);

        $performance->rollout($cycle);

        $scored = 0;

        foreach ($cycle->reviews()->get() as $review) {
            $this->scoreReview($performance, $review);
            $scored++;
        }

        $cycle->update(['status' => ReviewCycle::STATUS_CLOSED]);

        return $scored;
    }

    /** This year's cycle, open, with roughly half the evaluations done. */
    private function seedOpenCycle(PerformanceService $performance): int
    {
        $year = Carbon::today()->year;

        $cycle = ReviewCycle::create([
            'name' => "FY{$year} Annual Review",
            'type' => 'annual',
            'period_start' => "{$year}-01-01",
            'period_end' => "{$year}-12-31",
            'review_due_date' => ($year + 1).'-01-31',
            'status' => ReviewCycle::STATUS_DRAFT,
            'description' => 'In progress — evaluations are being collected.',
        ]);

        $performance->rollout($cycle);

        $reviews = $cycle->reviews()->get();

        foreach ($reviews as $index => $review) {
            // Leave the rest as drafts so the "still to complete" state is real.
            if ($index % 2 === 0) {
                $this->scoreReview($performance, $review);
            }
        }

        return $reviews->count();
    }

    /** Rates every KPI on the scorecard, then submits. */
    private function scoreReview(PerformanceService $performance, PerformanceReview $review): void
    {
        $scorecard = EmployeeKpi::where('employee_id', $review->employee_id)
            ->where('review_cycle_id', $review->review_cycle_id)
            ->get();

        if ($scorecard->isEmpty()) {
            return;
        }

        $ratings = $scorecard->map(fn (EmployeeKpi $line) => [
            'kpi_id' => $line->kpi_id,
            // Self reviews skew a little generous, which is what happens.
            'rating' => $review->reviewer_type === 'self'
                ? random_int(7, 10) / 2
                : random_int(5, 10) / 2,
        ])->all();

        $performance->saveRatings($review, $ratings, [
            'strengths' => 'Dependable through the period and steady under pressure.',
            'areas_for_improvement' => 'Could document handovers more consistently.',
            'goals' => 'Take the lead on one route optimisation next cycle.',
        ]);

        $performance->submit($review->refresh());
    }
}
