<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Records who *took* personal data out, into the same `audit_logs` table.
 *
 * The system already answers two of the three questions an auditor asks.
 * `Auditable` answers "who changed this record"; `RecordAuthenticationEvents`
 * answers "who was in the system". Neither answers **"who read it"** — and for
 * an HRIS that is the question with teeth. Every access gate in Modules 1, 2,
 * and 4 was already correct; what was missing was any trace afterwards.
 *
 * Two things are worth a row, and they are not the same size:
 *
 * - **`accessed`** — one person opened one 201-file document. That file is a
 *   photograph of somebody's PhilSys ID or NBI clearance.
 * - **`exported`** — a CSV left the system carrying many people at once. The
 *   BIR alphalist alone holds every employee's TIN, SSS number, and annual
 *   pay. Without a row here, a full extract of the workforce's government
 *   numbers leaves no trace at all.
 *
 * Under RA 10173 a personal information controller has to be able to account
 * for how personal data was processed. "We gated it correctly" is only half an
 * answer; this is the other half.
 */
class DataAccessLogger
{
    public const EVENT_ACCESSED = 'accessed';

    public const EVENT_EXPORTED = 'exported';

    /** A batch written from a file — see imported() for why it is here. */
    public const EVENT_IMPORTED = 'imported';

    /**
     * The events this logger writes, for filtering the Security screen's log
     * by kind. `imported` is a *write* and the other two are reads, but they
     * share this class because they share the thing that makes them worth
     * recording: each is an act on many people's records at once, which the
     * per-model audit rows describe one at a time and never as one act.
     */
    public const EVENTS = [self::EVENT_ACCESSED, self::EVENT_EXPORTED, self::EVENT_IMPORTED];

    /**
     * One person opened one record's file.
     *
     * @param  string  $how  which route served it — `download` or `preview`,
     *                       kept apart because one leaves a copy on a machine
     *                       and the other does not.
     * @param  array<string, mixed>  $context
     */
    public function accessed(Model $subject, string $how, array $context = []): void
    {
        $this->write(
            event: self::EVENT_ACCESSED,
            type: $subject::class,
            id: $subject->getKey(),
            details: ['how' => $how, ...$context],
        );
    }

    /**
     * A report left the system carrying more than one person.
     *
     * `auditable_id` is null and `auditable_type` is not, which is the same
     * split a failed sign-in uses: an export is about many rows, so there is
     * no single id to point at, but the *kind* of record taken is always
     * known and is the first thing anyone reading the log wants.
     *
     * @param  string  $report  what was taken, e.g. "compliance:alphalist"
     * @param  class-string  $subject  the model the rows came from
     * @param  array<string, mixed>  $context  the filters it was taken under —
     *                                         the period and scope are what
     *                                         make the row answer anything
     */
    public function exported(string $report, string $subject, array $context = []): void
    {
        $this->write(
            event: self::EVENT_EXPORTED,
            type: $subject,
            id: null,
            details: ['report' => $report, ...$context],
        );
    }

    /**
     * A batch of records was written from a file.
     *
     * The counterpart to `exported()`, and it exists because the per-row
     * report an importer produces is *flashed to the session* — it is on
     * screen once and gone on the next page load. An import that quietly
     * dropped twelve rows would then have no record anywhere, which is the
     * opposite of what a retention policy is for: the rows that did not land
     * are exactly the ones somebody comes looking for a month later.
     *
     * Same null-`auditable_id` split as an export: a batch is about many rows.
     * The individual writes are audited on their own models as usual — this
     * row is the batch, not a substitute for them.
     *
     * @param  string  $source  what was fed in, e.g. "attendance:biometric"
     * @param  class-string  $subject  the model the rows were written to
     * @param  array<string, mixed>  $context  counts and the rows refused —
     *                                         a count with no reasons cannot
     *                                         be acted on later
     */
    public function imported(string $source, string $subject, array $context = []): void
    {
        $this->write(
            event: self::EVENT_IMPORTED,
            type: $subject,
            id: null,
            details: ['source' => $source, ...$context],
        );
    }

    /** @param array<string, mixed> $details */
    private function write(string $event, ?string $type, ?int $id, array $details): void
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'auditable_type' => $type,
            'auditable_id' => $id,
            'event' => $event,
            'old_values' => null,
            // The detail goes in `new_values` rather than a column of its own:
            // the table is shared with model changes and the Security screen
            // already knows how to render that side.
            'new_values' => $details,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }
}
