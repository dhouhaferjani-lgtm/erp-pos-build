<?php

declare(strict_types=1);

namespace App\Application\Sweep\Domain;

use App\Application\Sweep\InventoryService;

/**
 * Returned from {@see InventoryService::mutate()}.
 * Conveys the sha256 chain link the mutation just sealed and a count of
 * caller-appended history events (the chain event the service appends
 * automatically is NOT counted here — callers care about *their* events).
 */
final class MutationResult
{
    public function __construct(
        public readonly string $previousYamlSha256,
        public readonly string $newYamlSha256,
        public readonly int $eventsAppended,
    ) {}
}
