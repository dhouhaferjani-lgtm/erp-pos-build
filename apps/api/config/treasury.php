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
];
