<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\LoginOtp;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The second factor: a code emailed to the account holder's own inbox.
 *
 * The login stays the company username; the code goes to `users.otp_email`,
 * connected by an administrator on Users & Access. Having an address *is* the
 * enrolment, so most of these tests are about what happens to accounts that
 * have one and what deliberately does not happen to accounts that do not.
 */
class LoginOtpTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_a_password_alone_does_not_finish_a_sign_in(): void
    {
        $user = $this->enrolled();

        $this->post('/login', ['username' => $user->username, 'password' => self::PASSWORD])
            ->assertRedirect();

        // Authenticated, and held: every screen answers with the code screen.
        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertRedirect(route('otp.challenge'));

        Notification::assertSentTo($user, LoginOtp::class);
    }

    /** The code is mailed to the personal inbox, never to the company username. */
    public function test_the_code_goes_to_the_connected_inbox(): void
    {
        $user = $this->enrolled('nina.personal@gmail.com');

        $this->signIn($user);
        $this->get('/dashboard');

        Notification::assertSentTo(
            $user,
            LoginOtp::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routeNotificationForMail() === 'nina.personal@gmail.com',
        );
    }

    public function test_the_right_code_finishes_the_sign_in_and_marks_the_address_proved(): void
    {
        $user = $this->enrolled();
        $code = $this->challenge($user);

        $this->post('/otp', ['code' => $code])->assertRedirect(route('dashboard'));

        $this->get('/dashboard')->assertOk();
        $this->assertNotNull($user->fresh()->otp_email_verified_at);
        // The code is consumed, so a second tab cannot replay it.
        $this->assertNull($user->fresh()->otp_code_hash);
    }

    /** Spaces are what people paste out of an email; they are not a wrong answer. */
    public function test_a_pasted_code_with_spaces_is_accepted(): void
    {
        $user = $this->enrolled();
        $code = $this->challenge($user);

        $this->post('/otp', ['code' => substr($code, 0, 3).' '.substr($code, 3)])
            ->assertSessionHasNoErrors();

        $this->get('/dashboard')->assertOk();
    }

    public function test_a_wrong_code_costs_an_attempt_and_five_burn_it(): void
    {
        $user = $this->enrolled();
        $this->challenge($user);

        $this->post('/otp', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertSame(1, $user->fresh()->otp_attempts);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->post('/otp', ['code' => '000000']);
        }

        // Burned rather than the account locked: locking would hand anybody
        // who knows a username a way to keep its owner out.
        $this->assertNull($user->fresh()->otp_code_hash);
        $this->get('/dashboard')->assertRedirect(route('otp.challenge'));
    }

    public function test_an_expired_code_is_refused(): void
    {
        $user = $this->enrolled();
        $code = $this->challenge($user);

        $this->travel((int) config('otp.ttl_seconds') + 5)->seconds();

        $this->post('/otp', ['code' => $code])->assertSessionHasErrors('code');
        $this->get('/dashboard')->assertRedirect(route('otp.challenge'));
    }

    /** An account with no personal inbox signs in with a password alone, by design. */
    public function test_an_account_with_no_address_is_never_held(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD, 'must_change_password' => false]);

        $this->signIn($user);
        $this->get('/dashboard')->assertOk();

        Notification::assertNothingSent();
    }

    /**
     * The master switch is the way back in when mail breaks — with no
     * terminal on the host and no emailed password reset, it is the only
     * recovery that does not need a shell.
     */
    public function test_the_switch_drops_the_factor_for_everybody(): void
    {
        config(['otp.enabled' => false]);
        $user = $this->enrolled();

        $this->signIn($user);
        $this->get('/dashboard')->assertOk();

        Notification::assertNothingSent();
    }

    /** The hold is on the session, so a second machine is asked again. */
    public function test_a_new_session_is_challenged_again(): void
    {
        $user = $this->enrolled();
        $this->post('/otp', ['code' => $this->challenge($user)]);
        $this->get('/dashboard')->assertOk();

        $this->post('/logout');
        $this->signIn($user);

        $this->get('/dashboard')->assertRedirect(route('otp.challenge'));
    }

    /** Held, but not trapped: the screen, a new code, and the way out. */
    public function test_a_held_session_may_still_sign_out(): void
    {
        $user = $this->enrolled();
        $this->signIn($user);

        $this->get('/otp')->assertOk();
        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
    }

    public function test_a_resend_is_paced(): void
    {
        $user = $this->enrolled();
        $this->challenge($user);

        $this->post('/otp/resend')->assertSessionHasErrors('code');

        $this->travel((int) config('otp.resend_after_seconds') + 1)->seconds();

        $this->post('/otp/resend')->assertSessionHas('success');
    }

    /** Sent, passed and failed are all in the audit trail — and the code never is. */
    public function test_the_trail_records_the_factor_without_the_code(): void
    {
        $user = $this->enrolled('nina.personal@gmail.com');
        $code = $this->challenge($user);
        $this->post('/otp', ['code' => '000000']);
        $this->post('/otp', ['code' => $code]);

        $events = AuditLog::whereIn('event', OtpService::EVENTS)->pluck('event');

        $this->assertTrue($events->contains(OtpService::EVENT_SENT));
        $this->assertTrue($events->contains(OtpService::EVENT_FAILED));
        $this->assertTrue($events->contains(OtpService::EVENT_PASSED));

        $row = AuditLog::where('event', OtpService::EVENT_SENT)->firstOrFail();
        $this->assertSame('ni'.str_repeat('*', 11).'@gmail.com', $row->new_values['sent_to']);
        $this->assertStringNotContainsString($code, json_encode($row->new_values));
    }

    // --- Connecting the address, on Users & Access ---------------------------

    public function test_an_admin_connects_a_personal_inbox(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->put("/settings/users/{$user->id}/profile", [
                'name' => $user->name,
                'username' => $user->username,
                'otp_email' => 'Nina.Personal@Gmail.com',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'Sign-in codes will go to'));

        // Lowercased on the way in, so two spellings are not two addresses.
        $this->assertSame('nina.personal@gmail.com', $user->fresh()->otp_email);
    }

    /** Changing the address unproves it and kills any code aimed at the old one. */
    public function test_a_changed_address_starts_unproved(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->enrolled();
        $this->challenge($user);
        $user->forceFill(['otp_email_verified_at' => now()])->save();

        $this->actingAs($admin)->put("/settings/users/{$user->id}/profile", [
            'name' => $user->name,
            'username' => $user->username,
            'otp_email' => 'someone.else@gmail.com',
        ]);

        $fresh = $user->fresh();
        $this->assertNull($fresh->otp_email_verified_at);
        $this->assertNull($fresh->otp_code_hash);
    }

    /** Clearing the address is how the factor is switched off for one account. */
    public function test_clearing_the_address_switches_the_factor_off(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->enrolled();

        $this->actingAs($admin)
            ->put("/settings/users/{$user->id}/profile", [
                'name' => $user->name,
                'username' => $user->username,
                'otp_email' => '',
            ])
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'password alone'));

        $this->assertNull($user->fresh()->otp_email);
    }

    /**
     * The test code is the point of the button: a typo in the address or a
     * broken mailer is found here rather than at somebody's next sign-in.
     */
    public function test_the_test_code_sends_a_real_one(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->enrolled();

        $this->actingAs($admin)
            ->post("/settings/users/{$user->id}/otp-test")
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'code was sent'));

        Notification::assertSentTo($user, LoginOtp::class);
    }

    public function test_the_test_code_says_so_when_there_is_nowhere_to_send_it(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->post("/settings/users/{$user->id}/otp-test")
            ->assertSessionHas('error');

        Notification::assertNothingSent();
    }

    public function test_only_an_admin_connects_an_address_or_sends_a_code(): void
    {
        $user = $this->enrolled();

        foreach ([User::factory()->hrStaff()->create(), User::factory()->supervisor()->create()] as $actor) {
            $this->actingAs($actor)->post("/settings/users/{$user->id}/otp-test")->assertForbidden();
        }
    }

    public function test_a_user_can_connect_their_own_otp_email_in_personal_security(): void
    {
        $user = User::factory()->create(['otp_email' => null]);

        $this->actingAs($user)
            ->put('/settings/security/otp', [
                'otp_email' => 'my.personal@gmail.com',
                'password' => 'password',
            ])
            ->assertRedirect();

        $this->assertSame('my.personal@gmail.com', $user->fresh()->otp_email);
        $this->assertNull($user->fresh()->otp_email_verified_at);
    }

    public function test_disabling_otp_is_prohibited_in_settings_as_mfa_is_mandatory(): void
    {
        $user = $this->enrolled('my.personal@gmail.com');

        $this->actingAs($user)
            ->withSession([OtpService::SESSION_KEY => now()->toIso8601String()])
            ->put('/settings/security/otp', [
                'otp_enabled' => false,
                'password' => self::PASSWORD,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('otp_enabled');

        $this->assertTrue((bool) ($user->fresh()->otp_enabled ?? true));
        $this->assertTrue((new OtpService)->isRequiredFor($user->fresh()));
    }

    public function test_disabling_otp_fails_with_wrong_password(): void
    {
        $user = $this->enrolled('my.personal@gmail.com');

        $this->actingAs($user)
            ->withSession([OtpService::SESSION_KEY => now()->toIso8601String()])
            ->put('/settings/security/otp', [
                'otp_enabled' => false,
                'password' => 'wrong-password-here',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue((bool) ($user->fresh()->otp_enabled ?? true));
    }

    public function test_enabling_otp_requires_password_confirmation_and_enables_2fa(): void
    {
        $user = User::factory()->create([
            'password' => self::PASSWORD,
            'otp_email' => 'my.personal@gmail.com',
            'otp_enabled' => false,
        ]);

        $this->assertFalse((new OtpService)->isRequiredFor($user));

        $this->actingAs($user)
            ->put('/settings/security/otp', [
                'otp_enabled' => true,
                'password' => self::PASSWORD,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue($user->fresh()->otp_enabled);
        $this->assertTrue((new OtpService)->isRequiredFor($user->fresh()));
    }

    public function test_disabled_otp_user_does_not_require_otp_on_login(): void
    {
        $user = User::factory()->create([
            'password' => self::PASSWORD,
            'must_change_password' => false,
            'otp_email' => 'my.personal@gmail.com',
            'otp_enabled' => false,
        ]);

        $this->post('/login', ['username' => $user->username, 'password' => self::PASSWORD])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
        // Does NOT redirect to otp challenge because 2FA is disabled
        $this->get('/dashboard')->assertOk();
        Notification::assertNothingSent();
    }

    public function test_put_request_to_login_gracefully_redirects_with_303(): void
    {
        $this->put('/login')->assertRedirect(route('login'))->assertStatus(303);
    }

    public function test_a_user_can_send_a_test_otp_code_from_personal_security(): void
    {
        $user = $this->enrolled('my.personal@gmail.com');

        $this->actingAs($user)
            ->withSession([OtpService::SESSION_KEY => now()->toIso8601String()])
            ->post('/settings/security/otp/test')
            ->assertSessionHas('success');

        Notification::assertSentTo($user, LoginOtp::class);
    }

    public function test_otp_bind_artisan_command_binds_account(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser@primepower.com',
            'otp_email' => null,
        ]);

        $this->artisan('otp:bind', [
            'username' => 'testuser@primepower.com',
            'email' => 'bound.gmail@gmail.com',
        ])->assertSuccessful();

        $this->assertSame('bound.gmail@gmail.com', $user->fresh()->otp_email);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    // --- Helpers -------------------------------------------------------------

    private function enrolled(string $address = 'owner.personal@gmail.com'): User
    {
        return User::factory()->create([
            'password' => self::PASSWORD,
            'must_change_password' => false,
            'otp_email' => $address,
        ]);
    }

    private function signIn(User $user): void
    {
        $this->post('/login', ['username' => $user->username, 'password' => self::PASSWORD]);
    }

    /**
     * Signs in, triggers the send, and returns the code that was mailed.
     *
     * The code exists only in the notification, by design — the column holds a
     * hash — so the test reads it back the same way the person does.
     */
    private function challenge(User $user): string
    {
        $this->signIn($user);
        $this->get('/dashboard');

        $code = null;

        Notification::assertSentTo($user, LoginOtp::class, function ($notification) use (&$code) {
            $lines = collect($notification->toMail($notification)->introLines ?? []);
            $code = $lines->map(fn (string $line) => preg_replace('/\D/', '', $line))
                ->first(fn (string $digits) => strlen($digits) === (int) config('otp.length'));

            return true;
        });

        $this->assertNotNull($code, 'No code was found in the email.');

        return $code;
    }
}
