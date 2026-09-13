<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Key/value application settings.
 *
 * Reads go through a single cached map — settings are read on nearly every
 * request and change rarely, so one query per boot rather than one per key.
 */
class Setting extends Model
{
    /** Defaults for every key the application understands. */
    public const DEFAULTS = [
        // --- Company profile ---
        'company.name' => 'PrimePower',
        'company.tagline' => 'Human Resource Information System',
        'company.address' => '',
        'company.email' => '',
        'company.phone' => '',
        'company.tin' => '',
        'company.sss_employer_number' => '',
        'company.philhealth_employer_number' => '',
        'company.pagibig_employer_number' => '',

        // --- Regional ---
        'regional.timezone' => 'Asia/Manila',
        'regional.date_format' => 'M j, Y',
        'regional.currency' => 'PHP',
        'regional.week_starts_on' => 1,

        // --- Notifications (in-app; email is not wired up yet) ---
        'notifications.leave_filed' => true,
        'notifications.leave_endorsed' => true,
        'notifications.overtime_filed' => true,
        'notifications.payroll_for_approval' => true,
        'notifications.review_assigned' => true,
        'notifications.document_expiring' => true,
        'notifications.expiry_lead_days' => 30,

        // --- Data retention ---
        'data.audit_retention_days' => 365,
    ];

    private const CACHE_KEY = 'settings.all';

    protected $guarded = ['id'];

    /** All settings, defaults merged under whatever is stored. */
    public static function all($columns = ['*']): mixed
    {
        // Preserve Eloquent's signature when called as a query.
        if ($columns !== ['*']) {
            return parent::all($columns);
        }

        return Cache::rememberForever(self::CACHE_KEY, function () {
            $stored = static::query()->pluck('value', 'key')
                ->map(fn ($value) => $value['v'] ?? null)
                ->all();

            return [...self::DEFAULTS, ...array_filter($stored, fn ($v) => $v !== null)];
        });
    }

    public static function get(string $key, mixed $fallback = null): mixed
    {
        return self::all()[$key] ?? $fallback ?? self::DEFAULTS[$key] ?? null;
    }

    /** @param array<string, mixed> $values */
    public static function setMany(array $values, string $group = 'general'): void
    {
        foreach ($values as $key => $value) {
            static::updateOrCreate(
                ['key' => $key],
                // Wrapped so scalars survive the json cast intact.
                ['value' => ['v' => $value], 'group' => $group],
            );
        }

        Cache::forget(self::CACHE_KEY);
    }

    /** Only the keys in a namespace, e.g. `company`. */
    public static function group(string $prefix): array
    {
        $settings = self::all();

        return array_filter(
            $settings,
            fn ($key) => str_starts_with($key, "{$prefix}."),
            ARRAY_FILTER_USE_KEY,
        );
    }

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
