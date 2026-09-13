<?php

/*
|--------------------------------------------------------------------------
| 201-file requirements
|--------------------------------------------------------------------------
| What a complete 201 file holds. OnboardingChecker reads this, so changing
| what the company requires is a config edit — and requirements genuinely do
| change, usually because a client audit asks for something new.
*/

return [

    /*
    | Document types every employee must have on file, whatever the role.
    | `blocking` marks the ones that stop the employee being deployed at all,
    | the same distinction the credential screen draws: a missing contract is
    | a legal exposure, a missing résumé is untidy.
    */
    'documents' => [
        'contract' => ['label' => 'Employment contract', 'blocking' => true],
        'government_id' => ['label' => 'Government ID', 'blocking' => true],
        'clearance' => ['label' => 'NBI / police clearance', 'blocking' => true],
        'medical' => ['label' => 'Medical certificate', 'blocking' => true],
        'resume' => ['label' => 'Résumé', 'blocking' => false],
    ],

    /*
    | Extra documents required only of employees whose position title matches
    | one of these fragments. A dispatcher does not need a driver's licence;
    | a driver may not work without one.
    */
    'by_position' => [
        'driver' => [
            'drivers_license' => ['label' => "Driver's licence", 'blocking' => true],
        ],
    ],

    /*
    | Government numbers that must be recorded before the employee can be
    | included in a statutory filing. The Compliance screen already flags a
    | missing number at remittance time — this catches it at onboarding,
    | which is when it is still cheap to fix.
    */
    'government_numbers' => [
        'sss_number' => 'SSS number',
        'philhealth_number' => 'PhilHealth number',
        'pagibig_number' => 'Pag-IBIG number',
        'tin' => 'TIN',
    ],
];
