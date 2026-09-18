<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AccountChangeRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Personal security: password, active API tokens, and — for HR — the audit log.
 *
 * This is what replaced the starter kit's profile page.
 */
class SecurityController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    public function index(Request $request): Response
    {
        Gate::authorize('managePersonal', Setting::class);

        $user = $request->user();
        $canViewAudit = $request->user()->can('viewAuditLog', Setting::class);

        return Inertia::render('Settings/Security', [
            'account' => [
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->role,
                'email_verified' => $user->email_verified_at !== null,
                'created_at' => $user->created_at?->toDateString(),

            ],

            'tokens' => $user->tokens()
                ->latest('id')
                ->get()
                ->map(fn ($token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'created_at' => $token->created_at?->toIso8601String(),
                ]),

            /*
             * Whether to offer the link to Audit Logs. The log itself used to
             * be drawn here — 50 unpaginated rows under somebody's password
             * form — and it is its own screen under Administration now, with
             * a date range and pages. This screen keeps the door, not the
             * window.
             */
            'canViewAudit' => $canViewAudit,

            // Whether the name is theirs to change. Read from the same
            // ability the update enforces, so a field can never be drawn
            // for somebody whose save would then drop it.
            'canRename' => Gate::allows('renameSelf', Setting::class),

            // Why the user is on this screen when they asked for another one.
            // RequirePasswordChange sent them here silently; without this the
            // page reads as a broken link rather than as a step to complete.
            'mustChangePassword' => (bool) $user->must_change_password,

            'privacy' => [
                'acknowledged_at' => $user->hasAcknowledgedPrivacyNotice()
                    ? $user->privacy_acknowledged_at?->toIso8601String()
                    : null,
                'version' => config('privacy.notice_version'),
            ],

            'otp' => [
                'enabled' => (bool) config('otp.enabled', true),
                'is_required' => $this->otp->isRequiredFor($user),
                'otp_email' => $user->otp_email,
                'otp_enabled' => (bool) ($user->otp_enabled ?? true),
                'otp_verified' => $user->otp_email_verified_at !== null,
                'ttl_minutes' => max(1, (int) ceil((int) config('otp.ttl_seconds', 120) / 60)),
                'can_disable' => false,
            ],

            'is_super_admin' => $user->isSuperAdmin(),

            'changeRequests' => AccountChangeRequest::where('user_id', $user->id)
                ->with('decider:id,name')
                ->latest()
                ->get()
                ->map(fn (AccountChangeRequest $r) => [
                    'id' => $r->id,
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
                ]),
        ]);
    }

    /**
     * Submit an account change request for Super Admin review.
     */
    public function storeChangeRequest(Request $request): RedirectResponse
    {
        Gate::authorize('managePersonal', Setting::class);

        $user = $request->user();

        $hasPending = AccountChangeRequest::where('user_id', $user->id)
            ->pending()
            ->exists();

        if ($hasPending) {
            return back()->with('error', 'You already have a pending change request under review by the Super Administrator.');
        }

        $validated = $request->validate([
            'requested_username' => ['nullable', 'string', 'max:100'],
            'requested_email' => ['nullable', 'email:rfc', 'max:180'],
            'staff_notes' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'staff_notes.required' => 'Please provide notes or a reason explaining why you need this credential change.',
            'staff_notes.min' => 'Notes must be at least 5 characters.',
        ]);

        $reqUsername = filled($validated['requested_username'] ?? null)
            ? trim($validated['requested_username'])
            : null;
        $reqEmail = filled($validated['requested_email'] ?? null)
            ? strtolower(trim($validated['requested_email']))
            : null;

        if (blank($reqUsername) && blank($reqEmail)) {
            return back()->with('error', 'Please specify a new username, a new email, or both.');
        }

        if ($reqUsername !== null) {
            $fullUsername = User::withDomain($reqUsername);
            if ($fullUsername === $user->username) {
                $reqUsername = null;
            } else {
                if (User::where('username', $fullUsername)->where('id', '!=', $user->id)->exists()) {
                    return back()->with('error', "The requested username '{$fullUsername}' is already taken.");
                }
            }
        }

        if ($reqEmail !== null && strtolower((string) $user->otp_email) === $reqEmail) {
            $reqEmail = null;
        }

        if (blank($reqUsername) && blank($reqEmail)) {
            return back()->with('error', 'Requested username and/or email must be different from your current credentials.');
        }

        AccountChangeRequest::create([
            'user_id' => $user->id,
            'current_username' => $user->username,
            'requested_username' => $reqUsername,
            'current_email' => $user->otp_email,
            'requested_email' => $reqEmail,
            'staff_notes' => $validated['staff_notes'],
            'status' => AccountChangeRequest::STATUS_PENDING,
        ]);

        return back()->with('success', 'Your change request has been submitted to the Super Administrator for review.');
    }

    public function updateOtpEmail(Request $request): RedirectResponse
    {
        Gate::authorize('managePersonal', Setting::class);

        $validated = $request->validate([
            'password' => ['required', 'current_password'],
            'otp_email' => ['nullable', 'email:rfc', 'max:180'],
            'otp_enabled' => ['nullable', 'boolean'],
        ], [
            'password.required' => 'Please enter your current password to confirm this security change.',
            'password.current_password' => 'That is not your current password.',
        ]);

        $user = $request->user();
        $hasEnabledInput = array_key_exists('otp_enabled', $validated);

        // Disabling multi-factor authentication is strictly prohibited in settings
        if ($hasEnabledInput && ! (bool) $validated['otp_enabled']) {
            return back()->withErrors([
                'otp_enabled' => 'Multi-Factor Authentication is mandatory and cannot be disabled in account settings.',
            ]);
        }

        $newStatus = true;

        $hasEmailInput = array_key_exists('otp_email', $validated);
        $address = $hasEmailInput
            ? (filled($validated['otp_email'] ?? null) ? strtolower(trim($validated['otp_email'])) : null)
            : $user->otp_email;

        // Require a valid email address
        if (blank($address)) {
            return back()->withErrors([
                'otp_email' => 'Please provide a valid Gmail address to configure multi-factor authentication.',
            ]);
        }

        $emailChanged = $address !== $user->otp_email;
        $statusChanged = $newStatus !== (bool) ($user->otp_enabled ?? true);

        $updates = [];
        if ($emailChanged) {
            $updates['otp_email'] = $address;
            $updates['otp_email_verified_at'] = null;
        }

        if ($statusChanged || $user->otp_enabled === null) {
            $updates['otp_enabled'] = $newStatus;
        }

        if (! empty($updates)) {
            $this->otp->clear($user);
            $user->update($updates);

            if ($statusChanged) {
                $message = $newStatus
                    ? "Two-Factor Authentication is now enabled. Sign-in codes will be sent to {$user->otp_email}."
                    : 'Two-Factor Authentication is now disabled. This account will sign in with password only.';
            } else {
                $message = $address !== null
                    ? "Personal email for sign-in codes updated to {$address}."
                    : 'Personal email for sign-in codes removed.';
            }

            return back()->with('success', $message);
        }

        return back();
    }

    public function sendOtpTest(Request $request): RedirectResponse
    {
        Gate::authorize('managePersonal', Setting::class);

        $user = $request->user();

        if (blank($user->otp_email)) {
            return back()->with('error', 'Connect an email address first before sending a test code.');
        }

        if (! $this->otp->canResend($user)) {
            return back()->with('error', 'A code was just sent. Please wait '.$this->otp->secondsUntilResend($user).' second(s).');
        }

        return $this->otp->send($user)
            ? back()->with('success', "A sign-in code was sent to {$user->otp_email}. Please check your inbox.")
            : back()->with('error', 'The code could not be sent. Please check your company mail settings.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        Gate::authorize('managePersonal', Setting::class);

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ], [
            'current_password.current_password' => 'That is not your current password.',
        ]);

        $user = $request->user();

        // Clearing the flag here rather than anywhere else is what makes this
        // the only way out of RequirePasswordChange: the hold is lifted by the
        // act that removes the reason for it, not by a separate "done" button
        // somebody could reach without changing anything.
        $wasForced = (bool) $user->must_change_password;

        $user->update([
            'password' => $validated['password'],
            'visible_password' => Crypt::encryptString($validated['password']),
            'must_change_password' => false,
        ]);

        // Keep current session authenticated and synchronized with the new password
        Auth::login($user);

        // A token issued while the shared password was live was issued to
        // whoever held that password. Rotating one and leaving the other is
        // half a rotation.
        if ($wasForced) {
            $user->tokens()->delete();
        }

        return back()->with('success', 'Password updated.');
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        Gate::authorize('managePersonal', Setting::class);

        $user = $request->user();

        /*
         * Whether the name is theirs to change decides the rules, not just
         * whether the field is drawn.
         *
         * Hiding the input and validating it anyway would make the rule
         * cosmetic — anyone who can post a form could rename themselves, and
         * the drift it creates against their employee record is caught by
         * nothing: no check in this system compares a login name to the 201
         * file it is meant to match.
         *
         * A submitted name from someone not allowed one is dropped rather
         * than refused: nothing wrong is stored either way, and refusing
         * would fail an email change over a field the person cannot see.
         */
        $mayRename = Gate::allows('renameSelf', Setting::class);

        $rules = [
            'email' => [
                'required', 'email', 'max:255',
                'unique:users,email,'.$user->id,
            ],
        ];

        if ($mayRename) {
            $rules['name'] = ['required', 'string', 'max:255'];
        }

        $validated = $request->validate($rules);

        $emailChanged = $validated['email'] !== $user->email;

        $user->fill($validated);

        // A changed address has to be proven again before it is trusted. Set
        // outside the fillable payload — email_verified_at is guarded, so mass
        // assignment would drop it silently.
        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        return back()->with('success', 'Profile updated.');
    }

    /** Signs every other session out — the "I lost my laptop" button. */
    public function revokeTokens(Request $request): RedirectResponse
    {
        Gate::authorize('managePersonal', Setting::class);

        $count = $request->user()->tokens()->count();

        $request->user()->tokens()->delete();

        return back()->with('success', "Revoked {$count} API token(s).");
    }

    public function revokeToken(Request $request, int $tokenId): RedirectResponse
    {
        Gate::authorize('managePersonal', Setting::class);

        $request->user()->tokens()->whereKey($tokenId)->delete();

        return back()->with('success', 'Token revoked.');
    }

    public function destroyAccount(Request $request): RedirectResponse
    {
        Gate::authorize('managePersonal', Setting::class);

        $request->validate(['password' => ['required', 'current_password']]);

        $user = $request->user();

        // The last administrator cannot leave — nobody would be able to
        // configure the application or approve payroll.
        if ($user->isAdmin() && User::where('role', User::ROLE_ADMIN)->where('is_active', true)->count() <= 1) {
            return back()->with('error', 'You are the only active administrator — promote someone else first.');
        }

        Auth::logout();
        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
