<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientTimesheet;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The attendance of everybody deployed to a client, sent to that client to
 * confirm.
 *
 * In a manpower agency the client is who saw the work done. A timesheet
 * snapshots the DTR totals when it is prepared, is sent (printed or emailed
 * outside this system), and records the client's answer — confirmed, or
 * disputed with their remarks. The snapshot is what they signed, so a record
 * corrected later does not silently change a timesheet already confirmed;
 * preparing it again is a visible act.
 */
class ClientTimesheetService
{
    public function __construct(private readonly TimekeepingService $timekeeping) {}

    public function prepare(Client $client, PayrollPeriod $period, User $by): ClientTimesheet
    {
        $existing = ClientTimesheet::query()
            ->where('client_id', $client->id)
            ->where('payroll_period_id', $period->id)
            ->first();

        if ($existing?->isConfirmed()) {
            throw ValidationException::withMessages([
                'timesheet' => 'The client already confirmed this timesheet, so it can no longer be prepared again.',
            ]);
        }

        $employees = Employee::query()
            ->where('client_id', $client->id)
            ->where('employment_category', 'external')
            ->where('status', '!=', 'inactive')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->pluck('id')
            ->all();

        if ($employees === []) {
            throw ValidationException::withMessages([
                'timesheet' => "Nobody is deployed to {$client->name}, so there is no attendance to send.",
            ]);
        }

        [$from, $to] = $this->timekeeping->periodRange($period);
        $summaries = $this->timekeeping->summaries($employees, $from, $to);

        return DB::transaction(function () use ($existing, $client, $period, $by, $summaries) {
            $sheet = $existing ?? new ClientTimesheet([
                'client_id' => $client->id,
                'payroll_period_id' => $period->id,
            ]);

            $sheet->fill([
                'status' => ClientTimesheet::STATUS_DRAFT,
                'prepared_by' => $by->id,
                'sent_at' => null,
                'confirmed_by_name' => null,
                'confirmed_at' => null,
                'client_remarks' => null,
                'recorded_by' => null,
            ])->save();

            $sheet->lines()->delete();

            foreach ($summaries as $employeeId => $summary) {
                $sheet->lines()->create([
                    'employee_id' => $employeeId,
                    'days_worked' => $summary['days_worked'],
                    'hours_worked' => round($summary['minutes_worked'] / 60, 2),
                    'late_minutes' => $summary['late_minutes'],
                    'undertime_minutes' => $summary['undertime_minutes'],
                    'absent_days' => $summary['absent_days'],
                    'overtime_hours' => $summary['overtime_hours'],
                ]);
            }

            return $sheet->load('lines');
        });
    }

    public function send(ClientTimesheet $sheet): ClientTimesheet
    {
        if ($sheet->status !== ClientTimesheet::STATUS_DRAFT) {
            throw ValidationException::withMessages(['timesheet' => 'Only a draft timesheet can be marked sent.']);
        }

        $sheet->update(['status' => ClientTimesheet::STATUS_SENT, 'sent_at' => now()]);

        return $sheet;
    }

    /**
     * Records the client's answer. The name is the client's representative as
     * written on the signed sheet; `recorded_by` is who typed it in here.
     */
    public function answer(ClientTimesheet $sheet, bool $confirmed, string $name, ?string $remarks, User $by): ClientTimesheet
    {
        if ($sheet->status !== ClientTimesheet::STATUS_SENT) {
            throw ValidationException::withMessages(['timesheet' => 'Mark the timesheet sent before recording the client\'s answer.']);
        }

        $sheet->update([
            'status' => $confirmed ? ClientTimesheet::STATUS_CONFIRMED : ClientTimesheet::STATUS_DISPUTED,
            'confirmed_by_name' => $name,
            'confirmed_at' => now(),
            'client_remarks' => $remarks,
            'recorded_by' => $by->id,
        ]);

        return $sheet;
    }
}
