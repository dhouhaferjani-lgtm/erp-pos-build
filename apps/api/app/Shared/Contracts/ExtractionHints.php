<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

final readonly class ExtractionHints
{
    public function __construct(
        public string $languageHint = 'fr',
        public ?string $currencyHint = null,
    ) {}
}
