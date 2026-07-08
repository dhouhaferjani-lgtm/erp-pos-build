<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Application\Contracts;

use App\Modules\DocumentIngestion\Domain\Enums\DocumentKind;

interface ExtractionClientInterface
{
    /**
     * @throws ExtractionFailedException
     */
    public function extract(
        string $fileContents,
        string $mimeType,
        DocumentKind $kind,
        ExtractionHints $hints,
    ): ExtractionResponse;
}
