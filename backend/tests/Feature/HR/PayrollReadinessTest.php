<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\AttendanceCutoffService;
use App\Services\PayrollReadinessChecker;
use App\Services\PayrollService;
use App\Services\TimekeepingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * What payroll warns about before it computes: the wage floor and suspensions
 * here; the attendance checks in Timekeeping\PayrollIntegrationTest.
 */
class PayrollReadinessTest extends TestCase
{
    use RefreshDatabase;

    private const SALARY = 26100;

    public function test_a_period_with_nothing_to_flag_reports_ready(): void
    {
        $period = $this->period();
        $this->settle($period, $this->employee());

        $readiness = $this->check($period);

        $this->assertTrue($readiness['ready']);
        $this->assertSame([], $readiness['checks']);
    }

    public function test_a_draft_run_shows_the_readiness_panel(): void
    {
        $period = $this->period();
        $this->settle($period, Employee::factory()->create(['basic_salary' => 5000]));

        $run = app(PayrollService::class)->generate($period, $this->hr());

        $this->actingAs($this->hr())
            ->get("/hr/payroll/runs/{$run->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('readiness.ready', false)
                ->where('readiness.warnings', 1)
                ->has('readiness.checks', 1),
            );
    }

    public function test_an_approved_run_hides_the_panel_because_the_figures_are_history(): void
    {
        $period = $this->period();
        Employee::factory()->create(['basic_salary' => 5000]);

        $service = app(PayrollService::class);
        $run = $service->generate($period, $this->hr());
        $service->submitForApproval($run);
        $service->approve($run, User::factory()->admin()->create());

        $this->actingAs($this->hr())
            ->get("/hr/payroll/runs/{$run->id}")
            ->assertInertia(fn (Assert $page) => $page->where('readiness', null));
    }

    public function test_readiness_never_blocks_the_run_from_computing(): void
    {
        $period = $this->period();
        $employee = Employee::factory()->create(['basic_salary' => 5000]);

        $this->actingAs($this->hr())
            ->post("/hr/payroll/periods/{$period->id}/generate")
            ->assertRedirect();

        $this->assertDatabaseCount('payroll_runs', 1);
        $this->assertDatabaseHas('payslips', ['employee_id' => $employee->id]);
    }

    // --- The regional wage floor -------------------------------------------

    /**
     * There is no national minimum wage here — each region's RTWPB sets its
     * own. The check has to resolve the region per employee.
     */
    public function test_a_rate_under_the_regions_floor_warns(): void
    {
        $period = $this->period();

        // 5,000/mo is roughly 230/day against a 261-day factor — under every
        // configured floor.
        $employee = Employee::factory()->create(['basic_salary' => 5000]);

        $check = collect($this->check($period)['checks'])->firstWhere('key', 'below_regional_minimum');

        $this->assertNotNull($check, 'The wage floor check did not fire.');
        // Never a blocker: refusing to run payroll would strand the very
        // employee the floor exists to protect.
        $this->assertSame(PayrollReadinessChecker::SEVERITY_WARNING, $check['severity']);
        $this->assertContains($employee->full_name, $check['employees']);
    }

    public function test_a_rate_above_the_floor_is_silent(): void
    {
        $period = $this->period();
        $this->employee();

        $this->assertNull(collect($this->check($period)['checks'])->firstWhere('key', 'below_regional_minimum'));
    }

    /** The floor follows where the work happens. */
    public function test_the_floor_follows_the_employees_region(): void
    {
        config([
            'payroll.wage_regions' => [
                'NCR' => ['label' => 'NCR', 'daily_minimum' => 645.00],
                'R11' => ['label' => 'Davao', 'daily_minimum' => 481.00],
            ],
        ]);

        $period = $this->period();

        // ~536/day: above Davao's floor, under Metro Manila's.
        $employee = Employee::factory()->create([
            'basic_salary' => 11660,
            'wage_region' => 'R11',
        ]);

        $this->assertNull(
            collect($this->check($period)['checks'])->firstWhere('key', 'below_regional_minimum'),
            'Legal in Davao — should not warn.',
        );

        $employee->update(['wage_region' => 'NCR']);

        $this->assertNotNull(
            collect($this->check($period)['checks'])->firstWhere('key', 'below_regional_minimum'),
            'The same rate is under the Metro Manila floor and should warn.',
        );
    }

    /** A day on the DTR and the cutoff closed, so only the checks under test can fire. */
    private function settle(PayrollPeriod $period, Employee $employee): void
    {
        app(TimekeepingService::class)->record($employee, Carbon::parse('2026-08-03'), '08:00', '17:00');
        app(AttendanceCutoffService::class)->close($period, $this->hr());
    }

    /** @return array<string, mixed> */
    private function check(PayrollPeriod $period): array
    {
        return app(PayrollReadinessChecker::class)->check($period);
    }

    private function employee(): Employee
    {
        return Employee::factory()->create(['basic_salary' => self::SALARY]);
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }

    private function period(): PayrollPeriod
    {
        return PayrollPeriod::create([
            'name' => 'Aug 1 – 15, 2026',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-15',
            'pay_date' => '2026-08-20',
            'frequency' => 'semi_monthly',
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);
    }
}
