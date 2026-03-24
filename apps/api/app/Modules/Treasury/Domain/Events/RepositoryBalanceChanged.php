<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a payment repository balance changes.
 *
 * Dispatched from PaymentController::store() after balance update.
 * Immutable — never modify once deployed.
 */
final class RepositoryBalanceChanged extends DomainEvent
{
    public function __construct(
        public readonly string $repositoryId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $previousBalance,
        public readonly string $newBalance,
        public readonly string $changeAmount,
        public readonly string $currency,
        public readonly string $changedAt,
    ) {
        parent::__construct($repositoryId);
    }

    public function getEventName(): string
    {
        return 'treasury.repository.balance_changed';
    }

    /**
     * @return array<string, string>
     */
    public function getAuditPayload(): array
    {
        return [
            'repository_id' => $this->repositoryId,
            'previous_balance' => $this->previousBalance,
            'new_balance' => $this->newBalance,
            'change_amount' => $this->changeAmount,
            'currency' => $this->currency,
            'changed_at' => $this->changedAt,
        ];
    }
}
