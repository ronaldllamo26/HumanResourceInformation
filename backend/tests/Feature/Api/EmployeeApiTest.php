<?php

namespace Tests\Feature\Api;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_credentials_return_a_token(): void
    {
        $user = User::factory()->hrStaff()->create();

        $this->postJson('/api/v1/login', [
            'username' => $user->username,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'username', 'role']]);
    }

    /** Other ISMERS systems already call this with an email. */
    public function test_an_email_is_still_accepted_where_the_account_has_one(): void
    {
        $user = User::factory()->hrStaff()->create(['email' => 'integration@primepower.com']);

        $this->postJson('/api/v1/login', [
            'email' => 'integration@primepower.com',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_a_login_needs_a_username_or_an_email(): void
    {
        $this->postJson('/api/v1/login', ['password' => 'password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['username', 'email']);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/login', [
            'username' => $user->username,
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_a_deactivated_account_cannot_obtain_a_token(): void
    {
        $user = User::factory()->inactive()->create();

        $this->postJson('/api/v1/login', [
            'username' => $user->username,
            'password' => 'password',
        ])->assertStatus(422);
    }

    public function test_endpoints_require_a_token(): void
    {
        Employee::factory()->create();

        $this->getJson('/api/v1/employees')->assertUnauthorized();
        $this->getJson('/api/v1/employees/statistics')->assertUnauthorized();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_the_employee_list_is_paginated(): void
    {
        Employee::factory()->count(25)->create();

        $this->withHeaders($this->auth(User::factory()->hrStaff()->create()))
            ->getJson('/api/v1/employees?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonStructure(['data' => [['id', 'employee_number', 'full_name']], 'links', 'meta']);
    }

    public function test_the_list_can_be_searched(): void
    {
        Employee::factory()->create(['last_name' => 'Villanueva']);
        Employee::factory()->count(3)->create();

        $this->withHeaders($this->auth(User::factory()->hrStaff()->create()))
            ->getJson('/api/v1/employees?search=Villanueva')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_statistics_are_returned(): void
    {
        Employee::factory()->count(3)->create(['status' => 'active']);
        Employee::factory()->onLeave()->create();

        $this->withHeaders($this->auth(User::factory()->hrStaff()->create()))
            ->getJson('/api/v1/employees/statistics')
            ->assertOk()
            ->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.active', 3)
            ->assertJsonPath('data.on_leave', 1);
    }

    public function test_hr_can_create_an_employee_over_the_api(): void
    {
        $this->withHeaders($this->auth(User::factory()->hrStaff()->create()))
            ->postJson('/api/v1/employees', [
                'first_name' => 'Ana',
                'last_name' => 'Reyes',
                'employment_category' => 'internal',
                'employment_status' => 'regular',
                'employment_type' => 'full_time',
                'date_hired' => '2026-03-01',
                'basic_salary' => 30000,
                'pay_frequency' => 'semi_monthly',
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.full_name', 'Ana Reyes');

        $this->assertDatabaseHas('employees', ['first_name' => 'Ana', 'last_name' => 'Reyes']);
    }

    public function test_an_employee_role_cannot_create_over_the_api(): void
    {
        $user = User::factory()->create();
        Employee::factory()->create(['user_id' => $user->id]);

        $this->withHeaders($this->auth($user))
            ->postJson('/api/v1/employees', ['first_name' => 'Nope', 'last_name' => 'Nope'])
            ->assertForbidden();
    }

    public function test_api_scoping_matches_the_web_ui(): void
    {
        $user = User::factory()->create();
        Employee::factory()->create(['user_id' => $user->id]);
        Employee::factory()->count(4)->create();

        $this->withHeaders($this->auth($user))
            ->getJson('/api/v1/employees')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_sensitive_fields_are_withheld_from_unauthorized_viewers(): void
    {
        $user = User::factory()->role(User::ROLE_SUPERVISOR)->create();
        $supervisorRecord = Employee::factory()->create(['user_id' => $user->id]);
        $report = Employee::factory()->create(['supervisor_id' => $supervisorRecord->id]);

        $this->withHeaders($this->auth($user))
            ->getJson("/api/v1/employees/{$report->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.basic_salary')
            ->assertJsonMissingPath('data.sss_number');
    }

    public function test_an_admin_can_archive_and_restore_an_employee(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = Employee::factory()->create();
        $headers = $this->auth($admin);

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/employees/{$employee->id}")
            ->assertOk();

        $this->assertSoftDeleted('employees', ['id' => $employee->id]);

        $this->withHeaders($headers)
            ->postJson("/api/v1/employees/{$employee->id}/restore")
            ->assertOk();

        $this->assertNotSoftDeleted('employees', ['id' => $employee->id]);
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = User::factory()->hrStaff()->create();
        $headers = $this->auth($user);

        $this->withHeaders($headers)->postJson('/api/v1/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // The guard caches the resolved user for the rest of the test process,
        // so it has to be dropped before the revoked token is re-checked.
        $this->app['auth']->forgetGuards();

        $this->withHeaders($headers)->getJson('/api/v1/me')->assertUnauthorized();
    }

    private function tokenFor(User $user): string
    {
        return $this->postJson('/api/v1/login', [
            'username' => $user->username,
            'password' => 'password',
            'device_name' => 'phpunit',
        ])->assertOk()->json('token');
    }

    /** @return array<string, string> */
    private function auth(User $user): array
    {
        return ['Authorization' => 'Bearer '.$this->tokenFor($user)];
    }
}
