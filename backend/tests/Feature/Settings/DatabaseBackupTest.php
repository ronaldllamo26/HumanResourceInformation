<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Who may take a copy of the whole database, and what the screen offers them.
 *
 * The Data & Backup screen used to print the connection name and the sentence
 * "back up with your database server's own dump tooling" — which is true, and
 * is a sentence pointing at a terminal there is none of on the deployment
 * host. This is the half of the replacement that needs the database: the gate,
 * the route, and what the screen hands the reader. Where the database *is* and
 * whether `pg_dump` runs are config questions with no table behind them, and
 * live in `Tests\Unit\DatabaseBackupTest`.
 *
 * Nothing here moves `database.default`. The suite runs on SQLite, so every
 * case below is also the honest answer for an unsupported driver: the backup
 * is refused with a reason rather than producing an empty file.
 */
class DatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Super administrator only, and **not** `manage` — which is what the rest
     * of this screen sits behind. Editing how long the audit trail is kept and
     * downloading every payslip, government identifier and bank account in the
     * company are not the same act, and they share a screen only because both
     * are about data.
     */
    public function test_an_administrator_may_not_take_a_backup(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get('/settings/data/backup')
            ->assertForbidden();
    }

    public function test_hr_staff_may_not_take_a_backup(): void
    {
        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/settings/data/backup')
            ->assertForbidden();
    }

    public function test_an_employee_may_not_take_a_backup(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->get('/settings/data/backup')
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get('/settings/data/backup')->assertRedirect('/login');
    }

    /**
     * The button is drawn only for somebody who may press it — the same rule
     * the org directory follows in drawing a plain row rather than a link
     * nobody may follow. An administrator still reads the rest of the screen.
     */
    public function test_the_screen_withholds_the_button_from_an_administrator(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get('/settings/data')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.backupDatabase', false));
    }

    public function test_the_screen_offers_the_button_to_a_super_administrator(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]))
            ->get('/settings/data')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.backupDatabase', true)
                // On SQLite there is nothing this can dump, so the screen says
                // so rather than offering a button that produces an empty file.
                ->where('database.backup_available', false)
                ->where('database.unavailable_reason', fn ($reason) => str_contains((string) $reason, 'sqlite')),
            );
    }

    /**
     * The screen answers "local or cloud" outright, which is the fact the card
     * used to leave out — and the one somebody opening this on a deployment
     * most needs to be sure of before they press anything.
     */
    public function test_the_screen_says_where_the_database_is(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]))
            ->get('/settings/data')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('database.placement')
                ->has('database.host'),
            );
    }

    /**
     * A backup that cannot be taken goes back with the reason rather than
     * throwing: the administrator did nothing wrong, and there is something
     * specific for them to do about it that an error page would not say.
     */
    public function test_an_impossible_backup_is_explained_rather_than_a_server_error(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]))
            ->from('/settings/data')
            ->get('/settings/data/backup')
            ->assertRedirect('/settings/data')
            ->assertSessionHas('error');
    }

    /**
     * Nothing was handed over, so nothing is recorded as having been.
     *
     * The logger runs after the gate *and* after the dump, for the same reason
     * every other `exported` row does: a refused request that wrote an access
     * row would put a copy of the database in the trail that never left the
     * building.
     */
    public function test_a_refused_backup_writes_no_export_row(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]))
            ->from('/settings/data')
            ->get('/settings/data/backup');

        $this->assertSame(
            0,
            AuditLog::where('event', 'exported')->count(),
            'A backup that never happened must not appear in the audit trail as one that did.',
        );
    }
}
