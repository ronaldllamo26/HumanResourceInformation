<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Separation;
use App\Models\User;
use App\Services\SeparationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SeparationTest extends TestCase
{
    use RefreshDatabase;

    private const SALARY = 26100;   // daily rate 1,200

    private ?PayrollPeriod $period = null;

    // --- The screens -------------------------------------------------------

    public function test_the_index_renders_with_the_release_deadline(): void
    {
        $this->separation();

        $this->actingAs($this->hr())
            ->get('/hr/payroll/separations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Payroll/Separations')
                ->has('separations.data', 1)
                ->where('releaseWithinDays', 30)
                ->has('separations.data.0.days_to_deadline'),
            );
    }

    public function test_the_detail_screen_shows_the_working_behind_the_figure(): void
    {
        $separation = $this->separation();

        $this->actingAs($this->hr())
            ->get('/hr/payroll/separations/'.$separation->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Payroll/Separation')
                ->has('separation.breakdown')
                ->has('separation.clearance', 6),
            );
    }

    /** An employee already separated should not be offered again. */
    public function test_the_employee_list_excludes_those_with_an_open_separation(): void
    {
        $separation = $this->separation();

        $this->actingAs($this->hr())
            ->get('/hr/payroll/separations')
            ->assertInertia(fn (Assert $page) => $page->has('employees', 0));

        $separation->update(['status' => Separation::STATUS_RELEASED]);

        $this->actingAs($this->hr())
            ->get('/hr/payroll/separations')
            ->assertInertia(fn (Assert $page) => $page->has('employees', 1));
    }

    // --- The computation ---------------------------------------------------

    public function test_opening_a_separation_computes_the_settlement(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => self::SALARY]);
        $this->payslip($employee, basic: 13050);
        $this->convertibleCredits($employee, days: 5);
        $this->loan($employee, balance: 4000);

        $this->actingAs($this->hr())->post('/hr/payroll/separations', [
            'employee_id' => $employee->id,
            'last_day' => now()->toDateString(),
            'reason' => Separation::REASON_RESIGNED,
            'days_unpaid' => 3,
        ])->assertRedirect();

        $separation = Separation::firstOrFail();

        $this->assertSame('3600.00', $separation->unpaid_salary);       // 3 × 1,200
        $this->assertSame('1087.50', $separation->thirteenth_month);    // 13,050 ÷ 12
        $this->assertSame('6000.00', $separation->leave_conversion);    // 5 × 1,200
        $this->assertSame('4000.00', $separation->loan_deduction);
        $this->assertSame('6687.50', $separation->net_final_pay);
        $this->assertSame(Separation::STATUS_DRAFT, $separation->status);
    }

    /** Only leave types the company converts to cash are paid out. */
    public function test_non_convertible_leave_credits_are_not_paid_out(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => self::SALARY]);
        $this->convertibleCredits($employee, days: 5, convertible: false);

        $separation = $this->open($employee);

        $this->assertSame('0.00', $separation->leave_conversion);
    }

    /**
     * A draft run is still being corrected. Paying 13th month against it would
     * quote a figure the 13th-month screen does not even show, and hand over
     * money computed from payslips nobody has approved.
     */
    public function test_payslips_from_an_unapproved_run_are_not_counted(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => self::SALARY]);
        $this->payslip($employee, basic: 13050, status: PayrollRun::STATUS_APPROVED);
        $this->payslip($employee, basic: 13050, status: PayrollRun::STATUS_DRAFT);
        $this->payslip($employee, basic: 13050, status: PayrollRun::STATUS_FOR_APPROVAL);

        // Only the approved 13,050 counts: 13,050 ÷ 12.
        $this->assertSame('1087.50', $this->open($employee)->thirteenth_month);
    }

    public function test_a_draft_can_be_recomputed(): void
    {
        $separation = $this->separation();

        $this->actingAs($this->hr())
            ->post('/hr/payroll/separations/'.$separation->id.'/recompute', ['days_unpaid' => 2])
            ->assertRedirect();

        $this->assertSame('2400.00', $separation->refresh()->unpaid_salary);
    }

    public function test_a_released_settlement_can_no_longer_be_recomputed(): void
    {
        $separation = $this->releasable();
        $this->actingAs($this->admin())->post('/hr/payroll/separations/'.$separation->id.'/release');

        $this->actingAs($this->hr())
            ->post('/hr/payroll/separations/'.$separation->id.'/recompute', ['days_unpaid' => 9])
            ->assertForbidden();
    }

    // --- Clearance ---------------------------------------------------------

    public function test_the_status_follows_the_blocking_checklist_items(): void
    {
        $separation = $this->separation();

        foreach ($this->blockingKeys() as $key) {
            $this->assertSame(Separation::STATUS_DRAFT, $separation->refresh()->status);

            $this->actingAs($this->hr())->post(
                '/hr/payroll/separations/'.$separation->id.'/clearance',
                ['key' => $key, 'cleared' => true],
            )->assertRedirect();
        }

        $this->assertSame(Separation::STATUS_CLEARED, $separation->refresh()->status);
    }

    /** Un-ticking an item drops the separation back out of "cleared". */
    public function test_un_clearing_an_item_reopens_the_separation(): void
    {
        $separation = $this->releasable();

        $this->actingAs($this->hr())->post(
            '/hr/payroll/separations/'.$separation->id.'/clearance',
            ['key' => $this->blockingKeys()[0], 'cleared' => false],
        );

        $this->assertSame(Separation::STATUS_DRAFT, $separation->refresh()->status);
    }

    /**
     * Withholding someone's final pay over an unreturned lanyard is not a
     * defensible reason to miss a statutory deadline.
     */
    public function test_a_non_blocking_item_does_not_hold_up_release(): void
    {
        $separation = $this->releasable();

        $this->assertTrue($separation->isCleared());
        $this->actingAs($this->admin())
            ->post('/hr/payroll/separations/'.$separation->id.'/release')
            ->assertRedirect();
    }

    // --- Release -----------------------------------------------------------

    public function test_release_freezes_the_row_and_separates_the_employee(): void
    {
        $separation = $this->releasable();

        $this->actingAs($this->admin())
            ->post('/hr/payroll/separations/'.$separation->id.'/release')
            ->assertRedirect();

        $separation->refresh();

        $this->assertSame(Separation::STATUS_RELEASED, $separation->status);
        $this->assertNotNull($separation->released_at);
        $this->assertFalse($separation->isEditable());

        $employee = $separation->employee->refresh();
        $this->assertSame('resigned', $employee->employment_status);
        $this->assertSame('inactive', $employee->status);
    }

    public function test_release_settles_the_outstanding_loans(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => self::SALARY]);
        $this->payslip($employee, basic: 13050);
        $loan = $this->loan($employee, balance: 1000);

        $separation = $this->clear($this->open($employee));
        $this->actingAs($this->admin())->post('/hr/payroll/separations/'.$separation->id.'/release');

        $loan->refresh();
        $this->assertSame('0.00', $loan->outstanding_balance);
        $this->assertSame(EmployeeLoan::STATUS_PAID, $loan->status);
    }

    /** A loan the settlement could not cover stays owed, not written off. */
    public function test_a_loan_beyond_the_settlement_keeps_its_remaining_balance(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => self::SALARY]);
        $this->payslip($employee, basic: 12000);  // 1,000 of 13th month
        $loan = $this->loan($employee, balance: 5000);

        $separation = $this->clear($this->open($employee));
        $this->actingAs($this->admin())->post('/hr/payroll/separations/'.$separation->id.'/release');

        $this->assertSame('4000.00', $loan->refresh()->outstanding_balance);
        $this->assertSame(EmployeeLoan::STATUS_ACTIVE, $loan->status);
    }

    public function test_terminated_is_recorded_separately_from_resigned(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => self::SALARY]);
        $separation = $this->clear($this->open($employee, ['reason' => Separation::REASON_TERMINATED]));

        $this->actingAs($this->admin())->post('/hr/payroll/separations/'.$separation->id.'/release');

        $this->assertSame('terminated', $separation->employee->refresh()->employment_status);
    }

    // --- Access control ----------------------------------------------------

    /** Separation of duties: HR prepares the settlement, an admin releases it. */
    public function test_hr_staff_cannot_release_a_settlement_they_prepared(): void
    {
        $separation = $this->releasable();

        $this->actingAs($this->hr())
            ->post('/hr/payroll/separations/'.$separation->id.'/release')
            ->assertForbidden();
    }

    public function test_release_is_refused_while_a_blocking_item_is_open(): void
    {
        $separation = $this->separation();

        $this->actingAs($this->admin())
            ->post('/hr/payroll/separations/'.$separation->id.'/release')
            ->assertForbidden();
    }

    public function test_an_employee_cannot_view_the_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/hr/payroll/separations')
            ->assertForbidden();
    }

    public function test_a_supervisor_cannot_view_the_screen(): void
    {
        $this->actingAs(User::factory()->supervisor()->create())
            ->get('/hr/payroll/separations')
            ->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/hr/payroll/separations')->assertRedirect('/login');
    }

    public function test_an_employee_cannot_have_two_open_separations(): void
    {
        $separation = $this->separation();

        $this->actingAs($this->hr())->post('/hr/payroll/separations', [
            'employee_id' => $separation->employee_id,
            'last_day' => now()->toDateString(),
            'reason' => Separation::REASON_RESIGNED,
        ]);

        $this->assertSame(1, Separation::count());
    }

    // --- Helpers -----------------------------------------------------------

    private function separation(): Separation
    {
        $employee = Employee::factory()->create(['basic_salary' => self::SALARY]);
        $this->payslip($employee, basic: 13050);

        return $this->open($employee);
    }

    private function open(Employee $employee, array $data = []): Separation
    {
        return app(SeparationService::class)->open($employee, [
            'last_day' => now()->toDateString(),
            'reason' => Separation::REASON_RESIGNED,
            ...$data,
        ], $this->hr());
    }

    /** A separation with every blocking item signed off. */
    private function releasable(): Separation
    {
        return $this->clear($this->separation());
    }

    private function clear(Separation $separation): Separation
    {
        $service = app(SeparationService::class);

        foreach ($this->blockingKeys() as $key) {
            $separation = $service->toggleClearance($separation, $key, true);
        }

        return $separation;
    }

    /** @return array<int, string> */
    private function blockingKeys(): array
    {
        return collect(config('separation.checklist'))
            ->filter(fn (array $item) => $item['blocking'])
            ->keys()
            ->all();
    }

    private function convertibleCredits(Employee $employee, float $days, bool $convertible = true): LeaveBalance
    {
        $type = LeaveType::factory()->create(['is_convertible_to_cash' => $convertible]);

        return LeaveBalance::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'year' => now()->year,
            'credits_earned' => $days,
            'credits_used' => 0,
            'credits_carried_over' => 0,
        ]);
    }

    private function loan(Employee $employee, float $balance): EmployeeLoan
    {
        return EmployeeLoan::create([
            'employee_id' => $employee->id,
            'type' => 'company',
            'principal_amount' => $balance,
            'monthly_amortization' => 500,
            'outstanding_balance' => $balance,
            'start_date' => now()->subMonths(3)->toDateString(),
            'status' => EmployeeLoan::STATUS_ACTIVE,
        ]);
    }

    private function payslip(Employee $employee, float $basic, string $status = PayrollRun::STATUS_APPROVED): Payslip
    {
        $run = PayrollRun::create([
            'payroll_period_id' => $this->period()->id,
            'run_number' => 'PR-'.now()->year.'-'.str_pad((string) (PayrollRun::count() + 1), 4, '0', STR_PAD_LEFT),
            'status' => $status,
        ]);

        return Payslip::create([
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'payslip_number' => 'PS-'.$run->run_number.'-'.$employee->id,
            'basic_pay' => $basic,
        ]);
    }

    /** Held on the instance — see the date-cast gotcha in ThirteenthMonthTest. */
    private function period(): PayrollPeriod
    {
        return $this->period ??= PayrollPeriod::create([
            'name' => 'Aug 1 – 15',
            'start_date' => now()->year.'-08-01',
            'end_date' => now()->year.'-08-15',
            'pay_date' => now()->year.'-08-20',
            'frequency' => 'semi_monthly',
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);
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
