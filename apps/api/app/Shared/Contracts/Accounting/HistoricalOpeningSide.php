<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Accounting;

/**
 * Which side of the ledger a historical opening balance sits on (C-0a0 / F-2).
 *
 * `ArApOpeningService::postBatch()` mints BOTH an AR (customer, receivable) and
 * an AP (supplier, payable) opening as `DocumentType::Invoice` — same type, same
 * `is_historical` flag, same batch reference prefix. Nothing on the `documents`
 * row distinguishes them, which is why a payment path that keys on type alone
 * books Dr bank / Cr 411 for money the company OWES.
 *
 * This is the missing discriminator, expressed as a value rather than as a
 * string: `AccountsReceivable` is collected on the AR path; `AccountsPayable` is
 * settled Dr 401 / Cr bank through the supplier flow and must never reach the AR
 * path. A `null` from the reader means the evidence is genuinely absent — the
 * caller must fail closed, not guess.
 */
enum HistoricalOpeningSide: string
{
    case AccountsReceivable = 'ar';
    case AccountsPayable = 'ap';
}
