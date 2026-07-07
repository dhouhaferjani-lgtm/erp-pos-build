<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Application\Contracts;

final class ExtractionFailedException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $failureCode = 'EXTRACTION_FAILED',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }
}
