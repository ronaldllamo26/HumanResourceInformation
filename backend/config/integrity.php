<?php

/*
|--------------------------------------------------------------------------
| Record integrity checks
|--------------------------------------------------------------------------
| What "these records disagree with each other" means, in config rather than
| in code, the same shape as credentials.php and onboarding.php.
|
| Everything here is regex and string comparison. There is no model in this
| file and there is deliberately none behind it: a TIN with eleven digits is
| wrong for a reason that can be written down, and a rule that can be written
| down should not be inferred. See App\Services\RecordIntegrityChecker.
*/

return [

    /*
    | What each government number looks like once punctuation is stripped.
    |
    | These are the agencies' own published lengths:
    |
    | - **SSS** — 10 digits, printed XX-XXXXXXX-X.
    | - **PhilHealth (PIN)** — 12 digits, printed XX-XXXXXXXXX-X.
    | - **Pag-IBIG (MID)** — 12 digits, printed XXXX-XXXX-XXXX.
    | - **TIN** — 9 digits, or 12 with the three-digit branch code.
    |
    | Checked on length and digits only. A checksum would be tighter and none
    | of these four publishes one, so inventing a check digit rule would refuse
    | real numbers to look clever.
    */
    'number_formats' => [
        'sss_number' => [
            'label' => 'SSS',
            'patterns' => ['/^\d{10}$/'],
            'shape' => '10 digits — 34-1234567-8',
        ],
        'philhealth_number' => [
            'label' => 'PhilHealth',
            'patterns' => ['/^\d{12}$/'],
            'shape' => '12 digits — 12-345678901-2',
        ],
        'pagibig_number' => [
            'label' => 'Pag-IBIG',
            'patterns' => ['/^\d{12}$/'],
            'shape' => '12 digits — 1234-5678-9012',
        ],
        'tin' => [
            'label' => 'TIN',
            // Nine on its own, or twelve with the branch code.
            'patterns' => ['/^\d{9}$/', '/^\d{12}$/'],
            'shape' => '9 digits, or 12 with the branch code',
        ],
        'drivers_license_number' => [
            'label' => "Driver's licence",
            'patterns' => ['/^[a-z]\d{10}$/'],
            'shape' => 'a letter and ten digits — N01-23-456789',
        ],
    ],

    /*
    | Two employees cannot hold the same government number.
    |
    | This is the check worth having most, and the one a spreadsheet import
    | makes likely: a copied row, or a number keyed against the wrong person.
    | It is also the only finding here that is about *two* records rather than
    | one, which is why it is reported against both.
    |
    | `drivers_license_number` is included; a shared licence number is either a
    | keying error or something worse.
    */
    'unique_numbers' => [
        'sss_number',
        'philhealth_number',
        'pagibig_number',
        'tin',
        'drivers_license_number',
    ],

    /*
    | Document types where holding two current copies at once is a filing
    | error rather than a history.
    |
    | Deliberately not every type. An employee accumulates certificates and
    | clearances legitimately — a new NBI clearance every year, several TESDA
    | certificates — and flagging those would bury the real finding. These are
    | the ones where a person holds exactly one at a time.
    */
    'single_copy_types' => [
        'drivers_license',
        'contract',
        'resume',
    ],

    /*
    | How far a name may drift before it is worth reporting.
    |
    | The comparison itself is DocumentScanner::nameMatches(), reused rather
    | than re-derived — the married-name and truncated-card rules live there
    | and a second implementation would eventually disagree with the upload
    | form about the same person.
    */
    'report_name_mismatch' => true,
];
