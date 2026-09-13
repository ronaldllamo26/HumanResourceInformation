<?php

namespace App\Http\Controllers;

use App\Http\Requests\RejectEndorsementRequest;
use App\Models\EmployeeEndorsement;
use App\Services\EndorsementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Core 1 inbox — hires proposed by recruitment, awaiting a decision here.
 *
 * Thin, like every controller in this system: authorize, delegate to
 * `EndorsementService`, respond. The one thing it does own is the shape of the
 * review screen, which is deliberately two lists rather than a form — what
 * Core 1 sent, and what this system still needs before the person can be paid.
 */
class EndorsementController extends Controller
{
    public function __construct(private readonly EndorsementService $endorsements) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', EmployeeEndorsement::class);

        /*
         * Pending by default, because the inbox is a work queue rather than an
         * archive: the question it answers on opening is "what is waiting on
         * me", and starting on "everything" buries that under every decision
         * already taken. `all` is an explicit choice in the filter.
         */
        $status = $request->input('status', EmployeeEndorsement::STATUS_PENDING);

        $endorsements = EmployeeEndorsement::query()
            ->with(['employee:id,employee_number', 'decidedBy:id,name'])
            ->search($request->input('search'))
            ->status($status === 'all' ? null : $status)
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('HR/Endorsements/Index', [
            'endorsements' => [
                'data' => $endorsements->map(fn (EmployeeEndorsement $row) => [
                    'id' => $row->id,
                    'reference' => $row->reference,
                    'source' => $row->source,
                    'full_name' => $row->fullName(),
                    'email' => $row->email,
                    'position_title' => $row->position_title,
                    'client_name' => $row->client_name,
                    'date_hired' => $row->date_hired?->toDateString(),
                    'status' => $row->status,
                    'submitted_at' => $row->created_at?->toDateString(),
                    'decided_at' => $row->decided_at?->toDateString(),
                    'decided_by' => $row->decidedBy?->name,
                    'employee_number' => $row->employee?->employee_number,
                    'employee_id' => $row->employee_id,

                    /*
                     * Asked per row rather than once for the screen, because
                     * `decide` is not only about the user — it also requires
                     * the endorsement to still be pending. A decided row is
                     * history, and drawing Approve on one would offer either a
                     * second employee from one endorsement or an overwrite of
                     * who was recorded as approving the first.
                     */
                    'can_decide' => Gate::allows('decide', $row),
                ]),
                'meta' => [
                    'from' => $endorsements->firstItem(),
                    'to' => $endorsements->lastItem(),
                    'total' => $endorsements->total(),
                    'links' => $endorsements->linkCollection()->toArray(),
                ],
            ],
            'filters' => [
                'search' => $request->input('search', ''),
                'status' => $status,
            ],
            // The whole table, not the filtered view — a summary that moves
            // while you type is not a summary.
            'statistics' => $this->endorsements->statistics(),
        ]);
    }

    public function show(EmployeeEndorsement $endorsement): Response
    {
        Gate::authorize('view', $endorsement);

        $endorsement->load(['employee:id,employee_number', 'decidedBy:id,name']);

        return Inertia::render('HR/Endorsements/Show', [
            /*
             * Shaped explicitly rather than handed over as the model.
             *
             * `decidedBy` serialises to the key `decided_by`, which is also the
             * name of the column holding that user's id — Eloquent merges
             * relations over attributes, so the model's own JSON silently
             * replaces the id with the object. It happens to give the page
             * what it wants here, which is worse than failing: the next person
             * to read `decided_by` server-side gets an integer and the same
             * name client-side gets an object.
             */
            'endorsement' => [
                'id' => $endorsement->id,
                'reference' => $endorsement->reference,
                'source' => $endorsement->source,
                'position_title' => $endorsement->position_title,
                'client_name' => $endorsement->client_name,
                'date_hired' => $endorsement->date_hired?->toDateString(),
                'status' => $endorsement->status,
                'payload' => $endorsement->payload,
                'decision_note' => $endorsement->decision_note,
                'decided_at' => $endorsement->decided_at?->toDateString(),
                'decided_by_name' => $endorsement->decidedBy?->name,
                'employee' => $endorsement->employee ? [
                    'id' => $endorsement->employee->id,
                    'employee_number' => $endorsement->employee->employee_number,
                ] : null,
            ],
            'fullName' => $endorsement->fullName(),

            /*
             * What this system still needs before the person can be paid.
             * Computed here rather than in the component so the screen and the
             * form request cannot drift about what "complete" means — these are
             * exactly the fields `StoreEmployeeRequest` marks required and that
             * Core 1 is not permitted to send.
             */
            'missing' => $this->missingForPayroll($endorsement),

            'can' => [
                'decide' => $endorsement->isPending()
                    && Gate::allows('decide', $endorsement),
            ],
        ]);
    }

    public function reject(RejectEndorsementRequest $request, EmployeeEndorsement $endorsement): RedirectResponse
    {
        $this->endorsements->reject(
            $endorsement,
            $request->user(),
            $request->validated()['decision_note'],
        );

        return redirect()
            ->route('hr.endorsements.index')
            ->with('success', "Endorsement {$endorsement->reference} declined. Core 1 can read the reason back.");
    }

    /**
     * The decisions this system has to make that Core 1 cannot make for it.
     *
     * Named on the review screen so the reviewer knows what the next step
     * costs before taking it, rather than meeting six required fields after
     * having already clicked Approve.
     */
    private function missingForPayroll(EmployeeEndorsement $endorsement): array
    {
        $suggestions = $this->endorsements->suggestions($endorsement);

        return array_values(array_filter([
            'Employment category (internal, or deployed to a client)',
            'Basic salary and pay frequency',
            $suggestions['position_id'] ? null : 'Position',
            'Department or client assignment',
            $endorsement->date_hired ? null : 'Hire date',
        ]));
    }
}
