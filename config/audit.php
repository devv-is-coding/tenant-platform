<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Audit Retention
    |--------------------------------------------------------------------------
    |
    | How long audit events are kept, applied to the platform log and to every
    | agency's own log by `audit:prune`. An event older than the first period
    | is soft-deleted: hidden from the logs but still recoverable. One older
    | than the second is deleted for good.
    |
    */

    'soft_delete_after_years' => (int) env('AUDIT_SOFT_DELETE_AFTER_YEARS', 3),

    'hard_delete_after_years' => (int) env('AUDIT_HARD_DELETE_AFTER_YEARS', 5),

];
