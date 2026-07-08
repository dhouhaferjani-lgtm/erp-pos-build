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
        public string $severity,
        public ?string $requiresPermission,
        public ?string $floorNet,
        public ?string $discountPercent,
        public array $reasons = [],
        public array $meta = [],
    ) {}
}
