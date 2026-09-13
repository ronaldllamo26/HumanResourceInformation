<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Allocates this year's credits to every employee and files a spread of leave
 * requests so the workflow, calendar, and balances all have something to show.
 */
class LeaveSeeder extends Seeder
{
    public function run(LeaveService $leave): void
    {
        $types = LeaveType::where('is_active', true)->get();
        $employees = Employee::where('status', '!=', 'inactive')->get();

        if ($types->isEmpty() || $employees->isEmpty()) {
            return;
        }

        $this->allocateCredits($employees, $types);

        if (LeaveRequest::exists()) {
            return;
        }

        $this->fileSampleRequests($leave, $employees, $types);
    }

    private function allocateCredits($employees, $types): void
    {
        foreach ($employees as $employee) {
            foreach ($types->where('default_credits', '>', 0) as $type) {
                LeaveBalance::updateOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'leave_type_id' => $type->id,
                        'year' => now()->year,
                    ],
                    ['credits_earned' => $type->default_credits],
                );
            }
        }
    }

    private function fileSampleRequests(LeaveService $leave, $employees, $types): void
    {
        // Only the common day-to-day types; maternity would skew the samples.
        $filable = $types->whereIn('code', ['SL', 'VL', 'EL'])->values();

        if ($filable->isEmpty()) {
            return;
        }

        $statuses = [
            LeaveRequest::STATUS_APPROVED,
            LeaveRequest::STATUS_APPROVED,
            LeaveRequest::STATUS_SUPERVISOR_APPROVED,
            LeaveRequest::STATUS_PENDING,
            LeaveRequest::STATUS_PENDING,
            LeaveRequest::STATUS_REJECTED,
        ];

        $filed = 0;

        $sample = $employees->random(min(18, $employees->count()))->values();

        foreach ($sample as $index => $employee) {
            $type = $filable[$index % $filable->count()];

            // Spread across last month, this month, and next. Nudge off the
            // weekend, or the whole range can land on rest days and file zero
            // working days — which the real form would refuse.
            $start = Carbon::today()->addDays(random_int(-25, 25))->startOfDay();

            while ($start->dayOfWeekIso >= 6) {
                $start->addDay();
            }

            $request = $leave->file($employee, $type, [
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->addDays(random_int(0, 2))->toDateString(),
                'reason' => 'Seeded sample leave request.',
            ]);

            // A holiday can still swallow the range; don't seed an empty request.
            if ((float) $request->days_requested <= 0) {
                $request->delete();

                continue;
            }

            $status = $statuses[$index % count($statuses)];

            if ($status === LeaveRequest::STATUS_APPROVED) {
                $request->update([
                    'status' => LeaveRequest::STATUS_APPROVED,
                    'supervisor_acted_at' => now(),
                    'hr_acted_at' => now(),
                ]);

                // Mirror what approveByHr would have spent.
                $balance = $leave->balanceFor($employee, $type, $start->year);
                $balance->update([
                    'credits_used' => (float) $balance->credits_used + (float) $request->days_requested,
                ]);
            } elseif ($status !== LeaveRequest::STATUS_PENDING) {
                $request->update(['status' => $status, 'supervisor_acted_at' => now()]);
            }

            $filed++;
        }

        $this->command?->info("Seeded {$filed} leave requests.");
    }
}
