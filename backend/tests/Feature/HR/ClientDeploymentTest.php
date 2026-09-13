<?php

namespace Tests\Feature\HR;

use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeEndorsement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * PrimePower is a manpower agency: its workforce splits into the staff who run
 * the agency and the employees deployed to client companies. These cover the
 * rules that split holds — who may be filed against a client, and what that
 * changes downstream.
 */
class ClientDeploymentTest extends TestCase
{
    use RefreshDatabase;

    // --- The internal / external split -------------------------------------

    public function test_an_external_employee_must_be_deployed_to_a_client(): void
    {
        $this->actingAs($this->hr())
            ->post('/hr/employees', $this->payload([
                'employment_category' => 'external',
                'client_id' => null,
            ]))
            ->assertSessionHasErrors('client_id');

        $this->assertDatabaseCount('employees', 0);
    }

    /**
     * Prohibited rather than merely ignored: a client id left on someone
     * brought in-house keeps them in that client's billing and headcount,
     * which is an error nobody would think to go looking for.
     */
    public function test_internal_staff_cannot_carry_a_client(): void
    {
        $client = $this->client();

        $this->actingAs($this->hr())
            ->post('/hr/employees', $this->payload([
                'employment_category' => 'internal',
                'client_id' => $client->id,
            ]))
            ->assertSessionHasErrors('client_id');
    }

