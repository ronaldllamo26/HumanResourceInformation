<?php

return [

    /*
    |--------------------------------------------------------------------------
    | What an LTO driver's licence actually says
    |--------------------------------------------------------------------------
    |
    | Taken from the printed card rather than from memory — the code list and
    | the conditions below are the ones on the back of a current licence, in
    | the agency's own wording.
    |
    | This matters for a fleet operator more than it looks. A driver's DL code
    | is not a formality: it is the legal ceiling on what they may be put
    | behind the wheel of, and dispatching somebody with code A on a truck is
    | the same class of problem as dispatching somebody whose licence has
    | lapsed. The system used to hold this as one free-text
    | "restriction codes" box, which is both the *old* scheme's name and
    | unusable for deciding anything.
    |
    */

    /*
    | The vehicle classes a licence may carry, from the card's own "I. DL CODES"
    | panel. The old numeric restriction codes (1–8) these replaced are not
    | listed: a licence issued today does not carry them, and keeping both
    | would invite filing a new card under the retired scheme.
    */
    'dl_codes' => [
        'A' => 'Motorcycle',
        'A1' => 'Tricycle',
        'B' => 'Up to 5000 kgs GVW / 8 seats',
        'B1' => 'Up to 5000 kgs GVW / 9 or more seats',
        'B2' => 'Goods, 3500 kgs GVW',
        'C' => 'Goods over 3500 kgs GVW',
        'D' => 'Bus, over 5000 kgs GVW / 9 or more seats',
        'BE' => 'Trailers, 3500 kgs',
        'CE' => 'Articulated, C over 3500 kgs combined GVW',
    ],

    /*
    | The "II. CONDITIONS" panel — what the holder must do, or may not do,
    | while driving.
    |
    | Condition 4 is the one with teeth for a fleet: a driver restricted to
    | daylight cannot lawfully take a night run, which is a scheduling fact
    | rather than a paperwork one. It is surfaced on Deployment Readiness for
    | that reason.
    */
    'conditions' => [
        '1' => 'Wear corrective lenses',
        '2' => 'Drive only with special equipment for upper/lower limbs',
        '3' => 'Drive customized motor vehicle only',
        '4' => 'Daylight driving only',
        '5' => 'Hearing aid required',
    ],

    /*
    | Conditions that restrict *when or what* somebody may drive, rather than
    | describing equipment they wear. Reported on Deployment Readiness so the
    | person assigning a run sees it before the run is assigned.
    */
    'operational_conditions' => ['3', '4'],

    /*
    | The licence number, as printed: an agency code, the two-digit year, and a
    | six-digit serial — "N02-24-001292". Stored without the dashes, which is
    | why the integrity check reads a letter and ten digits.
    |
    | The first three characters are also printed separately as the Agency
    | Code, so the two can be checked against each other. That is the only
    | self-check the card offers: none of the numbers on it carries a
    | checksum, and inventing one would refuse real licences to look clever.
    */
    'number_pattern' => '/^[A-Z]\d{2}-?\d{2}-?\d{6}$/i',

    /*
    | A licence expires on the holder's birthday.
    |
    | Confirmed on the card this was modelled from: born 29 November, expires
    | 29 November. It is therefore checkable against the employee's own
    | `birth_date` — and a mismatch means one of the two was keyed wrong,
    | which is worth saying.
    |
    | Reported, never enforced. Renewals around a birthday, and the occasional
    | extension the agency grants by memorandum, are real enough that refusing
    | the entry would reject correct records to catch wrong ones — the same
    | line the salary bands and the wage regions draw.
    */
    'expires_on_birthday' => true,

    /*
    |--------------------------------------------------------------------------
    | Verifying a licence against LTO
    |--------------------------------------------------------------------------
    |
    | **There is no official LTO API, and this system does not pretend there
    | is one.** LTMS is a citizen-facing portal: a holder signs in to manage
    | their own licence. The agency publishes no endpoint an employer can call
    | to ask whether somebody else's licence is genuine, and the commercial
    | "LTO verification APIs" that advertise one are private wrappers whose
    | data source is not stated by LTO.
    |
    | So verification here is what it honestly can be: the structure is
    | checked automatically, and the *authenticity* is checked by a person on
    | the portal, whose answer is recorded with their name and the date. That
    | is a weaker claim than an API call and a far stronger one than a green
    | tick nobody can account for.
    */
    'ltms_url' => env('LTMS_PORTAL_URL', 'https://portal.lto.gov.ph/'),

    /*
    | How long a recorded verification stands before it is worth doing again.
    | A licence can be suspended the day after it was checked, so this is a
    | staleness marker rather than an expiry — it says "nobody has looked at
    | this in a year", not "this licence is invalid".
    */
    'verification_valid_days' => (int) env('LICENSE_VERIFICATION_DAYS', 365),
];
