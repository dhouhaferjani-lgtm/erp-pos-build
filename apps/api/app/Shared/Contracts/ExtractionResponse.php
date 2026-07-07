<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Modules\DocumentIngestion\Application\DTO\ExtractionResultData;

final readonly class ExtractionResponse
{
    public function __construct(
        public ExtractionResultData $result,
        public string $provider,
        public ?string $model,
    ) {}
}
