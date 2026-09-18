<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Payslip;
use App\Models\PerformanceReview;
use App\Models\Setting;
use App\Services\DataAccessLogger;
use App\Services\DatabaseBackup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * General, Appearance, Notifications, and Data & Backup.
 *
 * The sections that are pure configuration; the ones that manage records of
 * their own have their own controllers.
 */
class SettingsController extends Controller
{
    public function general(): Response
    {
        Gate::authorize('manage', Setting::class);

        return Inertia::render('Settings/General', [
            'settings' => [
                ...Setting::group('company'),
                ...Setting::group('regional'),
            ],
            'timezones' => ['Asia/Manila', 'Asia/Singapore', 'Asia/Hong_Kong', 'UTC'],
            'dateFormats' => [
                ['value' => 'M j, Y', 'label' => 'Aug 10, 2026'],
                ['value' => 'j M Y', 'label' => '10 Aug 2026'],
                ['value' => 'm/d/Y', 'label' => '08/10/2026'],
                ['value' => 'd/m/Y', 'label' => '10/08/2026'],
                ['value' => 'Y-m-d', 'label' => '2026-08-10'],
            ],
        ]);
    }

    public function updateGeneral(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Setting::class);

        $validated = $request->validate([
            'company.name' => ['required', 'string', 'max:255'],
            'company.tagline' => ['nullable', 'string', 'max:255'],
            'company.address' => ['nullable', 'string', 'max:500'],
            'company.email' => ['nullable', 'email', 'max:255'],
            'company.phone' => ['nullable', 'string', 'max:32'],
            'company.tin' => ['nullable', 'string', 'max:32'],
            'company.sss_employer_number' => ['nullable', 'string', 'max:32'],
            'company.philhealth_employer_number' => ['nullable', 'string', 'max:32'],
            'company.pagibig_employer_number' => ['nullable', 'string', 'max:32'],
            'regional.timezone' => ['required', 'timezone'],
            'regional.date_format' => ['required', 'string', 'max:32'],
            'regional.currency' => ['required', 'string', 'size:3'],
            'regional.week_starts_on' => ['required', 'integer', 'between:1,7'],
        ]);

        Setting::setMany($this->flatten($validated), 'general');

