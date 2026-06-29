<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Product\Domain\Enums\RestockPolicy;
use App\Modules\Product\Domain\Enums\RestockPolicySource;

final class EffectiveRestockPolicy
{
    public function __construct(
        public readonly RestockPolicy $policy,
        public readonly RestockPolicySource $source,
        public readonly ?int $sourceCategoryId,
    ) {}
}
