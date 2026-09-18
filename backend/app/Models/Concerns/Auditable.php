<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use App\Services\ImpersonationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;

/**
 * Records create / update / delete events against the model into `audit_logs`.
 *
 * Models may declare `$auditExclude` to keep noisy or sensitive columns out of
 * the recorded diff.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => $model->writeAuditLog('created', null, $model->auditableAttributes($model->getAttributes())));

        static::updated(function (Model $model) {
            $changes = $model->auditableAttributes($model->getChanges());

            // Nothing meaningful changed once excluded columns are stripped.
            if ($changes === []) {
                return;
            }

            $before = array_intersect_key($model->auditableAttributes($model->getOriginal()), $changes);

            $model->writeAuditLog('updated', $before, $changes);
        });

        static::deleted(fn (Model $model) => $model->writeAuditLog('deleted', $model->auditableAttributes($model->getOriginal()), null));
    }

    public function audits(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable')->latest();
    }

    /** @return array<string, mixed> */
    protected function auditableAttributes(array $attributes): array
    {
        $excluded = array_merge(
            ['updated_at', 'created_at', 'remember_token', 'password'],
            property_exists($this, 'auditExclude') ? $this->auditExclude : [],
        );

        return array_diff_key($attributes, array_flip($excluded));
    }

    protected function writeAuditLog(string $event, ?array $old, ?array $new): void
    {
        $request = request();

        AuditLog::create([
            'user_id' => Auth::id(),
            /*
             * Who was really at the keyboard.
             *
             * During an impersonation `Auth::id()` is the employee, so without
             * this every row an administrator writes while impersonating would
             * be filed under the person it was done to — an administrator's
             * edit appearing in the trail as the employee's own. Read straight
             * from the session rather than through the service, because this
             * runs inside a model event and a container resolution here would
             * fire on every audited write in the system.
             */
            'impersonated_by' => session(ImpersonationService::SESSION_KEY),
            'auditable_type' => static::class,
            'auditable_id' => $this->getKey(),
            'event' => $event,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
