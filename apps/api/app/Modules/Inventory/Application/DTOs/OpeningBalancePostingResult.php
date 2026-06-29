<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Accounting\Domain\JournalEntry;

final readonly class OpeningBalancePostingResult
{
    /** @param array<int, string> $movementIdsInInputOrder */
    public function __construct(
        public JournalEntry $entry,
        public array $movementIdsInInputOrder,
    ) {}
}
