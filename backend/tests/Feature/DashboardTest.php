<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private ?PayrollPeriod $period = null;

    // --- Who may read company-wide figures ---------------------------------

    /**
     * The landing page used to print the whole company's net pay to every
     * signed-in role. Salary is gated behind `viewSensitive` everywhere else;
     * a dashboard tile is not an exception to that.
     */
    public function test_an_employee_is_not_shown_the_company_payroll_total(): void
    {
        $this->seedPayrollRun(765676.04);

        $user = User::factory()->create();
        Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.viewCompanyFigures', false)
                ->where('payroll.total_net', 0)
                ->where('payroll.period', null)
                ->where('payrollSummary', null)
                ->where('leaveSummary', null),
            );
    }

    public function test_a_supervisor_is_not_shown_the_company_payroll_total(): void
    {
        $this->seedPayrollRun(765676.04);

        $user = User::factory()->role(User::ROLE_SUPERVISOR)->create();
        Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.viewCompanyFigures', false)
                ->where('payroll.total_net', 0)
                ->where('payrollSummary', null),
            );
    }

    public function test_hr_sees_the_company_figures(): void
    {
        $this->seedPayrollRun(765676.04);

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.viewCompanyFigures', true)
                ->where('payroll.total_net', 765676.04)
                ->has('payrollSummary')
                ->has('leaveSummary'),
            );
    }

    /**
     * The figure must not survive anywhere in the payload — hiding the tile
     * while the number still rides along in the props is not hiding it.
     */
    public function test_the_payroll_total_appears_nowhere_in_an_employees_payload(): void
    {
        $this->seedPayrollRun(765676.04);

        $user = User::factory()->create();
        Employee::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->assertStringNotContainsString('765676.04', $response->getContent());
        $this->assertStringNotContainsString('765,676.04', $response->getContent());
    }

    // --- The trend series --------------------------------------------------

    public function test_the_headcount_trend_covers_twelve_months(): void
    {
        Employee::factory()->count(3)->create(['date_hired' => now()->subYears(2)]);

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('headcountTrend', 12));
    }

    /**
     * A hire inside the window has to move the line, or the chart is drawing
     * a constant and calling it a trend.
     */
    public function test_a_recent_hire_lifts_the_end_of_the_trend(): void
    {
        Employee::factory()->count(4)->create(['date_hired' => now()->subMonths(11)]);
        Employee::factory()->create(['date_hired' => now()->subDays(3)]);

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $trend = $page->toArray()['props']['headcountTrend'];

                $this->assertSame(4, $trend[0]['value'], 'Eleven months ago: four on the books.');
                $this->assertSame(5, $trend[11]['value'], 'This month: the new hire counts.');
            });
    }

    public function test_a_separation_lowers_the_trend(): void
    {
        Employee::factory()->count(3)->create(['date_hired' => now()->subYear()]);
        Employee::factory()->create([
            'date_hired' => now()->subYear(),
            'date_separated' => now()->subDays(5),
        ]);

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $trend = $page->toArray()['props']['headcountTrend'];

                $this->assertSame(4, $trend[0]['value']);
                $this->assertSame(3, $trend[11]['value'], 'The leaver is off the books.');
            });
    }

    // --- The summary tiles -------------------------------------------------

    public function test_the_leave_summary_counts_this_months_requests_by_status(): void
    {
        $employee = Employee::factory()->create();

        foreach ([
            LeaveRequest::STATUS_PENDING,
            LeaveRequest::STATUS_PENDING,
            LeaveRequest::STATUS_APPROVED,
            LeaveRequest::STATUS_REJECTED,
        ] as $status) {
            LeaveRequest::factory()->create([
                'employee_id' => $employee->id,
                'status' => $status,
            ]);
        }

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('leaveSummary.pending', 2)
                ->where('leaveSummary.approved', 1)
                ->where('leaveSummary.rejected', 1),
            );
    }

    /** Only finalised runs are money that moved — the PayrollRun::REPORTABLE rule. */
    public function test_the_payroll_summary_counts_runs_by_stage(): void
    {
        $this->payrollRun(PayrollRun::STATUS_DRAFT);
        $this->payrollRun(PayrollRun::STATUS_FOR_APPROVAL);
        $this->payrollRun(PayrollRun::STATUS_PAID);

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payrollSummary.draft', 1)
                ->where('payrollSummary.for_approval', 1)
                ->where('payrollSummary.released', 1),
            );
    }

    /** Scoped, unlike the two above — an employee sees their own file only. */
    public function test_the_onboarding_summary_is_scoped_to_what_the_viewer_may_see(): void
    {
        Employee::factory()->count(5)->create(['date_hired' => now()->subDays(3)]);

        $user = User::factory()->create();
        Employee::factory()->create(['user_id' => $user->id, 'date_hired' => now()->subDays(3)]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('onboardingSummary.new_hires', 1),
            );
    }

    // --- The reader's own record -------------------------------------------

    /**
     * The one band on this screen that belongs to the person reading it.
     *
     * Everything else here is the company looking at itself, and for a
     * rank-and-file login none of it is theirs to act on — so the card carries
     * their own record and the four screens that are theirs.
     */
    public function test_the_dashboard_carries_the_readers_own_record(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $employee = Employee::factory()->create([
            'user_id' => $user->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('profile.employee.id', $employee->id)
                ->where('profile.employee.employee_number', $employee->employee_number)
                ->where('profile.name', $employee->full_name),
            );
    }

    /**
     * A login with no 201 file is a real case, not a defensive branch.
     *
     * An administrator need not be an employee at all — a pure system account
     * has no record, no attendance and no payslip — and the card's links are
     * built from `employee.id`. Left un-handled, the first thing that account
     * sees on landing is four links into a 404.
     */
    public function test_an_account_with_no_employee_record_gets_a_null_rather_than_a_broken_card(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'System Account']);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('profile.employee', null)
                // It still names who is signed in, from the account itself.
                ->where('profile.name', 'System Account')
                ->where('profile.email', $admin->email),
            );
    }

    /**
     * Their own rate is theirs, and the card asks the policy rather than
     * assuming it.
     *
     * `EmployeePolicy::viewSensitive` returns true for HR *and* for the person
     * the record belongs to — an employee has always been able to open their
     * own 201 file and read their own salary, so withholding it from their own
     * dashboard would be the screen disagreeing with the gate.
     */
    public function test_the_card_carries_the_readers_own_salary(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        Employee::factory()->create(['user_id' => $user->id, 'basic_salary' => 45000]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                // Compared numerically: the cast is a float and the JSON round
                // trip hands back an int, and `where()` is strict.
                ->where(
                    'profile.employee.compensation.basic_salary',
                    fn ($value) => (float) $value === 45000.0,
                ),
            );
    }

    /**
     * Government numbers and the bank account stay out whatever the policy
     * says about salary.
     *
     * They are what a stolen dump is worth stealing, and nothing on a landing
     * page needs them — so this asserts the *rendered payload* rather than the
     * shape of the array, the way `DirectoryTest` does. A leak elsewhere in the
     * props would still be a leak.
     */
    public function test_the_card_carries_no_government_numbers_or_bank_details(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        Employee::factory()->create([
            'user_id' => $user->id,
            'tin' => '123-456-789-000',
            'bank_account_number' => '0011-2233-4455',
            'sss_number' => '34-1234567-8',
        ]);

        $payload = $this->actingAs($user)->get('/dashboard')->getContent();

        foreach (['123-456-789-000', '0011-2233-4455', '34-1234567-8'] as $secret) {
            $this->assertStringNotContainsString($secret, $payload, "{$secret} reached the dashboard.");
        }
    }

    private function seedPayrollRun(float $net): void
    {
        $this->payrollRun(PayrollRun::STATUS_PAID, $net);
    }

    private function payrollRun(string $status, float $net = 0): PayrollRun
    {
        return PayrollRun::create([
            'payroll_period_id' => $this->period()->id,
            'run_number' => 'PR-'.now()->year.'-'.str_pad((string) (PayrollRun::count() + 1), 4, '0', STR_PAD_LEFT),
            'status' => $status,
            'total_net' => $net,
        ]);
    }

    /**
     * Held on the instance: `firstOrCreate` matches on exact column equality
     * and these date-cast columns store as "Y-m-d 00:00:00", so a "Y-m-d"
     * lookup misses and inserts a duplicate.
     */
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
}
