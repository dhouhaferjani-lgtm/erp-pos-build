<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Count-correction GL posting (DPA Wave 3D — T21)
    |--------------------------------------------------------------------------
    |
    | DEPLOY-TIME GATE, OQ-12/H-5. The T20 treasury ruling of record
    | (docs/handoff/reviews/wave3-3c-3d/TREASURY-RULING-2026-08-19-t20-option-a.md)
    | approved the Option A shrinkage/gain map (6586 / 7586) but queued
    | expert-comptable ratification of its liasse presentation BEFORE
    | count-correction posting goes live.
    |
    | "Goes live" is this flag. The T21 listener is fully built and tested; with
    | the flag FALSE it corrects stock and threads the row cost onto the count
    | movement, but enqueues no journal entry — so the ledger can be
    | reconstructed from the movement rows once the flag flips. Both
    | count-correction purposes stay dormant exactly as sub-wave 3C shipped
    | them.
    |
    | Do NOT enable this for any tenant before the ratification is recorded.
    |
    */

    'count_correction_gl_posting_enabled' => (bool) env('INVENTORY_COUNT_CORRECTION_GL_POSTING_ENABLED', false),

];
