<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class BarcodeLookupResultData extends Data
{
    /**
     * @param array<string, mixed>|null $article
     * @param array<string, mixed>|null $suggestedProduct
     */
    public function __construct(
        public string $status,
        public ?string $barcode,
        public ?array $article,
        public ?array $suggestedProduct,
        public ?string $error_reason,
    ) {}

    /**
     * @param array<string, mixed> $article
     * @param array<string, mixed> $suggestedProduct
     */
    public static function found(string $barcode, array $article, array $suggestedProduct): self
    {
        return new self(
            status: 'found',
            barcode: $barcode,
            article: $article,
            suggestedProduct: $suggestedProduct,
            error_reason: null,
        );
    }

    public static function notFound(string $barcode): self
    {
        return new self(
            status: 'not_found',
            barcode: $barcode,
            article: null,
            suggestedProduct: null,
            error_reason: null,
        );
    }

    public static function error(string $barcode, string $reason): self
    {
        return new self(
            status: 'error',
            barcode: $barcode,
            article: null,
            suggestedProduct: null,
            error_reason: $reason,
        );
    }
}
