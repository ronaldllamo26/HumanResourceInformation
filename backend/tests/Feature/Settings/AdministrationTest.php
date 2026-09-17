<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Administration group: the audit log on its own screen, and editing an
 * account's profile from Users & Access.
 */
class AdministrationTest extends TestCase
{
    use RefreshDatabase;

    // --- Audit Logs ---------------------------------------------------------

    public function test_the_audit_log_opens_with_filters_and_totals(): void
    {
        $admin = User::factory()->admin()->create();
        Employee::factory()->create(); // a `created` row

        $this->actingAs($admin)
            ->get('/settings/audit-logs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/AuditLogs')
                ->where('filters.group', 'changes')
                ->has('entries.data')
                ->has('options.events')
                ->has('summary.total'));
    }

    /** HR reads the log; `viewAuditLog` is `isHrAdmin()`, not admin alone. */
    public function test_hr_staff_may_read_it_and_an_employee_may_not(): void
    {
        $this->actingAs(User::factory()->hrStaff()->create())->get('/settings/audit-logs')->assertOk();
        $this->actingAs(User::factory()->supervisor()->create())->get('/settings/audit-logs')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/settings/audit-logs')->assertForbidden();
    }

    public function test_the_range_narrows_what_is_listed(): void
    {
        $admin = User::factory()->admin()->create();
        $old = AuditLog::create([
            'user_id' => $admin->id,
            'auditable_type' => Employee::class,
            'auditable_id' => 1,
            'event' => 'updated',
            'new_values' => ['first_name' => 'x'],
        ]);
        $old->forceFill(['created_at' => now()->subYear()])->saveQuietly();

        $this->actingAs($admin)
            ->get('/settings/audit-logs?group=all')
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data', fn ($rows) => collect($rows)->doesntContain('id', $old->id)));

        $this->actingAs($admin)
            ->get('/settings/audit-logs?group=all&from='.now()->subYears(2)->toDateString())
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data', fn ($rows) => collect($rows)->contains('id', $old->id)));
    }

    /** Reads and exports are their own group: they answer a different question from an edit. */
    public function test_reads_can_be_listed_apart_from_changes(): void
    {
        $admin = User::factory()->admin()->create();
        AuditLog::create([
            'user_id' => $admin->id,
            'auditable_type' => Employee::class,
            'auditable_id' => null,
            'event' => 'exported',
            'new_values' => ['report' => 'employees', 'format' => 'csv'],
        ]);

        $this->actingAs($admin)
            ->get('/settings/audit-logs?group=reads')
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data', fn ($rows) => count($rows) === 1
                    && $rows[0]['event'] === 'exported'
                    && $rows[0]['is_read'] === true));
    }

    /** The export of an audit trail is itself an access, and is recorded as one. */
    public function test_exporting_the_log_writes_its_own_row(): void
    {
        $admin = User::factory()->admin()->create();

        $csv = $this->actingAs($admin)
            ->get('/settings/audit-logs/export?group=all')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Audit log', $csv);

        $row = AuditLog::where('event', 'exported')->latest('id')->firstOrFail();
        $this->assertSame('audit_log', $row->new_values['report'] ?? null);
        $this->assertSame($admin->id, $row->user_id);
    }

    public function test_the_signature_check_lives_with_the_log(): void
    {
        $this->actingAs(User::factory()->hrStaff()->create())
            ->post(route('settings.audit-logs.verify'))
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'No entry has been altered'));
    }

    // --- Users & Access: profile --------------------------------------------

    public function test_an_admin_edits_an_accounts_name_and_username(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['name' => 'Nina Reyes', 'username' => 'nreyes@primepower.com']);

        $this->actingAs($admin)
            ->put("/settings/users/{$user->id}/profile", ['name' => 'Nina Reyes-Cruz', 'username' => 'nreyescruz'])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('Nina Reyes-Cruz', $user->name);
        // Typed without the domain, stored with it.
        $this->assertSame('nreyescruz@primepower.com', $user->username);
    }

    public function test_the_new_username_signs_in(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['password' => 'correct-horse-battery', 'must_change_password' => false]);

        $this->actingAs($admin)
            ->put("/settings/users/{$user->id}/profile", ['name' => $user->name, 'username' => 'renamed'])
            ->assertSessionHasNoErrors();

        $this->post('/logout');

        $this->post('/login', ['username' => 'renamed@primepower.com', 'password' => 'correct-horse-battery'])
            ->assertRedirect('/dashboard');
    }

    public function test_a_username_somebody_else_holds_is_refused(): void
    {
        $admin = User::factory()->admin()->create();
        $taken = User::factory()->create(['username' => 'taken@primepower.com']);
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->put("/settings/users/{$user->id}/profile", ['name' => $user->name, 'username' => 'taken'])
            ->assertSessionHasErrors('username');

        $this->assertNotSame($taken->username, $user->refresh()->username);
    }

    /**
     * Nothing reconciles `users.name` with the employee record, so a rename
     * that parts from the 201 file is *said* rather than refused — HR renames
     * people for real reasons and a refusal would strand the login.
     */
    public function test_a_rename_that_drifts_from_the_201_file_says_so(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        Employee::factory()->create(['user_id' => $user->id, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

        $this->actingAs($admin)
            ->put("/settings/users/{$user->id}/profile", ['name' => 'Juanito Cruz', 'username' => $user->username])
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'employee record still reads'));
    }

    public function test_only_an_admin_edits_a_profile_here(): void
    {
        $user = User::factory()->create();

        foreach ([User::factory()->hrStaff()->create(), User::factory()->supervisor()->create(), $user] as $actor) {
            $this->actingAs($actor)
                ->put("/settings/users/{$user->id}/profile", ['name' => 'Whoever', 'username' => 'whoever'])
                ->assertForbidden();
        }

        $this->assertNotSame('Whoever', $user->refresh()->name);
    }

    /** The screen is now a sidebar entry as well as a Settings section; both doors are gated. */
    public function test_users_and_access_stays_admin_only(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/settings/users')->assertOk();
        $this->actingAs(User::factory()->hrStaff()->create())->get('/settings/users')->assertForbidden();
    }
}
