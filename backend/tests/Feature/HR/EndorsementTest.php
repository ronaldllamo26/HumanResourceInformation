<?php

namespace Tests\Feature\HR;

use App\Models\Client;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeEndorsement;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Core 1 → Core 2 handover, from this system's side.
 *
 * Core 1 recruits and this system employs, so nobody reaches the payroll
 * without somebody here approving them. What these assert is mostly the
 * *absence* of the shortcuts that would quietly undo that: no second creation
 * path, no re-deciding a closed endorsement, no salary set over the wire.
 */
class EndorsementTest extends TestCase
{
    use RefreshDatabase;

    /*
     * -----------------------------------------------------------------
     * The inbox
     * -----------------------------------------------------------------
     */

    public function test_hr_sees_pending_endorsements_by_default(): void
    {
        EmployeeEndorsement::factory()->count(2)->create();
        EmployeeEndorsement::factory()->approved()->create();

        $this->actingAs($this->hr())
            ->get('/hr/endorsements')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Endorsements/Index')
                // The inbox is a work queue: it opens on what is waiting,
                // not on every decision ever taken.
                ->has('endorsements.data', 2)
                ->where('statistics.pending', 2)
                ->where('statistics.approved', 1),
            );
    }

    public function test_the_summary_counts_the_whole_table_not_the_filtered_view(): void
    {
        EmployeeEndorsement::factory()->count(3)->create();
        EmployeeEndorsement::factory()->rejected()->count(2)->create();

        $this->actingAs($this->hr())
            ->get('/hr/endorsements?status=rejected')
            ->assertInertia(fn (Assert $page) => $page
                ->has('endorsements.data', 2)
                // A summary that moves while you filter is not a summary.
                ->where('statistics.pending', 3)
                ->where('statistics.rejected', 2),
            );
    }

    /**
     * The inbox carries the decision itself, not only a way into the review
     * screen — a queue whose whole job is answering has to be answerable from
     * where it is read.
     *
     * `can_decide` is per row rather than per screen, because the ability is
     * not only about the user: a decided endorsement is closed. Drawing
     * Approve on one would offer either a second employee from one
     * endorsement or an overwrite of who was recorded as approving the first.
     */
    public function test_the_list_says_which_rows_can_still_be_decided(): void
    {
        EmployeeEndorsement::factory()->create();
        EmployeeEndorsement::factory()->approved()->create();
        EmployeeEndorsement::factory()->rejected()->create();

        $this->actingAs($this->hr())
            ->get('/hr/endorsements?status=all')
            ->assertInertia(fn (Assert $page) => $page
                ->has('endorsements.data', 3)
                ->where(
                    'endorsements.data',
                    fn ($rows) => collect($rows)
                        ->groupBy('status')
                        ->map(fn ($group) => $group->first()['can_decide'])
                        ->all() === [
                            EmployeeEndorsement::STATUS_PENDING => true,
                            EmployeeEndorsement::STATUS_APPROVED => false,
                            EmployeeEndorsement::STATUS_REJECTED => false,
                        ],
                ),
            );
    }

    public function test_a_supervisor_is_never_offered_the_decision(): void
    {
        EmployeeEndorsement::factory()->create();

        // They cannot open the inbox at all, so the question is settled before
        // the column is reached — asserted so a future widening of `viewAny`
        // cannot quietly hand them the buttons too.
        $this->actingAs(User::factory()->role(User::ROLE_SUPERVISOR)->create())
            ->get('/hr/endorsements')
            ->assertForbidden();
    }

    public function test_paginator_meta_links_is_an_array(): void
    {
        EmployeeEndorsement::factory()->count(25)->create();

        $response = $this->actingAs($this->hr())->get('/hr/endorsements');
        $links = $response->viewData('page')['props']['endorsements']['meta']['links'];

        // Handing <Pagination> the {first,last,prev,next} object blanks the
        // page — the same trap the employee directory is guarded against.
        $this->assertIsArray($links);
        $this->assertGreaterThan(3, count($links));
    }

    public function test_supervisors_and_employees_cannot_open_the_inbox(): void
    {
        $endorsement = EmployeeEndorsement::factory()->create();

        foreach ([User::ROLE_SUPERVISOR, User::ROLE_EMPLOYEE] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->get('/hr/endorsements')->assertForbidden();
            $this->actingAs($user)->get("/hr/endorsements/{$endorsement->id}")->assertForbidden();
        }
    }

    /*
     * -----------------------------------------------------------------
     * Approving
     * -----------------------------------------------------------------
     */

    public function test_approving_creates_the_employee_and_closes_the_endorsement(): void
    {
        $endorsement = EmployeeEndorsement::factory()->create([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
        ]);
        $hr = $this->hr();

        $this->actingAs($hr)
            ->post('/hr/employees', $this->employeePayload([
                'endorsement_id' => $endorsement->id,
                'first_name' => 'Maria',
                'last_name' => 'Santos',
            ]))
            ->assertRedirect();

        $employee = Employee::where('last_name', 'Santos')->firstOrFail();

        $this->assertDatabaseHas('employee_endorsements', [
            'id' => $endorsement->id,
            'status' => EmployeeEndorsement::STATUS_APPROVED,
            'employee_id' => $employee->id,
            'decided_by' => $hr->id,
        ]);
    }

    public function test_without_an_endorsement_the_form_opens_as_a_direct_add(): void
    {
        $this->actingAs($this->hr())
            ->get('/hr/employees/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('HR/Employees/Create')
                ->where('endorsement', null));
    }

    /**
     * The direct door stays accountable: with no endorsement to record who
     * decided and why, the reason is required instead.
     */
    public function test_a_direct_add_without_a_reason_creates_nobody(): void
    {
        $this->actingAs($this->hr())
            ->post('/hr/employees', $this->employeePayload(['endorsement_id' => null]))
            ->assertSessionHasErrors('direct_hire_reason');

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_a_direct_add_with_a_reason_creates_the_employee_and_logs_why(): void
    {
        $hr = $this->hr();

        $this->actingAs($hr)
            ->post('/hr/employees', $this->employeePayload([
                'endorsement_id' => null,
                'direct_hire_reason' => 'Rehire of a former driver, approved by operations.',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $employee = Employee::firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'direct_hire',
            'user_id' => $hr->id,
            'auditable_id' => $employee->id,
        ]);
    }

    public function test_a_missing_endorsement_id_still_goes_back_to_the_inbox(): void
    {
        $this->actingAs($this->hr())
            ->post('/hr/employees', $this->employeePayload(['endorsement_id' => 999999]))
            ->assertRedirect('/hr/endorsements');

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_an_endorsement_cannot_be_approved_twice(): void
    {
        $endorsement = EmployeeEndorsement::factory()->approved()->create();

        // Two employees from one endorsement is two employee numbers and one
        // person on the payroll twice.
        $this->actingAs($this->hr())
            ->post('/hr/employees', $this->employeePayload([
                'endorsement_id' => $endorsement->id,
            ]))
            ->assertForbidden();

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_a_declined_endorsement_cannot_be_reopened_by_approving_it(): void
    {
        $endorsement = EmployeeEndorsement::factory()->rejected()->create();

        $this->actingAs($this->hr())
            ->get("/hr/employees/create?endorsement={$endorsement->id}")
            ->assertForbidden();
    }

    /*
     * -----------------------------------------------------------------
     * Declining
     * -----------------------------------------------------------------
     */

    public function test_declining_records_the_reason(): void
    {
        $endorsement = EmployeeEndorsement::factory()->create();
        $hr = $this->hr();

        $this->actingAs($hr)
            ->post("/hr/endorsements/{$endorsement->id}/reject", [
                'decision_note' => 'No LTO licence on file.',
            ])
            ->assertRedirect('/hr/endorsements');

        $this->assertDatabaseHas('employee_endorsements', [
            'id' => $endorsement->id,
            'status' => EmployeeEndorsement::STATUS_REJECTED,
            'decision_note' => 'No LTO licence on file.',
            'decided_by' => $hr->id,
        ]);

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_declining_requires_a_reason(): void
    {
        $endorsement = EmployeeEndorsement::factory()->create();

        // Core 1 reads this back. "Rejected" with no reason means the same
        // candidate is sent again.
        $this->actingAs($this->hr())
            ->post("/hr/endorsements/{$endorsement->id}/reject", ['decision_note' => ''])
            ->assertSessionHasErrors('decision_note');

        $this->assertSame(
            EmployeeEndorsement::STATUS_PENDING,
            $endorsement->fresh()->status,
        );
    }

    public function test_a_decided_endorsement_cannot_be_decided_again(): void
    {
        $endorsement = EmployeeEndorsement::factory()->approved()->create();

        $this->actingAs($this->hr())
            ->post("/hr/endorsements/{$endorsement->id}/reject", [
                'decision_note' => 'Changed my mind about this one.',
            ])
            ->assertForbidden();

        $this->assertSame(
            EmployeeEndorsement::STATUS_APPROVED,
            $endorsement->fresh()->status,
        );
    }

    /*
     * -----------------------------------------------------------------
     * Prefilling the form
     * -----------------------------------------------------------------
     */

    public function test_what_core_one_sent_is_filled_in_but_a_missing_key_is_not(): void
    {
        $endorsement = EmployeeEndorsement::factory()->create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'payload' => [
                'first_name' => 'Ana',
                'last_name' => 'Reyes',
                'sss_number' => '34-1234567-8',
                // No religion, and no empty string for it either.
            ],
        ]);

        $this->actingAs($this->hr())
            ->get("/hr/employees/create?endorsement={$endorsement->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Employees/Create')
                ->where('prefill.sss_number', '34-1234567-8')
                ->where('endorsement.reference', $endorsement->reference)
                // Absent rather than empty: spreading a blank over the form's
                // own default would erase it.
                ->missing('prefill.religion'),
            );
    }

    public function test_a_named_client_makes_the_hire_external(): void
    {
        $client = Client::create([
            'code' => 'MFL',
            'name' => 'Metro Fleet Logistics',
            'is_active' => true,
        ]);

        $endorsement = EmployeeEndorsement::factory()->create([
            'client_name' => 'metro fleet logistics',
        ]);

        // Prefilling the client while leaving the category at 'internal' would
        // hand back a form that refuses to save, with the error on a field the
        // reviewer never touched — client_id is *prohibited* on internal staff.
        $this->actingAs($this->hr())
            ->get("/hr/employees/create?endorsement={$endorsement->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('prefill.client_id', $client->id)
                ->where('prefill.employment_category', Employee::CATEGORY_EXTERNAL),
            );
    }

    public function test_an_unknown_position_is_left_for_hr_to_choose(): void
    {
        $endorsement = EmployeeEndorsement::factory()->create([
            'position_title' => 'Fleet Wizard',
        ]);

        // Inventing master data from a string is what the bulk importer
        // refuses to do, and another system is no more entitled to it.
        $this->actingAs($this->hr())
            ->get("/hr/employees/create?endorsement={$endorsement->id}")
            ->assertInertia(fn (Assert $page) => $page->missing('prefill.position_id'));

        $this->assertDatabaseMissing('positions', ['title' => 'Fleet Wizard']);
    }

    public function test_a_deactivated_position_is_not_matched(): void
    {
        $department = Department::create(['code' => 'OPS', 'name' => 'Operations', 'is_active' => true]);

        Position::create([
            'department_id' => $department->id,
            'code' => 'OPS-OLD',
            'title' => 'Retired Role',
            'is_active' => false,
        ]);

        $endorsement = EmployeeEndorsement::factory()->create(['position_title' => 'Retired Role']);

        // A deactivated row is kept so history keeps what it was filed under,
        // not so a new hire can be filed against it.
        $this->actingAs($this->hr())
            ->get("/hr/employees/create?endorsement={$endorsement->id}")
            ->assertInertia(fn (Assert $page) => $page->missing('prefill.position_id'));
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

    private function employeePayload(array $overrides = []): array
    {
        return array_merge([
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
}
