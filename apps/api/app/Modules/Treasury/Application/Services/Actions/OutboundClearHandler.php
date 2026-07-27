<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services\Actions;

use App\Modules\Treasury\Application\Contracts\StatementActionHandlerInterface;
use App\Modules\Treasury\Application\DTOs\ExecutionResult;
use App\Modules\Treasury\Application\Services\OutboundInstrumentService;
use App\Modules\Treasury\Application\Services\StatementActionDigest;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\MatchActionType;
use App\Modules\Treasury\Domain\PaymentInstrument;
use DomainException;

final readonly class OutboundClearHandler implements StatementActionHandlerInterface
{
    public function __construct(private OutboundInstrumentService $instruments) {}

    public function supports(MatchActionType $action): bool
    {
        return $action === MatchActionType::OutboundClear;
    }

    /** @param array<string, mixed> $params */
    public function execute(BankStatementLine $line, array $params, string $userId): ExecutionResult
    {
        $instrumentId = $this->requiredString($params, 'instrument_id');
        $statement = $line->statement()->firstOrFail();
        $instrument = PaymentInstrument::query()
            ->where('tenant_id', $statement->tenant_id)
            ->where('company_id', $statement->company_id)
            ->where('repository_id', $line->payment_repository_id)
            ->whereKey($instrumentId)
            ->lockForUpdate()
            ->first();
        if (! $instrument instanceof PaymentInstrument || $instrument->direction !== InstrumentDirection::Outbound) {
            throw new DomainException('The outbound instrument does not belong to the statement repository.');
        }

        $result = match ($instrument->status) {
            InstrumentStatus::Received => $this->instruments->clear(
                $instrument->id,
                $statement->tenant_id,
                $statement->company_id,
                $userId,
                $line->value_date->toDateString(),
            ),
            InstrumentStatus::Bounced => $this->instruments->represent(
                $instrument->id,
                $statement->tenant_id,
                $statement->company_id,
                $userId,
            ),
            default => throw new DomainException('Only a received or bounced outbound instrument can be confirmed from a statement.'),
        };
        if ($result->movementId === null) {
            throw new DomainException('Outbound clearing did not produce a repository movement.');
        }

        return new ExecutionResult(
            movementIds: [$result->movementId],
            targetType: 'payment_instrument',
            targetId: $instrument->id,
            semanticDigest: StatementActionDigest::make(MatchActionType::OutboundClear, $line, $params),
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
}
