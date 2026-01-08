<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Product\Domain\HealthClaim;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ProductHealthClaimData extends Data
{
    public function __construct(
        public HealthClaimData $health_claim,
        public int $display_order,
    ) {}

    public static function fromPivot(HealthClaim $healthClaim, Pivot $pivot): self
    {
        return new self(
            health_claim: HealthClaimData::fromModel($healthClaim),
            display_order: $pivot->display_order ?? 0,
        );
    }
}
