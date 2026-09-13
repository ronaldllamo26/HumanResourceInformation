<?php

/*
|--------------------------------------------------------------------------
| Credential expiry monitoring
|--------------------------------------------------------------------------
| How much warning each kind of 201-file document needs before it lapses.
| CredentialExpiryScanner reads everything here, so giving HR more lead time
| on a document is a config edit, not a code change.
*/

return [

    /*
    | Lead time for anything not named below.
    */
    'default_warning_days' => 30,

    /*
    | Renewal is not instant, and it is not equally slow for everything. An
    | LTO licence renewal wants weeks of notice; a training certificate can be
    | re-issued quickly. The window is per document type so the warning lands
    | while there is still time to act on it.
    */
    'warning_days' => [
        'drivers_license' => 60,
        'medical' => 45,
        'clearance' => 45,
        'certificate' => 30,
        'government_id' => 30,
        'contract' => 30,
    ],

    /*
    | Documents whose expiry legally stops the employee from doing the job —
    | a driver with a lapsed licence may not drive, and the liability is the
    | company's. These are flagged harder than an expired certificate.
    */
    'blocking_types' => [
        'drivers_license',
        'medical',
    ],

    /*
    |--------------------------------------------------------------------------
    | Which types normally carry an expiry date
    |--------------------------------------------------------------------------
    | Drives the upload form: the Expiry Date field is shown for these and
    | tucked behind a toggle for the rest, so a résumé is not asked when it
    | expires.
    |
    | "Normally", not "always", and the difference matters. A PhilSys ID never
    | expires but a passport does, and both are filed as `government_id`; a
    | regular employment contract has no end date but a project-based one does.
    | So this decides what the form *offers first*, never what it will accept —
    | the field is always reachable, and `expires_at` stays nullable for every
    | type. Hiding it outright would leave HR unable to record a real date.
    */
    'expiring_types' => [
        'drivers_license',
        'medical',
        'clearance',
        'certificate',
    ],
];
