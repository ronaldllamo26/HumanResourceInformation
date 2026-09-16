<?php

/*
|--------------------------------------------------------------------------
| Privacy notice (RA 10173, the Data Privacy Act of 2012)
|--------------------------------------------------------------------------
|
| Every signed-in person reads the notice once before using the system, and
| again whenever `notice_version` changes. Change the version whenever the
| wording changes in substance — what is collected, why, who sees it, or where
| it goes — so nobody is held to a notice they never read.
|
*/

return [

    'notice_version' => '2026-09-15',

    // Who to contact about personal data. Falls back to the company email set
    // on Settings > General when blank.
    'dpo_name' => env('PRIVACY_DPO_NAME', 'Data Protection Officer'),
    'dpo_email' => env('PRIVACY_DPO_EMAIL'),

    /*
     * How long records are kept, as stated to the people they describe. These
     * mirror the retention the rest of the system already assumes: employment
     * records under the Labor Code's implementing rules, payroll under the
     * NIRC. Nothing is deleted on a timer — see config/archive.php.
     */
    'retention' => [
        'employment_years' => 3,
        'payroll_years' => 10,
    ],

];
