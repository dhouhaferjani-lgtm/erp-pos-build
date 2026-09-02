<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * Version-tolerant detail shared by job-level and row-level import errors.
 *
 * Optional properties make absent legacy keys disappear from serialization
 * instead of padding stored JSON with nulls. Spatie Data ignores unknown input
 * keys, so additive payloads written by later lanes remain readable by this
 * version. Explicit null values are preserved as explicit null values.
 */
#[TypeScript]
final class ImportErrorDetailData extends Data
{
    /**
     * @param  list<string>|Optional|null  $accepted
     * @param  list<UnitCandidateData>|Optional|null  $candidates
     * @param  list<string>|Optional|null  $candidate_skus
     * @param  list<int>|Optional|null  $row_numbers
     * @param  list<string>|Optional|null  $differing_fields
     */
    public function __construct(
        public readonly string|Optional|null $supplied,
        #[TypeScriptType('string[]|null')]
        public readonly array|Optional|null $accepted,
        #[DataCollectionOf(UnitCandidateData::class)]
        public readonly array|Optional|null $candidates,
        #[TypeScriptType('string[]|null')]
        public readonly array|Optional|null $candidate_skus,
        public readonly string|Optional|null $sku,
        public readonly string|Optional|null $existing_product_id,
        public readonly string|Optional|null $filename,
        public readonly string|Optional|null $reason,
        public readonly string|Optional|null $column,
        public readonly string|Optional|null $raw,
        public readonly string|Optional|null $remedy,
        public readonly string|Optional|null $held_quantity,
        public readonly string|Optional|null $held_at,
        public readonly string|Optional|null $barcode,
        #[TypeScriptType('number[]|null')]
        public readonly array|Optional|null $row_numbers,
        #[TypeScriptType('string[]|null')]
        public readonly array|Optional|null $differing_fields,
    ) {}
}
