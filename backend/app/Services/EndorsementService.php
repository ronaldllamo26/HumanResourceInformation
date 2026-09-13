<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeEndorsement;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Core 1 → Core 2 handover.
 *
 * Core 1 recruits and Core 2 employs, and this is the seam between them. It
 * does three things: takes what Core 1 sends, turns an accepted endorsement
 * into the shape this system's employee form expects, and records the
 * decision either way.
 *
 * What it deliberately does *not* do is create the employee. That still goes
 * through `EmployeeService::create()`, the one place an employee has ever been
 * made — employee numbering, the photo, and the optional login all live there,
 * and a second creation path would eventually disagree with the first about
 * one of them. This class hands the form its starting values and, afterwards,
 * links the record it produced back to the endorsement it came from.
 */
class EndorsementService
{
    /**
     * Fields Core 1 may send that map straight onto the employee form.
     *
     * Everything here is identity, contact, or a government number — facts
     * about the person that recruitment already collected and that do not
     * change on the way over. Absent, on purpose: salary, department,
     * position, client, employment status, and pay frequency. Those are
     * decisions this system makes about somebody it is taking on, and a
     * recruitment system is not in a position to make them. Accepting them
     * over the wire would let Core 1 set what PrimePower pays.
     */
    private const CARRIED_FIELDS = [
        'first_name', 'middle_name', 'last_name', 'suffix',
        'birth_date', 'birth_place', 'gender', 'civil_status',
        'nationality', 'religion', 'blood_type',
        'email', 'mobile_number', 'phone_number',
        'present_address', 'permanent_address',
        'emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_number',
        'sss_number', 'philhealth_number', 'pagibig_number', 'tin',
        'drivers_license_number', 'license_dl_codes', 'license_conditions', 'license_expiry',
    ];

    /**
     * Files what Core 1 sent, or returns the row already filed under it.
     *
     * Idempotent on `reference` because a timeout on Core 1's side is
     * indistinguishable from a failure — they will retry, and two rows for one
     * person is two employee numbers and one person paid twice. A retry after
     * a decision returns the decided row rather than reopening it: Core 1
     * learns the outcome, and an answer already given is not quietly undone by
     * the sender repeating the question.
     */
    public function receive(array $data, string $source = 'core1'): EmployeeEndorsement
    {
        $existing = EmployeeEndorsement::where('reference', $data['reference'])->first();

        if ($existing) {
            return $existing;
        }

        return EmployeeEndorsement::create([
            'reference' => $data['reference'],
            'source' => $source,
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'suffix' => $data['suffix'] ?? null,
            'email' => $data['email'] ?? null,
            'mobile_number' => $data['mobile_number'] ?? null,
            'position_title' => $data['position_title'] ?? null,
            'client_name' => $data['client_name'] ?? null,
            'date_hired' => $data['date_hired'] ?? null,
            'payload' => $data,
            'status' => EmployeeEndorsement::STATUS_PENDING,
        ]);
    }

    /**
     * The employee form's starting values for this endorsement.
     *
     * Only what Core 1 actually sent — a key it omitted is left out entirely
     * rather than sent as an empty string, so the form's own defaults survive
     * (`nationality` is 'Filipino' until somebody says otherwise). Same rule
     * the 201-form scanner follows: filling a field with a blank is not
     * filling it, it is erasing what was there.
     */
    public function prefill(EmployeeEndorsement $endorsement): array
    {
        $payload = $endorsement->payload ?? [];

        $values = collect(self::CARRIED_FIELDS)
            ->mapWithKeys(fn (string $field) => [$field => $payload[$field] ?? null])
            ->filter(fn ($value) => filled($value))
            ->all();

        // The list columns are the authority on identity: they were lifted out
        // of the payload at receipt and are what the inbox has been showing
        // the person making this decision.
        $values['first_name'] = $endorsement->first_name;
        $values['middle_name'] = $endorsement->middle_name;
        $values['last_name'] = $endorsement->last_name;
        $values['suffix'] = $endorsement->suffix;
        $values['email'] = $endorsement->email;
        $values['mobile_number'] = $endorsement->mobile_number;

        if ($endorsement->date_hired) {
            $values['date_hired'] = $endorsement->date_hired->toDateString();
        }

        return array_filter($values, fn ($value) => filled($value));
    }

