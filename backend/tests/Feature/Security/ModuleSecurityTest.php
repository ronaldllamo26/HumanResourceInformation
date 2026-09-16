<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Kpi;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PerformanceReview;
use App\Models\PerformanceReviewRating;
use App\Models\User;
use App\Services\AuditLogSigner;
use App\Services\PayrollAnomalyScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Security controls added across Leave, Payroll, Performance and the audit log.
 */
class ModuleSecurityTest extends TestCase
{
    use RefreshDatabase;

    // --- Leave: segregation of duties and balance validation ---------------

    public function test_hr_cannot_adjust_their_own_leave_credits(): void
    {
        $hr = User::factory()->hrStaff()->create();
        $mine = Employee::factory()->create(['user_id' => $hr->id]);
        $type = LeaveType::factory()->create();

        $this->actingAs($hr)->post(route('hr.leave.balances.update'), [
            'employee_id' => $mine->id,
            'leave_type_id' => $type->id,
            'year' => now()->year,
            'credits_earned' => 99,
            'credits_carried_over' => 0,
        ])->assertSessionHas('error');

        $this->assertDatabaseMissing('leave_balances', ['employee_id' => $mine->id, 'credits_earned' => 99]);
    }

    public function test_hr_can_still_adjust_somebody_elses_credits(): void
    {
        $hr = User::factory()->hrStaff()->create();
        $other = Employee::factory()->create();
        $type = LeaveType::factory()->create();

        $this->actingAs($hr)->post(route('hr.leave.balances.update'), [
            'employee_id' => $other->id,
            'leave_type_id' => $type->id,
            'year' => now()->year,
            'credits_earned' => 12,
            'credits_carried_over' => 0,
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');

        $this->assertDatabaseHas('leave_balances', ['employee_id' => $other->id, 'credits_earned' => 12]);
    }

    public function test_a_balance_cannot_be_set_below_what_was_already_used(): void
    {
        $hr = User::factory()->hrStaff()->create();
        $other = Employee::factory()->create();
        $type = LeaveType::factory()->create();
        LeaveBalance::create([
            'employee_id' => $other->id, 'leave_type_id' => $type->id, 'year' => now()->year,
            'credits_earned' => 10, 'credits_used' => 6, 'credits_carried_over' => 0,
        ]);

        $this->actingAs($hr)->post(route('hr.leave.balances.update'), [
            'employee_id' => $other->id,
            'leave_type_id' => $type->id,
            'year' => now()->year,
            'credits_earned' => 4,
            'credits_carried_over' => 1,
        ])->assertSessionHasErrors('credits_earned');
    }

    public function test_approval_is_refused_when_the_balance_no_longer_covers_the_request(): void
    {
        $hr = User::factory()->hrStaff()->create();
        $employee = Employee::factory()->create();
        $type = LeaveType::factory()->create(['is_paid' => true]);
        LeaveBalance::create([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => now()->year,
            'credits_earned' => 2, 'credits_used' => 0, 'credits_carried_over' => 0,
        ]);
        $request = LeaveRequest::factory()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => now()->startOfYear()->addMonths(6),
            'end_date' => now()->startOfYear()->addMonths(6)->addDays(4),
            'days_requested' => 5,
            'status' => LeaveRequest::STATUS_PENDING,
        ]);

        $this->actingAs($hr)->post(route('hr.leave.approve', $request))->assertSessionHas('error');

        $this->assertSame(LeaveRequest::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_nobody_decides_their_own_leave(): void
    {
        $hr = User::factory()->hrStaff()->create();
        $mine = Employee::factory()->create(['user_id' => $hr->id]);
        $request = LeaveRequest::factory()->create([
            'employee_id' => $mine->id,
            'status' => LeaveRequest::STATUS_PENDING,
        ]);

        $this->actingAs($hr)->post(route('hr.leave.approve', $request))->assertForbidden();
    }

    // --- Payroll: segregation of duties, masking, anomalies ---------------

    public function test_the_person_who_computed_a_run_cannot_mark_it_paid(): void
    {
        $processor = User::factory()->hrStaff()->create();
        $other = User::factory()->hrStaff()->create();
        $run = $this->makeRun(['status' => PayrollRun::STATUS_APPROVED, 'processed_by' => $processor->id]);

        $this->assertFalse($processor->can('markPaid', $run));
        $this->assertTrue($other->can('markPaid', $run));
    }

    public function test_the_payslip_carries_masked_numbers_only(): void
    {
        $hr = User::factory()->hrStaff()->create();
        $employee = Employee::factory()->create([
            'sss_number' => '34-1234567-8',
            'bank_account_number' => '0012345678',
        ]);
        $payslip = $this->payslip($this->makeRun(), ['employee_id' => $employee->id]);

        $response = $this->actingAs($hr)->get(route('hr.payroll.payslip', $payslip));

        $response->assertOk();
        $this->assertStringNotContainsString('34-1234567-8', $response->getContent());
        $this->assertStringNotContainsString('0012345678', $response->getContent());
        $this->assertStringContainsString('5678', $response->getContent());
    }

    public function test_the_scanner_flags_two_salaries_into_one_bank_account(): void
    {
        $run = $this->makeRun();
        foreach ([1, 2] as $_) {
            $this->payslip($run, [
                'employee_id' => Employee::factory()->create(['bank_account_number' => '0012-3456-78'])->id,
            ]);
        }

        $keys = collect(app(PayrollAnomalyScanner::class)->scan($run)['findings'])->pluck('key');

        $this->assertContains('shared_bank_account', $keys);
    }

    public function test_the_scanner_flags_pay_to_somebody_who_has_resigned(): void
    {
        $run = $this->makeRun();
        $this->payslip($run, [
            'employee_id' => Employee::factory()->create(['employment_status' => 'resigned'])->id,
        ]);

        $result = app(PayrollAnomalyScanner::class)->scan($run);

        $this->assertContains('paid_after_leaving', collect($result['findings'])->pluck('key'));
        $this->assertGreaterThan(0, $result['critical']);
    }

    public function test_the_scanner_flags_a_payslip_that_does_not_add_up(): void
    {
        $run = $this->makeRun();
        $this->payslip($run, [
            'gross_pay' => 20000,
            'deductions_total' => 3000,
            'net_pay' => 19000,
        ]);

        $keys = collect(app(PayrollAnomalyScanner::class)->scan($run)['findings'])->pluck('key');

        $this->assertContains('figures_do_not_add_up', $keys);
    }

    public function test_a_clean_run_reports_no_anomalies(): void
    {
        $run = $this->makeRun();
        $this->payslip($run, [
            'gross_pay' => 20000,
            'deductions_total' => 3000,
            'net_pay' => 17000,
        ]);

        $this->assertTrue(app(PayrollAnomalyScanner::class)->scan($run)['clean']);
    }

    // --- Performance: rater anonymity and rating audit --------------------

    public function test_a_peer_reviewer_is_anonymous_to_the_person_reviewed(): void
    {
        $employeeUser = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $employeeUser->id]);
        $peer = User::factory()->create(['name' => 'Secret Peer']);
        $review = PerformanceReview::factory()->create([
            'employee_id' => $employee->id,
            'reviewer_id' => $peer->id,
            'reviewer_type' => 'peer',
            'status' => PerformanceReview::STATUS_SUBMITTED,
        ]);

        $this->actingAs($employeeUser)->get(route('hr.performance.review', $review))
            ->assertOk()
            ->assertDontSee('Secret Peer');

        $hr = User::factory()->hrStaff()->create();
        $this->actingAs($hr)->get(route('hr.performance.review', $review))->assertSee('Secret Peer');
    }

