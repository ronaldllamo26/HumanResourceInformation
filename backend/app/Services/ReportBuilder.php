<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientTimesheet;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\Payslip;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One place that builds every report, in one shape.
 *
 * The screen, the CSV and the PDF all render the *same* `build()` result —
 * columns, rows and totals — so a figure cannot come out different depending
 * on which button somebody pressed. That is the whole reason this is a service
 * and not three export methods: the report on screen is the report that
 * downloads.
 *
 * Every report reads the service that already owns its numbers
 * (`TimekeepingService` for attendance, stored payslips for payroll) rather
 * than re-deriving them here, the same rule the API follows.
 *
 * **Government numbers, bank accounts and addresses are in no report.** These
 * files leave the building — they are printed, emailed to a client, kept in a
 * downloads folder — and a report that carries what a stolen copy is worth
 * stealing is the leak nobody notices until afterwards. Salary appears only in
 * the payroll register, which is read back from payslips HR already approved.
 */
class ReportBuilder
{
    public const REPORTS = ['employees', 'attendance', 'leave', 'payroll', 'client_billing'];

    public function __construct(private readonly TimekeepingService $timekeeping) {}

    /**
     * What each report is, and which filters it accepts — sent to the screen
     * so the filter row is built from one description rather than a second
     * copy of it in React.
     *
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            [
                'value' => 'employees',
                'label' => 'Employee Masterlist',
                'description' => 'Who works here, where they are filed, and who they are deployed to.',
                'filters' => ['department', 'client', 'category', 'status'],
            ],
            [
                'value' => 'attendance',
                'label' => 'Attendance Summary',
                'description' => 'Days worked, lateness, undertime, absences and approved overtime over a range.',
                'filters' => ['dates', 'department', 'client'],
            ],
            [
                'value' => 'leave',
                'label' => 'Leave Report',
                'description' => 'Leave filed in the range, with its type, days and decision.',
                'filters' => ['dates', 'department', 'leave_status'],
            ],
            [
                'value' => 'payroll',
                'label' => 'Payroll Register',
                'description' => 'Gross, deductions and net pay per employee, read back from approved payslips.',
                'filters' => ['period', 'client', 'category'],
            ],
            [
                'value' => 'client_billing',
                'label' => 'Client Billing Summary',
                'description' => 'Per client for one cutoff: headcount, days, hours, overtime and whether they confirmed.',
                'filters' => ['period'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     title: string, subtitle: string,
     *     columns: array<int, array{key: string, label: string, align?: string}>,
     *     rows: array<int, array<string, mixed>>,
     *     totals: array<string, mixed>|null,
     *     note: string|null
     * }
     */
    public function build(string $report, array $filters): array
    {
        return match ($report) {
            'attendance' => $this->attendance($filters),
            'leave' => $this->leave($filters),
            'payroll' => $this->payroll($filters),
            'client_billing' => $this->clientBilling($filters),
            default => $this->employees($filters),
        };
    }

    /** Every filter this screen understands, cleaned of what it does not. */
    public function filters(array $input): array
    {
        return [
            'from' => $this->date($input['from'] ?? null)?->toDateString(),
            'to' => $this->date($input['to'] ?? null)?->toDateString(),
            'department' => $input['department'] ?? null,
            'client' => $input['client'] ?? null,
            'category' => in_array($input['category'] ?? null, Employee::CATEGORIES, true) ? $input['category'] : null,
            'status' => in_array($input['status'] ?? null, ['active', 'on_leave', 'inactive'], true) ? $input['status'] : null,
            'leave_status' => in_array($input['leave_status'] ?? null, LeaveRequest::STATUSES, true) ? $input['leave_status'] : null,
            'period' => $input['period'] ?? null,
        ];
    }

    /** @return Collection<int, PayrollRun> */
    public function reportableRuns(): Collection
    {
        return PayrollRun::query()->reportable()->with('period:id,name')->get();
    }

    // --- Module 1 -----------------------------------------------------------

