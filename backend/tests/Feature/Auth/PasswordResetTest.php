<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'Reset-Passw0rd-26',
                'password_confirmation' => 'Reset-Passw0rd-26',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    /**
     * A reset lifts the forced-change hold, and it did not used to.
     *
     * `must_change_password` marks a login still on the password somebody else
     * chose, and `RequirePasswordChange` pins the account to
     * `/settings/security` until that stops being true. Only the Settings form
     * cleared it — so somebody who took the other route to the same act, the
     * emailed reset link, chose a password nobody else had ever seen and was
     * still held afterwards, told to replace a password they had just replaced.
     * There is no way out of that loop from inside the reset flow.
     */
    public function test_resetting_the_password_lifts_the_forced_change_hold(): void
    {
        Notification::fake();

        $user = User::factory()->create(['must_change_password' => true]);
        $token = $user->createToken('device')->plainTextToken;

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'Reset-Passw0rd-26',
                'password_confirmation' => 'Reset-Passw0rd-26',
            ])->assertSessionHasNoErrors();

            return true;
        });

        $this->assertFalse($user->fresh()->must_change_password);

        // And the other half of the rotation: a token issued while the shared
        // password was live was issued to whoever held it.
        $this->assertSame(0, $user->fresh()->tokens()->count(), 'API tokens survived the reset.');
        $this->assertNotNull($token);
    }
}
