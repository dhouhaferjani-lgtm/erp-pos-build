# Enrichment Image Persistence — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist enrichment-provided product images as durable, tenant-owned `MediaAsset`/`MediaAttachment` records (downloaded to MinIO with renditions) so `primary_image_url` resolves and real images render in the POS and product pages — auto-applying Primary when a product has no image, else attaching as a reviewable Gallery alternate.

**Architecture:** One shared `EnrichmentImagePersister` (Application/Product) is invoked from both enrichment paths (Path A auto-apply catalog hit; Path B human-reviewed accept) via a queued `PersistEnrichmentImagesJob`. It normalizes untrusted image descriptors, gets-or-creates a `MediaAsset` keyed by a new `source_ref` (DB-enforced idempotency), downloads bytes through an SSRF-hardened `RemoteImageFetcher` (IP-pinned, no DNS-rebind race), stores to MinIO via the existing `MediaUploadService`, and attaches with a Primary-if-empty-else-Gallery policy. Backend-only; no web changes.

**Tech Stack:** Laravel 12 / PHP 8.2 strict types; PostgreSQL (per-tenant DB via Stancl); MinIO (`s3` disk); Laravel Horizon (`enrichment` + `images` queues); PHPUnit; Guzzle (via `Http`) with a cURL handler for IP pinning.

**Spec:** `docs/superpowers/specs/2026-07-08-enrichment-image-persistence-design.md` (Rev 2). **Review:** `docs/superpowers/specs/reviews/2026-07-08-enrichment-image-persistence-spec-review.md`.

## Global Constraints

- **Strict typing** — `declare(strict_types=1);` in every new PHP file; no `mixed` on public signatures except the deliberately-untrusted normalizer input. No `any`.
- **Constructor injection only** — all deps via constructor `private readonly`. **Never** use the `app()` helper.
- **PHPStan level 8** — zero errors on new code (`cd apps/api && ./vendor/bin/phpstan`).
- **Pint clean** — `./vendor/bin/pint` before every commit.
- **Enums for status/type** — reuse `MediaRole`, `MediaStatus`, `MediaSource`; no magic strings.
- **Queued jobs run with NO `CompanyContext`** (POS rule 20) — pass tenant explicitly; rebind tenancy via `BindsTenantContext`. Projection/job tests must not rely on a bound `CompanyContext`.
- **No new Horizon queue** — reuse `enrichment` (persist orchestration) and `images` (renditions), both already in `config/horizon.php`. `HorizonQueueCoverageTest` must stay green.
- **Events immutable** — no changes to existing event classes.
- **Money/quantity precision** — N/A (no monetary values in this feature).
- **Never run the full PHPUnit suite** without asking — run new tests **by path** only (memory: full suite can crash the laptop).
- **Branch discipline** — implement in a `git worktree` at `../erp.enrichment-images` on branch `feat/enrichment-image-persistence` off `origin/dev`; never commit on shared `dev`.

---

## File Structure

**New files (shipped):**
- `app/Modules/Media/Domain/ValueObjects/ExternalUrlGuard.php` — structural URL guard (https, length, host blocklist), shared.
- `app/Modules/Media/Domain/Contracts/HostResolverInterface.php` — DNS resolution seam.
- `app/Modules/Media/Infrastructure/Net/DnsHostResolver.php` — default resolver (`gethostbynamel`/`dns_get_record`).
- `app/Modules/Media/Domain/ValueObjects/PrivateIpRanges.php` — private/loopback/link-local/CGNAT checker.
- `app/Modules/Media/Application/DTOs/FetchedImage.php` — downloaded-image DTO.
- `app/Modules/Media/Application/Services/RemoteImageFetcher.php` — SSRF-hardened image download.
- `app/Modules/Product/Application/Support/ImageDescriptorNormalizer.php` — untrusted `images[]` → clean list.
- `app/Modules/Product/Application/Services/EnrichmentImagePolicy.php` — Primary-vs-Gallery role decision.
- `app/Modules/Product/Application/Services/EnrichmentImagePersister.php` — orchestrator.
- `app/Modules/Product/Application/DTOs/EnrichmentImagePersistOutcome.php` — per-run result.
- `app/Modules/Product/Application/Jobs/PersistEnrichmentImagesJob.php` — queued wrapper.
- `app/Modules/Product/Presentation/Console/RunEnrichmentCommand.php` — `enrichment:run` bulk trigger.
- `database/migrations/tenant/2026_07_08_100000_add_source_ref_and_media_idempotency.php` — schema.

