<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who may rename themselves on Settings > Security.
 *
 * Everyone but an administrator is held to the name on their employee record.
 * `users.name` and the 201 file are meant to name the same person, and nothing
 * in this system reconciles the two — so a drift is silent and permanent. That
 * is the reason it is prevented here rather than detected somewhere later.
 *
 * Email and password are deliberately untouched by this: they are credentials
 * rather than a display name, and the point of the Security screen is that
 * every signed-in user can manage their own.
 */
class SelfRenameTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_may_still_rename_themselves(): void
    {
        // An admin need not be an employee at all — a pure system account has
        // no 201 file to be held to, and locking it would leave a wrong name
        // with nowhere to be fixed.
        $admin = User::factory()->admin()->create(['name' => 'Old Name']);

        $this->actingAs($admin)
            ->put('/settings/security/profile', [
                'name' => 'New Name',
                'email' => $admin->email,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('New Name', $admin->fresh()->name);
    }

    /**
     * The rule has to bite on the server. Hiding the input and validating it
     * anyway would mean anyone who can post a form can still rename themselves,
     * which is the whole thing being prevented.
     */
    public function test_a_posted_name_is_ignored_for_everybody_else(): void
    {
        foreach ([User::ROLE_HR_STAFF, User::ROLE_SUPERVISOR, User::ROLE_EMPLOYEE] as $role) {
            $user = User::factory()->create(['role' => $role, 'name' => 'Real Name']);

            $this->actingAs($user)
                ->put('/settings/security/profile', [
                    'name' => 'Renamed Myself',
                    'email' => $user->email,
                ])
                ->assertSessionHasNoErrors();

            $this->assertSame(
                'Real Name',
                $user->fresh()->name,
                "a {$role} was able to rename themselves",
            );
        }
    }

    /**
     * Dropped rather than refused: nothing wrong is stored either way, and
     * refusing would fail an email change over a field the person cannot see.
     */
    public function test_they_can_still_change_their_email(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_HR_STAFF,
            'name' => 'Real Name',
            'email' => 'before@primepower.test',
        ]);

        $this->actingAs($user)
            ->put('/settings/security/profile', ['email' => 'after@primepower.test'])
            ->assertSessionHasNoErrors();

        $fresh = $user->fresh();

        $this->assertSame('after@primepower.test', $fresh->email);
        $this->assertSame('Real Name', $fresh->name);

        // A changed address is still unproven until it is verified again.
        $this->assertNull($fresh->email_verified_at);
    }

    public function test_they_can_still_change_their_password(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'password' => 'Current-Password1!',
        ]);

        $this->actingAs($user)
            ->put('/settings/security/password', [
                'current_password' => 'Current-Password1!',
                'password' => 'A-new-password-1!',
                'password_confirmation' => 'A-new-password-1!',
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_the_screen_says_whether_the_name_is_theirs_to_change(): void
    {
        // The field is drawn from the same ability the update enforces, so one
        // can never be offered where the other would drop the value.
        $this->actingAs(User::factory()->admin()->create())
            ->get('/settings/security')
            ->assertInertia(fn ($page) => $page->where('canRename', true));

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/settings/security')
            ->assertInertia(fn ($page) => $page->where('canRename', false));
    }
}
