<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Count-correction GL posting — SYSTEM default (lane P-1)
    |--------------------------------------------------------------------------
    |
    | This key is no longer THE gate. It is the last link of a three-step chain
    | owned by CountCorrectionGlPostingResolver:
    |
    |   companies.count_correction_gl_posting_enabled          (tenant override)
    |     -> country_inventory_settings.count_correction_gl_posting_enabled
    |       -> this value                                      (system default)
    |
    | It ships TRUE. The owner RULED on 2026-08-25 that count-correction posting
    | is seeded ON — perpetual inventory means a stock-take difference must reach
    | the ledger — with the expert-comptable reviewing the Option A account
    | choice (6586 shortage / 7586 overage) later at onboarding. That supersedes
    | the OQ-12/H-5 deploy-time blocker of 2026-08-19, which had this key
    | defaulted FALSE pending ratification; the supersession is appended to
    | docs/handoff/reviews/wave3-3c-3d/ORCHESTRATOR-RULING-2026-08-19-m5-oq12-gate.md.
    |
    | The env var survives as a deployment-wide kill switch: setting it false
    | turns posting off for every company that has not set its OWN override,
    | without editing tenant data. A single tenant is turned off through the
    | settings surface instead, which is what the company column is for.
    |
    */

    'count_correction_gl_posting_enabled' => (bool) env('INVENTORY_COUNT_CORRECTION_GL_POSTING_ENABLED', true),

];
