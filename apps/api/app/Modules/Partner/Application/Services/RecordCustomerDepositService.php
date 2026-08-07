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

        // W-5c D1 — resolve every Treasury reference the projection will need
        // BEFORE authoring anything. A DEPOSIT_RECEIPT is sealed into the hash
        // chain the instant it is authored and there is no delete route by design,
        // so a reference that cannot resolve MUST abort here: discovering it during
        // the (post-commit) projection run leaves a permanent orphan receipt that
        // over-states the customer's deposit history while moving no money.
        //
        // The refusal set is enumerated and kept in parity with the bridge — see
        // DepositReferenceResolutionService. `RecordDepositRequest` carries the
        // cheap subset as field-level rules, so an HTTP caller usually gets a
        // field-scoped 422 first; this is the complete check.
        $this->assertProjectable($partner, $methodCode, $repositoryId, $currencyCode, $actorUserId);

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
            // R2-K-prev — RE-VERIFY inside the sealing transaction.
            //
            // HONEST SCOPE (contractual, Codex round-3): two of the refusals are
            // time-of-check/time-of-use vectors, not input validation — the
            // repository can be frozen (a cash count) and the actor's company
            // membership can be revoked between the pre-flight above and the
            // moment the projection runs. Re-reading them HERE narrows the race
            // from "the whole request" to "the width of this transaction", and a
            // refusal rolls the transaction back so nothing is sealed.
            //
            // It does NOT close the race. `runSeededProjectionsSync()` below runs
            // AFTER this transaction commits, so a freeze or a revocation landing
            // in that gap still mints a permanent hash-chained orphan receipt.
            // Detection and disposition of such orphans is the separate
            // recoverability lane (R2-K-rec) and is deliberately not attempted
            // here. Ticket:
            // docs/superpowers/tickets/2026-08-05-deposit-residual-seal-before-resolve-vectors.md
            $this->assertProjectable($partner, $methodCode, $repositoryId, $currencyCode, $actorUserId);

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

    /**
     * Refuse the deposit unless every Treasury reference the projection will
     * need resolves right now.
     *
     * Called TWICE by design (W-5c D1 + R2-K-prev): once before the transaction
     * opens, so the common case fails cheaply without taking any lock, and once
     * inside it, immediately before the append — see the call site for the
     * TOCTOU scope statement.
     *
     * @throws UnresolvableDepositReferenceException
     */
    private function assertProjectable(
        Partner $partner,
        string $methodCode,
        ?string $repositoryId,
        string $currencyCode,
        string $actorUserId,
    ): void {
        $refusal = $this->referenceResolution->refusalFor(
            tenantId: $partner->tenant_id,
            companyId: $partner->company_id,
            methodCode: $methodCode,
            repositoryId: $repositoryId,
            currencyCode: $currencyCode,
            actorUserId: $actorUserId,
        );

        if ($refusal === null) {
            return;
        }

        throw UnresolvableDepositReferenceException::forRefusal(
            refusal: $refusal,
            methodCode: $methodCode,
            repositoryId: $repositoryId,
            currencyCode: $currencyCode,
            companyId: $partner->company_id,
            actorUserId: $actorUserId,
        );
    }

    private function balance(?string $value, int $scale): string
    {
        return CurrencyScale::bcformat($value ?? '0', $scale);
    }
}