    /**
     * This system's best guess at the master data Core 1 named in words.
     *
     * Core 1 sends "Driver" and "Metro Fleet Logistics"; this system files
     * against a `position_id` and a `client_id`. The match is offered to the
     * form as a starting value and is **never** applied on its own — a wrong
     * position is a wrong salary band and a wrong client is a bill to the
     * wrong company, and neither is worth guessing at silently.
     *
     * A miss returns null and the form's select stays empty, which is the
     * right outcome: an unmatched title means HR chooses, not that the system
     * invents master data. Inventing it is what the bulk importer refuses to
     * do for the same reason — a spreadsheet must not reshape the org chart
     * behind `manageOrganization`'s back, and neither must another system.
     */
    public function suggestions(EmployeeEndorsement $endorsement): array
    {
        return [
            'position_id' => $this->matchByName(Position::query(), 'title', $endorsement->position_title),
            'client_id' => $this->matchByName(Client::query(), 'name', $endorsement->client_name),
        ];
    }

    /**
     * Everything the employee form should open with for this endorsement.
     *
     * One method rather than the caller merging `prefill()` and
     * `suggestions()` itself, because the two interact: naming a client makes
     * somebody **external**, and `StoreEmployeeRequest` *prohibits* a
     * `client_id` on internal staff rather than merely ignoring it. Prefilling
     * the client while leaving the category at its 'internal' default would
     * hand the reviewer a form that refuses to save, with the error on a field
     * they did not touch — and the fix would be to clear the correct value.
     *
     * Setting the category is an inference, and it is the honest one: Core 1
     * naming a client means the person was recruited to be deployed there. It
     * is still only a default on a form somebody reads before saving.
     */
    public function formDefaults(EmployeeEndorsement $endorsement): array
    {
        $values = $this->prefill($endorsement);

        foreach (array_filter($this->suggestions($endorsement)) as $field => $id) {
            $values[$field] = $id;
        }

        if (isset($values['client_id'])) {
            $values['employment_category'] = Employee::CATEGORY_EXTERNAL;
        }

        return $values;
    }

    /**
     * Records that this endorsement became that employee.
     *
     * Called after `EmployeeService::create()` has done the work, so a failure
     * to create leaves the endorsement pending and re-answerable rather than
     * approved with nothing behind it.
     */
    public function approve(EmployeeEndorsement $endorsement, Employee $employee, User $decidedBy): EmployeeEndorsement
    {
        return DB::transaction(function () use ($endorsement, $employee, $decidedBy) {
            $endorsement->update([
                'status' => EmployeeEndorsement::STATUS_APPROVED,
                'employee_id' => $employee->id,
                'decided_by' => $decidedBy->id,
                'decided_at' => now(),
            ]);

            return $endorsement->fresh();
        });
    }

    /**
     * Declines an endorsement, with the reason Core 1 will read back.
     *
     * The reason is required by the form request rather than optional here: a
     * recruiter told only "rejected" sends the same candidate again, and the
     * queue fills up with the same unstated argument.
     */
    public function reject(EmployeeEndorsement $endorsement, User $decidedBy, string $reason): EmployeeEndorsement
    {
        $endorsement->update([
            'status' => EmployeeEndorsement::STATUS_REJECTED,
            'decided_by' => $decidedBy->id,
            'decided_at' => now(),
            'decision_note' => $reason,
        ]);

        return $endorsement->fresh();
    }

    /** Counts for the inbox's summary tiles — the whole table, not the filtered view. */
    public function statistics(): array
    {
        $counts = EmployeeEndorsement::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'pending' => (int) ($counts[EmployeeEndorsement::STATUS_PENDING] ?? 0),
            'approved' => (int) ($counts[EmployeeEndorsement::STATUS_APPROVED] ?? 0),
            'rejected' => (int) ($counts[EmployeeEndorsement::STATUS_REJECTED] ?? 0),
        ];
    }

    /**
     * An exact, case-insensitive name match, or null.
     *
     * Deliberately exact rather than fuzzy. A near match between "Driver" and
     * "Driver (Heavy Vehicle)" is two different jobs on two different salary
     * bands, and the cost of guessing wrong is paid quietly for as long as
     * nobody notices. `nameMatches()` earns its Levenshtein tolerance on
     * *people*, where the same person really is spelled several ways; master
     * data is chosen from a list and has one spelling.
     *
     * Only active rows are considered. A deactivated position or client is one
     * this system has stopped offering for new filings — it is kept so history
     * keeps what it was filed under, not so a new hire can be filed against it.
     */
    private function matchByName($query, string $column, ?string $name): ?int
    {
        if (blank($name)) {
            return null;
        }

        $needle = Str::lower(trim($name));

        return $query->where('is_active', true)
            ->get(['id', $column])
            ->first(fn ($row) => Str::lower(trim($row->{$column})) === $needle)
            ?->id;
    }
}
