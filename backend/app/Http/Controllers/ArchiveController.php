<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The master list of everything that has been deleted, and the way back.
 *
 * A delete button in this system never permanently destroys a row — employees,
 * user accounts, and clients are soft-deleted. The Archive module allows
 * administrators to view deleted data by category and restore them at any time.
 */
class ArchiveController extends Controller
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewArchive', Employee::class);

        $window = (int) config('archive.restore_window_days', 30);
        $search = $request->string('search')->trim()->value();
        $category = $request->string('category')->trim()->value() ?: 'all';

        $employees = Employee::onlyTrashed()
            ->with(['department:id,name', 'position:id,title', 'client:id,name'])
            ->search($search)
            ->orderByDesc('deleted_at')
            ->get()
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'kind' => 'employee',
                'reference' => $employee->employee_number,
                'name' => $employee->full_name,
                'detail' => $employee->client?->name
                    ?? $employee->department?->name
                    ?? 'No department',
                'sub_detail' => $employee->position?->title ?? $employee->employment_status,
                ...$this->timing($employee->deleted_at, $window),
            ]);

        $users = User::onlyTrashed()
            ->with(['employeeWithTrashed:id,user_id,employee_number,first_name,last_name'])
            ->when($search, fn ($q) => $q->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%")
                ->orWhere('otp_email', 'like', "%{$search}%"),
            ))
            ->orderByDesc('deleted_at')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'kind' => 'user',
                'reference' => $user->username,
                'name' => $user->name,
                'detail' => ucwords(str_replace('_', ' ', $user->role)),
                'sub_detail' => $user->employeeWithTrashed
                    ? "Linked Employee: {$user->employeeWithTrashed->employee_number}"
                    : ($user->otp_email ? "Email: {$user->otp_email}" : 'No linked employee'),
                ...$this->timing($user->deleted_at, $window),
            ]);

        $clients = Client::onlyTrashed()
            ->withCount('employees')
            ->search($search)
            ->orderByDesc('deleted_at')
            ->get()
            ->map(fn (Client $client) => [
                'id' => $client->id,
                'kind' => 'client',
                'reference' => $client->code,
                'name' => $client->name,
                'detail' => $client->industry ?? 'No industry recorded',
                'sub_detail' => $client->employees_count > 0
                    ? $client->employees_count.' employee(s) still filed here'
                    : null,
                ...$this->timing($client->deleted_at, $window),
            ]);

        $rows = $employees->concat($users)->concat($clients)
            ->sortByDesc('deleted_at')
            ->values();

        return Inertia::render('HR/Archive', [
            'rows' => $rows,
            'filters' => [
                'search' => $search,
                'category' => $category,
            ],
            'window' => $window,
            'summary' => [
                'all' => $rows->count(),
                'employees' => Employee::onlyTrashed()->count(),
                'users' => User::onlyTrashed()->count(),
                'clients' => Client::onlyTrashed()->count(),
                'within_window' => $rows->where('within_window', true)->count(),
            ],
        ]);
    }

    public function restoreEmployee(int $employee): RedirectResponse
    {
        $record = Employee::onlyTrashed()->findOrFail($employee);

        Gate::authorize('restore', $record);

        $this->employees->restore($record);

        return back()->with('success', "{$record->full_name} restored to Employee Directory.");
    }

    public function restoreUser(Request $request, int $user): RedirectResponse
    {
        Gate::authorize('viewArchive', Employee::class);

        $record = User::onlyTrashed()->findOrFail($user);
        $record->restore();
        $record->update(['is_active' => true]);

        // If this user has a soft-deleted employee profile, restore it too so they reappear in the directory
        if ($record->employeeWithTrashed) {
            $employee = $record->employeeWithTrashed;
            $employee->restore();
            $employee->update([
                'status' => 'active',
                'employment_status' => 'regular',
                'date_separated' => null,
                'separation_reason' => null,
            ]);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'auditable_type' => User::class,
            'auditable_id' => $record->id,
            'event' => 'account_restored',
            'old_values' => null,
            'new_values' => [
                'username' => $record->username,
                'name' => $record->name,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return back()->with('success', "Account for {$record->name} ({$record->username}) restored.");
    }

    public function restoreClient(int $client): RedirectResponse
    {
        Gate::authorize('viewArchive', Employee::class);

        $record = Client::onlyTrashed()->findOrFail($client);
        $record->restore();

        return back()->with('success', "{$record->name} restored.");
    }

    /**
     * When it was deleted and whether that is still recent.
     *
     * @return array<string, mixed>
     */
    private function timing(?Carbon $deletedAt, int $window): array
    {
        $days = $deletedAt === null ? null : $deletedAt->diffInDays(now());

        return [
            'deleted_at' => $deletedAt?->toIso8601String(),
            'days_ago' => $days === null ? null : (int) $days,
            'within_window' => $days !== null && $days <= $window,
        ];
    }
}
