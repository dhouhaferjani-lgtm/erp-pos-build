<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Domain\Enums;

/**
 * Discriminator for what a bundle component references.
 *
 * - Part: references a Product (automotive part, consumable).
 * - Labor: references a Service (labor-catalog entry — hours of work).
 * - NestedBundle: references another ServiceBundle (composition).
 */
enum BundleComponentType: string
{
    case Part = 'part';
    case Labor = 'labor';
    case NestedBundle = 'nested_bundle';

    public function label(): string
    {
        return match ($this) {
            self::Part => 'Part',
            self::Labor => 'Labor',
            self::NestedBundle => 'Nested bundle',
        };
    }
}
