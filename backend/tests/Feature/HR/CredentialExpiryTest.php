<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\CredentialExpiryScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CredentialExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_licence_inside_its_window_is_reported(): void
    {
        // Driver's licences warn 60 days out.
        $this->document(expiresInDays: 30, type: 'drivers_license');

        $this->actingAs($this->hr())
            ->get('/hr/credentials')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Employees/Credentials')
                ->has('credentials', 1)
                ->where('credentials.0.status', CredentialExpiryScanner::STATUS_EXPIRING)
                ->where('credentials.0.days_remaining', 30)
                ->where('credentials.0.blocking', true),
            );
    }

    public function test_a_licence_beyond_its_window_is_left_alone(): void
    {
        $this->document(expiresInDays: 120, type: 'drivers_license');

        $this->actingAs($this->hr())
            ->get('/hr/credentials')
            ->assertInertia(fn (Assert $page) => $page->has('credentials', 0));
    }

    public function test_each_type_gets_its_own_lead_time(): void
    {
        // 45 days out: inside the licence window (60), outside a certificate's (30).
        $this->document(expiresInDays: 45, type: 'drivers_license');
        $this->document(expiresInDays: 45, type: 'certificate');

        $this->actingAs($this->hr())
            ->get('/hr/credentials')
            ->assertInertia(fn (Assert $page) => $page
                ->has('credentials', 1)
                ->where('credentials.0.type', 'drivers_license'),
            );
    }

    public function test_an_expired_document_is_reported_as_overdue(): void
    {
        $this->document(expiresInDays: -10, type: 'medical');

        $this->actingAs($this->hr())
            ->get('/hr/credentials')
            ->assertInertia(fn (Assert $page) => $page
                ->where('credentials.0.status', CredentialExpiryScanner::STATUS_EXPIRED)
                ->where('credentials.0.days_remaining', -10),
            );
    }

    public function test_expired_documents_sort_above_merely_expiring_ones(): void
    {
        $this->document(expiresInDays: 5, type: 'medical');
        $this->document(expiresInDays: -1, type: 'medical');

        $this->actingAs($this->hr())
            ->get('/hr/credentials')
            ->assertInertia(fn (Assert $page) => $page
                ->where('credentials.0.status', CredentialExpiryScanner::STATUS_EXPIRED)
                ->where('credentials.1.status', CredentialExpiryScanner::STATUS_EXPIRING),
            );
    }

    public function test_a_document_with_no_expiry_is_never_reported(): void
    {
        // A resume does not lapse.
        $this->document(expiresInDays: null, type: 'resume');

        $this->actingAs($this->hr())
            ->get('/hr/credentials')
            ->assertInertia(fn (Assert $page) => $page->has('credentials', 0));
    }

    public function test_a_non_blocking_document_is_not_flagged_as_stopping_work(): void
    {
        $this->document(expiresInDays: 10, type: 'certificate');

        $this->actingAs($this->hr())
            ->get('/hr/credentials')
            ->assertInertia(fn (Assert $page) => $page
                ->where('credentials.0.blocking', false)
                ->where('summary.blocking', 0),
            );
    }

    public function test_the_summary_separates_expired_from_expiring(): void
    {
        $this->document(expiresInDays: -3, type: 'medical');
        $this->document(expiresInDays: 10, type: 'medical');
        $this->document(expiresInDays: 10, type: 'certificate');

        $this->actingAs($this->hr())
            ->get('/hr/credentials')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total', 3)
                ->where('summary.expired', 1)
                ->where('summary.expiring', 2)
                ->where('summary.blocking', 2),
            );
    }

    public function test_filtering_by_status_narrows_the_list(): void
    {
        $this->document(expiresInDays: -3, type: 'medical');
        $this->document(expiresInDays: 10, type: 'medical');

        $this->actingAs($this->hr())
            ->get('/hr/credentials?status='.CredentialExpiryScanner::STATUS_EXPIRED)
            ->assertInertia(fn (Assert $page) => $page
                ->has('credentials', 1)
                // The summary still describes everything, not just the view.
                ->where('summary.total', 2),
            );
    }

    public function test_an_employee_sees_only_their_own_credentials(): void
    {
        $user = User::factory()->create();
        $own = Employee::factory()->create(['user_id' => $user->id]);

        $this->document(expiresInDays: 10, type: 'medical', employee: $own);
        $this->document(expiresInDays: 10, type: 'medical');

        $this->actingAs($user)
            ->get('/hr/credentials')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('credentials', 1)
                ->where('credentials.0.employee_id', $own->id),
            );
    }

    public function test_the_topbar_count_matches_what_the_user_may_see(): void
    {
        $user = User::factory()->create();
        $own = Employee::factory()->create(['user_id' => $user->id]);

        $this->document(expiresInDays: 10, type: 'medical', employee: $own);
        $this->document(expiresInDays: 10, type: 'medical');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('expiringCredentials', 1));

        $this->actingAs($this->hr())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('expiringCredentials', 2));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/hr/credentials')->assertRedirect('/login');
    }

    private function document(
        ?int $expiresInDays,
        string $type,
        ?Employee $employee = null,
    ): EmployeeDocument {
        return EmployeeDocument::create([
            'employee_id' => ($employee ?? Employee::factory()->create())->id,
            'type' => $type,
            'title' => ucfirst(str_replace('_', ' ', $type)),
            'file_path' => "documents/{$type}.pdf",
            'file_name' => "{$type}.pdf",
            'expires_at' => $expiresInDays === null
                ? null
                : now()->addDays($expiresInDays)->toDateString(),
        ]);
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }
}
