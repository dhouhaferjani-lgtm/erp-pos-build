<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Application\Contracts;

final readonly class ExtractionHints
{
    public function __construct(
        public string $languageHint = 'fr',
        public ?string $currencyHint = null,
    ) {}
}
