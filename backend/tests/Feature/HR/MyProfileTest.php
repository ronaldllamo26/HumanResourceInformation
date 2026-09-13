<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\User;
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
}
