<?php

namespace App\Models;

use App\Services\AuditLogSigner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'auditable_type',
        'auditable_id',
        'event',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /*
     * Signed the moment it is written. After `created` rather than before,
     * because the id is part of what is signed — without it a row could be
     * copied over another and still verify.
     */
    protected static function booted(): void
    {
        static::created(fn (AuditLog $log) => app(AuditLogSigner::class)->signAndStore($log));
    }

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }
}
