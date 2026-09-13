<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payslip extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /**
     * Kept readable after the employee is archived.
     *
     * A payslip is a financial record, and the one thing it may never forget
     * is whose it is. Without `withTrashed()` the row still loads — the run
     * screen joins `employees` directly, and a join ignores the soft-delete
     * scope — while this relation came back null, so the page fataled on
     * `$payslip->employee->full_name` and the SSS R-3 exported a line with
     * money on it and no person attached to it.
     *
     * Filtering archived people *out* is the job of the query that lists
     * them, not of the record's own memory of whose it is.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class);
    }

    public function earnings(): HasMany
    {
        return $this->lines()->where('type', PayslipLine::TYPE_EARNING);
    }

    public function deductions(): HasMany
    {
        return $this->lines()->where('type', PayslipLine::TYPE_DEDUCTION);
    }

    /** Employer share, for the remittance report — never deducted from pay. */
    public function employerContributions(): float
    {
        return round(
            (float) $this->sss_employer
            + (float) $this->philhealth_employer
            + (float) $this->pagibig_employer,
            2,
        );
    }

    protected function casts(): array
    {
        return [
            'days_worked' => 'decimal:2',
            'hours_worked' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
            'night_diff_hours' => 'decimal:2',
            'absent_days' => 'decimal:2',
            'unpaid_leave_days' => 'decimal:2',
            'basic_pay' => 'decimal:2',
            'overtime_pay' => 'decimal:2',
            'night_diff_pay' => 'decimal:2',
            'holiday_pay' => 'decimal:2',
            'allowances_total' => 'decimal:2',
            'gross_pay' => 'decimal:2',
            'sss_employee' => 'decimal:2',
            'philhealth_employee' => 'decimal:2',
            'pagibig_employee' => 'decimal:2',
            'withholding_tax' => 'decimal:2',
            'sss_employer' => 'decimal:2',
            'philhealth_employer' => 'decimal:2',
            'pagibig_employer' => 'decimal:2',
            'late_deduction' => 'decimal:2',
            'undertime_deduction' => 'decimal:2',
            'absence_deduction' => 'decimal:2',
            'unpaid_leave_deduction' => 'decimal:2',
            'loans_deduction' => 'decimal:2',
            'other_deductions' => 'decimal:2',
            'deductions_total' => 'decimal:2',
            'net_pay' => 'decimal:2',
        ];
    }
}
