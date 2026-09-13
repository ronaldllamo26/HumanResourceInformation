<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 2 — who changed a DTR record, when, and what changed.
 *
 * Only HR corrects time (see AttendanceLogPolicy), so this reuses the same
 * "who may see an audit trail" gate as Settings > Security rather than
 * inventing a parallel permission.
 */
class AttendanceHistoryController extends Controller
{
    /** Fields worth showing a before/after for; the rest just says "changed". */
    private const HEADLINE_FIELDS = [
        'time_in', 'time_out', 'status', 'late_minutes', 'undertime_minutes', 'overtime_minutes',
    ];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAuditLog', Setting::class);

        // employee_id lives inside the JSON diff, not as a column on
        // audit_logs, so it can't be filtered at the SQL level without
        // breaking the pagination totals below — event is the one column
        // that both filters correctly and paginates correctly together.
        $filters = $request->only(['event']);

        $audits = AuditLog::where('auditable_type', AttendanceLog::class)
            ->with('user:id,name')
            ->when($filters['event'] ?? null, fn ($q, $value) => $q->where('event', $value))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $employeeIds = $audits->getCollection()
            ->map(fn (AuditLog $audit) => ($audit->new_values ?? $audit->old_values ?? [])['employee_id'] ?? null)
            ->filter()
            ->unique();

        $employees = Employee::whereIn('id', $employeeIds)
            ->get(['id', 'employee_number', 'first_name', 'middle_name', 'last_name', 'suffix'])
            ->keyBy('id');

        $rows = $audits->getCollection()->map(function (AuditLog $audit) use ($employees) {
            $values = $audit->new_values ?? $audit->old_values ?? [];
            $employee = $employees->get($values['employee_id'] ?? null);

            return [
                'id' => $audit->id,
                'event' => $audit->event,
                'employee' => $employee ? [
                    'full_name' => $employee->full_name,
                    'employee_number' => $employee->employee_number,
                ] : null,
                'log_date' => $values['log_date'] ?? null,
                'user' => $audit->user?->name ?? 'System',
                'changes' => $this->summarizeChanges($audit),
                'created_at' => $audit->created_at?->toIso8601String(),
            ];
        });

        return Inertia::render('HR/Timekeeping/History', [
            'audits' => [
                'data' => $rows,
                'meta' => [
                    'from' => $audits->firstItem(),
                    'to' => $audits->lastItem(),
                    'total' => $audits->total(),
                    'links' => $audits->linkCollection()->toArray(),
                ],
            ],
            'filters' => $filters,
            'events' => ['created', 'updated', 'deleted'],
        ]);
    }

    /** @return array<int, string> */
    private function summarizeChanges(AuditLog $audit): array
    {
        if ($audit->event === 'created') {
            return ['New DTR record created.'];
        }

        if ($audit->event === 'deleted') {
            return ['DTR record deleted.'];
        }

        $before = $audit->old_values ?? [];
        $after = $audit->new_values ?? [];
        $summary = [];

        foreach (self::HEADLINE_FIELDS as $field) {
            if (! array_key_exists($field, $after)) {
                continue;
            }

            $summary[] = sprintf('%s: %s → %s', str_replace('_', ' ', $field), $before[$field] ?? '—', $after[$field] ?? '—');
        }

        // Fields outside the headline list still changed; say so without
        // spelling out every one.
        $other = array_diff(array_keys($after), self::HEADLINE_FIELDS);

        if ($other !== []) {
            $summary[] = 'Also updated: '.implode(', ', array_map(fn ($field) => str_replace('_', ' ', $field), $other));
        }

        return $summary !== [] ? $summary : ['Record updated.'];
    }
}
