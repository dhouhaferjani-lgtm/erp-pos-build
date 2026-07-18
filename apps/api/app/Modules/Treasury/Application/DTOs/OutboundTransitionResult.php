<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

final readonly class OutboundTransitionResult
{
    public function __construct(
        public string $instrumentId,
        public string $fromStatus,
        public string $toStatus,
        public ?string $journalEntryId,
        public ?string $movementId,
        public bool $replayed,
    ) {}
}
