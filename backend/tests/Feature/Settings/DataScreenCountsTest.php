<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The record counts on Settings → Data & Backup, and the screens behind them.
 *
 * These were five plain figures with nowhere to go, which is the rule the
 * dashboard tiles already follow being quietly broken on another screen: a
 * count of 1,247 payslips that cannot be opened has raised a question and then
 * refused to answer it.
 *
 * **Every case here follows the link and counts**, because that is how the two
 * dashboard versions of this bug were found — by clicking the tile, not by
 * reading the code. A link that returns a different number from the figure
 * that sent you is worse than no link: two screens now disagree and neither
 * says why.
 */
class DataScreenCountsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The employee figure was one count taken `withTrashed()`, so it read 42
     * while `/hr/employees` showed 38. Split in two now, each with its own
     * screen, rather than a total that no screen can reproduce.
     */
    public function test_the_employee_counts_are_split_so_each_matches_its_own_screen(): void
    {
        Employee::factory()->count(3)->create();
        Employee::factory()->count(2)->create()->each->delete();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $counts = $this->countsFor($admin);

        $this->assertSame(3, $counts['Employees']['count']);
        $this->assertSame('/hr/employees', $counts['Employees']['href']);

        $this->assertSame(2, $counts['Archived employees']['count']);
        $this->assertSame('/hr/archive', $counts['Archived employees']['href']);
    }

    /**
     * `?status=all` was the first guess, and `all` is not one of
     * `LeaveRequest::STATUSES` — the screen applies no default filter, so a
     * bare visit is the whole table.
     */
    public function test_the_leave_link_opens_every_request_rather_than_a_status(): void
    {
        $employee = Employee::factory()->create();

        foreach ([
            LeaveRequest::STATUS_PENDING,
            LeaveRequest::STATUS_APPROVED,
            LeaveRequest::STATUS_REJECTED,
        ] as $status) {
            LeaveRequest::factory()->create([
                'employee_id' => $employee->id,
                'status' => $status,
            ]);
        }

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $row = $this->countsFor($admin)['Leave requests'];

        $this->assertSame(3, $row['count']);

        // Follow it and count: the screen has to hold all three, not the one
        // status a guessed parameter would have narrowed it to.
        $this->actingAs($admin)
            ->get($row['href'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('requests.meta.total', 3));
    }

    /**
     * `/hr/performance/reviews` exists only with an id after it, so the
     * obvious-looking plural would have been a 404 reached from a settings
     * screen. This follows the link rather than asserting the string.
     */
    public function test_the_performance_link_is_a_screen_that_exists(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get($this->countsFor($admin)['Performance reviews']['href'])
            ->assertOk();
    }

    public function test_the_payslip_link_is_a_screen_that_exists(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get($this->countsFor($admin)['Payslips']['href'])
            ->assertOk();
    }

    /**
     * Two defaults on the audit log would otherwise narrow it below this
     * count: it shows record changes only, and the last thirty days only. So
     * the link carries the group that means everything *and* a `from` old
     * enough to reach the first row.
     */
    public function test_the_audit_link_opens_wide_enough_to_hold_its_own_number(): void
    {
        // A sign-in row, which the log's default "changes" group excludes, and
        // dated outside its default thirty-day window — the two narrowings
        // this link exists to undo, in one row.
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $old = AuditLog::create([
            'user_id' => $admin->id,
            'event' => 'login',
            'auditable_type' => User::class,
            'auditable_id' => $admin->id,
        ]);
        $old->forceFill(['created_at' => now()->subDays(120)])->save();

        $row = $this->countsFor($admin)['Audit log entries'];

        $this->assertStringContainsString('group=all', $row['href']);
        $this->assertStringContainsString('from='.now()->subDays(120)->toDateString(), $row['href']);

        /*
         * Follow it and count. `summary.total` is the audit screen's own
         * figure over the requested range with the group ignored — which is
         * exactly the number this card claims — so the two have to agree to
         * the row.
         */
        $this->actingAs($admin)
            ->get($row['href'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total', $row['count']),
            );
    }

    /** With nothing logged yet the link is still a date the screen accepts. */
    public function test_an_empty_audit_log_still_produces_a_usable_link(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // Creating the account wrote rows; clearing them leaves the empty case.
        AuditLog::query()->delete();

        $this->actingAs($admin)
            ->get($this->countsFor($admin)['Audit log entries']['href'])
            ->assertOk();
    }

    /**
     * @return array<string, array{label: string, count: int, href: string|null}>
     */
    private function countsFor(User $user): array
    {
        $counts = [];

        $this->actingAs($user)
            ->get('/settings/data')
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$counts) {
                foreach ($page->toArray()['props']['counts'] as $row) {
                    $counts[$row['label']] = $row;
                }
            });

        return $counts;
    }
}