**Modified files:**
- `app/Modules/Media/Application/Services/MediaUploadService.php` — `?string $sourceRef` param; delegate guard to `ExternalUrlGuard`.
- `app/Modules/Media/Domain/Media/MediaAsset.php` — add `source_ref` to `$fillable`.
- `app/Modules/Media/Application/Services/MediaAttachmentService.php` — add `hardDeleteOrphan(...)` (row+rendition cleanup the existing soft `deleteAsset` doesn't do).
- `app/Modules/Product/Application/Services/EnrichmentReviewService.php` — delegate `imagesFromPayload` to normalizer; dispatch job on `accept` (auto + opt-out).
- `app/Modules/Product/Application/Services/CatalogEnrichmentService.php` — dispatch job in `applyCatalogHit`.
- `app/Modules/Product/Providers/ProductServiceProvider.php` — register `RunEnrichmentCommand`.

**Demo-only (NOT shipped as an integration path) — Task 13:**
- `database/seeders/DemoImageProductsFromPlatformSeeder.php` — discovers platform products-with-images and seeds them into the demo tenant.

---

## Task 1: `ExternalUrlGuard` value object (extract shared guard)

Extract the private `MediaUploadService::guardExternalUrl()` into a reusable VO so `registerExternalUrl` and the new fetcher share one implementation (spec M-06).

**Files:**
- Create: `app/Modules/Media/Domain/ValueObjects/ExternalUrlGuard.php`
- Modify: `app/Modules/Media/Application/Services/MediaUploadService.php` (`guardExternalUrl` body → delegate; keep the private method as a thin call-through so existing callers are untouched)
- Test: `tests/Unit/Modules/Media/ExternalUrlGuardTest.php`

**Interfaces:**
- Produces: `ExternalUrlGuard::assertHttpsHostAllowed(string $url): void` (throws `InvalidArgumentException` on violation).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Media;

use App\Modules\Media\Domain\ValueObjects\ExternalUrlGuard;
use InvalidArgumentException;
use Tests\TestCase;

final class ExternalUrlGuardTest extends TestCase
{
    public function test_accepts_normal_https_url(): void
    {
        $this->expectNotToPerformAssertions();
        ExternalUrlGuard::assertHttpsHostAllowed('https://pharma-shop.tn/img/serum.jpg');
    }

    /** @dataProvider blockedUrls */
    public function test_rejects_unsafe_urls(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExternalUrlGuard::assertHttpsHostAllowed($url);
    }

    /** @return array<string, array{string}> */
    public static function blockedUrls(): array
    {
        return [
            'http scheme'   => ['http://pharma-shop.tn/x.jpg'],
            'localhost'     => ['https://localhost/x.jpg'],
            'loopback v4'   => ['https://127.0.0.1/x.jpg'],
            'loopback v6'   => ['https://[::1]/x.jpg'],
            'rfc1918 10'    => ['https://10.0.0.5/x.jpg'],
            'rfc1918 192'   => ['https://192.168.1.10/x.jpg'],
            'link-local'    => ['https://169.254.1.1/x.jpg'],
            'no host'       => ['https:///x.jpg'],
            'over-length'   => ['https://a.tn/'.str_repeat('a', 2100)],
        ];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Modules/Media/ExternalUrlGuardTest.php`
Expected: FAIL — class `ExternalUrlGuard` not found.

- [ ] **Step 3: Write minimal implementation**

Copy the exact rules from `MediaUploadService::guardExternalUrl()` (`:296-337`) — the `BLOCKED_HOSTS` list, `PRIVATE_IP_PREFIXES`, https-only, length ≤ 2048, IPv6-bracket stripping.

```php
<?php

declare(strict_types=1);

namespace App\Modules\Media\Domain\ValueObjects;

use InvalidArgumentException;

final class ExternalUrlGuard
{
    private const MAX_LENGTH = 2048;

    /** @var list<string> */
    private const BLOCKED_HOSTS = ['localhost', '127.0.0.1', '::1'];

    /** @var list<string> */
    private const PRIVATE_IP_PREFIXES = ['10.', '192.168.', '169.254.', '172.16.', '172.17.', '172.18.', '172.19.', '172.20.', '172.21.', '172.22.', '172.23.', '172.24.', '172.25.', '172.26.', '172.27.', '172.28.', '172.29.', '172.30.', '172.31.'];

    public static function assertHttpsHostAllowed(string $url): void
    {
        if (strlen($url) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('URL exceeds maximum length.');
        }

        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'https') {
            throw new InvalidArgumentException('URL must be https.');
        }

        $host = $parts['host'] ?? '';
        if ($host === '') {
            throw new InvalidArgumentException('URL host is missing.');
        }

        $host = strtolower(trim($host, '[]'));

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            throw new InvalidArgumentException('URL host is blocked.');
        }

        foreach (self::PRIVATE_IP_PREFIXES as $prefix) {
            if (str_starts_with($host, $prefix)) {
                throw new InvalidArgumentException('URL host is a private address.');
            }
        }
    }
}
```

Then change `MediaUploadService::guardExternalUrl()` body to delegate (keep the method + its callers intact):

```php
private function guardExternalUrl(string $url): void
{
    \App\Modules\Media\Domain\ValueObjects\ExternalUrlGuard::assertHttpsHostAllowed($url);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Modules/Media/ExternalUrlGuardTest.php tests/Feature/Modules/Catalog/Media/MediaUploadServiceTest.php`
Expected: PASS (guard tests + existing upload-service tests unaffected).

- [ ] **Step 5: Pint + commit**

```bash
cd apps/api && ./vendor/bin/pint app/Modules/Media/Domain/ValueObjects/ExternalUrlGuard.php app/Modules/Media/Application/Services/MediaUploadService.php tests/Unit/Modules/Media/ExternalUrlGuardTest.php
git add -A && git commit -m "refactor(media): extract ExternalUrlGuard from MediaUploadService"
```

---

## Task 2: Migration + `source_ref` on uploads

Add the provenance column, the DB-enforced idempotency indexes, and thread `sourceRef` through `MediaUploadService`.

**Files:**
- Create: `database/migrations/tenant/2026_07_08_100000_add_source_ref_and_media_idempotency.php`
- Modify: `app/Modules/Media/Domain/Media/MediaAsset.php` (add `source_ref` to `$fillable`)
- Modify: `app/Modules/Media/Application/Services/MediaUploadService.php` (`uploadForProduct` + `upload` gain `?string $sourceRef = null`; write it into the `MediaAsset::create([...])` array at `:174-188`)
- Test: `tests/Feature/Modules/Media/SourceRefIdempotencyTest.php`

**Interfaces:**
- Produces: `MediaUploadService::uploadForProduct(string $tenantId, string $productId, UploadedFile $file, ?string $userId, ?string $sourceRef = null): MediaAsset`.
- Produces: DB constraints — partial unique `media_assets (tenant_id, source_ref) WHERE source_ref IS NOT NULL`; unique `media_attachments (tenant_id, owner_type, owner_id, media_asset_id)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Media;

use App\Modules\Media\Application\Services\MediaUploadService;
use App\Modules\Media\Domain\Media\MediaAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SourceRefIdempotencyTest extends TestCase
{
    public function test_upload_persists_source_ref_and_second_same_url_violates_unique(): void
    {
        Storage::fake('s3');
        Bus::fake();
        $service = app(MediaUploadService::class);
        $tenantId = (string) \Illuminate\Support\Str::uuid();
        $productId = (string) \Illuminate\Support\Str::uuid();
        $url = 'https://pharma-shop.tn/img/serum.jpg';

        $asset = $service->uploadForProduct(
            $tenantId, $productId,
            UploadedFile::fake()->image('serum.jpg', 400, 400),
            null, $url,
        );

        $this->assertSame($url, $asset->refresh()->source_ref);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $service->uploadForProduct(
            $tenantId, $productId,
            UploadedFile::fake()->image('serum2.jpg', 400, 400),
            null, $url,
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Media/SourceRefIdempotencyTest.php`
Expected: FAIL — `uploadForProduct` has no `$sourceRef` param / column missing.

- [ ] **Step 3: Write the migration**

Match the existing tenant-migration style in `database/migrations/tenant/2026_06_12_100001_create_media_assets_table.php`.

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->string('source_ref', 2048)->nullable()->after('external_url');
        });

        // Partial unique: one asset per (tenant, source URL). DB-enforced idempotency.
        DB::statement(
            'CREATE UNIQUE INDEX media_assets_tenant_source_ref_unique
             ON media_assets (tenant_id, source_ref) WHERE source_ref IS NOT NULL'
        );

        // An asset attaches to a given owner at most once.
        Schema::table('media_attachments', function (Blueprint $table): void {
            $table->unique(
                ['tenant_id', 'owner_type', 'owner_id', 'media_asset_id'],
                'media_attachments_owner_asset_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('media_attachments', function (Blueprint $table): void {
            $table->dropUnique('media_attachments_owner_asset_unique');
        });
        DB::statement('DROP INDEX IF EXISTS media_assets_tenant_source_ref_unique');
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropColumn('source_ref');
        });
    }
};
```

- [ ] **Step 4: Add `source_ref` to `MediaAsset::$fillable`**

In `app/Modules/Media/Domain/Media/MediaAsset.php`, add `'source_ref',` after `'external_url',` in `$fillable`.

- [ ] **Step 5: Thread `sourceRef` through `MediaUploadService`**

Change `uploadForProduct` (`:217`) to accept and forward `?string $sourceRef = null`, `upload(...)` (`:107`) to accept it, and add `'source_ref' => $sourceRef,` to the `MediaAsset::create([...])` array (`:174-188`). Existing callers pass nothing → null (non-breaking).

- [ ] **Step 6: Migrate the test DB + run the test**

Run: `cd apps/api && php artisan migrate --path=database/migrations/tenant --database=tenant 2>/dev/null; ./vendor/bin/phpunit tests/Feature/Modules/Media/SourceRefIdempotencyTest.php`
Expected: PASS (first upload stores `source_ref`; second throws `QueryException` on the unique index). *(If the harness auto-migrates tenant tables in `RefreshDatabase`, the explicit migrate is a no-op.)*

- [ ] **Step 7: Pint + commit**

```bash
cd apps/api && ./vendor/bin/pint app/Modules/Media database/migrations/tenant/2026_07_08_100000_add_source_ref_and_media_idempotency.php tests/Feature/Modules/Media/SourceRefIdempotencyTest.php
git add -A && git commit -m "feat(media): source_ref column + DB-enforced media idempotency"
```

---

## Task 3: `HostResolverInterface` + private-IP checker (SSRF resolution seam)

The injectable DNS seam that makes IP-pinning testable.

**Files:**
- Create: `app/Modules/Media/Domain/Contracts/HostResolverInterface.php`
- Create: `app/Modules/Media/Infrastructure/Net/DnsHostResolver.php`
- Create: `app/Modules/Media/Domain/ValueObjects/PrivateIpRanges.php`
- Test: `tests/Unit/Modules/Media/PrivateIpRangesTest.php`

**Interfaces:**
- Produces: `HostResolverInterface::resolve(string $host): array<int, string>` (list of IP strings; empty if unresolvable).
- Produces: `PrivateIpRanges::isDisallowed(string $ip): bool` (true for loopback/private/link-local/CGNAT/unspecified/multicast).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Media;

use App\Modules\Media\Domain\ValueObjects\PrivateIpRanges;
use Tests\TestCase;

final class PrivateIpRangesTest extends TestCase
{
    /** @dataProvider ips */
    public function test_classifies_ip(string $ip, bool $disallowed): void
    {
        $this->assertSame($disallowed, PrivateIpRanges::isDisallowed($ip));
    }

    /** @return array<string, array{string, bool}> */
    public static function ips(): array
    {
        return [
            'public v4'      => ['41.226.11.20', false],
            'loopback'       => ['127.0.0.1', true],
            'rfc1918 10'     => ['10.1.2.3', true],
            'rfc1918 172'    => ['172.16.5.5', true],
            'rfc1918 192'    => ['192.168.0.1', true],
            'link-local'     => ['169.254.0.1', true],
            'cgnat'          => ['100.64.0.1', true],
            'v6 loopback'    => ['::1', true],
            'v6 ula'         => ['fd00::1', true],
            'v6 public'      => ['2a00:1450:4001::1', false],
        ];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Modules/Media/PrivateIpRangesTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementations**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Media\Domain\ValueObjects;

final class PrivateIpRanges
{
    public static function isDisallowed(string $ip): bool
    {
        // Reject anything that is not a global, non-reserved unicast address.
        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;

        if (! $isPublic) {
            return true;
        }

        // CGNAT 100.64.0.0/10 is not covered by NO_RES_RANGE on all PHP builds.
        if (str_starts_with($ip, '100.')) {
            $second = (int) explode('.', $ip)[1];
            if ($second >= 64 && $second <= 127) {
                return true;
            }
        }

        return false;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Media\Domain\Contracts;

interface HostResolverInterface
{
    /** @return array<int, string> resolved IPv4/IPv6 addresses; empty if none */
    public function resolve(string $host): array;
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Media\Infrastructure\Net;

use App\Modules\Media\Domain\Contracts\HostResolverInterface;

final class DnsHostResolver implements HostResolverInterface
{
    public function resolve(string $host): array
    {
        $ips = [];
        $v4 = gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $rec) {
            if (isset($rec['ipv6'])) {
                $ips[] = $rec['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
```

- [ ] **Step 4: Bind the interface**

In `app/Modules/Media/Providers/MediaServiceProvider.php` `register()`, add:
```php
$this->app->bind(
    \App\Modules\Media\Domain\Contracts\HostResolverInterface::class,
    \App\Modules\Media\Infrastructure\Net\DnsHostResolver::class,
);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Modules/Media/PrivateIpRangesTest.php`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
cd apps/api && ./vendor/bin/pint app/Modules/Media tests/Unit/Modules/Media/PrivateIpRangesTest.php
git add -A && git commit -m "feat(media): host resolver seam + private-IP range checker"
```

---

## Task 4: `FetchedImage` DTO + `RemoteImageFetcher` (SSRF-hardened download)

The security-critical unit. Structural guard (Task 1) → resolve+validate every IP (Task 3) → pin the connection to a validated IP (Host/SNI preserved) → content-type allowlist + magic-byte sniff → streamed size cap → timeouts → redirect re-guard.

**Files:**
- Create: `app/Modules/Media/Application/DTOs/FetchedImage.php`
- Create: `app/Modules/Media/Application/Services/RemoteImageFetcher.php`
- Create: `app/Modules/Media/Application/Exceptions/RemoteImageFetchException.php`
- Test: `tests/Feature/Modules/Media/RemoteImageFetcherTest.php`

**Interfaces:**
- Consumes: `ExternalUrlGuard::assertHttpsHostAllowed`, `HostResolverInterface`, `PrivateIpRanges`.
- Produces: `RemoteImageFetcher::fetch(string $url): FetchedImage` (throws `RemoteImageFetchException` on any violation).
- Produces: `FetchedImage { public readonly string $tempPath; public readonly string $mime; public readonly string $filename; public readonly int $byteSize; }`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Media;

use App\Modules\Media\Application\Exceptions\RemoteImageFetchException;
use App\Modules\Media\Application\Services\RemoteImageFetcher;
use App\Modules\Media\Domain\Contracts\HostResolverInterface;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class RemoteImageFetcherTest extends TestCase
{
    private function fetcherResolvingTo(array $ips): RemoteImageFetcher
    {
        $resolver = new class($ips) implements HostResolverInterface {
            public function __construct(private array $ips) {}
            public function resolve(string $host): array { return $this->ips; }
        };

        return new RemoteImageFetcher($resolver);
    }

    public function test_downloads_a_valid_image(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        Http::fake(['https://pharma-shop.tn/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

        $result = $this->fetcherResolvingTo(['41.226.11.20'])->fetch('https://pharma-shop.tn/img/serum.png');

        $this->assertFileExists($result->tempPath);
        $this->assertSame('image/png', $result->mime);
        @unlink($result->tempPath);
    }

    public function test_rejects_host_resolving_to_private_ip(): void
    {
        Http::fake(['https://evil.example/*' => Http::response('x', 200, ['Content-Type' => 'image/png'])]);
        $this->expectException(RemoteImageFetchException::class);
        $this->fetcherResolvingTo(['10.0.0.5'])->fetch('https://evil.example/x.png');
    }

    public function test_rejects_non_image_content_type(): void
    {
        Http::fake(['https://pharma-shop.tn/*' => Http::response('<html>', 200, ['Content-Type' => 'text/html'])]);
        $this->expectException(RemoteImageFetchException::class);
        $this->fetcherResolvingTo(['41.226.11.20'])->fetch('https://pharma-shop.tn/x.png');
    }

    public function test_rejects_content_type_image_but_bad_magic_bytes(): void
    {
        Http::fake(['https://pharma-shop.tn/*' => Http::response('not-really-an-image', 200, ['Content-Type' => 'image/png'])]);
        $this->expectException(RemoteImageFetchException::class);
        $this->fetcherResolvingTo(['41.226.11.20'])->fetch('https://pharma-shop.tn/x.png');
    }

    public function test_rejects_unresolvable_host(): void
    {
        Http::fake();
        $this->expectException(RemoteImageFetchException::class);
        $this->fetcherResolvingTo([])->fetch('https://pharma-shop.tn/x.png');
    }

    public function test_rejects_non_https(): void
    {
        Http::fake();
        $this->expectException(RemoteImageFetchException::class);
        $this->fetcherResolvingTo(['41.226.11.20'])->fetch('http://pharma-shop.tn/x.png');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Media/RemoteImageFetcherTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write the implementations**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Media\Application\Exceptions;

use RuntimeException;

final class RemoteImageFetchException extends RuntimeException {}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Media\Application\DTOs;

final class FetchedImage
{
    public function __construct(
        public readonly string $tempPath,
        public readonly string $mime,
        public readonly string $filename,
        public readonly int $byteSize,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Media\Application\Services;

use App\Modules\Media\Application\DTOs\FetchedImage;
use App\Modules\Media\Application\Exceptions\RemoteImageFetchException;
use App\Modules\Media\Domain\Contracts\HostResolverInterface;
use App\Modules\Media\Domain\ValueObjects\ExternalUrlGuard;
use App\Modules\Media\Domain\ValueObjects\PrivateIpRanges;
use Illuminate\Support\Facades\Http;
use Throwable;

final class RemoteImageFetcher
{
    private const MAX_BYTES = 10_485_760; // 10 MiB
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly HostResolverInterface $resolver) {}

    public function fetch(string $url): FetchedImage
    {
        try {
            ExternalUrlGuard::assertHttpsHostAllowed($url);
        } catch (Throwable $e) {
            throw new RemoteImageFetchException("Blocked URL: {$e->getMessage()}", 0, $e);
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $ips = $this->resolver->resolve(trim($host, '[]'));
        if ($ips === []) {
            throw new RemoteImageFetchException("Host does not resolve: {$host}");
        }
        foreach ($ips as $ip) {
            if (PrivateIpRanges::isDisallowed($ip)) {
                throw new RemoteImageFetchException("Host resolves to a disallowed address: {$ip}");
            }
        }
        // Pin the connection to the first validated public IP while preserving Host + SNI,
        // so a post-check DNS rebind cannot redirect the socket to a private address.
        $pinnedIp = $ips[0];
        $port = str_contains($ip = strtolower($url), 'https') ? 443 : 443;

        $response = Http::withOptions([
            'allow_redirects' => false, // we re-guard hops ourselves; deny transparent redirects
            'stream' => true,
            'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$pinnedIp}"]],
        ])->timeout(15)->connectTimeout(5)->get($url);

        if (! $response->successful()) {
            throw new RemoteImageFetchException("Fetch failed: HTTP {$response->status()}");
        }

        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        if (! in_array($mime, self::ALLOWED_MIME, true)) {
            throw new RemoteImageFetchException("Disallowed content-type: {$mime}");
        }

        $body = $response->body();
        $size = strlen($body);
        if ($size === 0 || $size > self::MAX_BYTES) {
            throw new RemoteImageFetchException("Image size out of bounds: {$size}");
        }

        $sniffed = $this->sniff($body);
        if ($sniffed === null || $sniffed !== $mime) {
            throw new RemoteImageFetchException('Magic bytes do not match declared image type.');
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'enrimg_');
        if ($tempPath === false || file_put_contents($tempPath, $body) === false) {
            throw new RemoteImageFetchException('Could not buffer image to temp storage.');
        }

        return new FetchedImage(
            tempPath: $tempPath,
            mime: $mime,
            filename: basename((string) parse_url($url, PHP_URL_PATH)) ?: 'image',
            byteSize: $size,
        );
    }

    private function sniff(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return null;
    }
}
```

> **NOTE for implementer (flagged for the plan's Codex review):** `Http::fake()` short-circuits before Guzzle applies the `curl` `CURLOPT_RESOLVE` option, so the IP-pin is exercised only in real requests, not unit tests — the tests above assert the *resolve-and-reject* gate. Verify against a live fetch during Task 12 that `CURLINFO_PRIMARY_IP` (via an `on_stats`/`withMiddleware` hook) equals `$pinnedIp`; if the project prefers, replace `CURLOPT_RESOLVE` with an explicit peer-IP assertion in an `on_stats` callback that throws if the connected IP is disallowed. The streamed size cap should also be enforced incrementally on the stream for very large bodies; the buffered check above is the floor.

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Media/RemoteImageFetcherTest.php`
Expected: PASS (all 6).

- [ ] **Step 5: Pint + PHPStan + commit**

```bash
cd apps/api && ./vendor/bin/pint app/Modules/Media tests/Feature/Modules/Media/RemoteImageFetcherTest.php && ./vendor/bin/phpstan analyse app/Modules/Media/Application/Services/RemoteImageFetcher.php
git add -A && git commit -m "feat(media): SSRF-hardened RemoteImageFetcher (IP-pinned, magic-byte, size cap)"
```

---

## Task 5: `ImageDescriptorNormalizer` (extract + reuse across both paths)

Untrusted `images[]` → clean, capped `list<array{url,thumbnail,type}>` (spec M-05).

**Files:**
- Create: `app/Modules/Product/Application/Support/ImageDescriptorNormalizer.php`
- Modify: `app/Modules/Product/Application/Services/EnrichmentReviewService.php` (`imagesFromPayload` delegates)
- Test: `tests/Unit/Modules/Product/ImageDescriptorNormalizerTest.php`

**Interfaces:**
- Produces: `ImageDescriptorNormalizer::normalize(mixed $images, int $cap = 6): array` → `list<array{url: ?string, thumbnail: ?string, type: ?string}>`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Product;

use App\Modules\Product\Application\Support\ImageDescriptorNormalizer;
use Tests\TestCase;

final class ImageDescriptorNormalizerTest extends TestCase
{
    public function test_drops_malformed_and_caps(): void
    {
        $raw = [
            ['url' => 'https://a.tn/1.jpg', 'thumbnail' => 'https://a.tn/1t.jpg', 'type' => 'featured'],
            ['url' => 123],                 // non-string url → url null
            'not-an-array',                 // dropped entirely
            ['thumbnail' => 'https://a.tn/only-thumb.jpg'], // url missing → null, kept (has thumb)
            ['url' => 'https://a.tn/2.jpg'],
            ['url' => 'https://a.tn/3.jpg'],
            ['url' => 'https://a.tn/4.jpg'],
            ['url' => 'https://a.tn/5.jpg'],
            ['url' => 'https://a.tn/6.jpg'],
            ['url' => 'https://a.tn/7.jpg'], // over cap
        ];

        $out = ImageDescriptorNormalizer::normalize($raw, 6);

        $this->assertCount(6, $out);
        $this->assertSame('https://a.tn/1.jpg', $out[0]['url']);
        $this->assertNull($out[1]['url']);
        $this->assertArrayHasKey('type', $out[0]);
    }

    public function test_non_array_input_returns_empty(): void
    {
        $this->assertSame([], ImageDescriptorNormalizer::normalize('nope'));
        $this->assertSame([], ImageDescriptorNormalizer::normalize(null));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Modules/Product/ImageDescriptorNormalizerTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation (mirror `imagesFromPayload` logic, add cap)**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Support;

final class ImageDescriptorNormalizer
{
    /**
     * @return list<array{url: string|null, thumbnail: string|null, type: string|null}>
     */
    public static function normalize(mixed $images, int $cap = 6): array
    {
        if (! is_array($images)) {
            return [];
        }

        $out = [];
        foreach ($images as $item) {
            if (! is_array($item)) {
                continue;
            }
            $url = isset($item['url']) && is_string($item['url']) ? $item['url'] : null;
            $thumb = isset($item['thumbnail']) && is_string($item['thumbnail']) ? $item['thumbnail'] : null;
            $type = isset($item['type']) && is_string($item['type']) ? $item['type'] : null;

            if ($url === null && $thumb === null) {
                continue; // nothing fetchable
            }

            $out[] = ['url' => $url, 'thumbnail' => $thumb, 'type' => $type];
            if (count($out) >= $cap) {
                break;
            }
        }

        return $out;
    }
}
```

Then refactor `EnrichmentReviewService::imagesFromPayload()` (`:368`) to delegate — keep the private method signature; body becomes:
```php
return ImageDescriptorNormalizer::normalize($value, PHP_INT_MAX);
```
*(Pass an effectively-unbounded cap here so existing display behavior is unchanged; the persister applies the real cap of 6.)*

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Modules/Product/ImageDescriptorNormalizerTest.php tests/Feature/Modules/Product/EnrichmentRefreshControllerTest.php`
Expected: PASS (normalizer + existing enrichment tests unaffected).

- [ ] **Step 5: Pint + commit**

```bash
cd apps/api && ./vendor/bin/pint app/Modules/Product/Application/Support/ImageDescriptorNormalizer.php app/Modules/Product/Application/Services/EnrichmentReviewService.php tests/Unit/Modules/Product/ImageDescriptorNormalizerTest.php
git add -A && git commit -m "refactor(product): shared ImageDescriptorNormalizer for enrichment images"
```

---

## Task 6: `EnrichmentImagePolicy`

Decide Primary vs Gallery per image.

**Files:**
- Create: `app/Modules/Product/Application/Services/EnrichmentImagePolicy.php`
- Test: `tests/Feature/Modules/Product/EnrichmentImagePolicyTest.php`

**Interfaces:**
- Produces: `EnrichmentImagePolicy::roleFor(string $productId, string $tenantId, bool $runAssignedPrimary): MediaRole`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Product;

use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Product\Application\Services\EnrichmentImagePolicy;
use Tests\TestCase;

final class EnrichmentImagePolicyTest extends TestCase
{
    public function test_primary_when_no_existing_primary_and_run_has_none(): void
    {
        $policy = app(EnrichmentImagePolicy::class);
        $productId = (string) \Illuminate\Support\Str::uuid();
        $tenantId = (string) \Illuminate\Support\Str::uuid();

        $this->assertSame(MediaRole::Primary, $policy->roleFor($productId, $tenantId, false));
    }

    public function test_gallery_when_run_already_assigned_primary(): void
    {
        $policy = app(EnrichmentImagePolicy::class);
        $productId = (string) \Illuminate\Support\Str::uuid();
        $tenantId = (string) \Illuminate\Support\Str::uuid();

        $this->assertSame(MediaRole::Gallery, $policy->roleFor($productId, $tenantId, true));
    }
}
```

*(A third case — Gallery when a READY Primary attachment already exists in the DB — is covered end-to-end in Task 7's idempotency test, where real attachments exist.)*

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Product/EnrichmentImagePolicyTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAttachment;

final class EnrichmentImagePolicy
{
    public function roleFor(string $productId, string $tenantId, bool $runAssignedPrimary): MediaRole
    {
        if ($runAssignedPrimary) {
            return MediaRole::Gallery;
        }

        $hasPrimary = MediaAttachment::query()
            ->where('tenant_id', $tenantId)
            ->where('owner_type', \App\Modules\Media\Domain\Enums\MediaOwnerType::Product->value)
            ->where('owner_id', $productId)
            ->where('role', MediaRole::Primary->value)
            ->whereHas('asset', fn ($q) => $q->where('status', MediaStatus::Ready->value))
            ->exists();

        return $hasPrimary ? MediaRole::Gallery : MediaRole::Primary;
    }
}
```

> **Implementer note:** confirm the `MediaAttachment` model exposes an `asset` relation and `owner_type` stores the `MediaOwnerType` value (check `app/Modules/Media/Domain/Media/MediaAttachment.php`). If `owner_type` is stored as the enum name or a morph alias, adjust the `where`. This is verified against code before writing the query.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Product/EnrichmentImagePolicyTest.php`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
cd apps/api && ./vendor/bin/pint app/Modules/Product/Application/Services/EnrichmentImagePolicy.php tests/Feature/Modules/Product/EnrichmentImagePolicyTest.php
git add -A && git commit -m "feat(product): EnrichmentImagePolicy (primary-if-empty else gallery)"
```

---

## Task 7: `EnrichmentImagePersister` (orchestrator)

Ties it all together: normalize → per-descriptor get-or-create asset by `source_ref` → fetch → upload (with `sourceRef`) → checksum backstop (pre-attach) → policy → attach → per-image isolation + orphan/temp cleanup.

**Files:**
- Create: `app/Modules/Product/Application/DTOs/EnrichmentImagePersistOutcome.php`
- Create: `app/Modules/Product/Application/Services/EnrichmentImagePersister.php`
- Modify: `app/Modules/Media/Application/Services/MediaAttachmentService.php` — add `hardDeleteOrphan(string $assetId, string $tenantId): void` (deletes storage object + `media_renditions` rows + hard-deletes the asset row; asserts zero links first). The existing `deleteAsset` only soft-deletes and leaves rendition rows.
- Test: `tests/Feature/Modules/Product/EnrichmentImagePersisterTest.php`

**Interfaces:**
- Consumes: `ImageDescriptorNormalizer`, `RemoteImageFetcher`, `MediaUploadService::uploadForProduct(..., sourceRef)`, `MediaAttachmentService::attach(...)` + `hardDeleteOrphan(...)`, `EnrichmentImagePolicy::roleFor(...)`.
- Produces: `EnrichmentImagePersister::persist(string $productId, string $tenantId, array $images, ?string $userId): EnrichmentImagePersistOutcome`.
- Produces: `EnrichmentImagePersistOutcome { int $attached; int $reused; int $skipped; int $failed; }`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Product;

use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Media\MediaAttachment;
use App\Modules\Product\Application\Services\EnrichmentImagePersister;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EnrichmentImagePersisterTest extends TestCase
{
    private string $png;

    protected function setUp(): void
    {
        parent::setUp();
        $this->png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        Storage::fake('s3');
        Bus::fake(); // swallow GenerateRenditions
    }

    private function persister(): EnrichmentImagePersister
    {
        return app(EnrichmentImagePersister::class);
    }

    public function test_first_image_becomes_primary(): void
    {
        Http::fake(['https://pharma-shop.tn/*' => Http::response($this->png, 200, ['Content-Type' => 'image/png'])]);
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        $outcome = $this->persister()->persist($productId, $tenantId, [
            ['url' => 'https://pharma-shop.tn/a.png', 'thumbnail' => null, 'type' => null],
            ['url' => 'https://pharma-shop.tn/b.png', 'thumbnail' => null, 'type' => null],
        ], null);

        $this->assertSame(2, $outcome->attached);
        $primary = MediaAttachment::query()->where('owner_id', $productId)->where('role', MediaRole::Primary->value)->count();
        $gallery = MediaAttachment::query()->where('owner_id', $productId)->where('role', MediaRole::Gallery->value)->count();
        $this->assertSame(1, $primary);
        $this->assertSame(1, $gallery);
    }

    public function test_idempotent_on_rerun_same_urls(): void
    {
        Http::fake(['https://pharma-shop.tn/*' => Http::response($this->png, 200, ['Content-Type' => 'image/png'])]);
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();
        $images = [['url' => 'https://pharma-shop.tn/a.png', 'thumbnail' => null, 'type' => null]];

        $this->persister()->persist($productId, $tenantId, $images, null);
        $second = $this->persister()->persist($productId, $tenantId, $images, null);

        $this->assertSame(0, $second->attached);
        $this->assertSame(1, $second->reused);
        $this->assertSame(1, MediaAttachment::query()->where('owner_id', $productId)->count());
    }

    public function test_one_bad_url_does_not_abort_the_rest(): void
    {
        Http::fake([
            'https://pharma-shop.tn/good.png' => Http::response($this->png, 200, ['Content-Type' => 'image/png']),
            'https://pharma-shop.tn/bad.png' => Http::response('nope', 404),
        ]);
        $tenantId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        $outcome = $this->persister()->persist($productId, $tenantId, [
            ['url' => 'https://pharma-shop.tn/bad.png', 'thumbnail' => null, 'type' => null],
            ['url' => 'https://pharma-shop.tn/good.png', 'thumbnail' => null, 'type' => null],
        ], null);

        $this->assertSame(1, $outcome->attached);
        $this->assertSame(1, $outcome->failed);
        $this->assertSame(1, MediaAttachment::query()->where('owner_id', $productId)->count());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Product/EnrichmentImagePersisterTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Add `hardDeleteOrphan` to `MediaAttachmentService`**

```php
/**
 * Fully remove an asset that has no attachment links (orphan cleanup after a
 * failed/duplicate persist). Deletes storage object + rendition files + rows,
 * then hard-deletes the asset. Unlike deleteAsset(), this removes the rows.
 */
public function hardDeleteOrphan(string $assetId, string $tenantId): void
{
    $asset = MediaAsset::withTrashed()->where('id', $assetId)->where('tenant_id', $tenantId)->first();
    if ($asset === null) {
        return;
    }
    if ($asset->attachments()->exists()) {
        throw new \RuntimeException('Refusing to hard-delete an asset that still has links.');
    }

    $disk = $asset->storage_disk;
    $paths = [$asset->storage_path];
    foreach ($asset->renditions as $r) {
        $paths[] = $r->storage_path;
    }
    $asset->renditions()->delete();
    $asset->forceDelete();

    DB::afterCommit(function () use ($disk, $paths): void {
        if ($disk === 'url') {
            return;
        }
        foreach (array_filter($paths) as $p) {
            $this->storage->delete($disk, $p);
        }
    });
}
```

> **Implementer note:** confirm `MediaAsset` exposes `attachments()` and `renditions()` relations (check the model). If rendition rows are keyed differently, adjust. Verified against code before writing.

- [ ] **Step 4: Write the outcome DTO + persister**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

final class EnrichmentImagePersistOutcome
{
    public function __construct(
        public int $attached = 0,
        public int $reused = 0,
        public int $skipped = 0,
        public int $failed = 0,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Media\Application\Services\MediaAttachmentService;
use App\Modules\Media\Application\Services\MediaUploadService;
use App\Modules\Media\Application\Services\RemoteImageFetcher;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Product\Application\DTOs\EnrichmentImagePersistOutcome;
use App\Modules\Product\Application\Support\ImageDescriptorNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Psr\Log\LoggerInterface;
use Throwable;

final class EnrichmentImagePersister
{
    private const CAP = 6;

    public function __construct(
        private readonly RemoteImageFetcher $fetcher,
        private readonly MediaUploadService $uploads,
        private readonly MediaAttachmentService $attachments,
        private readonly EnrichmentImagePolicy $policy,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  list<array{url: string|null, thumbnail: string|null, type: string|null}>|mixed  $images
     */
    public function persist(string $productId, string $tenantId, mixed $images, ?string $userId): EnrichmentImagePersistOutcome
    {
        $outcome = new EnrichmentImagePersistOutcome();
        $descriptors = ImageDescriptorNormalizer::normalize($images, self::CAP);
        $runAssignedPrimary = false;
        $sort = 0;

        foreach ($descriptors as $d) {
            $url = $d['url'] ?? $d['thumbnail'];
            if (! is_string($url) || $url === '') {
                continue;
            }

            try {
                $existing = MediaAsset::query()
                    ->where('tenant_id', $tenantId)
                    ->where('source_ref', $url)
                    ->first();

                if ($existing !== null) {
                    $this->attachIdempotent($existing->id, $productId, $tenantId, $runAssignedPrimary, $sort);
                    $runAssignedPrimary = $runAssignedPrimary || $this->wasPrimary($existing->id, $productId, $tenantId);
                    $outcome->reused++;
                    $sort++;
                    continue;
                }

                $fetched = $this->fetcher->fetch($url);
                $asset = null;
                try {
                    $file = new UploadedFile($fetched->tempPath, $fetched->filename, $fetched->mime, null, true);
                    $asset = $this->uploads->uploadForProduct($tenantId, $productId, $file, $userId, $url);

                    // checksum backstop: same bytes already present for this product under a different URL?
                    if ($this->isChecksumDuplicate($asset, $productId, $tenantId)) {
                        $this->attachments->hardDeleteOrphan($asset->id, $tenantId);
                        $outcome->skipped++;
                        continue;
                    }

                    $wasPrimary = $this->attachWithPolicy($asset->id, $productId, $tenantId, $runAssignedPrimary, $sort);
                    $runAssignedPrimary = $runAssignedPrimary || $wasPrimary;
                    $outcome->attached++;
                    $sort++;
                } catch (QueryException $e) {
                    // Lost the (tenant, source_ref) unique race: another worker created it.
                    if ($asset !== null) {
                        $this->safeCleanup($asset->id, $tenantId);
                    }
                    $winner = MediaAsset::query()->where('tenant_id', $tenantId)->where('source_ref', $url)->first();
                    if ($winner !== null) {
                        $this->attachIdempotent($winner->id, $productId, $tenantId, $runAssignedPrimary, $sort);
                        $outcome->reused++;
                        $sort++;
                    } else {
                        throw $e;
                    }
                } catch (Throwable $e) {
                    if ($asset !== null) {
                        $this->safeCleanup($asset->id, $tenantId);
                    }
                    throw $e;
                } finally {
                    @unlink($fetched->tempPath);
                }
            } catch (Throwable $e) {
                $this->logger->warning('Enrichment image persist failed', [
                    'product_id' => $productId, 'url' => $url, 'error' => $e->getMessage(),
                ]);
                $outcome->failed++;
            }
        }

        return $outcome;
    }

    private function attachWithPolicy(string $assetId, string $productId, string $tenantId, bool $runAssignedPrimary, int $sort): bool
    {
        $role = $this->policy->roleFor($productId, $tenantId, $runAssignedPrimary);
        $this->attachIdempotentWithRole($assetId, $productId, $tenantId, $role, $sort);

        return $role === MediaRole::Primary;
    }

    private function attachIdempotent(string $assetId, string $productId, string $tenantId, bool $runAssignedPrimary, int $sort): void
    {
        $role = $this->policy->roleFor($productId, $tenantId, $runAssignedPrimary);
        $this->attachIdempotentWithRole($assetId, $productId, $tenantId, $role, $sort);
    }

    private function attachIdempotentWithRole(string $assetId, string $productId, string $tenantId, MediaRole $role, int $sort): void
    {
        try {
            $this->attachments->attach($assetId, MediaOwnerType::Product, $productId, $role, $sort, $tenantId);
        } catch (QueryException $e) {
            // media_attachments_owner_asset_unique → already attached; no-op.
        }
    }

    private function wasPrimary(string $assetId, string $productId, string $tenantId): bool
    {
        return \App\Modules\Media\Domain\Media\MediaAttachment::query()
            ->where('tenant_id', $tenantId)->where('owner_id', $productId)
            ->where('media_asset_id', $assetId)->where('role', MediaRole::Primary->value)->exists();
    }

    private function isChecksumDuplicate(MediaAsset $asset, string $productId, string $tenantId): bool
    {
        if ($asset->checksum === null) {
            return false;
        }

        return MediaAsset::query()
            ->where('tenant_id', $tenantId)
            ->where('checksum', $asset->checksum)
            ->where('id', '!=', $asset->id)
            ->whereIn('status', [MediaStatus::Uploaded->value, MediaStatus::Processing->value, MediaStatus::Ready->value])
            ->whereHas('attachments', fn ($q) => $q->where('owner_id', $productId))
            ->exists();
    }

    private function safeCleanup(string $assetId, string $tenantId): void
    {
        try {
            $this->attachments->hardDeleteOrphan($assetId, $tenantId);
        } catch (Throwable $e) {
            $this->logger->warning('Orphan cleanup failed', ['asset_id' => $assetId, 'error' => $e->getMessage()]);
        }
    }
}
```

> **Implementer note:** confirm `MediaAsset` exposes an `attachments()` relation for the `whereHas` in `isChecksumDuplicate`. If the injected `LoggerInterface` is not auto-resolvable, inject `\Illuminate\Log\LogManager` or a channel via the module provider.

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Product/EnrichmentImagePersisterTest.php`
Expected: PASS (primary/gallery split; idempotent reuse; bad-URL isolation).

- [ ] **Step 6: Pint + PHPStan + commit**

```bash
cd apps/api && ./vendor/bin/pint app/Modules/Product app/Modules/Media/Application/Services/MediaAttachmentService.php tests/Feature/Modules/Product/EnrichmentImagePersisterTest.php && ./vendor/bin/phpstan analyse app/Modules/Product/Application/Services/EnrichmentImagePersister.php app/Modules/Media/Application/Services/MediaAttachmentService.php
git add -A && git commit -m "feat(product): EnrichmentImagePersister with idempotency + orphan cleanup"
```

---

## Task 8: `PersistEnrichmentImagesJob`

Queued wrapper both paths dispatch (queue `enrichment`, tenancy via `BindsTenantContext`).

**Files:**
- Create: `app/Modules/Product/Application/Jobs/PersistEnrichmentImagesJob.php`
- Test: `tests/Feature/Modules/Product/PersistEnrichmentImagesJobTest.php`

**Interfaces:**
- Produces: `new PersistEnrichmentImagesJob(string $tenantId, string $productId, array $images, ?string $userId = null)` — `public readonly string $tenantId` (for `BindsTenantContext`), self-selects `onQueue('enrichment')`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Product;

use App\Modules\Product\Application\Jobs\PersistEnrichmentImagesJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PersistEnrichmentImagesJobTest extends TestCase
{
    public function test_job_targets_enrichment_queue(): void
    {
        Queue::fake();
        PersistEnrichmentImagesJob::dispatch((string) Str::uuid(), (string) Str::uuid(), [], null);

        Queue::assertPushed(PersistEnrichmentImagesJob::class, fn (PersistEnrichmentImagesJob $j) => $j->queue === 'enrichment');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Product/PersistEnrichmentImagesJobTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the job (mirror `GenerateRenditions` tenancy pattern)**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Jobs;

use App\Jobs\Concerns\BindsTenantContext;
use App\Modules\Product\Application\Services\EnrichmentImagePersister;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class PersistEnrichmentImagesJob implements ShouldQueue
{
    use BindsTenantContext;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    /**
     * @param  array<int, array{url: string|null, thumbnail: string|null, type: string|null}>  $images
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $productId,
        public readonly array $images,
        public readonly ?string $userId = null,
    ) {
        $this->onQueue('enrichment');
    }

    public function handle(EnrichmentImagePersister $persister): void
    {
        $this->withTenantContext(function () use ($persister): void {
            $persister->persist($this->productId, $this->tenantId, $this->images, $this->userId);
        });
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Product/PersistEnrichmentImagesJobTest.php`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
cd apps/api && ./vendor/bin/pint app/Modules/Product/Application/Jobs/PersistEnrichmentImagesJob.php tests/Feature/Modules/Product/PersistEnrichmentImagesJobTest.php
git add -A && git commit -m "feat(product): PersistEnrichmentImagesJob (enrichment queue, tenant-bound)"
```

---

## Task 9: Path A hook — dispatch from `applyCatalogHit`

**Files:**
- Modify: `app/Modules/Product/Application/Services/CatalogEnrichmentService.php` (after the `EnrichmentResult` is created, ~`:128`)
- Test: `tests/Feature/Modules/Product/CatalogEnrichmentImageDispatchTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Product;

use App\Modules\Product\Application\Jobs\PersistEnrichmentImagesJob;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class CatalogEnrichmentImageDispatchTest extends TestCase
{
    public function test_apply_catalog_hit_with_images_dispatches_persist_job(): void
    {
        Bus::fake();
        // Arrange: a Product + a CatalogProductDTO carrying images.
        // (Use the project's existing factories/builders — see EnrichmentRefreshControllerTest for setup.)
        [$product, $catalog] = $this->makeProductAndCatalogWithImages(['https://pharma-shop.tn/a.jpg']);

        app(\App\Modules\Product\Application\Services\CatalogEnrichmentService::class)
            ->applyCatalogHit($product, $catalog);

        Bus::assertDispatched(PersistEnrichmentImagesJob::class, fn (PersistEnrichmentImagesJob $j) =>
            $j->productId === $product->id && $j->tenantId === $product->tenant_id && $j->images !== []);
    }
}
```

> **Implementer note:** implement `makeProductAndCatalogWithImages()` using the same product/tenant setup the existing `CatalogEnrichmentService` tests use (search `tests/` for `applyCatalogHit`). `CatalogProductDTO` is at `app/Shared/DTOs/CatalogProductDTO.php` with `public array $images`.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Product/CatalogEnrichmentImageDispatchTest.php`
Expected: FAIL — no dispatch happens.

- [ ] **Step 3: Add the dispatch (after `EnrichmentResult` creation, ~`:128`)**

```php
$normalizedImages = \App\Modules\Product\Application\Support\ImageDescriptorNormalizer::normalize($catalog->images);
if ($normalizedImages !== []) {
    \App\Modules\Product\Application\Jobs\PersistEnrichmentImagesJob::dispatch(
        $product->tenant_id, $product->id, $normalizedImages,
    );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Product/CatalogEnrichmentImageDispatchTest.php`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
cd apps/api && ./vendor/bin/pint app/Modules/Product/Application/Services/CatalogEnrichmentService.php tests/Feature/Modules/Product/CatalogEnrichmentImageDispatchTest.php
git add -A && git commit -m "feat(product): dispatch image persistence on catalog-hit enrichment"
```

---

## Task 10: Path B hook — dispatch from `accept` (auto + opt-out)

**Files:**
- Modify: `app/Modules/Product/Application/Services/EnrichmentReviewService.php` (after `$product->update($productUpdates)`, `:179`)
- Test: `tests/Feature/Modules/Product/EnrichmentAcceptImageDispatchTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Product;

use App\Modules\Product\Application\Jobs\PersistEnrichmentImagesJob;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class EnrichmentAcceptImageDispatchTest extends TestCase
{
    public function test_accept_auto_dispatches_when_accepted_fields_empty(): void
    {
        Bus::fake();
        $result = $this->makePendingResultWithImages(['https://pharma-shop.tn/a.jpg']);
        app(\App\Modules\Product\Application\Services\EnrichmentReviewService::class)
            ->accept($result, [], 'reviewer@x.tn');
        Bus::assertDispatched(PersistEnrichmentImagesJob::class);
    }

    public function test_accept_dispatches_when_images_field_included(): void
    {
        Bus::fake();
        $result = $this->makePendingResultWithImages(['https://pharma-shop.tn/a.jpg']);
        app(\App\Modules\Product\Application\Services\EnrichmentReviewService::class)
            ->accept($result, ['name', 'images'], 'reviewer@x.tn');
        Bus::assertDispatched(PersistEnrichmentImagesJob::class);
    }

    public function test_accept_skips_images_when_fields_present_and_exclude_images(): void
    {
        Bus::fake();
        $result = $this->makePendingResultWithImages(['https://pharma-shop.tn/a.jpg']);
        app(\App\Modules\Product\Application\Services\EnrichmentReviewService::class)
            ->accept($result, ['name'], 'reviewer@x.tn');
        Bus::assertNotDispatched(PersistEnrichmentImagesJob::class);
    }
}
```

> **Implementer note:** build `makePendingResultWithImages()` from the existing Path-B test setup (search `tests/` for `EnrichmentReviewService` / `fetchAndStore`). The enriched images live on `$result->enriched_data->images`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Product/EnrichmentAcceptImageDispatchTest.php`
Expected: FAIL — no dispatch.

- [ ] **Step 3: Add the dispatch (after `:179`)**

```php
$imagesRequested = $acceptedFields === [] || in_array('images', $acceptedFields, true);
$normalizedImages = \App\Modules\Product\Application\Support\ImageDescriptorNormalizer::normalize($enrichedData->images);
if ($imagesRequested && $normalizedImages !== []) {
    \App\Modules\Product\Application\Jobs\PersistEnrichmentImagesJob::dispatch(
        $product->tenant_id, $product->id, $normalizedImages,
    );
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Product/EnrichmentAcceptImageDispatchTest.php`
Expected: PASS (all 3).

- [ ] **Step 5: Pint + commit**

```bash
cd apps/api && ./vendor/bin/pint app/Modules/Product/Application/Services/EnrichmentReviewService.php tests/Feature/Modules/Product/EnrichmentAcceptImageDispatchTest.php
git add -A && git commit -m "feat(product): dispatch image persistence on enrichment accept (auto + opt-out)"
```

---

## Task 11: `enrichment:run` bulk command

Trigger fresh Path-A enrichment across a tenant's eligible products (tenancy-init + `onQueue('enrichment')`).

**Files:**
- Create: `app/Modules/Product/Presentation/Console/RunEnrichmentCommand.php`
- Modify: `app/Modules/Product/Providers/ProductServiceProvider.php` (register in `boot()` under `runningInConsole()`)
- Test: `tests/Feature/Modules/Product/RunEnrichmentCommandTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Product;

use App\Modules\Product\Application\Jobs\ApplyCatalogEnrichmentJob;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class RunEnrichmentCommandTest extends TestCase
{
    public function test_dispatches_for_eligible_products_on_enrichment_queue(): void
    {
        Bus::fake();
        $tenant = $this->makeTenantWithProducts(
            eligible: 2,   // have platform_product_id + barcode
            ineligible: 1, // missing barcode
        );

        $this->artisan('enrichment:run', ['tenant' => $tenant->id, '--vertical' => 'parapharmacy'])
            ->assertExitCode(0);

        Bus::assertDispatchedTimes(ApplyCatalogEnrichmentJob::class, 2);
        Bus::assertDispatched(ApplyCatalogEnrichmentJob::class, fn ($j) => $j->queue === 'enrichment');
    }
}
```

> **Implementer note:** `makeTenantWithProducts()` creates a tenant DB + products (reuse the project's tenant-provisioning test helper; search `tests/` for how other tenant-scoped command tests bootstrap a tenant). Eligible = `platform_product_id` + `barcode` set.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Product/RunEnrichmentCommandTest.php`
Expected: FAIL — command not registered.

- [ ] **Step 3: Write the command**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Console;

use App\Modules\Product\Application\Jobs\ApplyCatalogEnrichmentJob;
use App\Modules\Product\Domain\Product; // confirm exact Product model path
use Illuminate\Console\Command;
use Stancl\Tenancy\Contracts\Tenant;

final class RunEnrichmentCommand extends Command
{
    protected $signature = 'enrichment:run {tenant} {--vertical=} {--limit=} {--only-missing-images}';

    protected $description = 'Dispatch fresh catalog enrichment (incl. image persistence) for a tenant\'s eligible products.';

    public function handle(): int
    {
        $tenantId = (string) $this->argument('tenant');
        $tenant = tenancy()->find($tenantId);
        if (! $tenant instanceof Tenant) {
            $this->error("Tenant not found: {$tenantId}");

            return self::FAILURE;
        }

        $dispatched = 0;
        $ineligible = 0;

        tenancy()->initialize($tenant);
        try {
            $query = Product::query()
                ->whereNotNull('platform_product_id')
                ->whereNotNull('barcode');

            if ($this->option('vertical')) {
                $query->where('vertical', $this->option('vertical'));
            }
            if ($this->option('limit')) {
                $query->limit((int) $this->option('limit'));
            }

            foreach ($query->cursor() as $product) {
                if ($this->option('only-missing-images') && $this->hasPrimaryImage($product->id, $tenantId)) {
                    continue;
                }
                ApplyCatalogEnrichmentJob::dispatch(
                    $product->id, $product->platform_product_id, $product->barcode,
                    $product->vertical ?? (string) $this->option('vertical'),
                )->onQueue('enrichment');
                $dispatched++;
            }
        } finally {
            tenancy()->end();
        }

        $this->info("Dispatched {$dispatched} enrichment job(s); {$ineligible} ineligible.");

        return self::SUCCESS;
    }

    private function hasPrimaryImage(string $productId, string $tenantId): bool
    {
        return \App\Modules\Media\Domain\Media\MediaAttachment::query()
            ->where('tenant_id', $tenantId)->where('owner_id', $productId)
            ->where('role', \App\Modules\Media\Domain\Enums\MediaRole::Primary->value)->exists();
    }
}
```

> **Implementer note:** confirm the exact `Product` model FQCN and the `ApplyCatalogEnrichmentJob` constructor arg order (`productId, expectedPlatformProductId, barcode, vertical` per `ApplyCatalogEnrichmentJob.php:37-42`). Confirm `tenancy()->find()/initialize()/end()` are the Stancl helpers available in this codebase (check `BindsTenantContext` / other tenant commands for the exact API).

- [ ] **Step 4: Register the command in `ProductServiceProvider::boot()`**

```php
if ($this->app->runningInConsole()) {
    $this->commands([
        \App\Modules\Product\Presentation\Console\RunEnrichmentCommand::class,
    ]);
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Product/RunEnrichmentCommandTest.php`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```bash
cd apps/api && ./vendor/bin/pint app/Modules/Product/Presentation/Console/RunEnrichmentCommand.php app/Modules/Product/Providers/ProductServiceProvider.php tests/Feature/Modules/Product/RunEnrichmentCommandTest.php
git add -A && git commit -m "feat(product): enrichment:run bulk trigger (tenant-init + enrichment queue)"
```

---

## Task 12: End-to-end integration + regression

Prove the whole chain and guard the invariants.

**Files:**
- Test: `tests/Feature/Modules/Product/EnrichmentImageEndToEndTest.php`

- [ ] **Step 1: Write the integration test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Product;

use App\Modules\Product\Application\Services\EnrichmentImagePersister;
use App\Modules\Product\Application\DTOs\ProductData; // confirm path
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class EnrichmentImageEndToEndTest extends TestCase
{
    public function test_persisted_primary_image_flows_to_primary_image_url(): void
    {
        Storage::fake('s3');
        Bus::fake(); // GenerateRenditions swallowed; primary_image_url resolves from the Primary attachment
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        Http::fake(['https://pharma-shop.tn/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

        $product = $this->makePersistedProduct(); // real Product row in a tenant DB
        app(EnrichmentImagePersister::class)->persist(
            $product->id, $product->tenant_id,
            [['url' => 'https://pharma-shop.tn/a.png', 'thumbnail' => null, 'type' => null]],
            null,
        );

        // Resolve product media the same way ProductData does and assert primary_image_url is set.
        $primaryUrl = $this->resolvePrimaryImageUrl($product->id, $product->tenant_id);
        $this->assertNotNull($primaryUrl);
    }
}
```

> **Implementer note:** `resolvePrimaryImageUrl()` should call the same `CatalogMediaQuery`/`ProductData` path the app uses (see `CatalogMediaQuery.php` + `ProductData.php:104`). Because `Bus::fake()` skips rendition generation, the `sm` rendition won't exist — assert `primary_image_url` resolves to the original/served URL (the resolver falls back when a rendition is absent). If the resolver strictly requires a `sm` rendition, run `GenerateRenditions` inline instead of faking the bus for this one test.

- [ ] **Step 2: Run the integration test**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Modules/Product/EnrichmentImageEndToEndTest.php`
Expected: PASS.

- [ ] **Step 3: Run the Horizon coverage + static gates**

Run:
```bash
cd apps/api && ./vendor/bin/phpunit tests/Unit/Config/HorizonQueueCoverageTest.php
./vendor/bin/phpstan analyse app/Modules/Product app/Modules/Media
./vendor/bin/pint --test app/Modules/Product app/Modules/Media
```
Expected: Horizon coverage PASS (no new queue — `enrichment`/`images` already covered); PHPStan 0 errors; Pint clean.

- [ ] **Step 4: Run the full feature test set (by path)**

Run:
```bash
cd apps/api && ./vendor/bin/phpunit \
  tests/Unit/Modules/Media tests/Unit/Modules/Product \
  tests/Feature/Modules/Media tests/Feature/Modules/Product/EnrichmentImagePersisterTest.php \
  tests/Feature/Modules/Product/PersistEnrichmentImagesJobTest.php \
  tests/Feature/Modules/Product/CatalogEnrichmentImageDispatchTest.php \
  tests/Feature/Modules/Product/EnrichmentAcceptImageDispatchTest.php \
  tests/Feature/Modules/Product/RunEnrichmentCommandTest.php \
  tests/Feature/Modules/Product/EnrichmentImageEndToEndTest.php
```
Expected: all PASS. **Do NOT run the full suite** (memory: crashes the laptop).

- [ ] **Step 5: Commit**

```bash
cd apps/api && git add -A && git commit -m "test(product): end-to-end enrichment image persistence + regression gates"
```

---

## Task 13: Demo population — real platform products with images (OPS, not a shipped path)

Goal: put ~a few dozen **real** parapharmacy products that actually have images into the demo tenant so we can eyeball real POS cards. Owner guidance: discover image-bearing products in the platform DB by barcode, seed those into the demo tenant, keep the rest as-is. **This is demo/ops code, clearly labeled — not a production integration path.**

**Files:**
- Create: `database/seeders/DemoImageProductsFromPlatformSeeder.php`

- [ ] **Step 1: Discover image-bearing platform products (read-only, one-off)**

Query the **platform** DB (`apps/platform`, separate Postgres) for parapharmacy products that have ≥1 image, capturing `barcode`, platform product id, name, brand, and the image URL(s). There is no parapharmacy browse API, so this discovery is a direct DB read. Example (run against the platform DB connection, not the ERP tenant DB):

```sql
-- Adjust table/column names to the platform schema (confirm against apps/platform).
SELECT p.id, p.barcode, p.name, p.brand, i.url
FROM products p
JOIN product_images i ON i.product_id = p.id
WHERE p.barcode IS NOT NULL
LIMIT 40;
```

Capture the resulting rows (barcode + platform_product_id + image URLs) into the seeder as a fixture list, OR have the seeder read them live from the platform via `BarcodeLookupService::lookup($barcode)` for each captured barcode.

- [ ] **Step 2: Write the seeder**

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Product\Application\Jobs\ApplyCatalogEnrichmentJob;
use Illuminate\Database\Seeder;

/**
 * DEMO ONLY. Seeds a handful of real parapharmacy products (with platform_product_id
 * + barcode) discovered from the Synerivia platform as having images, then lets the
 * normal enrichment path attach the images. Not a production integration path.
 */
final class DemoImageProductsFromPlatformSeeder extends Seeder
{
    /** @var list<array{barcode: string, platform_product_id: string, name: string, brand: ?string}> */
    private const PRODUCTS = [
        // Filled from Task-13 Step-1 discovery, e.g.:
        // ['barcode' => '3401579...', 'platform_product_id' => '...', 'name' => 'Avène ...', 'brand' => 'AVENE'],
    ];

    public function run(): void
    {
        foreach (self::PRODUCTS as $row) {
            // Create the product in the current tenant with platform linkage
            // (reuse the app's product-create service or model create, matching DemoPharmacySeeder).
            $product = $this->createDemoProduct($row);

            ApplyCatalogEnrichmentJob::dispatch(
                $product->id, $row['platform_product_id'], $row['barcode'], 'parapharmacy',
            )->onQueue('enrichment');
        }
    }
}
```

> **Implementer note:** `createDemoProduct()` mirrors how `DemoPharmacySeeder`/`ParapharmacySeeder` create products (same required columns, category, price). Confirm `platform_product_id` + `barcode` are real columns on the product model.

- [ ] **Step 3: Run it against the demo tenant + verify**

```bash
cd apps/api
# 1. Ensure platform is reachable: services.platform.url / api_key set in .env
# 2. Run the seeder within the demo tenant context:
php artisan tenants:run 'db:seed --class=Database\\Seeders\\DemoImageProductsFromPlatformSeeder' --tenants=<demo-tenant-id>
# 3. Run the queues so enrichment + images process:
php artisan queue:work --queue=enrichment,images --stop-when-empty
# 4. Verify primary_image_url populated:
php artisan tinker --execute="\App\Modules\Product\Domain\Product::query()->whereNotNull('platform_product_id')->take(5)->get()->each(fn(\$p)=>print(\$p->name.' => '.optional(\$p->primaryMedia)->url.PHP_EOL));"
```
Expected: the seeded products have a Primary media attachment; opening the POS on the demo tenant shows real images on those cards.

> **Prerequisite gate:** if the platform is not reachable or the discovery query returns no image-bearing rows for barcodes we can seed, STOP and report — the durable feature (Tasks 1–12) is still correct and mergeable; only the visual demo is blocked. Do not fake images to make it "work."

- [ ] **Step 4: Commit (demo seeder only; do not include local .env changes)**

```bash
cd apps/api && ./vendor/bin/pint database/seeders/DemoImageProductsFromPlatformSeeder.php
git add database/seeders/DemoImageProductsFromPlatformSeeder.php
git commit -m "chore(demo): seed real platform parapharmacy products with images"
```

---

## Self-Review

**Spec coverage:** Every spec section maps to a task — RemoteImageFetcher/SSRF §1 → Tasks 1,3,4; EnrichmentImagePersister §2 → Task 7; EnrichmentImagePolicy §3 → Task 6; PersistEnrichmentImagesJob §4 → Task 8; schema §5 → Task 2; enrichment:run §6 → Task 11; both path hooks → Tasks 9,10; normalization (M-05) → Task 5; ExternalUrlGuard extraction (M-06) → Task 1; idempotency (B-02) → Tasks 2,7; orphan cleanup (M-03/04) → Task 7; tenancy/queue (M-07) → Tasks 8,11; demo population (owner guidance) → Task 13.

**Placeholder scan:** The `> Implementer note` blocks are deliberate "confirm this exact existing symbol against code" instructions (Product FQCN, MediaAttachment relations, Stancl tenancy helper API, test factories) — not deferred design. Each names the file to check. No `TODO`/`TBD` in shipped logic.

**Type consistency:** `persist(productId, tenantId, images, userId)` used consistently (Tasks 7,8,12); `uploadForProduct(..., sourceRef)` consistent (Tasks 2,7); `roleFor(productId, tenantId, runAssignedPrimary)` consistent (Tasks 6,7); job ctor `(tenantId, productId, images, userId?)` consistent (Tasks 8,9,10).

**Known residuals flagged for the plan's Codex review:** (a) `Http::fake()` bypasses the cURL `CURLOPT_RESOLVE` pin, so IP-pinning is asserted structurally + verified live in Task 12, not in unit tests; (b) `hardDeleteOrphan` is new behavior on `MediaAttachmentService` — confirm rendition-row relations; (c) several `MediaAttachment`/`Product` relation + FQCN confirmations; (d) whether `primary_image_url` resolves without a `sm` rendition when the bus is faked (Task 12 note).
