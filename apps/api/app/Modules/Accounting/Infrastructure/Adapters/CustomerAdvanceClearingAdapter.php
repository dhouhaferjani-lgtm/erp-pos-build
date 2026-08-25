<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Infrastructure\Adapters;

use App\Modules\Accounting\Domain\Enums\PostingMode;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Document\Domain\Document;
use App\Shared\Contracts\Accounting\CustomerAdvanceClearingInterface;

/**
 * Accounting's side of the {@see CustomerAdvanceClearingInterface} seam (N-6).
 *
 * A pure delegation to the existing, already-tested
 * `GeneralLedgerService::clearCustomerAdvanceToReceivable()` — this class adds
 * no policy of its own, exactly like {@see FiscalPeriodLockReader}. The ceiling
 * check ("never clear beyond the partner's available advance"), the partner
 * lock and the draft-netting all stay in the ledger service where they are
 * tested.
 *
 * `SynchronousInTransaction` is not a detail the caller may choose: clearing
 * must be atomic with the seal, and the AfterCommit branch silently leaves a
 * DRAFT entry when no actor can be resolved — which posting never can.
 */
final readonly class CustomerAdvanceClearingAdapter implements CustomerAdvanceClearingInterface
{
    public function __construct(
        private GeneralLedgerService $generalLedger,
    ) {}

    public function clearCustomerAdvanceForDocument(
        Document $document,
        string $amount,
        ?string $actorUserId,
    ): string {
        /** @var numeric-string $amount */
        $entry = $this->generalLedger->clearCustomerAdvanceToReceivable(
            $document->company_id,
            $document->partner_id,
            $document->id,
            $amount,
            now(),
            "Prepayment applied on posting of {$document->document_number}",
            $actorUserId,
            (string) $document->currency,
            PostingMode::SynchronousInTransaction,
        );

        return $entry->id;
    }
}
