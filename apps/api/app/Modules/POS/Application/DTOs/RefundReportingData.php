<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class RefundReportingData extends Data
{
    /** @param list<array<string, mixed>> $refund_policy_alerts */
    public function __construct(
        public readonly ?string $original_receipt_number,
        public readonly ?string $refund_reason,
        public readonly string $refund_reason_source,
        public readonly ?string $refund_destination,
        public readonly array $refund_policy_alerts,
    ) {}
}
