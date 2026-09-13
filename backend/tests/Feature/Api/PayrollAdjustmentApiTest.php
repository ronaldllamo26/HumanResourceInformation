<?php

namespace Tests\Feature\Api;

use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One-off amounts Fleet and Supply Chain put on a payslip.
 *
 * **The test that matters most is `a_recompute_does_not_double_the_amount`.**
 * Everything else here is contract detail; that one is the reason the endpoint
 * stores a row instead of touching a payslip. A draft run can be recomputed
 * freely, so an endpoint that applied an amount when it was *called* would
 * apply it again on the next recompute — and the two systems would part
 * company with nobody watching. CLAUDE.md records the same failure from the
 * loan design, which is why loans are a standing instruction rather than a
 * per-period instruction to deduct.
 */
class PayrollAdjustmentApiTest extends TestCase
{
    use RefreshDatabase;

    private ?int $employeeId = null;

    private ?int $periodId = null;

    // --- The safety property -----------------------------------------------

    /**
     * The whole reason this endpoint writes a row rather than a payslip.
     *
     * Computed, then computed again over the same period — the trip allowance
     * has to appear once, not twice.
     */
    public function test_a_recompute_does_not_double_the_amount(): void
    {
        $employee = $this->employee();
        $period = $this->period();

        PayrollAdjustment::create([
            'employee_id' => $employee->id,
            'payroll_period_id' => $period->id,
            'source' => 'fleet',
            'reference' => 'TRIP-4471',
            'kind' => PayrollAdjustment::KIND_EARNING,
            'label' => 'Trip allowance',
            'amount' => 500,
        ]);

        $service = app(PayrollService::class);

        $first = $service->gatherInputs($period, $employee);
        $second = $service->gatherInputs($period, $employee);

        $sum = fn (array $inputs) => array_sum(array_column($inputs['allowances'], 'amount'));

        $this->assertSame(500.0, $sum($first));
        $this->assertSame(
            500.0,
            $sum($second),
            'The allowance doubled on a second compute — the amount is being applied rather than read.',
        );
    }

    /**
     * A one-off amount is paid at face value, never prorated.
     *
     * `amountForPeriod()` halves a monthly standing allowance on a
     * semi-monthly run, which is right for a rice allowance and wrong for a
     * trip that happened: halving it would pay ₱250 for a ₱500 trip.
     */
    public function test_a_one_off_earning_is_not_prorated_by_frequency(): void
    {
        $employee = $this->employee();
        $period = $this->period(['frequency' => 'semi_monthly']);

        PayrollAdjustment::create([
            'employee_id' => $employee->id,
            'payroll_period_id' => $period->id,
            'source' => 'fleet',
            'reference' => 'TRIP-1',
            'kind' => PayrollAdjustment::KIND_EARNING,
            'label' => 'Per diem',
            'amount' => 500,
        ]);

        $inputs = app(PayrollService::class)->gatherInputs($period, $employee);

        $this->assertSame(500.0, (float) $inputs['allowances'][0]['amount']);
    }

    /** A deduction lands in the calculator's `other_deductions` slot. */
    public function test_a_deduction_reaches_the_other_deductions_slot(): void
    {
        $employee = $this->employee();
        $period = $this->period();

        PayrollAdjustment::create([
            'employee_id' => $employee->id,
            'payroll_period_id' => $period->id,
            'source' => 'supply_chain',
            'reference' => 'DMG-88',
            'kind' => PayrollAdjustment::KIND_DEDUCTION,
            'label' => 'Damaged pallet jack',
            'amount' => 1200,
        ]);

        $inputs = app(PayrollService::class)->gatherInputs($period, $employee);

        $this->assertSame(1200.0, (float) $inputs['other_deductions'][0]['amount']);
        $this->assertSame('Supply Chain — Damaged pallet jack', $inputs['other_deductions'][0]['label']);
        $this->assertSame([], $inputs['allowances'], 'A deduction must not read as an earning.');
    }

    /** An amount for another cutoff is not picked up by this one. */
    public function test_an_amount_for_another_period_is_not_read(): void
    {
        $employee = $this->employee();
        $thisPeriod = $this->period();
        $otherPeriod = $this->period();

        PayrollAdjustment::create([
            'employee_id' => $employee->id,
            'payroll_period_id' => $otherPeriod->id,
            'source' => 'fleet',
            'reference' => 'TRIP-2',
            'kind' => PayrollAdjustment::KIND_EARNING,
            'label' => 'Trip allowance',
            'amount' => 500,
        ]);

        $inputs = app(PayrollService::class)->gatherInputs($thisPeriod, $employee);

        $this->assertSame([], $inputs['allowances']);
    }

    // --- The contract ------------------------------------------------------

