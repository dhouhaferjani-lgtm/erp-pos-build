<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One lot row of one reconciliation line. Every quantity is a 4-dp string. */
#[TypeScript]
final class TransferReconciliationLotData extends Data
{
    public function __construct(
        public string $batch_id,
        public string $batch_number,
        public ?string $expiry_date,
        public string $sent,
        public string $received,
        public string $damaged,
        public string $written_off,
        public string $returned,
        public string $remaining,
        public string $variance,
    ) {}
}
