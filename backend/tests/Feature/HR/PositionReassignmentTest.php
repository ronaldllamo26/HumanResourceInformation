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
 * Moving somebody between job titles, from the Positions screen.
 *
 * The screen is master data behind `manageOrganization`; the move is a change
 * to an employee's record behind `update`. Most of this class is about keeping
 * those two apart — they are held by the same roles today, and the day they
 * are not, asking the wrong one would quietly let the wrong person move
 * somebody.
 */
class PositionReassignmentTest extends TestCase
{
    use RefreshDatabase;

    /*
     * -----------------------------------------------------------------
     * The screen
     * -----------------------------------------------------------------
     */

    public function test_a_position_carries_the_people_who_hold_it(): void
    {
        $position = $this->position('OPS', 'Operations', 'OPS-DRV', 'Driver');

        Employee::factory()->count(2)->create([
            'position_id' => $position->id,
            'department_id' => $position->department_id,
            'status' => 'active',
        ]);

        // A separated employee is a record, not a headcount — the archive is
        // where that question is asked.
        Employee::factory()->create([
            'position_id' => $position->id,
            'status' => 'inactive',
        ]);

        $this->actingAs($this->hr())
            ->get('/hr/positions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('positions.0.employees_count', 2)
                ->has('positions.0.employees', 2)
                ->has('positions.0.employees.0.full_name')
                ->has('positions.0.employees.0.employee_number'),
            );
    }

