<?php

/*
|--------------------------------------------------------------------------
| Separation & clearance
|--------------------------------------------------------------------------
| What has to be settled before a final pay is released. Config-driven, so a
| new company asset to account for is a config edit.
*/

return [

    /*
    | DOLE Labor Advisory 06-20 puts final pay at 30 days from separation
    | unless a more favourable company policy or agreement applies. The screen
    | counts down against this rather than leaving it as a date to remember.
    */
    'release_within_days' => 30,

    /*
    | The clearance checklist. `blocking` items must be signed off before the
    | settlement can be released — company property and accountabilities. The
    | rest are recorded but do not hold up the payment: withholding someone's
    | final pay over an unreturned lanyard is not a defensible reason to miss
    | a statutory deadline.
    */
    'checklist' => [
        'company_id' => ['label' => 'Company ID returned', 'blocking' => true],
        'assets' => ['label' => 'Issued equipment returned', 'blocking' => true],
        'cash_advances' => ['label' => 'Cash advances settled', 'blocking' => true],
        'turnover' => ['label' => 'Work turned over', 'blocking' => true],
        'uniform' => ['label' => 'Uniform returned', 'blocking' => false],
        'exit_interview' => ['label' => 'Exit interview completed', 'blocking' => false],
    ],
];
