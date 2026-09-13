<?php

namespace Tests\Feature\HR;

use App\Models\Client;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A delete button in this system never destroys a row. These cover the
 * promise: what happens to a deleted record, who can see it, and that it
 * comes back exactly as it was.
 */
class ArchiveTest extends TestCase
{
    use RefreshDatabase;

    // --- Deleting keeps the row --------------------------------------------

    public function test_an_archived_employee_keeps_their_record(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->admin())
            ->delete("/hr/employees/{$employee->id}")
            ->assertRedirect();

        // Gone from the working list, still on the table.
        $this->assertNull(Employee::find($employee->id));
        $this->assertNotNull(Employee::withTrashed()->find($employee->id));
    }

    /**
     * Clients used to be destroyed outright. Payslips and attendance are
     * grouped by client_id, so the row has to survive or that history points
     * at nothing — and a mis-click had no undo at all.
     */
    public function test_an_unused_client_is_archived_not_destroyed(): void
    {
        $client = $this->client();

        $this->actingAs($this->admin())
            ->delete("/hr/clients/{$client->id}")
            ->assertRedirect();

        $this->assertNull(Client::find($client->id));
        $this->assertNotNull(Client::withTrashed()->find($client->id));
    }

    /** A client with people on it is only deactivated — it never leaves the list. */
    public function test_a_client_with_deployed_staff_stays_visible(): void
    {
        $client = $this->client();
        Employee::factory()->create([
            'employment_category' => 'external', 'client_id' => $client->id,
        ]);

        $this->actingAs($this->admin())->delete("/hr/clients/{$client->id}");

        $this->assertNotNull(Client::find($client->id));
        $this->assertFalse(Client::find($client->id)->is_active);
    }

    // --- The master list ---------------------------------------------------

    public function test_the_archive_lists_deleted_employees_and_clients_together(): void
    {
        $employee = Employee::factory()->create();
        $client = $this->client();

        $employee->delete();
        $client->delete();

        $this->actingAs($this->admin())
            ->get('/hr/archive')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 2)
                ->where('summary.employees', 1)
                ->where('summary.clients', 1),
            );
    }

    public function test_live_records_are_not_listed(): void
    {
        Employee::factory()->count(3)->create();
        $this->client();

        $this->actingAs($this->admin())
            ->get('/hr/archive')
            ->assertInertia(fn (Assert $page) => $page->has('rows', 0));
    }

    public function test_the_archive_is_searchable(): void
    {
        Employee::factory()->create(['first_name' => 'Zenaida', 'last_name' => 'Marquez'])->delete();
        Employee::factory()->create(['first_name' => 'Rolando', 'last_name' => 'Cruz'])->delete();

        $this->actingAs($this->admin())
            ->get('/hr/archive?search=Zenaida')
            ->assertInertia(fn (Assert $page) => $page->has('rows', 1));
    }

    // --- Restoring ---------------------------------------------------------

    public function test_restoring_an_employee_puts_them_back_as_active(): void
    {
        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($this->admin())->delete("/hr/employees/{$employee->id}");

        $this->actingAs($this->admin())
            ->post("/hr/archive/employees/{$employee->id}/restore")
            ->assertRedirect();

        $restored = Employee::find($employee->id);

        $this->assertNotNull($restored);
        $this->assertSame('active', $restored->status);
        // Deleting deactivated the login, so restoring has to switch it back
        // or the employee is on the directory but cannot sign in.
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_restoring_a_client_puts_it_back_on_the_list(): void
    {
        $client = $this->client();
        $client->delete();

        $this->actingAs($this->admin())
            ->post("/hr/archive/clients/{$client->id}/restore")
            ->assertRedirect();

        $this->assertNotNull(Client::find($client->id));
    }

    // --- Who may see it ----------------------------------------------------

    /** Putting a deleted record back is an admin act, so the screen is too. */
    public function test_hr_staff_cannot_reach_the_archive(): void
    {
        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/hr/archive')
            ->assertForbidden();
    }

    public function test_an_employee_cannot_restore_a_record(): void
    {
        $employee = Employee::factory()->create();
        $employee->delete();

        $user = User::factory()->create();
        Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post("/hr/archive/employees/{$employee->id}/restore")
            ->assertForbidden();

        $this->assertNull(Employee::find($employee->id));
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get('/hr/archive')->assertRedirect('/login');
    }

    // --- The window is a label, not a deadline -----------------------------

    /**
     * Nothing expires. Employment records must be held three years under the
     * Labor Code and payroll ten under the NIRC, so a record older than the
     * restore window is still listed and still restorable — the window only
     * changes how the row reads.
     */
    public function test_a_record_older_than_the_window_is_still_restorable(): void
    {
        config(['archive.restore_window_days' => 30]);

        $employee = Employee::factory()->create();
        $employee->delete();
        $employee->update(['deleted_at' => now()->subDays(200)]);

        $this->actingAs($this->admin())
            ->get('/hr/archive')
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.within_window', false)
                ->where('summary.within_window', 0),
            );

        $this->actingAs($this->admin())
            ->post("/hr/archive/employees/{$employee->id}/restore")
            ->assertRedirect();

        $this->assertNotNull(Employee::find($employee->id));
    }

    public function test_a_recent_deletion_is_flagged_as_within_the_window(): void
    {
        config(['archive.restore_window_days' => 30]);

        Employee::factory()->create()->delete();

        $this->actingAs($this->admin())
            ->get('/hr/archive')
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.within_window', true)
                ->where('summary.within_window', 1),
            );
    }

    private function client(array $overrides = []): Client
    {
        return Client::create(array_merge([
            'code' => 'CLI'.Client::withTrashed()->count(),
            'name' => 'Test Client '.Client::withTrashed()->count(),
            'is_active' => true,
        ], $overrides));
    }

    private function admin(): User
    {
        return User::factory()->role(User::ROLE_ADMIN)->create();
    }
}
