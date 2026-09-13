<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DisciplinaryAction;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Disciplinary actions from **Core 4 (Governance, Safety & Admin)**.
 *
 * A safety violation, a written warning, a suspension with dates. Core 4 runs
 * the investigation and signs it off; this system holds the employment record
 * the outcome attaches to.
 *
 * **What this endpoint refuses to do is the design.** A suspension arriving
 * here does *not* mark days absent and does *not* dock pay. It is stored, and
 * `PayrollReadinessChecker` raises an unpaid one as a **warning** before the
 * money is computed — "this person is suspended over four days of this cutoff
 * and their DTR shows nothing of the sort" — leaving HR to key it or decide
 * not to.
 *
 * That is deliberate and it costs something. **A DTR another system can write
 * is not a record of anything**: the same argument keeps employees out of
 * `attendance_logs`, where they file a correction and somebody decides, because
 * a time record somebody can rewrite proves nothing at cut-off. Core 4 is
 * another system and is no more entitled to it. The gap that leaves — an
 * unpaid suspension nobody acts on is paid — is the accepted price of not
 * letting one system move money in another.
 */
class DisciplinaryActionController extends Controller
{
    /**
     * POST /api/v1/disciplinary-actions
     *
     * Idempotent on `(source, reference)`, the same contract
     * `/endorsements`, `/loans` and `/payroll/adjustments` offer: a timeout on
     * Core 4's side is indistinguishable from a failure, so they resend, and
     * two rows for one suspension is two warnings on the payroll screen for
     * one event — which is how a screen stops being read.
     */
    public function store(Request $request): JsonResponse
    {
        /*
         * Gated on the employee record rather than on a new ability. A
         * disciplinary action is a fact about somebody's employment, and who
         * may write to an employment record is a question this system already
         * answers.
         */
        Gate::authorize('create', DisciplinaryAction::class);

        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:64'],
            'source' => ['sometimes', Rule::in(DisciplinaryAction::SOURCES)],

            'employee_id' => ['required', 'exists:employees,id'],
            'type' => ['required', Rule::in(DisciplinaryAction::TYPES)],

            /*
             * Required, and not merely for the record. Core 4 reads the
             * outcome back, and an action with no stated reason is one nobody
             * can answer for later — the same rule `/endorsements` applies to
             * a rejection.
             */
            'reason' => ['required', 'string', 'max:1000'],

            'effective_from' => ['required', 'date'],

            /*
             * Nullable, because a suspension of unknown length is a real state
             * — pending investigation — and defaulting it to the start date
             * would be inventing the outcome. Never before the start.
             */
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],

            /*
             * Only a suspension without pay reaches payroll at all. One *with*
             * pay changes nothing about a payslip, and raising it on the
             * readiness panel would be noise on a screen whose whole value is
             * that every line needs acting on.
             */
            'is_unpaid' => ['sometimes', 'boolean'],

            'issued_by' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $source = $validated['source'] ?? 'core4';

        $existing = DisciplinaryAction::where('source', $source)
            ->where('reference', $validated['reference'])
            ->first();

        if ($existing !== null) {
            // Returned unchanged rather than updated to match the resend: the
            // reference names an action HR may already have acted on, and
            // rewriting the dates behind it would move which days are unpaid.
            return response()->json(['data' => $this->present($existing)], 200);
        }

        $action = DisciplinaryAction::create([
            'employee_id' => $validated['employee_id'],
            'source' => $source,
            'reference' => $validated['reference'],
            'type' => $validated['type'],
            'reason' => $validated['reason'],
            'effective_from' => $validated['effective_from'],
            'effective_to' => $validated['effective_to'] ?? null,
            'is_unpaid' => $validated['is_unpaid'] ?? true,
            'issued_by' => $validated['issued_by'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json(['data' => $this->present($action)], 201);
    }

    /**
     * GET /api/v1/employees/{employee}/disciplinary-actions
     *
     * What is on record for one person. Core 4 needs this to avoid issuing a
     * second first-warning, and Performance Management needs it to read a
     * rating in context.
     */
    public function index(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('view', $employee);

        $actions = $employee->disciplinaryActions()
            ->orderByDesc('effective_from')
            ->get()
            ->map(fn (DisciplinaryAction $action) => $this->present($action));

        return response()->json(['data' => $actions]);
    }

    /** GET /api/v1/disciplinary-actions/{source}/{reference} */
    public function show(Request $request, string $source, string $reference): JsonResponse
    {
        $action = DisciplinaryAction::where('source', $source)
            ->where('reference', $reference)
            ->firstOrFail();

        Gate::authorize('view', $action->employee);

        return response()->json(['data' => $this->present($action)]);
    }

    /** @return array<string, mixed> */
    private function present(DisciplinaryAction $action): array
    {
        return [
            'source' => $action->source,
            'reference' => $action->reference,
            'employee_id' => $action->employee_id,
            'type' => $action->type,
            'reason' => $action->reason,
            'effective_from' => $action->effective_from?->toDateString(),
            'effective_to' => $action->effective_to?->toDateString(),
            'is_unpaid' => (bool) $action->is_unpaid,
            'issued_by' => $action->issued_by,

            /*
             * Said back explicitly, because it is the thing most likely to be
             * assumed wrong by whoever is integrating. A suspension posted
             * here has *not* docked anybody's pay.
             */
            'payroll_effect' => $action->isSuspension() && $action->is_unpaid
                ? 'flagged_for_hr'
                : 'none',

            'created_at' => $action->created_at?->toIso8601String(),
        ];
    }
}
