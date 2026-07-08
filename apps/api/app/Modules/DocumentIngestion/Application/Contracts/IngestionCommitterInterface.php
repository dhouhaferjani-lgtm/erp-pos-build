<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Application\Contracts;

use App\Modules\DocumentIngestion\Application\DTO\CommitResultData;
use App\Modules\DocumentIngestion\Application\DTO\ReviewedPayloadData;
use App\Modules\DocumentIngestion\Domain\DocumentIngestion;
use App\Modules\DocumentIngestion\Domain\Enums\DocumentKind;

interface IngestionCommitterInterface
{
    public function supports(DocumentKind $kind): bool;

    public function commit(DocumentIngestion $ingestion, ReviewedPayloadData $payload, string $actorId): CommitResultData;
}
