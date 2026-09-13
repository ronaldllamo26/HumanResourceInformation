<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\User;
use App\Services\DeploymentReadinessChecker;
use App\Services\EmployeeService;
use App\Services\LicenseVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What can honestly be said about a driver's licence.
 *
 * The claim is deliberately split. **Structure** is checked here and
 * automatically — the number's shape, the DL codes, the conditions, and the
 * expiry falling on the holder's birthday. **Authenticity** is not, and cannot
 * be: LTO publishes no API an employer can call, so a person checks the LTMS
 * portal and their answer is recorded with their name and the date.
 *
 * These cover both halves, including the part that matters most — that the
 * automatic half never claims to be the other one.
 */
class LicenseVerificationTest extends TestCase
{
    use RefreshDatabase;

    /*
     * -----------------------------------------------------------------
     * The shape of the card
     * -----------------------------------------------------------------
     */

    public function test_a_real_licence_number_passes(): void
    {
        // The shape a current card prints: agency code, two-digit year,
        // six-digit serial.
        $employee = $this->driver(['drivers_license_number' => 'N02-24-001292']);

        $this->assertSame([], $this->verifier()->check($employee));
    }

    public function test_the_number_is_accepted_with_or_without_its_dashes(): void
    {
        $employee = $this->driver(['drivers_license_number' => 'N0224001292']);

        $this->assertSame([], $this->verifier()->check($employee));
    }

    public function test_a_number_that_is_not_an_lto_shape_is_an_error(): void
    {
        $employee = $this->driver(['drivers_license_number' => 'ABC-12345678']);

        $findings = $this->verifier()->check($employee);

        $this->assertCount(1, $findings);
        $this->assertSame(LicenseVerifier::SEVERITY_ERROR, $findings[0]['severity']);
        $this->assertSame('drivers_license_number', $findings[0]['field']);
    }

    public function test_a_record_with_no_licence_says_nothing(): void
    {
        // Whether a driver is *missing* a licence is OnboardingChecker's
        // question. Saying it twice on two screens teaches people to ignore
        // both.
        $employee = Employee::factory()->create(['drivers_license_number' => null]);

        $this->assertSame([], $this->verifier()->check($employee));
    }

    /*
     * -----------------------------------------------------------------
     * DL codes and conditions
     * -----------------------------------------------------------------
     */

    public function test_real_dl_codes_pass(): void
    {
        $employee = $this->driver(['license_dl_codes' => 'B,C,CE']);

        $this->assertSame([], $this->verifier()->check($employee));
    }

    /**
     * The retired numeric restriction scheme (1–8) is not what a current card
     * prints, and every record still carrying it is filed under a scheme LTO
     * no longer issues.
     */
    public function test_a_retired_numeric_restriction_code_is_reported(): void
    {
        $employee = $this->driver(['license_dl_codes' => '1,2']);

        $findings = collect($this->verifier()->check($employee))
            ->where('field', 'license_dl_codes');

        $this->assertCount(2, $findings);
        $this->assertSame(LicenseVerifier::SEVERITY_ERROR, $findings->first()['severity']);
    }

    public function test_a_condition_outside_the_cards_own_list_is_reported(): void
    {
        $employee = $this->driver(['license_conditions' => '9']);

        $findings = collect($this->verifier()->check($employee))
            ->where('field', 'license_conditions');

        $this->assertCount(1, $findings);
    }

    /*
     * -----------------------------------------------------------------
     * The birthday rule
     * -----------------------------------------------------------------
     */

    public function test_an_expiry_off_the_holders_birthday_is_a_warning(): void
    {
        $employee = $this->driver([
            'birth_date' => '2002-11-29',
            'license_expiry' => '2028-06-15',
        ]);

        $findings = collect($this->verifier()->check($employee))
            ->where('field', 'license_expiry');

        $this->assertCount(1, $findings);

        /*
         * A warning, never an error. Renewals around a birthday and the
         * occasional extension granted by memorandum are real enough that
         * refusing the entry would reject correct records to catch wrong ones.
         */
        $this->assertSame(LicenseVerifier::SEVERITY_WARNING, $findings->first()['severity']);
    }

    public function test_an_expiry_on_the_birthday_passes(): void
    {
        $employee = $this->driver([
            'birth_date' => '2002-11-29',
            'license_expiry' => '2028-11-29',
        ]);

        $this->assertSame([], $this->verifier()->check($employee));
    }

    /*
     * -----------------------------------------------------------------
     * The recorded LTMS check
     * -----------------------------------------------------------------
     */

