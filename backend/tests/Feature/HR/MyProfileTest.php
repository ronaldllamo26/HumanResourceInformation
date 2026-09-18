<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MyProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/hr/my-profile')
            ->assertRedirect('/login');
    }

    public function test_user_with_employee_can_view_my_profile(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $employee = Employee::factory()->create([
            'user_id' => $user->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
        ]);

        $this->actingAs($user)
            ->get('/hr/my-profile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Employees/Show')
                ->where('isMyProfile', true)
                ->where('employee.data.id', $employee->id)
                ->where('employee.data.first_name', 'Juan'),
            );
    }

    public function test_user_without_employee_is_redirected_to_dashboard_with_info(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($user)
            ->get('/hr/my-profile')
            ->assertRedirect('/dashboard')
            ->assertSessionHas('info');
    }

    public function test_user_with_unlinked_employee_matching_email_is_auto_linked_and_can_view_profile(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'email' => 'maria@primepower.test',
        ]);
        $employee = Employee::factory()->create([
            'user_id' => null,
            'email' => 'maria@primepower.test',
            'first_name' => 'Maria',
            'last_name' => 'Santos',
        ]);

        $this->actingAs($user)
            ->get('/hr/my-profile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Employees/Show')
                ->where('isMyProfile', true)
                ->where('employee.data.id', $employee->id)
                ->where('employee.data.first_name', 'Maria'),
            );

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_user_with_unlinked_employee_matching_otp_email_is_auto_linked_and_can_view_profile(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'email' => 'admin@primepower.test',
            'otp_email' => 'admin.personal@gmail.com',
        ]);
        $employee = Employee::factory()->create([
            'user_id' => null,
            'email' => 'admin.personal@gmail.com',
            'first_name' => 'John',
            'last_name' => 'Benavidez',
        ]);

        $this->actingAs($user)
            ->withSession([OtpService::SESSION_KEY => now()->timestamp])
            ->get('/hr/my-profile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Employees/Show')
                ->where('isMyProfile', true)
                ->where('employee.data.id', $employee->id)
                ->where('employee.data.first_name', 'John'),
            );

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'user_id' => $user->id,
        ]);
    }
}
