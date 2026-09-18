<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Makes the audit log tamper-evident.
 *
 * Every row carries an HMAC-SHA256 of its own contents, keyed from APP_KEY.
 * Somebody who can edit the database but does not hold the key cannot change
 * who did something, what they changed, or when, without the signature ceasing
 * to match — and `verify()` says which rows. That is the property an auditor
 * (BIR, SSS, PhilHealth, Pag-IBIG, the NPC) asks of a trail: not that it
 * cannot be touched, but that touching it shows.
 *
 * What it does not do, stated: someone holding APP_KEY *and* the database can
 * re-sign an edit, and deleting the newest rows leaves no gap to find. Gaps in
 * the id sequence are reported, but a rolled-back transaction leaves one too,
 * so a gap is a question rather than proof.
 */
class AuditLogSigner
{
    public function sign(AuditLog $log): string
    {
        return hash_hmac('sha256', $this->canonical($log), $this->key());
    }

    public function signAndStore(AuditLog $log): void
    {
        // Straight through the query builder: saving the model would fire its
        // events again, and the row is signed exactly as it now sits.
        DB::table($log->getTable())->where('id', $log->id)->update(['signature' => $this->sign($log)]);
    }

    /**
     * @return array{checked: int, valid: int, altered: array<int, int>, unsigned: int, gaps: int}
     */
    public function verify(): array
    {
        $result = ['checked' => 0, 'valid' => 0, 'altered' => [], 'unsigned' => 0, 'gaps' => 0];
        $previousId = null;

        AuditLog::query()->orderBy('id')->chunkById(500, function ($logs) use (&$result, &$previousId) {
            foreach ($logs as $log) {
                $result['checked']++;

                if ($previousId !== null && $log->id > $previousId + 1) {
                    $result['gaps'] += $log->id - $previousId - 1;
                }
                $previousId = $log->id;

                if ($log->signature === null) {
                    $result['unsigned']++;
                } elseif (hash_equals($log->signature, $this->sign($log))) {
                    $result['valid']++;
                } elseif (count($result['altered']) < 50) {
                    $result['altered'][] = $log->id;
                }
            }
        });

        return $result;
    }

    /**
     * The row as one string, in a fixed shape: JSON keys sorted so the same
     * values always produce the same text, whatever order the database hands
     * them back in, and the timestamp in whole seconds as the column stores it.
     */
    private function canonical(AuditLog $log): string
    {
        $fields = [
            $log->id,
            $log->user_id ?? '',
            $log->auditable_type ?? '',
            $log->auditable_id ?? '',
            $log->event,
            $this->json($log->old_values),
            $this->json($log->new_values),
            $log->ip_address ?? '',
            $log->user_agent ?? '',
            $log->created_at?->getTimestamp() ?? '',
        ];

        /*
         * `impersonated_by` joins the signature **only when it is set**, and
         * the condition is the whole point rather than a shortcut.
         *
         * Appended unconditionally, every row written before the column
         * existed would gain an empty field in its canonical string, its
         * stored signature would stop matching, and `verify()` would report
         * all 1,896 of them as altered — a tamper-evident trail crying
         * tamper at its own migration is one nobody will trust the next time
         * it speaks. Left out of the signature altogether it would be the one
         * field on the row that could be edited with no signature to break,
         * and it is precisely the field somebody covering their tracks would
         * edit: clearing it turns an administrator's action into the
         * employee's own.
         *
         * Conditional inclusion holds in both directions. Clearing a real
         * impersonator drops the field and breaks the signature; inventing
         * one on an old row adds it and breaks the signature.
         */
        if ($log->impersonated_by !== null) {
            $fields[] = 'impersonated_by='.$log->impersonated_by;
        }

        return implode("\x1F", $fields);
    }

    private function json(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return json_encode($this->sortKeys($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->sortKeys($item), $value);
    }

    /** A key used only for this, so a signature is useless for anything else. */
    private function key(): string
    {
        $appKey = (string) config('app.key');

        if (str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7));
        }

        return hash_hmac('sha256', 'audit-log-signature', $appKey, true);
    }
}
