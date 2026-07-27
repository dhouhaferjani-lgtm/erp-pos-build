<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services\Actions;

use App\Modules\Treasury\Application\Contracts\StatementActionHandlerInterface;
use App\Modules\Treasury\Application\DTOs\ExecutionResult;
use App\Modules\Treasury\Application\Services\AcquirerFeeService;
use App\Modules\Treasury\Application\Services\CardBatchResolver;
use App\Modules\Treasury\Application\Services\StatementActionDigest;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\Enums\MatchActionType;
use DomainException;

final readonly class AcquirerFeeHandler implements StatementActionHandlerInterface
{
    public function __construct(
        private AcquirerFeeService $fees,
        private CardBatchResolver $cardBatches,
    ) {}

    public function supports(MatchActionType $action): bool
    {
        return $action === MatchActionType::AcquirerFee;
    }

    /** @param array<string, mixed> $params */
    public function execute(BankStatementLine $line, array $params, string $userId): ExecutionResult
    {
        $methodId = $this->requiredString($params, 'payment_method_id');
        $businessDate = $this->requiredString($params, 'business_date');
        $gross = $this->requiredString($params, 'gross_amount');
        $fee = $this->requiredString($params, 'fee_amount');
        $movementIds = $params['gross_movement_ids'] ?? null;
        if (! is_array($movementIds) || $movementIds === [] || ! array_is_list($movementIds)) {
            throw new DomainException('Statement action parameter gross_movement_ids must be a non-empty list.');
        }
        foreach ($movementIds as $movementId) {
            if (! is_string($movementId) || trim($movementId) === '') {
                throw new DomainException('Every gross movement id must be a string.');
            }
        }
        $movementIds = array_values(array_unique($movementIds));
        sort($movementIds, SORT_STRING);
        $statement = $line->statement()->firstOrFail();
        $group = $this->cardBatches->assertGroup($statement, $methodId, $businessDate, $movementIds, $gross);

        $feeMovement = $this->fees->record($line, $methodId, $gross, $fee, $userId);

        return new ExecutionResult(
            movementIds: [...$group['movementIds'], $feeMovement->id],
            targetType: 'payment_method',
            targetId: $methodId,
            semanticDigest: StatementActionDigest::make(MatchActionType::AcquirerFee, $line, $params),
        );
    }

    /** @param array<string, mixed> $params */
    private function requiredString(array $params, string $key): string
    {
        $value = $params[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new DomainException("Statement action parameter {$key} is required.");
        }

        return trim($value);
    }
}
