<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\Kpi;
use App\Models\PerformanceReview;
use App\Models\ReviewCycle;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Module 5 — Performance Management.
 *
 * Owns persistence and the cycle workflow; PerformanceScorer does the maths.
 */
class PerformanceService
{
    public function __construct(
        private readonly PerformanceScorer $scorer,
        private readonly EmployeeService $employees,
    ) {}

    /** Reviews the viewer may see: their own, ones they wrote, or all for HR. */
    public function scopedQuery(User $user): Builder
    {
        $query = PerformanceReview::query()->with([
            'employee:id,employee_number,first_name,middle_name,last_name,suffix',
            'reviewCycle:id,name,status,period_start,period_end',
            'reviewer:id,name',
        ]);

        if ($user->isHrAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($user) {
            $inner->where('reviewer_id', $user->id)
                ->orWhereHas('employee', fn (Builder $e) => $e->where('user_id', $user->id));
        });
    }

    /**
     * Opens a cycle: builds each employee's scorecard from the KPI library and
     * creates the self and supervisor reviews they will fill in.
     *
     * Safe to re-run — existing scorecards and reviews are left alone.
     *
     * @return array{employees: int, scorecards: int, reviews: int}
     */
    public function rollout(ReviewCycle $cycle): array
    {
        return DB::transaction(function () use ($cycle) {
            $employees = Employee::where('status', '!=', 'inactive')->get();

            $scorecards = 0;
            $reviews = 0;

            foreach ($employees as $employee) {
                $scorecards += $this->buildScorecard($cycle, $employee);
                $reviews += $this->createReviews($cycle, $employee);
            }

            $cycle->update(['status' => ReviewCycle::STATUS_OPEN]);

            return [
                'employees' => $employees->count(),
                'scorecards' => $scorecards,
                'reviews' => $reviews,
            ];
        });
    }

    /**
     * Saves a reviewer's ratings and recomputes the review's overall score.
     *
     * @param  array<int, array{kpi_id: int, rating: float, actual_value?: float|null, comments?: string|null}>  $ratings
     */
    public function saveRatings(PerformanceReview $review, array $ratings, array $narrative = []): PerformanceReview
    {
        return DB::transaction(function () use ($review, $ratings, $narrative) {
            // Weights come from the employee's scorecard, never from the form —
            // a reviewer rates, they do not decide what counts.
            $weights = EmployeeKpi::where('employee_id', $review->employee_id)
                ->where('review_cycle_id', $review->review_cycle_id)
                ->pluck('weight', 'kpi_id');

            $scored = [];

            foreach ($ratings as $entry) {
                $weight = (float) ($weights[$entry['kpi_id']] ?? 0);

                $review->ratings()->updateOrCreate(
                    ['kpi_id' => $entry['kpi_id']],
                    [
                        'rating' => $entry['rating'],
                        'weight' => $weight,
                        'actual_value' => $entry['actual_value'] ?? null,
                        'comments' => $entry['comments'] ?? null,
                    ],
                );

                $scored[] = ['rating' => (float) $entry['rating'], 'weight' => $weight];
            }

            $review->update([
                ...$narrative,
                'overall_rating' => $this->scorer->reviewScore($scored),
            ]);

            return $review->refresh();
        });
    }

