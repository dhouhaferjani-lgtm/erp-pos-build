<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class DiscountPolicyVerdict extends Data
{
    /**
     * @param  array<int, string>  $reasons
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public bool $allowed,
        public bool $blocksSale,
        public string $severity,
        public ?string $requiresPermission,
        public string $maxDiscountPercent,
        public ?string $discountPercent,
        public ?string $floorPriceNet,
        public string $floorBasis,
        public string $floorEnforcement,
        public string $mode,
        public bool $overridable,
        public bool $requiresReason,
        public string $policyVersion,
        public string $policyAsOf,
        public array $reasons = [],
        public array $meta = [],
    ) {}
}
