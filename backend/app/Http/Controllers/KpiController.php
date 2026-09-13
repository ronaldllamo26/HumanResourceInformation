<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Kpi;
use App\Models\PerformanceReview;
use App\Models\Position;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 5 — the KPI library scorecards are built from.
 */
class KpiController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PerformanceReview::class);

        return Inertia::render('HR/Performance/Kpis', [
            'kpis' => Kpi::with(['department:id,name', 'position:id,title'])
                ->withCount('employeeKpis')
                ->orderBy('title')
                ->get()
                ->map(fn (Kpi $kpi) => [
                    'id' => $kpi->id,
                    'title' => $kpi->title,
                    'description' => $kpi->description,
                    'category' => $kpi->category,
                    'measurement_unit' => $kpi->measurement_unit,
                    'default_weight' => (float) $kpi->default_weight,
                    'is_active' => $kpi->is_active,
                    'department_id' => $kpi->department_id,
                    'position_id' => $kpi->position_id,
                    'scope' => $kpi->scopeText(),
                    'scope_name' => $kpi->position?->title ?? $kpi->department?->name ?? 'All employees',
                    'assigned_count' => $kpi->employee_kpis_count,
                ]),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'positions' => Position::orderBy('title')->get(['id', 'title', 'department_id']),
            'can' => ['manage' => $request->user()->can('manageKpis', PerformanceReview::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manageKpis', PerformanceReview::class);

        Kpi::create($this->validated($request));

        return back()->with('success', 'KPI added to the library.');
    }

    public function update(Request $request, Kpi $kpi): RedirectResponse
    {
        Gate::authorize('manageKpis', PerformanceReview::class);

        $kpi->update($this->validated($request));

        return back()->with('success', 'KPI updated.');
    }

    public function destroy(Kpi $kpi): RedirectResponse
    {
        Gate::authorize('manageKpis', PerformanceReview::class);

        // Scorecards and ratings point at the KPI; deactivate so past reviews
        // stay readable.
        if ($kpi->employeeKpis()->exists()) {
            $kpi->update(['is_active' => false]);

            return back()->with('success', 'KPI is in use — deactivated instead of deleted.');
        }

        $kpi->delete();

        return back()->with('success', 'KPI deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category' => ['nullable', 'string', 'max:48'],
            'measurement_unit' => ['nullable', 'string', 'max:32'],
            'default_weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'position_id' => ['nullable', 'exists:positions,id'],
            'is_active' => ['boolean'],
        ]);

        return [
            ...$validated,
            // A position already implies its department; keeping both would
            // make the KPI match twice.
            'department_id' => $validated['position_id'] ? null : ($validated['department_id'] ?? null),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
