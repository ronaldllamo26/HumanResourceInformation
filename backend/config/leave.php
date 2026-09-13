<?php

/*
|--------------------------------------------------------------------------
| Leave credit accrual
|--------------------------------------------------------------------------
| Credits are *earned* with service, not handed out whole on 1 January. The
| numbers here are the company's policy, so tightening or loosening it is a
| config edit rather than a code change.
*/

return [

    /*
    | Months a full year's entitlement is spread across. Twelve is the usual
    | figure: a leave type worth 15 days a year accrues 1.25 days a month.
    */
    'accrual_months' => 12,

    /*
    | Months of service before any credit is earned. Many companies grant
    | leave only on regularisation; the Labor Code's own service incentive
    | leave vests after one year. Zero accrues from the hire date.
    |
    | The probation is a *waiting period*, not a forfeit — once it passes,
    | the months served during it are credited too. Anything else would mean
    | an employee regularised in July starts the year with nothing.
    */
    'months_before_accrual' => 0,

    /*
    | Accrued credits are rounded to this many decimals. Two keeps 1.25 exact;
    | rounding to whole days would quietly lose a quarter-day every month.
    */
    'decimals' => 2,
];
