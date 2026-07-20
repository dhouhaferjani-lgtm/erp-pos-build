<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use App\Modules\Treasury\Domain\Enums\MatchActionType;

final readonly class StatementSuggestion
{
    /**
     * @param  list<string>  $movementIds
     * @param  numeric-string  $amount
     * @param  array<string, mixed>  $actionParams
     */
    public function __construct(
        public int $tier,
        public string $kind,
        public array $movementIds,
        public ?MatchActionType $actionType,
        public ?string $targetType,
        public ?string $targetId,
        public string $amount,
        public string $reason,
        public bool $referenceMatched = false,
        public array $actionParams = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tier' => $this->tier,
            'kind' => $this->kind,
            'movement_ids' => $this->movementIds,
            'action_type' => $this->actionType?->value,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'amount' => $this->amount,
            'reason' => $this->reason,
            'reference_matched' => $this->referenceMatched,
            'action_params' => $this->actionParams,
        ];
    }
}