    /**
     * The whole safety of putting people on a master-data screen. It answers
     * "who holds this title", which needs no salary and no government number
     * — the same narrowing the org directory makes.
     */
    public function test_the_holder_rows_carry_no_salary_or_government_numbers(): void
    {
        $position = $this->position();

        Employee::factory()->create([
            'position_id' => $position->id,
            'status' => 'active',
            'basic_salary' => 44000,
            'sss_number' => '34-1234567-8',
            'tin' => '481-946-366-987',
            'bank_account_number' => '0012 3456 7890',
        ]);

        $response = $this->actingAs($this->hr())->get('/hr/positions')->assertOk();
        $payload = json_encode($response->viewData('page')['props']);

        foreach (['44000', '34-1234567-8', '481-946-366-987', '0012 3456 7890'] as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $payload,
                "the positions screen leaked \"{$secret}\"",
            );
        }
    }

    /**
     * A deactivated position is kept so history keeps what it was filed under,
     * not so somebody new can be filed against it — the rule the endorsement
     * form's position matching already applies.
     */
    public function test_a_deactivated_position_is_not_offered_as_a_destination(): void
    {
        $this->position('OPS', 'Operations', 'OPS-DRV', 'Driver');
        $retired = $this->position('OPS', 'Operations', 'OPS-OLD', 'Retired Title');
        $retired->update(['is_active' => false]);

        $this->actingAs($this->hr())
            ->get('/hr/positions')
            ->assertInertia(fn (Assert $page) => $page
                // Still listed — deactivated is not deleted.
                ->has('positions', 2)
                // But not somewhere a person can be moved to.
                ->has('moveTargets', 1)
                ->where('moveTargets.0.title', 'Driver')
                ->where('moveTargets.0.department', 'Operations'),
            );
    }

    /*
     * -----------------------------------------------------------------
     * The move
     * -----------------------------------------------------------------
     */

    public function test_moving_an_employee_changes_their_position(): void
    {
        $from = $this->position('OPS', 'Operations', 'OPS-DRV', 'Driver');
        $to = $this->position('OPS', 'Operations', 'OPS-DSP', 'Dispatcher');

        $employee = Employee::factory()->create([
            'position_id' => $from->id,
            'department_id' => $from->department_id,
            'status' => 'active',
        ]);

        $this->actingAs($this->hr())
            ->patch("/hr/employees/{$employee->id}/position", ['position_id' => $to->id])
            ->assertRedirect();

        $this->assertSame($to->id, $employee->fresh()->position_id);
    }

    /**
     * A position belongs to a department, so a record filed under Operations
     * while holding a Finance title is not a state anybody chose.
     */
    public function test_the_department_follows_the_position(): void
    {
        $operations = $this->position('OPS', 'Operations', 'OPS-DRV', 'Driver');
        $finance = $this->position('FIN', 'Finance', 'FIN-PAY', 'Payroll Officer');

        $employee = Employee::factory()->create([
            'position_id' => $operations->id,
            'department_id' => $operations->department_id,
            'status' => 'active',
        ]);

        $this->actingAs($this->hr())
            ->patch("/hr/employees/{$employee->id}/position", ['position_id' => $finance->id])
            ->assertRedirect();

        $this->assertSame(
            $finance->department_id,
            $employee->fresh()->department_id,
            'the department has to follow, or the record holds a title from a department it is not in',
        );
    }

    /**
     * `basic_salary` is a cache of what `salary_adjustments` says, and money
     * reads that history rather than this column. Writing a rate here would
     * put a figure on the record that no adjustment explains — and payroll
     * would keep paying the old one anyway.
     */
    public function test_a_move_never_touches_pay(): void
    {
        $from = $this->position('OPS', 'Operations', 'OPS-DRV', 'Driver');
        $to = $this->position('OPS', 'Operations', 'OPS-SUP', 'Supervisor');
        $to->update(['min_salary' => 60000, 'max_salary' => 90000]);

        $employee = Employee::factory()->create([
            'position_id' => $from->id,
            'status' => 'active',
            'basic_salary' => 22000,
        ]);

        $this->actingAs($this->hr())
            ->patch("/hr/employees/{$employee->id}/position", ['position_id' => $to->id]);

        $this->assertEquals(
            22000,
            $employee->fresh()->basic_salary,
            'moving into a higher band must not silently grant a raise',
        );
        $this->assertDatabaseCount('salary_adjustments', 0);
    }

    public function test_somebody_cannot_be_moved_onto_a_deactivated_position(): void
    {
        $from = $this->position('OPS', 'Operations', 'OPS-DRV', 'Driver');
        $retired = $this->position('OPS', 'Operations', 'OPS-OLD', 'Retired Title');
        $retired->update(['is_active' => false]);

        $employee = Employee::factory()->create([
            'position_id' => $from->id,
            'status' => 'active',
        ]);

        // `exists` alone would have accepted this: the row is still there, and
        // that is the point of keeping it.
        $this->actingAs($this->hr())
            ->patch("/hr/employees/{$employee->id}/position", ['position_id' => $retired->id])
            ->assertSessionHasErrors('position_id');

        $this->assertSame($from->id, $employee->fresh()->position_id);
    }

    /*
     * -----------------------------------------------------------------
     * Who may do it
     * -----------------------------------------------------------------
     */

    /**
     * The move is gated on `update` for the employee, not on the
     * `manageOrganization` the screen itself uses. Shaping the org chart and
     * filing a person against it are different acts.
     */
    public function test_a_supervisor_cannot_move_somebody(): void
    {
        $from = $this->position('OPS', 'Operations', 'OPS-DRV', 'Driver');
        $to = $this->position('OPS', 'Operations', 'OPS-DSP', 'Dispatcher');

        $employee = Employee::factory()->create([
            'position_id' => $from->id,
            'status' => 'active',
        ]);

        $this->actingAs(User::factory()->role(User::ROLE_SUPERVISOR)->create())
            ->patch("/hr/employees/{$employee->id}/position", ['position_id' => $to->id])
            ->assertForbidden();

        $this->assertSame($from->id, $employee->fresh()->position_id);
    }

    public function test_an_employee_cannot_move_themselves(): void
    {
        $from = $this->position('OPS', 'Operations', 'OPS-DRV', 'Driver');
        $to = $this->position('OPS', 'Operations', 'OPS-SUP', 'Supervisor');

        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $employee = Employee::factory()->create([
            'user_id' => $user->id,
            'position_id' => $from->id,
            'status' => 'active',
        ]);

        // They may *view* their own record. Promoting themselves is a
        // different question, and `update` answers it.
        $this->actingAs($user)
            ->patch("/hr/employees/{$employee->id}/position", ['position_id' => $to->id])
            ->assertForbidden();

        $this->assertSame($from->id, $employee->fresh()->position_id);
    }

    /*
     * -----------------------------------------------------------------
     * Helpers
     * -----------------------------------------------------------------
     */

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }

    private function position(
        string $departmentCode = 'OPS',
        string $departmentName = 'Operations',
        string $code = 'OPS-DRV',
        string $title = 'Driver',
    ): Position {
        $department = Department::firstOrCreate(
            ['code' => $departmentCode],
            ['name' => $departmentName, 'is_active' => true],
        );

        return Position::create([
            'department_id' => $department->id,
            'code' => $code,
            'title' => $title,
            'is_active' => true,
        ]);
    }
}
