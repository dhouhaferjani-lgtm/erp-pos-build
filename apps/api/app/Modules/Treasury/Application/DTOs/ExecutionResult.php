<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

final readonly class ExecutionResult
{
    /** @param list<string> $movementIds */
    public function __construct(
        public array $movementIds,
        public ?string $targetType,
        public ?string $targetId,
        public string $semanticDigest,
    ) {}
}
