<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\Projections;

use App\Modules\Document\Application\DTOs\CreatePOSAccountChargeDraftCommand;
use App\Modules\Document\Application\Services\POSAccountChargeDraftService;
use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Domain\DTOs\Canonical\AccountChargeView;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Exceptions\ProjectionInvariantViolationException;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Shared\Contracts\Fiscal\FiscalEventProjector;

final class DocumentAccountChargeFactureBridge implements FiscalEventProjector
{
    public function __construct(
        private readonly CanonicalPayloadReader $canonicalReader,
        private readonly POSAccountChargeDraftService $draftService,
    ) {}

    public function name(): string
    {
        return 'document_account_charge_facture_bridge';
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        return $type === FiscalEventType::ACCOUNT_CHARGE;
    }

    public function requiresModule(): string
    {
        return 'Sales';
    }

    public function priority(): int
    {
        return 170;
    }

    public function apply(FiscalEvent $event): void
    {
        $view = $this->canonicalReader->forAccountCharge($event);

        if (! $this->shouldCreateFactureDraft($view)) {
            return;
        }

        $partner = $this->resolveCustomer($event, $view);

        $this->draftService->createDraft(new CreatePOSAccountChargeDraftCommand(
            tenantId: $event->tenant_id,
            companyId: $event->company_id,
            partnerId: $partner->id,
            fiscalEventId: $event->id,
            accountChargeUuid: $view->payload->accountChargeUuid,
            businessDate: $view->payload->businessDate,
            currencyCode: $view->payload->currencyCode,
            currencyScale: $view->payload->currencyScale,
            subtotal: $this->numericString($event, 'totals.subtotal', $view->totals->subtotal),
            vatTotal: $this->numericString($event, 'totals.vat_total', $view->totals->vatTotal),
            total: $this->numericString($event, 'totals.total', $view->totals->total),
            transactionDiscountAmount: $this->numericString(
                $event,
                'transaction_discount_amount',
                $view->payload->transactionDiscountAmount,
            ),
            lineItems: $view->lineItems,
            payloadSnapshot: $view->payload->toArray(),
            dueDate: $view->chargeTerms->dueDate,
        ));
    }

    private function shouldCreateFactureDraft(AccountChargeView $view): bool
    {
        return $view->customer->customerCategory === 'business'
            && $view->payload->invoiceClassification === 'b2b_facture_draft_requested';
    }

    private function resolveCustomer(FiscalEvent $event, AccountChargeView $view): Partner
    {
        if ($view->customer->customerSyncStatus !== 'synced') {
            throw $this->invariant($event, 'customer_sync_status_unsupported:'.$view->customer->customerSyncStatus);
        }

        $partner = Partner::query()
            ->where('tenant_id', $event->tenant_id)
            ->where('company_id', $event->company_id)
            ->whereIn('type', [PartnerType::Customer, PartnerType::Both])
            ->whereKey($view->customer->customerId)
            ->first();

        if (! $partner instanceof Partner) {
            throw $this->invariant($event, 'customer_not_found:customer_id='.$view->customer->customerId);
        }

        return $partner;
    }

    private function invariant(FiscalEvent $event, string $reason): ProjectionInvariantViolationException
    {
        return new ProjectionInvariantViolationException(
            projectorName: $this->name(),
            fiscalEventId: $event->id,
            reason: $reason,
        );
    }

    /**
     * @return numeric-string
     */
    private function numericString(FiscalEvent $event, string $field, string $value): string
    {
        if (! is_numeric($value)) {
            throw $this->invariant($event, 'non_numeric_money_field:'.$field);
        }

        return $value;
    }
}
