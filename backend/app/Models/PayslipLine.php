<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An itemised line on a payslip. The payslip carries the totals; these explain
 * how each one was reached.
 */
class PayslipLine extends Model
{
    public const TYPE_EARNING = 'earning';

    public const TYPE_DEDUCTION = 'deduction';

    protected $guarded = ['id'];

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
