<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class TierBenefitsData extends Data
{
    /**
     * @param  array<int, string>|null  $free_shipping
     * @param  array<int, string>|null  $priority_support
     * @param  array<int, string>|null  $exclusive_rewards
     * @param  array<int, string>|null  $birthday_bonus
     * @param  array<string, mixed>|null  $custom_benefits
     */
    public function __construct(
        public ?string $discount_percent = null,
        public ?array $free_shipping = null,
        public ?array $priority_support = null,
        public ?array $exclusive_rewards = null,
        public ?string $bonus_points_multiplier = null,
        public ?array $birthday_bonus = null,
        public ?int $extended_expiry_days = null,
        public ?string $welcome_bonus = null,
        public ?array $custom_benefits = null,
    ) {}
}
