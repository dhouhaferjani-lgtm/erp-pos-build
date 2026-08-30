<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A visible unit candidate included in an ambiguous-unit refusal.
 *
 * Tier is one of system, tenant, or company. It remains a string because it
 * describes resolution provenance rather than a persisted status column.
 */
#[TypeScript]
final class UnitCandidateData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
        public readonly string $category,
        public readonly string $tier,
    ) {}
}
