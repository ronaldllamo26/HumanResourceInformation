<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Signing out after ten minutes of nobody being there.
 *
 * The countdown on the screen is a courtesy; `config('session.lifetime')` is
 * the enforcement, and these cover the half that can be tested server-side —
 * the route that ends a session with a message, the ping that keeps one alive,
 * and the number both are read from.
 */
class IdleTimeoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_idle_window_is_five_minutes(): void
    {
        /*
         * Laravel refreshes `last_activity` on every request, so `lifetime` is
         * a true idle window rather than a fixed expiry. Asserted because this
         * one number is the entire enforcement — the browser only counts down
         * to it.
         */
        $this->assertSame(5, (int) config('session.lifetime'));
    }

    public function test_signing_out_lands_on_the_login_screen_saying_why(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout/idle')
            ->assertRedirect(route('login'))
            // An unexplained login screen reads as a crash, which is the
            // reaction that gets a timeout switched off.
            ->assertSessionHas('status', 'You were signed out after 5 minutes of inactivity.');

        $this->assertGuest();
    }

    public function test_the_keepalive_costs_nothing_and_says_nothing(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/session/keepalive')
            ->assertNoContent();
    }

    public function test_neither_route_is_open_to_a_guest(): void
    {
        $this->post('/logout/idle')->assertRedirect(route('login'));
        $this->get('/session/keepalive')->assertRedirect(route('login'));
    }

    /**
     * A Sanctum token is an unattended credential on a biometric device with
     * nobody at the other end to sign in again. `session.lifetime` only reaches
     * session auth, which is the same line RequirePasswordChange draws — this
     * asserts the line rather than trusting it.
     */
    public function test_api_tokens_are_not_subject_to_the_idle_timeout(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $this->travel(30)->minutes();

        $this->getJson('/api/v1/employees')->assertOk();
    }

    public function test_the_screen_is_told_the_same_number_the_server_enforces(): void
    {
        // Two copies would drift the first time one was tuned, and the failure
        // would be silent: a screen counting down from ten against a session
        // that died at five.
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('idle.timeout', (int) config('session.lifetime') * 60)
                ->where('idle.warnAfter', (int) config('session.idle_warning')),
            );
    }
}
