<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeEndorsement;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_sees_pending_leave_to_decide(): void
    {
        $hr = User::factory()->hrStaff()->create();
        $leave = LeaveRequest::factory()->create(['status' => LeaveRequest::STATUS_PENDING]);

        $this->actingAs($hr)->getJson(route('notifications'))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0.key', "leave-{$leave->id}");
    }

    /** Nobody is asked to decide their own leave, so it is not a notification. */
    public function test_hr_is_not_notified_about_their_own_leave(): void
    {
        $hr = User::factory()->hrStaff()->create();
        $mine = Employee::factory()->create(['user_id' => $hr->id]);
        LeaveRequest::factory()->create(['employee_id' => $mine->id, 'status' => LeaveRequest::STATUS_PENDING]);

        $this->actingAs($hr)->getJson(route('notifications'))->assertJsonPath('count', 0);
    }

    public function test_hr_sees_new_hires_waiting(): void
    {
        $hr = User::factory()->hrStaff()->create();
        EmployeeEndorsement::factory()->count(2)->create();

        $keys = collect($this->actingAs($hr)->getJson(route('notifications'))->json('items'))->pluck('key');

        $this->assertContains('hires', $keys);
    }

    public function test_an_employee_sees_the_decision_on_their_own_leave_but_no_queues(): void
    {
        $user = User::factory()->create();
        $mine = Employee::factory()->create(['user_id' => $user->id]);
        LeaveRequest::factory()->create([
            'employee_id' => $mine->id,
            'status' => LeaveRequest::STATUS_APPROVED,
            'hr_acted_at' => now()->subDay(),
        ]);
        LeaveRequest::factory()->create(['status' => LeaveRequest::STATUS_PENDING]);
        EmployeeEndorsement::factory()->create();

        $response = $this->actingAs($user)->getJson(route('notifications'))->assertOk();

        $response->assertJsonPath('count', 0);
        $items = collect($response->json('items'));
        $this->assertSame(['decision'], $items->pluck('kind')->unique()->values()->all());
        $this->assertStringContainsString('approved', $items->first()['title']);
    }

    public function test_the_badge_count_is_shared_with_every_page(): void
    {
        $hr = User::factory()->hrStaff()->create();
        LeaveRequest::factory()->count(2)->create(['status' => LeaveRequest::STATUS_PENDING]);

        $this->actingAs($hr)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('notificationCount', 2));
    }

    public function test_guests_cannot_read_notifications(): void
    {
        $this->getJson(route('notifications'))->assertUnauthorized();
    }
}
