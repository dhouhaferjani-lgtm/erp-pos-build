<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Catalog\Media;

use App\Modules\Media\Domain\Enums\MediaOwnerType;
use PHPUnit\Framework\TestCase;

final class MediaOwnerTypeTest extends TestCase
{
    public function test_document_case_and_storage_segments(): void
    {
        $this->assertSame('DOCUMENT', MediaOwnerType::Document->value);
        $this->assertSame('DOCUMENT_INGESTION', MediaOwnerType::DocumentIngestion->value);
        $this->assertSame('products', MediaOwnerType::Product->storageSegment());
        $this->assertSame('documents', MediaOwnerType::Document->storageSegment());
        $this->assertSame('ingestions', MediaOwnerType::DocumentIngestion->storageSegment());
        $this->assertSame('product-variants', MediaOwnerType::ProductVariant->storageSegment());
        $this->assertSame('categories', MediaOwnerType::Category->storageSegment());
    }
}
