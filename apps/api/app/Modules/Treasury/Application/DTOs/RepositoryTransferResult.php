<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

final readonly class RepositoryTransferResult
{
    public function __construct(
        public string $transferGroupId,
        public ?string $journalEntryId,
        public MovementResult $out,
        public MovementResult $in,
        public bool $idempotentReplay,
    ) {}
}
