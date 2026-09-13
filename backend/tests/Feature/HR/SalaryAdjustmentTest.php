<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\SalaryAdjustment;
use App\Models\User;
use App\Services\PayrollService;
use App\Services\SalaryAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SalaryAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    // --- The screen --------------------------------------------------------

    public function test_the_screen_renders_with_the_history(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => 20000]);
        $this->adjust($employee, 25000, 'today');

        $this->actingAs($this->hr())
            ->get('/hr/payroll/salaries')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Payroll/Salaries')
                ->has('adjustments.data', 1)
                ->where('adjustments.data.0.previous_salary', 20000)
                ->where('adjustments.data.0.new_salary', 25000)
                ->where('adjustments.data.0.difference', 5000),
            );
    }

    public function test_recording_an_adjustment_moves_the_employees_rate(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => 20000]);

        $this->actingAs($this->hr())->post('/hr/payroll/salaries', [
            'employee_id' => $employee->id,
            'new_salary' => 26000,
            'effective_date' => today()->toDateString(),
            'reason' => 'merit',
        ])->assertRedirect();

        $this->assertSame('26000.00', $employee->refresh()->basic_salary);
        $this->assertSame('20000.00', SalaryAdjustment::firstOrFail()->previous_salary);
    }

    // --- Effective dating --------------------------------------------------

    /**
     * The whole point of the feature. A rate applies from the date it takes
     * effect, so asking for an earlier date must give the earlier rate.
     */
    public function test_the_rate_is_read_as_of_a_date_not_as_of_today(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => 20000]);

        $this->adjust($employee, 22000, '2026-03-01');
        $this->adjust($employee, 28000, '2026-07-01');

        $rate = fn (string $date) => app(SalaryAdjustmentService::class)
            ->rateAsOf($employee->refresh(), Carbon::parse($date));

        // Before any recorded change — the rate they were on beforehand.
        $this->assertSame(20000.0, $rate('2026-01-15'));
        $this->assertSame(22000.0, $rate('2026-03-01'));  // the day it lands
        $this->assertSame(22000.0, $rate('2026-06-30'));
        $this->assertSame(28000.0, $rate('2026-07-01'));
        $this->assertSame(28000.0, $rate('2026-12-31'));
    }

    /**
     * An employee with no history at all — every record created before this
     * screen existed — still answers with the figure on their file.
     */
    public function test_an_employee_with_no_history_falls_back_to_their_field(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => 31500]);

        $this->assertSame(
            31500.0,
            app(SalaryAdjustmentService::class)->rateAsOf($employee, Carbon::parse('2020-01-01')),
        );
    }

    public function test_a_future_dated_adjustment_does_not_move_the_rate_yet(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => 20000]);
        $this->adjust($employee, 30000, today()->addMonth()->toDateString());

        $this->assertSame('20000.00', $employee->refresh()->basic_salary);
        $this->assertTrue(SalaryAdjustment::firstOrFail()->isScheduled());
    }

    public function test_the_due_command_applies_a_scheduled_raise_once_it_lands(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => 20000]);
        $this->adjust($employee, 30000, today()->addDays(2)->toDateString());

        $this->artisan('salaries:apply-due')->assertSuccessful();
        $this->assertSame('20000.00', $employee->refresh()->basic_salary);

        Carbon::setTestNow(today()->addDays(3));

        $this->artisan('salaries:apply-due')->assertSuccessful();
        $this->assertSame('30000.00', $employee->refresh()->basic_salary);

        Carbon::setTestNow();
    }

    /** Back-dating records the rate that was true then, not today's figure. */
    public function test_back_dating_records_the_rate_in_force_on_that_date(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => 20000]);

        $this->adjust($employee, 30000, '2026-06-01');
        // Slotted in *before* the June change.
        $this->adjust($employee, 24000, '2026-02-01');

        $february = SalaryAdjustment::where('new_salary', 24000)->firstOrFail();

        // The rate before February was the original 20,000 — not the 30,000
        // the employee happens to be on now.
        $this->assertSame('20000.00', $february->previous_salary);
    }

    // --- The reason it matters: payroll ------------------------------------

    /**
     * A raise keyed in after a period closed must not rewrite what that period
     * pays. This is the failure the feature exists to prevent.
     */
    public function test_payroll_pays_the_rate_in_force_over_the_period(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => 26100]);

        $period = PayrollPeriod::create([
            'name' => 'Mar 1 – 15',
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-15',
            'pay_date' => '2026-03-20',
            'frequency' => 'semi_monthly',
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);

        // A big raise, effective long after the period being paid.
        $this->adjust($employee, 52200, '2026-08-01');

        $run = app(PayrollService::class)->generate($period, $this->hr());
        $payslip = $run->payslips()->where('employee_id', $employee->id)->firstOrFail();

        // Half of 26,100 for a semi-monthly period, not half of 52,200.
        $this->assertSame('13050.00', $payslip->basic_pay);
    }

    // --- Removing ----------------------------------------------------------

    public function test_removing_an_adjustment_repoints_the_rate_at_the_previous_one(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => 20000]);
        $this->adjust($employee, 24000, '2026-01-01');
        $latest = $this->adjust($employee, 30000, '2026-06-01');

        $this->assertSame('30000.00', $employee->refresh()->basic_salary);

        $this->actingAs($this->admin())
            ->delete('/hr/payroll/salaries/'.$latest->id)
            ->assertRedirect();

        $this->assertSame('24000.00', $employee->refresh()->basic_salary);
    }

    /**
     * The case the two-adjustment test above cannot reach: with the history
     * emptied there is nothing to read the rate back from, and `basic_salary`
     * still holds the figure this very adjustment set. Caught live, not here —
     * removing the only adjustment kept the raise.
     */
    public function test_removing_the_only_adjustment_undoes_the_raise(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => 20000]);
        $adjustment = $this->adjust($employee, 30000, 'today');

        $this->assertSame('30000.00', $employee->refresh()->basic_salary);

        $this->actingAs($this->admin())
            ->delete('/hr/payroll/salaries/'.$adjustment->id)
            ->assertRedirect();

        $this->assertSame(0, SalaryAdjustment::count());
        $this->assertSame('20000.00', $employee->refresh()->basic_salary);
    }

    public function test_hr_staff_cannot_remove_an_adjustment(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => 20000]);
        $adjustment = $this->adjust($employee, 24000, 'today');

        $this->actingAs($this->hr())
            ->delete('/hr/payroll/salaries/'.$adjustment->id)
            ->assertForbidden();
    }

    // --- Access control ----------------------------------------------------

    /** Salary is sensitive: the same bar EmployeePolicy::viewSensitive sets. */
    public function test_a_supervisor_cannot_view_the_screen(): void
    {
        $this->actingAs(User::factory()->supervisor()->create())
            ->get('/hr/payroll/salaries')
            ->assertForbidden();
    }

    public function test_an_employee_cannot_view_the_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/hr/payroll/salaries')
            ->assertForbidden();
    }

    public function test_an_employee_cannot_set_a_salary(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => 20000]);

        $this->actingAs(User::factory()->create())->post('/hr/payroll/salaries', [
            'employee_id' => $employee->id,
            'new_salary' => 999999,
            'effective_date' => today()->toDateString(),
            'reason' => 'merit',
        ])->assertForbidden();

        $this->assertSame('20000.00', $employee->refresh()->basic_salary);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/hr/payroll/salaries')->assertRedirect('/login');
    }

    public function test_an_unlisted_reason_is_rejected(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => 20000]);

        $this->actingAs($this->hr())->post('/hr/payroll/salaries', [
            'employee_id' => $employee->id,
            'new_salary' => 24000,
            'effective_date' => today()->toDateString(),
            'reason' => 'because_i_said_so',
        ])->assertSessionHasErrors('reason');
    }

    // --- Helpers -----------------------------------------------------------

    private function adjust(Employee $employee, float $salary, string $date): SalaryAdjustment
    {
        return app(SalaryAdjustmentService::class)->record($employee, [
            'new_salary' => $salary,
            'effective_date' => $date === 'today' ? today()->toDateString() : $date,
            'reason' => 'merit',
        ], $this->hr());
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }
}
