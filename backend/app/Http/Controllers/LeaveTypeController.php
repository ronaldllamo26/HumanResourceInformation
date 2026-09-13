<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 3 — leave type catalogue (SL, VL, ML, LWOP, …).
 */
class LeaveTypeController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', LeaveRequest::class);

        return Inertia::render('HR/Leave/Types', [
            'types' => LeaveType::withCount('requests')
                ->orderBy('code')
                ->get()
                ->map(fn (LeaveType $type) => [
                    'id' => $type->id,
                    'code' => $type->code,
                    'name' => $type->name,
                    'description' => $type->description,
                    'default_credits' => (float) $type->default_credits,
                    'is_paid' => $type->is_paid,
                    'requires_attachment' => $type->requires_attachment,
                    'is_convertible_to_cash' => $type->is_convertible_to_cash,
                    'max_consecutive_days' => $type->max_consecutive_days,
                    'min_days_notice' => $type->min_days_notice,
                    'is_active' => $type->is_active,
                    'requests_count' => $type->requests_count,
                ]),
            'can' => ['manage' => $request->user()->can('manageTypes', LeaveRequest::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manageTypes', LeaveRequest::class);

        LeaveType::create($this->validated($request));

        return back()->with('success', 'Leave type created.');
    }

    public function update(Request $request, LeaveType $leaveType): RedirectResponse
    {
        Gate::authorize('manageTypes', LeaveRequest::class);

        $leaveType->update($this->validated($request, $leaveType));

        return back()->with('success', 'Leave type updated.');
    }

    public function destroy(Request $request, LeaveType $leaveType): RedirectResponse
    {
        Gate::authorize('manageTypes', LeaveRequest::class);

        // Filed leave points at the type; deactivate so history stays readable.
        if ($leaveType->requests()->exists() || $leaveType->balances()->exists()) {
            $leaveType->update(['is_active' => false]);

            return back()->with('success', 'Leave type is in use — deactivated instead of deleted.');
        }

        $leaveType->delete();

        return back()->with('success', 'Leave type deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?LeaveType $type = null): array
    {
        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:16',
                Rule::unique('leave_types', 'code')->ignore($type?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'default_credits' => ['required', 'numeric', 'min:0', 'max:400'],
            'is_paid' => ['boolean'],
            'requires_attachment' => ['boolean'],
            'is_convertible_to_cash' => ['boolean'],
            'max_consecutive_days' => ['nullable', 'integer', 'min:1', 'max:400'],
            'min_days_notice' => ['required', 'integer', 'min:0', 'max:365'],
            'is_active' => ['boolean'],
        ]);

        return [
            ...$validated,
            'code' => strtoupper($validated['code']),
            'is_paid' => $request->boolean('is_paid'),
            'requires_attachment' => $request->boolean('requires_attachment'),
            'is_convertible_to_cash' => $request->boolean('is_convertible_to_cash'),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
