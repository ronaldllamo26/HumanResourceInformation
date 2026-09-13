<?php

namespace Tests\Feature\HR;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    /** Everything config asks for, so nothing should be flagged. */
    private const COMPLETE = ['contract', 'government_id', 'clearance', 'medical', 'resume'];

    public function test_a_complete_file_is_not_listed(): void
    {
        $employee = $this->employee();

        foreach (self::COMPLETE as $type) {
            $this->document($employee, $type);
        }

        $this->actingAs($this->hr())
            ->get('/hr/onboarding')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Employees/Onboarding')
                ->has('rows', 0)
                ->where('summary.incomplete', 0),
            );
    }

    public function test_a_missing_document_is_reported(): void
    {
        $employee = $this->employee();

        foreach (['government_id', 'clearance', 'medical', 'resume'] as $type) {
            $this->document($employee, $type);
        }

        $this->actingAs($this->hr())
            ->get('/hr/onboarding')
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.missing_count', 1)
                ->where('rows.0.missing.0.key', 'contract')
                ->where('rows.0.missing.0.blocking', true),
            );
    }

    public function test_a_missing_resume_does_not_stop_deployment(): void
    {
        $employee = $this->employee();

        foreach (['contract', 'government_id', 'clearance', 'medical'] as $type) {
            $this->document($employee, $type);
        }

        $this->actingAs($this->hr())
            ->get('/hr/onboarding')
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.missing.0.key', 'resume')
                ->where('rows.0.missing.0.blocking', false)
                ->where('rows.0.blocking', 0)
                ->where('summary.blocking', 0),
            );
    }

    /** A driver needs a licence; a dispatcher does not. */
    public function test_a_driver_additionally_needs_a_licence(): void
    {
        $driver = $this->employee(position: 'Fleet Driver');

        foreach (self::COMPLETE as $type) {
            $this->document($driver, $type);
        }

        $this->actingAs($this->hr())
            ->get('/hr/onboarding')
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.missing.0.key', 'drivers_license')
                ->where('rows.0.missing.0.blocking', true),
            );
    }

    public function test_a_non_driver_is_not_asked_for_a_licence(): void
    {
        $dispatcher = $this->employee(position: 'Dispatcher');

        foreach (self::COMPLETE as $type) {
            $this->document($dispatcher, $type);
        }

        $this->actingAs($this->hr())
            ->get('/hr/onboarding')
            ->assertInertia(fn (Assert $page) => $page->has('rows', 0));
    }

    public function test_a_missing_government_number_is_reported(): void
    {
        $employee = $this->employee(['tin' => null]);

        foreach (self::COMPLETE as $type) {
            $this->document($employee, $type);
        }

        $this->actingAs($this->hr())
            ->get('/hr/onboarding')
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.missing.0.kind', 'government_number')
                ->where('rows.0.missing.0.key', 'tin')
                // It doesn't stop the person working — it stops the filing.
                ->where('rows.0.missing.0.blocking', false)
                ->where('summary.missing_numbers', 1),
            );
    }

    public function test_blocking_gaps_sort_above_the_rest(): void
    {
        $tidy = $this->employee();
        foreach (['contract', 'government_id', 'clearance', 'medical'] as $type) {
            $this->document($tidy, $type); // only a résumé missing
        }

        $blocked = $this->employee();
        foreach (['government_id', 'clearance', 'medical', 'resume'] as $type) {
            $this->document($blocked, $type); // no contract
        }

        $this->actingAs($this->hr())
            ->get('/hr/onboarding')
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.employee_id', $blocked->id)
                ->where('rows.1.employee_id', $tidy->id),
            );
    }

    public function test_filtering_to_blocking_only_narrows_the_list(): void
    {
        $tidy = $this->employee();
        foreach (['contract', 'government_id', 'clearance', 'medical'] as $type) {
            $this->document($tidy, $type);
        }

        $blocked = $this->employee();
        foreach (['government_id', 'clearance', 'medical', 'resume'] as $type) {
            $this->document($blocked, $type);
        }

        $this->actingAs($this->hr())
            ->get('/hr/onboarding?blocking=1')
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.employee_id', $blocked->id)
                // The summary still describes everything, not just the view.
                ->where('summary.incomplete', 2),
            );
    }

    public function test_an_employee_sees_only_their_own_file(): void
    {
        $user = User::factory()->create();
        $own = $this->employee(['user_id' => $user->id]);
        $this->employee();

        $this->actingAs($user)
            ->get('/hr/onboarding')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.employee_id', $own->id),
            );
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/hr/onboarding')->assertRedirect('/login');
    }

    private function employee(array $attributes = [], string $position = 'Dispatcher'): Employee
    {
        $department = Department::firstOrCreate(
            ['code' => 'OPS'],
            ['name' => 'Operations'],
        );

        return Employee::factory()->create([
            'position_id' => Position::create([
                'title' => $position,
                'code' => strtoupper(substr(md5($position.uniqid()), 0, 8)),
                'department_id' => $department->id,
            ])->id,
            'department_id' => $department->id,
            'sss_number' => '34-1234567-8',
            'philhealth_number' => '12-345678901-2',
            'pagibig_number' => '1234-5678-9012',
            'tin' => '123-456-789-000',
            ...$attributes,
        ]);
    }

    private function document(Employee $employee, string $type): EmployeeDocument
    {
        return EmployeeDocument::create([
            'employee_id' => $employee->id,
            'type' => $type,
            'title' => ucfirst(str_replace('_', ' ', $type)),
            'file_path' => "documents/{$type}.pdf",
            'file_name' => "{$type}.pdf",
        ]);
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }
}
