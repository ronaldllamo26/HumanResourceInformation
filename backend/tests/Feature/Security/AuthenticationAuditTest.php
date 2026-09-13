<?php

namespace Tests\Feature\Security;

use App\Listeners\RecordAuthenticationEvents;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The Auditable trait records what changed; this records who signed in. An
 * HRIS is asked both questions and used to be able to answer only the first.
 */
class AuthenticationAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_login_is_recorded(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery-1']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-1',
        ])->assertRedirect();

        $entry = $this->lastAuditOf(RecordAuthenticationEvents::EVENT_LOGIN);

        $this->assertNotNull($entry, 'A login should leave an audit entry.');
        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame($user->id, (int) $entry->auditable_id);
        $this->assertSame(User::class, $entry->auditable_type);
    }

    /**
     * Regression guard. The listener methods were once named `handle*`, which
     * is the prefix Laravel's own listener discovery scans `app/Listeners` for
     * — so every event was registered twice, by discovery and by the explicit
     * subscriber, and every sign-in was written to the log twice. Two rows for
     * one event is worse than none: it makes the log something you cannot
     * count from.
     */
    public function test_one_sign_in_writes_exactly_one_entry(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery-1']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-1',
        ])->assertRedirect();

        $this->assertSame(
            1,
            AuditLog::where('event', RecordAuthenticationEvents::EVENT_LOGIN)->count(),
            'The listener is registered more than once.',
        );
    }

    public function test_a_logout_is_recorded(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect();

        $entry = $this->lastAuditOf(RecordAuthenticationEvents::EVENT_LOGOUT);

        $this->assertNotNull($entry);
        $this->assertSame($user->id, (int) $entry->auditable_id);
    }

    /**
     * The address exists but the password was wrong — a user who mistyped, or
     * somebody working on a known account. The entry points at the account.
     */
    public function test_a_failed_login_against_a_real_account_names_that_account(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery-1']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password-entirely',
        ]);

        $entry = $this->lastAuditOf(RecordAuthenticationEvents::EVENT_FAILED);

        $this->assertNotNull($entry);
        $this->assertNull($entry->user_id, 'Nobody was authenticated, so there is no actor.');
        $this->assertSame($user->id, (int) $entry->auditable_id);
        $this->assertSame($user->email, $entry->new_values['email']);
    }

    /**
     * The regression this whole table change was for: an unknown address has
     * no user row to point at, and dropping the entry would hide exactly the
     * pattern worth seeing.
     */
    public function test_a_failed_login_against_an_unknown_address_is_still_recorded(): void
    {
        $this->post('/login', [
            'email' => 'not-a-user@example.test',
            'password' => 'whatever-they-tried',
        ]);

        $entry = $this->lastAuditOf(RecordAuthenticationEvents::EVENT_FAILED);

        $this->assertNotNull($entry);
        $this->assertNull($entry->auditable_id);
        $this->assertSame('not-a-user@example.test', $entry->new_values['email']);
    }

    public function test_the_attempted_password_is_never_recorded(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'sup3r-s3cret-guess',
        ]);

        $this->assertStringNotContainsString(
            'sup3r-s3cret-guess',
            AuditLog::query()->get()->toJson(),
        );
    }

    public function test_hitting_the_rate_limit_is_recorded(): void
    {
        $user = User::factory()->create();

        // The limiter trips on the sixth attempt for one email/IP pair.
        foreach (range(1, 6) as $ignored) {
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password-entirely',
            ]);
        }

        $this->assertNotNull(
            $this->lastAuditOf(RecordAuthenticationEvents::EVENT_LOCKOUT),
            'Tripping the login throttle should be audited, not just refused.',
        );
    }

    public function test_the_audit_records_where_the_attempt_came_from(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery-1']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-1',
        ]);

        $entry = $this->lastAuditOf(RecordAuthenticationEvents::EVENT_LOGIN);

        $this->assertNotNull($entry->ip_address, 'An audit entry with no origin answers half the question.');
    }

    // --- The Security screen's log ----------------------------------------

    /**
     * There are far more sign-ins than edits, and the log window is 50 rows.
     * Defaulting to everything would mean a busy morning's logins pushed every
     * record change out of sight — so changes are the default view.
     */
    public function test_the_audit_log_defaults_to_record_changes(): void
    {
        $admin = User::factory()->admin()->create();

        // Signing in is itself an auth entry, and the factory create above is
        // a record change.
        $this->actingAs($admin)
            ->get('/settings/security')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auditFilter', 'changes')
                ->where('auditLog', fn ($log) => collect($log)->every(
                    fn ($entry) => $entry['is_auth'] === false,
                )),
            );
    }

    public function test_the_audit_log_can_be_narrowed_to_sign_ins(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/logout');

        $this->actingAs($admin)
            ->get('/settings/security?audit=auth')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auditFilter', 'auth')
                ->where('auditLog', fn ($log) => count($log) > 0 && collect($log)->every(
                    fn ($entry) => $entry['is_auth'] === true,
                )),
            );
    }

    public function test_an_unknown_filter_falls_back_to_changes(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/settings/security?audit='.urlencode("'; drop table users; --"))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('auditFilter', 'changes'));
    }

    /** Non-HR roles never had the log and still do not. */
    public function test_the_log_stays_hidden_from_an_ordinary_employee(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/settings/security')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canViewAudit', false)
                ->where('auditLog', []),
            );
    }

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('');
    }

    private function lastAuditOf(string $event): ?AuditLog
    {
        return AuditLog::where('event', $event)->latest('id')->first();
    }
}
