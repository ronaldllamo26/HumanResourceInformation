<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function index(Request $request): Response
    {
        Gate::authorize('manageUsers', Setting::class);

        return Inertia::render('Settings/Users', [
            'users' => User::with('employee:id,user_id,employee_number')
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => [
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
}
