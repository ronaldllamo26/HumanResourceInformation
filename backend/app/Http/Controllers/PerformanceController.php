<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\PerformanceReview;
use App\Models\ReviewCycle;
use App\Models\User;
use App\Services\PerformanceScorer;
use App\Services\PerformanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 5 — evaluations: the review list and the evaluation form itself.
 */
class PerformanceController extends Controller
{
    public function __construct(
        private readonly PerformanceService $performance,
        private readonly PerformanceScorer $scorer,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PerformanceReview::class);

        $filters = $request->only(['status', 'review_cycle_id', 'reviewer_type', 'employee_id']);

        $reviews = $this->performance->scopedQuery($request->user())
            ->filter($filters)
            ->orderByRaw("case when status = 'draft' then 0 when status = 'submitted' then 1 else 2 end")
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('HR/Performance/Index', [
            'reviews' => [
                'data' => $reviews->map(fn (PerformanceReview $review) => [
                    'id' => $review->id,
                    'employee' => $review->employee?->full_name,
                    'employee_number' => $review->employee?->employee_number,
                    'cycle' => $review->reviewCycle?->name,
                    'reviewer' => $this->reviewerName($review, $request->user()),
                    'reviewer_type' => $review->reviewer_type,
                    'status' => $review->status,
                    'overall_rating' => $review->overall_rating ? (float) $review->overall_rating : null,
                    'band' => $this->scorer->band(
                        $review->overall_rating ? (float) $review->overall_rating : null,
                    ),
                    'is_mine_to_write' => $review->reviewer_id === $request->user()->id,
                    'can' => [
                        'update' => $request->user()->can('update', $review),
                        'acknowledge' => $request->user()->can('acknowledge', $review),
                    ],
                ]),
                'meta' => [
                    'from' => $reviews->firstItem(),
                    'to' => $reviews->lastItem(),
                    'total' => $reviews->total(),
                    'links' => $reviews->linkCollection()->toArray(),
                ],
            ],
            'filters' => $filters,
            'statuses' => PerformanceReview::STATUSES,
            'reviewerTypes' => PerformanceReview::REVIEWER_TYPES,
            'cycles' => ReviewCycle::orderByDesc('period_start')->get(['id', 'name']),
            'pendingForMe' => $this->performance->pendingReviewsFor($request->user()),
        ]);
    }

    /** The evaluation form — read-only once submitted. */
    public function show(Request $request, PerformanceReview $review): Response
    {
        Gate::authorize('view', $review);

        $review->load(['employee.position:id,title', 'reviewCycle', 'reviewer:id,name', 'ratings']);

        $scorecard = EmployeeKpi::with('kpi')
            ->where('employee_id', $review->employee_id)
            ->where('review_cycle_id', $review->review_cycle_id)
            ->get();

        $ratings = $review->ratings->keyBy('kpi_id');
        $weightTotal = (float) $scorecard->sum('weight');

        return Inertia::render('HR/Performance/Review', [
            'review' => [
                'id' => $review->id,
                'status' => $review->status,
                'reviewer_type' => $review->reviewer_type,
                'reviewer' => $this->reviewerName($review, $request->user()),
                'overall_rating' => $review->overall_rating ? (float) $review->overall_rating : null,
                'band' => $this->scorer->band(
                    $review->overall_rating ? (float) $review->overall_rating : null,
                ),
                'strengths' => $review->strengths,
                'areas_for_improvement' => $review->areas_for_improvement,
                'goals' => $review->goals,
                'reviewer_comments' => $review->reviewer_comments,
                'employee_comments' => $review->employee_comments,
                'submitted_at' => $review->submitted_at?->toIso8601String(),
                'acknowledged_at' => $review->acknowledged_at?->toIso8601String(),

                'employee' => [
                    'id' => $review->employee->id,
                    'full_name' => $review->employee->full_name,
                    'employee_number' => $review->employee->employee_number,
                    'position' => $review->employee->position?->title,
                ],
                'cycle' => [
                    'name' => $review->reviewCycle->name,
                    'period_start' => $review->reviewCycle->period_start->toDateString(),
                    'period_end' => $review->reviewCycle->period_end->toDateString(),
                    'status' => $review->reviewCycle->status,
                ],
            ],

            'scorecard' => $scorecard->map(fn (EmployeeKpi $line) => [
                'kpi_id' => $line->kpi_id,
                'title' => $line->kpi?->title,
                'description' => $line->kpi?->description,
                'category' => $line->kpi?->category,
                'measurement_unit' => $line->kpi?->measurement_unit,
                'weight' => (float) $line->weight,
                'target_value' => $line->target_value ? (float) $line->target_value : null,
                'rating' => isset($ratings[$line->kpi_id]) ? (float) $ratings[$line->kpi_id]->rating : null,
                'actual_value' => isset($ratings[$line->kpi_id]) && $ratings[$line->kpi_id]->actual_value !== null
                    ? (float) $ratings[$line->kpi_id]->actual_value
                    : null,
                'comments' => $ratings[$line->kpi_id]->comments ?? null,
            ]),

            'weightTotal' => round($weightTotal, 2),
            'weightsBalance' => $this->scorer->weightsBalance($weightTotal),
            'scale' => config('performance.rating_scale'),

            'can' => [
                'update' => $request->user()->can('update', $review),
                'submit' => $request->user()->can('submit', $review),
                'acknowledge' => $request->user()->can('acknowledge', $review),
            ],
        ]);
    }

    public function update(Request $request, PerformanceReview $review): RedirectResponse
    {
        Gate::authorize('update', $review);

        $scale = config('performance.rating_scale');

        $validated = $request->validate([
            'ratings' => ['required', 'array', 'min:1'],
            'ratings.*.kpi_id' => ['required', 'exists:kpis,id'],
            'ratings.*.rating' => ['required', 'numeric', "min:{$scale['min']}", "max:{$scale['max']}"],
            'ratings.*.actual_value' => ['nullable', 'numeric'],
            'ratings.*.comments' => ['nullable', 'string', 'max:1000'],
            'strengths' => ['nullable', 'string', 'max:2000'],
            'areas_for_improvement' => ['nullable', 'string', 'max:2000'],
            'goals' => ['nullable', 'string', 'max:2000'],
            'reviewer_comments' => ['nullable', 'string', 'max:2000'],
        ], [
            'ratings.required' => 'Rate at least one KPI before saving.',
            'ratings.*.rating.min' => "Ratings run from {$scale['min']} to {$scale['max']}.",
            'ratings.*.rating.max' => "Ratings run from {$scale['min']} to {$scale['max']}.",
        ]);

        $this->performance->saveRatings(
            $review,
            $validated['ratings'],
            array_intersect_key($validated, array_flip([
                'strengths', 'areas_for_improvement', 'goals', 'reviewer_comments',
            ])),
        );

        return back()->with('success', 'Evaluation saved.');
    }

    public function submit(PerformanceReview $review): RedirectResponse
    {
        Gate::authorize('submit', $review);

        $this->performance->submit($review);

        return back()->with('success', 'Evaluation submitted.');
    }

    public function acknowledge(Request $request, PerformanceReview $review): RedirectResponse
    {
        Gate::authorize('acknowledge', $review);

        $validated = $request->validate([
            'employee_comments' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->performance->acknowledge($review, $validated['employee_comments'] ?? null);

        return back()->with('success', 'Review acknowledged.');
    }

    /** Cycle-by-cycle history and the 360 breakdown for one employee. */
    public function history(Request $request, Employee $employee): Response
    {
        Gate::authorize('view', $employee);

        return Inertia::render('HR/Performance/History', [
            'employee' => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'employee_number' => $employee->employee_number,
                'position' => $employee->position?->title,
                'department' => $employee->department?->name,
            ],
            'history' => $this->performance->history($employee),
            'weights' => config('performance.reviewer_weights'),
        ]);
    }

    /**
     * Who wrote a review — withheld for peer and subordinate feedback.
     *
     * 360 feedback is only honest if the person being rated cannot tell which
     * colleague or which report said what; a named subordinate review is one
     * nobody writes candidly twice. HR still sees the name (to act on abuse),
     * and the writer sees their own. Self and supervisor reviews are named,
     * because who wrote those is never a secret.
     */
    private function reviewerName(PerformanceReview $review, User $viewer): ?string
    {
        $anonymous = in_array($review->reviewer_type, ['peer', 'subordinate'], true)
            && ! $viewer->isHrAdmin()
            && $review->reviewer_id !== $viewer->id;

        return $anonymous ? 'Anonymous '.$review->reviewer_type : $review->reviewer?->name;
    }
}
