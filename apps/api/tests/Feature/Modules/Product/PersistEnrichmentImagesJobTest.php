<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Product;

use App\Jobs\Concerns\BindsTenantContext;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Media\Domain\Contracts\HostResolverInterface;
use App\Modules\Media\Domain\Contracts\PinnedImageDownloaderInterface;
use App\Modules\Media\Domain\Exceptions\RemoteImageFetchException;
use App\Modules\Media\Domain\Media\MediaAttachment;
use App\Modules\Media\Domain\ValueObjects\FetchedImage;
use App\Modules\Product\Application\Jobs\PersistEnrichmentImagesJob;
use App\Modules\Product\Application\Services\EnrichmentImagePersister;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Modules\Catalog\Media\GenerateRenditionsJobTest;
use Tests\TestCase;

final class PersistEnrichmentImagesJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_targets_enrichment_queue(): void
    {
        Queue::fake();
        PersistEnrichmentImagesJob::dispatch((string) Str::uuid(), (string) Str::uuid(), [], null);

        Queue::assertPushed(PersistEnrichmentImagesJob::class, fn (PersistEnrichmentImagesJob $j) => $j->queue === 'enrichment');
    }

    /**
     * R5 (POS cross-layer rule 20): the job must resolve tenancy on its own via
     * {@see BindsTenantContext::withTenantContext()} — it must
     * NOT depend on a CompanyContext bound by request middleware, because a real
     * queue worker never binds one. A real Tenant row is required so
     * `Tenant::find($tenantId)` (inside the trait) can resolve it in the SQLite
     * test environment — mirrors {@see GenerateRenditionsJobTest}.
     *
     * {@see EnrichmentImagePersister}
     * is declared `final`, so neither Mockery nor PHPUnit can generate a
     * subclass mock/spy for it (PHP forbids extending final classes). Instead
     * of a literal spy object, this test uses the REAL persister with its two
     * network-facing collaborators faked (the established pattern from
     * {@see EnrichmentImagePersisterTest}) and
     * asserts the DB side effect the persister only produces when
     * `persist($productId, $tenantId, $images, $userId)` is actually invoked
     * with those exact arguments: a MediaAttachment row scoped to this
     * tenant/product. That side effect is proof-of-call equivalent to a spy
     * assertion, without fighting PHP's final-class mocking limitation.
     */
    public function test_job_runs_without_bound_company_context(): void
    {
        Storage::fake('s3');
        Bus::fake(); // swallow GenerateRenditions dispatched by MediaUploadService

        $resolver = new FakeHostResolverForJobTest;
        $downloader = new FakePinnedDownloaderForJobTest;
        $this->app->instance(HostResolverInterface::class, $resolver);
        $this->app->instance(PinnedImageDownloaderInterface::class, $downloader);

        $tenant = Tenant::create([
            'name' => 'Persist Enrichment Images Tenant',
            'slug' => 'persist-enrichment-images-'.Str::lower(Str::random(6)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $productId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $downloader->register('https://pharma-shop.tn/enrich.png', $this->png(4, 4));
        $images = [['url' => 'https://pharma-shop.tn/enrich.png', 'thumbnail' => null, 'type' => null]];

        // Prove no CompanyContext is bound — a real queue worker never binds one.
        $this->app->make(CompanyContext::class)->clear();
        self::assertFalse($this->app->make(CompanyContext::class)->hasCompany());

        $job = new PersistEnrichmentImagesJob($tenant->id, $productId, $images, $userId);
        $job->handle($this->app->make(EnrichmentImagePersister::class));

        // The job must not leak a bound CompanyContext into subsequent jobs on the worker.
        self::assertFalse($this->app->make(CompanyContext::class)->hasCompany());

        // Proves persist() ran with the job's exact (productId, tenantId, images, userId):
        // this attachment row can only exist if the tenant-scoped MediaAsset get-or-create,
        // fetch, upload and attach pipeline executed for THIS product under THIS tenant.
        self::assertSame(
            1,
            MediaAttachment::query()
                ->where('tenant_id', $tenant->id)
                ->where('owner_id', $productId)
                ->count(),
        );
    }

    /**
     * Generate distinct, valid PNG bytes.
     *
     * @param  int<1, max>  $w
     * @param  int<1, max>  $h
     */
    private function png(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        self::assertNotFalse($img, 'GD could not create the image canvas in test setup');
        $color = imagecolorallocate($img, $w % 255, $h % 255, ($w + $h) % 255);
        self::assertNotFalse($color, 'GD could not allocate the fill color in test setup');
        imagefill($img, 0, 0, $color);
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }
}

/**
 * In-memory host resolver returning a fixed public IP for every host, so the
 * real RemoteImageFetcher's private-range guard passes without touching DNS.
 */
final class FakeHostResolverForJobTest implements HostResolverInterface
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
final class FakePinnedDownloaderForJobTest implements PinnedImageDownloaderInterface
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
        $tmp = (string) tempnam(sys_get_temp_dir(), 'enrich-img-job-');
        file_put_contents($tmp, $bytes);

        $path = (string) parse_url($url, PHP_URL_PATH);
        $filename = basename($path) ?: 'image.png';

        return new FetchedImage($tmp, 'image/png', $filename, strlen($bytes));
    }
}
