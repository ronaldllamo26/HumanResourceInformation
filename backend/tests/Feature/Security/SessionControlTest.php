<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\SessionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Who is signed in, and signing them out.
 *
 * The five-minute idle window closes an abandoned desk. None of the cases
 * this screen exists for — a password known to have leaked, a laptop out of
 * the building, an engagement that ended an hour ago — can wait five minutes,
 * which is the whole argument for the feature.
 */
class SessionControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_super_administrator_sees_who_is_signed_in(): void
    {
        $admin = $this->superAdmin();
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Nina Reyes']);
        $this->openSessionFor($employee, 'session-one');

        $this->actingAs($admin)
            ->get('/settings/sessions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Sessions')
                ->where('sessions.0.name', 'Nina Reyes')
                ->where('summary.accounts', 1));
    }

    /**
     * The list is every signed-in person's address and device — a map of the
     * workforce's whereabouts nothing else in this system hands over — so it
     * is narrower than the rest of Administration.
     */
    public function test_an_administrator_may_not_see_the_session_list(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/settings/sessions')->assertForbidden();
    }

    public function test_signing_one_account_out_ends_all_of_its_sessions(): void
    {
        $admin = $this->superAdmin();
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        // Two devices, one person.
        $this->openSessionFor($employee, 'phone');
        $this->openSessionFor($employee, 'desktop');

        $this->actingAs($admin)
            ->delete("/settings/sessions/user/{$employee->id}")
            ->assertSessionHas('success');

        $this->assertSame(0, DB::table('sessions')->where('user_id', $employee->id)->count());
    }

    /**
     * Unattended credentials on biometric devices have nobody at the other end
     * to sign in again, so ending a browser session must not take the timeclock
     * down as a side effect. Revoking a token is its own decision.
     */
    public function test_ending_sessions_leaves_api_tokens_alone(): void
    {
        $admin = $this->superAdmin();
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $employee->createToken('biometric-device');
        $this->openSessionFor($employee, 'desktop');

        $this->actingAs($admin)->delete("/settings/sessions/user/{$employee->id}");

        $this->assertSame(1, $employee->fresh()->tokens()->count());
    }

    /**
     * An administrator thrown out by their own first action has to stop and
     * sign back in — through a second factor, on a system they were in the
     * middle of securing.
     */
    public function test_signing_everyone_out_keeps_the_actors_own_session(): void
    {
        $admin = $this->superAdmin();
        $one = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $two = User::factory()->create(['role' => User::ROLE_HR_STAFF]);
        $this->openSessionFor($one, 'one');
        $this->openSessionFor($two, 'two');

        $response = $this->actingAs($admin)->delete('/settings/sessions/all');
        $response->assertSessionHas('success');

        $this->assertSame(0, DB::table('sessions')->whereIn('id', ['one', 'two'])->count());
        $this->assertAuthenticatedAs($admin);
    }

    /**
     * A session that is not there is reported as already ended, not as a fault.
     *
     * The neighbouring guard — refusing to end *your own* session from this
     * list — is **deliberately not covered here, and the reason is worth
     * writing down rather than leaving as a gap somebody assumes was
     * forgotten.** The suite runs on `SESSION_DRIVER=array` (phpunit.xml),
     * where the id is not persisted and each request is handed a fresh one:
     * measured at `zEOck…` on the test side against `87zWi…` inside the very
     * next request. So the id a test can read is never the id the request
     * carries, and the comparison the guard makes cannot be reached from out
     * here however the assertion is written. Forcing the database driver does
     * not help — the ids still differ per request. It holds in a browser,
     * where the cookie carries one id throughout, and it is verified there.
     */
    public function test_ending_a_session_that_has_already_gone_is_not_an_error(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->delete('/settings/sessions/one', ['session' => 'a-session-that-ended'])
            ->assertSessionHas('success');
    }

    public function test_one_session_can_be_ended_without_touching_the_others(): void
    {
        $admin = $this->superAdmin();
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->openSessionFor($employee, 'phone');
        $this->openSessionFor($employee, 'desktop');

        $this->actingAs($admin)->delete('/settings/sessions/one', ['session' => 'phone']);

        $this->assertNull(DB::table('sessions')->where('id', 'phone')->first());
        $this->assertNotNull(DB::table('sessions')->where('id', 'desktop')->first());
    }

    /**
     * Recorded even at zero, because "somebody pressed sign out everywhere
     * and nothing was open" is a fact about the response to an incident, and a
     * trail that keeps only the successful half cannot reconstruct one.
     */
    public function test_every_termination_is_audited_with_its_scope_and_count(): void
    {
        $admin = $this->superAdmin();
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->openSessionFor($employee, 'desktop');

        $this->actingAs($admin)->delete("/settings/sessions/user/{$employee->id}");

        $row = AuditLog::where('event', SessionRegistry::EVENT_TERMINATED)->sole();

        $this->assertSame($admin->id, $row->user_id);
        $this->assertSame($employee->id, (int) $row->auditable_id);
        $this->assertSame('user', $row->new_values['scope']);
        $this->assertSame(1, $row->new_values['sessions_ended']);
    }

    public function test_an_employee_cannot_end_anybodys_session(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $other = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->openSessionFor($other, 'desktop');

        $this->actingAs($employee)
            ->delete("/settings/sessions/user/{$other->id}")
            ->assertForbidden();

        $this->assertNotNull(DB::table('sessions')->where('id', 'desktop')->first());
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    }

    /** A row in `sessions` is what "signed in" means with the database driver. */
    private function openSessionFor(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->timestamp,
        ]);
    }
}
