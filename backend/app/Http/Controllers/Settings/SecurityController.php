<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Listeners\RecordAuthenticationEvents;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogSigner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
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

            // Recent activity on this account, so a user can spot what they did
            // not do. HR sees the whole log.
            'auditLog' => $canViewAudit
                ? $this->auditLog($this->auditFilter($request))
                : [],

            'auditFilter' => $this->auditFilter($request),
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
        ]);
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
            'must_change_password' => false,
        ]);

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

    /**
     * Checks every audit row against its tamper-evidence signature.
     *
     * Behind `viewAuditLog`, the same people who read the log. The result is
     * flashed rather than stored: the point is to look now.
     */
    public function verifyAuditLog(AuditLogSigner $signer): RedirectResponse
    {
        Gate::authorize('viewAuditLog', Setting::class);

        $result = $signer->verify();
        $altered = count($result['altered']);

        $summary = "Checked {$result['checked']} audit entries: {$result['valid']} verified";
        $summary .= $result['unsigned'] > 0 ? ", {$result['unsigned']} unsigned" : '';
        $summary .= $result['gaps'] > 0 ? ", {$result['gaps']} missing id(s) (a deleted entry, or a cancelled save)" : '';

        return $altered > 0
            ? back()->with('error', "{$summary}. {$altered} ENTRY(IES) WERE ALTERED — ids ".implode(', ', $result['altered']).'.')
            : back()->with('success', "{$summary}. No entry has been altered.");
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

    /**
     * Sign-ins and record changes share one table but are read for different
     * reasons, and there are far more of the former. Unfiltered, a busy
     * morning's logins would push every edit off the 50-row window — so the
     * log defaults to changes, and sign-ins are asked for.
     */
    private function auditFilter(Request $request): string
    {
        $filter = (string) $request->query('audit', 'changes');

        return in_array($filter, ['changes', 'auth', 'all'], true) ? $filter : 'changes';
    }

    private function auditLog(string $filter): Collection
    {
        return AuditLog::with('user:id,name')
            ->when(
                $filter === 'auth',
                fn ($query) => $query->whereIn('event', RecordAuthenticationEvents::EVENTS),
            )
            ->when(
                $filter === 'changes',
                fn ($query) => $query->whereNotIn('event', RecordAuthenticationEvents::EVENTS),
            )
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (AuditLog $entry) => [
                'id' => $entry->id,
                'event' => $entry->event,
                'is_auth' => in_array($entry->event, RecordAuthenticationEvents::EVENTS, true),
                'subject' => class_basename($entry->auditable_type),
                'subject_id' => $entry->auditable_id,
                'user' => $entry->user?->name ?? 'System',
                // A read has nothing that changed; say what was read and how
                // instead — "reveal sss_number" is the line worth finding.
                'changed' => $entry->event === 'accessed'
                    ? [trim(($entry->new_values['how'] ?? '').' '.($entry->new_values['field'] ?? ''))]
                    : array_keys($entry->new_values ?? []),
                // For a failed sign-in this is the whole point of the row: the
                // account has no id to show when the address is not one of ours.
                // Rows written before sign-in moved to usernames hold `email`.
                'attempted_login' => $entry->new_values['username'] ?? $entry->new_values['email'] ?? null,
                'ip_address' => $entry->ip_address,
                'created_at' => $entry->created_at?->toIso8601String(),
            ]);
    }
}
