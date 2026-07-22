<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class MaturingInstrumentRowData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $reference,
        public readonly string $amount,
        public readonly string $currency,
        public readonly ?string $maturity_date,
        public readonly string $received_date,
        public readonly string $status,
        public readonly string $direction,
        public readonly ?string $kind,
        public readonly ?string $repository_id,
        public readonly ?string $location_id,
        public readonly ?string $location_name,
        public readonly ?string $partner_id,
        public readonly bool $needs_details,
        public readonly string $certainty,
        public readonly string $bucket,
    ) {}
}
