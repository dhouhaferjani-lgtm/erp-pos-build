<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Product;

use App\Modules\Media\Application\DTOs\FetchedImage;
use App\Modules\Media\Application\Exceptions\RemoteImageFetchException;
use App\Modules\Media\Application\Services\RemoteImageFetcher;
use App\Modules\Media\Domain\Contracts\HostResolverInterface;
use App\Modules\Media\Domain\Contracts\PinnedImageDownloaderInterface;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Media\Domain\Media\MediaAttachment;
use App\Modules\Product\Application\Services\EnrichmentImagePersister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature coverage for the enrichment image persister orchestrator.
 *
 * The real {@see RemoteImageFetcher} is
 * used verbatim (its SSRF resolve/guard seam runs), but its two collaborators —
 * host resolver and pinned downloader — are replaced with in-memory fakes so no
 * network or DNS is touched. Distinct real PNG bytes per URL give distinct
 * SHA-256 checksums, which is what the checksum backstop keys off.
 */
final class EnrichmentImagePersisterTest extends TestCase
{
    use RefreshDatabase;

    private FakeHostResolver $resolver;

    private FakePinnedDownloader $downloader;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        Bus::fake(); // swallow GenerateRenditions dispatched by MediaUploadService

        $this->resolver = new FakeHostResolver;
        $this->downloader = new FakePinnedDownloader;
        $this->app->instance(HostResolverInterface::class, $this->resolver);
        $this->app->instance(PinnedImageDownloaderInterface::class, $this->downloader);
    }

    private function persister(): EnrichmentImagePersister
    {
        return $this->app->make(EnrichmentImagePersister::class);
    }

    /** Generate distinct, valid PNG bytes keyed by (w,h) so checksums differ. */
    private function png(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        $color = imagecolorallocate($img, $w % 255, $h % 255, ($w + $h) % 255);
        imagefill($img, 0, 0, $color);
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    public function test_first_image_becomes_primary_second_gallery(): void
    {
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        $this->downloader->register('https://pharma-shop.tn/a.png', $this->png(2, 2));
        $this->downloader->register('https://pharma-shop.tn/b.png', $this->png(3, 4));

        $outcome = $this->persister()->persist($productId, $tenantId, [
            ['url' => 'https://pharma-shop.tn/a.png', 'thumbnail' => null, 'type' => null],
            ['url' => 'https://pharma-shop.tn/b.png', 'thumbnail' => null, 'type' => null],
        ], null);

        self::assertSame(2, $outcome->attached);
        self::assertSame(0, $outcome->failed);
        self::assertSame(0, $outcome->skipped);

        $primary = MediaAttachment::query()->where('owner_id', $productId)->where('role', MediaRole::Primary->value)->count();
        $gallery = MediaAttachment::query()->where('owner_id', $productId)->where('role', MediaRole::Gallery->value)->count();
        self::assertSame(1, $primary);
        self::assertSame(1, $gallery);
    }

    public function test_idempotent_on_rerun_same_urls(): void
    {
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();
        $this->downloader->register('https://pharma-shop.tn/a.png', $this->png(2, 2));
        $images = [['url' => 'https://pharma-shop.tn/a.png', 'thumbnail' => null, 'type' => null]];

        $this->persister()->persist($productId, $tenantId, $images, null);
        $second = $this->persister()->persist($productId, $tenantId, $images, null);

        self::assertSame(0, $second->attached);
        self::assertSame(1, $second->reused);
        self::assertSame(1, MediaAttachment::query()->where('owner_id', $productId)->count());
        self::assertSame(1, MediaAsset::query()->where('tenant_id', $tenantId)->count());
    }

    public function test_one_bad_url_does_not_abort_the_rest(): void
    {
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        // good.png is registered → downloads; bad.png is NOT registered → throws.
        $this->downloader->register('https://pharma-shop.tn/good.png', $this->png(2, 2));

        $outcome = $this->persister()->persist($productId, $tenantId, [
            ['url' => 'https://pharma-shop.tn/bad.png', 'thumbnail' => null, 'type' => null],
            ['url' => 'https://pharma-shop.tn/good.png', 'thumbnail' => null, 'type' => null],
        ], null);

        self::assertSame(1, $outcome->attached);
        self::assertSame(1, $outcome->failed);
        self::assertSame(1, MediaAttachment::query()->where('owner_id', $productId)->count());
    }

    public function test_checksum_backstop_skips_same_bytes_under_different_url(): void
    {
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        // Identical bytes behind two different URLs → second is a checksum duplicate.
        $bytes = $this->png(5, 5);
        $this->downloader->register('https://pharma-shop.tn/one.png', $bytes);
        $this->downloader->register('https://pharma-shop.tn/two.png', $bytes);

        $outcome = $this->persister()->persist($productId, $tenantId, [
            ['url' => 'https://pharma-shop.tn/one.png', 'thumbnail' => null, 'type' => null],
            ['url' => 'https://pharma-shop.tn/two.png', 'thumbnail' => null, 'type' => null],
        ], null);

        self::assertSame(1, $outcome->attached);
        self::assertSame(1, $outcome->skipped);
        self::assertSame(1, MediaAttachment::query()->where('owner_id', $productId)->count());
        // The orphan (duplicate) asset row must be hard-deleted, leaving exactly one.
        self::assertSame(1, MediaAsset::query()->where('tenant_id', $tenantId)->count());
    }

    public function test_gallery_when_ready_primary_already_exists(): void
    {
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        // Pre-seed a READY asset already attached as PRIMARY to this product.
        $existingAsset = MediaAsset::create([
            'tenant_id' => $tenantId,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => "products/{$tenantId}/{$productId}/seed/original.png",
            'mime_type' => 'image/png',
            'checksum' => str_repeat('a', 64),
        ]);
        $existingPrimary = MediaAttachment::create([
            'tenant_id' => $tenantId,
            'media_asset_id' => $existingAsset->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => $productId,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        $this->downloader->register('https://pharma-shop.tn/new.png', $this->png(7, 9));

        $outcome = $this->persister()->persist($productId, $tenantId, [
            ['url' => 'https://pharma-shop.tn/new.png', 'thumbnail' => null, 'type' => null],
        ], null);

        self::assertSame(1, $outcome->attached);

        // The pre-existing PRIMARY must be untouched.
        $existingPrimary->refresh();
        self::assertSame(MediaRole::Primary, $existingPrimary->role);

        // The newly persisted image must be GALLERY (never clobber the existing primary).
        $newAttachment = MediaAttachment::query()
            ->where('owner_id', $productId)
            ->where('media_asset_id', '!=', $existingAsset->id)
            ->first();
        self::assertNotNull($newAttachment);
        self::assertSame(MediaRole::Gallery, $newAttachment->role);

        // Exactly one PRIMARY remains for the product.
        self::assertSame(1, MediaAttachment::query()->where('owner_id', $productId)->where('role', MediaRole::Primary->value)->count());
    }
}

/**
 * In-memory host resolver returning a fixed public IP for every host, so the
 * real RemoteImageFetcher's private-range guard passes without touching DNS.
 */
final class FakeHostResolver implements HostResolverInterface
{
    /** @return array<int, string> */
    public function resolve(string $host): array
    {
        return ['93.184.216.34']; // documentation range — public, not disallowed
    }
}

/**
 * In-memory pinned downloader. Registered URLs materialise their bytes into a
 * temp file and return a FetchedImage; unregistered URLs throw (simulating 404).
 */
final class FakePinnedDownloader implements PinnedImageDownloaderInterface
{
    /** @var array<string, string> */
    private array $registry = [];

    public function register(string $url, string $bytes): void
    {
        $this->registry[$url] = $bytes;
    }

    public function download(string $url, string $host, string $pinnedIp, int $port, int $maxBytes): FetchedImage
    {
        if (! array_key_exists($url, $this->registry)) {
            throw new RemoteImageFetchException("Simulated fetch failure (unregistered): {$url}");
        }

        $bytes = $this->registry[$url];
        $tmp = (string) tempnam(sys_get_temp_dir(), 'enrich-img-');
        file_put_contents($tmp, $bytes);

        $path = (string) parse_url($url, PHP_URL_PATH);
        $filename = basename($path) ?: 'image.png';

        return new FetchedImage($tmp, 'image/png', $filename, strlen($bytes));
    }
}
