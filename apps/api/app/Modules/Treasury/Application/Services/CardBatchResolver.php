<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Treasury\Domain\BankStatement;
use App\Modules\Treasury\Domain\BankStatementLineAllocation;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\RepositoryMovement;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use DomainException;

final readonly class CardBatchResolver
{
    public function __construct(private CurrencyScaleResolverInterface $scaleResolver) {}

    /**
     * @return list<array{
     *     paymentMethod: PaymentMethod,
     *     businessDate: string,
     *     movementIds: list<string>,
     *     grossAmount: numeric-string
     * }>
     */
    public function groups(BankStatement $statement): array
    {
        $movements = RepositoryMovement::query()
            ->where('tenant_id', $statement->tenant_id)
            ->where('company_id', $statement->company_id)
            ->where('payment_repository_id', $statement->payment_repository_id)
            ->where('currency', $statement->currency)
            ->whereIn('source_type', [MovementSourceType::FiscalEvent, MovementSourceType::Refund])
            ->orderBy('id')
            ->get();
        if ($movements->isEmpty()) {
            return [];
        }

        $movementIds = $movements->pluck('id')->all();
        $allocated = BankStatementLineAllocation::query()
            ->whereIn('repository_movement_id', $movementIds)
            ->pluck('repository_movement_id')
            ->flip();
        $saleKeys = $movements
            ->where('source_type', MovementSourceType::FiscalEvent)
            ->pluck('idempotency_key')
            ->all();
        $refundPaymentIds = $movements
            ->where('source_type', MovementSourceType::Refund)
            ->pluck('source_id')
            ->all();
        $paymentsByLeg = Payment::query()
            ->where('tenant_id', $statement->tenant_id)
            ->where('company_id', $statement->company_id)
            ->whereIn('idempotency_key', $saleKeys)
            ->get()
            ->keyBy('idempotency_key');
        $paymentsById = Payment::query()
            ->where('tenant_id', $statement->tenant_id)
            ->where('company_id', $statement->company_id)
            ->whereIn('id', $refundPaymentIds)
            ->get()
            ->keyBy('id');
        $payments = $paymentsByLeg->concat($paymentsById)->unique('id');
        $eventIds = $payments->pluck('fiscal_event_id')->filter()->unique()->values()->all();
        $methodIds = $payments->pluck('payment_method_id')->filter()->unique()->values()->all();
        $events = FiscalEvent::query()
            ->where('tenant_id', $statement->tenant_id)
            ->where('company_id', $statement->company_id)
            ->whereIn('id', $eventIds)
            ->get()
            ->keyBy('id');
        $methods = PaymentMethod::query()
            ->where('tenant_id', $statement->tenant_id)
            ->where('company_id', $statement->company_id)
            ->where('is_active', true)
            ->where('has_deducted_fees', true)
            ->where('default_repository_id', $statement->payment_repository_id)
            ->whereNotNull('fee_account_id')
            ->whereIn('id', $methodIds)
            ->get()
            ->keyBy('id');
        $scale = $this->scaleResolver->getScale($statement->currency);
        $grouped = [];

        foreach ($movements as $movement) {
            $payment = $movement->source_type === MovementSourceType::FiscalEvent
                ? $paymentsByLeg->get($movement->idempotency_key)
                : $paymentsById->get($movement->source_id);
            if (! $payment instanceof Payment
                || $payment->repository_id !== $statement->payment_repository_id
                || $payment->fiscal_event_id === null
                || $payment->payment_method_id === null) {
                continue;
            }
            $event = $events->get($payment->fiscal_event_id);
            $method = $methods->get($payment->payment_method_id);
            if (! $event instanceof FiscalEvent || ! $method instanceof PaymentMethod) {
                continue;
            }
            if (($movement->source_type === MovementSourceType::FiscalEvent && $movement->direction !== MovementDirection::In)
                || ($movement->source_type === MovementSourceType::Refund && $movement->direction !== MovementDirection::Out)) {
                continue;
            }
            if ($movement->source_type === MovementSourceType::FiscalEvent
                && bccomp($movement->amount, $payment->amount, $scale) !== 0) {
                continue;
            }

            $businessDate = $event->business_date->toDateString();
            $key = $method->id.'|'.$businessDate;
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'paymentMethod' => $method,
                    'businessDate' => $businessDate,
                    'movementIds' => [],
                    'grossAmount' => CurrencyScale::bcformatStrict('0', $scale),
                    'hasAllocation' => false,
                ];
            }
            $grouped[$key]['movementIds'][] = $movement->id;
            $grouped[$key]['grossAmount'] = $movement->direction === MovementDirection::In
                ? bcadd($grouped[$key]['grossAmount'], $movement->amount, $scale)
                : bcsub($grouped[$key]['grossAmount'], $movement->amount, $scale);
            $grouped[$key]['hasAllocation'] = $grouped[$key]['hasAllocation'] || $allocated->has($movement->id);
        }

        ksort($grouped, SORT_STRING);
        $result = [];
        foreach ($grouped as $group) {
            if ($group['hasAllocation'] || bccomp($group['grossAmount'], '0', $scale) <= 0) {
                continue;
            }
            sort($group['movementIds'], SORT_STRING);
            unset($group['hasAllocation']);
            $result[] = $group;
        }

        return $result;
    }

    /**
     * @param  list<string>  $movementIds
     * @return array{paymentMethod: PaymentMethod, businessDate: string, movementIds: list<string>, grossAmount: numeric-string}
     */
    public function assertGroup(
        BankStatement $statement,
        string $paymentMethodId,
        string $businessDate,
        array $movementIds,
        string $grossAmount,
    ): array {
        $expectedIds = array_values(array_unique($movementIds));
        sort($expectedIds, SORT_STRING);
        $scale = $this->scaleResolver->getScale($statement->currency);
        $expectedGross = CurrencyScale::bcformatStrict($grossAmount, $scale);
        foreach ($this->groups($statement) as $group) {
            if ($group['paymentMethod']->id === $paymentMethodId
                && $group['businessDate'] === $businessDate
                && $group['movementIds'] === $expectedIds
                && bccomp($group['grossAmount'], $expectedGross, $scale) === 0) {
                return $group;
            }
        }

        throw new DomainException('Acquirer movements are not one unallocated payment-method business-day group.');
    }
}
