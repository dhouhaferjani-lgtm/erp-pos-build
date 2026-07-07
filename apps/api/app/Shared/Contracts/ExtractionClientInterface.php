<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Modules\DocumentIngestion\Application\DTO\ExtractionResultData;
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
    ): ExtractionResultData;
}
