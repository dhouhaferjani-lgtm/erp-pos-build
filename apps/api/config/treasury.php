<?php

declare(strict_types=1);

return [
    // Phase ⑤b launches acquirer fees as VAT-exempt. A non-zero rate fails
    // closed in AcquirerFeeService until the Phase ④ VAT split is explicitly
    // wired, so an environment change cannot silently misstate recoverable VAT.
    'acquirer_fee_vat_rate' => env('TREASURY_ACQUIRER_FEE_VAT_RATE', '0.000'),
];
