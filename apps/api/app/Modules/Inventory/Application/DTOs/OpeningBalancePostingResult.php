<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Inventory\Domain\Enums\OpeningLotExpiryOutcome;

final readonly class OpeningBalancePostingResult
{
    /**
     * @param  array<int, string>  $movementIdsInInputOrder
     * @param  array<int, OpeningLotExpiryOutcome>  $expiryOutcomesInInputOrder  W4-1 gate r1 —
     *                                                                           what became of each line's supplied `expiry_date`. Keyed by the same input
     *                                                                           index as $movementIdsInInputOrder so the import phase can attach a row
     *                                                                           warning to the row that supplied it. A caller that ignores this is
     *                                                                           reporting `ok` for a row whose date it threw away.
     */
    public function __construct(
        public JournalEntry $entry,
        public array $movementIdsInInputOrder,
        public array $expiryOutcomesInInputOrder = [],
    ) {}
}
