<?php

declare(strict_types=1);

namespace App\Modules\Partner\Application\Services;

use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionDispatcher;
use App\Modules\Partner\Application\DTOs\RecordCustomerDepositResult;
use App\Modules\Partner\Application\Exceptions\UnresolvableDepositReferenceException;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Application\Services\VirtualAdminFiscalEventService;
use App\Modules\Treasury\Application\Services\DepositAllocationSummaryService;
use App\Modules\Treasury\Application\Services\DepositReferenceResolutionService;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Orchestrates a back-office customer-account deposit (Phase 5 design spec §4.1).
 *
 * One server-authored entry point that:
 *   1. asserts the partner can carry an account (is a customer),
 *   2. authors the `DEPOSIT_RECEIPT` fiscal event and seeds its projection rows
 *      atomically (author + dispatch in one transaction — closing the Phase 2
 *      "fiscal row without projection rows" crash gap),
 *   3. drives the projection pipeline synchronously so the response reflects the
 *      printable receipt, the Treasury FIFO allocation, and the refreshed
 *      balances regardless of the global queue connection, and
 *   4. reports how the money landed: `settled` against open invoices vs.
 *      `credited` as advance, derived from the partner's own balance deltas.
 *
 * Cross-module work goes through public services only (POS authoring, Fiscal
 * dispatch/reader) — never another module's models (CLAUDE.md rule 6). The money
 * math itself lives entirely in the shared `PaymentAllocationService` the Treasury
 * bridge calls; this service never re-derives it.
 */
final class RecordCustomerDepositService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly VirtualAdminFiscalEventService $fiscalEventService,
        private readonly FiscalEventProjectionDispatcher $projectionDispatcher,
        private readonly CanonicalPayloadReader $canonicalReader,
        private readonly DepositAllocationSummaryService $allocationSummary,
        private readonly DepositReferenceResolutionService $referenceResolution,
    ) {}

    public function record(
        Partner $partner,
        string $actorUserId,
        string $actorName,
        string $currencyCode,
        string $amount,
        string $methodCode,
        ?string $repositoryId,
        ?string $notes,
    ): RecordCustomerDepositResult {
        if (! $partner->isCustomer()) {
            throw new RuntimeException('partner_not_customer:partner_id='.$partner->id);
        }

        // W-5c D1 — resolve the Treasury references the projection will need BEFORE
        // authoring anything. A DEPOSIT_RECEIPT is sealed into the hash chain the
        // instant it is authored and there is no delete route by design, so a
        // reference that cannot resolve MUST abort here: discovering it during the
        // (post-commit) projection run leaves a permanent orphan receipt that
        // over-states the customer's deposit history while moving no money.
        // `RecordDepositRequest` carries the same predicates, so an HTTP caller
        // gets a 422 and never reaches this guard — it exists for any internal
        // caller that bypasses the FormRequest.
        if (! $this->referenceResolution->activePaymentMethodExists($partner->tenant_id, $partner->company_id, $methodCode)) {
            throw UnresolvableDepositReferenceException::paymentMethod($methodCode, $partner->company_id);
        }

        if (! $this->referenceResolution->activeRepositoryExists($partner->tenant_id, $partner->company_id, $repositoryId)) {
            throw UnresolvableDepositReferenceException::repository($repositoryId, $partner->company_id);
        }

        $scale = CurrencyScale::for($currencyCode);

        // Author the fiscal event (chain truth) + seed projection rows atomically.
        $event = $this->db->transaction(function () use (
            $partner,
            $actorUserId,
            $actorName,
            $currencyCode,
            $amount,
            $methodCode,
            $repositoryId,
            $notes,
        ) {
            $event = $this->fiscalEventService->appendDepositReceipt(
                partner: $partner,
                actorUserId: $actorUserId,
                actorName: $actorName,
                currencyCode: $currencyCode,
                amount: $amount,
                methodCode: $methodCode,
                repositoryId: $repositoryId,
                notes: $notes,
            );

            // Seed projection rows in the SAME transaction as the event (no async
            // enqueue) so the synchronous run below has no racing worker.
            $this->projectionDispatcher->seedProjections($event);

            return $event;
        });

        // Drive the projection pipeline synchronously (in-process) so the response
        // reflects the receipt + Treasury allocation in this request. A projector
        // failure leaves its row retryable + re-queued for async healing, and
        // propagates here.
        $this->projectionDispatcher->runSeededProjectionsSync($event);

        $view = $this->canonicalReader->forDepositReceipt($event);

        // How the money split — settled against open invoices vs. credited as
        // advance — read from the persisted allocations (exact at write time).
        $summary = $this->allocationSummary->summaryForFiscalEvent(
            tenantId: $partner->tenant_id,
            companyId: $partner->company_id,
            fiscalEventId: $event->id,
            scale: $scale,
        );

        // Balances reflect POSTED GL; a freshly-drafted customer-advance entry
        // updates them once the accounting cycle posts it (eventually consistent).
        $partner->refresh();

        return new RecordCustomerDepositResult(
            fiscalEventId: $event->id,
            depositReceiptUuid: $view->payload->depositReceiptUuid,
            amount: $view->payment->amount,
            currencyCode: $currencyCode,
            settledAmount: $summary['settled'],
            creditedAmount: $summary['credited'],
            receivableBalance: $this->balance($partner->receivable_balance, $scale),
            creditBalance: $this->balance($partner->credit_balance, $scale),
            netBalance: CurrencyScale::bcformat($partner->net_balance, $scale),
        );
    }

    private function balance(?string $value, int $scale): string
    {
        return CurrencyScale::bcformat($value ?? '0', $scale);
    }
}
