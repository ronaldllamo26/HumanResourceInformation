<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Answers one question: can this person be sent to a client tomorrow?
 *
 * It is the question the agency actually asks, and no single module can answer
 * it. Whether someone is deployable depends on their credentials (Module 1),
 * the completeness of their 201 file (Module 1), and their employment standing
 * — and until now HR had to open four screens and hold the answer in their
 * head. People forget; a screen does not.
 *
 * **It re-uses the two scanners rather than re-deriving them.** What counts as
 * a lapsed credential belongs to `CredentialExpiryScanner`, and what counts as
 * an incomplete file belongs to `OnboardingChecker`. If this class made its own
 * judgement about either, the three would drift, and the readiness screen would
 * eventually disagree with the credentials screen about the same driver.
 *
 * The blocking distinction is not cosmetic here, unlike everywhere else in the
 * system. A driver whose licence has lapsed **may not lawfully drive**, and
 * dispatching them is the company's liability, not a matter of tidiness.
 */
class DeploymentReadinessChecker
{
    public const STATUS_READY = 'ready';

    public const STATUS_WARNING = 'warning';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly CredentialExpiryScanner $credentials,
        private readonly OnboardingChecker $onboarding,
        private readonly LicenseVerifier $licences,
    ) {}

    /**
     * Readiness for every employee the caller may see.
     *
     * Both scanners are run once over the whole set and then indexed by
     * employee, rather than called per person: a per-employee loop would be
     * two queries each, and this screen exists to be looked at for the whole
     * bench at once.
     *
     * @param  Builder<Employee>  $scoped  already narrowed by role
     * @return Collection<int, array<string, mixed>> blocked first, then warned
     */
    public function scan(Builder $scoped): Collection
    {
        $employees = (clone $scoped)
            ->with(['position:id,title', 'department:id,name', 'client:id,name'])
            ->get();

        $findings = $this->credentials
            ->scan(EmployeeDocument::whereIn('employee_id', $employees->pluck('id')))
            ->groupBy('employee_id');

        $gaps = $this->onboarding
            ->scan((clone $scoped))
            ->keyBy('employee_id');

        return $employees
            ->map(fn (Employee $employee) => $this->assess(
                $employee,
                $findings->get($employee->id, collect()),
                $gaps->get($employee->id),
            ))
            ->sortBy([
                // Blocked first — those are the ones that cost something today.
                fn (array $row) => match ($row['status']) {
                    self::STATUS_BLOCKED => 0,
                    self::STATUS_WARNING => 1,
                    default => 2,
                },
                fn (array $row) => $row['employee_name'],
            ])
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $credentials  this employee's expiring documents
     * @param  array<string, mixed>|null  $gaps  their 201-file gaps, if any
     * @return array<string, mixed>
     */
    private function assess(Employee $employee, Collection $credentials, ?array $gaps): array
    {
        $reasons = [];

        /*
         * Separated or inactive is the hardest stop there is, and it is checked
         * first because the rest of the questions stop mattering: an employee
         * who has left cannot be deployed however complete their file is.
         */
        if ($employee->status !== 'active') {
            $reasons[] = [
                'blocking' => true,
                'detail' => 'Employee record is '.str_replace('_', ' ', $employee->status).'.',
            ];
        }

        foreach ($credentials as $finding) {
            $lapsed = $finding['status'] === 'expired';

            // A lapsed *blocking* credential is the licence case: unlawful to
            // dispatch, not merely untidy. A lapsed certificate, or anything
            // still inside its warning window, is worth saying and no more.
            $reasons[] = [
                'blocking' => $lapsed && $finding['blocking'],
                'detail' => $finding['detail'],
            ];
        }

        /*
         * What the licence itself restricts.
         *
         * A driver limited to daylight cannot lawfully take a night run, and a
         * driver restricted to a customized vehicle cannot be given whatever
         * is free on the yard. Neither shows up as a missing document or a
         * lapsed one, so without this the screen would call them ready and the
         * dispatcher would find out at the depot.
         *
         * A warning, not a block: they *can* be deployed, on the right run.
         * Blocking would keep somebody off the roster entirely over a
         * condition that only rules out some of it.
         */
        foreach ($this->licences->operationalConditions($employee) as $condition) {
            $reasons[] = [
                'blocking' => false,
                'detail' => 'Licence condition: '.lcfirst($condition).'.',
            ];
        }

        foreach ($gaps['missing'] ?? [] as $missing) {
            $reasons[] = [
                'blocking' => (bool) $missing['blocking'],
                'detail' => 'Missing '.strtolower($missing['label']).'.',
            ];
        }

        $blocking = collect($reasons)->where('blocking', true);

        return [
            'employee_id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'employee_name' => $employee->full_name,
            'position' => $employee->position?->title,
            'department' => $employee->department?->name,
            'employment_category' => $employee->employment_category,
            // Where they are now, so redeploying somebody is a visible move
            // rather than a silent reassignment.
            'client' => $employee->client?->name,
            'client_id' => $employee->client_id,
            'status' => match (true) {
                $blocking->isNotEmpty() => self::STATUS_BLOCKED,
                $reasons !== [] => self::STATUS_WARNING,
                default => self::STATUS_READY,
            },
            'blocking_count' => $blocking->count(),
            'reasons' => array_values($reasons),
        ];
    }
}
