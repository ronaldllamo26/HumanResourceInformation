<?php

/*
|--------------------------------------------------------------------------
| Educational & qualification records
|--------------------------------------------------------------------------
| What the 201 file may say about somebody's schooling, training, and skills.
| Held here rather than in code for the same reason config/onboarding.php is:
| a client audit asking for a new level or grade is a config edit.
*/

return [

    /*
    | Education levels, **in order**. The order is load-bearing: highest
    | attainment is derived from it rather than stored on the row, so adding a
    | level means placing it correctly, and no employee record has to be
    | re-flagged when one is added.
    |
    | The Philippine ladder, as the DepEd K-12 reform left it: senior high is
    | its own two years and is not the same claim as a high-school diploma
    | from before 2016.
    */
    'education_levels' => [
        'elementary' => 'Elementary',
        'high_school' => 'High School (Junior)',
        'senior_high' => 'Senior High School',
        'vocational' => 'Vocational / Technical',
        'college' => 'College / Bachelor',
        'post_graduate' => 'Post-Graduate',
    ],

    /*
    | Levels where a course or strand is expected. Below senior high there is
    | nothing to name, so the field is hidden rather than left blank and
    | looking unfilled.
    */
    'levels_with_course' => [
        'senior_high',
        'vocational',
        'college',
        'post_graduate',
    ],

    /*
    | Optional, and deliberately coarse. Three grades somebody can apply
    | consistently beat five they cannot — a scale nobody agrees on records
    | the grader rather than the skill.
    */
    'proficiency_levels' => [
        'basic' => 'Basic',
        'intermediate' => 'Intermediate',
        'advanced' => 'Advanced',
    ],

    /*
    | The earliest year a graduation may be keyed against. Not a real rule so
    | much as a typo catcher: "1019" and "2109" are both easy to type and both
    | survive every other check.
    */
    'earliest_graduation_year' => 1940,
];
