<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DisciplinaryActionController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EndorsementController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\PayrollAdjustmentController;
use App\Http\Controllers\Api\TimekeepingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PrimePower HRIS — REST API (v1)
|--------------------------------------------------------------------------
| Token-based (Sanctum). Obtain a token from POST /api/v1/login, then send
| it as `Authorization: Bearer <token>`.
*/

Route::middleware('auth:sanctum')->get('/user', function (\Illuminate\Http\Request $request) {
    return response()->json([
        'data' => $request->user()->only(['id', 'name', 'email', 'role', 'is_active']),
    ]);
});

Route::middleware('auth:sanctum')->get('/dashboard', \App\Http\Controllers\DashboardController::class);

Route::prefix('v1')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1');

    /*
     * `throttle:api` is not on by default in Laravel 11 — the limiter has to
     * be both defined and applied, and without this line every authenticated
     * endpoint below would answer as fast as the server can, which is a scrape
     * of the whole directory. Defined in AppServiceProvider::defineApiRateLimit.
     */
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);

        // --- Module 1: Employee Information Management ---
        Route::get('employees/statistics', [EmployeeController::class, 'statistics']);
        Route::post('employees/{employeeId}/restore', [EmployeeController::class, 'restore'])
            ->whereNumber('employeeId');

        Route::apiResource('employees', EmployeeController::class);

        /*
         * --- The Core 1 handover ---
         *
         * Recruitment pushes a hire; HR decides on it in this system; Core 1
         * reads the outcome back. Above the employee routes because it is the
         * step before one exists.
         *
         * The lookup is by Core 1's own reference rather than by our id: their
         * reference is the only identifier they hold if our response to the
         * submission never arrived, which is precisely when they need to ask.
         */
        Route::post('endorsements', [EndorsementController::class, 'store']);
        Route::get('endorsements/{reference}', [EndorsementController::class, 'show']);

        Route::get('employees/{employee}/documents', [EmployeeController::class, 'documents']);
        Route::post('employees/{employee}/documents', [EmployeeController::class, 'storeDocument']);
        Route::delete('employees/{employee}/documents/{document}', [EmployeeController::class, 'destroyDocument']);

        /*
        |------------------------------------------------------------------
        | What Core 2 publishes to the rest of ISMERS
        |------------------------------------------------------------------
        | Grouped by who consumes each one, because that is the question a
        | second team actually has. Every route is read-only and every figure
        | comes from the service that already computes it for our own screens —
        | if a consumer and one of our screens disagreed about the same driver,
        | one of them would be running its own copy of the rules.
        |
        | The two inbound doors are `POST /endorsements` (Core 1 proposes a
        | hire) and `POST /loans` (Core 3 posts something for payroll to
        | deduct). Nothing else may write into this system over the wire.
        */

        // Fleet & Transportation — who may lawfully drive what, and when.
        Route::get('drivers', [IntegrationController::class, 'drivers']);

        // Core 1 (Deployment & Job Order) and Fleet — who can be sent out.
        Route::get('deployment-readiness', [IntegrationController::class, 'deploymentReadiness']);

        // Financial Management — the disbursement register.
        Route::get('payroll/runs', [IntegrationController::class, 'payrollRuns']);
        Route::get('payroll/runs/{run}/register', [IntegrationController::class, 'payrollRegister']);

        /*
         * Financial Management (General Ledger, AP, Tax) — payroll as a
         * journal entry.
         *
         * Keyed by **period** and not by run, unlike the two above: a ledger
         * is posted per accounting period, and there can be more than one run
         * in one. Asking Finance to add the runs up themselves would be asking
         * them to re-derive a total this system already holds.
         */
        Route::get('payroll/journal-summary/{period}', [IntegrationController::class, 'journalSummary']);

        /*
         * Financial Management (AP) — the other end of `/register`.
         *
         * Closes a loop that was open: the register handed Finance a list and
         * nothing came back, so a run sat at `approved` until somebody in HR
         * remembered to tick it — and *approved* and *the money arrived* are
         * different facts the system was reporting as one. The amount is
         * checked against the run's own total rather than trusted.
         */
        Route::post('payroll/runs/{run}/disbursement', [IntegrationController::class, 'confirmDisbursement']);

        /*
         * Core 4 (Governance, Safety & Admin) — warnings and suspensions.
         *
         * A suspension posted here does **not** mark days absent and does not
         * dock pay. It is stored, and `PayrollReadinessChecker` raises an
         * unpaid one as a warning before the money is computed, leaving HR to
         * key the days or decide not to. A DTR another system can write is not
         * a record of anything — the same argument that keeps employees out of
         * `attendance_logs`.
         */
        Route::post('disciplinary-actions', [DisciplinaryActionController::class, 'store']);
        Route::get('disciplinary-actions/{source}/{reference}', [DisciplinaryActionController::class, 'show']);
        Route::get('employees/{employee}/disciplinary-actions', [DisciplinaryActionController::class, 'index']);

        /*
         * Fleet (trip allowances, per diems) and Supply Chain (accountability
         * for a damaged or lost item) — one-off amounts on a payslip.
         *
         * **A write door, and only the third in the whole API.** It stores a
         * row that payroll reads at compute time; it never touches a payslip,
         * because a draft run can be recomputed and an endpoint that applied
         * an amount when called would apply it twice. Idempotent on
         * `(source, reference)`, the same contract `/endorsements` and `/loans`
         * offer.
         */
        Route::post('payroll/adjustments', [PayrollAdjustmentController::class, 'store']);
        Route::get('payroll/adjustments/{source}/{reference}', [PayrollAdjustmentController::class, 'show']);
        Route::delete('payroll/adjustments/{source}/{reference}', [PayrollAdjustmentController::class, 'destroy']);

        // Core 3 (Government Contribution & Compliance) — what to remit.
        Route::get('payroll/runs/{run}/contributions', [IntegrationController::class, 'contributions']);

        // Core 4 (Reports & Dashboards) and BI — aggregates, never people.
        Route::get('analytics/workforce', [IntegrationController::class, 'workforce']);

        /*
         * Core 3 (Benefits and Loans) — the second inbound door. They approve
         * the loan; only this system can take it off a payslip.
         */
        Route::post('loans', [LoanController::class, 'store']);
        Route::get('loans/{reference}', [LoanController::class, 'show']);
        Route::get('employees/{employee}/loans', [LoanController::class, 'forEmployee']);

        // --- Module 2: Timekeeping & Attendance ---
        Route::get('attendance/summary', [TimekeepingController::class, 'summary']);
        Route::get('attendance', [TimekeepingController::class, 'index']);
        Route::post('attendance', [TimekeepingController::class, 'store']);
        Route::get('attendance/{attendanceLog}', [TimekeepingController::class, 'show']);
        Route::delete('attendance/{attendanceLog}', [TimekeepingController::class, 'destroy']);
    });
});
