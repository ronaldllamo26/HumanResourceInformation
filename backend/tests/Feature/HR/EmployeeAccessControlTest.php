<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmployeeAccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/hr/employees')->assertRedirect('/login');
    }

    public function test_an_employee_only_sees_their_own_record(): void
    {
        [$user] = $this->staff(User::ROLE_EMPLOYEE);
        Employee::factory()->count(4)->create();

        $this->actingAs($user)
            ->get('/hr/employees')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('employees.data', 1)
                ->where('statistics.total', 1),
            );
    }

    public function test_a_supervisor_sees_themselves_and_their_direct_reports(): void
    {
        [$user, $supervisorRecord] = $this->staff(User::ROLE_SUPERVISOR);

        Employee::factory()->count(2)->create(['supervisor_id' => $supervisorRecord->id]);
        Employee::factory()->count(3)->create();

        $this->actingAs($user)
            ->get('/hr/employees')
            ->assertInertia(fn (Assert $page) => $page->has('employees.data', 3));
    }

    public function test_hr_staff_see_every_record(): void
    {
        Employee::factory()->count(5)->create();

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/hr/employees')
            ->assertInertia(fn (Assert $page) => $page->has('employees.data', 5));
    }

    public function test_an_employee_cannot_view_someone_elses_201_file(): void
    {
        [$user] = $this->staff(User::ROLE_EMPLOYEE);
        $other = Employee::factory()->create();

        $this->actingAs($user)->get("/hr/employees/{$other->id}")->assertForbidden();
    }

    public function test_an_employee_can_view_their_own_201_file(): void
    {
        [$user, $own] = $this->staff(User::ROLE_EMPLOYEE);

        $this->actingAs($user)->get("/hr/employees/{$own->id}")->assertOk();
    }

    public function test_non_hr_roles_cannot_reach_the_create_form(): void
    {
        [$employee] = $this->staff(User::ROLE_EMPLOYEE);
        [$supervisor] = $this->staff(User::ROLE_SUPERVISOR);

        $this->actingAs($employee)->get('/hr/employees/create')->assertForbidden();
        $this->actingAs($supervisor)->get('/hr/employees/create')->assertForbidden();
    }

    public function test_non_hr_roles_cannot_create_employees(): void
    {
        [$user] = $this->staff(User::ROLE_EMPLOYEE);

        $this->actingAs($user)->post('/hr/employees', [
            'first_name' => 'Sneaky',
            'last_name' => 'User',
            'employment_status' => 'regular',
            'employment_type' => 'full_time',
            'date_hired' => '2026-01-01',
            'basic_salary' => 1,
            'pay_frequency' => 'monthly',
            'status' => 'active',
        ])->assertForbidden();

        $this->assertDatabaseMissing('employees', ['first_name' => 'Sneaky']);
    }

    public function test_an_employee_cannot_update_their_own_salary(): void
    {
        [$user, $own] = $this->staff(User::ROLE_EMPLOYEE);

        $this->actingAs($user)
            ->put("/hr/employees/{$own->id}", ['basic_salary' => 999999])
            ->assertForbidden();

        $this->assertNotEquals('999999.00', $own->fresh()->basic_salary);
    }

    public function test_only_admins_can_archive_employees(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs(User::factory()->hrStaff()->create())
            ->delete("/hr/employees/{$employee->id}")
            ->assertForbidden();

        $this->assertNotSoftDeleted('employees', ['id' => $employee->id]);
    }

    public function test_an_admin_cannot_archive_their_own_record(): void
    {
        $admin = User::factory()->admin()->create();
        $own = Employee::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin)->delete("/hr/employees/{$own->id}")->assertForbidden();
    }

    public function test_salary_and_government_ids_are_hidden_from_a_supervisor(): void
    {
        [$user, $supervisorRecord] = $this->staff(User::ROLE_SUPERVISOR);
        $report = Employee::factory()->create(['supervisor_id' => $supervisorRecord->id]);

        $this->actingAs($user)
            ->get("/hr/employees/{$report->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.viewSensitive', false)
                ->missing('employee.data.basic_salary')
                ->missing('employee.data.sss_number')
                ->missing('employee.data.tin'),
            );
    }

    public function test_hr_can_see_salary_and_government_ids(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get("/hr/employees/{$employee->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.viewSensitive', true)
                ->has('employee.data.basic_salary')
                ->has('employee.data.sss_number'),
            );
    }

    /** Creates a user linked to their own 201 file. */
    private function staff(string $role): array
    {
        $user = User::factory()->role($role)->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        return [$user, $employee];
    }
}
