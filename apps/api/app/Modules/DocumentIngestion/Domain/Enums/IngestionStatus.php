<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Domain\Enums;

enum IngestionStatus: string
{
    case Uploaded = 'uploaded';
    case Extracting = 'extracting';
    case NeedsReview = 'needs_review';
    case Committing = 'committing';
    case Committed = 'committed';
    case Rejected = 'rejected';
    case Failed = 'failed';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Uploaded => [self::Extracting],
            self::Extracting => [self::Extracting, self::NeedsReview, self::Failed],
            self::Failed => [self::Extracting],
            self::NeedsReview => [self::Extracting, self::Committing, self::Rejected],
            self::Committing => [self::Committed, self::NeedsReview],
            self::Committed, self::Rejected => [],
        };
    }
}
