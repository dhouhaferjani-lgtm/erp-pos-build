<?php

declare(strict_types=1);

namespace App\Modules\Media\Domain\Enums;

enum MediaOwnerType: string
{
    case Product = 'PRODUCT';
    case ProductVariant = 'PRODUCT_VARIANT';
    case Category = 'CATEGORY';
    case Document = 'DOCUMENT';
    case DocumentIngestion = 'DOCUMENT_INGESTION';

    public function storageSegment(): string
    {
        return match ($this) {
            self::Product => 'products',
            self::ProductVariant => 'product-variants',
            self::Category => 'categories',
            self::Document => 'documents',
            self::DocumentIngestion => 'ingestions',
        };
    }
}
