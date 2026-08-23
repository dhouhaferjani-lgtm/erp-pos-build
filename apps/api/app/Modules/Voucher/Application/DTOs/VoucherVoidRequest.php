<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\DTOs;

use Illuminate\Support\Carbon;

/**
 * Command DTO for VoucherVoidService::void() — the single write path for the
 * voucher void edge (Session B lane Q-5, sweep findings #22 + #24).
 *
 * The three callers differ only in provenance, never in accounting behaviour:
 *   - back-office manual void  → policyTrigger 'manual_void', carries a reason
 *   - credit-note cascade void → policyTrigger 'cascade_credit_note_void', carries
 *     the credit note as receiptId + terminalId
 *   - fraud auto-void          → policyTrigger 'auto_fraud_void', carries terminalId
 *
 * The idempotency key of a void is structural, not client-supplied: a voucher has
 * at most one Voided ledger row, enforced under the row lock by the service and
 * backed at the DB by the `uniq_voucher_ledger_voided_per_voucher` partial unique
 * index. A re-entered void therefore short-circuits instead of appending a row.
 */
final readonly class VoucherVoidRequest
{
    /**
     * @param  string  $voucherId  Voucher aggregate id
     * @param  string  $userId  Actor recorded on the ledger row
     * @param  string  $policyTrigger  Provenance discriminator, preserved verbatim on the ledger row
     * @param  string|null  $reason  Non-null only for operator-initiated voids; appended to
     *                               notes and stored on override_reason
     * @param  string|null  $receiptId  Credit note that cascaded the void, when applicable
     * @param  string|null  $terminalId  Terminal the void originated from, when applicable
     * @param  string|null  $tenantId  Optional scoping guard applied to the locked load
     * @param  string|null  $companyId  Optional scoping guard applied to the locked load
     * @param  Carbon|null  $occurredAt  Explicit event time (cascade shares one instant across a batch)
     */
    public function __construct(
        public string $voucherId,
        public string $userId,
        public string $policyTrigger,
        public ?string $reason = null,
        public ?string $receiptId = null,
        public ?string $terminalId = null,
        public ?string $tenantId = null,
        public ?string $companyId = null,
        public ?Carbon $occurredAt = null,
    ) {}
}