    public function test_hr_can_deploy_an_employee_to_a_client(): void
    {
        $client = $this->client();

        $this->actingAs($this->hr())
            ->post('/hr/employees', $this->payload([
                'employment_category' => 'external',
                'client_id' => $client->id,
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('employees', [
            'employment_category' => 'external',
            'client_id' => $client->id,
        ]);
    }

    public function test_an_unknown_category_is_rejected(): void
    {
        $this->actingAs($this->hr())
            ->post('/hr/employees', $this->payload(['employment_category' => 'contractor']))
            ->assertSessionHasErrors('employment_category');
    }

    // --- The directory -----------------------------------------------------

    public function test_the_directory_filters_by_client(): void
    {
        $a = $this->client(['code' => 'AAA']);
        $b = $this->client(['code' => 'BBB']);

        Employee::factory()->count(3)->create([
            'employment_category' => 'external', 'client_id' => $a->id,
        ]);
        Employee::factory()->count(2)->create([
            'employment_category' => 'external', 'client_id' => $b->id,
        ]);
        Employee::factory()->count(4)->create(['employment_category' => 'internal']);

        $this->actingAs($this->hr())
            ->get("/hr/employees?client_id={$a->id}")
            ->assertInertia(fn (Assert $page) => $page->has('employees.data', 3));
    }

    public function test_the_directory_filters_by_category(): void
    {
        Employee::factory()->count(3)->create([
            'employment_category' => 'external', 'client_id' => $this->client()->id,
        ]);
        Employee::factory()->count(4)->create(['employment_category' => 'internal']);

        $this->actingAs($this->hr())
            ->get('/hr/employees?employment_category=internal')
            ->assertInertia(fn (Assert $page) => $page->has('employees.data', 4));
    }

    // --- Wage region -------------------------------------------------------

    /**
     * There is no national minimum wage in the Philippines — each region's
     * RTWPB sets its own. A deployed employee inherits their client's site.
     */
    public function test_an_employee_inherits_their_clients_wage_region(): void
    {
        $employee = Employee::factory()->create([
            'employment_category' => 'external',
            'client_id' => $this->client(['wage_region' => 'R7'])->id,
            'wage_region' => null,
        ]);

        $this->assertSame('R7', $employee->fresh()->wageRegion());
    }

    /** Someone posted away from the client site overrides it on their record. */
    public function test_an_employees_own_region_overrides_the_clients(): void
    {
        $employee = Employee::factory()->create([
            'employment_category' => 'external',
            'client_id' => $this->client(['wage_region' => 'R7'])->id,
            'wage_region' => 'NCR',
        ]);

        $this->assertSame('NCR', $employee->fresh()->wageRegion());
    }

    /** Internal staff are deployed nowhere, so there is nothing to inherit. */
    public function test_internal_staff_fall_back_to_the_configured_default(): void
    {
        config(['payroll.default_wage_region' => 'R3']);

        $employee = Employee::factory()->create([
            'employment_category' => 'internal',
            'client_id' => null,
            'wage_region' => null,
        ]);

        $this->assertSame('R3', $employee->fresh()->wageRegion());
    }

    // --- The clients screen ------------------------------------------------

    public function test_a_client_with_deployed_staff_is_deactivated_not_deleted(): void
    {
        $client = $this->client();
        Employee::factory()->create([
            'employment_category' => 'external', 'client_id' => $client->id,
        ]);

        $this->actingAs($this->admin())
            ->delete("/hr/clients/{$client->id}")
            ->assertRedirect();

        // The row survives so payroll and attendance keep the client they were
        // filed under; it is simply no longer offered for new deployments.
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'is_active' => false]);
    }

    /**
     * A client nobody was deployed to is archived, not destroyed.
     *
     * This used to assert a hard delete. Nothing in the system destroys a row
     * any more — see ArchiveTest for the full behaviour and why: payslips and
     * attendance are grouped by client_id, and a mis-click should cost a click
     * to undo rather than a retype.
     */
    public function test_an_unused_client_is_archived_not_destroyed(): void
    {
        $client = $this->client();

        $this->actingAs($this->admin())
            ->delete("/hr/clients/{$client->id}")
            ->assertRedirect();

        // Off the working list...
        $this->assertNull(Client::find($client->id));
        // ...but the row survives, and the archive can restore it.
        $this->assertNotNull(Client::withTrashed()->find($client->id));
    }

    /** Same gate as departments — a client is org structure in an agency. */
    public function test_a_supervisor_cannot_reach_the_clients_screen(): void
    {
        $user = User::factory()->role(User::ROLE_SUPERVISOR)->create();
        Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->get('/hr/clients')->assertForbidden();
    }

    // --- Deploying from the Clients screen ----------------------------------

    public function test_deploying_moves_the_employee_and_the_category_together(): void
    {
        $client = $this->client();
        $employee = Employee::factory()->create([
            'employment_category' => Employee::CATEGORY_INTERNAL,
            'client_id' => null,
        ]);

        $this->actingAs($this->hr())
            ->patch("/hr/employees/{$employee->id}/deployment", ['client_id' => $client->id])
            ->assertRedirect();

        $employee->refresh();

        $this->assertSame($client->id, $employee->client_id);
        /*
         * The pair is the point. `client_id` is prohibited on internal staff
         * rather than ignored, so setting one without the category writes a
         * record the employee form would refuse to save.
         */
        $this->assertSame(Employee::CATEGORY_EXTERNAL, $employee->employment_category);
    }

    public function test_recalling_clears_the_client_and_returns_them_to_internal(): void
    {
        $client = $this->client();
        $employee = Employee::factory()->create([
            'employment_category' => Employee::CATEGORY_EXTERNAL,
            'client_id' => $client->id,
        ]);

        $this->actingAs($this->hr())
            ->patch("/hr/employees/{$employee->id}/deployment", ['client_id' => null])
            ->assertRedirect();

        $employee->refresh();

        $this->assertNull($employee->client_id);
        // A stale client on somebody brought in-house keeps them in that
        // client's billing and headcount — an error nobody goes looking for.
        $this->assertSame(Employee::CATEGORY_INTERNAL, $employee->employment_category);
    }

    public function test_a_deactivated_client_cannot_be_deployed_to(): void
    {
        $client = $this->client(['is_active' => false]);
        $employee = Employee::factory()->create();

        // Kept so payroll and attendance keep what they were filed under, not
        // so somebody new can be sent there.
        $this->actingAs($this->hr())
            ->patch("/hr/employees/{$employee->id}/deployment", ['client_id' => $client->id])
            ->assertSessionHasErrors('client_id');

        $this->assertNull($employee->fresh()->client_id);
    }

    public function test_deploying_leaves_the_position_and_department_alone(): void
    {
        $client = $this->client();
        $employee = Employee::factory()->create();

        $position = $employee->position_id;
        $department = $employee->department_id;

        $this->actingAs($this->hr())
            ->patch("/hr/employees/{$employee->id}/deployment", ['client_id' => $client->id]);

        $employee->refresh();

        // A driver deployed to a client is still a driver; where they are sent
        // is not what they do.
        $this->assertSame($position, $employee->position_id);
        $this->assertSame($department, $employee->department_id);
    }

    public function test_deploying_is_gated_on_updating_that_employee(): void
    {
        $client = $this->client();
        $employee = Employee::factory()->create();

        $user = User::factory()->create();
        Employee::factory()->create(['user_id' => $user->id]);

        /*
         * Gated on EmployeePolicy::update for the *employee*, not on
         * manageOrganization: that gate is for shaping the client list, and
         * filing a person against one is a different act.
         */
        $this->actingAs($user)
            ->patch("/hr/employees/{$employee->id}/deployment", ['client_id' => $client->id])
            ->assertForbidden();
    }

    public function test_the_clients_screen_carries_who_can_be_deployed(): void
    {
        $this->client();
        Employee::factory()->count(2)->create();
        Employee::factory()->create(['status' => 'inactive']);

        $this->actingAs($this->hr())
            ->get('/hr/clients')
            ->assertOk()
            // Active roster only — somebody who has left is a record, not
            // somebody who can be sent anywhere.
            ->assertInertia(fn (Assert $page) => $page->has('deployable', 2));
    }

    public function test_the_picker_carries_the_job_being_deployed(): void
    {
        $this->client();

        $employee = Employee::factory()->create();

        /*
         * The position is the basis of the decision — a client asking for
         * drivers is not asking for whoever is free. Without it the picker
         * showed names and numbers, and "can this person do the job" was a
         * question you had to leave the screen to answer.
         */
        $this->actingAs($this->hr())
            ->get('/hr/clients')
            ->assertInertia(fn (Assert $page) => $page
                ->where('deployable.0.position', $employee->position?->title)
                ->where('deployable.0.department', $employee->department?->name),
            );
    }

    // --- Payroll grouping --------------------------------------------------

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            // Employees are created by approving a Core 1 endorsement — the
            // form has no other way in. A fresh one per call, because one
            // endorsement becomes one employee and a decided one is closed.
            'endorsement_id' => EmployeeEndorsement::factory()->create()->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'nationality' => 'Filipino',
            'employment_category' => 'internal',
            'employment_status' => 'probationary',
            'employment_type' => 'full_time',
            'date_hired' => '2026-01-15',
            'basic_salary' => 25000,
            'pay_frequency' => 'semi_monthly',
            'status' => 'active',
        ], $overrides);
    }

    private function client(array $overrides = []): Client
    {
        return Client::create(array_merge([
            'code' => 'CLI'.Client::count(),
            'name' => 'Test Client '.Client::count(),
            'wage_region' => 'NCR',
            'is_active' => true,
        ], $overrides));
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }

    private function admin(): User
    {
        return User::factory()->role(User::ROLE_ADMIN)->create();
    }
}