    public function test_changing_a_rating_is_audited(): void
    {
        $review = PerformanceReview::factory()->create();
        $rating = PerformanceReviewRating::create([
            'performance_review_id' => $review->id,
            'kpi_id' => Kpi::factory()->create()->id,
            'rating' => 3,
            'weight' => 100,
        ]);

        $rating->update(['rating' => 5]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => PerformanceReviewRating::class,
            'auditable_id' => $rating->id,
            'event' => 'updated',
        ]);
    }

    // --- Tamper-evident audit log ------------------------------------------

    public function test_every_audit_entry_is_signed_and_verifies(): void
    {
        $hr = User::factory()->hrStaff()->create();
        $this->actingAs($hr);
        Employee::factory()->create();

        $result = app(AuditLogSigner::class)->verify();

        $this->assertGreaterThan(0, $result['checked']);
        $this->assertSame($result['checked'], $result['valid']);
        $this->assertSame([], $result['altered']);
    }

    public function test_an_entry_edited_in_the_database_is_detected(): void
    {
        $hr = User::factory()->hrStaff()->create();
        $this->actingAs($hr);
        Employee::factory()->create();
        $entry = AuditLog::latest('id')->first();

        // What somebody with database access but not the app key could do.
        DB::table('audit_logs')->where('id', $entry->id)->update(['user_id' => null]);

        $this->assertContains($entry->id, app(AuditLogSigner::class)->verify()['altered']);

        $this->artisan('audit:verify')->assertFailed();
    }

    public function test_hr_can_run_the_integrity_check_from_settings(): void
    {
        $hr = User::factory()->hrStaff()->create();

        $this->actingAs($hr)->post(route('settings.security.audit.verify'))
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'No entry has been altered'));

        $employeeUser = User::factory()->create();
        $this->actingAs($employeeUser)->post(route('settings.security.audit.verify'))->assertForbidden();
    }

    // --- Access review -------------------------------------------------------

    public function test_the_accounts_list_shows_last_sign_in_and_unused_accounts(): void
    {
        $admin = User::factory()->admin()->create();
        $stale = User::factory()->create(['created_at' => now()->subYear()]);

        $this->actingAs($admin)->get(route('settings.users'))
            ->assertInertia(fn ($page) => $page
                ->where('accessReview.due', true)
                ->where('users', fn ($users) => collect($users)->firstWhere('id', $stale->id)['is_stale'] === true));
    }

    public function test_recording_an_access_review_is_logged(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('settings.users.review'))->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', ['event' => 'access_reviewed', 'user_id' => $admin->id]);

        $this->actingAs($admin)->get(route('settings.users'))
            ->assertInertia(fn ($page) => $page->where('accessReview.due', false));
    }

    private function makeRun(array $attributes = []): PayrollRun
    {
        static $sequence = 0;
        $sequence++;
        $start = now()->startOfMonth()->subMonths($sequence);

        $period = PayrollPeriod::create([
            'name' => "Period {$sequence}",
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(14)->toDateString(),
            'pay_date' => $start->copy()->addDays(19)->toDateString(),
            'frequency' => 'semi_monthly',
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);

        return PayrollRun::create(array_merge([
            'payroll_period_id' => $period->id,
            'run_number' => sprintf('PR-SEC-%04d', $sequence),
            'status' => PayrollRun::STATUS_DRAFT,
            'employee_count' => 0,
            'total_gross' => 0,
            'total_deductions' => 0,
            'total_net' => 0,
        ], $attributes));
    }

    private function payslip(PayrollRun $run, array $attributes = []): Payslip
    {
        static $sequence = 0;
        $sequence++;

        return Payslip::create(array_merge([
            'payroll_run_id' => $run->id,
            'employee_id' => Employee::factory()->create(['date_hired' => now()->subYears(2)])->id,
            'payslip_number' => sprintf('PS-SEC-%05d', $sequence),
            'basic_pay' => 20000,
            'gross_pay' => 20000,
            'deductions_total' => 3000,
            'net_pay' => 17000,
        ], $attributes));
    }
}
