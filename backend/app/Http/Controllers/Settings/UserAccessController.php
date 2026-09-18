<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Listeners\RecordAuthenticationEvents;
use App\Models\AccountChangeRequest;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AccountProvisioned;
use App\Services\OtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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

    public function __construct(private readonly OtpService $otp) {}

    public function index(Request $request): Response
    {
        Gate::authorize('manageUsers', Setting::class);

        $isSuperAdmin = $request->user()->isSuperAdmin();
        $canManageRequests = $request->user()->can('manageAccountRequests', Setting::class);
        $canViewPasswords = $request->user()->can('viewStaffPasswords', Setting::class);
        $lastSignIns = $this->lastSignIns();
        $staleBefore = now()->subDays(self::STALE_AFTER_DAYS);

        $changeRequests = $canManageRequests
            ? AccountChangeRequest::with(['user:id,name,username,otp_email', 'decider:id,name'])
                ->latest()
                ->get()
                ->map(fn (AccountChangeRequest $r) => [
                    'id' => $r->id,
                    'user_id' => $r->user_id,
                    'staff_name' => $r->user?->name ?? 'Unknown Staff',
                    'current_username' => $r->current_username,
                    'requested_username' => $r->requested_username,
                    'current_email' => $r->current_email,
                    'requested_email' => $r->requested_email,
                    'staff_notes' => $r->staff_notes,
                    'status' => $r->status,
                    'decided_by' => $r->decider?->name,
                    'decided_at' => $r->decided_at?->toIso8601String(),
                    'admin_notes' => $r->admin_notes,
                    'created_at' => $r->created_at?->toIso8601String(),
                ])
            : [];

        return Inertia::render('Settings/Users', [
            'is_super_admin' => $isSuperAdmin,
            'can_manage_requests' => $canManageRequests,
            'can_view_passwords' => $canViewPasswords,
            'change_requests' => $changeRequests,
            'users' => User::with('employee:id,user_id,employee_number,first_name,middle_name,last_name,suffix')
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
                    'role' => $user->role,
                    'is_active' => (bool) $user->is_active,
                    'employee_number' => $user->employee?->employee_number,
                    // Offered in the edit form, and compared with after the
                    // save: `users.name` and the 201 file are meant to name
                    // one person and nothing here reconciles them.
                    'employee_name' => $user->employee?->full_name,
                    /*
                     * Passwords are encrypted at rest with AES-256-CBC.
                     * Only Super Administrator is authorized to receive decrypted staff passwords.
                     * For regular administrators, this is strictly null.
                     */
                    'password_plain' => $canViewPasswords ? $user->getDecryptedPassword() : null,
                    /*
                     * The personal inbox sign-in codes go to. Shown in full
                     * rather than masked: the administrator is the person who
                     * has to notice a typo in it, and a masked address is one
                     * nobody can check. It is a work contact's own address,
                     * not a government number.
                     */
                    'otp_email' => $user->otp_email,
                    'otp_verified' => $user->otp_email_verified_at !== null,
                    'has_employee_record' => $user->employee !== null,
                    'is_self' => $user->id === $request->user()->id,
                    'tokens' => $user->tokens()->count(),
                    'created_at' => $user->created_at?->toDateString(),
                ]),

            'roles' => array_values(array_filter([
                $isSuperAdmin ? ['value' => User::ROLE_SUPER_ADMIN, 'label' => 'Super Administrator', 'description' => 'Highest authority: approve credential changes, manage system accounts and security.'] : null,
                ['value' => User::ROLE_ADMIN, 'label' => 'Administrator', 'description' => 'Full access, including payroll approval and settings.'],
                ['value' => User::ROLE_HR_STAFF, 'label' => 'HR Staff', 'description' => 'Runs every module; cannot approve payroll or change settings.'],
                ['value' => User::ROLE_SUPERVISOR, 'label' => 'Supervisor', 'description' => 'Own record plus direct reports; endorses leave and overtime.'],
                ['value' => User::ROLE_EMPLOYEE, 'label' => 'Employee', 'description' => 'Own record, payslips, and filings only.'],
            ])),

            /*
             * Whether the factor is switched on at all, so the screen can say
             * "connected, but the switch is off" rather than implying a code
             * will be asked for when it will not.
             */
            'otp' => [
                'enabled' => (bool) config('otp.enabled', true),
                'ttl_minutes' => max(1, (int) ceil((int) config('otp.ttl_seconds', 120) / 60)),
            ],

            'accessReview' => $this->lastAccessReview(),
            'staleAfterDays' => self::STALE_AFTER_DAYS,

            // Employees who could be given a login but do not have one yet.
            'unlinkedEmployees' => Employee::where(function ($q) {
                $q->whereNull('user_id')
                    ->orWhereDoesntHave('user', fn ($uq) => $uq->whereNull('deleted_at'));
            })
                ->orderBy('last_name')
                ->get(['id', 'employee_number', 'first_name', 'middle_name', 'last_name', 'suffix', 'email'])
                ->map(fn (Employee $employee) => [
                    'id' => $employee->id,
                    'employee_number' => $employee->employee_number,
                    'full_name' => $employee->full_name,
                    'email' => $employee->email,
                    // Suggested, not assigned: the admin may type another.
                    'username' => User::usernameFor($employee->first_name, $employee->last_name),
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

        // `nina` and `nina@primepower.com` are the same username.
        if (filled($request->input('username'))) {
            $request->merge(['username' => User::withDomain($request->input('username'))]);
        }

        $validated = $request->validate([
            'employee_id' => ['nullable', 'exists:employees,id'],
            'name' => ['required', 'string', 'max:255'],
            // Optional: left blank, one is made from the name.
            'username' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9._-]{2,}@[a-z0-9.-]+\.[a-z]{2,}$/', 'unique:users,username'],
            'otp_email' => ['nullable', 'email:rfc', 'max:180'],
            'role' => ['required', Rule::in(User::ROLES)],
        ], [
            'username.regex' => 'Use a name like nina@primepower.com — lowercase letters, numbers, dots, dashes or underscores.',
            'otp_email.email' => 'Enter a valid email address (e.g. employee@gmail.com).',
        ]);

        // Handed to the administrator once; the account holder changes it after.
        $password = User::generatePassword();
        $address = filled($validated['otp_email'] ?? null) ? strtolower(trim($validated['otp_email'])) : null;

        $user = User::create([
            'name' => $validated['name'],
            'username' => $validated['username'] ?? null,
            'role' => $validated['role'],
            'password' => $password,
            'visible_password' => Crypt::encryptString($password),
            'is_active' => true,
            // See RequirePasswordChange: a password the administrator has read
            // is not the account holder's password yet.
            'must_change_password' => true,
            'otp_email' => $address,
        ]);

        if ($validated['employee_id'] ?? null) {
            $employee = Employee::find($validated['employee_id']);
            if ($employee) {
                $employeeUpdates = ['user_id' => $user->id];
                if (empty($employee->email) && ! empty($address)) {
                    $employeeUpdates['email'] = $address;
                }
                $employee->update($employeeUpdates);
            }
        }

        $emailSent = false;
        $mailError = null;

        if ($user->otp_email) {
            try {
                $user->notify(new AccountProvisioned($password, $user->role));
                $emailSent = true;
            } catch (\Throwable $e) {
                Log::error("Failed to email account credentials to {$user->otp_email}: ".$e->getMessage());
                $mailError = $e->getMessage();
            }
        }

        if ($request->user()->isSuperAdmin()) {
            $message = "Account created. Username: {$user->username} / temporary password: {$password}.";
        } else {
            $message = "Account created for {$user->username}.";
        }

        if ($emailSent) {
            $message .= " Company login credentials and system link have been sent to {$user->otp_email}.";
        } elseif ($mailError) {
            $message .= " (Note: Could not send email to {$user->otp_email}: {$mailError})";
        }

        return back()->with('success', $message);
    }

    /**
     * Editing an account's profile — the name it is known by and the username
     * it signs in with.
     *
     * **Renaming somebody is allowed here and refused on their own Security
     * screen, and that is not an inconsistency.** `SettingPolicy::renameSelf`
     * holds every non-admin to the name on their employee record, because
     * nothing in this system reconciles `users.name` with the 201 file and a
     * drift between them is silent and permanent. This screen is the
     * administrator's, which is exactly who that ability belongs to — and
     * where an account *is* linked to an employee, the response says so when
     * the two names part company, rather than leaving it to be discovered on a
     * payslip.
     *
     * The username is normalised through `User::withDomain()`, so `nina` and
     * `nina@primepower.com` are the same entry and only the second is stored.
     * An account cannot be left without one: `User::booted()` fills a blank,
     * but a blank typed *over* a working username would change what somebody
     * signs in with to something they were never told.
     */
    public function updateProfile(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageAccountRequests', Setting::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'username' => [
                'required',
                'string',
                'max:120',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
            /*
             * The personal inbox sign-in codes go to — a Gmail, usually,
             * because that is what people have. **Not restricted to gmail.com
             * on purpose**: the Gmail in this feature is the company account
             * that *sends*, configured once in `MAIL_*`, and refusing a
             * Yahoo or an Outlook address on the receiving side would be a
             * rule about the wrong half.
             *
             * Nullable, because clearing it is how the factor is switched off
             * for one account — and `dns` is deliberately not used: it fails
             * on an offline machine and on a domain whose MX records are slow,
             * which would refuse a correct address.
             */
            'otp_email' => ['nullable', 'email:rfc', 'max:180'],
        ]);

        $username = User::withDomain($validated['username']);

        if ($username !== $user->username && User::where('username', $username)->whereKeyNot($user->id)->exists()) {
            return back()->withErrors(['username' => 'That username is taken.']);
        }

        $address = filled($validated['otp_email'] ?? null) ? strtolower(trim($validated['otp_email'])) : null;
        $changed = $address !== $user->otp_email;

        $user->update([
            'name' => $validated['name'],
            'username' => $username,
            'otp_email' => $address,
        ]);

        /*
         * A new address has not been proved yet, and any code outstanding for
         * the old one is a credential pointing at an inbox that is no longer
         * this account's. Both go.
         */
        if ($changed) {
            $user->forceFill(['otp_email_verified_at' => null])->save();
            $this->otp->clear($user);
        }

        $employeeName = $user->employee?->full_name;

        // Said out loud rather than prevented: HR renames somebody on the 201
        // file for real reasons (a marriage, a correction), and refusing the
        // save would leave the login stuck on a name nobody uses.
        $drifted = $employeeName && $employeeName !== $user->name
            ? " Note: their employee record still reads \"{$employeeName}\" — nothing reconciles the two."
            : '';

        $factor = match (true) {
            $address === null => ' No personal email, so this account signs in with a password alone.',
            $changed => " Sign-in codes will go to {$address} — send a test code to prove it arrives.",
            default => '',
        };

        return back()->with('success', "Profile saved. {$user->name} signs in as {$user->username}.".$drifted.$factor);
    }

    /**
     * Sends a real code to the connected inbox, now, so a typo or a broken
     * mailer is found here rather than at somebody's next sign-in.
     *
     * This is the whole reason the button exists. `MAIL_*` is configuration
     * nobody can verify by reading it, and an address is typed by hand: with
     * no way to test either, the first proof that both are right would be an
     * employee who cannot get in and an administrator who cannot tell which
     * of the two is wrong.
     *
     * It issues a genuine code rather than a fake one, because a test that
     * exercises a different path proves nothing about this one. The code is
     * live for its normal two minutes and the person can simply use it.
     */
    public function sendTestCode(User $user): RedirectResponse
    {
        Gate::authorize('manageUsers', Setting::class);

        if (blank($user->otp_email)) {
            return back()->with('error', "{$user->name} has no personal email connected yet.");
        }

        return $this->otp->send($user)
            ? back()->with('success', "A sign-in code was sent to {$user->otp_email}. Ask them to confirm it arrived.")
            : back()->with('error', 'The code could not be sent. Check MAIL_USERNAME and MAIL_PASSWORD on the server, then try again.');
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

    /**
     * Deletes / archives a user account and archives their linked employee profile.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('deleteUser', Setting::class);

        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        if ($user->isSuperAdmin() && User::where('role', User::ROLE_SUPER_ADMIN)->count() <= 1) {
            return back()->with('error', 'The last super administrator account cannot be deleted.');
        }

        $employeeNumber = null;

        // If user has a linked employee, soft-delete and mark terminated so it leaves the Employee Directory
        if ($user->employee) {
            $employee = $user->employee;
            $employeeNumber = $employee->employee_number;

            $employee->update([
                'status' => 'inactive',
                'employment_status' => 'terminated',
                'date_separated' => now(),
                'separation_reason' => 'Terminated via User Access control by '.$request->user()->name,
            ]);
            $employee->delete();
        }

        // Revoke all API tokens
        $user->tokens()->delete();

        $name = $user->name;
        $username = $user->username;

        $user->update(['is_active' => false]);
        $user->delete();

        AuditLog::create([
            'user_id' => $request->user()->id,
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'event' => 'account_deleted',
            'old_values' => [
                'target_user_id' => $user->id,
                'target_name' => $name,
                'target_username' => $username,
                'linked_employee' => $employeeNumber,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $message = "Account for {$name} ({$username}) has been archived.";
        if ($employeeNumber) {
            $message .= " Associated employee profile ({$employeeNumber}) has been moved to the Archive.";
        }

        return back()->with('success', $message);
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageUsers', Setting::class);

        $password = User::generatePassword();

        $user->update([
            'password' => $password,
            'visible_password' => Crypt::encryptString($password),
            'must_change_password' => true,
        ]);
        $emailSent = false;
        if ($user->otp_email) {
            try {
                $user->notify(new AccountProvisioned($password, $user->role));
                $emailSent = true;
            } catch (\Throwable $e) {
                Log::error("Failed to email reset credentials to {$user->otp_email}: ".$e->getMessage());
            }
        }

        if ($request->user()->isSuperAdmin()) {
            $message = "New password for {$user->username}: {$password}";
            if ($emailSent) {
                $message .= " — emailed to {$user->otp_email}.";
            }
        } else {
            $message = "Password has been reset for {$user->username}.";
            if ($emailSent) {
                $message .= " New login credentials have been emailed to {$user->otp_email}.";
            }
        }

        return back()->with('success', $message);
    }

    /**
     * Super Admin approves a staff change request, applying username and/or email changes.
     */
    public function approveChangeRequest(Request $request, AccountChangeRequest $accountChangeRequest): RedirectResponse
    {
        Gate::authorize('manageAccountRequests', Setting::class);

        if ($accountChangeRequest->status !== AccountChangeRequest::STATUS_PENDING) {
            return back()->with('error', 'This request has already been processed.');
        }

        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $accountChangeRequest->user;
        if (! $user) {
            return back()->with('error', 'The associated user account could not be found.');
        }

        $oldValues = [
            'username' => $user->username,
            'otp_email' => $user->otp_email,
        ];
        $newValues = [];

        if (filled($accountChangeRequest->requested_username)) {
            $username = User::withDomain($accountChangeRequest->requested_username);
            if (User::where('username', $username)->where('id', '!=', $user->id)->exists()) {
                return back()->with('error', "The requested username '{$username}' is already taken by another account.");
            }
            $user->username = $username;
            $newValues['username'] = $username;
        }

        if (filled($accountChangeRequest->requested_email)) {
            $email = strtolower(trim($accountChangeRequest->requested_email));
            $user->otp_email = $email;
            $user->otp_email_verified_at = null;
            $user->otp_code_hash = null;
            $user->otp_expires_at = null;
            $newValues['otp_email'] = $email;
        }

        $user->save();

        $accountChangeRequest->update([
            'status' => AccountChangeRequest::STATUS_APPROVED,
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
            'admin_notes' => $validated['admin_notes'] ?? null,
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'event' => 'account_change_approved',
            'old_values' => $oldValues,
            'new_values' => array_merge($newValues, [
                'request_id' => $accountChangeRequest->id,
                'staff_notes' => $accountChangeRequest->staff_notes,
                'admin_notes' => $validated['admin_notes'] ?? null,
            ]),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return back()->with('success', "Account change request for {$user->name} has been approved and applied.");
    }

    /**
     * Super Admin rejects a staff change request with required feedback notes.
     */
    public function rejectChangeRequest(Request $request, AccountChangeRequest $accountChangeRequest): RedirectResponse
    {
        Gate::authorize('manageAccountRequests', Setting::class);

        if ($accountChangeRequest->status !== AccountChangeRequest::STATUS_PENDING) {
            return back()->with('error', 'This request has already been processed.');
        }

        $validated = $request->validate([
            'admin_notes' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'admin_notes.required' => 'Please provide a reason or note explaining why this request was rejected.',
            'admin_notes.min' => 'Rejection note must be at least 3 characters.',
        ]);

        $accountChangeRequest->update([
            'status' => AccountChangeRequest::STATUS_REJECTED,
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
            'admin_notes' => $validated['admin_notes'],
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'auditable_type' => User::class,
            'auditable_id' => $accountChangeRequest->user_id,
            'event' => 'account_change_rejected',
            'old_values' => null,
            'new_values' => [
                'request_id' => $accountChangeRequest->id,
                'staff_notes' => $accountChangeRequest->staff_notes,
                'reason' => $validated['admin_notes'],
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $userName = $accountChangeRequest->user?->name ?? 'Staff';

        return back()->with('success', "Account change request for {$userName} has been rejected.");
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
