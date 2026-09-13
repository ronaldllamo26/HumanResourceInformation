<?php

/*
|--------------------------------------------------------------------------
| Philippine statutory payroll rates
|--------------------------------------------------------------------------
| These change by law, so they live in config rather than in code. When a
| circular is issued, edit the values here — StatutoryContributions reads
| everything from this file and the unit tests assert against these brackets.
|
| Sources: RA 11199 (SSS), RA 11223 / PhilHealth Circular 2019-0009 as amended,
| HDMF Circular 460 (Pag-IBIG), and the TRAIN law table effective 2023 onward.
*/

return [

    /*
    | Standard working days per year, used to derive the daily rate from a
    | monthly salary: daily = monthly * 12 / factor. 261 is the usual figure
    | for a Monday-to-Friday operation.
    */
    'working_days_per_year' => 261,

    'hours_per_day' => 8,

    /*
    |----------------------------------------------------------------------
    | Regional daily minimum wage
    |----------------------------------------------------------------------
    | There is no national minimum wage in the Philippines. Each region's
    | Regional Tripartite Wages and Productivity Board issues its own wage
    | order, so a driver deployed in Calabarzon and one deployed in Metro
    | Manila are measured against different floors — which is what the
    | agency's clients mean by a "provincial rate".
    |
    | Used to *flag*, never to enforce. PayrollCalculator pays the rate on
    | the employee's record; this is the floor that rate is checked against,
    | the same way a position's salary band flags an out-of-band rate without
    | refusing it. A wage order is also not the whole story — an employee may
    | sit above the floor for a dozen legitimate reasons, and one below it is
    | a conversation for HR, not a blocked payroll.
    |
    | Figures below are non-agriculture daily rates and go stale every time a
    | board issues an order. Treat them as this system's assumption, not as
    | the law: check the current wage order before trusting a flag.
    */
    'default_wage_region' => env('PAYROLL_DEFAULT_REGION', 'NCR'),

    'wage_regions' => [
        'NCR' => ['label' => 'National Capital Region', 'daily_minimum' => 645.00],
        'CAR' => ['label' => 'Cordillera Administrative Region', 'daily_minimum' => 450.00],
        'R3' => ['label' => 'Region III — Central Luzon', 'daily_minimum' => 500.00],
        'R4A' => ['label' => 'Region IV-A — Calabarzon', 'daily_minimum' => 520.00],
        'R6' => ['label' => 'Region VI — Western Visayas', 'daily_minimum' => 480.00],
        'R7' => ['label' => 'Region VII — Central Visayas', 'daily_minimum' => 501.00],
        'R11' => ['label' => 'Region XI — Davao', 'daily_minimum' => 481.00],
    ],

    /*
    | Premium multipliers under the Labor Code. Overtime on an ordinary day is
    | +25%; night differential is +10% of the hourly rate for hours worked
    | between 22:00 and 06:00.
    */
    'premiums' => [
        'overtime' => 1.25,
        'rest_day_overtime' => 1.69,
        'night_differential' => 0.10,
        'regular_holiday' => 2.00,
        'special_holiday' => 1.30,
    ],

    /*
    | SSS — 15% of the Monthly Salary Credit, split 5% employee / 10% employer.
    | The MSC is the compensation rounded down to a 500-peso bracket, floored
    | at 5,000 and capped at 35,000.
    */
    'sss' => [
        'employee_rate' => 0.05,
        'employer_rate' => 0.10,
        'msc_floor' => 5000,
        'msc_ceiling' => 35000,
        'msc_step' => 500,
    ],

    /*
    | PhilHealth — 5% premium split evenly between employee and employer, with
    | an income floor of 10,000 and a ceiling of 100,000.
    */
    'philhealth' => [
        'premium_rate' => 0.05,
        'employee_share' => 0.50,
        'income_floor' => 10000,
        'income_ceiling' => 100000,
    ],

    /*
    | Pag-IBIG — the Monthly Fund Salary is capped at 10,000, so the employee
    | share tops out at 200 pesos. Employees earning 1,500 or less pay 1%.
    */
    'pagibig' => [
        'fund_salary_cap' => 10000,
        'lower_bracket_ceiling' => 1500,
        'employee_rate_lower' => 0.01,
        'employee_rate_upper' => 0.02,
        'employer_rate' => 0.02,
    ],

    /*
    | Withholding tax on compensation (TRAIN, 2023 onward). Each bracket is
    | [floor, base tax, rate on the excess over the floor]. Taxable income is
    | gross pay less the employee's statutory contributions.
    */
    'withholding_tax' => [
        'monthly' => [
            [0, 0, 0.00],
            [20833, 0, 0.15],
            [33333, 1875.00, 0.20],
            [66667, 8541.80, 0.25],
            [166667, 33541.80, 0.30],
            [666667, 183541.80, 0.35],
        ],

        'semi_monthly' => [
            [0, 0, 0.00],
            [10417, 0, 0.15],
            [16667, 937.50, 0.20],
            [33333, 4270.70, 0.25],
            [83333, 16770.70, 0.30],
            [333333, 91770.70, 0.35],
        ],

        'weekly' => [
            [0, 0, 0.00],
            [4808, 0, 0.15],
            [7692, 432.60, 0.20],
            [15385, 1971.20, 0.25],
            [38462, 7740.45, 0.30],
            [153846, 42355.65, 0.35],
        ],

        'daily' => [
            [0, 0, 0.00],
            [685, 0, 0.15],
            [1096, 61.65, 0.20],
            [2192, 280.85, 0.25],
            [5479, 1102.60, 0.30],
            [21918, 6034.30, 0.35],
        ],
    ],

    /*
    | Why a salary changed. Config-driven like the rest: a company that tracks
    | a reason this list does not carry adds it here, not in a migration.
    | 'correction' is deliberately its own reason — it means the previous
    | figure was wrong, not that the employee earned more.
    */
    'salary_adjustment_reasons' => [
        'hiring' => 'Hiring rate',
        'regularization' => 'Regularization',
        'merit' => 'Merit increase',
        'promotion' => 'Promotion',
        'market' => 'Market adjustment',
        'correction' => 'Correction',
    ],
];
