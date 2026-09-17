<?php

namespace Tests\Feature\HR;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeEndorsement;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_can_view_the_employee_directory(): void
    {
        Employee::factory()->count(3)->create();

        $this->actingAs($this->hr())
            ->get('/hr/employees')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Employees/Index')
                ->has('employees.data', 3)
                // Pagination reads meta.links; employees.links is the
                // {first,last,prev,next} object and must not be used as an array.
                ->has('employees.meta.links')
                ->where('statistics.total', 3),
            );
    }

    public function test_paginator_meta_links_is_an_array(): void
    {
        Employee::factory()->count(20)->create();

        $response = $this->actingAs($this->hr())->get('/hr/employees');

        $links = $response->viewData('page')['props']['employees']['meta']['links'];

        $this->assertIsArray($links);
        $this->assertGreaterThan(3, count($links));
    }

    /**
     * The directory scrolls to load more instead of numbered pages: a partial
     * reload for the next page must carry the merge instructions the client
     * needs to append rows rather than replace them, and must never repeat a
     * row the first page already sent.
     */
    public function test_scrolling_further_merges_the_next_page_instead_of_replacing_it(): void
    {
        Employee::factory()->count(20)->create();
        $hr = $this->hr();

        $first = $this->actingAs($hr)->get('/hr/employees');
        $version = $first->viewData('page')['version'];
        $firstIds = collect($first->viewData('page')['props']['employees']['data'])->pluck('id');

        $partial = $this->actingAs($hr)->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Component' => 'HR/Employees/Index',
            'X-Inertia-Partial-Data' => 'employees',
        ])->get('/hr/employees?page=2');

        $partial->assertOk();
        $payload = $partial->json();

        // The server sends only page 2's rows — the client does the
        // appending — plus the instructions it needs to do that.
        $this->assertCount(5, $payload['props']['employees']['data']);
        $this->assertContains('employees.data', $payload['mergeProps']);
        $this->assertContains('employees.data.id', $payload['matchPropsOn']);

        $secondIds = collect($payload['props']['employees']['data'])->pluck('id');
        $this->assertTrue(
            $firstIds->intersect($secondIds)->isEmpty(),
            'page 2 must not repeat a row page 1 already sent',
        );
    }

    public function test_hr_can_create_an_employee(): void
    {
        $department = $this->department();
        $position = Position::create([
            'department_id' => $department->id,
            'code' => 'OPS-DRV',
            'title' => 'Professional Driver',
            'is_active' => true,
        ]);

        $this->actingAs($this->hr())
            ->post('/hr/employees', $this->payload([
                'department_id' => $department->id,
                'position_id' => $position->id,
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('employees', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'department_id' => $department->id,
            'employment_category' => 'internal',
            'employment_status' => 'probationary',
        ]);
    }

    public function test_created_employees_get_a_sequential_employee_number(): void
    {
        $hr = $this->hr();

        $this->actingAs($hr)->post('/hr/employees', $this->payload());
        $this->actingAs($hr)->post('/hr/employees', $this->payload(['first_name' => 'Pedro']));

        $numbers = Employee::orderBy('id')->pluck('employee_number')->all();

        $this->assertCount(2, $numbers);
        $this->assertNotSame($numbers[0], $numbers[1]);
        $this->assertMatchesRegularExpression('/^PPM-\d{4}-\d{4}$/', $numbers[0]);
    }

    public function test_creating_an_employee_writes_an_audit_log(): void
    {
        $hr = $this->hr();

        $this->actingAs($hr)->post('/hr/employees', $this->payload());

        $employee = Employee::first();

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Employee::class,
            'auditable_id' => $employee->id,
            'event' => 'created',
            'user_id' => $hr->id,
        ]);
    }

    public function test_creating_an_employee_can_provision_a_login(): void
    {
        $this->actingAs($this->hr())->post('/hr/employees', $this->payload([
            'email' => 'juan@primepower.com',
            'create_user_account' => true,
            'user_role' => 'employee',
        ]))->assertSessionHas('success', fn ($message) => str_contains($message, 'Login: jdelacruz@primepower.com'));

        // The username comes from the name. The employee's email is contact
        // information on the 201 file, and is not copied onto the login.
        $this->assertDatabaseHas('users', [
            'username' => 'jdelacruz@primepower.com',
            'email' => null,
            'role' => 'employee',
        ]);

        $this->assertNotNull(Employee::first()->user_id);
    }

    public function test_a_login_can_be_provisioned_without_an_email(): void
    {
        $this->actingAs($this->hr())
            ->post('/hr/employees', $this->payload(['create_user_account' => true]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['username' => 'jdelacruz@primepower.com']);
    }

    public function test_required_fields_are_validated(): void
    {
        $this->actingAs($this->hr())
            ->post('/hr/employees', [])
            ->assertSessionHasErrors([
                'first_name', 'last_name', 'employment_status',
                'employment_type', 'date_hired', 'basic_salary', 'status',
            ]);
    }

    public function test_regularization_date_cannot_precede_the_hire_date(): void
    {
        $this->actingAs($this->hr())
            ->post('/hr/employees', $this->payload([
                'date_hired' => '2026-06-01',
                'date_regularized' => '2026-01-01',
            ]))
            ->assertSessionHasErrors('date_regularized');
    }

    public function test_hr_can_update_an_employee(): void
    {
        $employee = Employee::factory()->create(['last_name' => 'Original']);

        $this->actingAs($this->hr())
            ->put("/hr/employees/{$employee->id}", $this->payload([
                'last_name' => 'Updated',
            ]))
            ->assertRedirect();

        $this->assertSame('Updated', $employee->fresh()->last_name);
    }

    public function test_the_employee_number_is_immutable_on_update(): void
    {
        $employee = Employee::factory()->create();
        $original = $employee->employee_number;

        $this->actingAs($this->hr())->put("/hr/employees/{$employee->id}", $this->payload([
            'employee_number' => 'HACKED-0001',
        ]));

        $this->assertSame($original, $employee->fresh()->employee_number);
    }

    public function test_an_employee_cannot_be_their_own_supervisor(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->hr())
            ->put("/hr/employees/{$employee->id}", $this->payload([
                'supervisor_id' => $employee->id,
            ]))
            ->assertSessionHasErrors('supervisor_id');
    }

    public function test_an_admin_can_archive_an_employee(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->delete("/hr/employees/{$employee->id}")
            ->assertRedirect('/hr/employees');

        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
    }

    public function test_search_matches_name_and_employee_number(): void
    {
        Employee::factory()->create(['first_name' => 'Marilou', 'last_name' => 'Bautista']);
        Employee::factory()->count(2)->create();

        $this->actingAs($this->hr())
            ->get('/hr/employees?search=Bautista')
            ->assertInertia(fn (Assert $page) => $page->has('employees.data', 1));
    }

    public function test_the_directory_can_be_filtered_by_status(): void
    {
        Employee::factory()->count(2)->create(['status' => 'active']);
        Employee::factory()->onLeave()->create();

        $this->actingAs($this->hr())
            ->get('/hr/employees?status=on_leave')
            ->assertInertia(fn (Assert $page) => $page->has('employees.data', 1));
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }

    private function department(): Department
    {
        return Department::create(['code' => 'OPS', 'name' => 'Fleet Operations', 'is_active' => true]);
    }

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
            // Required now that the agency splits its workforce; a create with
            // no category is rejected rather than silently filed as internal.
            'employment_category' => 'internal',
            'employment_status' => 'probationary',
            'employment_type' => 'full_time',
            'date_hired' => '2026-01-15',
            'basic_salary' => 25000,
            'pay_frequency' => 'semi_monthly',
            'status' => 'active',
        ], $overrides);
    }
}