        return back()->with('success', 'Company settings saved.');
    }

    public function appearance(Request $request): Response
    {
        Gate::authorize('managePersonal', Setting::class);

        return Inertia::render('Settings/Appearance', [
            // Theme and density live in the browser, not the database — they are
            // per-device preferences, and the page reads them on mount.
            'brand' => [
                'name' => Setting::get('company.name'),
                'tagline' => Setting::get('company.tagline'),
            ],
        ]);
    }

    public function notifications(): Response
    {
        Gate::authorize('manage', Setting::class);

        return Inertia::render('Settings/Notifications', [
            'settings' => Setting::group('notifications'),
        ]);
    }

    public function updateNotifications(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Setting::class);

        $validated = $request->validate([
            'notifications.leave_filed' => ['boolean'],
            'notifications.leave_endorsed' => ['boolean'],
            'notifications.overtime_filed' => ['boolean'],
            'notifications.payroll_for_approval' => ['boolean'],
            'notifications.review_assigned' => ['boolean'],
            'notifications.document_expiring' => ['boolean'],
            'notifications.expiry_lead_days' => ['required', 'integer', 'between:1,180'],
        ]);

        Setting::setMany($this->flatten($validated), 'notifications');

        return back()->with('success', 'Notification settings saved.');
    }

    public function data(Request $request, DatabaseBackup $backup): Response
    {
        Gate::authorize('manage', Setting::class);

        return Inertia::render('Settings/Data', [
            /*
             * Read from `DatabaseBackup` rather than assembled here, because
             * the screen and the thing that takes the backup have to agree
             * about which database they are talking about. The card used to
             * build this itself and print only the connection name — which
             * cannot answer the question somebody opening this screen on a
             * deployment actually has, which is whether these are the records
             * on their laptop or the ones every payslip is filed in.
             */
            'database' => $backup->describe(),
            'counts' => $this->recordCounts(),
            'settings' => Setting::group('data'),
            'exports' => [
                ['label' => 'Employee directory', 'href' => '/settings/data/export/employees'],
                ['label' => 'Attendance summary', 'href' => '/hr/timekeeping/reports/export?period=monthly'],
            ],

            // A dump is the narrowest-held action on this screen, so the
            // button is drawn only for somebody who may press it — the same
            // rule the org directory follows rather than offering a link into
            // a 403.
            'can' => [
                'backupDatabase' => $request->user()->can('backupDatabase', Setting::class),
            ],
        ]);
    }

    /**
     * Hands over the whole database as one file.
     *
     * **Audited before it is streamed**, through the same `exported()` row a
     * report download writes. This is the most complete export the system can
     * produce — every employee, every payslip, and the ciphertext of every
     * encrypted column — so "who took a copy of everything, and when" is
     * exactly the question the audit trail exists to answer. Written after the
     * gate, so a refused request is never recorded as an access.
     *
     * **The file is deleted after it is sent.** A dump left in the temporary
     * directory is every government identifier and bank account in the company
     * sitting on disk unencrypted, waiting for whatever reads that folder next.
     */
    public function backup(DatabaseBackup $backup): BinaryFileResponse|RedirectResponse
    {
        Gate::authorize('backupDatabase', Setting::class);

        if ($reason = $backup->unavailableReason()) {
            // Back with the reason rather than a 500: the administrator did
            // nothing wrong and there is something specific for them to do
            // about it, which an error page would not say.
            return back()->with('error', $reason);
        }

        try {
            $path = $backup->dump();
        } catch (ProcessFailedException) {
            // `pg_dump`'s own message names the host and the database it could
            // not reach; it is logged by the service and kept off the screen.
            return back()->with('error', 'The backup could not be taken. The details are in the application log.');
        }

        $filename = $backup->filename();

        app(DataAccessLogger::class)->exported('database.backup', Setting::class, [
            'database' => config('database.connections.'.config('database.default').'.database'),
            'file' => $filename,
            'bytes' => filesize($path) ?: null,
        ]);

        return response()
            ->download($path, $filename)
            ->deleteFileAfterSend();
    }

    public function updateData(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Setting::class);

        $validated = $request->validate([
            'data.audit_retention_days' => ['required', 'integer', 'between:30,3650'],
        ]);

        Setting::setMany($this->flatten($validated), 'data');

        return back()->with('success', 'Data settings saved.');
    }

    /**
     * `company.name` arrives nested as ['company' => ['name' => …]]; the store
     * wants it flat and dotted.
     *
     * @return array<string, mixed>
     */
    private function flatten(array $validated): array
    {
        $flat = [];

        foreach ($validated as $group => $values) {
            foreach ((array) $values as $key => $value) {
                $flat["{$group}.{$key}"] = $value;
            }
        }

        return $flat;
    }

    /** @return array<int, array{label: string, count: int}> */
    /**
     * What is stored, and where to go and look at it.
     *
     * **Every figure carries the screen that lists the rows it counted**, which
     * is the rule the dashboard tiles already follow and this card was quietly
     * breaking: a count of 1,247 payslips that cannot be opened has raised a
     * question and then refused to answer it.
     *
     * **The employee count is split rather than totalled**, and that is the
     * same rule rather than an exception to it. It was one figure taken
     * `withTrashed()`, so it read 42 while `/hr/employees` showed 38 — and a
     * link that returns a different number from the tile that sent you is
     * worse than no link, because now two screens disagree and neither says
     * why. Archived rows are their own count with their own screen.
     *
     * @return array<int, array{label: string, count: int, href: string|null}>
     */
    private function recordCounts(): array
    {
        return [
            [
                'label' => 'Employees',
                'count' => Employee::count(),
                'href' => '/hr/employees',
            ],
            [
                'label' => 'Archived employees',
                'count' => Employee::onlyTrashed()->count(),
                'href' => '/hr/archive',
            ],
            [
                'label' => 'Leave requests',
                'count' => LeaveRequest::count(),
                // No status parameter: this screen applies no default filter,
                // so a bare visit is the whole table. `?status=all` was the
                // first guess and `all` is not one of `LeaveRequest::STATUSES`.
                'href' => '/hr/leave',
            ],
            [
                'label' => 'Payslips',
                'count' => Payslip::count(),
                'href' => '/hr/payroll/payslips',
            ],
            [
                'label' => 'Performance reviews',
                'count' => PerformanceReview::count(),
                // `/hr/performance` is the review list. `/hr/performance/reviews`
                // exists only with an id after it, so the obvious-looking
                // plural would have been a 404 reached from a settings screen.
                'href' => '/hr/performance',
            ],
            [
                'label' => 'Audit log entries',
                'count' => AuditLog::count(),
                'href' => $this->auditLogHref(),
            ],
        ];
    }

    /**
     * The audit log, opened wide enough to hold the number beside it.
     *
     * Two defaults on that screen would otherwise narrow it below this count:
     * it shows **record changes** only, and the **last thirty days** only. So
     * a card reporting 3,921 entries would open a list of a few hundred, which
     * is the failure this system has already had twice on dashboard tiles —
     * found by clicking the tile and counting, not by reading the code.
     *
     * `from` is the date of the oldest row rather than a fixed early date: it
     * is one cheap `min()` and it cannot go stale, where "2020-01-01" would
     * quietly start excluding rows the day somebody imports older history.
     */
    private function auditLogHref(): string
    {
        $oldest = AuditLog::min('created_at');

        $from = $oldest === null
            ? Carbon::today()->toDateString()
            : Carbon::parse($oldest)->toDateString();

        return '/settings/audit-logs?group=all&from='.$from;
    }
}
