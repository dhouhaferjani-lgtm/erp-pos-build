<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ReceiptFilterCashierData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {}
}
