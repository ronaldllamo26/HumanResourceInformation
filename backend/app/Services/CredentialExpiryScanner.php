<?php

namespace App\Services;

use App\Models\EmployeeDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which 201-file documents are about to lapse — or already have.
 *
 * `EmployeeDocument::isExpired()` answers this one document at a time, and only
 * once it is too late to do anything about it. Renewals need lead time, so this
 * looks forward instead: it reports what expires inside each type's warning
 * window, per config/credentials.php.
 *
 * Config-driven and database-free, the same pattern as AttendanceCalculator and
 * AttendanceExceptionScanner — it takes a query and returns rows, so the
 * date arithmetic is unit tested without a document on disk.
 */
class CredentialExpiryScanner
{
    public const STATUS_EXPIRED = 'expired';

    public const STATUS_EXPIRING = 'expiring';

    /**
     * @return Collection<int, array<string, mixed>> lapsed first, then whatever
     *                                               runs out soonest
     */
    public function scan(Builder $query): Collection
    {
        $today = Carbon::today();

        $documents = (clone $query)->reorder()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $today->copy()->addDays(60))
            ->with('employee:id,employee_number,first_name,middle_name,last_name,suffix,department_id')
            ->with('employee.department:id,name')
            ->get();

        return $documents
            ->map(fn (EmployeeDocument $document) => $this->evaluate($document, $today))
            ->filter()
            // Expired outranks expiring; inside each, the tightest deadline
            // first. Sorting on the words would order them alphabetically by
            // accident, so the weight is explicit.
            ->sortBy([
                fn (array $row) => $row['status'] === self::STATUS_EXPIRED ? 0 : 1,
                fn (array $row) => $row['days_remaining'],
            ])
            ->values();
    }

    /** The count for the topbar indicator — the same rules, without loading relations or sorting. */
    public function countFor(Builder $query): int
    {
        $today = Carbon::today();

        // Max warning window across all types is 60 days (config/credentials.php)
        return (clone $query)->reorder()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $today->copy()->addDays(60))
            ->get(['id', 'type', 'expires_at'])
            ->filter(function (EmployeeDocument $document) use ($today) {
                $days = (int) $today->diffInDays($document->expires_at->copy()->startOfDay(), false);
                $window = $this->windowFor($document->type);

                return $days < 0 || $days <= $window;
            })
            ->count();
    }

    /**
     * Null when the document is still comfortably in date; there is nothing to
     * report about a licence that runs to next year.
     *
     * @return array<string, mixed>|null
     */
    private function evaluate(EmployeeDocument $document, Carbon $today): ?array
    {
        $expiry = $document->expires_at->copy()->startOfDay();
        $days = (int) $today->diffInDays($expiry, false);

        $window = $this->windowFor($document->type);

        if ($days >= 0 && $days > $window) {
            return null;
        }

        $expired = $days < 0;

        return [
            'id' => $document->id,
            'employee_id' => $document->employee_id,
            'employee_name' => $document->employee?->full_name ?? '—',
            'employee_number' => $document->employee?->employee_number,
            'department' => $document->employee?->department?->name,
            'type' => $document->type,
            'type_label' => $this->typeLabel($document->type),
            'title' => $document->title,
            'expires_at' => $expiry->toDateString(),
            'days_remaining' => $days,
            'status' => $expired ? self::STATUS_EXPIRED : self::STATUS_EXPIRING,
            // An expired certificate is untidy; an expired licence means the
            // employee cannot legally do the job they are rostered for.
            'blocking' => in_array($document->type, config('credentials.blocking_types', []), true),
            'detail' => $this->detail($document, $days, $expired),
        ];
    }

    private function windowFor(string $type): int
    {
        return (int) (config("credentials.warning_days.{$type}")
            ?? config('credentials.default_warning_days'));
    }

    private function detail(EmployeeDocument $document, int $days, bool $expired): string
    {
        $label = $this->typeLabel($document->type);
        $date = $document->expires_at->toFormattedDateString();

        if ($expired) {
            $overdue = abs($days);

            return "{$label} lapsed on {$date} — {$overdue} day(s) ago.";
        }

        if ($days === 0) {
            return "{$label} expires today ({$date}).";
        }

        return "{$label} expires in {$days} day(s), on {$date}.";
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'drivers_license' => "Driver's licence",
            'government_id' => 'Government ID',
            'medical' => 'Medical certificate',
            'clearance' => 'Clearance',
            'certificate' => 'Certificate',
            'contract' => 'Contract',
            'resume' => 'Resume',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }
}
