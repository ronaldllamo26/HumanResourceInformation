<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * API tokens and the outside systems this HRIS talks to.
 */
class IntegrationController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('manage', Setting::class);

        return Inertia::render('Settings/Integrations', [
            'tokens' => $request->user()->tokens()
                ->latest('id')
                ->get()
                ->map(fn ($token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'abilities' => $token->abilities,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'created_at' => $token->created_at?->toIso8601String(),
                ]),

            // What the REST API currently exposes, so an integrator can see the
            // surface without reading routes/api.php.
            'endpoints' => [
                ['method' => 'POST', 'path' => '/api/v1/login', 'description' => 'Exchange credentials for a token'],
                ['method' => 'GET', 'path' => '/api/v1/employees', 'description' => 'Employee directory, paginated and role-scoped'],
                ['method' => 'POST', 'path' => '/api/v1/employees', 'description' => 'Create an employee'],
                ['method' => 'GET', 'path' => '/api/v1/employees/statistics', 'description' => 'Headline headcount figures'],
                ['method' => 'GET', 'path' => '/api/v1/attendance', 'description' => 'Daily time records'],
                ['method' => 'POST', 'path' => '/api/v1/attendance', 'description' => 'Push a punch — the biometric device target'],
                ['method' => 'GET', 'path' => '/api/v1/attendance/summary', 'description' => 'Aggregated attendance figures'],
            ],

            'biometric' => [
                'import_url' => url('/hr/timekeeping'),
                'api_url' => url('/api/v1/attendance'),
                'csv_columns' => 'employee_number, date, time_in, time_out, break_out, break_in, device_id',
            ],
        ]);
    }

    public function storeToken(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Setting::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $token = $request->user()->createToken(
            $validated['name'],
            ["role:{$request->user()->role}"],
        );

        // Shown once. Sanctum stores only a hash, so it cannot be shown again.
        return back()->with('success', "Token created — copy it now, it will not be shown again: {$token->plainTextToken}");
    }

    public function destroyToken(Request $request, int $tokenId): RedirectResponse
    {
        Gate::authorize('manage', Setting::class);

        $request->user()->tokens()->whereKey($tokenId)->delete();

        return back()->with('success', 'Token revoked.');
    }
}
