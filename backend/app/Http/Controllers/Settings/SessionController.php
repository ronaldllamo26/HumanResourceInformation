<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Services\SessionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who is signed in, and signing them out.
 *
 * The screen answers an incident question — a leaked password, a laptop out
 * of the building, an engagement that ended an hour ago — none of which the
 * five-minute idle window is a response to.
 */
class SessionController extends Controller
{
    public function __construct(private readonly SessionRegistry $sessions) {}

    public function index(Request $request): Response
    {
        Gate::authorize('manageSessions', Setting::class);

        $sessions = $this->sessions->active($request->session()->getId());

        return Inertia::render('Settings/Sessions', [
            'sessions' => $sessions->values(),
            'summary' => [
                'sessions' => $sessions->count(),
                // Distinct accounts rather than rows: one person with a phone
                // and a desktop is one person, and "12 sessions" over-reads as
                // twelve people during the minute somebody is deciding whether
                // this is a breach.
                'accounts' => $sessions->pluck('user_id')->unique()->count(),
                'idle_minutes' => (int) config('session.lifetime'),
            ],
        ]);
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageSessions', Setting::class);

        $count = $this->sessions->terminateFor($request, $user, $request->user());

        return back()->with(
            'success',
            $count === 0
                ? "{$user->name} had no open sessions."
                : "Signed {$user->name} out of {$count} ".str('session')->plural($count).'.',
        );
    }

    public function destroySession(Request $request): RedirectResponse
    {
        Gate::authorize('manageSessions', Setting::class);

        $validated = $request->validate(['session' => ['required', 'string', 'max:255']]);

        /*
         * Refused rather than quietly skipped. Ending your own session here
         * would log you out mid-incident, and a button that silently does
         * nothing is one somebody presses twice and then distrusts.
         */
        if ($validated['session'] === $request->session()->getId()) {
            return back()->with('error', 'That is your own session. Use Sign out for that.');
        }

        $count = $this->sessions->terminateSession($request, $validated['session'], $request->user());

        return back()->with(
            'success',
            $count === 0 ? 'That session had already ended.' : 'Session ended.',
        );
    }

    /**
     * Everybody but the person pressing it.
     *
     * Confirmation is asked for in the UI rather than with a typed phrase
     * here: the act is loud, immediate and *recoverable* — everybody signs
     * back in — which is a different class of thing from the demo-data wipe
     * that demands `--force`.
     */
    public function destroyAll(Request $request): RedirectResponse
    {
        Gate::authorize('manageSessions', Setting::class);

        $count = $this->sessions->terminateAll($request, $request->user());

        return back()->with(
            'success',
            $count === 0
                ? 'No other sessions were open.'
                : "Signed out {$count} ".str('session')->plural($count).'. Your own session was kept.',
        );
    }
}