    public function submit(PerformanceReview $review): PerformanceReview
    {
        $review->update([
            'status' => PerformanceReview::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        return $review->refresh();
    }

    /** The employee signs off — the last step of a review. */
    public function acknowledge(PerformanceReview $review, ?string $comments = null): PerformanceReview
    {
        $review->update([
            'status' => PerformanceReview::STATUS_ACKNOWLEDGED,
            'acknowledged_at' => now(),
            'employee_comments' => $comments,
        ]);

        return $review->refresh();
    }

    /**
     * An employee's blended score for a cycle, plus the perspective breakdown.
     *
     * @return array{composite: float|null, band: array|null, perspectives: array<string, float|null>, reviews: int}
     */
    public function cycleResult(Employee $employee, ReviewCycle $cycle): array
    {
        $reviews = PerformanceReview::where('employee_id', $employee->id)
            ->where('review_cycle_id', $cycle->id)
            ->whereIn('status', [
                PerformanceReview::STATUS_SUBMITTED,
                PerformanceReview::STATUS_ACKNOWLEDGED,
            ])
            ->whereNotNull('overall_rating')
            ->get();

        $perspectives = [];

        foreach (PerformanceReview::REVIEWER_TYPES as $type) {
            // Several peers can review one person; average them first.
            $scores = $reviews->where('reviewer_type', $type)
                ->pluck('overall_rating')
                ->map(fn ($rating) => (float) $rating)
                ->all();

            $perspectives[$type] = $this->scorer->averageOf($scores);
        }

        $composite = $this->scorer->compositeScore($perspectives);

        return [
            'composite' => $composite,
            'band' => $this->scorer->band($composite),
            'perspectives' => $perspectives,
            'reviews' => $reviews->count(),
        ];
    }

    /**
     * Cycle-by-cycle history for one employee — the performance trend.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function history(Employee $employee): Collection
    {
        return ReviewCycle::whereHas(
            'reviews',
            fn (Builder $query) => $query->where('employee_id', $employee->id),
        )
            ->orderBy('period_start')
            ->get()
            ->map(function (ReviewCycle $cycle) use ($employee) {
                $result = $this->cycleResult($employee, $cycle);

                return [
                    'cycle_id' => $cycle->id,
                    'cycle' => $cycle->name,
                    'period_end' => $cycle->period_end->toDateString(),
                    'composite' => $result['composite'],
                    'band' => $result['band'],
                    'perspectives' => $result['perspectives'],
                    'reviews' => $result['reviews'],
                ];
            });
    }

    /** Progress figures for the cycle dashboard. */
    public function cycleProgress(ReviewCycle $cycle): array
    {
        $reviews = $cycle->reviews()->get(['status', 'overall_rating']);

        $completed = $reviews->whereIn('status', [
            PerformanceReview::STATUS_SUBMITTED,
            PerformanceReview::STATUS_ACKNOWLEDGED,
        ]);

        return [
            'total' => $reviews->count(),
            'draft' => $reviews->where('status', PerformanceReview::STATUS_DRAFT)->count(),
            'submitted' => $reviews->where('status', PerformanceReview::STATUS_SUBMITTED)->count(),
            'acknowledged' => $reviews->where('status', PerformanceReview::STATUS_ACKNOWLEDGED)->count(),
            'completion' => $reviews->count() > 0
                ? (int) round($completed->count() / $reviews->count() * 100)
                : 0,
            'average_rating' => $completed->count() > 0
                ? round((float) $completed->avg('overall_rating'), 2)
                : null,
        ];
    }

    /** Reviews waiting on this user to write. Drives the reviews screen badge. */
    public function pendingReviewsFor(User $user): int
    {
        return PerformanceReview::where('reviewer_id', $user->id)
            ->where('status', PerformanceReview::STATUS_DRAFT)
            ->whereHas('reviewCycle', fn (Builder $q) => $q->whereIn('status', [
                ReviewCycle::STATUS_OPEN,
                ReviewCycle::STATUS_IN_REVIEW,
            ]))
            ->count();
    }

    /** Copies the applicable KPI library onto the employee's scorecard. */
    private function buildScorecard(ReviewCycle $cycle, Employee $employee): int
    {
        $existing = EmployeeKpi::where('employee_id', $employee->id)
            ->where('review_cycle_id', $cycle->id)
            ->exists();

        if ($existing) {
            return 0;
        }

        $kpis = Kpi::applicableTo($employee)->get();

        if ($kpis->isEmpty()) {
            return 0;
        }

        // Distribute weight evenly when the library carries none, so a
        // scorecard always sums to the required total.
        $declared = (float) $kpis->sum('default_weight');
        $evenWeight = round(config('performance.required_weight_total') / $kpis->count(), 2);

        foreach ($kpis as $kpi) {
            EmployeeKpi::create([
                'employee_id' => $employee->id,
                'kpi_id' => $kpi->id,
                'review_cycle_id' => $cycle->id,
                'weight' => $declared > 0 ? $kpi->default_weight : $evenWeight,
            ]);
        }

        return $kpis->count();
    }

    /** Creates the self review, and the supervisor's, if there is one. */
    private function createReviews(ReviewCycle $cycle, Employee $employee): int
    {
        $created = 0;

        if ($employee->user_id) {
            $created += $this->ensureReview($cycle, $employee, $employee->user_id, 'self');
        }

        $supervisorUserId = $employee->supervisor?->user_id;

        if ($supervisorUserId) {
            $created += $this->ensureReview($cycle, $employee, $supervisorUserId, 'supervisor');
        }

        return $created;
    }

    private function ensureReview(ReviewCycle $cycle, Employee $employee, int $reviewerId, string $type): int
    {
        $review = PerformanceReview::firstOrCreate(
            [
                'employee_id' => $employee->id,
                'review_cycle_id' => $cycle->id,
                'reviewer_id' => $reviewerId,
                'reviewer_type' => $type,
            ],
            ['status' => PerformanceReview::STATUS_DRAFT],
        );

        return $review->wasRecentlyCreated ? 1 : 0;
    }
}
