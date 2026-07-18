<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Projections\Concerns;

use App\Modules\Fiscal\Domain\DTOs\Canonical\PaymentDTO;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Treasury\Application\DTOs\MaturityLegContext;
use App\Modules\Treasury\Application\DTOs\MaturityLegResult;
use App\Modules\Treasury\Application\DTOs\ReceiveInstrumentData;
use App\Modules\Treasury\Application\Services\InstrumentAccountResolver;
use App\Modules\Treasury\Application\Services\InstrumentLifecycleService;
use App\Modules\Treasury\Domain\Enums\InstrumentAccountPurpose;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

final readonly class HandlesMaturityTenderLeg
{
    public function __construct(
        private InstrumentLifecycleService $lifecycle,
        private InstrumentAccountResolver $accountResolver,
        private CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function handles(PaymentMethod $method): bool
    {
        return $method->has_maturity
            && in_array($method->instrument_kind, [InstrumentKind::Cheque, InstrumentKind::Effet], true);
    }

    public function portfolioAccountId(PaymentMethod $method, string $companyId): string
    {
        $purpose = match ($method->instrument_kind) {
            InstrumentKind::Cheque => InstrumentAccountPurpose::ChecksToCollect,
            InstrumentKind::Effet => InstrumentAccountPurpose::EffectsReceivable,
            default => throw new RuntimeException('Payment method is not a cheque or effet maturity tender.'),
        };

        return $this->accountResolver->resolveOrFail($purpose, $companyId);
    }

    public function handleMaturityLeg(
        FiscalEvent $event,
        PaymentDTO $leg,
        int $index,
        PaymentMethod $method,
        MaturityLegContext $context,
    ): MaturityLegResult {
        if (! $this->handles($method)) {
            throw new RuntimeException('Maturity-leg handler invoked for a non-paper tender.');
        }
        if (! is_numeric($leg->amount)) {
            throw new RuntimeException('Maturity-leg amount must be a numeric string.');
        }
        $kind = $method->instrument_kind;
        if (! $kind instanceof InstrumentKind) {
            throw new RuntimeException('Maturity-leg payment method has no instrument kind.');
        }

        $idempotencyKey = sprintf('fiscal_event:%s:instrument:%d', $event->id, $index);
        $portfolioAccountId = $this->portfolioAccountId($method, $event->company_id);
        $instrument = PaymentInstrument::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (! $instrument instanceof PaymentInstrument) {
            try {
                $instrument = $this->lifecycle->receive(new ReceiveInstrumentData(
                    tenantId: $event->tenant_id,
                    companyId: $event->company_id,
                    paymentMethodId: $method->id,
                    kind: $kind,
                    direction: InstrumentDirection::Inbound,
                    origin: InstrumentOrigin::Pos,
                    reference: 'POS-'.substr($event->id, 0, 8).'-'.$index,
                    amount: $leg->amount,
                    currency: $context->currency,
                    repositoryId: $context->repositoryId,
                    partnerId: $context->partnerId,
                    maturityDate: null,
                    receivedDate: $context->receivedDate,
                    idempotencyKey: $idempotencyKey,
                    needsDetails: true,
                    createdBy: $context->createdBy,
                    locationId: $context->locationId,
                ));
            } catch (UniqueConstraintViolationException) {
                // receive() owns a nested transaction/savepoint. Query only
                // after it has rolled back so PostgreSQL is no longer aborted.
                $instrument = PaymentInstrument::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->firstOrFail();
            }
        }

        $scale = $this->scaleResolver->getScale($context->currency);
        $expectedAmount = CurrencyScale::bcformatStrict($leg->amount, $scale);
        if ($instrument->tenant_id !== $event->tenant_id
            || $instrument->company_id !== $event->company_id
            || $instrument->idempotency_key !== $idempotencyKey
            || $instrument->kind !== $kind
            || bccomp($instrument->amount, $expectedAmount, $scale) !== 0) {
            throw new RuntimeException(sprintf(
                'Treasury maturity-leg idempotency conflict for fiscal event %s leg %d.',
                $event->id,
                $index,
            ));
        }

        return new MaturityLegResult($instrument, $portfolioAccountId);
    }
}
