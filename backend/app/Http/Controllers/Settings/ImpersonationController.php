<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Starting and ending an impersonation.
 *
 * Thin on purpose: every rule lives in `ImpersonationService`, because the
 * session switch, the audit rows and the refusals have to stay together —
 * split across a controller and a service, the next door added to this
 * feature would come with only half of them.
 */
class ImpersonationController extends Controller
{
    public function __construct(private readonly ImpersonationService $impersonation) {}

    public function store(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('impersonate', Setting::class);

        $administrator = $request->user();

        if ($reason = $this->impersonation->refusalReason($administrator, $user)) {
            return back()->with('error', $reason);
        }

        $this->impersonation->start($request, $administrator, $user);

        /*
         * Onto the dashboard rather than back to Users & Access, which the
         * impersonated account may not even be allowed to open — landing an
         * impersonation on a 403 would read as the feature failing. The
         * dashboard is the one screen every role can see, and it is where a
         * person's own view of the system starts.
         */
        return redirect()->route('dashboard')->with(
            'info',
            "You are now signed in as {$user->name}. Everything you do is recorded against your own account.",
        );
    }

    /**
     * Hands the session back.
     *
     * Deliberately *not* gated on `impersonate`: the person pressing this is
     * currently authenticated as the impersonated employee, who does not hold
     * that ability — asking the policy here would trap the administrator
     * inside the session they are trying to leave. The session's own
     * `administrator_id` is the authority, and it can only have been put
     * there by a request that did pass the gate.
     */
    public function destroy(Request $request): RedirectResponse
    {
        if (! $this->impersonation->stop($request)) {
            return redirect()->route('login')->with('error', 'That impersonation could not be ended. Please sign in again.');
        }

        return redirect()->route('settings.users')->with('success', 'Impersonation ended.');
    }
}
