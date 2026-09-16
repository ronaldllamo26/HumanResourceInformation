<?php

namespace Tests\Feature\Api;

use App\Models\DisciplinaryAction;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\PayrollReadinessChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Disciplinary actions from Core 4, and the one thing they deliberately do not
 * do.
 *
 * A suspension is a stated fact: this system does not dock pay on another
 * system's say-so. `PayrollReadinessChecker` reports it and HR decides. The
 * gap that leaves — an unpaid suspension nobody acts on is paid — is the
 * accepted price.
 */
class DisciplinaryActionApiTest extends TestCase
{
    use RefreshDatabase;

    private ?int $employeeId = null;

    // --- What it refuses to do ---------------------------------------------

    /** And it says so in the response, rather than leaving it to be assumed. */
    public function test_the_response_states_that_no_pay_was_docked(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $this->postJson('/api/v1/disciplinary-actions', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.payroll_effect', 'flagged_for_hr');
    }

    /** A warning is not a suspension, so it reaches payroll not at all. */
    public function test_a_warning_has_no_payroll_effect(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $this->postJson('/api/v1/disciplinary-actions', $this->payload([
            'type' => DisciplinaryAction::TYPE_WRITTEN_WARNING,
            'effective_to' => null,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.payroll_effect', 'none');
    }

    // --- How it reaches pay: as a warning on the readiness panel -----------

    /**
     * The whole of Method B: reported before the money is computed, never
     * enforced.
     */
    public function test_an_unpaid_suspension_is_raised_on_the_readiness_panel(): void
    {
        $period = $this->period();
        $employee = Employee::factory()->create();

        DisciplinaryAction::create([
            'employee_id' => $employee->id,
            'source' => 'core4',
            'reference' => 'SAF-9001',
            'type' => DisciplinaryAction::TYPE_SUSPENSION,
            'reason' => 'Failed to wear PPE on a live site.',
            'effective_from' => $period->start_date->copy()->addDay()->toDateString(),
            'effective_to' => $period->start_date->copy()->addDays(4)->toDateString(),
            'is_unpaid' => true,
        ]);

        $check = collect(app(PayrollReadinessChecker::class)->check($period)['checks'])
            ->firstWhere('key', 'unserved_suspensions');

        $this->assertNotNull($check, 'The suspension was not reported at all.');
        $this->assertSame(PayrollReadinessChecker::SEVERITY_WARNING, $check['severity']);
        $this->assertContains($employee->full_name, $check['employees']);
    }

    /**
     * A **warning and never a blocker**, for the same reason the wage-floor
     * check is one: the discrepancy is often legitimate — served on a rest
     * day, lifted on appeal — and refusing to run payroll over it would
     * strand everybody else unpaid over one person's Tuesday.
     */
    public function test_it_never_blocks_a_run(): void
    {
        $period = $this->period();

        DisciplinaryAction::create([
            'employee_id' => Employee::factory()->create()->id,
            'source' => 'core4',
            'reference' => 'SAF-9002',
            'type' => DisciplinaryAction::TYPE_SUSPENSION,
            'reason' => 'Unsafe driving.',
            'effective_from' => $period->start_date->toDateString(),
            'effective_to' => $period->start_date->copy()->addDays(2)->toDateString(),
            'is_unpaid' => true,
        ]);

        $this->assertSame(0, app(PayrollReadinessChecker::class)->check($period)['blockers']);
    }

    public function test_a_paid_suspension_is_not_raised(): void
    {
        $period = $this->period();

        DisciplinaryAction::create([
            'employee_id' => Employee::factory()->create()->id,
            'source' => 'core4',
            'reference' => 'SAF-9004',
            'type' => DisciplinaryAction::TYPE_SUSPENSION,
            'reason' => 'Suspended with pay pending investigation.',
            'effective_from' => $period->start_date->toDateString(),
            'effective_to' => $period->start_date->copy()->addDays(3)->toDateString(),
            'is_unpaid' => false,
        ]);

        $this->assertNull(
            collect(app(PayrollReadinessChecker::class)->check($period)['checks'])
                ->firstWhere('key', 'unserved_suspensions'),
        );
    }

    /**
     * A suspension that began before the cutoff and runs into it still costs
     * days inside it. Matching only actions *beginning* in the period would
     * miss exactly the long ones that matter most.
     */
    public function test_a_suspension_starting_before_the_cutoff_is_still_counted(): void
    {
        $period = $this->period();

        DisciplinaryAction::create([
            'employee_id' => Employee::factory()->create()->id,
            'source' => 'core4',
            'reference' => 'SAF-9005',
            'type' => DisciplinaryAction::TYPE_SUSPENSION,
            'reason' => 'Rolling suspension.',
            'effective_from' => $period->start_date->copy()->subDays(5)->toDateString(),
            'effective_to' => $period->start_date->copy()->addDays(2)->toDateString(),
            'is_unpaid' => true,
        ]);

        $this->assertNotNull(
            collect(app(PayrollReadinessChecker::class)->check($period)['checks'])
                ->firstWhere('key', 'unserved_suspensions'),
        );
    }

    // --- The contract ------------------------------------------------------

    public function test_a_resent_action_does_not_create_a_second_row(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $this->postJson('/api/v1/disciplinary-actions', $this->payload())->assertCreated();
        $this->postJson('/api/v1/disciplinary-actions', $this->payload())->assertOk();

        $this->assertSame(1, DisciplinaryAction::count());
    }

    /**
     * A resend with different dates returns what is stored. The reference
     * names an action HR may already have acted on, and rewriting the dates
     * behind it would move which days are unpaid.
     */
    public function test_a_resend_does_not_rewrite_the_dates(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $this->postJson('/api/v1/disciplinary-actions', $this->payload())->assertCreated();

        $this->postJson('/api/v1/disciplinary-actions', $this->payload([
            'effective_to' => '2026-12-31',
        ]))->assertOk()->assertJsonPath('data.effective_to', '2026-03-05');
    }

    public function test_an_end_date_before_the_start_is_refused(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $this->postJson('/api/v1/disciplinary-actions', $this->payload([
            'effective_to' => '2026-01-01',
        ]))->assertJsonValidationErrors('effective_to');
    }

    /**
     * An open-ended suspension is a real state — pending investigation — so a
     * null end date is accepted rather than defaulted to the start, which
     * would be inventing the outcome.
     */
    public function test_an_open_ended_suspension_is_accepted(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $this->postJson('/api/v1/disciplinary-actions', $this->payload(['effective_to' => null]))
            ->assertCreated()
            ->assertJsonPath('data.effective_to', null);
    }

    /** Core 4 reads the outcome back, and an action with no reason is one nobody can answer for. */
    public function test_a_reason_is_required(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $this->postJson('/api/v1/disciplinary-actions', $this->payload(['reason' => '']))
            ->assertJsonValidationErrors('reason');
    }

    // --- Who may record one ------------------------------------------------

    public function test_an_anonymous_caller_is_refused(): void
    {
        $this->postJson('/api/v1/disciplinary-actions', $this->payload())->assertUnauthorized();
    }

    /**
     * No supervisor exemption, and that is deliberate: a supervisor is usually
     * the person who *reports* the incident, and letting them also file the
     * sanction would put the complaint and the penalty in one pair of hands.
     */
    public function test_a_supervisor_cannot_record_one(): void
    {
        Sanctum::actingAs(User::factory()->role(User::ROLE_SUPERVISOR)->create());

        $this->postJson('/api/v1/disciplinary-actions', $this->payload())->assertForbidden();
    }

    public function test_an_employee_cannot_record_one(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]));

        $this->postJson('/api/v1/disciplinary-actions', $this->payload())->assertForbidden();
    }

    /**
     * Reading follows the *employee's* gate rather than this class's, so an
     * employee may see their own record — which is the half of due process a
     * system holding sanctions has to support.
     */
    public function test_an_employee_may_read_their_own_actions(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        DisciplinaryAction::create([
            'employee_id' => $employee->id,
            'source' => 'hr',
            'reference' => 'HR-1',
            'type' => DisciplinaryAction::TYPE_WRITTEN_WARNING,
            'reason' => 'Repeated lateness.',
            'effective_from' => '2026-03-01',
            'is_unpaid' => false,
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/employees/{$employee->id}/disciplinary-actions")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_an_employee_cannot_read_somebody_elses(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        Employee::factory()->create(['user_id' => $user->id]);

        $other = Employee::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson("/api/v1/employees/{$other->id}/disciplinary-actions")->assertForbidden();
    }

    // --- Helpers -----------------------------------------------------------

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'reference' => 'SAF-2026-0041',
            'source' => 'core4',
            'employee_id' => $this->employeeId ??= Employee::factory()->create()->id,
            'type' => DisciplinaryAction::TYPE_SUSPENSION,
            'reason' => 'Failed to wear PPE on a live site.',
            'effective_from' => '2026-03-02',
            'effective_to' => '2026-03-05',
            'is_unpaid' => true,
            'issued_by' => 'Safety Officer — R. Cruz',
        ], $overrides);
    }

    private function period(): PayrollPeriod
    {
        static $sequence = 0;
        $sequence++;

        $start = now()->startOfYear()->addDays(($sequence - 1) * 20);

        return PayrollPeriod::create([
            'name' => "Period {$sequence}",
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(14)->toDateString(),
            'pay_date' => $start->copy()->addDays(19)->toDateString(),
            'frequency' => 'semi_monthly',
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);
    }
}
