<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const EMPLOYMENT_STATUSES = [
        'regular',
        'probationary',
        'contractual',
        'project-based',
        'resigned',
        'terminated',
    ];

    public const STATUSES = ['active', 'inactive', 'on_leave'];

    /** Employment statuses that mean the person no longer works here. */
    public const SEPARATED_STATUSES = ['resigned', 'terminated'];

    /**
     * Numbers shown as their last four characters until somebody presses Show.
     *
     * The six encrypted columns: what a stolen screenshot, a shoulder-surfer or
     * a shared screen is worth taking. The last four are enough to tell two
     * records apart and to read one back over the phone.
     */
    public const MASKABLE = [
        'sss_number',
        'philhealth_number',
        'pagibig_number',
        'tin',
        'bank_account_number',
        'drivers_license_number',
    ];

    /**
     * The licence number is outside `viewSensitive` — supervisors dispatching a
     * driver already see it — so revealing it asks the same `view` the rest of
     * the record does. Every other maskable number needs `viewSensitive`.
     */
    public const MASKABLE_WITH_VIEW = ['drivers_license_number'];

    /**
     * PrimePower is a manpower agency, so an employee is one of two things.
     *
     * `internal` runs the agency itself and is filed against a department.
     * `external` is deployed to a client — still PrimePower's employee and on
     * PrimePower's payroll, but working at, and billed to, that client.
     */
    public const CATEGORY_INTERNAL = 'internal';

    public const CATEGORY_EXTERNAL = 'external';

    public const CATEGORIES = [self::CATEGORY_INTERNAL, self::CATEGORY_EXTERNAL];

    protected $guarded = ['id'];

    protected $appends = ['full_name'];

    /**
     * `34-1234567-8` becomes `••••••5678`. A fixed run of dots rather than one
     * per character, so the mask does not give away the number's length; a
     * very short value shows fewer than four so most of it stays hidden.
     */
    public static function mask(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $characters = preg_replace('/[^A-Za-z0-9]/', '', $value);

        return str_repeat('•', 6).substr($characters, -min(4, max(strlen($characters) - 2, 1)));
    }

    public function hasLeft(): bool
    {
        return $this->status === 'inactive'
            || in_array($this->employment_status, self::SEPARATED_STATUSES, true);
    }

    public function contractHasLapsed(): bool
    {
        return $this->contract_end !== null && $this->contract_end->isPast();
    }

    public function contractExpiringSoon(int $days = 30): bool
    {
        return $this->contract_end !== null
            && ! $this->contract_end->isPast()
            && $this->contract_end->lte(now()->addDays($days));
    }

    // --- Relationships -----------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Null for internal staff — they are deployed nowhere. */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    public function userWithTrashed(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** Who last recorded an LTMS check against this licence. */
    public function licenseVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'license_verified_by');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'supervisor_id');
    }

    /**
     * Money still coming off this person's pay.
     *
     * Approved by Core 3 (Benefits and Loans), amortised here — the two
     * systems own different halves of the same fact, and only this one can
     * take money off a payslip.
     */
    public function loans(): HasMany
    {
        return $this->hasMany(EmployeeLoan::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    // --- Educational & qualification records -----------------------------

    public function educations(): HasMany
    {
        return $this->hasMany(EmployeeEducation::class);
    }

    public function trainings(): HasMany
    {
        return $this->hasMany(EmployeeTraining::class);
    }

    public function skills(): HasMany
    {
        return $this->hasMany(EmployeeSkill::class);
    }

    /**
     * Warnings and suspensions on record, from Core 4 or from HR directly.
     *
     * Nothing here changes a payslip or a time record on its own —
     * `PayrollReadinessChecker` raises an unpaid suspension as a warning and
     * HR decides. See `DisciplinaryAction` for why.
     */
    public function disciplinaryActions(): HasMany
    {
        return $this->hasMany(DisciplinaryAction::class);
    }

    /**
     * The furthest this employee got, derived rather than stored.
     *
     * A stored "highest attainment" is a second answer to a question the rows
     * already answer, and it goes stale the first time somebody adds a degree
     * and forgets to move the flag. The ladder's order lives in
     * `config/qualifications.php`, so this reads it rather than hard-coding
     * one — and an unknown level ranks last, so a level dropped from config
     * cannot outrank a real one.
     */
    public function highestEducation(): ?EmployeeEducation
    {
        return $this->educations
            ->sortByDesc(fn (EmployeeEducation $education) => $education->rank())
            ->first();
    }

    public function separations(): HasMany
    {
        return $this->hasMany(Separation::class);
    }

    // --- Accessors ---------------------------------------------------------

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name ? mb_substr($this->middle_name, 0, 1).'.' : null,
            $this->last_name,
            $this->suffix,
        ])));
    }

    // --- Scopes ------------------------------------------------------------

    /** Matches name, employee number, or email. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace('%', '\%', $term).'%';

        // `ilike` is Postgres-only; sqlite's LIKE is already case-insensitive.
        $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return $query->where(function (Builder $inner) use ($like, $operator) {
            $inner->where('first_name', $operator, $like)
                ->orWhere('last_name', $operator, $like)
                ->orWhere('middle_name', $operator, $like)
                ->orWhere('employee_number', $operator, $like)
                ->orWhere('email', $operator, $like);
        });
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->search($filters['search'] ?? null)
            ->when(
                $filters['department_id'] ?? null,
                fn (Builder $q, $value) => $q->where('department_id', $value),
            )
            /*
             * A comma separates alternatives, because the dashboard donut
             * groups statuses the dropdown does not: "Contractual" is
             * contractual *and* project-based, "Separated" is resigned *and*
             * terminated. Without this the slice could not open the records it
             * counted, and a figure that cannot show its own rows is a figure
             * nobody can check. A single value still behaves exactly as it did.
             */
            ->when(
                $filters['employment_status'] ?? null,
                fn (Builder $q, $value) => $q->whereIn(
                    'employment_status',
                    array_filter(array_map('trim', explode(',', (string) $value))),
                ),
            )
            /*
             * Set by the dashboard's "New Hires" tile, which counts the last
             * 30 days. A date range would have done, but the tile is a rolling
             * window and pinning it to two dates in a URL would leave a stale
             * link that reads correctly and returns the wrong month.
             */
            ->when(
                $filters['hired_within'] ?? null,
                fn (Builder $q, $value) => $q->where(
                    'date_hired',
                    '>=',
                    now()->startOfDay()->subDays((int) $value),
                ),
            )
            // Set by "No Documents": an empty 201 file, not a specific gap in
            // one — that finer question is OnboardingChecker's.
            ->when(
                filter_var($filters['without_documents'] ?? null, FILTER_VALIDATE_BOOLEAN),
                fn (Builder $q) => $q->whereDoesntHave('documents'),
            )
            ->when(
                $filters['status'] ?? null,
                fn (Builder $q, $value) => $q->where('status', $value),
            )
            ->when(
                $filters['employment_category'] ?? null,
                fn (Builder $q, $value) => $q->where('employment_category', $value),
            )
            ->when(
                $filters['client_id'] ?? null,
                fn (Builder $q, $value) => $q->where('client_id', $value),
            );
    }

    /**
     * The wage region this employee is measured against.
     *
     * Their own posting wins where it is set; otherwise the client's site.
     * Internal staff fall back to the agency's own region in
     * `config('payroll.wage_regions.default')` — they are not deployed
     * anywhere, so there is no client to inherit from.
     */
    public function wageRegion(): string
    {
        return $this->wage_region
            ?: $this->client?->wage_region
            ?: config('payroll.default_wage_region', 'NCR');
    }

    public function isExternal(): bool
    {
        return $this->employment_category === self::CATEGORY_EXTERNAL;
    }

    /** Next sequential employee number, e.g. PPM-2026-0007. */
    public static function nextEmployeeNumber(): string
    {
        $year = now()->year;
        $prefix = "PPM-{$year}-";

        $latest = static::withTrashed()
            ->where('employee_number', 'like', $prefix.'%')
            ->orderByDesc('employee_number')
            ->value('employee_number');

        $sequence = $latest ? ((int) substr($latest, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    /*
     * Leaving takes the login with it, however the record got there.
     *
     * A person is marked gone in four places — the employee form, the API, a
     * released separation, and archiving — and only archiving used to switch
     * the account off. So a resigned driver kept signing in to the 201 files
     * and payslips of people still here. Hooked on the model so no path can
     * forget. Only ever switches off: turning a login back on is a decision
     * somebody makes (Users & Access, or restoring from the archive).
     */
    protected static function booted(): void
    {
        static::updated(function (Employee $employee) {
            if (! $employee->wasChanged(['status', 'employment_status']) || ! $employee->hasLeft()) {
                return;
            }

            $user = $employee->user;

            if ($user?->is_active) {
                $user->update(['is_active' => false]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'date_hired' => 'date',
            'date_regularized' => 'date',
            'date_separated' => 'date',
            'contract_start' => 'date',
            'contract_end' => 'date',
            'license_expiry' => 'date',
            // A timestamp, not a date: the recorded LTMS check is a moment
            // somebody acted, and the staleness window is counted from it.
            'license_verified_at' => 'datetime',
            'basic_salary' => 'decimal:2',

            /*
             * Encrypted at rest — the fields that make a stolen database dump
             * worth stealing. A name and a department are what a colleague
             * already knows; an SSS number, a TIN and a bank account are what
             * somebody opens a loan with, and under RA 10173 they are
             * sensitive personal information.
             *
             * `viewSensitive` already decides who may *see* them; this decides
             * what is readable in the file the database sits in, which is a
             * different question and the one an application gate cannot answer.
             *
             * The cost: an encrypted column cannot be searched or indexed.
             * Nothing here needs that — duplicate detection loads the rows and
             * compares in PHP (`RecordIntegrityChecker::sharedNumbers()`), and
             * `scopeSearch` never covered these. "Find the employee with this
             * TIN" would want a blind index rather than plaintext.
             */
            'sss_number' => 'encrypted',
            'philhealth_number' => 'encrypted',
            'pagibig_number' => 'encrypted',
            'tin' => 'encrypted',
            'bank_account_number' => 'encrypted',
            'drivers_license_number' => 'encrypted',
        ];
    }
}
