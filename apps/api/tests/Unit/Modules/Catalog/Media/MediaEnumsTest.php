<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Catalog\Media;

use App\Modules\Catalog\Domain\Enums\MediaAssetType;
use App\Modules\Catalog\Domain\Enums\MediaOwnerType;
use App\Modules\Catalog\Domain\Enums\MediaRole;
use App\Modules\Catalog\Domain\Enums\MediaSource;
use App\Modules\Catalog\Domain\Enums\MediaStatus;
use App\Modules\Catalog\Domain\Enums\RenditionFormat;
use App\Modules\Catalog\Domain\Enums\RenditionName;
use PHPUnit\Framework\TestCase;

final class MediaEnumsTest extends TestCase
{
    public function test_enum_values_are_stable_strings(): void
    {
        self::assertSame('IMAGE', MediaAssetType::Image->value);
        self::assertSame('EXTERNAL_URL', MediaSource::ExternalUrl->value);
        self::assertSame('READY', MediaStatus::Ready->value);
        self::assertSame('THUMBNAIL', RenditionName::Thumbnail->value);
        self::assertSame('WEBP', RenditionFormat::Webp->value);
        self::assertSame('PRIMARY', MediaRole::Primary->value);
        self::assertSame('PRODUCT', MediaOwnerType::Product->value);
    }
}
