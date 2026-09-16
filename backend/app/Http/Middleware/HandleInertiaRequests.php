<?php

namespace App\Http\Middleware;

use App\Models\EmployeeDocument;
use App\Models\EmployeeEndorsement;
use App\Models\Setting;
use App\Services\CredentialExpiryScanner;
use App\Services\EmployeeService;
use App\Services\LeaveService;
use App\Services\NotificationFeed;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return '';
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user()?->only([
                    'id', 'name', 'email', 'role', 'is_active',
                ]),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // Neither a success nor a failure — an explanation. A redirect
                // that lands somewhere the user did not ask for needs to say
                // why, and dressing that in a green tick claims something was
                // accomplished when nothing was.
                'info' => fn () => $request->session()->get('info'),
            ],
            // Per-row failures from a bulk import, surfaced on the page that
            // triggered it rather than squeezed into a toast.
            'importErrors' => fn () => $request->session()->get('importErrors', []),
            // Brand text, so the logo and payslip header follow whatever
            // Settings > General holds rather than a hardcoded string.
            'brand' => fn () => [
                'name' => Setting::get('company.name'),
                'tagline' => Setting::get('company.tagline'),
            ],
            // Leave requests waiting on *this* user, for the topbar badge.
            // Lazily evaluated, so guests and API calls never run the query.
            'pendingApprovals' => fn () => $request->user()
                ? app(LeaveService::class)->pendingApprovalsFor($request->user())
                : 0,
            // Everything waiting on this user to decide — leave, overtime, DTR
            // corrections, new hires — for the bell's badge. The list behind
            // it is fetched only when the bell is opened.
            'notificationCount' => fn () => $request->user()
                ? app(NotificationFeed::class)->count($request->user())
                : 0,
            // Lapsed or soon-to-lapse 201 documents, scoped to what this user
            // may see — so an employee's own licence warns them directly.
            // Lazy for the same reason as the badge above.
            'expiringCredentials' => fn () => $request->user()
                ? app(CredentialExpiryScanner::class)->countFor(
                    EmployeeDocument::query()->whereIn(
                        'employee_id',
                        app(EmployeeService::class)
                            ->scopedQuery($request->user())
                            ->select('employees.id'),
                    ),
                )
                : 0,

            /*
             * Hires Core 1 has sent that nobody has answered yet.
             *
             * Gated on the ability rather than merely counted, because the
             * figure is company-wide: a supervisor being told "4 waiting" for a
             * queue they cannot open is a leak of hiring activity dressed up as
             * a badge. Zero for everyone else, which draws no badge at all.
             *
             * Lazy like the two above, so guests and API calls never run it.
             */
            'pendingEndorsements' => fn () => $request->user()?->can('viewAny', EmployeeEndorsement::class)
                ? EmployeeEndorsement::pending()->count()
                : 0,

            /*
             * The idle sign-out, in the browser's terms.
             *
             * Read from config rather than restated in JS so the countdown on
             * the screen and the expiry on the server are the same number.
             * Two copies would drift the first time one was tuned, and the
             * failure would be silent in the direction that matters: a screen
             * counting down from ten against a session that died at five.
             *
             * Not lazy, unlike the badges above — it is needed on every
             * authenticated page render and costs nothing.
             */
            'idle' => [
                'timeout' => (int) config('session.lifetime') * 60,
                'warnAfter' => (int) config('session.idle_warning'),
            ],
        ];
    }
}
