<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeaveAccrualCalculator;
use App\Services\LeaveAccrualService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class LeaveAccrualTest extends TestCase
{
    use RefreshDatabase;

    private const YEAR = 2026;

    // ── The calculation ──────────────────────────────────────────────────

    public function test_a_full_year_of_service_earns_the_full_entitlement(): void
    {
        $earned = $this->calculator()->earned(
            annualCredits: 15,
            hiredOn: Carbon::create(2020, 1, 1),
            year: self::YEAR,
            asOf: Carbon::create(self::YEAR, 12, 31),
        );

        $this->assertSame(15.0, $earned);
    }

    public function test_credits_accrue_per_completed_month(): void
    {
        // Six months in: 15 ÷ 12 × 6 = 7.5
        $earned = $this->calculator()->earned(
            annualCredits: 15,
            hiredOn: Carbon::create(2020, 1, 1),
            year: self::YEAR,
            asOf: Carbon::create(self::YEAR, 7, 1),
        );

        $this->assertSame(7.5, $earned);
    }

    /** The bug this feature exists to fix. */
    public function test_a_november_hire_does_not_get_a_full_year(): void
    {
        $earned = $this->calculator()->earned(
            annualCredits: 15,
            hiredOn: Carbon::create(self::YEAR, 11, 1),
            year: self::YEAR,
            asOf: Carbon::create(self::YEAR, 12, 31),
        );

        // Two months of service, not twelve: 15 ÷ 12 × 2 = 2.5
        $this->assertSame(2.5, $earned);
    }

    public function test_a_part_month_earns_nothing_yet(): void
    {
        // Hired on the 20th, asked on the 25th — the month is not complete.
        $earned = $this->calculator()->earned(
            annualCredits: 15,
            hiredOn: Carbon::create(self::YEAR, 1, 20),
            year: self::YEAR,
            asOf: Carbon::create(self::YEAR, 1, 25),
        );

        $this->assertSame(0.0, $earned);
    }

    public function test_someone_hired_after_the_year_ends_earns_nothing(): void
    {
        $earned = $this->calculator()->earned(
            annualCredits: 15,
            hiredOn: Carbon::create(2027, 3, 1),
            year: self::YEAR,
            asOf: Carbon::create(2027, 6, 1),
        );

        $this->assertSame(0.0, $earned);
    }

    public function test_accrual_never_runs_past_the_year_being_computed(): void
    {
        // Asking in 2028 about 2026 still caps at 2026's twelve months.
        $earned = $this->calculator()->earned(
            annualCredits: 15,
            hiredOn: Carbon::create(2020, 1, 1),
            year: self::YEAR,
            asOf: Carbon::create(2028, 6, 1),
        );

        $this->assertSame(15.0, $earned);
    }

    public function test_a_waiting_period_delays_but_does_not_forfeit(): void
    {
        config(['leave.months_before_accrual' => 6]);

        $hired = Carbon::create(self::YEAR, 1, 1);

        // Five months in — still inside the waiting period.
        $this->assertSame(0.0, $this->calculator()->earned(
            15, $hired, self::YEAR, Carbon::create(self::YEAR, 6, 1),
        ));

        // Past it, the months served during the wait are credited too.
        $this->assertSame(8.75, $this->calculator()->earned(
            15, $hired, self::YEAR, Carbon::create(self::YEAR, 8, 1),
        ));
    }

    // ── Applying it ──────────────────────────────────────────────────────

    public function test_accruing_writes_a_balance_per_employee_and_type(): void
    {
        $employee = Employee::factory()->create(['date_hired' => '2020-01-01']);
        $type = $this->leaveType(15);

        app(LeaveAccrualService::class)->accrue(self::YEAR, Carbon::create(self::YEAR, 7, 1));

        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'year' => self::YEAR,
            'credits_earned' => 7.5,
        ]);
    }

    public function test_re_running_grants_nothing_twice(): void
    {
        Employee::factory()->create(['date_hired' => '2020-01-01']);
        $this->leaveType(15);

        $service = app(LeaveAccrualService::class);
        $asOf = Carbon::create(self::YEAR, 7, 1);

        $first = $service->accrue(self::YEAR, $asOf);
        $second = $service->accrue(self::YEAR, $asOf);

        $this->assertSame(1, $first['updated']);
        // Recomputed, not added — the second pass finds it already current.
        $this->assertSame(0, $second['updated']);
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(7.5, (float) LeaveBalance::first()->credits_earned);
    }

    public function test_used_credits_are_never_touched(): void
    {
        $employee = Employee::factory()->create(['date_hired' => '2020-01-01']);
        $type = $this->leaveType(15);

        LeaveBalance::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'year' => self::YEAR,
            'credits_earned' => 15,
            'credits_used' => 3,
        ]);

        app(LeaveAccrualService::class)->accrue(self::YEAR, Carbon::create(self::YEAR, 7, 1));

        $balance = LeaveBalance::first();
        $this->assertSame(7.5, (float) $balance->credits_earned);
        $this->assertSame(3.0, (float) $balance->credits_used);
    }

    /**
     * Someone may already have filed against the old bulk allocation. Dropping
     * earned below used would invent a negative balance and imply the approved
     * leave was never valid.
     */
    public function test_a_balance_already_spent_past_accrual_is_held_not_reduced(): void
    {
        $employee = Employee::factory()->create(['date_hired' => '2020-01-01']);
        $type = $this->leaveType(15);

        LeaveBalance::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'year' => self::YEAR,
            'credits_earned' => 15,
            'credits_used' => 12, // accrual at 7.5 would undercut this
        ]);

        $result = app(LeaveAccrualService::class)
            ->accrue(self::YEAR, Carbon::create(self::YEAR, 7, 1));

        $this->assertSame(12.0, (float) LeaveBalance::first()->credits_earned);
        $this->assertCount(1, $result['over_granted']);
        $this->assertSame(12.0, $result['over_granted'][0]['already_used']);
    }

    public function test_inactive_employees_do_not_accrue(): void
    {
        Employee::factory()->create(['date_hired' => '2020-01-01', 'status' => 'inactive']);
        $this->leaveType(15);

        app(LeaveAccrualService::class)->accrue(self::YEAR);

        $this->assertSame(0, LeaveBalance::count());
    }

    // ── The endpoint ─────────────────────────────────────────────────────

    public function test_hr_can_run_accrual(): void
    {
        Employee::factory()->create(['date_hired' => '2020-01-01']);
        $this->leaveType(15);

        $this->actingAs(User::factory()->hrStaff()->create())
            ->post('/hr/leave/balances/accrue', ['year' => self::YEAR])
            ->assertRedirect();

        $this->assertSame(1, LeaveBalance::count());
    }

    public function test_an_employee_cannot_run_accrual(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/hr/leave/balances/accrue', ['year' => self::YEAR])
            ->assertForbidden();
    }

    /**
     * The balances screen renders a row per employee through a closure that
     * type-hints the model. Nothing exercised that closure before, so removing
     * an import it depended on passed the whole suite and 500'd in a browser —
     * this asserts the page renders with an employee actually present.
     */
    public function test_the_balances_screen_renders_with_employees_present(): void
    {
        Employee::factory()->count(2)->create(['date_hired' => '2020-01-01']);
        $this->leaveType(15);

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/hr/leave/balances')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('HR/Leave/Balances')
                ->has('rows', 2),
            );
    }

    private function calculator(): LeaveAccrualCalculator
    {
        return app(LeaveAccrualCalculator::class);
    }

    private function leaveType(float $credits): LeaveType
    {
        return LeaveType::create([
            'name' => 'Vacation Leave',
            'code' => 'VL',
            'default_credits' => $credits,
            'is_paid' => true,
            'is_active' => true,
        ]);
    }
}
