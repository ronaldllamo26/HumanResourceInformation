<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEndorsementRequest;
use App\Http\Resources\EmployeeEndorsementResource;
use App\Models\EmployeeEndorsement;
use App\Services\EndorsementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Core 1's end of the handover.
 *
 * Two endpoints and nothing else: submit a hire, and ask what happened to one.
 * Recruitment pushes, HR decides here, recruitment reads the outcome back —
 * which is a deliberately dull integration, and the dullness is the feature.
 * Core 1 cannot create an employee, cannot set a salary, and cannot see one
 * once created beyond the number assigned to the person it sent.
 */
class EndorsementController extends Controller
{
    public function __construct(private readonly EndorsementService $endorsements) {}

    /**
     * POST /api/v1/endorsements
     *
     * Idempotent on `reference`. A resend of something already filed returns
     * the existing row with **200** rather than 201, so Core 1 can tell a
     * fresh submission from a duplicate without either being an error — a
     * retry after a timeout is correct behaviour on their side, not a fault
     * to report.
     */
    public function store(StoreEndorsementRequest $request): JsonResponse
    {
        $existing = EmployeeEndorsement::where('reference', $request->input('reference'))->first();

        $endorsement = $this->endorsements->receive($request->validated());

        return (new EmployeeEndorsementResource($endorsement->load('employee')))
            ->response()
            ->setStatusCode($existing ? 200 : 201);
    }

    /**
     * GET /api/v1/endorsements/{reference}
     *
     * Looked up by Core 1's own reference rather than by this system's id:
     * their reference is the only identifier they held when they sent it, and
     * making them store ours to ask after their own record would mean the
     * lookup fails exactly when it is most needed — after a timeout where they
     * never received a response to store.
     */
    public function show(string $reference): EmployeeEndorsementResource
    {
        Gate::authorize('create', EmployeeEndorsement::class);

        $endorsement = EmployeeEndorsement::where('reference', $reference)
            ->with('employee')
            ->firstOrFail();

        return new EmployeeEndorsementResource($endorsement);
    }
}
