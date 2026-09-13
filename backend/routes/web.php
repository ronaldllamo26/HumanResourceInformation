<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\AttendanceAdjustmentController;
use App\Http\Controllers\AttendanceExceptionController;
use App\Http\Controllers\AttendanceHistoryController;
use App\Http\Controllers\AttendanceReportController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CompensationController;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\DirectoryController;
use App\Http\Controllers\DocumentBatchController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeImportController;
use App\Http\Controllers\EmployeeQualificationController;
use App\Http\Controllers\EndorsementController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\KpiController;
use App\Http\Controllers\LeaveBalanceController;
use App\Http\Controllers\LeaveCalendarController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OvertimeController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PayslipController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\PeriodAttendanceController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\RecordIntegrityController;
use App\Http\Controllers\ReviewCycleController;
use App\Http\Controllers\SalaryController;
use App\Http\Controllers\ScanAccuracyController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\SeparationController;
use App\Http\Controllers\Settings\DataExportController;
use App\Http\Controllers\Settings\IntegrationController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SettingsController;
use App\Http\Controllers\Settings\UserAccessController;
use App\Http\Controllers\ThirteenthMonthController;
use App\Http\Controllers\TimekeepingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    /*
    |----------------------------------------------------------------------
    | Core Transaction 2 — Human Resource Information & Operations
    |----------------------------------------------------------------------
    */
    Route::prefix('hr')->name('hr.')->group(function () {
        // Module 1 — Employee Information Management
        /*
         * Above the resource route, so /hr/employees/import is not swallowed
         * by the {employee} wildcard — the same reason the compliance export
         * sits above its own index.
         */
        /*
         * Reads a paper 201 form and proposes the record it describes. Same
         * gate as creating one by hand, and it writes nothing — it fills a
         * form somebody then checks and saves.
         */
        Route::post('employees/scan-form', [EmployeeController::class, 'scanEmployeeForm'])
            ->name('employees.scanForm');

        /*
         * A stack of scans off the glass, filed in one pass. Above the
         * resource route with the others, or {employee} swallows it.
         */
        Route::get('employees/documents/batch', [DocumentBatchController::class, 'create'])
            ->name('employees.documents.batch');
        Route::post('employees/documents/batch/examine', [DocumentBatchController::class, 'examine'])
            ->name('employees.documents.batch.examine');
        Route::post('employees/documents/batch', [DocumentBatchController::class, 'store'])
            ->name('employees.documents.batch.store');

        Route::get('employees/import', [EmployeeImportController::class, 'create'])
            ->name('employees.import');
        Route::post('employees/import', [EmployeeImportController::class, 'store'])
            ->name('employees.import.store');

        /*
         * Records that a person checked this licence on the LTMS portal.
         *
         * LTO publishes no API to call, so this stores a human's answer with
         * their name and the date rather than pretending to ask the agency.
         * Above the resource route with the others, or {employee} swallows it.
         */
        Route::post('employees/{employee}/verify-license', [EmployeeController::class, 'verifyLicense'])
            ->name('employees.verifyLicense');

        /*
         * Moving somebody between job titles, reached from the Positions
         * screen. Addressed as the *employee* rather than as the position,
         * because that is whose record changes — and it is gated on `update`
         * for that employee, not on `manageOrganization`.
         */
        Route::patch('employees/{employee}/position', [EmployeeController::class, 'updatePosition'])
            ->name('employees.position');

        /*
         * Sending somebody to a client, or bringing them back in-house.
         * Reached from the Clients screen and addressed as the *employee* for
         * the same reason the position move is: that is whose record changes,
         * so it is gated on `update` for them rather than on
         * `manageOrganization`.
         */
        Route::patch('employees/{employee}/deployment', [EmployeeController::class, 'updateDeployment'])
            ->name('employees.deployment');

        /*
         * Educational & qualification records — schooling, completed
         * trainings, and skills. Named `education`/`training`/`skill` so the
         * form request can tell which of the three it is validating from the
         * route name alone.
         */
        Route::post('employees/{employee}/education', [EmployeeQualificationController::class, 'storeEducation'])
            ->name('employees.education.store');
        Route::put('employees/{employee}/education/{education}', [EmployeeQualificationController::class, 'updateEducation'])
            ->name('employees.education.update');
        Route::delete('employees/{employee}/education/{education}', [EmployeeQualificationController::class, 'destroyEducation'])
            ->name('employees.education.destroy');

        Route::post('employees/{employee}/training', [EmployeeQualificationController::class, 'storeTraining'])
            ->name('employees.training.store');
        Route::put('employees/{employee}/training/{training}', [EmployeeQualificationController::class, 'updateTraining'])
            ->name('employees.training.update');
        Route::delete('employees/{employee}/training/{training}', [EmployeeQualificationController::class, 'destroyTraining'])
            ->name('employees.training.destroy');

        Route::post('employees/{employee}/skill', [EmployeeQualificationController::class, 'storeSkill'])
            ->name('employees.skill.store');
        Route::put('employees/{employee}/skill/{skill}', [EmployeeQualificationController::class, 'updateSkill'])
            ->name('employees.skill.update');
        Route::delete('employees/{employee}/skill/{skill}', [EmployeeQualificationController::class, 'destroySkill'])
            ->name('employees.skill.destroy');

        Route::get('my-profile', [EmployeeController::class, 'myProfile'])->name('my-profile');

        Route::resource('employees', EmployeeController::class);

        /*
         * The org directory — who works here, arranged the way the company is.
         *
         * Open to every signed-in user, unlike the HR directory beside it,
         * because the fields narrow to match: a name, a job, a posting, and a
         * work contact. See EmployeePolicy::viewDirectory — the widening and
         * the narrowing are one decision.
         */
        Route::get('directory', [DirectoryController::class, 'index'])->name('directory');

        /*
         * The Core 1 inbox — proposed hires waiting on a decision here.
         *
         * There is no `create` and no `store`: an endorsement is written by
         * Core 1 over the API and never by hand on this side, which is what
         * makes the queue a record of what recruitment actually sent rather
         * than of what somebody here typed. Approving is not a route either —
         * it opens the employee form at /hr/employees/create?endorsement=…,
         * because accepting somebody *is* creating them and that has always
         * gone through one place.
         */
        Route::get('endorsements', [EndorsementController::class, 'index'])
            ->name('endorsements.index');
        Route::get('endorsements/{endorsement}', [EndorsementController::class, 'show'])
            ->name('endorsements.show');
        Route::post('endorsements/{endorsement}/reject', [EndorsementController::class, 'reject'])
            ->name('endorsements.reject');

        // 201-file documents inside their renewal window, or already lapsed.
        Route::get('credentials', [CredentialController::class, 'index'])->name('credentials');

        /*
         * AI & Analytics — Workforce demographics, timekeeping insights,
         * multi-file AI scanner, and OCR performance benchmarking.
         */
        Route::get('analytics/workforce', [AnalyticsController::class, 'workforce'])
            ->name('analytics.workforce');
        Route::get('analytics/attendance', [AnalyticsController::class, 'attendance'])
            ->name('analytics.attendance');

        // Centralized AI Batch Document Scanner
        Route::get('employees/documents/batch', [DocumentBatchController::class, 'create'])
            ->name('employees.documents.batch');
        Route::post('employees/documents/batch/examine', [DocumentBatchController::class, 'examine'])
            ->name('employees.documents.batch.examine');
        Route::post('employees/documents/batch', [DocumentBatchController::class, 'store'])
            ->name('employees.documents.batch.store');

        Route::get('scan-accuracy', [ScanAccuracyController::class, 'index'])
            ->name('scanAccuracy');

        /*
         * Where the records disagree with each other — a number on two
         * people, a document naming somebody else, dates that cannot both
         * be true. Regex and string comparison; no model behind it.
         */
        Route::get('record-checks', [RecordIntegrityController::class, 'index'])
            ->name('recordChecks');

        // Which 201 files are still missing a requirement.
        Route::get('onboarding', [OnboardingController::class, 'index'])->name('onboarding');

        // Who can be sent to a client today. Reads credentials, 201-file
        // completeness, and employment standing together — no single one of
        // them answers it.
        Route::get('deployment', [DeploymentController::class, 'index'])->name('deployment');

        // The master list of everything deleted, and the way back. Sits with
        // Employee Information because that is where most of it is deleted from.
        Route::get('archive', [ArchiveController::class, 'index'])->name('archive');
        Route::post('archive/employees/{employee}/restore', [ArchiveController::class, 'restoreEmployee'])
            ->name('archive.employees.restore');
        Route::post('archive/clients/{client}/restore', [ArchiveController::class, 'restoreClient'])
            ->name('archive.clients.restore');

        // Master data — the org structure employee records are filed against.
        // In a manpower agency that includes the clients staff are deployed to,
        // which is why this sits beside departments rather than under Payroll.
        Route::get('clients', [ClientController::class, 'index'])->name('clients');
        Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
        Route::put('clients/{client}', [ClientController::class, 'update'])
            ->name('clients.update');
        Route::delete('clients/{client}', [ClientController::class, 'destroy'])
            ->name('clients.destroy');

        Route::get('departments', [DepartmentController::class, 'index'])->name('departments');
        Route::post('departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::put('departments/{department}', [DepartmentController::class, 'update'])
            ->name('departments.update');
        Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])
            ->name('departments.destroy');

        Route::get('positions', [PositionController::class, 'index'])->name('positions');
        Route::post('positions', [PositionController::class, 'store'])->name('positions.store');
        Route::put('positions/{position}', [PositionController::class, 'update'])
            ->name('positions.update');
        Route::delete('positions/{position}', [PositionController::class, 'destroy'])
            ->name('positions.destroy');

        Route::post('employees/{employee}/documents', [EmployeeController::class, 'storeDocument'])
            ->name('employees.documents.store');
        // Reads a scan and proposes the fields. Saves nothing — the upload
        // above is still what commits, after the human has checked them.
        Route::post('employees/{employee}/documents/scan', [EmployeeController::class, 'scanDocument'])
            ->name('employees.documents.scan');
        Route::get('employees/{employee}/documents/{document}/download', [EmployeeController::class, 'downloadDocument'])
            ->name('employees.documents.download');
        // Same file, served inline so the browser renders it in the preview.
        Route::get('employees/{employee}/documents/{document}/preview', [EmployeeController::class, 'previewDocument'])
            ->name('employees.documents.preview');
        Route::delete('employees/{employee}/documents/{document}', [EmployeeController::class, 'destroyDocument'])
            ->name('employees.documents.destroy');

        // Module 2 — Timekeeping & Attendance
        Route::get('timekeeping', [TimekeepingController::class, 'index'])->name('timekeeping');
        Route::post('timekeeping', [TimekeepingController::class, 'store'])->name('timekeeping.store');
        Route::post('timekeeping/import', [TimekeepingController::class, 'import'])
            ->name('timekeeping.import');
        // The cutoff sheet — a fortnight of attendance for a whole department
        // or client at once. Declared before the {attendanceLog} wildcard so
        // "period" is never read as a record id.
        Route::get('timekeeping/period', [PeriodAttendanceController::class, 'index'])
            ->name('timekeeping.period');
        Route::post('timekeeping/period', [PeriodAttendanceController::class, 'store'])
            ->name('timekeeping.period.store');

        // One person's cutoff, as a calendar. Reached by clicking their row on
        // Records; gated on EmployeePolicy::view rather than on the log.
        Route::get('timekeeping/employee/{employee}', [TimekeepingController::class, 'show'])
            ->name('timekeeping.employee');

        Route::delete('timekeeping/{attendanceLog}', [TimekeepingController::class, 'destroy'])
            ->name('timekeeping.destroy');

        /*
         * The exception-handling step: an employee cannot edit a time record
         * and never should be able to, so a discrepancy on their DTR becomes a
         * request a supervisor or HR decides on before it reaches the log.
         */
        Route::get('timekeeping/adjustments', [AttendanceAdjustmentController::class, 'index'])
            ->name('adjustments');
        Route::post('timekeeping/adjustments', [AttendanceAdjustmentController::class, 'store'])
            ->name('adjustments.store');
        Route::post('timekeeping/adjustments/{adjustment}/decide', [AttendanceAdjustmentController::class, 'decide'])
            ->name('adjustments.decide');
        Route::post('timekeeping/adjustments/{adjustment}/cancel', [AttendanceAdjustmentController::class, 'cancel'])
            ->name('adjustments.cancel');

        // Overtime filing and approval
        Route::get('timekeeping/overtime', [OvertimeController::class, 'index'])->name('overtime');
        Route::post('timekeeping/overtime', [OvertimeController::class, 'store'])->name('overtime.store');
        Route::put('timekeeping/overtime/{overtimeRequest}', [OvertimeController::class, 'update'])
            ->name('overtime.update');
        Route::post('timekeeping/overtime/{overtimeRequest}/decide', [OvertimeController::class, 'decide'])
            ->name('overtime.decide');
        Route::post('timekeeping/overtime/{overtimeRequest}/cancel', [OvertimeController::class, 'cancel'])
            ->name('overtime.cancel');

        // Shifts and employee schedules
        Route::get('timekeeping/schedules', [ScheduleController::class, 'index'])->name('schedules');
        Route::post('timekeeping/shifts', [ScheduleController::class, 'storeShift'])->name('shifts.store');
        Route::put('timekeeping/shifts/{shift}', [ScheduleController::class, 'updateShift'])->name('shifts.update');
        Route::delete('timekeeping/shifts/{shift}', [ScheduleController::class, 'destroyShift'])->name('shifts.destroy');
        // The work calendar's holiday list — read by leave costing, attendance
        // status, and holiday pay, so it is not merely a reference table.
        Route::get('timekeeping/holidays', [HolidayController::class, 'index'])->name('holidays');
        Route::post('timekeeping/holidays', [HolidayController::class, 'store'])->name('holidays.store');
        Route::put('timekeeping/holidays/{holiday}', [HolidayController::class, 'update'])->name('holidays.update');
        Route::delete('timekeeping/holidays/{holiday}', [HolidayController::class, 'destroy'])->name('holidays.destroy');

        Route::post('timekeeping/schedules', [ScheduleController::class, 'storeSchedule'])->name('schedules.store');
        Route::delete('timekeeping/schedules/{schedule}', [ScheduleController::class, 'destroySchedule'])
            ->name('schedules.destroy');

        /*
         * The per-employee attendance summary is the Records screen itself
         * now, so Reports has nowhere left to be: it showed the same rows for
         * the same range with no way to reach the days behind them. The route
         * redirects rather than 404s, carrying the range across, the same way
         * /settings/organization still lands somewhere useful.
         */
        Route::get('timekeeping/reports', fn (Request $request) => redirect()->route(
            'hr.timekeeping',
            $request->only('from', 'to'),
        ))->name('reports');

        Route::get('timekeeping/export', [AttendanceReportController::class, 'export'])
            ->name('timekeeping.export');

        // Automated exception checker — flags DTR records worth a second look.
        Route::get('timekeeping/exceptions', [AttendanceExceptionController::class, 'index'])->name('exceptions');

        // Who edited a DTR record, when, and what changed.
        Route::get('timekeeping/history', [AttendanceHistoryController::class, 'index'])->name('timekeeping.history');

        // Module 3 — Leave & Absence
        Route::get('leave', [LeaveController::class, 'index'])->name('leave');
        Route::post('leave', [LeaveController::class, 'store'])->name('leave.store');
        Route::post('leave/{leaveRequest}/approve', [LeaveController::class, 'approve'])->name('leave.approve');
        Route::post('leave/{leaveRequest}/reject', [LeaveController::class, 'reject'])->name('leave.reject');
        Route::post('leave/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])->name('leave.cancel');
        Route::get('leave/{leaveRequest}/attachment', [LeaveController::class, 'attachment'])
            ->name('leave.attachment');

        Route::get('leave/balances', [LeaveBalanceController::class, 'index'])->name('leave.balances');
        Route::post('leave/balances', [LeaveBalanceController::class, 'update'])->name('leave.balances.update');
        // Credits are earned per month of service, not handed out whole on
        // 1 January. Safe to re-run — it recomputes rather than adding.
        Route::post('leave/balances/accrue', [LeaveBalanceController::class, 'accrue'])
            ->name('leave.balances.accrue');

        Route::get('leave/calendar', LeaveCalendarController::class)->name('leave.calendar');

        Route::get('leave/types', [LeaveTypeController::class, 'index'])->name('leave.types');
        Route::post('leave/types', [LeaveTypeController::class, 'store'])->name('leave.types.store');
        Route::put('leave/types/{leaveType}', [LeaveTypeController::class, 'update'])->name('leave.types.update');
        Route::delete('leave/types/{leaveType}', [LeaveTypeController::class, 'destroy'])->name('leave.types.destroy');

        // Module 4 — Payroll & Compensation
        Route::get('payroll', [PayrollController::class, 'index'])->name('payroll');
        Route::post('payroll/periods', [PayrollController::class, 'storePeriod'])->name('payroll.periods.store');
        Route::post('payroll/periods/{payrollPeriod}/generate', [PayrollController::class, 'generate'])
            ->name('payroll.generate');

        Route::get('payroll/runs/{payrollRun}', [PayrollController::class, 'show'])->name('payroll.run');
        Route::post('payroll/runs/{payrollRun}/submit', [PayrollController::class, 'submit'])->name('payroll.submit');
        Route::post('payroll/runs/{payrollRun}/approve', [PayrollController::class, 'approve'])->name('payroll.approve');
        Route::post('payroll/runs/{payrollRun}/paid', [PayrollController::class, 'markPaid'])->name('payroll.paid');
        Route::post('payroll/runs/{payrollRun}/cancel', [PayrollController::class, 'cancel'])->name('payroll.cancel');

        Route::get('payroll/payslips', [PayslipController::class, 'index'])->name('payroll.payslips');
        Route::get('payroll/payslips/{payslip}', [PayslipController::class, 'show'])->name('payroll.payslip');

        // Statutory remittance and BIR reporting, read back from issued payslips.
        // Export sits above the index so /compliance/export is not swallowed.
        Route::get('payroll/compliance/export', [ComplianceController::class, 'export'])
            ->name('payroll.compliance.export');
        Route::get('payroll/compliance', [ComplianceController::class, 'index'])
            ->name('payroll.compliance');

        // 13th-month pay under PD 851, read back from finalised payslips.
        Route::get('payroll/13th-month/export', [ThirteenthMonthController::class, 'export'])
            ->name('payroll.thirteenth.export');
        Route::get('payroll/13th-month', [ThirteenthMonthController::class, 'index'])
            ->name('payroll.thirteenth');

        // Where an employee's rate is set, and the history behind it.
        Route::get('payroll/salaries', [SalaryController::class, 'index'])
            ->name('payroll.salaries');
        Route::post('payroll/salaries', [SalaryController::class, 'store'])
            ->name('payroll.salaries.store');
        Route::delete('payroll/salaries/{salaryAdjustment}', [SalaryController::class, 'destroy'])
            ->name('payroll.salaries.destroy');

        // Separation and final pay — the exit half of the lifecycle.
        Route::get('payroll/separations', [SeparationController::class, 'index'])
            ->name('payroll.separations');
        Route::post('payroll/separations', [SeparationController::class, 'store'])
            ->name('payroll.separations.store');
        Route::get('payroll/separations/{separation}', [SeparationController::class, 'show'])
            ->name('payroll.separation');
        Route::post('payroll/separations/{separation}/recompute', [SeparationController::class, 'recompute'])
            ->name('payroll.separations.recompute');
        Route::post('payroll/separations/{separation}/clearance', [SeparationController::class, 'clearance'])
            ->name('payroll.separations.clearance');
        Route::post('payroll/separations/{separation}/release', [SeparationController::class, 'release'])
            ->name('payroll.separations.release');

        Route::get('payroll/compensation', [CompensationController::class, 'index'])->name('payroll.compensation');
        Route::post('payroll/allowances', [CompensationController::class, 'storeAllowance'])->name('payroll.allowances.store');
        Route::delete('payroll/allowances/{allowance}', [CompensationController::class, 'destroyAllowance'])
            ->name('payroll.allowances.destroy');
        Route::post('payroll/loans', [CompensationController::class, 'storeLoan'])->name('payroll.loans.store');
        Route::post('payroll/loans/{loan}/cancel', [CompensationController::class, 'cancelLoan'])
            ->name('payroll.loans.cancel');

        // Module 5 — Performance Management
        Route::get('performance', [PerformanceController::class, 'index'])->name('performance');
        Route::get('performance/reviews/{review}', [PerformanceController::class, 'show'])
            ->name('performance.review');
        Route::put('performance/reviews/{review}', [PerformanceController::class, 'update'])
            ->name('performance.review.update');
        Route::post('performance/reviews/{review}/submit', [PerformanceController::class, 'submit'])
            ->name('performance.review.submit');
        Route::post('performance/reviews/{review}/acknowledge', [PerformanceController::class, 'acknowledge'])
            ->name('performance.review.acknowledge');
        Route::get('performance/employees/{employee}/history', [PerformanceController::class, 'history'])
            ->name('performance.history');

        Route::get('performance/cycles', [ReviewCycleController::class, 'index'])->name('performance.cycles');
        Route::post('performance/cycles', [ReviewCycleController::class, 'store'])->name('performance.cycles.store');
        Route::put('performance/cycles/{cycle}', [ReviewCycleController::class, 'update'])
            ->name('performance.cycles.update');
        Route::post('performance/cycles/{cycle}/rollout', [ReviewCycleController::class, 'rollout'])
            ->name('performance.cycles.rollout');
        Route::post('performance/cycles/{cycle}/close', [ReviewCycleController::class, 'close'])
            ->name('performance.cycles.close');

        Route::get('performance/kpis', [KpiController::class, 'index'])->name('performance.kpis');
        Route::post('performance/kpis', [KpiController::class, 'store'])->name('performance.kpis.store');
        Route::put('performance/kpis/{kpi}', [KpiController::class, 'update'])->name('performance.kpis.update');
        Route::delete('performance/kpis/{kpi}', [KpiController::class, 'destroy'])->name('performance.kpis.destroy');
    });
});

