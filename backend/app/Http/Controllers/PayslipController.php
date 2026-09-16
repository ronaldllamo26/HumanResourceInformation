<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Payslip;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 4 — payslip viewing. The detail page is print-styled, which is how it
 * becomes a PDF: the browser's own print-to-PDF, no extra dependency.
 */
class PayslipController extends Controller
{
    public function __construct(private readonly PayrollService $payroll) {}

    /** An employee's own payslip history; HR sees everyone's. */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Payslip::class);

        $payslips = $this->payroll->scopedPayslipQuery($request->user())
            ->when(
                ! $request->user()->isHrAdmin(),
                // Employees never see figures from a run still being corrected.
                fn ($query) => $query->whereHas('run', fn ($run) => $run->whereIn('status', ['approved', 'paid'])),
            )
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_runs.payroll_period_id')
            ->orderByDesc('payroll_periods.end_date')
            ->select('payslips.*')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('HR/Payroll/Payslips', [
            'payslips' => [
                'data' => $payslips->map(fn (Payslip $payslip) => [
                    'id' => $payslip->id,
                    'payslip_number' => $payslip->payslip_number,
                    'employee' => $payslip->employee?->full_name,
                    'period' => $payslip->run?->period?->name,
                    'pay_date' => $payslip->run?->period?->pay_date?->toDateString(),
                    'gross_pay' => (float) $payslip->gross_pay,
                    'deductions_total' => (float) $payslip->deductions_total,
                    'net_pay' => (float) $payslip->net_pay,
                    'status' => $payslip->run?->status,
                ]),
                'meta' => [
                    'from' => $payslips->firstItem(),
                    'to' => $payslips->lastItem(),
                    'total' => $payslips->total(),
                    'links' => $payslips->linkCollection()->toArray(),
                ],
            ],
            'isHr' => $request->user()->isHrAdmin(),
        ]);
    }

    public function show(Payslip $payslip): Response
    {
        Gate::authorize('view', $payslip);

        $payslip->load([
            'lines',
            'employee.department:id,name',
            'employee.position:id,title',
            'run.period',
        ]);

        return Inertia::render('HR/Payroll/Payslip', [
            'payslip' => [
                'id' => $payslip->id,
                'payslip_number' => $payslip->payslip_number,

                'employee' => [
                    'full_name' => $payslip->employee->full_name,
                    'employee_number' => $payslip->employee->employee_number,
                    'position' => $payslip->employee->position?->title,
                    'department' => $payslip->employee->department?->name,
                    // A payslip is printed, saved as PDF and emailed around, so
                    // it carries the last four characters only — enough to
                    // recognise, not enough to use.
                    'sss_number' => Employee::mask($payslip->employee->sss_number),
                    'philhealth_number' => Employee::mask($payslip->employee->philhealth_number),
                    'pagibig_number' => Employee::mask($payslip->employee->pagibig_number),
                    'tin' => Employee::mask($payslip->employee->tin),
                    'bank_name' => $payslip->employee->bank_name,
                    'bank_account_number' => Employee::mask($payslip->employee->bank_account_number),
                ],

                'period' => [
                    'name' => $payslip->run->period->name,
                    'start_date' => $payslip->run->period->start_date->toDateString(),
                    'end_date' => $payslip->run->period->end_date->toDateString(),
                    'pay_date' => $payslip->run->period->pay_date->toDateString(),
                ],

                'attendance' => [
                    'days_worked' => (float) $payslip->days_worked,
                    'hours_worked' => (float) $payslip->hours_worked,
                    'overtime_hours' => (float) $payslip->overtime_hours,
                    'night_diff_hours' => (float) $payslip->night_diff_hours,
                    'late_minutes' => (int) $payslip->late_minutes,
                    'undertime_minutes' => (int) $payslip->undertime_minutes,
                    'absent_days' => (float) $payslip->absent_days,
                    'unpaid_leave_days' => (float) $payslip->unpaid_leave_days,
                ],

                'earnings' => $payslip->lines
                    ->where('type', 'earning')
                    ->map(fn ($line) => ['label' => $line->label, 'amount' => (float) $line->amount])
                    ->values(),
                'deductions' => $payslip->lines
                    ->where('type', 'deduction')
                    ->map(fn ($line) => ['label' => $line->label, 'amount' => (float) $line->amount])
                    ->values(),

                'gross_pay' => (float) $payslip->gross_pay,
                'deductions_total' => (float) $payslip->deductions_total,
                'net_pay' => (float) $payslip->net_pay,

                'employer_contributions' => [
                    'sss' => (float) $payslip->sss_employer,
                    'philhealth' => (float) $payslip->philhealth_employer,
                    'pagibig' => (float) $payslip->pagibig_employer,
                    'total' => $payslip->employerContributions(),
                ],

                'run_status' => $payslip->run->status,
            ],
        ]);
    }
}
