<?php

namespace App\Http\Controllers;

use App\Models\PerformanceReview;
use App\Models\ReviewCycle;
use App\Services\PerformanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 5 — review cycles. Rolling one out builds every scorecard and creates
 * the evaluations reviewers will fill in.
 */
class ReviewCycleController extends Controller
{
    public function __construct(private readonly PerformanceService $performance) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PerformanceReview::class);

        return Inertia::render('HR/Performance/Cycles', [
            'cycles' => ReviewCycle::orderByDesc('period_start')
                ->get()
                ->map(fn (ReviewCycle $cycle) => [
                    'id' => $cycle->id,
                    'name' => $cycle->name,
                    'type' => $cycle->type,
                    'period_start' => $cycle->period_start->toDateString(),
                    'period_end' => $cycle->period_end->toDateString(),
                    'review_due_date' => $cycle->review_due_date?->toDateString(),
                    'status' => $cycle->status,
                    'description' => $cycle->description,
                    'progress' => $this->performance->cycleProgress($cycle),
                ]),
            'types' => ReviewCycle::TYPES,
            'can' => ['manage' => $request->user()->can('manageCycles', PerformanceReview::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manageCycles', PerformanceReview::class);

        ReviewCycle::create($this->validated($request) + ['status' => ReviewCycle::STATUS_DRAFT]);

        return back()->with('success', 'Review cycle created. Roll it out when you are ready.');
    }

    public function update(Request $request, ReviewCycle $cycle): RedirectResponse
    {
        Gate::authorize('manageCycles', PerformanceReview::class);

        $cycle->update($this->validated($request));

        return back()->with('success', 'Review cycle updated.');
    }

    /**
     * Opens the cycle: every active employee gets a scorecard, plus a self
     * review and — where there is a supervisor — a supervisor review.
     */
    public function rollout(ReviewCycle $cycle): RedirectResponse
    {
        Gate::authorize('manageCycles', PerformanceReview::class);

        if ($cycle->status === ReviewCycle::STATUS_CLOSED) {
            return back()->with('error', 'A closed cycle cannot be rolled out again.');
        }

        $result = $this->performance->rollout($cycle);

        return back()->with(
            'success',
            "Rolled out to {$result['employees']} employee(s): "
            ."{$result['scorecards']} scorecard line(s) and {$result['reviews']} new evaluation(s).",
        );
    }

    public function close(ReviewCycle $cycle): RedirectResponse
    {
        Gate::authorize('manageCycles', PerformanceReview::class);

        $cycle->update(['status' => ReviewCycle::STATUS_CLOSED]);

        return back()->with('success', 'Cycle closed — no further evaluations can be filed.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(ReviewCycle::TYPES)],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'review_due_date' => ['nullable', 'date', 'after_or_equal:period_end'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [
            'review_due_date.after_or_equal' => 'Reviews cannot be due before the period ends.',
        ]);
    }
}
