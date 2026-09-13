<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Setting;
use App\Services\DataAccessLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV exports from Settings > Data & Backup.
 *
 * Streamed rather than built in memory, so a large directory does not have to
 * fit in PHP's memory limit before the download starts.
 */
class DataExportController extends Controller
{
    public function employees(Request $request, DataAccessLogger $access): StreamedResponse
    {
        Gate::authorize('manage', Setting::class);

        // The whole directory in one file — the broadest extract there is.
        $access->exported('employee-directory', Employee::class, [
            'employees' => Employee::count(),
        ]);

        $filename = 'employees-'.now()->toDateString().'.csv';

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Employee Number', 'Last Name', 'First Name', 'Middle Name',
                'Department', 'Position', 'Employment Status', 'Record Status',
                'Date Hired', 'Email', 'Mobile',
                'SSS', 'PhilHealth', 'Pag-IBIG', 'TIN', 'Basic Salary',
            ]);

            Employee::with(['department:id,name', 'position:id,title'])
                ->orderBy('last_name')
                ->chunk(200, function ($employees) use ($handle) {
                    foreach ($employees as $employee) {
                        fputcsv($handle, [
                            $employee->employee_number,
                            $employee->last_name,
                            $employee->first_name,
                            $employee->middle_name,
                            $employee->department?->name,
                            $employee->position?->title,
                            $employee->employment_status,
                            $employee->status,
                            $employee->date_hired?->toDateString(),
                            $employee->email,
                            $employee->mobile_number,
                            $employee->sss_number,
                            $employee->philhealth_number,
                            $employee->pagibig_number,
                            $employee->tin,
                            $employee->basic_salary,
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
