<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Media;

use App\Modules\Media\Application\Services\MediaUploadService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SourceRefIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_persists_source_ref_and_second_same_url_violates_unique(): void
    {
        Storage::fake('s3');
        Bus::fake();
        $service = app(MediaUploadService::class);
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();
        $url = 'https://pharma-shop.tn/img/serum.jpg';

        $asset = $service->uploadForProduct(
            $tenantId, $productId,
            UploadedFile::fake()->image('serum.jpg', 400, 400),
            null, $url,
        );

        $this->assertSame($url, $asset->refresh()->source_ref);

        $this->expectException(QueryException::class);
        $service->uploadForProduct(
            $tenantId, $productId,
            UploadedFile::fake()->image('serum2.jpg', 400, 400),
            null, $url,
        );
    }
}
