<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What Core 2 publishes to the rest of ISMERS, and what it refuses to.
 *
 * The interesting assertions are the refusals and the agreements. An
 * integration is only worth anything if the figure a consumer reads is the
 * same figure our own screen shows — so these check that the endpoints are
 * wired to the services behind those screens rather than to a second copy of
 * the rules — and if it hands over no more than the consumer needs.
 */
class IntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    /*
     * -----------------------------------------------------------------
     * Fleet & Transportation — /drivers
     * -----------------------------------------------------------------
     */

    public function test_fleet_gets_the_licence_facts_a_dispatcher_needs(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        Employee::factory()->create([
            'drivers_license_number' => 'N02-24-001292',
            'license_dl_codes' => 'B,C',
            'license_conditions' => '4',
            'license_expiry' => now()->addYear(),
            'status' => 'active',
        ]);

        $this->getJson('/api/v1/drivers')
            ->assertOk()
            ->assertJsonPath('data.0.licence.dl_codes.0.code', 'B')
            // Spelled out, not just coded: sending only "C" would make every
            // consumer keep its own copy of the LTO table.
            ->assertJsonPath('data.0.licence.dl_codes.1.label', 'Goods over 3500 kgs GVW')
            ->assertJsonPath('data.0.may_drive', true)
            // Condition 4 reaches scheduling — that driver cannot take a night
            // run, and nothing else in the payload says so.
            ->assertJsonPath('data.0.operational_restrictions.0', 'Daylight driving only');
    }

    public function test_a_lapsed_licence_says_the_driver_may_not_drive(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        Employee::factory()->create([
            'drivers_license_number' => 'N02-20-000001',
            'license_expiry' => now()->subMonth(),
            'status' => 'active',
        ]);

        $this->getJson('/api/v1/drivers')
            ->assertOk()
            ->assertJsonPath('data.0.licence.is_expired', true)
            // The single field a dispatch screen keys on. False means
            // assigning this driver would be unlawful, not merely untidy.
            ->assertJsonPath('data.0.may_drive', false);
    }

    public function test_drivers_never_carry_salary_or_government_numbers(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        Employee::factory()->create([
            'drivers_license_number' => 'N02-24-001292',
            'basic_salary' => 30000,
            'sss_number' => '34-1234567-8',
        ]);

        $body = $this->getJson('/api/v1/drivers')->assertOk()->json('data.0');

        /*
         * Fleet has no reason to hold these. An integration that hands over
         * more than the consumer needs is the failure noticed after a breach
         * rather than before one — and an admin token is exactly the case
         * where a lazier resource would have leaked them.
         */
        $this->assertArrayNotHasKey('basic_salary', $body);
        $this->assertArrayNotHasKey('sss_number', $body);
        $this->assertArrayNotHasKey('bank_account_number', $body);
    }

    public function test_fleet_can_ask_for_one_clients_drivers(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $client = Client::create(['code' => 'MFL', 'name' => 'Metro Fleet', 'is_active' => true]);

        Employee::factory()->create([
            'drivers_license_number' => 'N02-24-000001',
            'employment_category' => Employee::CATEGORY_EXTERNAL,
            'client_id' => $client->id,
        ]);
        Employee::factory()->create(['drivers_license_number' => 'N02-24-000002']);

        $this->getJson("/api/v1/drivers?client_id={$client->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /*
     * -----------------------------------------------------------------
     * Core 1 and Fleet — /deployment-readiness
     * -----------------------------------------------------------------
     */

    public function test_deployment_readiness_agrees_with_the_screen_it_comes_from(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        Employee::factory()->count(3)->create();

        $body = $this->getJson('/api/v1/deployment-readiness')->assertOk()->json();

        // The same service `/hr/deployment` renders, so the counts cannot
        // drift between the screen and the consumer.
        $this->assertSame(3, $body['meta']['total']);
        $this->assertSame(
            3,
            $body['meta']['ready'] + $body['meta']['warning'] + $body['meta']['blocked'],
        );
    }

    /*
     * -----------------------------------------------------------------
     * Financial Management — /payroll/runs
     * -----------------------------------------------------------------
     */

    public function test_finance_only_sees_runs_this_system_has_agreed_to(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->payrollRun(PayrollRun::STATUS_DRAFT);
        $approved = $this->payrollRun(PayrollRun::STATUS_APPROVED);

        /*
         * A draft is still being corrected. Finance disbursing against one
         * would be paying a figure this system has not agreed to — which is
         * why `scopeReportable()` is read rather than the condition rewritten.
         */
        $this->getJson('/api/v1/payroll/runs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $approved->id);
    }

    public function test_a_draft_run_refuses_to_produce_a_register(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $draft = $this->payrollRun(PayrollRun::STATUS_DRAFT);

        // 409, not 404: the run exists, it is just not disbursable yet, and
        // saying so is the difference between "retry later" and "wrong id".
        $this->getJson("/api/v1/payroll/runs/{$draft->id}/register")->assertStatus(409);
    }

    /*
     * -----------------------------------------------------------------
     * Core 3 — /loans
     * -----------------------------------------------------------------
     */

    public function test_core_three_can_post_a_loan_for_payroll_to_deduct(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $employee = Employee::factory()->create();

        $this->postJson('/api/v1/loans', [
            'reference_number' => 'C3-LOAN-0001',
            'employee_id' => $employee->id,
            'type' => 'sss',
            'principal_amount' => 24000,
            'monthly_amortization' => 2000,
            'start_date' => '2026-09-01',
        ])
            ->assertCreated()
            ->assertJsonPath('data.outstanding_balance', 24000)
            // Stated rather than left to be inferred: a balance read mid-cycle
            // is the balance *before* this period's deduction.
            ->assertJsonPath('data.amortised_on_payroll_approval', true);

        $this->assertDatabaseHas('employee_loans', [
            'reference_number' => 'C3-LOAN-0001',
            'status' => EmployeeLoan::STATUS_ACTIVE,
        ]);
    }

    public function test_a_resent_loan_does_not_deduct_twice(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $body = [
            'reference_number' => 'C3-LOAN-RETRY',
            'employee_id' => Employee::factory()->create()->id,
            'type' => 'company',
            'principal_amount' => 12000,
            'monthly_amortization' => 1000,
            'start_date' => '2026-09-01',
        ];

        $this->postJson('/api/v1/loans', $body)->assertCreated();

        // A timeout on their side is indistinguishable from a failure, so they
        // resend — and two rows for one loan is the employee paying it twice.
        $this->postJson('/api/v1/loans', $body)->assertOk();

        $this->assertDatabaseCount('employee_loans', 1);
    }

    public function test_an_amortisation_larger_than_the_loan_is_refused(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        // A keying error that would otherwise take one period to surface, on
        // somebody's pay.
        $this->postJson('/api/v1/loans', [
            'reference_number' => 'C3-LOAN-BAD',
            'employee_id' => Employee::factory()->create()->id,
            'type' => 'company',
            'principal_amount' => 5000,
            'monthly_amortization' => 9000,
            'start_date' => '2026-09-01',
        ])->assertStatus(422)->assertJsonValidationErrors('monthly_amortization');
    }

    /*
     * -----------------------------------------------------------------
     * Core 4 and BI — /analytics/workforce
     * -----------------------------------------------------------------
     */

    public function test_analytics_returns_shapes_and_never_people(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        Employee::factory()->count(4)->create();

        $body = $this->getJson('/api/v1/analytics/workforce')->assertOk()->json('data');

        $this->assertSame(4, $body['headcount']['total']);
        $this->assertArrayNotHasKey('attendance', $body);

        /*
         * A dashboard needs shapes, not people. An endpoint that hands over
         * the directory to draw a bar chart is the endpoint that will one day
         * be the way the directory left.
         */
        $this->assertStringNotContainsString(
            'employee_number',
            json_encode($body),
        );
    }

    /*
     * -----------------------------------------------------------------
     * The doors that are shut
     * -----------------------------------------------------------------
     */

    public function test_every_integration_endpoint_is_closed_to_anonymous_callers(): void
    {
        foreach ([
            '/api/v1/drivers',
            '/api/v1/deployment-readiness',
            '/api/v1/payroll/runs',
            '/api/v1/analytics/workforce',
        ] as $endpoint) {
            $this->getJson($endpoint)->assertUnauthorized();
        }

        $this->postJson('/api/v1/loans', [])->assertUnauthorized();
    }

    public function test_a_rank_and_file_token_cannot_read_payroll_or_post_loans(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]));

        // The token's role decides what a consuming team may see. Issue Core
        // 3's token from an HR account, not from anybody's.
        $this->getJson('/api/v1/payroll/runs')->assertForbidden();
        $this->postJson('/api/v1/loans', [])->assertForbidden();
    }
    // --- Financial Management: payroll as a journal entry -------------------

    /**
     * The assertion the whole endpoint exists for.
     *
     * A journal entry that does not balance cannot be posted, and every figure
     * here is **read back from stored payslips** rather than recomputed. So if
     * a payslip were ever written with a `net_pay` that did not equal
     * `gross_pay - deductions_total`, this is where it would surface — which is
     * the difference between Finance catching it now and finding it in a trial
     * balance at month end.
     */
    public function test_the_journal_entry_balances(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $run = $this->payrollRun(PayrollRun::STATUS_PAID);
        $this->payslipOn($run, [
            'basic_pay' => 20000,
            'overtime_pay' => 1500,
            'allowances_total' => 2000,
            'sss_employee' => 900,
            'philhealth_employee' => 500,
            'pagibig_employee' => 200,
            'withholding_tax' => 1200,
            'late_deduction' => 300,
            'loans_deduction' => 1000,
            'sss_employer' => 1800,
            'philhealth_employer' => 500,
            'pagibig_employer' => 200,
        ]);

        $response = $this->getJson("/api/v1/payroll/journal-summary/{$run->payroll_period_id}")
            ->assertOk();

        $response->assertJsonPath('meta.balanced', true);
        $this->assertSame(0.0, (float) $response->json('meta.out_of_balance_by'));
        $this->assertSame(
            $response->json('meta.total_debits'),
            $response->json('meta.total_credits'),
        );
    }

    /**
     * Time not worked is a **contra to salary expense**, not a payable.
     *
     * Nobody is owed the money somebody lost to lateness — the company simply
     * spent less. Filing it as a liability would put figures on the balance
     * sheet that will never be paid to anyone, which is the kind of error
     * found in an audit rather than in a reconciliation.
     */
    public function test_time_not_worked_is_a_contra_and_not_a_liability(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $run = $this->payrollRun(PayrollRun::STATUS_PAID);
        $this->payslipOn($run, [
            'basic_pay' => 20000,
            'late_deduction' => 250,
            'absence_deduction' => 1000,
            'unpaid_leave_deduction' => 500,
            'undertime_deduction' => 100,
        ]);

        $credits = collect(
            $this->getJson("/api/v1/payroll/journal-summary/{$run->payroll_period_id}")
                ->assertOk()
                ->json('data.credits'),
        );

        $contra = $credits->firstWhere('account', 'Salaries Expense — Time Not Worked (contra)');

        $this->assertNotNull($contra, 'Time not worked was not reported at all.');
        $this->assertSame(1850.0, (float) $contra['amount'], 'The four deductions are one contra line.');
    }

    /**
     * The employer's share is a cost *and* a payable, so it appears on both
     * sides and nets out. A journal that only credited the liability would
     * understate what payroll cost the company by exactly that amount.
     */
    public function test_the_employer_share_appears_as_both_expense_and_payable(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $run = $this->payrollRun(PayrollRun::STATUS_PAID);
        $this->payslipOn($run, [
            'basic_pay' => 20000,
            'sss_employee' => 900,
            'sss_employer' => 1800,
        ]);

        $body = $this->getJson("/api/v1/payroll/journal-summary/{$run->payroll_period_id}")
            ->assertOk()
            ->json();

        $expense = collect($body['data']['debits'])
            ->firstWhere('account', 'SSS Contributions Expense (Employer)');
        $payable = collect($body['data']['credits'])->firstWhere('account', 'SSS Payable');

        $this->assertSame(1800.0, (float) $expense['amount']);
        // One cheque goes to SSS, so the employee's withholding and the
        // employer's share land in one payable.
        $this->assertSame(2700.0, (float) $payable['amount']);
    }

    /**
     * A draft is still being corrected, so a journal built from one is a number
     * Finance would post and then have to reverse.
     *
     * **409 rather than 404 or an empty entry**, the same distinction
     * `/register` draws: the period exists and is simply not finalised. An
     * empty journal would be the worst of the three — a period posted as zero
     * reads as a month nobody was paid.
     */
    public function test_a_period_with_only_a_draft_run_answers_409(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $run = $this->payrollRun(PayrollRun::STATUS_DRAFT);
        $this->payslipOn($run, ['basic_pay' => 20000]);

        $this->getJson("/api/v1/payroll/journal-summary/{$run->payroll_period_id}")
            ->assertStatus(409);
    }

    /** Nothing on this endpoint is readable without a token. */
    public function test_the_journal_summary_needs_a_token(): void
    {
        $run = $this->payrollRun(PayrollRun::STATUS_PAID);

        $this->getJson("/api/v1/payroll/journal-summary/{$run->payroll_period_id}")
            ->assertUnauthorized();
    }

    /**
     * A period is posted as a whole, so more than one run in it is summed —
     * and the runs are named in the meta so a reconciliation that disagrees
     * has somewhere to start.
     */
    public function test_every_reportable_run_in_the_period_is_included(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $first = $this->payrollRun(PayrollRun::STATUS_PAID);
        $this->payslipOn($first, ['basic_pay' => 10000]);

        // A second run over the same period — approved, so also reportable.
        $second = PayrollRun::create([
            'payroll_period_id' => $first->payroll_period_id,
            'run_number' => 'PR-2026-9999',
            'status' => PayrollRun::STATUS_APPROVED,
            'employee_count' => 0,
            'total_gross' => 0,
            'total_deductions' => 0,
            'total_net' => 0,
        ]);
        $this->payslipOn($second, ['basic_pay' => 5000]);

        $body = $this->getJson("/api/v1/payroll/journal-summary/{$first->payroll_period_id}")
            ->assertOk()
            ->json();

        $basic = collect($body['data']['debits'])->firstWhere('account', 'Basic Pay Expense');

        $this->assertSame(15000.0, (float) $basic['amount'], 'Both runs should be summed.');
        $this->assertCount(2, $body['meta']['runs']);
        $this->assertSame(2, $body['meta']['employee_count']);
    }
    // --- Financial Management: confirming the money left the bank ----------

    /**
     * The other end of `/register`, and what closes a loop that was open.
     *
     * Before this, the register handed Finance a list and nothing came back —
     * a run sat at `approved` until somebody in HR remembered to tick it, so
     * *approved* and *the money arrived* were two facts the system reported as
     * one.
     */
    public function test_finance_can_confirm_a_disbursement(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $run = $this->payrollRun(PayrollRun::STATUS_APPROVED);
        $run->update(['total_net' => 407610.55]);

        $this->postJson("/api/v1/payroll/runs/{$run->id}/disbursement", [
            'bank_reference' => 'BPI-TRF-99120044',
            'amount' => 407610.55,
            'disbursed_at' => '2026-09-20T09:15:00+08:00',
        ])->assertOk()->assertJsonPath('data.status', PayrollRun::STATUS_PAID);

        $run->refresh();

        $this->assertSame(PayrollRun::STATUS_PAID, $run->status);
        $this->assertSame('BPI-TRF-99120044', $run->disbursement_reference);
        $this->assertNotNull($run->disbursed_at);
    }

    /**
     * **The assertion this endpoint exists for.** A file that disbursed less
     * than the register said is somebody unpaid, and marking the run `paid`
     * over it would bury that — so the amount is checked against the run's own
     * total rather than trusted, and a mismatch is refused with the difference
     * stated.
     */
    public function test_a_mismatched_amount_is_refused_and_nothing_is_marked_paid(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $run = $this->payrollRun(PayrollRun::STATUS_APPROVED);
        $run->update(['total_net' => 407610.55]);

        $this->postJson("/api/v1/payroll/runs/{$run->id}/disbursement", [
            'bank_reference' => 'BPI-TRF-SHORT',
            // Short by ₱10,000 — one employee's pay, near enough.
            'amount' => 397610.55,
            'disbursed_at' => '2026-09-20T09:15:00+08:00',
        ])->assertStatus(409);

        $this->assertSame(PayrollRun::STATUS_APPROVED, $run->fresh()->status);
        $this->assertNull($run->fresh()->disbursement_reference);
    }

    /**
     * A centavo of tolerance, because the two figures are sums of rounded
     * currency reached by two systems — not one number twice.
     */
    public function test_a_centavo_of_rounding_is_tolerated(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $run = $this->payrollRun(PayrollRun::STATUS_APPROVED);
        $run->update(['total_net' => 100000.00]);

        $this->postJson("/api/v1/payroll/runs/{$run->id}/disbursement", [
            'bank_reference' => 'BPI-TRF-ROUND',
            'amount' => 100000.004,
            'disbursed_at' => '2026-09-20T09:15:00+08:00',
        ])->assertOk();
    }

    /**
     * A draft is still being corrected and a cancelled run was withdrawn. A
     * bank transfer against either is a fact somebody needs to look at rather
     * than a status this system should quietly accept.
     */
    public function test_only_an_approved_run_can_be_confirmed(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $draft = $this->payrollRun(PayrollRun::STATUS_DRAFT);

        $this->postJson("/api/v1/payroll/runs/{$draft->id}/disbursement", [
            'bank_reference' => 'BPI-TRF-EARLY',
            'amount' => 0,
            'disbursed_at' => '2026-09-20T09:15:00+08:00',
        ])->assertStatus(409);

        $this->assertSame(PayrollRun::STATUS_DRAFT, $draft->fresh()->status);
    }

    /**
     * A timeout on Finance's side is indistinguishable from a failure, so they
     * resend — and the stored reference comes back with 200 rather than the run
     * being marked paid twice.
     */
    public function test_a_resent_confirmation_is_idempotent(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $run = $this->payrollRun(PayrollRun::STATUS_APPROVED);
        $run->update(['total_net' => 5000]);

        $body = [
            'bank_reference' => 'BPI-TRF-ONCE',
            'amount' => 5000,
            'disbursed_at' => '2026-09-20T09:15:00+08:00',
        ];

        $this->postJson("/api/v1/payroll/runs/{$run->id}/disbursement", $body)
            ->assertOk()
            ->assertJsonPath('data.already_confirmed', false);

        $this->postJson("/api/v1/payroll/runs/{$run->id}/disbursement", $body)
            ->assertOk()
            ->assertJsonPath('data.already_confirmed', true)
            ->assertJsonPath('data.bank_reference', 'BPI-TRF-ONCE');
    }

    /**
     * Gated on `PayrollRunPolicy::markPaid`, the ability this system already
     * had for exactly this act — not on `approve`, which is a different
     * decision and is coupled to `for_approval`.
     *
     * That policy says `isHrAdmin()`, so **HR staff may confirm** and this
     * test asserts it rather than inventing a stricter rule for the API than
     * the screen has. Two answers to "who may mark a run paid" is the thing
     * worth avoiding; the separation of duties that matters is on `approve`,
     * which is admin-only and refuses the person who processed the run.
     */
    public function test_hr_staff_may_confirm_a_disbursement(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $run = $this->payrollRun(PayrollRun::STATUS_APPROVED);
        $run->update(['total_net' => 5000]);

        $this->postJson("/api/v1/payroll/runs/{$run->id}/disbursement", [
            'bank_reference' => 'BPI-TRF-OK',
            'amount' => 5000,
            'disbursed_at' => '2026-09-20T09:15:00+08:00',
        ])->assertOk();
    }

    /** A rank-and-file token has no business marking money as paid. */
    public function test_a_rank_and_file_token_cannot_confirm_a_disbursement(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]));

        $run = $this->payrollRun(PayrollRun::STATUS_APPROVED);
        $run->update(['total_net' => 5000]);

        $this->postJson("/api/v1/payroll/runs/{$run->id}/disbursement", [
            'bank_reference' => 'BPI-TRF-NOPE',
            'amount' => 5000,
            'disbursed_at' => '2026-09-20T09:15:00+08:00',
        ])->assertForbidden();
    }

    /**
     * Required, and it is the whole audit trail for "which transfer paid this
     * run" — without it the only record that money moved is a status column.
     */
    public function test_a_bank_reference_is_required(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $run = $this->payrollRun(PayrollRun::STATUS_APPROVED);

        $this->postJson("/api/v1/payroll/runs/{$run->id}/disbursement", [
            'amount' => 0,
            'disbursed_at' => '2026-09-20T09:15:00+08:00',
        ])->assertJsonValidationErrors('bank_reference');
    }

    /**
     * A payroll run in a given state.
     *
     * Built rather than faked because there is no factory for one, and there
     * should not be: a run is produced by `PayrollService::generate()` from a
     * period, and a factory would invite tests to invent runs that could never
     * exist. These two only need the status.
     */
    private function payrollRun(string $status): PayrollRun
    {
        static $sequence = 0;
        $sequence++;

        /*
         * A fresh fortnight each time. (start_date, end_date) is unique — two
         * runs over the same cut-off is not a thing payroll allows, and the
         * constraint says so before a second one can exist.
         */
        $start = now()->startOfYear()->addDays(($sequence - 1) * 15);

        $period = PayrollPeriod::create([
            'name' => "Period {$sequence}",
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(14)->toDateString(),
            'pay_date' => $start->copy()->addDays(19)->toDateString(),
            'frequency' => 'semi_monthly',
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);

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

    /**
     * One payslip on a run, with the figures a journal needs.
     *
     * `gross_pay`, `deductions_total` and `net_pay` are derived here rather
     * than passed in, because that is the identity the endpoint's balance check
     * rests on — a test that set all three by hand could assert a balance the
     * arithmetic never had.
     */
    private function payslipOn(PayrollRun $run, array $figures): Payslip
    {
        $earnings = ['basic_pay', 'overtime_pay', 'night_diff_pay', 'holiday_pay', 'allowances_total'];
        $withheld = [
            'sss_employee', 'philhealth_employee', 'pagibig_employee', 'withholding_tax',
            'late_deduction', 'undertime_deduction', 'absence_deduction',
            'unpaid_leave_deduction', 'loans_deduction', 'other_deductions',
        ];

        $gross = array_sum(array_map(fn ($k) => (float) ($figures[$k] ?? 0), $earnings));
        $deductions = array_sum(array_map(fn ($k) => (float) ($figures[$k] ?? 0), $withheld));

        static $sequence = 0;
        $sequence++;

        return Payslip::create(array_merge([
            'payroll_run_id' => $run->id,
            'employee_id' => Employee::factory()->create()->id,
            'payslip_number' => sprintf('PS-2026-%05d', $sequence),
            'gross_pay' => $gross,
            'deductions_total' => $deductions,
            'net_pay' => $gross - $deductions,
        ], $figures));
    }
}
