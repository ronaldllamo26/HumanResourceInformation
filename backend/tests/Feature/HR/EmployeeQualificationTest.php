<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\EmployeeEducation;
use App\Models\EmployeeSkill;
use App\Models\EmployeeTraining;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Educational & qualification records — the part of the 201 file that says
 * what somebody is qualified for.
 */
class EmployeeQualificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_can_record_education_training_and_skills(): void
    {
        $employee = Employee::factory()->create();
        $hr = $this->hr();

        $this->actingAs($hr)->post("/hr/employees/{$employee->id}/education", [
            'level' => 'college',
            'school' => 'University of the East',
            'course' => 'BS Industrial Engineering',
            'year_graduated' => 2019,
            'honors' => 'Cum Laude',
        ])->assertRedirect();

        $this->actingAs($hr)->post("/hr/employees/{$employee->id}/training", [
            'title' => 'Forklift Operation NC II',
            'provider' => 'TESDA',
            'completed_at' => '2024-03-01',
            'expires_at' => '2029-03-01',
            'hours' => 40,
        ])->assertRedirect();

        $this->actingAs($hr)->post("/hr/employees/{$employee->id}/skill", [
            'name' => 'Defensive Driving',
            'proficiency' => 'advanced',
        ])->assertRedirect();

        $this->assertDatabaseCount('employee_educations', 1);
        $this->assertDatabaseCount('employee_trainings', 1);
        $this->assertDatabaseCount('employee_skills', 1);
    }

    public function test_the_profile_carries_the_three_lists_and_the_highest_attainment(): void
    {
        $employee = Employee::factory()->create();

        $employee->educations()->createMany([
            ['level' => 'high_school', 'school' => 'Rizal High School', 'year_graduated' => 2013],
            ['level' => 'college', 'school' => 'University of the East', 'year_graduated' => 2019],
        ]);
        $employee->trainings()->create(['title' => 'Defensive Driving']);
        $employee->skills()->create(['name' => 'Forklift Operation']);

        $this->actingAs($this->hr())
            ->get("/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('employee.data.educations', 2)
                ->has('employee.data.trainings', 1)
                ->has('employee.data.skills', 1)
                // Derived from the rows and their order in config, never
                // stored — so adding a degree cannot leave it stale.
                ->where('employee.data.highest_education', 'College / Bachelor')
                // Highest first, so the row that matters is the row you read.
                ->where('employee.data.educations.0.level', 'college'),
            );
    }

    public function test_a_training_with_no_expiry_reports_no_state_rather_than_expired(): void
    {
        $employee = Employee::factory()->create();

        $employee->trainings()->createMany([
            ['title' => 'Company orientation'],                              // never lapses
            ['title' => 'First Aid', 'expires_at' => now()->subDay()],
            ['title' => 'Forklift NC II', 'expires_at' => now()->addYears(3)],
        ]);

        $this->actingAs($this->hr())
            ->get("/hr/employees/{$employee->id}")
            ->assertInertia(function (Assert $page) {
                $states = collect($page->toArray()['props']['employee']['data']['trainings'])
                    ->pluck('expiry_state', 'title');

                // Null is "does not expire", not "nobody typed it".
                $this->assertNull($states['Company orientation']);
                $this->assertSame('expired', $states['First Aid']);
                $this->assertSame('valid', $states['Forklift NC II']);
            });
    }

    public function test_the_same_skill_cannot_be_added_twice(): void
    {
        $employee = Employee::factory()->create();
        $employee->skills()->create(['name' => 'Forklift Operation']);

        // Different case and a stray space — the same skill, and a list
        // holding both is a list nobody can count.
        $this->actingAs($this->hr())
            ->post("/hr/employees/{$employee->id}/skill", ['name' => '  forklift   operation '])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('employee_skills', 1);
    }

    public function test_an_implausible_graduation_year_is_refused(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->hr())
            ->post("/hr/employees/{$employee->id}/education", [
                'level' => 'college',
                'school' => 'University of the East',
                'year_graduated' => 2109,
            ])
            ->assertSessionHasErrors('year_graduated');
    }

    public function test_an_expiry_before_the_completion_date_is_refused(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->hr())
            ->post("/hr/employees/{$employee->id}/training", [
                'title' => 'Forklift Operation NC II',
                'completed_at' => '2024-03-01',
                'expires_at' => '2023-03-01',
            ])
            ->assertSessionHasErrors('expires_at');
    }

    public function test_a_row_belonging_to_somebody_else_is_not_reachable(): void
    {
        $employee = Employee::factory()->create();
        $other = Employee::factory()->create();

        $skill = $other->skills()->create(['name' => 'Forklift Operation']);

        /*
         * Both ids are in the URL and Laravel binds them independently, so the
         * policy check passes on $employee while the row is $other's. Without
         * the ownership check, being allowed to edit one person is being
         * allowed to delete a row off anyone.
         */
        $this->actingAs($this->hr())
            ->delete("/hr/employees/{$employee->id}/skill/{$skill->id}")
            ->assertNotFound();

        $this->assertDatabaseCount('employee_skills', 1);
    }

    public function test_an_employee_cannot_edit_their_own_qualifications(): void
    {
        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        // Reading their own record is fine; writing to it is HR's, the same
        // line EmployeePolicy::update already draws for every other field.
        $this->actingAs($user)
            ->post("/hr/employees/{$employee->id}/skill", ['name' => 'Forklift Operation'])
            ->assertForbidden();
    }

    public function test_records_can_be_edited_and_removed(): void
    {
        $employee = Employee::factory()->create();
        $hr = $this->hr();

        $education = $employee->educations()->create([
            'level' => 'college',
            'school' => 'Univesity of the East',   // typo, which is the point
        ]);

        $this->actingAs($hr)
            ->put("/hr/employees/{$employee->id}/education/{$education->id}", [
                'level' => 'college',
                'school' => 'University of the East',
            ])
            ->assertRedirect();

        $this->assertSame('University of the East', $education->fresh()->school);

        $this->actingAs($hr)
            ->delete("/hr/employees/{$employee->id}/education/{$education->id}")
            ->assertRedirect();

        $this->assertDatabaseCount('employee_educations', 0);
    }

    public function test_qualification_rows_are_audited(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->hr())->post("/hr/employees/{$employee->id}/training", [
            'title' => 'Forklift Operation NC II',
        ]);

        // An attainment is a claim a deployment decision gets made on, so who
        // changed it matters as much as what it says.
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => EmployeeTraining::class,
            'event' => 'created',
        ]);
    }

    public function test_deleting_the_employee_takes_their_qualifications_with_them(): void
    {
        $employee = Employee::factory()->create();
        $employee->educations()->create(['level' => 'college', 'school' => 'UE']);
        $employee->trainings()->create(['title' => 'Defensive Driving']);
        $employee->skills()->create(['name' => 'Forklift']);

        // Not a soft delete — these hang off the employee row rather than
        // anchoring anything the way a payslip does.
        $employee->forceDelete();

        $this->assertSame(0, EmployeeEducation::count());
        $this->assertSame(0, EmployeeTraining::count());
        $this->assertSame(0, EmployeeSkill::count());
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }
}