/*
|--------------------------------------------------------------------------
| Settings
|--------------------------------------------------------------------------
| Replaces the starter kit's profile page. Company-wide sections are
| administrator-only; Appearance and Security belong to every signed-in user.
*/
Route::middleware(['auth', 'verified'])->prefix('settings')->name('settings.')->group(function () {
    /*
     * The menu, not a section.
     *
     * This redirected — to General for an admin and Appearance for everybody
     * else, the second half being a fix in its own right, since General is
     * admin-only and the old redirect sent every role into a 403. Both are
     * gone: `System Settings` is a sidebar entry now, and a nav entry that
     * silently lands somebody on the company's regional formats has answered a
     * question they did not ask. The seven sections are the subject, so the
     * list is what `/settings` renders and a section is the next click.
     *
     * No role check here. The page draws only what `visibleSections()` allows
     * and each section route keeps its own 403 — this decides what is offered,
     * never what is permitted.
     */
    Route::get('/', fn () => Inertia::render('Settings/Index'))->name('index');

    Route::get('general', [SettingsController::class, 'general'])->name('general');
    Route::put('general', [SettingsController::class, 'updateGeneral'])->name('general.update');

    Route::get('appearance', [SettingsController::class, 'appearance'])->name('appearance');

    // Departments and positions moved to Employee Information, where HR
    // maintains them while filing people rather than while configuring the
    // system. Kept as a redirect so old links and bookmarks still land.
    Route::get('organization', fn () => redirect()->route('hr.departments'))
        ->name('organization');

    Route::get('notifications', [SettingsController::class, 'notifications'])->name('notifications');
    Route::put('notifications', [SettingsController::class, 'updateNotifications'])->name('notifications.update');

    Route::get('users', [UserAccessController::class, 'index'])->name('users');
    Route::post('users', [UserAccessController::class, 'store'])->name('users.store');
    Route::put('users/{user}/role', [UserAccessController::class, 'updateRole'])->name('users.role');
    Route::post('users/{user}/toggle', [UserAccessController::class, 'toggleActive'])->name('users.toggle');
    Route::post('users/{user}/reset-password', [UserAccessController::class, 'resetPassword'])->name('users.reset');

    Route::get('security', [SecurityController::class, 'index'])->name('security');
    Route::put('security/profile', [SecurityController::class, 'updateProfile'])->name('security.profile');
    Route::put('security/password', [SecurityController::class, 'updatePassword'])->name('security.password');
    Route::post('security/tokens/revoke-all', [SecurityController::class, 'revokeTokens'])->name('security.tokens.revokeAll');
    Route::delete('security/tokens/{token}', [SecurityController::class, 'revokeToken'])->name('security.tokens.revoke');
    Route::delete('security/account', [SecurityController::class, 'destroyAccount'])->name('security.account');

    Route::get('data', [SettingsController::class, 'data'])->name('data');
    Route::put('data', [SettingsController::class, 'updateData'])->name('data.update');
    Route::get('data/export/employees', [DataExportController::class, 'employees'])->name('data.export.employees');

    Route::get('integrations', [IntegrationController::class, 'index'])->name('integrations');
    Route::post('integrations/tokens', [IntegrationController::class, 'storeToken'])->name('integrations.tokens.store');
    Route::delete('integrations/tokens/{token}', [IntegrationController::class, 'destroyToken'])->name('integrations.tokens.destroy');
});

// The starter kit's profile page now lives under Settings > Security.
Route::middleware('auth')->get('/profile', fn () => redirect()->route('settings.security'));

require __DIR__.'/auth.php';
