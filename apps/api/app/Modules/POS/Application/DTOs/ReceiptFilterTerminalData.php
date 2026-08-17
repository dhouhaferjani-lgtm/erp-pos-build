<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ReceiptFilterTerminalData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
        public readonly bool $is_active,
        public readonly bool $v4_refund_authoring_enabled,
        public readonly ?string $v4_refund_authoring_acknowledged_at,
    ) {}
}
