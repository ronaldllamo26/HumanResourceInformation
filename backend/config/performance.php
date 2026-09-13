<?php

/*
|--------------------------------------------------------------------------
| Performance management
|--------------------------------------------------------------------------
| The rating scale and the weighting of each reviewer type are company policy,
| not code. HR changes them here; PerformanceService reads everything from this
| file and the unit tests assert against these values.
*/

return [

    /*
    | The scale every KPI is rated on. `min` and `max` bound validation; the
    | descriptors are what appears beside each option on the evaluation form.
    */
    'rating_scale' => [
        'min' => 1,
        'max' => 5,
        'step' => 0.5,

        'descriptors' => [
            5 => ['label' => 'Outstanding', 'description' => 'Consistently exceeds every expectation.'],
            4 => ['label' => 'Exceeds Expectations', 'description' => 'Frequently goes beyond the standard.'],
            3 => ['label' => 'Meets Expectations', 'description' => 'Reliably delivers what the role requires.'],
            2 => ['label' => 'Needs Improvement', 'description' => 'Falls short of the standard in some areas.'],
            1 => ['label' => 'Unsatisfactory', 'description' => 'Consistently below the required standard.'],
        ],
    ],

    /*
    | How much each reviewer type counts toward the employee's final score for a
    | cycle. A 360 review blends all four; when a type is missing, the remaining
    | weights are re-normalised so the score is still out of the same scale.
    */
    'reviewer_weights' => [
        'supervisor' => 0.60,
        'self' => 0.10,
        'peer' => 0.20,
        'subordinate' => 0.10,
    ],

    /*
    | Bands the final score is reported against, highest first. Used for the
    | badge on performance history.
    */
    /*
    | The variants are stops on the shared good -> bad ramp (`--grade-1`
    | through `--grade-6` in app.css), so the badges read as one scale. They
    | used to be success / primary / default / warning / destructive — green,
    | *blue*, *grey*, orange, red — which is five separate meanings, not a
    | descent, and left the middle of the scale looking like an absence of
    | information rather than a middling result.
    |
    | Five bands over a six-stop ramp: grade-4 (yellow) is skipped so each
    | adjacent pair stays clearly apart. Adding a sixth band is an HR policy
    | decision, not a colour one — the rating scale above is 1–5.
    */
    'performance_bands' => [
        ['floor' => 4.50, 'label' => 'Outstanding', 'variant' => 'grade-1'],
        ['floor' => 3.50, 'label' => 'Exceeds Expectations', 'variant' => 'grade-2'],
        ['floor' => 2.50, 'label' => 'Meets Expectations', 'variant' => 'grade-3'],
        ['floor' => 1.50, 'label' => 'Needs Improvement', 'variant' => 'grade-5'],
        ['floor' => 0.00, 'label' => 'Unsatisfactory', 'variant' => 'grade-6'],
    ],

    /*
    | KPI weights on a single employee's scorecard must add up to this. The
    | evaluation form warns HR when a scorecard does not balance.
    */
    'required_weight_total' => 100,
];
