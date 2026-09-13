<?php

namespace Tests\Feature\Api;

use App\Models\EmployeeEndorsement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Core 1's end of the handover — what it may send, and what it may not.
 *
 * The interesting assertions here are the refusals. This endpoint is the only
 * place another system reaches into this one's workforce, and the fields it is
 * *not* allowed to set are what keep the approval on this side meaningful.
 */
class EndorsementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_one_can_submit_a_hire(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $this->postJson('/api/v1/endorsements', [
            'reference' => 'C1-2026-ABC123',
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria.santos@example.com',
            'position_title' => 'Driver',
            'date_hired' => '2026-10-01',
        ])
            ->assertCreated()
            ->assertJsonPath('data.reference', 'C1-2026-ABC123')
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('employee_endorsements', [
            'reference' => 'C1-2026-ABC123',
            'last_name' => 'Santos',
            'status' => 'pending',
        ]);

        // Submitting is not hiring. Nobody is on the payroll until somebody
        // in this system approves them.
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_a_resend_returns_the_same_row_instead_of_duplicating_it(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $body = [
            'reference' => 'C1-2026-RETRY',
            'first_name' => 'Jose',
            'last_name' => 'Rizal',
        ];

        $this->postJson('/api/v1/endorsements', $body)->assertCreated();

        /*
         * A timeout on Core 1's side is indistinguishable from a failure, so
         * they will send it again. 200 rather than 201 lets them tell a fresh
         * submission from a duplicate without either being an error.
         */
        $this->postJson('/api/v1/endorsements', $body)->assertOk();

        $this->assertDatabaseCount('employee_endorsements', 1);
    }

    public function test_a_resend_after_a_decision_does_not_reopen_it(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $endorsement = EmployeeEndorsement::factory()->rejected()->create([
            'reference' => 'C1-2026-CLOSED',
        ]);

        $this->postJson('/api/v1/endorsements', [
            'reference' => 'C1-2026-CLOSED',
            'first_name' => 'Jose',
            'last_name' => 'Rizal',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        // An answer already given is not undone by the sender repeating the
        // question.
        $this->assertSame('rejected', $endorsement->fresh()->status);
    }

    public function test_core_one_cannot_set_the_salary_or_the_client_it_is_billed_to(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $this->postJson('/api/v1/endorsements', [
            'reference' => 'C1-2026-MONEY',
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'basic_salary' => 999999,
            'client_id' => 1,
            'employment_category' => 'external',
        ])->assertCreated();

        $endorsement = EmployeeEndorsement::where('reference', 'C1-2026-MONEY')->firstOrFail();

        /*
         * Dropped, not stored-and-ignored. These decide what PrimePower pays
         * and who it bills, and an endorsement that carried them would be one
         * approval away from another system setting both.
         */
        $this->assertArrayNotHasKey('basic_salary', $endorsement->payload);
        $this->assertArrayNotHasKey('client_id', $endorsement->payload);
        $this->assertArrayNotHasKey('employment_category', $endorsement->payload);
    }

    public function test_a_reference_is_required(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        $this->postJson('/api/v1/endorsements', [
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
        ])->assertStatus(422)->assertJsonValidationErrors('reference');
    }

    public function test_only_a_name_is_required_beyond_the_reference(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        /*
         * Refusing to *receive* somebody is the one outcome this queue exists
         * to avoid: a recruitment system that cannot hand a candidate over
         * because it does not know this system's pay frequency has been locked
         * out, and the workaround is re-typing the record by hand.
         */
        $this->postJson('/api/v1/endorsements', [
            'reference' => 'C1-2026-BARE',
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
        ])->assertCreated();
    }

    public function test_core_one_can_read_the_outcome_back_by_its_own_reference(): void
    {
        Sanctum::actingAs(User::factory()->hrStaff()->create());

        EmployeeEndorsement::factory()->rejected()->create([
            'reference' => 'C1-2026-DECLINED',
            'decision_note' => 'No LTO licence on file.',
        ]);

        $this->getJson('/api/v1/endorsements/C1-2026-DECLINED')
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.decision_note', 'No LTO licence on file.');
    }

    public function test_a_rank_and_file_token_cannot_queue_hires(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]));

        // Submitting creates nothing, but a queue anybody can fill is one
        // people stop reading.
        $this->postJson('/api/v1/endorsements', [
            'reference' => 'C1-2026-NOPE',
            'first_name' => 'Sneaky',
            'last_name' => 'User',
        ])->assertForbidden();

        $this->assertDatabaseCount('employee_endorsements', 0);
    }

    public function test_the_endpoint_is_closed_to_anonymous_callers(): void
    {
        $this->postJson('/api/v1/endorsements', [
            'reference' => 'C1-2026-ANON',
            'first_name' => 'Anon',
            'last_name' => 'Ymous',
        ])->assertUnauthorized();
    }
}
