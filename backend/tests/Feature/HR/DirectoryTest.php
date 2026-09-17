<?php

namespace Tests\Feature\HR;

use App\Models\Client;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The org directory — who works here, arranged the way the company is.
 *
 * The screen is open to every signed-in user, which is a deliberate widening
 * of the HR directory's scoping. What makes it safe is the *field list*, so
 * that is what most of these assert: the widening and the narrowing are one
 * decision, and a test that only checked the widening would be checking the
 * dangerous half.
 */
class DirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_everyone_signed_in_can_open_it(): void
    {
        /*
         * A supervisor sees only their reports in the HR directory and an
         * employee sees only themselves — right for a screen carrying salary
         * and government numbers, useless for "who is in Operations".
         */
        foreach ([User::ROLE_ADMIN, User::ROLE_HR_STAFF, User::ROLE_SUPERVISOR, User::ROLE_EMPLOYEE] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get('/hr/directory')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('HR/Directory'));
        }
    }

    public function test_a_guest_cannot(): void
    {
        $this->get('/hr/directory')->assertRedirect('/login');
    }

    /**
     * The whole safety of the screen. Everything on a card is work-facing —
     * what somebody would read off a desk nameplate — and a field slipping in
     * here would be visible to the entire company at once.
     */
    public function test_it_never_carries_salary_government_numbers_or_addresses(): void
    {
        $this->workforce();

        $response = $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->get('/hr/directory')
            ->assertOk();

        $payload = json_encode($response->viewData('page')['props']);

        foreach ([
            '30000',              // basic_salary
            '34-1234567-8',       // sss_number
            '07-849963398-4',     // philhealth_number
            '481-946-366-987',    // tin
            '1990-05-14',         // birth_date
            '12 Mabini Street',   // present_address
            '0012 3456 7890',     // bank_account_number
        ] as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $payload,
                "the directory leaked \"{$secret}\" to a rank-and-file user",
            );
        }
    }

    public function test_the_card_carries_only_what_identifies_the_person(): void
    {
        $this->workforce();

        /*
         * The work contact and the posting used to be here, and have moved to
         * the record. What matters is that they left the *payload* rather than
         * only the markup: this screen is open to every signed-in user, and
         * what makes that safe is the server sending nothing but what everyone
         * may have. A key the page no longer renders but still ships is a leak
         * waiting for somebody to render it.
         */
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->get('/hr/directory')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('departments.0.positions.0.people.0.full_name', 'Juan Dela Cruz')
                ->has('departments.0.positions.0.people.0.employee_number')
                ->missing('departments.0.positions.0.people.0.email')
                ->missing('departments.0.positions.0.people.0.mobile_number')
                ->missing('departments.0.positions.0.people.0.client')
                ->missing('departments.0.positions.0.people.0.employment_category'),
            );
    }

    public function test_it_groups_department_then_position(): void
    {
        $this->workforce();

        // Somebody looking for "a driver" is walking down the org chart, not
        // searching a flat list of names.
        $this->actingAs($this->hr())
            ->get('/hr/directory')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('departments.0.name', 'Operations')
                ->where('departments.0.headcount', 2)
                ->where('departments.0.positions.0.title', 'Driver')
                ->has('departments.0.positions.0.people', 2),
            );
    }

    public function test_a_deployed_employee_shows_the_client_they_are_posted_to(): void
    {
        $client = Client::create(['code' => 'MFL', 'name' => 'Metro Fleet', 'is_active' => true]);
        $department = $this->department();

        Employee::factory()->create([
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'department_id' => $department->id,
            'employment_category' => Employee::CATEGORY_EXTERNAL,
            'client_id' => $client->id,
            'status' => 'active',
        ]);

        /*
         * A deployed employee is still listed under the department they are
         * filed against — the directory is the org chart, and being posted to
         * a client does not take somebody out of it.
         *
         * Where they are posted is no longer on the row. It is a fact about
         * the person rather than about the shape of the company, so it lives
         * on their record with the rest of what is known about them.
         */
        $this->actingAs($this->hr())
            ->get('/hr/directory')
            ->assertInertia(fn (Assert $page) => $page
                ->where('departments.0.headcount', 1)
                ->has('departments.0.positions.0.people', 1)
                ->missing('departments.0.positions.0.people.0.client'),
            );
    }

    public function test_somebody_with_no_department_is_still_listed(): void
    {
        Employee::factory()->create([
            'first_name' => 'Unfiled',
            // The factory issues a middle initial, which `full_name` folds in.
            'middle_name' => null,
            'last_name' => 'Person',
            'suffix' => null,
            'department_id' => null,
            'status' => 'active',
        ]);

        /*
         * A new hire filed before their department was decided is still
         * somebody a colleague may need to reach. Dropping them would make the
         * directory quietly wrong rather than visibly incomplete.
         */
        $this->actingAs($this->hr())
            ->get('/hr/directory')
            ->assertInertia(fn (Assert $page) => $page
                ->where('unassigned.headcount', 1)
                ->where('unassigned.positions.0.people.0.full_name', 'Unfiled Person'),
            );
    }

    public function test_separated_staff_are_not_listed(): void
    {
        $department = $this->department();

        Employee::factory()->create([
            'department_id' => $department->id,
            'status' => 'inactive',
        ]);

        // A directory is for reaching people who are here. Somebody who has
        // left is a record, not a colleague.
        $this->actingAs($this->hr())
            ->get('/hr/directory')
            ->assertInertia(fn (Assert $page) => $page->where('total', 0));
    }

    /*
     * -----------------------------------------------------------------
     * Following a row into the record
     * -----------------------------------------------------------------
     */

    public function test_hr_may_open_anybody_from_the_directory(): void
    {
        $this->workforce();

        $this->actingAs($this->hr())
            ->get('/hr/directory')
            ->assertInertia(fn (Assert $page) => $page
                ->where('departments.0.positions.0.people.0.can_view', true),
            );
    }

    public function test_a_rank_and_file_user_cannot_open_a_stranger(): void
    {
        $this->workforce();

        /*
         * The row is a link only for somebody who may follow it. Drawing one
         * that 403s is worse than drawing none: it says there is something
         * behind it *and* that they are not trusted with it.
         */
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->get('/hr/directory')
            ->assertInertia(fn (Assert $page) => $page
                ->where('departments.0.positions.0.people.0.can_view', false),
            );
    }

    public function test_credentials_are_shown_only_to_somebody_who_may_open_the_file(): void
    {
        $this->workforce();
        $employee = Employee::where('last_name', 'Dela Cruz')->firstOrFail();

        // A lapsed licence: the hard flag, because that driver may not
        // lawfully be dispatched.
        $employee->documents()->create([
            'type' => 'drivers_license',
            'title' => "Driver's Licence",
            'file_path' => 'documents/x.pdf',
            'file_name' => 'x.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'expires_at' => now()->subMonth(),
            'uploaded_by' => $this->hr()->id,
        ]);

        // HR may already open the 201 file, so the summary of it is theirs.
        $this->actingAs($this->hr())
            ->get('/hr/directory')
            ->assertInertia(fn (Assert $page) => $page
                ->where('departments.0.positions.0.people.0.credentials.expired', 1)
                ->where('departments.0.positions.0.people.0.credentials.blocking', true),
            );

        /*
         * Document data belongs to the same gate the record does, not to the
         * directory's open one. Null, and the component draws nothing.
         */
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->get('/hr/directory')
            ->assertInertia(fn (Assert $page) => $page
                ->where('departments.0.positions.0.people.0.credentials', null),
            );
    }

    public function test_search_narrows_to_the_person_being_looked_for(): void
    {
        $this->workforce();

        $this->actingAs($this->hr())
            ->get('/hr/directory?search=Juan')
            ->assertInertia(fn (Assert $page) => $page->where('total', 1));
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

    private function department(): Department
    {
        return Department::create([
            'code' => 'OPS',
            'name' => 'Operations',
            'is_active' => true,
        ]);
    }

    /** One department, one position, two drivers — one of them fully filled in. */
    private function workforce(): void
    {
        $department = $this->department();

        $position = Position::create([
            'department_id' => $department->id,
            'code' => 'OPS-DRV',
            'title' => 'Driver',
            'is_active' => true,
        ]);

        Employee::factory()->create([
            'first_name' => 'Juan',
            'middle_name' => null,
            'last_name' => 'Dela Cruz',
            'suffix' => null,
            'department_id' => $department->id,
            'position_id' => $position->id,
            'status' => 'active',
            'email' => 'juan@primepower.com',
            'mobile_number' => '09171234567',

            // Everything below must never reach the page.
            'basic_salary' => 30000,
            'sss_number' => '34-1234567-8',
            'philhealth_number' => '07-849963398-4',
            'tin' => '481-946-366-987',
            'birth_date' => '1990-05-14',
            'present_address' => '12 Mabini Street, Quezon City',
            'bank_account_number' => '0012 3456 7890',
        ]);

        Employee::factory()->create([
            'first_name' => 'Pedro',
            // Fixed, not Faker's: a random middle name or email containing
            // "juan" made the search test fail now and then.
            'middle_name' => null,
            'email' => 'pedro@primepower.com',
            'last_name' => 'Santos',
            'department_id' => $department->id,
            'position_id' => $position->id,
            'status' => 'active',
        ]);
    }
}
