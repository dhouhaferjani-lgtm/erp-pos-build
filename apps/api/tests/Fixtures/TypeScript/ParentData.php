<?php

declare(strict_types=1);

namespace Tests\Fixtures\TypeScript;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Fixture DTO for DataCollectionLoweringTest. NOT tagged with #[TypeScript]
 * so the production transform command ignores it — the test invokes the
 * transformer directly on this class.
 */
final class ParentData extends Data
{
    /**
     * @param  DataCollection<int, ChildData>|array<int, ChildData>  $children
     */
    public function __construct(
        public readonly string $label,
        #[DataCollectionOf(ChildData::class)]
        public readonly DataCollection|array $children,
    ) {}
}
