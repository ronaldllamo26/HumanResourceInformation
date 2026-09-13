<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Employee;
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
 * A delete button in this system never destroys a row — employees and clients
 * are soft-deleted. Before this screen existed that was a promise nobody could
 * see: an archived employee simply vanished from the directory with no way to
 * find them again short of a database query, and a mis-click cost a retype of
 * the whole 201 file.
 *
 * Two record types on one screen rather than an archive tab on each, because
 * the question being asked is "what did we delete", not "what did we delete
 * from Employees". Someone hunting a record they removed by accident does not
 * remember which list they were on when they did it.
 */
class ArchiveController extends Controller
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function index(Request $request): Response
    {
        // Restoring is an admin act on both models, so the screen that offers
        // it is admin-only too. Reusing the org gate would let HR staff in.
        Gate::authorize('viewArchive', Employee::class);

        $window = (int) config('archive.restore_window_days', 30);
        $search = $request->string('search')->trim()->value();

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
                // What it was filed under, so a restore is an informed choice
                // rather than a name with no context around it.
                'detail' => $employee->client?->name
                    ?? $employee->department?->name
                    ?? 'No department',
                'sub_detail' => $employee->position?->title,
                ...$this->timing($employee->deleted_at, $window),
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

        $rows = $employees->concat($clients)
            ->sortByDesc('deleted_at')
            ->values();

        return Inertia::render('HR/Archive', [
            'rows' => $rows,
            'filters' => ['search' => $search],
            'window' => $window,
            'summary' => [
                'employees' => Employee::onlyTrashed()->count(),
                'clients' => Client::onlyTrashed()->count(),
                'within_window' => $rows->where('within_window', true)->count(),
            ],
        ]);
    }

    public function restoreEmployee(int $employee): RedirectResponse
    {
        $record = Employee::onlyTrashed()->findOrFail($employee);

        Gate::authorize('restore', $record);

        // Reuses the service the API restore already calls, so the two entry
        // points cannot drift: it also reactivates the login it deactivated.
        $this->employees->restore($record);

        return back()->with('success', "{$record->full_name} restored.");
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
     * `within_window` only changes how the row reads — nothing expires. See
     * config/archive.php for why an HRIS must not purge on a timer.
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
