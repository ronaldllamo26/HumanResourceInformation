<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Payslip;
use App\Models\PerformanceReview;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

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

    public function data(): Response
    {
        Gate::authorize('manage', Setting::class);

        $connection = config('database.default');

        return Inertia::render('Settings/Data', [
            'database' => [
                'driver' => $connection,
                'name' => config("database.connections.{$connection}.database"),
                'is_sqlite' => $connection === 'sqlite',
            ],
            'counts' => $this->recordCounts(),
            'settings' => Setting::group('data'),
            'exports' => [
                ['label' => 'Employee directory', 'href' => '/settings/data/export/employees'],
                ['label' => 'Attendance summary', 'href' => '/hr/timekeeping/reports/export?period=monthly'],
            ],
        ]);
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
    private function recordCounts(): array
    {
        return [
            ['label' => 'Employees', 'count' => Employee::withTrashed()->count()],
            ['label' => 'Attendance records', 'count' => AttendanceLog::count()],
            ['label' => 'Leave requests', 'count' => LeaveRequest::count()],
            ['label' => 'Payslips', 'count' => Payslip::count()],
            ['label' => 'Performance reviews', 'count' => PerformanceReview::count()],
            ['label' => 'Audit log entries', 'count' => AuditLog::count()],
        ];
    }
}
