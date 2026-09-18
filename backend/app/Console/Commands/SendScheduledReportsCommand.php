<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PayrollPeriod;
use App\Models\Setting;
use App\Services\DataAccessLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled HR automated reports generation.
 *
 * Compiles routine management digests and compliance metrics for HR
 * administrators on a weekly schedule.
 */
class SendScheduledReportsCommand extends Command
{
    protected $signature = 'hris:send-scheduled-reports';

    protected $description = 'Generate and log scheduled HR metric digests and compliance reports';

    public function handle(DataAccessLogger $logger): int
    {
        $this->info('Generating scheduled HR compliance and operational report digest...');

        $metrics = [
            'active_employees' => Employee::where('employment_status', 'regular')
                ->orWhere('employment_status', 'probationary')
                ->count(),
            'pending_leaves' => LeaveRequest::where('status', LeaveRequest::STATUS_PENDING)->count(),
            'unfinalized_payrolls' => PayrollPeriod::where('status', '!=', PayrollPeriod::STATUS_PAID)->count(),
            'timestamp' => now()->toIso8601String(),
        ];

        $company = Setting::get('company.name', config('app.name'));

        // Log executive digest generation
        $logger->exported('scheduled-executive-summary', Employee::class, [
            'type' => 'automated_digest',
            'metrics' => $metrics,
        ]);

        Log::info("Scheduled HR report generated for {$company}", $metrics);

        $this->table(
            ['Metric', 'Current Value'],
            [
                ['Active Workforce', $metrics['active_employees']],
                ['Pending Leave Approvals', $metrics['pending_leaves']],
                ['Active / Open Payrolls', $metrics['unfinalized_payrolls']],
                ['Generated At', $metrics['timestamp']],
            ],
        );

        $this->info('Scheduled reports generation completed successfully.');

        return self::SUCCESS;
    }
}