    /** @param array<string, mixed> $filters */
    private function employees(array $filters): array
    {
        $employees = Employee::query()
            ->with(['department:id,name', 'position:id,title', 'client:id,name'])
            ->when($filters['department'] ?? null, fn ($query, $id) => $query->where('department_id', $id))
            ->when($filters['client'] ?? null, fn ($query, $id) => $query->where('client_id', $id))
            ->when($filters['category'] ?? null, fn ($query, $value) => $query->where('employment_category', $value))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return [
            'title' => 'Employee Masterlist',
            'subtitle' => $this->describe($filters, ['department', 'client', 'category', 'status']),
            'columns' => [
                ['key' => 'number', 'label' => 'Employee No.'],
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'position', 'label' => 'Position'],
                ['key' => 'department', 'label' => 'Department'],
                ['key' => 'assignment', 'label' => 'Deployed to'],
                ['key' => 'employment_status', 'label' => 'Employment'],
                ['key' => 'date_hired', 'label' => 'Date Hired'],
                ['key' => 'status', 'label' => 'Record'],
            ],
            'rows' => $employees->map(fn (Employee $employee) => [
                'number' => $employee->employee_number,
                'name' => $employee->full_name,
                'position' => $employee->position?->title,
                'department' => $employee->department?->name,
                'assignment' => $employee->client?->name ?? 'Internal',
                'employment_status' => $employee->employment_status,
                'date_hired' => $employee->date_hired?->toDateString(),
                'status' => $employee->status,
            ])->all(),
            'totals' => ['name' => $employees->count().' employee(s)'],
            'note' => null,
        ];
    }

    // --- Module 2 -----------------------------------------------------------

    /** @param array<string, mixed> $filters */
    private function attendance(array $filters): array
    {
        [$from, $to] = $this->range($filters);

        $employees = Employee::query()
            ->with(['department:id,name', 'client:id,name'])
            ->where('status', '!=', 'inactive')
            ->when($filters['department'] ?? null, fn ($query, $id) => $query->where('department_id', $id))
            ->when($filters['client'] ?? null, fn ($query, $id) => $query->where('client_id', $id))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $summaries = $this->timekeeping->summaries($employees->pluck('id')->all(), $from, $to);

        $rows = $employees->map(function (Employee $employee) use ($summaries) {
            $summary = $summaries[$employee->id];

            return [
                'number' => $employee->employee_number,
                'name' => $employee->full_name,
                'assignment' => $employee->client?->name ?? $employee->department?->name ?? 'Internal',
                'days_worked' => (float) $summary['days_worked'],
                'hours_worked' => round($summary['minutes_worked'] / 60, 2),
                'late_minutes' => $summary['late_minutes'],
                'undertime_minutes' => $summary['undertime_minutes'],
                'absent_days' => (float) $summary['absent_days'],
                'overtime_hours' => (float) $summary['overtime_hours'],
                'night_diff_hours' => round($summary['night_diff_minutes'] / 60, 2),
            ];
        });

        return [
            'title' => 'Attendance Summary',
            'subtitle' => $from->format('M j, Y').' – '.$to->format('M j, Y')
                .$this->suffix($filters, ['department', 'client']),
            'columns' => [
                ['key' => 'number', 'label' => 'Employee No.'],
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'assignment', 'label' => 'Assignment'],
                ['key' => 'days_worked', 'label' => 'Days', 'align' => 'right'],
                ['key' => 'hours_worked', 'label' => 'Hours', 'align' => 'right'],
                ['key' => 'late_minutes', 'label' => 'Late (min)', 'align' => 'right'],
                ['key' => 'undertime_minutes', 'label' => 'Undertime (min)', 'align' => 'right'],
                ['key' => 'absent_days', 'label' => 'Absent', 'align' => 'right'],
                ['key' => 'overtime_hours', 'label' => 'OT hrs', 'align' => 'right'],
                ['key' => 'night_diff_hours', 'label' => 'Night diff hrs', 'align' => 'right'],
            ],
            'rows' => $rows->all(),
            'totals' => [
                'name' => $rows->count().' employee(s)',
                'days_worked' => round($rows->sum('days_worked'), 2),
                'hours_worked' => round($rows->sum('hours_worked'), 2),
                'late_minutes' => $rows->sum('late_minutes'),
                'undertime_minutes' => $rows->sum('undertime_minutes'),
                'absent_days' => round($rows->sum('absent_days'), 2),
                'overtime_hours' => round($rows->sum('overtime_hours'), 2),
                'night_diff_hours' => round($rows->sum('night_diff_hours'), 2),
            ],
            'note' => 'Absences already covered by approved leave are not counted here; '
                .'overtime is approved requests only, which is what payroll pays.',
        ];
    }

    // --- Module 3 -----------------------------------------------------------

    /** @param array<string, mixed> $filters */
    private function leave(array $filters): array
    {
        [$from, $to] = $this->range($filters);

        $requests = LeaveRequest::query()
            ->with(['employee:id,employee_number,first_name,middle_name,last_name,suffix,department_id', 'employee.department:id,name', 'leaveType:id,name,is_paid', 'hr:id,name'])
            ->overlapping($from->toDateString(), $to->toDateString())
            ->when($filters['leave_status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['department'] ?? null, fn ($query, $id) => $query->whereHas('employee', fn ($inner) => $inner->where('department_id', $id)))
            ->orderBy('start_date')
            ->get();

        return [
            'title' => 'Leave Report',
            'subtitle' => $from->format('M j, Y').' – '.$to->format('M j, Y')
                .$this->suffix($filters, ['department', 'leave_status']),
            'columns' => [
                ['key' => 'reference', 'label' => 'Reference'],
                ['key' => 'name', 'label' => 'Employee'],
                ['key' => 'department', 'label' => 'Department'],
                ['key' => 'type', 'label' => 'Leave Type'],
                ['key' => 'dates', 'label' => 'Dates'],
                ['key' => 'days', 'label' => 'Days', 'align' => 'right'],
                ['key' => 'paid', 'label' => 'Paid'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'decided_by', 'label' => 'Decided by'],
            ],
            'rows' => $requests->map(fn (LeaveRequest $request) => [
                'reference' => $request->reference_number,
                'name' => $request->employee?->full_name,
                'department' => $request->employee?->department?->name,
                'type' => $request->leaveType?->name,
                'dates' => $request->start_date->format('M j').' – '.$request->end_date->format('M j, Y'),
                'days' => (float) $request->days_requested,
                'paid' => $request->leaveType?->is_paid ? 'Paid' : 'Unpaid',
                'status' => $request->status,
                'decided_by' => $request->hr?->name,
            ])->all(),
            'totals' => [
                'name' => $requests->count().' request(s)',
                'days' => round((float) $requests->sum('days_requested'), 2),
            ],
            'note' => null,
        ];
    }

    // --- Module 4 -----------------------------------------------------------

    /** @param array<string, mixed> $filters */
    private function payroll(array $filters): array
    {
        $period = $this->period($filters);

        $payslips = $period
            ? Payslip::query()
                ->with(['employee:id,employee_number,first_name,middle_name,last_name,suffix,client_id,employment_category', 'employee.client:id,name'])
                ->whereHas('run', fn ($query) => $query->where('payroll_period_id', $period->id)->reportable())
                ->when($filters['client'] ?? null, fn ($query, $id) => $query->whereHas('employee', fn ($inner) => $inner->where('client_id', $id)))
                ->when($filters['category'] ?? null, fn ($query, $value) => $query->whereHas('employee', fn ($inner) => $inner->where('employment_category', $value)))
                ->get()
                ->sortBy(fn (Payslip $payslip) => $payslip->employee?->last_name)
                ->values()
            : collect();

        return [
            'title' => 'Payroll Register',
            'subtitle' => ($period?->name ?? 'No payroll period chosen').$this->suffix($filters, ['client', 'category']),
            'columns' => [
                ['key' => 'payslip', 'label' => 'Payslip No.'],
                ['key' => 'number', 'label' => 'Employee No.'],
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'assignment', 'label' => 'Billed to'],
                ['key' => 'basic_pay', 'label' => 'Basic', 'align' => 'right'],
                ['key' => 'earnings', 'label' => 'OT / ND / Holiday / Allow.', 'align' => 'right'],
                ['key' => 'gross_pay', 'label' => 'Gross', 'align' => 'right'],
                ['key' => 'deductions_total', 'label' => 'Deductions', 'align' => 'right'],
                ['key' => 'net_pay', 'label' => 'Net Pay', 'align' => 'right'],
            ],
            'rows' => $payslips->map(fn (Payslip $payslip) => [
                'payslip' => $payslip->payslip_number,
                'number' => $payslip->employee?->employee_number,
                'name' => $payslip->employee?->full_name,
                'assignment' => $payslip->employee?->client?->name ?? 'Internal',
                'basic_pay' => (float) $payslip->basic_pay,
                'earnings' => round((float) $payslip->overtime_pay + (float) $payslip->night_diff_pay
                    + (float) $payslip->holiday_pay + (float) $payslip->allowances_total, 2),
                'gross_pay' => (float) $payslip->gross_pay,
                'deductions_total' => (float) $payslip->deductions_total,
                'net_pay' => (float) $payslip->net_pay,
            ])->all(),
            'totals' => [
                'name' => $payslips->count().' payslip(s)',
                'basic_pay' => round((float) $payslips->sum('basic_pay'), 2),
                'gross_pay' => round((float) $payslips->sum('gross_pay'), 2),
                'deductions_total' => round((float) $payslips->sum('deductions_total'), 2),
                'net_pay' => round((float) $payslips->sum('net_pay'), 2),
            ],
            'note' => 'Read back from stored payslips of approved and paid runs only — a draft run is '
                .'still being corrected, so its figures are not a register.',
        ];
    }

    /**
     * What each client owes for one cutoff, and whether they have agreed to
     * the attendance behind it — the two halves of a billing conversation on
     * one page.
     *
     * @param  array<string, mixed>  $filters
     */
    private function clientBilling(array $filters): array
    {
        $period = $this->period($filters);

        if (! $period) {
            return [
                'title' => 'Client Billing Summary',
                'subtitle' => 'No payroll period chosen',
                'columns' => [['key' => 'client', 'label' => 'Client']],
                'rows' => [],
                'totals' => null,
                'note' => 'Choose a payroll period — a billing summary is per cutoff.',
            ];
        }

        [$from, $to] = $this->timekeeping->periodRange($period);

        $deployed = Employee::query()
            ->where('employment_category', 'external')
            ->where('status', '!=', 'inactive')
            ->whereNotNull('client_id')
            ->get(['id', 'client_id']);

        $summaries = $this->timekeeping->summaries($deployed->pluck('id')->all(), $from, $to);
        $sheets = ClientTimesheet::where('payroll_period_id', $period->id)->get()->keyBy('client_id');

        $rows = Client::query()
            ->whereIn('id', $deployed->pluck('client_id')->unique())
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(function (Client $client) use ($deployed, $summaries, $sheets) {
                $staff = $deployed->where('client_id', $client->id);
                $figures = $staff->map(fn (Employee $employee) => $summaries[$employee->id]);

                return [
                    'client' => $client->name,
                    'code' => $client->code,
                    'headcount' => $staff->count(),
                    'days_worked' => round((float) $figures->sum('days_worked'), 2),
                    'hours_worked' => round($figures->sum('minutes_worked') / 60, 2),
                    'overtime_hours' => round((float) $figures->sum('overtime_hours'), 2),
                    'absent_days' => round((float) $figures->sum('absent_days'), 2),
                    'timesheet' => $sheets[$client->id]->status ?? 'not prepared',
                ];
            });

        return [
            'title' => 'Client Billing Summary',
            'subtitle' => $period->name.' · '.$from->format('M j').' – '.$to->format('M j, Y'),
            'columns' => [
                ['key' => 'code', 'label' => 'Code'],
                ['key' => 'client', 'label' => 'Client'],
                ['key' => 'headcount', 'label' => 'Deployed', 'align' => 'right'],
                ['key' => 'days_worked', 'label' => 'Days', 'align' => 'right'],
                ['key' => 'hours_worked', 'label' => 'Hours', 'align' => 'right'],
                ['key' => 'overtime_hours', 'label' => 'OT hrs', 'align' => 'right'],
                ['key' => 'absent_days', 'label' => 'Absent', 'align' => 'right'],
                ['key' => 'timesheet', 'label' => 'Client confirmation'],
            ],
            'rows' => $rows->all(),
            'totals' => [
                'client' => $rows->count().' client(s)',
                'headcount' => $rows->sum('headcount'),
                'days_worked' => round($rows->sum('days_worked'), 2),
                'hours_worked' => round($rows->sum('hours_worked'), 2),
                'overtime_hours' => round($rows->sum('overtime_hours'), 2),
                'absent_days' => round($rows->sum('absent_days'), 2),
            ],
            'note' => 'A row whose confirmation is not "confirmed" is attendance the client has not yet '
                .'agreed to — bill from it and the dispute arrives after the invoice.',
        ];
    }

    // --- Filters ------------------------------------------------------------

    /** @return array<int, Carbon> */
    private function range(array $filters): array
    {
        $from = $this->date($filters['from'] ?? null) ?? now()->startOfMonth();
        $to = $this->date($filters['to'] ?? null) ?? now()->endOfMonth();

        return $to->lessThan($from) ? [$to, $from] : [$from, $to];
    }

    private function period(array $filters): ?PayrollPeriod
    {
        $id = $filters['period'] ?? null;

        return $id
            ? PayrollPeriod::find($id)
            : PayrollPeriod::query()->whereDate('start_date', '<=', now())->orderByDesc('start_date')->first();
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
     * Names the filters that are on, so a printed page says what it is a
     * report *of* — a sheet of forty names with no heading is a sheet nobody
     * can file.
     */
    private function describe(array $filters, array $keys): string
    {
        $parts = collect($keys)
            ->map(fn (string $key) => $this->label($key, $filters[$key] ?? null))
            ->filter()
            ->values();

        return $parts->isEmpty() ? 'All employees' : $parts->implode(' · ');
    }

    private function suffix(array $filters, array $keys): string
    {
        $parts = collect($keys)->map(fn (string $key) => $this->label($key, $filters[$key] ?? null))->filter();

        return $parts->isEmpty() ? '' : ' · '.$parts->implode(' · ');
    }

    private function label(string $key, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($key) {
            'department' => Department::find($value)?->name,
            'client' => Client::withTrashed()->find($value)?->name,
            'category' => ucfirst((string) $value).' staff',
            'status' => 'Record: '.$value,
            'leave_status' => 'Status: '.str_replace('_', ' ', (string) $value),
            default => null,
        };
    }
}
