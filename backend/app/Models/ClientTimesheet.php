<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The attendance of everybody deployed to one client for one payroll period,
 * as the client confirmed it.
 *
 * In a manpower agency the client is the one who saw the work done, so their
 * confirmation is what both the employee's pay and the client's bill rest on.
 */
class ClientTimesheet extends Model
{
    use Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_DISPUTED = 'disputed';

    protected $guarded = ['id'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ClientTimesheetLine::class);
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }
}
