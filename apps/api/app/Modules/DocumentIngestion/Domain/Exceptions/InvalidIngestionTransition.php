<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Domain\Exceptions;

use App\Modules\DocumentIngestion\Domain\Enums\IngestionStatus;
use DomainException;

final class InvalidIngestionTransition extends DomainException
{
    public static function from(IngestionStatus $current, IngestionStatus $next): self
    {
        return new self(sprintf(
            'Cannot transition document ingestion from [%s] to [%s].',
            $current->value,
            $next->value,
        ));
    }
}
