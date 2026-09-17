<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Listeners\RecordAuthenticationEvents;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogSigner;
use App\Services\DataAccessLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Audit Logs — who signed in, who changed a record, and who read one.
 *
 * It was 50 unpaginated rows at the bottom of Settings → Security, which is
 * the wrong place twice over: that screen is where a person manages their own
 * password, and a window of 50 answers "what happened in the last hour" and
 * nothing else. A question like "who opened this employee's file in August"
 * needs a date range, a user, and pages — so the log is its own screen under
 * **Administration** now, and Security links to it instead of holding it.
 *
 * Three sources write here and the screen keeps them apart, because they are
 * read for different reasons: `Auditable` records changes,
 * `RecordAuthenticationEvents` records sign-ins and failures, and
 * `DataAccessLogger` records reads and exports. Unfiltered, a busy morning's
 * sign-ins bury every edit — which is why the default is still *changes*.
 */
class AuditLogController extends Controller
{
    /** Reads and exports, read from the logger rather than restated here. */
    public const READ_EVENTS = DataAccessLogger::EVENTS;

    public const GROUPS = ['changes', 'auth', 'reads', 'all'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAuditLog', Setting::class);

        $filters = $this->filters($request);
        $base = $this->query($filters);

        $entries = (clone $base)
            ->with('user:id,name')
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditLog $entry) => $this->row($entry));

        // Counted over the range rather than the filtered group, so switching
        // the group does not move the tiles that are meant to be the totals.
        $inRange = $this->query([...$filters, 'group' => 'all', 'event' => null]);

        return Inertia::render('Settings/AuditLogs', [
            'entries' => $entries,
            'filters' => $filters,
            'summary' => [
                'total' => (clone $inRange)->count(),
                'sign_ins' => (clone $inRange)->where('event', 'login')->count(),
                'failed' => (clone $inRange)->whereIn('event', ['login_failed', 'lockout'])->count(),
                'reads' => (clone $inRange)->whereIn('event', self::READ_EVENTS)->count(),
            ],
            'options' => [
                'groups' => [
                    ['value' => 'changes', 'label' => 'Record changes'],
                    ['value' => 'auth', 'label' => 'Sign-ins'],
                    ['value' => 'reads', 'label' => 'Reads & exports'],
                    ['value' => 'all', 'label' => 'Everything'],
                ],
                // Read from the table rather than a hard-coded list: three
                // services write events here and a fourth will one day, and a
                // dropdown that has to be updated by hand is a dropdown that
                // quietly stops offering the newest event.
                'events' => AuditLog::query()
                    ->select('event')
                    ->distinct()
                    ->orderBy('event')
                    ->pluck('event')
                    ->map(fn (string $event) => [
                        'value' => $event,
                        'label' => ucwords(str_replace('_', ' ', $event)),
                    ]),
                'users' => User::query()
                    ->whereIn('id', AuditLog::query()->distinct()->whereNotNull('user_id')->pluck('user_id'))
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (User $user) => ['value' => $user->id, 'label' => $user->name]),
            ],
            'retentionDays' => (int) Setting::get('data.audit_retention_days', 0),
        ]);
    }

    /**
     * Re-checks every row's signature.
     *
     * Lives with the log rather than on the Security screen it came from: the
     * answer is about these rows, and the button belongs beside them.
     * Throttled, because it reads the whole table.
     */
    public function verify(AuditLogSigner $signer): RedirectResponse
    {
        Gate::authorize('viewAuditLog', Setting::class);

        $result = $signer->verify();
        $altered = count($result['altered']);

        $summary = "Checked {$result['checked']} audit entries: {$result['valid']} verified";
        $summary .= $result['unsigned'] > 0 ? ", {$result['unsigned']} unsigned" : '';
        $summary .= $result['gaps'] > 0 ? ", {$result['gaps']} missing id(s) (a deleted entry, or a cancelled save)" : '';

        return $altered > 0
            ? back()->with('error', "{$summary}. {$altered} ENTRY(IES) WERE ALTERED — ids ".implode(', ', $result['altered']).'.')
            : back()->with('success', "{$summary}. No entry has been altered.");
    }

    /**
     * The filtered log as a CSV.
     *
     * Itself an export of personal data — attempted usernames, addresses, who
     * read what — so it writes its own `exported` row before handing the file
     * over. An audit trail whose export leaves no trace is missing the one
     * event it exists to record.
     */
    public function export(Request $request, DataAccessLogger $logger): StreamedResponse
    {
        Gate::authorize('viewAuditLog', Setting::class);

        $filters = $this->filters($request);
        $entries = $this->query($filters)->with('user:id,name')->latest('id')->limit(5000)->get();

        $logger->exported('audit_log', AuditLog::class, [
            'format' => 'csv',
            'rows' => $entries->count(),
            'filters' => array_filter($filters, fn ($value) => $value !== null),
        ]);

        return response()->streamDownload(function () use ($entries, $filters) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Audit log']);
            fputcsv($handle, [$filters['from'].' to '.$filters['to'].' · '.$filters['group']]);
            fputcsv($handle, []);
            fputcsv($handle, ['When', 'Event', 'By', 'Subject', 'Subject ID', 'Detail', 'Attempted login', 'IP address']);

            foreach ($entries as $entry) {
                $row = $this->row($entry);

                fputcsv($handle, [
                    $entry->created_at?->toDateTimeString(),
                    $row['event'],
                    $row['user'],
                    $row['subject'],
                    $row['subject_id'],
                    implode(' ', $row['changed']),
                    $row['attempted_login'],
                    $row['ip_address'],
                ]);
            }

            fputcsv($handle, []);
            fputcsv($handle, [$entries->count().' row(s)']);

            fclose($handle);
        }, 'audit-log-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** @param array<string, mixed> $filters */
    private function query(array $filters): Builder
    {
        return AuditLog::query()
            ->whereDate('created_at', '>=', $filters['from'])
            ->whereDate('created_at', '<=', $filters['to'])
            ->when($filters['group'] === 'auth', fn (Builder $query) => $query->whereIn('event', RecordAuthenticationEvents::EVENTS))
            ->when($filters['group'] === 'reads', fn (Builder $query) => $query->whereIn('event', self::READ_EVENTS))
            ->when($filters['group'] === 'changes', fn (Builder $query) => $query
                ->whereNotIn('event', [...RecordAuthenticationEvents::EVENTS, ...self::READ_EVENTS]))
            ->when($filters['event'], fn (Builder $query, string $event) => $query->where('event', $event))
            ->when($filters['user'], fn (Builder $query, int $id) => $query->where('user_id', $id))
            ->when($filters['search'], fn (Builder $query, string $term) => $query->where(
                fn (Builder $inner) => $inner
                    ->where('ip_address', 'like', "%{$term}%")
                    ->orWhere('auditable_type', 'like', "%{$term}%"),
            ));
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $to = $this->date($request->input('to')) ?? Carbon::today();
        $from = $this->date($request->input('from')) ?? $to->copy()->subDays(30);

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'group' => in_array($request->input('group'), self::GROUPS, true) ? $request->input('group') : 'changes',
            'event' => $request->filled('event') ? (string) $request->input('event') : null,
            'user' => $request->integer('user') ?: null,
            'search' => $request->filled('search') ? trim((string) $request->input('search')) : null,
        ];
    }

    private function date(mixed $value): ?Carbon
    {
        try {
            return is_string($value) && $value !== '' ? Carbon::parse($value)->startOfDay() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * One row, in the shape the old Security screen used — the same reading of
     * an `accessed` row ("reveal sss_number") and of a failed sign-in (the
     * attempted username, which is the whole point of that row).
     *
     * @return array<string, mixed>
     */
    private function row(AuditLog $entry): array
    {
        return [
            'id' => $entry->id,
            'event' => $entry->event,
            'is_auth' => in_array($entry->event, RecordAuthenticationEvents::EVENTS, true),
            'is_read' => in_array($entry->event, self::READ_EVENTS, true),
            'subject' => class_basename($entry->auditable_type),
            'subject_id' => $entry->auditable_id,
            'user' => $entry->user?->name ?? 'System',
            'changed' => in_array($entry->event, self::READ_EVENTS, true)
                ? [trim(implode(' ', array_filter([
                    $entry->new_values['how'] ?? null,
                    $entry->new_values['field'] ?? null,
                    $entry->new_values['report'] ?? null,
                ])))]
                : array_keys($entry->new_values ?? []),
            'attempted_login' => $entry->new_values['username'] ?? $entry->new_values['email'] ?? null,
            'ip_address' => $entry->ip_address,
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }
}
