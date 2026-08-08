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
    // The pre-enable gate is an OWNER RULING on POS count semantics: does a
    // cashier count the shift's TAKINGS or the WHOLE DRAWER? The variance the
    // listener books is the one the system already computes, and
    // `ReportGenerationService::buildExpectedPerMethod()` sums receipt payments
    // ONLY — no opening float, no deposits, no payouts. Under whole-drawer
    // semantics the float would therefore be booked to 658/758 on every single
    // close, permanently. Until that question is answered, this stays off; the
    // flag is also the kill switch if it ever needs stopping without a code
    // change. Everything else in the lane (document, GL entry, movement,
    // idempotency, audit trail) is in place behind it.
    'shift_variance_gl_enabled' => (bool) env('TREASURY_SHIFT_VARIANCE_GL_ENABLED', false),
];
