<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of the document scanner, and what became of it.
 *
 * See the migration for why this exists rather than a confidence column. In
 * short: the model's own confidence was measured useless, so accuracy is taken
 * from what HR did with the proposal instead of from what the model claimed
 * about it.
 */
class DocumentScan extends Model
{
    use HasFactory;

    /**
     * The fields worth comparing.
     *
     * `document_number` and `name_on_document` are read but never saved on the
     * document — they are checks, not stored values — so they cannot be
     * compared this way and are left out rather than counted as always-wrong.
     */
    public const COMPARED = ['type', 'title', 'issued_at', 'expires_at'];

    protected $guarded = ['id'];

    public function employee(): BelongsTo
    {
        // A scan outlives the employee record the way a payslip does; see
        // Payslip::employee().
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'employee_document_id');
    }

    public function scanner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }

    /**
     * Records what the document was filed with, and which proposals survived.
     *
     * Comparison is on the *saved* value, loosely: a date is compared as a
     * date string and text case-insensitively after trimming, because
     * "Clearance" and "clearance " are the same answer and counting them as a
     * correction would understate the scanner by rewarding whitespace.
     *
     * @param  array<string, mixed>  $saved
     */
    public function recordOutcome(EmployeeDocument $document, array $saved): void
    {
        $proposed = $this->proposed ?? [];

        $accepted = [];
        $corrected = [];

        foreach (self::COMPARED as $field) {
            $was = $this->comparable($proposed[$field] ?? null);
            $now = $this->comparable($saved[$field] ?? null);

            // A field the scanner did not propose is not a correction — there
            // was nothing to correct. It is simply outside this measurement.
            if ($was === null) {
                continue;
            }

            $was === $now ? $accepted[] = $field : $corrected[] = $field;
        }

        $this->update([
            'employee_document_id' => $document->id,
            'saved' => array_intersect_key($saved, array_flip(self::COMPARED)),
            'accepted' => $accepted,
            'corrected' => $corrected,
        ]);
    }

    protected function casts(): array
    {
        return [
            'proposed' => 'array',
            'saved' => 'array',
            'accepted' => 'array',
            'corrected' => 'array',
        ];
    }

    private function comparable(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Dates arrive as "2027-03-14" from the scanner and can arrive as a
        // Carbon-formatted string from the form; both reduce to the date.
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return mb_strtolower(trim((string) $value));
    }
}
