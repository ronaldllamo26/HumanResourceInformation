<?php

namespace Tests\Feature\HR;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Departments and positions — the org structure employee records are filed
 * against. Moved out of Settings into Employee Information; the old settings
 * URL redirects rather than 404s.
 */
class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    // --- The screens -------------------------------------------------------

    public function test_the_departments_screen_renders_with_its_counts(): void
    {
        $department = Department::create(['code' => 'OPS', 'name' => 'Operations']);
        Employee::factory()->count(2)->create(['department_id' => $department->id]);

        $this->actingAs($this->hr())
            ->get('/hr/departments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/MasterData/Departments')
                ->has('departments', 1)
                ->where('departments.0.employees_count', 2)
                ->where('summary.total', 1)
                ->where('summary.active', 1),
            );
    }

    public function test_the_positions_screen_renders_with_its_band(): void
    {
        $department = Department::create(['code' => 'OPS', 'name' => 'Operations']);
        Position::create([
            'department_id' => $department->id,
            'code' => 'OPS-DRV',
            'title' => 'Professional Driver',
            'min_salary' => 18000,
            'max_salary' => 25000,
        ]);

        $this->actingAs($this->hr())
            ->get('/hr/positions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/MasterData/Positions')
                ->has('positions', 1)
                ->where('positions.0.min_salary', 18000)
                ->where('positions.0.max_salary', 25000)
                ->where('summary.without_band', 0),
            );
    }

    /** A position with no band is not an error, but it is worth counting. */
    public function test_positions_without_a_band_are_counted(): void
    {
        $department = Department::create(['code' => 'OPS', 'name' => 'Operations']);
        Position::create([
            'department_id' => $department->id,
            'code' => 'OPS-X',
            'title' => 'Unbanded',
        ]);

        $this->actingAs($this->hr())
            ->get('/hr/positions')
            ->assertInertia(fn (Assert $page) => $page->where('summary.without_band', 1));
    }

    // --- Searching and filtering -------------------------------------------

    public function test_departments_can_be_searched_by_name_or_code(): void
    {
        Department::create(['code' => 'OPS', 'name' => 'Operations']);
        Department::create(['code' => 'FIN', 'name' => 'Finance']);

        $this->actingAs($this->hr())
            ->get('/hr/departments?search=fin')
            ->assertInertia(fn (Assert $page) => $page
                ->has('departments', 1)
                ->where('departments.0.code', 'FIN')
                // The summary counts the whole table, not the filtered view.
                ->where('summary.total', 2),
            );
    }

    public function test_positions_can_be_filtered_by_department(): void
    {
        $operations = Department::create(['code' => 'OPS', 'name' => 'Operations']);
        $finance = Department::create(['code' => 'FIN', 'name' => 'Finance']);

        Position::create(['department_id' => $operations->id, 'code' => 'A', 'title' => 'Driver']);
        Position::create(['department_id' => $finance->id, 'code' => 'B', 'title' => 'Analyst']);

        $this->actingAs($this->hr())
            ->get('/hr/positions?department_id='.$finance->id)
            ->assertInertia(fn (Assert $page) => $page
                ->has('positions', 1)
                ->where('positions.0.title', 'Analyst'),
            );
    }

    // --- Writing -----------------------------------------------------------

    public function test_hr_can_create_a_department_and_position(): void
    {
        $hr = $this->hr();

        $this->actingAs($hr)->post('/hr/departments', [
            'code' => 'ops',
            'name' => 'Fleet Operations',
        ])->assertRedirect();

        // Codes are normalised to upper case.
        $department = Department::firstOrFail();
        $this->assertSame('OPS', $department->code);

        $this->actingAs($hr)->post('/hr/positions', [
            'department_id' => $department->id,
            'code' => 'ops-drv',
            'title' => 'Professional Driver',
            'min_salary' => 18000,
            'max_salary' => 25000,
        ])->assertRedirect();

        $this->assertDatabaseHas('positions', ['code' => 'OPS-DRV']);
    }

    public function test_department_codes_must_be_unique(): void
    {
        Department::create(['code' => 'OPS', 'name' => 'Operations']);

        $this->actingAs($this->hr())
            ->post('/hr/departments', ['code' => 'OPS', 'name' => 'Duplicate'])
            ->assertSessionHasErrors('code');
    }

    public function test_a_maximum_salary_cannot_sit_below_the_minimum(): void
    {
        $department = Department::create(['code' => 'OPS', 'name' => 'Operations']);

        $this->actingAs($this->hr())
            ->post('/hr/positions', [
                'department_id' => $department->id,
                'code' => 'OPS-X',
                'title' => 'Backwards Band',
                'min_salary' => 30000,
                'max_salary' => 20000,
            ])
            ->assertSessionHasErrors('max_salary');
    }

    public function test_a_department_can_be_edited(): void
    {
        $department = Department::create(['code' => 'OPS', 'name' => 'Operations']);

        $this->actingAs($this->hr())
            ->put("/hr/departments/{$department->id}", [
                'code' => 'OPS',
                'name' => 'Fleet Operations',
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertSame('Fleet Operations', $department->refresh()->name);
    }

    // --- Deleting versus deactivating --------------------------------------

    public function test_a_department_in_use_is_deactivated_rather_than_deleted(): void
    {
        $department = Department::create(['code' => 'OPS', 'name' => 'Operations']);
        Employee::factory()->create(['department_id' => $department->id]);

        $this->actingAs($this->hr())
            ->delete("/hr/departments/{$department->id}")
            ->assertRedirect();

        $this->assertDatabaseHas('departments', ['id' => $department->id, 'is_active' => false]);
    }

    public function test_an_unused_position_is_deleted(): void
    {
        $department = Department::create(['code' => 'OPS', 'name' => 'Operations']);
        $position = Position::create([
            'department_id' => $department->id,
            'code' => 'OPS-X',
            'title' => 'Unused',
        ]);

        $this->actingAs($this->hr())
            ->delete("/hr/positions/{$position->id}")
            ->assertRedirect();

        $this->assertDatabaseCount('positions', 0);
    }

    /** An employee's history has to keep the title they held. */
    public function test_a_position_in_use_is_deactivated_rather_than_deleted(): void
    {
        $department = Department::create(['code' => 'OPS', 'name' => 'Operations']);
        $position = Position::create([
            'department_id' => $department->id,
            'code' => 'OPS-DRV',
            'title' => 'Driver',
        ]);
        Employee::factory()->create([
            'department_id' => $department->id,
            'position_id' => $position->id,
        ]);

        $this->actingAs($this->hr())
            ->delete("/hr/positions/{$position->id}")
            ->assertRedirect();

        $this->assertDatabaseHas('positions', ['id' => $position->id, 'is_active' => false]);
    }

    // --- Access control ----------------------------------------------------

    public function test_a_supervisor_cannot_reach_master_data(): void
    {
        $supervisor = User::factory()->supervisor()->create();

        $this->actingAs($supervisor)->get('/hr/departments')->assertForbidden();
        $this->actingAs($supervisor)->get('/hr/positions')->assertForbidden();
    }

    public function test_an_employee_cannot_create_a_department(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/hr/departments', ['code' => 'X', 'name' => 'Sneaky'])
            ->assertForbidden();

        $this->assertDatabaseCount('departments', 0);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/hr/departments')->assertRedirect('/login');
        $this->get('/hr/positions')->assertRedirect('/login');
    }

    // --- The move ----------------------------------------------------------

    /** Old links and bookmarks still land somewhere useful. */
    public function test_the_old_settings_url_redirects_to_departments(): void
    {
        $this->actingAs($this->hr())
            ->get('/settings/organization')
            ->assertRedirect('/hr/departments');
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }
}
