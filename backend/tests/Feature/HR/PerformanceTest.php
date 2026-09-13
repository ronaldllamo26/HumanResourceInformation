<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\Kpi;
use App\Models\PerformanceReview;
use App\Models\ReviewCycle;
use App\Models\User;
use App\Services\PerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PerformanceTest extends TestCase
{
    use RefreshDatabase;

    // --- Cycles and rollout -------------------------------------------------

    public function test_hr_can_create_a_review_cycle(): void
    {
        $this->actingAs($this->hr())
            ->post('/hr/performance/cycles', [
                'name' => 'FY2026 Annual Review',
                'type' => 'annual',
                'period_start' => '2026-01-01',
                'period_end' => '2026-12-31',
                'review_due_date' => '2027-01-15',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('review_cycles', [
            'name' => 'FY2026 Annual Review',
            'status' => ReviewCycle::STATUS_DRAFT,
        ]);
    }

    public function test_reviews_cannot_be_due_before_the_period_ends(): void
    {
        $this->actingAs($this->hr())
            ->post('/hr/performance/cycles', [
                'name' => 'Backwards',
                'type' => 'annual',
                'period_start' => '2026-01-01',
                'period_end' => '2026-12-31',
                'review_due_date' => '2026-06-01',
            ])
            ->assertSessionHasErrors('review_due_date');
    }

    public function test_rollout_builds_scorecards_and_creates_evaluations(): void
    {
        $cycle = $this->cycle();
        Kpi::factory()->count(3)->create();

        $supervisorUser = User::factory()->supervisor()->create();
        $supervisor = Employee::factory()->create(['user_id' => $supervisorUser->id]);

        $employeeUser = User::factory()->create();
        Employee::factory()->create([
            'user_id' => $employeeUser->id,
            'supervisor_id' => $supervisor->id,
        ]);

        $this->actingAs($this->hr())
            ->post("/hr/performance/cycles/{$cycle->id}/rollout")
            ->assertRedirect();

        $this->assertSame(ReviewCycle::STATUS_OPEN, $cycle->fresh()->status);

        // Both employees get a scorecard of three KPIs.
        $this->assertDatabaseCount('employee_kpis', 6);

        // Two self reviews, plus one supervisor review for the report.
        $this->assertDatabaseCount('performance_reviews', 3);
        $this->assertDatabaseHas('performance_reviews', [
            'reviewer_id' => $supervisorUser->id,
            'reviewer_type' => 'supervisor',
        ]);
    }

    public function test_rollout_is_safe_to_run_twice(): void
    {
        $cycle = $this->cycle();
        Kpi::factory()->count(2)->create();
        Employee::factory()->create(['user_id' => User::factory()->create()->id]);
        $hr = $this->hr();

        $this->actingAs($hr)->post("/hr/performance/cycles/{$cycle->id}/rollout");
        $this->actingAs($hr)->post("/hr/performance/cycles/{$cycle->id}/rollout");

        $this->assertDatabaseCount('employee_kpis', 2);
        $this->assertDatabaseCount('performance_reviews', 1);
    }

    public function test_scorecard_weight_is_split_evenly_when_the_library_declares_none(): void
    {
        $cycle = $this->cycle();
        Kpi::factory()->count(4)->create(['default_weight' => 0]);
        Employee::factory()->create(['user_id' => User::factory()->create()->id]);

        $this->actingAs($this->hr())->post("/hr/performance/cycles/{$cycle->id}/rollout");

        $this->assertEquals(100.0, (float) EmployeeKpi::sum('weight'));
    }

    public function test_a_closed_cycle_cannot_be_rolled_out_again(): void
    {
        $cycle = $this->cycle(['status' => ReviewCycle::STATUS_CLOSED]);

        $this->actingAs($this->hr())
            ->post("/hr/performance/cycles/{$cycle->id}/rollout")
            ->assertSessionHas('error');
    }

    public function test_only_hr_can_manage_cycles(): void
    {
        $user = User::factory()->create();
        Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->post('/hr/performance/cycles', [
            'name' => 'Sneaky',
            'type' => 'annual',
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ])->assertForbidden();
    }

    // --- The evaluation form ------------------------------------------------

    public function test_a_reviewer_can_score_their_evaluation(): void
    {
        [$review, $kpis] = $this->openReview();

        $this->actingAs($review->reviewer)
            ->put("/hr/performance/reviews/{$review->id}", [
                'ratings' => [
                    ['kpi_id' => $kpis[0]->id, 'rating' => 5],
                    ['kpi_id' => $kpis[1]->id, 'rating' => 3],
                ],
                'strengths' => 'Reliable on every dispatch.',
            ])
            ->assertRedirect();

        $review->refresh();

        // Even weights, so the score is the plain mean.
        $this->assertEquals(4.0, (float) $review->overall_rating);
        $this->assertSame('Reliable on every dispatch.', $review->strengths);
        $this->assertDatabaseCount('performance_review_ratings', 2);
    }

    public function test_weights_come_from_the_scorecard_not_the_form(): void
    {
        [$review, $kpis] = $this->openReview();

        // Skew the scorecard: the first KPI is worth four times the second.
        EmployeeKpi::where('kpi_id', $kpis[0]->id)->update(['weight' => 80]);
        EmployeeKpi::where('kpi_id', $kpis[1]->id)->update(['weight' => 20]);

        $this->actingAs($review->reviewer)->put("/hr/performance/reviews/{$review->id}", [
            'ratings' => [
                ['kpi_id' => $kpis[0]->id, 'rating' => 5, 'weight' => 1],
                ['kpi_id' => $kpis[1]->id, 'rating' => 1, 'weight' => 99],
            ],
        ]);

        // (5 x 80 + 1 x 20) / 100 = 4.20 — the posted weights are ignored.
        $this->assertEquals(4.2, (float) $review->fresh()->overall_rating);
    }

    public function test_ratings_outside_the_scale_are_rejected(): void
    {
        [$review, $kpis] = $this->openReview();

        $this->actingAs($review->reviewer)
            ->put("/hr/performance/reviews/{$review->id}", [
                'ratings' => [['kpi_id' => $kpis[0]->id, 'rating' => 9]],
            ])
            ->assertSessionHasErrors('ratings.0.rating');
    }

    public function test_only_the_assigned_reviewer_can_score(): void
    {
        [$review, $kpis] = $this->openReview();

        $this->actingAs($this->hr())
            ->put("/hr/performance/reviews/{$review->id}", [
                'ratings' => [['kpi_id' => $kpis[0]->id, 'rating' => 5]],
            ])
            ->assertForbidden();
    }

    public function test_a_submitted_review_can_no_longer_be_edited(): void
    {
        [$review, $kpis] = $this->openReview();
        $reviewer = $review->reviewer;

        $this->actingAs($reviewer)->put("/hr/performance/reviews/{$review->id}", [
            'ratings' => [['kpi_id' => $kpis[0]->id, 'rating' => 4]],
        ]);
        $this->actingAs($reviewer)->post("/hr/performance/reviews/{$review->id}/submit");

        $this->actingAs($reviewer)
            ->put("/hr/performance/reviews/{$review->id}", [
                'ratings' => [['kpi_id' => $kpis[0]->id, 'rating' => 1]],
            ])
            ->assertForbidden();
    }

    public function test_an_empty_review_cannot_be_submitted(): void
    {
        [$review] = $this->openReview();

        $this->actingAs($review->reviewer)
            ->post("/hr/performance/reviews/{$review->id}/submit")
            ->assertForbidden();
    }

    public function test_a_closed_cycle_locks_its_evaluations(): void
    {
        [$review, $kpis] = $this->openReview();
        $review->reviewCycle->update(['status' => ReviewCycle::STATUS_CLOSED]);

        $this->actingAs($review->reviewer)
            ->put("/hr/performance/reviews/{$review->id}", [
                'ratings' => [['kpi_id' => $kpis[0]->id, 'rating' => 4]],
            ])
            ->assertForbidden();
    }

    // --- Acknowledgement ----------------------------------------------------

    public function test_the_employee_acknowledges_a_submitted_review(): void
    {
        [$review, $kpis, $employeeUser] = $this->supervisorReview();

        $this->actingAs($review->reviewer)->put("/hr/performance/reviews/{$review->id}", [
            'ratings' => [['kpi_id' => $kpis[0]->id, 'rating' => 4]],
        ]);
        $this->actingAs($review->reviewer)->post("/hr/performance/reviews/{$review->id}/submit");

        $this->actingAs($employeeUser)
            ->post("/hr/performance/reviews/{$review->id}/acknowledge", [
                'employee_comments' => 'Noted, thank you.',
            ])
            ->assertRedirect();

        $review->refresh();

        $this->assertSame(PerformanceReview::STATUS_ACKNOWLEDGED, $review->status);
        $this->assertSame('Noted, thank you.', $review->employee_comments);
    }

    public function test_nobody_else_can_acknowledge_a_review(): void
    {
        [$review, $kpis] = $this->supervisorReview();

        $this->actingAs($review->reviewer)->put("/hr/performance/reviews/{$review->id}", [
            'ratings' => [['kpi_id' => $kpis[0]->id, 'rating' => 4]],
        ]);
        $this->actingAs($review->reviewer)->post("/hr/performance/reviews/{$review->id}/submit");

        $this->actingAs($this->hr())
            ->post("/hr/performance/reviews/{$review->id}/acknowledge")
            ->assertForbidden();
    }

    // --- Scoping and history ------------------------------------------------

    public function test_an_employee_sees_only_their_own_and_their_written_reviews(): void
    {
        $cycle = $this->cycle(['status' => ReviewCycle::STATUS_OPEN]);
        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        PerformanceReview::factory()->create([
            'employee_id' => $employee->id,
            'review_cycle_id' => $cycle->id,
            'reviewer_id' => $user->id,
            'reviewer_type' => 'self',
        ]);
        PerformanceReview::factory()->count(3)->create(['review_cycle_id' => $cycle->id]);

        $this->actingAs($user)
            ->get('/hr/performance')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('reviews.data', 1));
    }

    public function test_a_draft_review_is_hidden_from_the_employee(): void
    {
        [$review, , $employeeUser] = $this->supervisorReview();

        // Still a draft — the supervisor is mid-sentence.
        $this->actingAs($employeeUser)
            ->get("/hr/performance/reviews/{$review->id}")
            ->assertForbidden();
    }

    public function test_history_blends_the_perspectives_into_one_score(): void
    {
        $cycle = $this->cycle(['status' => ReviewCycle::STATUS_OPEN]);
        $employee = Employee::factory()->create();

        PerformanceReview::factory()->create([
            'employee_id' => $employee->id,
            'review_cycle_id' => $cycle->id,
            'reviewer_type' => 'supervisor',
            'status' => PerformanceReview::STATUS_SUBMITTED,
            'overall_rating' => 4.0,
        ]);
        PerformanceReview::factory()->create([
            'employee_id' => $employee->id,
            'review_cycle_id' => $cycle->id,
            'reviewer_type' => 'self',
            'status' => PerformanceReview::STATUS_SUBMITTED,
            'overall_rating' => 5.0,
        ]);

        $result = app(PerformanceService::class)->cycleResult($employee, $cycle);

        // (4 x 0.60 + 5 x 0.10) / 0.70
        $this->assertSame(4.14, $result['composite']);
        $this->assertSame('Exceeds Expectations', $result['band']['label']);
    }

    public function test_draft_reviews_do_not_count_toward_the_score(): void
    {
        $cycle = $this->cycle(['status' => ReviewCycle::STATUS_OPEN]);
        $employee = Employee::factory()->create();

        PerformanceReview::factory()->create([
            'employee_id' => $employee->id,
            'review_cycle_id' => $cycle->id,
            'reviewer_type' => 'supervisor',
            'status' => PerformanceReview::STATUS_DRAFT,
            'overall_rating' => 1.0,
        ]);

        $this->assertNull(
            app(PerformanceService::class)->cycleResult($employee, $cycle)['composite'],
        );
    }

    public function test_the_history_page_renders(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->hr())
            ->get("/hr/performance/employees/{$employee->id}/history")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('HR/Performance/History'));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/hr/performance')->assertRedirect('/login');
    }

    // --- Helpers ------------------------------------------------------------

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }

    private function cycle(array $overrides = []): ReviewCycle
    {
        return ReviewCycle::create([
            'name' => 'FY2026 Annual Review',
            'type' => 'annual',
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'status' => ReviewCycle::STATUS_DRAFT,
            ...$overrides,
        ]);
    }

    /** A self review, open and ready to score. @return array{0: PerformanceReview, 1: mixed} */
    private function openReview(): array
    {
        $cycle = $this->cycle();
        $kpis = Kpi::factory()->count(2)->create(['default_weight' => 50]);
        Employee::factory()->create(['user_id' => User::factory()->create()->id]);

        app(PerformanceService::class)->rollout($cycle);

        return [PerformanceReview::firstOrFail()->load('reviewer'), $kpis];
    }

    /** A supervisor review, so acknowledgement applies. */
    private function supervisorReview(): array
    {
        $cycle = $this->cycle();
        $kpis = Kpi::factory()->count(2)->create(['default_weight' => 50]);

        $supervisorUser = User::factory()->supervisor()->create();
        $supervisor = Employee::factory()->create(['user_id' => $supervisorUser->id]);

        $employeeUser = User::factory()->create();
        Employee::factory()->create([
            'user_id' => $employeeUser->id,
            'supervisor_id' => $supervisor->id,
        ]);

        app(PerformanceService::class)->rollout($cycle);

        $review = PerformanceReview::where('reviewer_type', 'supervisor')->firstOrFail();

        return [$review->load('reviewer'), $kpis, $employeeUser];
    }
}
