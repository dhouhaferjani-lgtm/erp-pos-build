<?php

declare(strict_types=1);

namespace Tests\Fixtures\TypeScript;

use Spatie\LaravelData\Data;

/**
 * Fixture DTO for DataCollectionLoweringTest. NOT tagged with #[TypeScript]
 * so the production transform command ignores it.
 */
final class ChildData extends Data
{
    public function __construct(
        public readonly string $value,
    ) {}
}