    public function test_fleet_can_post_a_trip_allowance(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $this->postJson('/api/v1/payroll/adjustments', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.reference', 'TRIP-4471')
            ->assertJsonPath('data.payslip_label', 'Fleet — Trip allowance');
    }

    /**
     * A timeout on the sender's side is indistinguishable from a failure, so
     * they resend — and two rows for one trip allowance is money. **200 rather
     * than 201** so they can tell a duplicate from a fresh submission without
     * either being an error.
     */
    public function test_a_resent_adjustment_does_not_pay_twice(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $this->postJson('/api/v1/payroll/adjustments', $this->payload())->assertCreated();
        $this->postJson('/api/v1/payroll/adjustments', $this->payload())->assertOk();

        $this->assertSame(1, PayrollAdjustment::count());
    }

    /**
     * A resend carrying a different amount returns what is stored rather than
     * rewriting it. The reference names a fact this system may already have
     * paid; quietly moving the amount behind it would move money nobody asked
     * to move.
     */
    public function test_a_resend_with_a_changed_amount_does_not_rewrite_it(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $this->postJson('/api/v1/payroll/adjustments', $this->payload())->assertCreated();

        // Compared numerically: the value is a float in PHP and the JSON round
        // trip hands a whole amount back as int, so the type here is the
        // transport rather than the figure.
        $this->postJson('/api/v1/payroll/adjustments', $this->payload(['amount' => 9999]))
            ->assertOk()
            ->assertJsonPath('data.amount', fn ($amount) => (float) $amount === 500.0);

        $this->assertSame('500.00', PayrollAdjustment::first()->amount);
    }

    /** The same reference from two systems is two different facts. */
    public function test_the_same_reference_from_another_source_is_a_separate_row(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $this->postJson('/api/v1/payroll/adjustments', $this->payload())->assertCreated();
        $this->postJson('/api/v1/payroll/adjustments', $this->payload([
            'source' => 'supply_chain',
            'kind' => PayrollAdjustment::KIND_DEDUCTION,
        ]))->assertCreated();

        $this->assertSame(2, PayrollAdjustment::count());
    }

    /**
     * Refused once the period is finalised, and **409** says which kind of
     * refusal it is.
     *
     * Accepting it would write a row nothing will ever read — an allowance
     * somebody was promised and never paid, with no error anywhere to say so.
     */
    public function test_an_amount_for_a_finalised_period_is_refused(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $period = $this->period();
        $this->runFor($period, PayrollRun::STATUS_PAID);

        $this->postJson('/api/v1/payroll/adjustments', $this->payload([
            'payroll_period_id' => $period->id,
        ]))->assertStatus(409);

        $this->assertSame(0, PayrollAdjustment::count());
    }

    public function test_a_draft_period_still_accepts_amounts(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $period = $this->period();
        $this->runFor($period, PayrollRun::STATUS_DRAFT);

        $this->postJson('/api/v1/payroll/adjustments', $this->payload([
            'payroll_period_id' => $period->id,
        ]))->assertCreated();
    }

    public function test_an_amount_can_be_withdrawn_before_the_run_is_finalised(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $this->postJson('/api/v1/payroll/adjustments', $this->payload())->assertCreated();

        $this->deleteJson('/api/v1/payroll/adjustments/fleet/TRIP-4471')->assertOk();

        $this->assertSame(0, PayrollAdjustment::count());
    }

    /**
     * Money already paid is not withdrawn by deleting the row that explained
     * it. That is a correction on the next cutoff — a new adjustment with the
     * opposite `kind`.
     */
    public function test_an_amount_cannot_be_withdrawn_once_paid(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $period = $this->period();
        $this->postJson('/api/v1/payroll/adjustments', $this->payload([
            'payroll_period_id' => $period->id,
        ]))->assertCreated();

        $this->runFor($period, PayrollRun::STATUS_PAID);

        $this->deleteJson('/api/v1/payroll/adjustments/fleet/TRIP-4471')->assertStatus(409);
        $this->assertSame(1, PayrollAdjustment::count());
    }

    // --- Who may post one --------------------------------------------------

    public function test_an_anonymous_caller_is_refused(): void
    {
        $this->postJson('/api/v1/payroll/adjustments', $this->payload())->assertUnauthorized();
    }

    /**
     * Gated on `manageCompensation`, the same ability that guards loans and
     * salary adjustments — one answer to "may this caller put money on a
     * payslip" rather than a second waiting to disagree.
     */
    public function test_a_rank_and_file_token_cannot_post_one(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]));

        $this->postJson('/api/v1/payroll/adjustments', $this->payload())->assertForbidden();
    }

    public function test_an_unknown_source_is_refused(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $this->postJson('/api/v1/payroll/adjustments', $this->payload(['source' => 'whoever']))
            ->assertJsonValidationErrors('source');
    }

    // --- Helpers -----------------------------------------------------------

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'reference' => 'TRIP-4471',
            'source' => 'fleet',
            'employee_id' => $this->employeeId ??= $this->employee()->id,
            'payroll_period_id' => $this->periodId ??= $this->period()->id,
            'kind' => PayrollAdjustment::KIND_EARNING,
            'label' => 'Trip allowance',
            'amount' => 500,
        ], $overrides);
    }

    private function employee(): Employee
    {
        return Employee::factory()->create();
    }

    /** A fresh cutoff each call — (start_date, end_date) is unique. */
    private function period(array $overrides = []): PayrollPeriod
    {
        static $sequence = 0;
        $sequence++;

        $start = now()->startOfYear()->addDays(($sequence - 1) * 15);

        return PayrollPeriod::create(array_merge([
            'name' => "Period {$sequence}",
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(14)->toDateString(),
            'pay_date' => $start->copy()->addDays(19)->toDateString(),
            'frequency' => 'semi_monthly',
            'status' => PayrollPeriod::STATUS_DRAFT,
        ], $overrides));
    }

    private function runFor(PayrollPeriod $period, string $status): PayrollRun
    {
        static $sequence = 0;
        $sequence++;

        return PayrollRun::create([
            'payroll_period_id' => $period->id,
            'run_number' => sprintf('PR-2026-%04d', $sequence),
            'status' => $status,
            'employee_count' => 0,
            'total_gross' => 0,
            'total_deductions' => 0,
            'total_net' => 0,
        ]);
    }
}