    public function test_a_licence_nobody_has_looked_at_is_unverified(): void
    {
        $employee = $this->driver();

        // Passing the structural checks is *not* verification, and the two
        // must never be conflated: a well-made forgery passes every one of
        // them.
        $this->assertSame([], $this->verifier()->check($employee));
        $this->assertSame('unverified', $this->verifier()->verificationState($employee)['state']);
    }

    public function test_hr_can_record_what_the_portal_showed(): void
    {
        $employee = $this->driver();
        $hr = User::factory()->hrStaff()->create();

        $this->actingAs($hr)
            ->post("/hr/employees/{$employee->id}/verify-license", [
                'license_verification_note' => 'Active on LTMS, no apprehensions.',
            ])
            ->assertRedirect();

        $employee->refresh();

        $this->assertSame('Active on LTMS, no apprehensions.', $employee->license_verification_note);
        $this->assertSame($hr->id, $employee->license_verified_by);
        $this->assertNotNull($employee->license_verified_at);
        $this->assertSame('verified', $this->verifier()->verificationState($employee)['state']);
    }

    public function test_the_note_is_required_because_a_tick_carries_nothing(): void
    {
        $employee = $this->driver();

        // "Active", "suspended until March", and "no record found" are three
        // different answers and only one is good news. A boolean loses two.
        $this->actingAs(User::factory()->hrStaff()->create())
            ->post("/hr/employees/{$employee->id}/verify-license", [
                'license_verification_note' => '',
            ])
            ->assertSessionHasErrors('license_verification_note');
    }

    public function test_a_check_older_than_the_window_goes_stale(): void
    {
        $employee = $this->driver(['license_verified_at' => now()->subDays(400)]);

        // Stale, not expired: a licence can be suspended the day after
        // somebody looked at it, so the date says nobody has checked in a
        // year — not that the licence is now invalid.
        $this->assertSame('stale', $this->verifier()->verificationState($employee)['state']);
    }

    public function test_an_employee_cannot_vouch_for_their_own_licence(): void
    {
        $user = User::factory()->create();
        $employee = $this->driver(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post("/hr/employees/{$employee->id}/verify-license", [
                'license_verification_note' => 'Looks fine to me.',
            ])
            ->assertForbidden();

        $this->assertNull($employee->fresh()->license_verified_at);
    }

    /*
     * -----------------------------------------------------------------
     * What it reaches
     * -----------------------------------------------------------------
     */

    public function test_a_daylight_only_driver_is_flagged_for_deployment(): void
    {
        $employee = $this->driver(['license_conditions' => '4']);

        $reasons = app(DeploymentReadinessChecker::class)
            ->scan(app(EmployeeService::class)->scopedQuery(User::factory()->admin()->create()))
            ->firstWhere('employee_id', $employee->id)['reasons'];

        $condition = collect($reasons)
            ->first(fn (array $reason) => str_contains(strtolower($reason['detail']), 'daylight'));

        // Neither a missing document nor a lapsed one, so without this the
        // screen would call them ready on that count and the dispatcher would
        // find out at the depot.
        $this->assertNotNull($condition, 'the daylight-only condition was not reported');

        /*
         * A warning, not a block — asserted on this reason alone rather than
         * on the employee's overall status, which a bare factory record fails
         * for its own reasons (no contract on file, no clearance). A licence
         * condition rules out some runs, not the roster.
         */
        $this->assertFalse($condition['blocking']);
    }

    public function test_the_employee_screen_separates_structure_from_authenticity(): void
    {
        $employee = $this->driver();

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get("/hr/employees/{$employee->id}")
            ->assertInertia(fn ($page) => $page
                ->has('licence.checks')
                ->where('licence.verification.state', 'unverified')
                ->has('licence.dl_codes')
                ->has('licence.ltms_url'),
            );
    }

    /*
     * -----------------------------------------------------------------
     * Helpers
     * -----------------------------------------------------------------
     */

    private function verifier(): LicenseVerifier
    {
        return app(LicenseVerifier::class);
    }

    /** A driver whose licence is internally consistent unless a test says otherwise. */
    private function driver(array $overrides = []): Employee
    {
        return Employee::factory()->create(array_merge([
            'birth_date' => '1990-05-14',
            'drivers_license_number' => 'N02-24-001292',
            'license_dl_codes' => 'B,C',
            'license_conditions' => null,
            'license_expiry' => '2029-05-14',
        ], $overrides));
    }
}
