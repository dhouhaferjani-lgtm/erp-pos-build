<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Treasury\DTOs;

/**
 * Narrow, read-only projection of an OUTBOUND payment instrument's settlement
 * linkage — exactly the two fields the Expense-module lifecycle listener needs
 * to mark an expense paid when its instrument clears.
 *
 * Task 3 (treasury burn-down) — the Expense listener
 * `SyncExpenseOnInstrumentLifecycle` previously read the Treasury
 * `PaymentInstrument` Eloquent model directly, violating the module boundary
 * (cross-module access only via Shared/Contracts, events, or a public service).
 * This DTO exposes ONLY `repositoryId` + `paymentMethodId`; direction, status,
 * amount and every other instrument attribute stay inside Treasury.
 */
final readonly class OutboundInstrumentPaymentLink
{
    public function __construct(
        public ?string $repositoryId,
        public string $paymentMethodId,
    ) {}
}
