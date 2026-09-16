<?php

namespace App\Http\Controllers\Timekeeping;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Client;
use App\Models\ClientTimesheet;
use App\Models\ClientTimesheetLine;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Services\ClientTimesheetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Client Timesheets — the attendance of staff deployed to each client, sent
 * to the client and confirmed (or disputed) by them.
 */
class ClientTimesheetController extends Controller
{
    public function __construct(private readonly ClientTimesheetService $timesheets) {}

    public function index(Request $request): Response
    {
        Gate::authorize('manage', AttendanceLog::class);

        $periods = PayrollPeriod::query()->orderByDesc('start_date')->limit(24)->get(['id', 'name', 'start_date', 'end_date']);

        $period = $periods->firstWhere('id', $request->integer('period'))
            ?? $periods->first(fn (PayrollPeriod $p) => $p->start_date->lte(now()))
            ?? $periods->first();

        $deployed = Employee::query()
            ->where('employment_category', 'external')
            ->where('status', '!=', 'inactive')
            ->whereNotNull('client_id')
            ->selectRaw('client_id, count(*) as total')
            ->groupBy('client_id')
            ->pluck('total', 'client_id');

        $sheets = $period
            ? ClientTimesheet::where('payroll_period_id', $period->id)->get()->keyBy('client_id')
            : collect();

        $clients = Client::query()
            ->where(fn ($query) => $query->whereIn('id', $deployed->keys())->orWhereIn('id', $sheets->keys()))
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'contact_person']);

        return Inertia::render('HR/Timekeeping/ClientTimesheets', [
            'periods' => $periods->map(fn (PayrollPeriod $p) => [
                'value' => $p->id,
                'label' => $p->name.' ('.$p->start_date->format('M j').' – '.$p->end_date->format('M j, Y').')',
            ]),
            'period' => $period ? ['id' => $period->id, 'name' => $period->name] : null,
            'clients' => $clients->map(function (Client $client) use ($deployed, $sheets) {
                $sheet = $sheets[$client->id] ?? null;

                return [
                    'id' => $client->id,
                    'code' => $client->code,
                    'name' => $client->name,
                    'contact_person' => $client->contact_person,
                    'deployed' => (int) ($deployed[$client->id] ?? 0),
                    'timesheet' => $sheet ? [
                        'id' => $sheet->id,
                        'status' => $sheet->status,
                        'sent_at' => $sheet->sent_at?->toDateString(),
                        'confirmed_at' => $sheet->confirmed_at?->toDateString(),
                    ] : null,
                ];
            }),
        ]);
    }

    public function prepare(Request $request): RedirectResponse
    {
        Gate::authorize('manage', AttendanceLog::class);

        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
            'payroll_period_id' => ['required', 'integer', Rule::exists('payroll_periods', 'id')],
        ]);

        $sheet = $this->timesheets->prepare(
            Client::findOrFail($data['client_id']),
            PayrollPeriod::findOrFail($data['payroll_period_id']),
            $request->user(),
        );

        return redirect()
            ->route('hr.timekeeping.client-timesheets.show', $sheet)
            ->with('success', 'Timesheet prepared from the current time records.');
    }

    public function show(ClientTimesheet $timesheet): Response
    {
        Gate::authorize('manage', AttendanceLog::class);

        $timesheet->load([
            'client:id,code,name,contact_person,address',
            'period:id,name,start_date,end_date',
            'preparer:id,name',
            'lines.employee:id,employee_number,first_name,middle_name,last_name,suffix,position_id',
            'lines.employee.position:id,title',
        ]);

        $lines = $timesheet->lines->sortBy(fn (ClientTimesheetLine $line) => $line->employee?->last_name)->values();

        return Inertia::render('HR/Timekeeping/ClientTimesheetShow', [
            'timesheet' => [
                'id' => $timesheet->id,
                'status' => $timesheet->status,
                'client' => $timesheet->client?->only(['id', 'code', 'name', 'contact_person', 'address']),
                'period' => [
                    'id' => $timesheet->period?->id,
                    'name' => $timesheet->period?->name,
                    'start_date' => $timesheet->period?->start_date->toDateString(),
                    'end_date' => $timesheet->period?->end_date->toDateString(),
                ],
                'prepared_by' => $timesheet->preparer?->name,
                'prepared_at' => $timesheet->updated_at?->toDateTimeString(),
                'sent_at' => $timesheet->sent_at?->toDateTimeString(),
                'confirmed_by_name' => $timesheet->confirmed_by_name,
                'confirmed_at' => $timesheet->confirmed_at?->toDateTimeString(),
                'client_remarks' => $timesheet->client_remarks,
                'lines' => $lines->map(fn (ClientTimesheetLine $line) => [
                    'id' => $line->id,
                    'name' => $line->employee?->full_name,
                    'number' => $line->employee?->employee_number,
                    'position' => $line->employee?->position?->title,
                    'days_worked' => (float) $line->days_worked,
                    'hours_worked' => (float) $line->hours_worked,
                    'late_minutes' => $line->late_minutes,
                    'undertime_minutes' => $line->undertime_minutes,
                    'absent_days' => (float) $line->absent_days,
                    'overtime_hours' => (float) $line->overtime_hours,
                ]),
                'totals' => [
                    'days_worked' => (float) $lines->sum('days_worked'),
                    'hours_worked' => round((float) $lines->sum('hours_worked'), 2),
                    'absent_days' => (float) $lines->sum('absent_days'),
                    'overtime_hours' => round((float) $lines->sum('overtime_hours'), 2),
                ],
            ],
        ]);
    }

    public function send(ClientTimesheet $timesheet): RedirectResponse
    {
        Gate::authorize('manage', AttendanceLog::class);

        $this->timesheets->send($timesheet);

        return back()->with('success', 'Marked as sent to the client.');
    }

    public function answer(Request $request, ClientTimesheet $timesheet): RedirectResponse
    {
        Gate::authorize('manage', AttendanceLog::class);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['confirm', 'dispute'])],
            'confirmed_by_name' => ['required', 'string', 'max:120'],
            'client_remarks' => ['nullable', 'required_if:decision,dispute', 'string', 'max:1000'],
        ], ['client_remarks.required_if' => 'Write down what the client disputes.']);

        $confirmed = $data['decision'] === 'confirm';
        $this->timesheets->answer($timesheet, $confirmed, $data['confirmed_by_name'], $data['client_remarks'] ?? null, $request->user());

        return back()->with('success', $confirmed ? 'Client confirmation recorded.' : 'Client dispute recorded. Correct the records, then prepare the timesheet again.');
    }
}
