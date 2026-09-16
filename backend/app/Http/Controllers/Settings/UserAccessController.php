<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Listeners\RecordAuthenticationEvents;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Login accounts and what each one may do.
 *
 * Self-registration is disabled, so this and the employee form are the only two
 * places an account comes into existence.
 */
class UserAccessController extends Controller
{
    /** An active account unused for this long is flagged on the list. */
    private const STALE_AFTER_DAYS = 90;

    /** How often access should be reviewed — quarterly. */
    private const REVIEW_EVERY_DAYS = 90;

    public function index(Request $request): Response
    {
        Gate::authorize('manageUsers', Setting::class);

        $lastSignIns = $this->lastSignIns();
        $staleBefore = now()->subDays(self::STALE_AFTER_DAYS);

        return Inertia::render('Settings/Users', [
            'users' => User::with('employee:id,user_id,employee_number')
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => [
                    'last_sign_in_at' => $lastSignIns->get($user->id),
                    // Switched on but not used in three months: an account
                    // nobody is watching is the one somebody else can use.
                    'is_stale' => $user->is_active
                        && ($lastSignIns->has($user->id)
                            ? $lastSignIns->get($user->id) < $staleBefore->toIso8601String()
                            : $user->created_at?->lt($staleBefore)),
                    'is_privileged' => $user->isHrAdmin(),
                    'id' => $user->id,
                    'name' => $user->name,
                    // What the person signs in with, so the admin can tell them.
                    'username' => $user->username,
                    'email' => $user->email,
                    'role' => $user->role,
                    'is_active' => (bool) $user->is_active,
                    'employee_number' => $user->employee?->employee_number,
                    'has_employee_record' => $user->employee !== null,
                    'is_self' => $user->id === $request->user()->id,
                    'tokens' => $user->tokens()->count(),
                    'created_at' => $user->created_at?->toDateString(),
                ]),

            'roles' => [
                ['value' => User::ROLE_ADMIN, 'label' => 'Administrator', 'description' => 'Full access, including payroll approval and settings.'],
                ['value' => User::ROLE_HR_STAFF, 'label' => 'HR Staff', 'description' => 'Runs every module; cannot approve payroll or change settings.'],
                ['value' => User::ROLE_SUPERVISOR, 'label' => 'Supervisor', 'description' => 'Own record plus direct reports; endorses leave and overtime.'],
                ['value' => User::ROLE_EMPLOYEE, 'label' => 'Employee', 'description' => 'Own record, payslips, and filings only.'],
            ],

            'accessReview' => $this->lastAccessReview(),
            'staleAfterDays' => self::STALE_AFTER_DAYS,

            // Employees who could be given a login but do not have one yet.
            'unlinkedEmployees' => Employee::whereNull('user_id')
                ->whereNotNull('email')
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'email'])
                ->map(fn (Employee $employee) => [
                    'id' => $employee->id,
                    'full_name' => $employee->full_name,
                    'email' => $employee->email,
                ]),
        ]);
    }

    /**
     * Records that somebody went through the account list and confirmed who
     * should still have access.
     *
     * Roles are not self-correcting: a person moves out of HR, a temporary
     * admin is never demoted, a leaver's account outlives the leaver. A review
     * is the control that catches what de-provisioning missed, and recording
     * it — who, when, what they looked at — is what makes it evidence rather
     * than a habit.
     */
    public function review(Request $request): RedirectResponse
    {
        Gate::authorize('manageUsers', Setting::class);

        $lastSignIns = $this->lastSignIns();
        $staleBefore = now()->subDays(self::STALE_AFTER_DAYS)->toIso8601String();
        $active = User::where('is_active', true)->get();

        AuditLog::create([
            'user_id' => $request->user()->id,
            'auditable_type' => User::class,
            'auditable_id' => null,
            'event' => 'access_reviewed',
            'new_values' => [
                'active_accounts' => $active->count(),
                'privileged_accounts' => $active->filter->isHrAdmin()->count(),
                'stale_accounts' => $active->filter(
                    fn (User $user) => ($lastSignIns->get($user->id) ?? '') < $staleBefore,
                )->count(),
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return back()->with('success', 'Access review recorded.');
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manageUsers', Setting::class);

        $validated = $request->validate([
            'employee_id' => ['nullable', 'exists:employees,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(User::ROLES)],
        ]);

        // Handed to the administrator once; the account holder changes it after.
        $password = User::generatePassword();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'password' => $password,
            'is_active' => true,
            'email_verified_at' => now(),
            // See RequirePasswordChange: a password the administrator has read
            // is not the account holder's password yet.
            'must_change_password' => true,
        ]);

        if ($validated['employee_id'] ?? null) {
            Employee::whereKey($validated['employee_id'])->update(['user_id' => $user->id]);
        }

        return back()->with('success', "Account created. Username: {$user->username} / temporary password: {$password}");
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageUsers', Setting::class);

        $validated = $request->validate([
            'role' => ['required', Rule::in(User::ROLES)],
        ]);

        // Losing your own administrator rights would lock you out of this page.
        if ($user->id === $request->user()->id && $validated['role'] !== User::ROLE_ADMIN) {
            return back()->with('error', 'You cannot remove your own administrator role.');
        }

        $user->update(['role' => $validated['role']]);

        return back()->with('success', "{$user->name} is now {$validated['role']}.");
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageUsers', Setting::class);

        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        $user->update(['is_active' => ! $user->is_active]);

        // A deactivated account should not keep working through the API.
        if (! $user->is_active) {
            $user->tokens()->delete();
        }

        return back()->with(
            'success',
            $user->is_active ? "{$user->name} reactivated." : "{$user->name} deactivated and API tokens revoked.",
        );
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageUsers', Setting::class);

        $password = User::generatePassword();

        $user->update([
            'password' => $password,
            'must_change_password' => true,
        ]);
        $user->tokens()->delete();

        return back()->with('success', "New password for {$user->username}: {$password}");
    }

    /** @return Collection<int, string> user id => ISO time of their latest sign-in */
    private function lastSignIns()
    {
        return AuditLog::query()
            ->where('event', RecordAuthenticationEvents::EVENT_LOGIN)
            ->where('auditable_type', User::class)
            ->whereNotNull('auditable_id')
            ->groupBy('auditable_id')
            ->selectRaw('auditable_id, max(created_at) as last_at')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->auditable_id => Carbon::parse($row->last_at)->toIso8601String()]);
    }

    /** @return array{at: string|null, by: string|null, due: bool} */
    private function lastAccessReview(): array
    {
        $last = AuditLog::with('user:id,name')
            ->where('event', 'access_reviewed')
            ->latest('id')
            ->first();

        return [
            'at' => $last?->created_at?->toIso8601String(),
            'by' => $last?->user?->name,
            'due' => $last === null || $last->created_at->lt(now()->subDays(self::REVIEW_EVERY_DAYS)),
        ];
    }
}
