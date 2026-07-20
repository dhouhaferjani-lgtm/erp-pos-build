<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services\Actions;

use App\Modules\Treasury\Application\Contracts\StatementActionHandlerInterface;
use App\Modules\Treasury\Application\DTOs\ClearInstrumentData;
use App\Modules\Treasury\Application\DTOs\ExecutionResult;
use App\Modules\Treasury\Application\Services\InstrumentLifecycleService;
use App\Modules\Treasury\Application\Services\StatementActionDigest;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentEventType;
use App\Modules\Treasury\Domain\Enums\MatchActionType;
use App\Modules\Treasury\Domain\InstrumentEvent;
use App\Modules\Treasury\Domain\PaymentInstrument;
use DomainException;

final readonly class InboundClearHandler implements StatementActionHandlerInterface
{
    public function __construct(private InstrumentLifecycleService $instruments) {}

    public function supports(MatchActionType $action): bool
    {
        return $action === MatchActionType::InboundClear;
    }

    /** @param array<string, mixed> $params */
    public function execute(BankStatementLine $line, array $params, string $userId): ExecutionResult
    {
        $instrumentId = $this->requiredString($params, 'instrument_id');
        $statement = $line->statement()->firstOrFail();
        $instrument = PaymentInstrument::query()
            ->where('tenant_id', $statement->tenant_id)
            ->where('company_id', $statement->company_id)
            ->where('deposited_to_id', $line->payment_repository_id)
            ->whereKey($instrumentId)
            ->lockForUpdate()
            ->first();
        if (! $instrument instanceof PaymentInstrument || $instrument->direction !== InstrumentDirection::Inbound) {
            throw new DomainException('The inbound instrument does not belong to the statement repository.');
        }
        $feeAmount = $this->optionalMoneyString($params, 'fee_amount', '0.000');
        $feeVatAmount = $this->optionalMoneyString($params, 'fee_vat_amount', '0.000');
        $this->instruments->clear(new ClearInstrumentData(
            instrumentId: $instrument->id,
            currency: $statement->currency,
            feeAmount: $feeAmount,
            feeVatAmount: $feeVatAmount,
            valueDate: $line->value_date->toDateString(),
            userId: $userId,
        ));
        $event = InstrumentEvent::query()
            ->where('instrument_id', $instrument->id)
            ->where('event_type', InstrumentEventType::Cleared)
            ->whereNotNull('movement_id')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
        if (! $event instanceof InstrumentEvent || $event->movement_id === null) {
            throw new DomainException('Inbound clearing did not produce a repository movement.');
        }

        return new ExecutionResult(
            movementIds: [$event->movement_id],
            targetType: 'payment_instrument',
            targetId: $instrument->id,
            semanticDigest: StatementActionDigest::make(MatchActionType::InboundClear, $line, $params),
        );
    }

    /** @param array<string, mixed> $params */
    private function requiredString(array $params, string $key): string
    {
        $value = $params[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new DomainException("Statement action parameter {$key} is required.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return numeric-string
     */
    private function optionalMoneyString(array $params, string $key, string $default): string
    {
        $value = $params[$key] ?? $default;
        if (! is_string($value) || ! is_numeric($value) || preg_match('/^-?\d+(?:\.\d+)?$/', $value) !== 1) {
            throw new DomainException("Statement action parameter {$key} must be a numeric string.");
        }

        return $value;
    }
}
