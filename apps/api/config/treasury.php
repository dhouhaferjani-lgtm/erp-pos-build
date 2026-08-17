<?php

declare(strict_types=1);

return [
    // Phase ⑤b launches acquirer fees as VAT-exempt. A non-zero rate fails
    // closed in AcquirerFeeService until the Phase ④ VAT split is explicitly
    // wired, so an environment change cannot silently misstate recoverable VAT.
    'acquirer_fee_vat_rate' => env('TREASURY_ACQUIRER_FEE_VAT_RATE', '0.000'),

    // Alert-only treasury:reconcile threshold. Statements still imported or
    // reconciling beyond this age are surfaced to operators but never freeze
    // their cash repository.
    'statement_stale_days' => (int) env('TREASURY_STATEMENT_STALE_DAYS', 30),

    // DPA lane G3 — the shift-close cash-variance GL leg
    // (PostShiftCashVarianceAdjustment). Defaults to FALSE and ships DISABLED.
    //
    // The count semantics are settled: cashiers count the WHOLE DRAWER. This
    // stays off because Treasury does not yet book the opening float or the
    // mid-shift drawer operations that form that expected balance (SV-3/SV-4).
    // Enabling the variance leg first would therefore create a cash/GL mismatch.
    // The flag remains the global kill switch if the lane ever needs stopping
    // without a code change. The remaining pre-enable gates are tracked in
    // docs/superpowers/tickets/2026-08-08-g3-shift-variance-gl-deploy-notes.md.
    // Everything else in the lane (document, GL entry, movement, idempotency,
    // audit trail) is in place behind it.
    'shift_variance_gl_enabled' => (bool) env('TREASURY_SHIFT_VARIANCE_GL_ENABLED', false),
];
