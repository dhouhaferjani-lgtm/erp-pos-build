<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\DTOs;

use Spatie\LaravelData\Data;

class ProductSubmissionData extends Data
{
    /**
     * @param array<string, mixed>|null $attributes
     * @param array<int, string> $photoIds
     */
    public function __construct(
        public ?string $barcode,
        public string $vertical,
        public string $name,
        public string $brand,
        public ?string $category,
        public ?string $description,
        public ?array $attributes,
        public array $photoIds,
        public bool $autoEnrich,
    ) {}
}
