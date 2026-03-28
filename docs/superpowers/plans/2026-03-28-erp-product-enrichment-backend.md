# ERP Product Enrichment Backend — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the ERP-side backend for barcode lookup (refactored to universal endpoint), product submission for enrichment, webhook reception, enrichment review (accept/reject), and polling fallback.

**Architecture:** PlatformIntegration module handles all HTTP to the Syneriva platform (transport + resilience). Product module owns the enrichment result model, review service, and merge logic. Cross-module communication via `EnrichmentWebhookReceived` event. The ERP is a thin client — all enrichment intelligence lives on the platform.

**Tech Stack:** Laravel 12, PHP 8.2+ strict types, PostgreSQL 16, Redis (cache/queue), Spatie Laravel Data, Spatie Permissions, Laravel Horizon.

**Spec:** `docs/superpowers/specs/2026-03-28-erp-product-enrichment-integration-design.md`

**Conventions:** Read `docs/conventions/README.md` before starting. Key rules: constructor injection only (`private readonly`), no `app()` helper, all status columns use PHP enums, DTOs for JSONB columns, routes use `['api', 'auth:sanctum', SetPermissionsTeam::class]` middleware (except webhooks).

---

## File Map

### PlatformIntegration Module

| Action | Path |
|--------|------|
| Modify | `apps/api/app/Modules/PlatformIntegration/Infrastructure/Http/PlatformHttpClient.php` |
| Create | `apps/api/app/Modules/PlatformIntegration/Domain/ValueObjects/PlatformProductData.php` |
| Modify | `apps/api/app/Modules/PlatformIntegration/Application/DTOs/BarcodeLookupResultData.php` |
| Create | `apps/api/app/Modules/PlatformIntegration/Application/DTOs/ProductSubmissionData.php` |
| Create | `apps/api/app/Modules/PlatformIntegration/Application/DTOs/SubmissionResultData.php` |
| Create | `apps/api/app/Modules/PlatformIntegration/Application/DTOs/EnrichmentWebhookPayload.php` |
| Modify | `apps/api/app/Modules/PlatformIntegration/Application/Services/BarcodeLookupService.php` |
| Create | `apps/api/app/Modules/PlatformIntegration/Application/Services/ProductSubmissionService.php` |
| Create | `apps/api/app/Modules/PlatformIntegration/Application/Jobs/ProcessEnrichmentWebhookJob.php` |
| Create | `apps/api/app/Modules/PlatformIntegration/Application/Commands/CheckPendingEnrichmentsCommand.php` |
| Create | `apps/api/app/Modules/PlatformIntegration/Infrastructure/Middleware/VerifySynerivaWebhookSignature.php` |
| Create | `apps/api/app/Modules/PlatformIntegration/Presentation/Controllers/EnrichmentWebhookController.php` |
| Modify | `apps/api/app/Modules/PlatformIntegration/Presentation/routes.php` |

### Product Module

| Action | Path |
|--------|------|
| Create | `apps/api/app/Modules/Product/Domain/Enums/EnrichmentStatus.php` |
| Create | `apps/api/app/Modules/Product/Domain/Enums/EnrichmentReviewStatus.php` |
| Create | `apps/api/app/Modules/Product/Domain/EnrichmentResult.php` |
| Create | `apps/api/app/Modules/Product/Domain/Events/EnrichmentWebhookReceived.php` |
| Create | `apps/api/app/Modules/Product/Application/DTOs/EnrichedProductData.php` |
| Create | `apps/api/app/Modules/Product/Application/DTOs/EnrichmentResultData.php` |
| Create | `apps/api/app/Modules/Product/Application/Services/EnrichmentReviewService.php` |
| Create | `apps/api/app/Modules/Product/Application/Listeners/ProcessEnrichmentEventListener.php` |
| Create | `apps/api/app/Modules/Product/Application/Notifications/EnrichmentCompletedNotification.php` |
| Create | `apps/api/app/Modules/Product/Presentation/Controllers/EnrichmentReviewController.php` |
| Create | `apps/api/app/Modules/Product/Presentation/Requests/AcceptEnrichmentRequest.php` |
| Create | `apps/api/app/Modules/Product/Presentation/Requests/RejectEnrichmentRequest.php` |
| Modify | `apps/api/app/Modules/Product/Domain/Product.php` |
| Modify | `apps/api/app/Modules/Product/routes.php` |
| Modify | `apps/api/app/Modules/Product/ProductServiceProvider.php` |

### Shared / Config

| Action | Path |
|--------|------|
| Modify | `apps/api/app/Enums/Vertical.php` |
| Modify | `apps/api/config/services.php` |
| Modify | `apps/api/routes/console.php` |
| Create | `apps/api/database/migrations/2026_03_28_100000_add_enrichment_columns_to_products_table.php` |
| Create | `apps/api/database/migrations/2026_03_28_100001_create_enrichment_results_table.php` |
| Modify | `apps/api/database/seeders/RolesAndPermissionsSeeder.php` |

### Tests

| Action | Path |
|--------|------|
| Create | `apps/api/tests/Unit/Modules/PlatformIntegration/PlatformHttpClientRawMethodsTest.php` |
| Create | `apps/api/tests/Unit/Modules/PlatformIntegration/PlatformProductDataTest.php` |
| Create | `apps/api/tests/Unit/Modules/PlatformIntegration/BarcodeLookupServiceTest.php` |
| Create | `apps/api/tests/Unit/Modules/PlatformIntegration/VerifySynerivaWebhookSignatureTest.php` |
| Create | `apps/api/tests/Unit/Modules/PlatformIntegration/ProductSubmissionServiceTest.php` |
| Create | `apps/api/tests/Unit/Modules/Product/EnrichmentReviewServiceTest.php` |
| Create | `apps/api/tests/Feature/Modules/PlatformIntegration/EnrichmentWebhookControllerTest.php` |
| Create | `apps/api/tests/Feature/Modules/Product/EnrichmentReviewControllerTest.php` |

---

## Task 1: Config, Vertical Mapping, and Migrations

**Files:**
- Modify: `apps/api/config/services.php:89-92`
- Modify: `apps/api/app/Enums/Vertical.php` (add method after `catalogScope()`)
- Create: `apps/api/database/migrations/2026_03_28_100000_add_enrichment_columns_to_products_table.php`
- Create: `apps/api/database/migrations/2026_03_28_100001_create_enrichment_results_table.php`

- [ ] **Step 1: Add `webhook_secret` to platform config**

In `apps/api/config/services.php`, change lines 89-92 from:

```php
'platform' => [
    'url' => env('SYNERIVA_PLATFORM_URL', 'http://localhost:8080'),
    'api_key' => env('SYNERIVA_PLATFORM_API_KEY'),
],
```

to:

```php
'platform' => [
    'url' => env('SYNERIVA_PLATFORM_URL', 'http://localhost:8080'),
    'api_key' => env('SYNERIVA_PLATFORM_API_KEY'),
    'webhook_secret' => env('SYNERIVA_WEBHOOK_SECRET'),
],
```

- [ ] **Step 2: Add `platformVertical()` to the `Vertical` enum**

In `apps/api/app/Enums/Vertical.php`, add this method after the `catalogScope()` method (after line 168):

```php
/**
 * Get the platform vertical alias for this vertical.
 * Maps ERP verticals to Syneriva platform VerticalAlias values.
 *
 * @return string|null Null means this vertical has no platform equivalent
 */
public function platformVertical(): ?string
{
    return match ($this) {
        self::Mechanic,
        self::BodyShop,
        self::PartsRetailer,
        self::CarGlass,
        self::TireShop,
        self::ServiceStation => 'automotive',
        self::Parapharmacy => 'parapharmacy',
        self::Pharmacy => 'pharmacy',
        default => null,
    };
}
```

- [ ] **Step 3: Create migration to add enrichment columns to products table**

Create `apps/api/database/migrations/2026_03_28_100000_add_enrichment_columns_to_products_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->uuid('platform_product_id')->nullable()->after('cost_updated_at');
            $table->uuid('platform_submission_id')->nullable()->after('platform_product_id');
            $table->string('enrichment_status', 20)->nullable()->after('platform_submission_id');

            $table->index(
                ['tenant_id', 'enrichment_status'],
                'idx_products_enrichment'
            );
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_enrichment');
            $table->dropColumn(['platform_product_id', 'platform_submission_id', 'enrichment_status']);
        });
    }
};
```

- [ ] **Step 4: Create migration for enrichment_results table**

Create `apps/api/database/migrations/2026_03_28_100001_create_enrichment_results_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrichment_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('company_id')->constrained('companies');
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->uuid('tracking_id');
            $table->string('status', 20)->default('pending_review');
            $table->jsonb('enriched_data');
            $table->string('enrichment_quality', 10);
            $table->string('assigned_barcode', 50)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users');
            $table->jsonb('accepted_fields')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['product_id'], 'idx_enrichment_results_product');
            $table->index(['tenant_id', 'company_id', 'status'], 'idx_enrichment_results_status');
            $table->unique(['tracking_id'], 'idx_enrichment_results_tracking');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrichment_results');
    }
};
```

- [ ] **Step 5: Run migrations**

Run: `cd apps/api && php artisan migrate`
Expected: Both migrations applied successfully.

- [ ] **Step 6: Commit**

```bash
git add apps/api/config/services.php apps/api/app/Enums/Vertical.php apps/api/database/migrations/2026_03_28_10000*
git commit -m "feat(enrichment): add config, vertical mapping, and database migrations

Add webhook_secret to platform config, platformVertical() to Vertical enum,
and migrations for products enrichment columns + enrichment_results table."
```

---

## Task 2: Product Module — Enums, Model, and Event

**Files:**
- Create: `apps/api/app/Modules/Product/Domain/Enums/EnrichmentStatus.php`
- Create: `apps/api/app/Modules/Product/Domain/Enums/EnrichmentReviewStatus.php`
- Create: `apps/api/app/Modules/Product/Domain/EnrichmentResult.php`
- Create: `apps/api/app/Modules/Product/Domain/Events/EnrichmentWebhookReceived.php`
- Create: `apps/api/app/Modules/Product/Application/DTOs/EnrichedProductData.php`
- Create: `apps/api/app/Modules/Product/Application/DTOs/EnrichmentResultData.php`
- Modify: `apps/api/app/Modules/Product/Domain/Product.php:63-87` (fillable), `100-111` (casts)

- [ ] **Step 1: Create `EnrichmentStatus` enum**

Create `apps/api/app/Modules/Product/Domain/Enums/EnrichmentStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Enums;

enum EnrichmentStatus: string
{
    case Pending = 'pending';
    case Enriching = 'enriching';
    case Completed = 'completed';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case NotEnrichable = 'not_enrichable';

    /**
     * Map a platform API status to the ERP enrichment status.
     */
    public static function fromPlatformStatus(string $platformStatus): self
    {
        return match ($platformStatus) {
            'submitted' => self::Pending,
            'enriching' => self::Enriching,
            'enriched', 'approved' => self::Completed,
            'rejected' => self::Rejected,
            'failed' => self::Failed,
            'not_enrichable' => self::NotEnrichable,
            default => throw new \ValueError("Unknown platform enrichment status: {$platformStatus}"),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Enriching => 'Enriching',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Rejected => 'Rejected',
            self::NotEnrichable => 'Not Enrichable',
        };
    }
}
```

- [ ] **Step 2: Create `EnrichmentReviewStatus` enum**

Create `apps/api/app/Modules/Product/Domain/Enums/EnrichmentReviewStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Enums;

enum EnrichmentReviewStatus: string
{
    case PendingReview = 'pending_review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pending Review',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
        };
    }
}
```

- [ ] **Step 3: Create `EnrichedProductData` DTO for JSONB column**

Create `apps/api/app/Modules/Product/Application/DTOs/EnrichedProductData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class EnrichedProductData extends Data
{
    /**
     * @param  array<string, mixed>  $classification
     * @param  list<array{name: string, position: int}>  $ingredients
     * @param  list<array{url: ?string, thumbnail: ?string, type: ?string}>  $images
     * @param  array<string, float>|null  $field_confidence
     * @param  list<string>|null  $enrichment_sources
     */
    public function __construct(
        public string $name,
        public ?string $brand,
        public ?string $description,
        public array $classification,
        public array $ingredients,
        public array $images,
        public int $confidence_score,
        public ?string $enrichment_tier,
        public ?array $field_confidence,
        public ?array $enrichment_sources,
        public ?string $assigned_barcode,
        public ?string $assigned_barcode_type,
    ) {}
}
```

- [ ] **Step 4: Create `EnrichmentResultData` DTO for API responses**

Create `apps/api/app/Modules/Product/Application/DTOs/EnrichmentResultData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class EnrichmentResultData extends Data
{
    public function __construct(
        public string $id,
        public string $product_id,
        public string $product_name,
        public ?string $product_barcode,
        public ?string $product_sku,
        public string $tracking_id,
        public string $status,
        public EnrichedProductData $enriched_data,
        public string $enrichment_quality,
        public ?string $assigned_barcode,
        public ?string $reviewed_at,
        public ?string $reviewed_by,
        public ?array $accepted_fields,
        public ?string $rejection_reason,
        public string $created_at,
    ) {}
}
```

- [ ] **Step 5: Create `EnrichmentWebhookReceived` event**

Create `apps/api/app/Modules/Product/Domain/Events/EnrichmentWebhookReceived.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class EnrichmentWebhookReceived
{
    use Dispatchable;

    public function __construct(
        public readonly string $trackingId,
        public readonly string $status,
        public readonly ?string $enrichmentQuality,
        public readonly bool $hasBarcodeAssigned,
        public readonly string $vertical,
    ) {}
}
```

- [ ] **Step 6: Create `EnrichmentResult` model**

Create `apps/api/app/Modules/Product/Domain/EnrichmentResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain;

use App\Modules\Identity\Domain\User;
use App\Modules\Product\Application\DTOs\EnrichedProductData;
use App\Modules\Product\Domain\Enums\EnrichmentReviewStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $product_id
 * @property string $tracking_id
 * @property EnrichmentReviewStatus $status
 * @property EnrichedProductData $enriched_data
 * @property string $enrichment_quality
 * @property string|null $assigned_barcode
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property string|null $reviewed_by
 * @property array<int, string>|null $accepted_fields
 * @property string|null $rejection_reason
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Product $product
 * @property-read User|null $reviewer
 */
final class EnrichmentResult extends Model
{
    use HasUuids;

    protected $table = 'enrichment_results';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'product_id',
        'tracking_id',
        'status',
        'enriched_data',
        'enrichment_quality',
        'assigned_barcode',
        'reviewed_at',
        'reviewed_by',
        'accepted_fields',
        'rejection_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EnrichmentReviewStatus::class,
            'enriched_data' => EnrichedProductData::class,
            'accepted_fields' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, self>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
```

- [ ] **Step 7: Update `Product` model — add enrichment columns to fillable and casts**

In `apps/api/app/Modules/Product/Domain/Product.php`:

Add to the `$fillable` array (after `'cost_updated_at'` on line 86):

```php
'platform_product_id',
'platform_submission_id',
'enrichment_status',
```

Add to the `casts()` method return array (after `'cost_updated_at' => 'datetime'` on line 109):

```php
'enrichment_status' => \App\Modules\Product\Domain\Enums\EnrichmentStatus::class,
```

Add a new relationship method after existing relationships:

```php
/**
 * @return HasOne<EnrichmentResult, self>
 */
public function latestEnrichmentResult(): HasOne
{
    return $this->hasOne(EnrichmentResult::class)->latestOfMany();
}

/**
 * @return HasMany<EnrichmentResult, self>
 */
public function enrichmentResults(): HasMany
{
    return $this->hasMany(EnrichmentResult::class);
}
```

Add the `use` import for `EnrichmentResult` at the top of the file.

Also add property annotations in the docblock:

```php
 * @property string|null $platform_product_id
 * @property string|null $platform_submission_id
 * @property \App\Modules\Product\Domain\Enums\EnrichmentStatus|null $enrichment_status
 * @property-read EnrichmentResult|null $latestEnrichmentResult
```

- [ ] **Step 8: Commit**

```bash
git add apps/api/app/Modules/Product/Domain/Enums/ apps/api/app/Modules/Product/Domain/EnrichmentResult.php apps/api/app/Modules/Product/Domain/Events/EnrichmentWebhookReceived.php apps/api/app/Modules/Product/Application/DTOs/EnrichedProductData.php apps/api/app/Modules/Product/Application/DTOs/EnrichmentResultData.php apps/api/app/Modules/Product/Domain/Product.php
git commit -m "feat(enrichment): add Product module domain layer — enums, model, event, DTOs

EnrichmentStatus and EnrichmentReviewStatus enums, EnrichmentResult model
with typed JSONB cast, EnrichedProductData DTO, EnrichmentWebhookReceived
event, and Product model enrichment columns."
```

---

## Task 3: PlatformHttpClient — Add Raw Methods

**Files:**
- Modify: `apps/api/app/Modules/PlatformIntegration/Infrastructure/Http/PlatformHttpClient.php`
- Create: `apps/api/tests/Unit/Modules/PlatformIntegration/PlatformHttpClientRawMethodsTest.php`

- [ ] **Step 1: Write the failing test**

Create `apps/api/tests/Unit/Modules/PlatformIntegration/PlatformHttpClientRawMethodsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\PlatformIntegration;

use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformHttpClientRawMethodsTest extends TestCase
{
    private PlatformHttpClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new PlatformHttpClient();
        config(['services.platform.url' => 'https://platform.test']);
        config(['services.platform.api_key' => 'test-key']);
        Cache::forget('platform:circuit_breaker');
        Cache::forget('platform:circuit_failures');
    }

    public function test_post_raw_returns_full_response_body(): void
    {
        Http::fake([
            'platform.test/*' => Http::response([
                'status' => 'found',
                'product' => ['id' => 'abc-123', 'name' => 'Test Product'],
            ]),
        ]);

        $result = $this->client->postRaw('/api/v1/products/lookup', ['barcode' => '123']);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertEquals('found', $result['status']);
        $this->assertArrayHasKey('product', $result);
    }

    public function test_get_raw_returns_full_response_body(): void
    {
        Http::fake([
            'platform.test/*' => Http::response([
                'tracking_id' => 'track-123',
                'status' => 'enriching',
                'enriched_data' => null,
            ]),
        ]);

        $result = $this->client->getRaw('/api/v1/products/lookup-status/track-123');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('tracking_id', $result);
        $this->assertEquals('enriching', $result['status']);
    }

    public function test_post_raw_returns_null_on_404(): void
    {
        Http::fake([
            'platform.test/*' => Http::response([], 404),
        ]);

        $result = $this->client->postRaw('/api/v1/products/lookup', ['barcode' => '123']);

        $this->assertNull($result);
    }

    public function test_post_raw_throws_on_circuit_open(): void
    {
        Cache::put('platform:circuit_breaker', true, 30);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Platform circuit breaker is open');

        $this->client->postRaw('/api/v1/products/lookup', []);
    }

    public function test_post_raw_with_custom_headers(): void
    {
        Http::fake([
            'platform.test/*' => Http::response(['tracking_id' => 'abc']),
        ]);

        $result = $this->client->postRaw(
            '/api/v1/products/submit',
            ['name' => 'Test'],
            ['Idempotency-Key' => 'idem-123']
        );

        $this->assertIsArray($result);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Idempotency-Key', 'idem-123');
        });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && php artisan test --filter=PlatformHttpClientRawMethodsTest`
Expected: FAIL — methods `postRaw`, `getRaw` do not exist.

- [ ] **Step 3: Implement `postRaw`, `getRaw` on PlatformHttpClient**

In `apps/api/app/Modules/PlatformIntegration/Infrastructure/Http/PlatformHttpClient.php`, add these two methods after the `post()` method (after line 89):

```php
/**
 * POST request returning the full response body (no 'data' unwrapping).
 * Used for platform endpoints that don't wrap responses in {data: ...}.
 *
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $headers
 * @return array<string, mixed>|null
 */
public function postRaw(string $path, array $data = [], array $headers = []): ?array
{
    if ($this->isCircuitOpen()) {
        throw new \RuntimeException('Platform circuit breaker is open');
    }

    try {
        $request = $this->buildRequest();
        if ($headers !== []) {
            $request = $request->withHeaders($headers);
        }

        $response = $request->post($this->buildUrl($path), $data);

        if ($response->status() === 404) {
            return null;
        }

        if ($response->successful()) {
            $this->resetCircuitFailures();
            /** @var array<string, mixed>|null $result */
            $result = $response->json();
            return $result;
        }

        $this->recordFailure();
        Log::warning('Platform API non-success response (raw)', [
            'path' => $path,
            'status' => $response->status(),
        ]);

        return null;
    } catch (\Throwable $e) {
        $this->recordFailure();
        throw $e;
    }
}

/**
 * GET request returning the full response body (no 'data' unwrapping).
 *
 * @param  array<string, string>  $queryParams
 * @return array<string, mixed>|null
 */
public function getRaw(string $path, array $queryParams = []): ?array
{
    if ($this->isCircuitOpen()) {
        throw new \RuntimeException('Platform circuit breaker is open');
    }

    try {
        $response = $this->buildRequest()
            ->get($this->buildUrl($path), $queryParams);

        if ($response->status() === 404) {
            return null;
        }

        if ($response->successful()) {
            $this->resetCircuitFailures();
            /** @var array<string, mixed>|null $result */
            $result = $response->json();
            return $result;
        }

        $this->recordFailure();
        Log::warning('Platform API non-success response (raw)', [
            'path' => $path,
            'status' => $response->status(),
        ]);

        return null;
    } catch (\Throwable $e) {
        $this->recordFailure();
        throw $e;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/api && php artisan test --filter=PlatformHttpClientRawMethodsTest`
Expected: All 5 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/PlatformIntegration/Infrastructure/Http/PlatformHttpClient.php apps/api/tests/Unit/Modules/PlatformIntegration/PlatformHttpClientRawMethodsTest.php
git commit -m "feat(platform): add postRaw/getRaw methods to PlatformHttpClient

New methods return full response body without unwrapping 'data' key,
needed for ProductLookup endpoints that use a different response shape."
```

---

## Task 4: PlatformProductData Value Object

**Files:**
- Create: `apps/api/app/Modules/PlatformIntegration/Domain/ValueObjects/PlatformProductData.php`
- Create: `apps/api/tests/Unit/Modules/PlatformIntegration/PlatformProductDataTest.php`

- [ ] **Step 1: Write the failing test**

Create `apps/api/tests/Unit/Modules/PlatformIntegration/PlatformProductDataTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\PlatformIntegration;

use App\Modules\PlatformIntegration\Domain\ValueObjects\PlatformProductData;
use PHPUnit\Framework\TestCase;

class PlatformProductDataTest extends TestCase
{
    public function test_from_api_response_maps_all_fields(): void
    {
        $apiResponse = [
            'id' => 'prod-uuid-123',
            'barcode' => '5901234123457',
            'name' => 'Avène Cleanance Gel',
            'brand' => 'Avène',
            'description' => 'Purifying cleansing gel',
            'classification' => ['category' => 'facial_cleanser', 'subcategory' => 'gel'],
            'ingredients' => [
                ['name' => 'Aqua', 'position' => 1],
                ['name' => 'Zinc Gluconate', 'position' => 2],
            ],
            'images' => [
                ['url' => 'https://cdn.test/img.jpg', 'thumbnail' => 'https://cdn.test/thumb.jpg', 'type' => 'front'],
            ],
            'confidence_score' => 85,
            'enrichment_tier' => 'high',
        ];

        $product = PlatformProductData::fromApiResponse($apiResponse);

        $this->assertSame('prod-uuid-123', $product->id);
        $this->assertSame('5901234123457', $product->barcode);
        $this->assertSame('Avène Cleanance Gel', $product->name);
        $this->assertSame('Avène', $product->brand);
        $this->assertSame('Purifying cleansing gel', $product->description);
        $this->assertSame('facial_cleanser', $product->classification['category']);
        $this->assertCount(2, $product->ingredients);
        $this->assertCount(1, $product->images);
        $this->assertSame(85, $product->confidenceScore);
        $this->assertSame('high', $product->enrichmentTier);
    }

    public function test_from_api_response_handles_nullable_fields(): void
    {
        $apiResponse = [
            'id' => 'prod-uuid-456',
            'barcode' => '1234567890123',
            'name' => 'Unknown Product',
        ];

        $product = PlatformProductData::fromApiResponse($apiResponse);

        $this->assertSame('prod-uuid-456', $product->id);
        $this->assertNull($product->brand);
        $this->assertNull($product->description);
        $this->assertSame([], $product->classification);
        $this->assertSame([], $product->ingredients);
        $this->assertSame([], $product->images);
        $this->assertSame(0, $product->confidenceScore);
        $this->assertNull($product->enrichmentTier);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && php artisan test --filter=PlatformProductDataTest`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Create the value object**

Create `apps/api/app/Modules/PlatformIntegration/Domain/ValueObjects/PlatformProductData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Domain\ValueObjects;

/**
 * Immutable representation of a product from the platform's universal lookup endpoint.
 * Maps the LookupProductResource response shape.
 *
 * @phpstan-type Classification array<string, mixed>
 * @phpstan-type Ingredient array{name: string, position: int}
 * @phpstan-type Image array{url: ?string, thumbnail: ?string, type: ?string}
 */
final readonly class PlatformProductData
{
    /**
     * @param  Classification  $classification
     * @param  list<Ingredient>  $ingredients
     * @param  list<Image>  $images
     */
    public function __construct(
        public string $id,
        public string $barcode,
        public string $name,
        public ?string $brand,
        public ?string $description,
        public array $classification,
        public array $ingredients,
        public array $images,
        public int $confidenceScore,
        public ?string $enrichmentTier,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromApiResponse(array $data): self
    {
        return new self(
            id: $data['id'],
            barcode: $data['barcode'],
            name: $data['name'],
            brand: $data['brand'] ?? null,
            description: $data['description'] ?? null,
            classification: $data['classification'] ?? [],
            ingredients: $data['ingredients'] ?? [],
            images: $data['images'] ?? [],
            confidenceScore: (int) ($data['confidence_score'] ?? 0),
            enrichmentTier: $data['enrichment_tier'] ?? null,
        );
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/api && php artisan test --filter=PlatformProductDataTest`
Expected: All 2 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/PlatformIntegration/Domain/ValueObjects/PlatformProductData.php apps/api/tests/Unit/Modules/PlatformIntegration/PlatformProductDataTest.php
git commit -m "feat(platform): add PlatformProductData value object

Readonly VO mapping the platform's LookupProductResource response.
Replaces PlatformArticle for the universal barcode lookup endpoint."
```

---

## Task 5: Refactor BarcodeLookupService and DTO

**Files:**
- Modify: `apps/api/app/Modules/PlatformIntegration/Application/DTOs/BarcodeLookupResultData.php`
- Modify: `apps/api/app/Modules/PlatformIntegration/Application/Services/BarcodeLookupService.php`
- Modify: `apps/api/app/Modules/PlatformIntegration/Presentation/Controllers/BarcodeLookupController.php`
- Create: `apps/api/tests/Unit/Modules/PlatformIntegration/BarcodeLookupServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `apps/api/tests/Unit/Modules/PlatformIntegration/BarcodeLookupServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\PlatformIntegration;

use App\Modules\PlatformIntegration\Application\Services\BarcodeLookupService;
use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use App\Services\CompanyContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class BarcodeLookupServiceTest extends TestCase
{
    private BarcodeLookupService $service;
    private CompanyContext $companyContext;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.platform.url' => 'https://platform.test']);
        config(['services.platform.api_key' => 'test-key']);
        Cache::flush();

        $this->companyContext = Mockery::mock(CompanyContext::class);
        $this->service = new BarcodeLookupService(
            new PlatformHttpClient(),
            $this->companyContext,
        );
    }

    public function test_lookup_found_returns_platform_product_data(): void
    {
        $this->mockCompanyWithVertical('parapharmacy');

        Http::fake([
            'platform.test/*' => Http::response([
                'status' => 'found',
                'product' => [
                    'id' => 'prod-123',
                    'barcode' => '5901234123457',
                    'name' => 'Test Product',
                    'brand' => 'TestBrand',
                    'description' => null,
                    'classification' => [],
                    'ingredients' => [],
                    'images' => [],
                    'confidence_score' => 90,
                    'enrichment_tier' => 'high',
                ],
            ]),
        ]);

        $result = $this->service->lookup('5901234123457');

        $this->assertSame('found', $result->status);
        $this->assertNotNull($result->product);
        $this->assertSame('prod-123', $result->product->id);
        $this->assertSame('Test Product', $result->product->name);
        $this->assertNotNull($result->suggestedProduct);
        $this->assertNull($result->trackingId);
    }

    public function test_lookup_not_found_returns_tracking_id(): void
    {
        $this->mockCompanyWithVertical('parapharmacy');

        Http::fake([
            'platform.test/*' => Http::response([
                'status' => 'not_found',
                'tracking_id' => 'track-456',
                'message' => 'Not found',
            ]),
        ]);

        $result = $this->service->lookup('5901234123457');

        $this->assertSame('not_found', $result->status);
        $this->assertNull($result->product);
        $this->assertSame('track-456', $result->trackingId);
    }

    public function test_lookup_returns_error_for_unsupported_vertical(): void
    {
        $this->mockCompanyWithVertical(null);

        $result = $this->service->lookup('5901234123457');

        $this->assertSame('error', $result->status);
        $this->assertSame('vertical_not_supported', $result->errorReason);
    }

    public function test_lookup_normalizes_upc12_to_ean13(): void
    {
        $this->mockCompanyWithVertical('automotive');

        Http::fake([
            'platform.test/*' => Http::response([
                'status' => 'not_found',
                'tracking_id' => null,
            ]),
        ]);

        $this->service->lookup('012345678905');

        Http::assertSent(function ($request) {
            $body = $request->data();
            return $body['barcode'] === '0012345678905';
        });
    }

    public function test_lookup_validates_ean13_check_digit(): void
    {
        $this->mockCompanyWithVertical('parapharmacy');

        $result = $this->service->lookup('5901234123450'); // invalid check digit

        $this->assertSame('error', $result->status);
        $this->assertSame('invalid_barcode', $result->errorReason);
    }

    public function test_lookup_uses_cache(): void
    {
        $this->mockCompanyWithVertical('parapharmacy');

        Http::fake([
            'platform.test/*' => Http::response([
                'status' => 'found',
                'product' => [
                    'id' => 'prod-789',
                    'barcode' => '5901234123457',
                    'name' => 'Cached Product',
                    'confidence_score' => 80,
                ],
            ]),
        ]);

        // First call — hits API
        $this->service->lookup('5901234123457');
        // Second call — should use cache
        $result = $this->service->lookup('5901234123457');

        $this->assertSame('found', $result->status);
        Http::assertSentCount(1);
    }

    public function test_lookup_returns_error_on_circuit_open(): void
    {
        $this->mockCompanyWithVertical('parapharmacy');
        Cache::put('platform:circuit_breaker', true, 30);

        $result = $this->service->lookup('5901234123457');

        $this->assertSame('error', $result->status);
        $this->assertSame('platform_unavailable', $result->errorReason);
    }

    private function mockCompanyWithVertical(?string $platformVertical): void
    {
        $vertical = Mockery::mock(\App\Enums\Vertical::class);
        $vertical->shouldReceive('platformVertical')->andReturn($platformVertical);

        $tenant = Mockery::mock();
        $tenant->vertical = $vertical;

        $company = Mockery::mock();
        $company->tenant = $tenant;

        $this->companyContext->shouldReceive('requireCompany')->andReturn($company);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && php artisan test --filter=BarcodeLookupServiceTest`
Expected: FAIL — constructor signature mismatch (no CompanyContext param), missing methods.

- [ ] **Step 3: Rewrite BarcodeLookupResultData**

Replace the entire file `apps/api/app/Modules/PlatformIntegration/Application/DTOs/BarcodeLookupResultData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\DTOs;

use App\Modules\PlatformIntegration\Domain\ValueObjects\PlatformProductData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class BarcodeLookupResultData extends Data
{
    /**
     * @param  array<string, mixed>|null  $suggestedProduct
     */
    public function __construct(
        public string $status,
        public ?string $barcode,
        public ?PlatformProductData $product,
        public ?string $trackingId,
        public ?array $suggestedProduct,
        public ?string $errorReason,
    ) {}

    public static function found(string $barcode, PlatformProductData $product): self
    {
        return new self(
            status: 'found',
            barcode: $barcode,
            product: $product,
            trackingId: null,
            suggestedProduct: self::buildSuggestedProduct($product),
            errorReason: null,
        );
    }

    public static function notFound(string $barcode, ?string $trackingId = null): self
    {
        return new self(
            status: 'not_found',
            barcode: $barcode,
            product: null,
            trackingId: $trackingId,
            suggestedProduct: null,
            errorReason: null,
        );
    }

    public static function error(string $barcode, string $reason): self
    {
        return new self(
            status: 'error',
            barcode: $barcode,
            product: null,
            trackingId: null,
            suggestedProduct: null,
            errorReason: $reason,
        );
    }

    /**
     * Build a suggested product structure from platform product data.
     *
     * @return array<string, mixed>
     */
    private static function buildSuggestedProduct(PlatformProductData $product): array
    {
        return [
            'name' => $product->name,
            'barcode' => $product->barcode,
            'brand' => $product->brand,
            'description' => $product->description,
            'platform_product_id' => $product->id,
            'classification' => $product->classification,
            'ingredients' => $product->ingredients,
            'images' => $product->images,
        ];
    }
}
```

- [ ] **Step 4: Rewrite BarcodeLookupService**

Replace the entire file `apps/api/app/Modules/PlatformIntegration/Application/Services/BarcodeLookupService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\Services;

use App\Modules\PlatformIntegration\Application\DTOs\BarcodeLookupResultData;
use App\Modules\PlatformIntegration\Domain\ValueObjects\PlatformProductData;
use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use App\Services\CompanyContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class BarcodeLookupService
{
    private const CACHE_PREFIX = 'platform:lookup:';
    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly PlatformHttpClient $platformClient,
        private readonly CompanyContext $companyContext,
    ) {}

    public function lookup(string $barcode, ?string $vertical = null): BarcodeLookupResultData
    {
        // 1. Resolve vertical from tenant
        $company = $this->companyContext->requireCompany();
        $resolvedVertical = $vertical ?? $company->tenant->vertical->platformVertical();

        if ($resolvedVertical === null) {
            return BarcodeLookupResultData::error($barcode, 'vertical_not_supported');
        }

        // 2. Normalize barcode
        $normalized = $this->normalizeBarcode($barcode);
        if ($normalized === null) {
            return BarcodeLookupResultData::error($barcode, 'invalid_barcode');
        }

        // 3. Check cache
        $cacheKey = self::CACHE_PREFIX . $resolvedVertical . ':' . $normalized;
        $cached = Cache::get($cacheKey);
        if ($cached instanceof BarcodeLookupResultData) {
            return $cached;
        }

        // 4. Check circuit breaker
        if ($this->platformClient->isCircuitOpen()) {
            return BarcodeLookupResultData::error($normalized, 'platform_unavailable');
        }

        // 5. Call platform
        try {
            $response = $this->platformClient->postRaw('/api/v1/products/lookup', [
                'barcode' => $normalized,
                'vertical' => $resolvedVertical,
            ]);

            if ($response === null) {
                return BarcodeLookupResultData::notFound($normalized);
            }

            if (($response['status'] ?? '') === 'found') {
                $product = PlatformProductData::fromApiResponse($response['product']);
                $result = BarcodeLookupResultData::found($normalized, $product);
                Cache::put($cacheKey, $result, self::CACHE_TTL_SECONDS);

                return $result;
            }

            return BarcodeLookupResultData::notFound(
                $normalized,
                trackingId: $response['tracking_id'] ?? null,
            );
        } catch (\Throwable $e) {
            Log::warning('Platform barcode lookup failed', [
                'barcode' => $normalized,
                'error' => $e->getMessage(),
            ]);

            return BarcodeLookupResultData::error($normalized, 'platform_error');
        }
    }

    private function normalizeBarcode(string $barcode): ?string
    {
        $barcode = trim($barcode);
        $barcode = preg_replace('/[^a-zA-Z0-9]/', '', $barcode) ?? $barcode;

        // UPC-12 to EAN-13
        if (strlen($barcode) === 12 && ctype_digit($barcode)) {
            $barcode = '0' . $barcode;
        }

        // EAN-13 check digit validation
        if (strlen($barcode) === 13 && ctype_digit($barcode)) {
            if (! $this->isValidEan13($barcode)) {
                return null;
            }
        }

        return $barcode;
    }

    private function isValidEan13(string $barcode): bool
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $barcode[$i];
            $sum += ($i % 2 === 0) ? $digit : $digit * 3;
        }
        $checkDigit = (10 - ($sum % 10)) % 10;

        return $checkDigit === (int) $barcode[12];
    }
}
```

- [ ] **Step 5: Update BarcodeLookupController to pass vertical**

The controller at `apps/api/app/Modules/PlatformIntegration/Presentation/Controllers/BarcodeLookupController.php` needs a minor update to accept an optional `vertical` parameter. Replace the `__invoke` method body to:

```php
public function __invoke(Request $request): JsonResponse
{
    $validated = $request->validate([
        'barcode' => ['required', 'string', 'max:100'],
        'vertical' => ['nullable', 'string', 'max:50'],
    ]);

    $result = $this->lookupService->lookup(
        $validated['barcode'],
        $validated['vertical'] ?? null,
    );

    return response()->json([
        'data' => $result->toArray(),
        'meta' => [
            'timestamp' => now()->toIso8601String(),
            'request_id' => request()->header('X-Request-Id', (string) \Illuminate\Support\Str::uuid()),
        ],
    ]);
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd apps/api && php artisan test --filter=BarcodeLookupServiceTest`
Expected: All 7 tests PASS.

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Modules/PlatformIntegration/Application/DTOs/BarcodeLookupResultData.php apps/api/app/Modules/PlatformIntegration/Application/Services/BarcodeLookupService.php apps/api/app/Modules/PlatformIntegration/Presentation/Controllers/BarcodeLookupController.php apps/api/tests/Unit/Modules/PlatformIntegration/BarcodeLookupServiceTest.php
git commit -m "refactor(platform): BarcodeLookupService to universal product lookup

Breaking change: calls POST /products/lookup instead of
GET /automotive/articles/barcode. Uses PlatformProductData VO,
resolves vertical from tenant, validates EAN-13 check digits."
```

---

## Task 6: Webhook Signature Middleware

**Files:**
- Create: `apps/api/app/Modules/PlatformIntegration/Infrastructure/Middleware/VerifySynerivaWebhookSignature.php`
- Create: `apps/api/tests/Unit/Modules/PlatformIntegration/VerifySynerivaWebhookSignatureTest.php`

- [ ] **Step 1: Write the failing test**

Create `apps/api/tests/Unit/Modules/PlatformIntegration/VerifySynerivaWebhookSignatureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\PlatformIntegration;

use App\Modules\PlatformIntegration\Infrastructure\Middleware\VerifySynerivaWebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class VerifySynerivaWebhookSignatureTest extends TestCase
{
    private VerifySynerivaWebhookSignature $middleware;
    private string $secret = 'test-webhook-secret-key';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.platform.webhook_secret' => $this->secret]);
        $this->middleware = new VerifySynerivaWebhookSignature();
    }

    public function test_valid_signature_passes(): void
    {
        $body = '{"event":"enrichment.resolved","tracking_id":"abc"}';
        $timestamp = (string) time();
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $this->secret);

        $request = $this->makeRequest($body, $signature, $timestamp);

        $response = $this->middleware->handle($request, fn () => new Response('ok'));

        $this->assertSame('ok', $response->getContent());
    }

    public function test_invalid_signature_returns_403(): void
    {
        $body = '{"event":"enrichment.resolved"}';
        $timestamp = (string) time();

        $request = $this->makeRequest($body, 'sha256=invalidsignature', $timestamp);

        $this->expectException(HttpException::class);
        $this->middleware->handle($request, fn () => new Response('ok'));
    }

    public function test_missing_signature_header_returns_403(): void
    {
        $body = '{"event":"enrichment.resolved"}';
        $request = $this->makeRequest($body, null, (string) time());

        $this->expectException(HttpException::class);
        $this->middleware->handle($request, fn () => new Response('ok'));
    }

    public function test_missing_timestamp_header_returns_403(): void
    {
        $body = '{"event":"enrichment.resolved"}';
        $signature = 'sha256=' . hash_hmac('sha256', time() . '.' . $body, $this->secret);
        $request = $this->makeRequest($body, $signature, null);

        $this->expectException(HttpException::class);
        $this->middleware->handle($request, fn () => new Response('ok'));
    }

    public function test_expired_timestamp_returns_403(): void
    {
        $body = '{"event":"enrichment.resolved"}';
        $expiredTimestamp = (string) (time() - 400); // 6+ minutes ago
        $signature = 'sha256=' . hash_hmac('sha256', $expiredTimestamp . '.' . $body, $this->secret);

        $request = $this->makeRequest($body, $signature, $expiredTimestamp);

        $this->expectException(HttpException::class);
        $this->middleware->handle($request, fn () => new Response('ok'));
    }

    public function test_tampered_body_returns_403(): void
    {
        $originalBody = '{"event":"enrichment.resolved","tracking_id":"abc"}';
        $timestamp = (string) time();
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $originalBody, $this->secret);

        $tamperedBody = '{"event":"enrichment.resolved","tracking_id":"HACKED"}';
        $request = $this->makeRequest($tamperedBody, $signature, $timestamp);

        $this->expectException(HttpException::class);
        $this->middleware->handle($request, fn () => new Response('ok'));
    }

    private function makeRequest(string $body, ?string $signature, ?string $timestamp): Request
    {
        $request = Request::create('/api/v1/webhooks/syneriva', 'POST', [], [], [], [], $body);
        $request->headers->set('Content-Type', 'application/json');

        if ($signature !== null) {
            $request->headers->set('X-Syneriva-Signature', $signature);
        }
        if ($timestamp !== null) {
            $request->headers->set('X-Syneriva-Timestamp', $timestamp);
        }

        return $request;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/api && php artisan test --filter=VerifySynerivaWebhookSignatureTest`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement the middleware**

Create `apps/api/app/Modules/PlatformIntegration/Infrastructure/Middleware/VerifySynerivaWebhookSignature.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Infrastructure\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class VerifySynerivaWebhookSignature
{
    private const MAX_TIMESTAMP_AGE_SECONDS = 300; // 5 minutes

    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Syneriva-Signature');
        $timestamp = $request->header('X-Syneriva-Timestamp');
        $secret = config('services.platform.webhook_secret');

        if ($signature === null || $timestamp === null || $secret === null) {
            throw new HttpException(403, 'Missing webhook signature headers.');
        }

        // Replay protection
        if (abs(time() - (int) $timestamp) > self::MAX_TIMESTAMP_AGE_SECONDS) {
            throw new HttpException(403, 'Webhook timestamp expired.');
        }

        $expectedSignature = 'sha256=' . hash_hmac(
            'sha256',
            $timestamp . '.' . $request->getContent(),
            (string) $secret,
        );

        if (! hash_equals($expectedSignature, $signature)) {
            throw new HttpException(403, 'Invalid webhook signature.');
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/api && php artisan test --filter=VerifySynerivaWebhookSignatureTest`
Expected: All 6 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/PlatformIntegration/Infrastructure/Middleware/VerifySynerivaWebhookSignature.php apps/api/tests/Unit/Modules/PlatformIntegration/VerifySynerivaWebhookSignatureTest.php
git commit -m "feat(platform): add webhook signature verification middleware

HMAC-SHA256 verification with timing-safe comparison and 5-minute
replay protection for platform webhook payloads."
```

---

## Task 7: Webhook Controller, Job, DTOs, and Routes

**Files:**
- Create: `apps/api/app/Modules/PlatformIntegration/Application/DTOs/EnrichmentWebhookPayload.php`
- Create: `apps/api/app/Modules/PlatformIntegration/Application/Jobs/ProcessEnrichmentWebhookJob.php`
- Create: `apps/api/app/Modules/PlatformIntegration/Presentation/Controllers/EnrichmentWebhookController.php`
- Modify: `apps/api/app/Modules/PlatformIntegration/Presentation/routes.php`
- Create: `apps/api/tests/Feature/Modules/PlatformIntegration/EnrichmentWebhookControllerTest.php`

- [ ] **Step 1: Create `EnrichmentWebhookPayload` DTO**

Create `apps/api/app/Modules/PlatformIntegration/Application/DTOs/EnrichmentWebhookPayload.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\DTOs;

use Spatie\LaravelData\Data;

class EnrichmentWebhookPayload extends Data
{
    public function __construct(
        public string $event,
        public string $trackingId,
        public ?string $barcode,
        public string $status,
        public ?string $enrichmentQuality,
        public bool $hasBarcodeAssigned,
        public string $vertical,
        public string $timestamp,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromWebhook(array $payload): self
    {
        return new self(
            event: $payload['event'],
            trackingId: $payload['tracking_id'],
            barcode: $payload['barcode'] ?? null,
            status: $payload['status'],
            enrichmentQuality: $payload['enrichment_quality'] ?? null,
            hasBarcodeAssigned: (bool) ($payload['has_barcode_assigned'] ?? false),
            vertical: $payload['vertical'],
            timestamp: $payload['timestamp'],
        );
    }
}
```

- [ ] **Step 2: Create `ProcessEnrichmentWebhookJob`**

Create `apps/api/app/Modules/PlatformIntegration/Application/Jobs/ProcessEnrichmentWebhookJob.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\Jobs;

use App\Modules\PlatformIntegration\Application\DTOs\EnrichmentWebhookPayload;
use App\Modules\Product\Domain\Events\EnrichmentWebhookReceived;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessEnrichmentWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly EnrichmentWebhookPayload $payload,
    ) {}

    public function handle(): void
    {
        if ($this->payload->event === 'enrichment.resolved') {
            EnrichmentWebhookReceived::dispatch(
                trackingId: $this->payload->trackingId,
                status: $this->payload->status,
                enrichmentQuality: $this->payload->enrichmentQuality,
                hasBarcodeAssigned: $this->payload->hasBarcodeAssigned,
                vertical: $this->payload->vertical,
            );
        }

        // enrichment.batch_resolved — each item dispatches its own event
        if ($this->payload->event === 'enrichment.batch_resolved') {
            // Batch payloads are unpacked to individual events by the controller
            // before dispatching this job (one job per item). This path handles
            // the case where a batch event arrives as a single job.
            EnrichmentWebhookReceived::dispatch(
                trackingId: $this->payload->trackingId,
                status: $this->payload->status,
                enrichmentQuality: $this->payload->enrichmentQuality,
                hasBarcodeAssigned: $this->payload->hasBarcodeAssigned,
                vertical: $this->payload->vertical,
            );
        }
    }
}
```

- [ ] **Step 3: Create `EnrichmentWebhookController`**

Create `apps/api/app/Modules/PlatformIntegration/Presentation/Controllers/EnrichmentWebhookController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Presentation\Controllers;

use App\Modules\PlatformIntegration\Application\DTOs\EnrichmentWebhookPayload;
use App\Modules\PlatformIntegration\Application\Jobs\ProcessEnrichmentWebhookJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class EnrichmentWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();
        $event = $payload['event'] ?? '';

        if ($event === 'enrichment.batch_resolved') {
            $this->dispatchBatch($payload);
        } else {
            $webhookPayload = EnrichmentWebhookPayload::fromWebhook($payload);
            ProcessEnrichmentWebhookJob::dispatch($webhookPayload)
                ->onQueue('enrichment');
        }

        return response()->json(['received' => true], 200);
    }

    /**
     * Unpack batch webhook into individual jobs.
     *
     * @param  array<string, mixed>  $payload
     */
    private function dispatchBatch(array $payload): void
    {
        /** @var list<array<string, mixed>> $items */
        $items = $payload['items'] ?? [];
        $vertical = $payload['vertical'] ?? '';
        $timestamp = $payload['timestamp'] ?? '';

        foreach ($items as $item) {
            $itemPayload = EnrichmentWebhookPayload::fromWebhook([
                'event' => 'enrichment.resolved',
                'tracking_id' => $item['tracking_id'],
                'barcode' => $item['barcode'] ?? null,
                'status' => $item['status'],
                'enrichment_quality' => $item['enrichment_quality'] ?? null,
                'has_barcode_assigned' => $item['has_barcode_assigned'] ?? false,
                'vertical' => $vertical,
                'timestamp' => $timestamp,
            ]);

            ProcessEnrichmentWebhookJob::dispatch($itemPayload)
                ->onQueue('enrichment');
        }
    }
}
```

- [ ] **Step 4: Add webhook route to PlatformIntegration routes.php**

In `apps/api/app/Modules/PlatformIntegration/Presentation/routes.php`, add this **before** the existing authenticated route group (before line 11). Add the new import at the top too:

After the existing `use` imports (after line 9), add:

```php
use App\Modules\PlatformIntegration\Infrastructure\Middleware\VerifySynerivaWebhookSignature;
use App\Modules\PlatformIntegration\Presentation\Controllers\EnrichmentWebhookController;
```

Before line 11 (the existing `Route::prefix('api/v1/platform')...`), add:

```php
// Webhook receiver — NO auth:sanctum (platform calls this with HMAC signature)
Route::middleware(['api', VerifySynerivaWebhookSignature::class])
    ->prefix('api/v1/webhooks')
    ->group(function () {
        Route::post('/syneriva', EnrichmentWebhookController::class)
            ->name('platform.webhook.syneriva');
    });

```

- [ ] **Step 5: Write the feature test**

Create `apps/api/tests/Feature/Modules/PlatformIntegration/EnrichmentWebhookControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\PlatformIntegration;

use App\Modules\PlatformIntegration\Application\Jobs\ProcessEnrichmentWebhookJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EnrichmentWebhookControllerTest extends TestCase
{
    private string $secret = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.platform.webhook_secret' => $this->secret]);
    }

    public function test_valid_webhook_dispatches_job_and_returns_200(): void
    {
        Queue::fake();

        $body = json_encode([
            'event' => 'enrichment.resolved',
            'tracking_id' => 'track-123',
            'barcode' => '5901234123457',
            'status' => 'approved',
            'enrichment_quality' => 'high',
            'has_barcode_assigned' => false,
            'vertical' => 'parapharmacy',
            'timestamp' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        $timestamp = (string) time();
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $this->secret);

        $response = $this->postJson('/api/v1/webhooks/syneriva', json_decode($body, true), [
            'X-Syneriva-Signature' => $signature,
            'X-Syneriva-Timestamp' => $timestamp,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['received' => true]);

        Queue::assertPushedOn('enrichment', ProcessEnrichmentWebhookJob::class);
    }

    public function test_invalid_signature_returns_403(): void
    {
        $body = json_encode([
            'event' => 'enrichment.resolved',
            'tracking_id' => 'track-123',
            'status' => 'approved',
            'vertical' => 'parapharmacy',
            'timestamp' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        $response = $this->postJson('/api/v1/webhooks/syneriva', json_decode($body, true), [
            'X-Syneriva-Signature' => 'sha256=invalid',
            'X-Syneriva-Timestamp' => (string) time(),
        ]);

        $response->assertStatus(403);
    }

    public function test_batch_webhook_dispatches_multiple_jobs(): void
    {
        Queue::fake();

        $body = json_encode([
            'event' => 'enrichment.batch_resolved',
            'items' => [
                ['tracking_id' => 'track-1', 'barcode' => '111', 'status' => 'approved', 'enrichment_quality' => 'high'],
                ['tracking_id' => 'track-2', 'barcode' => '222', 'status' => 'approved', 'enrichment_quality' => 'medium'],
                ['tracking_id' => 'track-3', 'barcode' => null, 'status' => 'not_enrichable'],
            ],
            'vertical' => 'parapharmacy',
            'timestamp' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        $timestamp = (string) time();
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $this->secret);

        $response = $this->postJson('/api/v1/webhooks/syneriva', json_decode($body, true), [
            'X-Syneriva-Signature' => $signature,
            'X-Syneriva-Timestamp' => $timestamp,
        ]);

        $response->assertStatus(200);
        Queue::assertPushedOn('enrichment', ProcessEnrichmentWebhookJob::class, 3);
    }
}
```

- [ ] **Step 6: Run tests**

Run: `cd apps/api && php artisan test --filter=EnrichmentWebhookControllerTest`
Expected: All 3 tests PASS.

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Modules/PlatformIntegration/Application/DTOs/EnrichmentWebhookPayload.php apps/api/app/Modules/PlatformIntegration/Application/Jobs/ProcessEnrichmentWebhookJob.php apps/api/app/Modules/PlatformIntegration/Presentation/Controllers/EnrichmentWebhookController.php apps/api/app/Modules/PlatformIntegration/Presentation/routes.php apps/api/tests/Feature/Modules/PlatformIntegration/EnrichmentWebhookControllerTest.php
git commit -m "feat(platform): add webhook receiver for enrichment events

EnrichmentWebhookController receives signed webhooks, dispatches
ProcessEnrichmentWebhookJob on enrichment queue. Handles both
single and batch_resolved event types."
```

---

## Task 8: ProductSubmissionService and DTOs

**Files:**
- Create: `apps/api/app/Modules/PlatformIntegration/Application/DTOs/ProductSubmissionData.php`
- Create: `apps/api/app/Modules/PlatformIntegration/Application/DTOs/SubmissionResultData.php`
- Create: `apps/api/app/Modules/PlatformIntegration/Application/Services/ProductSubmissionService.php`
- Create: `apps/api/tests/Unit/Modules/PlatformIntegration/ProductSubmissionServiceTest.php`

- [ ] **Step 1: Create `ProductSubmissionData` DTO**

Create `apps/api/app/Modules/PlatformIntegration/Application/DTOs/ProductSubmissionData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\DTOs;

use Spatie\LaravelData\Data;

class ProductSubmissionData extends Data
{
    /**
     * @param  array<string, mixed>|null  $attributes
     * @param  list<string>  $photoIds
     */
    public function __construct(
        public ?string $barcode,
        public string $vertical,
        public string $name,
        public string $brand,
        public ?string $category,
        public ?string $description,
        public ?array $attributes,
        public array $photoIds,
        public bool $autoEnrich,
    ) {}
}
```

- [ ] **Step 2: Create `SubmissionResultData` DTO**

Create `apps/api/app/Modules/PlatformIntegration/Application/DTOs/SubmissionResultData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class SubmissionResultData extends Data
{
    public function __construct(
        public string $trackingId,
        public string $status,
        public string $statusUrl,
    ) {}

    /**
     * @param  array<string, mixed>  $response
     */
    public static function fromApiResponse(array $response): self
    {
        return new self(
            trackingId: $response['tracking_id'],
            status: $response['status'],
            statusUrl: $response['status_url'],
        );
    }
}
```

- [ ] **Step 3: Create `ProductSubmissionService`**

Create `apps/api/app/Modules/PlatformIntegration/Application/Services/ProductSubmissionService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\Services;

use App\Modules\PlatformIntegration\Application\DTOs\ProductSubmissionData;
use App\Modules\PlatformIntegration\Application\DTOs\SubmissionResultData;
use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ProductSubmissionService
{
    public function __construct(
        private readonly PlatformHttpClient $client,
    ) {}

    /**
     * Request a presigned URL for photo upload.
     *
     * @return array{photo_id: string, upload_url: string, expires_at: string}|null
     */
    public function requestUploadUrl(string $filename, string $contentType, int $sizeBytes): ?array
    {
        /** @var array{photo_id: string, upload_url: string, expires_at: string}|null $response */
        $response = $this->client->postRaw('/api/v1/products/upload-url', [
            'filename' => $filename,
            'content_type' => $contentType,
            'size_bytes' => $sizeBytes,
        ]);

        return $response;
    }

    /**
     * Upload a photo to a presigned MinIO URL.
     * This bypasses PlatformHttpClient (different host, no circuit breaker).
     */
    public function uploadPhoto(string $uploadUrl, string $fileContents, string $contentType): void
    {
        $response = Http::withBody($fileContents, $contentType)
            ->timeout(30)
            ->put($uploadUrl);

        if (! $response->successful()) {
            Log::error('Failed to upload photo to presigned URL', [
                'status' => $response->status(),
            ]);
            throw new \RuntimeException('Photo upload failed with status ' . $response->status());
        }
    }

    /**
     * Submit a product for enrichment.
     */
    public function submit(ProductSubmissionData $data): ?SubmissionResultData
    {
        $response = $this->client->postRaw('/api/v1/products/submit', [
            'barcode' => $data->barcode,
            'vertical' => $data->vertical,
            'name' => $data->name,
            'brand' => $data->brand,
            'category' => $data->category,
            'description' => $data->description,
            'attributes' => $data->attributes,
            'photo_ids' => $data->photoIds,
            'auto_enrich' => $data->autoEnrich,
        ], [
            'Idempotency-Key' => Str::uuid()->toString(),
        ]);

        if ($response === null) {
            return null;
        }

        return SubmissionResultData::fromApiResponse($response);
    }

    /**
     * Submit products in bulk for enrichment.
     *
     * @param  list<array<string, mixed>>  $submissions
     * @return array<string, mixed>|null
     */
    public function bulkSubmit(string $vertical, array $submissions, bool $autoEnrich = true): ?array
    {
        return $this->client->postRaw('/api/v1/products/bulk-submit', [
            'vertical' => $vertical,
            'submissions' => $submissions,
            'auto_enrich' => $autoEnrich,
        ], [
            'Idempotency-Key' => Str::uuid()->toString(),
        ]);
    }

    /**
     * Bulk barcode lookup.
     *
     * @param  list<string>  $barcodes
     * @return array<string, mixed>|null
     */
    public function bulkLookup(array $barcodes, string $vertical): ?array
    {
        return $this->client->postRaw('/api/v1/products/bulk-lookup', [
            'barcodes' => $barcodes,
            'vertical' => $vertical,
            'mode' => 'sync',
        ]);
    }

    /**
     * Check the enrichment status of a submission.
     *
     * @return array<string, mixed>|null
     */
    public function checkStatus(string $trackingId): ?array
    {
        return $this->client->getRaw("/api/v1/products/lookup-status/{$trackingId}");
    }

    /**
     * Manually trigger enrichment for a submission.
     */
    public function triggerEnrichment(string $trackingId): ?SubmissionResultData
    {
        $response = $this->client->postRaw("/api/v1/products/{$trackingId}/enrich", []);

        if ($response === null) {
            return null;
        }

        return SubmissionResultData::fromApiResponse($response);
    }

    /**
     * Get category attribute schema for a vertical.
     *
     * @return array<string, mixed>|null
     */
    public function getCategoryAttributes(string $vertical, string $category): ?array
    {
        return $this->client->getRaw("/api/v1/verticals/{$vertical}/categories/{$category}/attributes");
    }
}
```

- [ ] **Step 4: Write tests**

Create `apps/api/tests/Unit/Modules/PlatformIntegration/ProductSubmissionServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\PlatformIntegration;

use App\Modules\PlatformIntegration\Application\DTOs\ProductSubmissionData;
use App\Modules\PlatformIntegration\Application\Services\ProductSubmissionService;
use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductSubmissionServiceTest extends TestCase
{
    private ProductSubmissionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.platform.url' => 'https://platform.test']);
        config(['services.platform.api_key' => 'test-key']);
        Cache::flush();

        $this->service = new ProductSubmissionService(new PlatformHttpClient());
    }

    public function test_submit_sends_correct_payload_with_idempotency_key(): void
    {
        Http::fake([
            'platform.test/*' => Http::response([
                'tracking_id' => 'track-abc',
                'status' => 'submitted',
                'status_url' => '/api/v1/products/lookup-status/track-abc',
            ], 201),
        ]);

        $data = new ProductSubmissionData(
            barcode: '5901234123457',
            vertical: 'parapharmacy',
            name: 'Test Product',
            brand: 'TestBrand',
            category: 'facial_cleanser',
            description: 'A test product',
            attributes: null,
            photoIds: [],
            autoEnrich: true,
        );

        $result = $this->service->submit($data);

        $this->assertNotNull($result);
        $this->assertSame('track-abc', $result->trackingId);
        $this->assertSame('submitted', $result->status);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Idempotency-Key')
                && $request->data()['name'] === 'Test Product'
                && $request->data()['auto_enrich'] === true;
        });
    }

    public function test_check_status_returns_enrichment_data(): void
    {
        Http::fake([
            'platform.test/*' => Http::response([
                'tracking_id' => 'track-abc',
                'status' => 'approved',
                'enriched_data' => ['name' => 'Enriched Name'],
                'enrichment_quality' => 'high',
                'assigned_barcode' => null,
            ]),
        ]);

        $result = $this->service->checkStatus('track-abc');

        $this->assertIsArray($result);
        $this->assertSame('approved', $result['status']);
        $this->assertSame('high', $result['enrichment_quality']);
    }

    public function test_request_upload_url_returns_presigned_data(): void
    {
        Http::fake([
            'platform.test/*' => Http::response([
                'photo_id' => 'photo-123',
                'upload_url' => 'https://minio.test/presigned',
                'expires_at' => '2026-03-28T15:00:00Z',
            ]),
        ]);

        $result = $this->service->requestUploadUrl('product.jpg', 'image/jpeg', 2048000);

        $this->assertNotNull($result);
        $this->assertSame('photo-123', $result['photo_id']);
        $this->assertSame('https://minio.test/presigned', $result['upload_url']);
    }

    public function test_upload_photo_puts_to_presigned_url(): void
    {
        Http::fake([
            'minio.test/*' => Http::response('', 200),
        ]);

        $this->service->uploadPhoto(
            'https://minio.test/presigned-url',
            'fake-image-contents',
            'image/jpeg',
        );

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && str_contains($request->url(), 'minio.test');
        });
    }

    public function test_get_category_attributes(): void
    {
        Http::fake([
            'platform.test/*' => Http::response([
                'vertical' => 'parapharmacy',
                'category' => 'orthopedic_shoes',
                'suggested_attributes' => [
                    ['key' => 'size', 'type' => 'select'],
                ],
            ]),
        ]);

        $result = $this->service->getCategoryAttributes('parapharmacy', 'orthopedic_shoes');

        $this->assertIsArray($result);
        $this->assertSame('parapharmacy', $result['vertical']);
    }
}
```

- [ ] **Step 5: Run tests**

Run: `cd apps/api && php artisan test --filter=ProductSubmissionServiceTest`
Expected: All 5 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/api/app/Modules/PlatformIntegration/Application/DTOs/ProductSubmissionData.php apps/api/app/Modules/PlatformIntegration/Application/DTOs/SubmissionResultData.php apps/api/app/Modules/PlatformIntegration/Application/Services/ProductSubmissionService.php apps/api/tests/Unit/Modules/PlatformIntegration/ProductSubmissionServiceTest.php
git commit -m "feat(platform): add ProductSubmissionService for enrichment flow

Handles presigned photo upload, product submission with idempotency,
bulk operations, status checking, and category attribute fetching."
```

---

## Task 9: EnrichmentReviewService, Listener, Notification

**Files:**
- Create: `apps/api/app/Modules/Product/Application/Services/EnrichmentReviewService.php`
- Create: `apps/api/app/Modules/Product/Application/Listeners/ProcessEnrichmentEventListener.php`
- Create: `apps/api/app/Modules/Product/Application/Notifications/EnrichmentCompletedNotification.php`
- Modify: `apps/api/app/Modules/Product/ProductServiceProvider.php`
- Create: `apps/api/tests/Unit/Modules/Product/EnrichmentReviewServiceTest.php`

- [ ] **Step 1: Create `EnrichmentCompletedNotification`**

Create `apps/api/app/Modules/Product/Application/Notifications/EnrichmentCompletedNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Notifications;

use App\Modules\Product\Domain\EnrichmentResult;
use Illuminate\Notifications\Notification;

class EnrichmentCompletedNotification extends Notification
{
    public function __construct(
        private readonly EnrichmentResult $enrichmentResult,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'enrichment_completed',
            'enrichment_result_id' => $this->enrichmentResult->id,
            'product_id' => $this->enrichmentResult->product_id,
            'product_name' => $this->enrichmentResult->product->name ?? 'Unknown',
            'enrichment_quality' => $this->enrichmentResult->enrichment_quality,
            'has_barcode_assigned' => $this->enrichmentResult->assigned_barcode !== null,
            'assigned_barcode' => $this->enrichmentResult->assigned_barcode,
            'message' => "Enrichment ready for {$this->enrichmentResult->product->name}",
        ];
    }
}
```

- [ ] **Step 2: Create `EnrichmentReviewService`**

Create `apps/api/app/Modules/Product/Application/Services/EnrichmentReviewService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\PlatformIntegration\Application\Services\ProductSubmissionService;
use App\Modules\Product\Application\DTOs\EnrichedProductData;
use App\Modules\Product\Domain\EnrichmentResult;
use App\Modules\Product\Domain\Enums\EnrichmentReviewStatus;
use App\Modules\Product\Domain\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

final class EnrichmentReviewService
{
    public function __construct(
        private readonly ProductSubmissionService $submissionService,
    ) {}

    /**
     * Fetch enrichment data from platform and store locally for review.
     */
    public function fetchAndStore(string $trackingId, Product $product): ?EnrichmentResult
    {
        $statusData = $this->submissionService->checkStatus($trackingId);

        if ($statusData === null) {
            Log::warning('Failed to fetch enrichment status from platform', [
                'tracking_id' => $trackingId,
                'product_id' => $product->id,
            ]);

            return null;
        }

        return EnrichmentResult::updateOrCreate(
            ['tracking_id' => $trackingId],
            [
                'tenant_id' => $product->tenant_id,
                'company_id' => $product->company_id,
                'product_id' => $product->id,
                'status' => EnrichmentReviewStatus::PendingReview,
                'enriched_data' => $statusData['enriched_data'] ?? [],
                'enrichment_quality' => $statusData['enrichment_quality'] ?? 'low',
                'assigned_barcode' => $statusData['assigned_barcode'] ?? null,
            ],
        );
    }

    /**
     * Accept selected enrichment fields and merge into the product.
     *
     * @param  list<string>  $acceptedFields
     */
    public function accept(
        EnrichmentResult $enrichmentResult,
        array $acceptedFields,
        string $reviewedBy,
    ): void {
        $product = $enrichmentResult->product;
        $enrichedData = $enrichmentResult->enriched_data;

        // Build update array from accepted fields
        $productUpdate = [];
        $fieldMap = [
            'name' => 'name',
            'brand' => 'brand',
            'description' => 'description',
            'barcode' => 'barcode',
        ];

        foreach ($acceptedFields as $field) {
            if (isset($fieldMap[$field]) && $enrichedData->{$field} !== null) {
                $productUpdate[$fieldMap[$field]] = $enrichedData->{$field};
            }
        }

        // If assigned barcode was accepted and barcode is in accepted fields
        if (in_array('barcode', $acceptedFields, true) && $enrichmentResult->assigned_barcode !== null) {
            $productUpdate['barcode'] = $enrichmentResult->assigned_barcode;
        }

        // Set platform product ID if available from enriched data
        if (isset($enrichedData->assigned_barcode_type)) {
            $productUpdate['platform_product_id'] = $enrichmentResult->tracking_id;
        }

        // Update product
        if ($productUpdate !== []) {
            $product->update($productUpdate);
        }

        // Clear enrichment tracking state
        $product->update([
            'enrichment_status' => null,
            'platform_submission_id' => null,
        ]);

        // Mark enrichment result as accepted
        $enrichmentResult->update([
            'status' => EnrichmentReviewStatus::Accepted,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewedBy,
            'accepted_fields' => $acceptedFields,
        ]);
    }

    /**
     * Reject enrichment results.
     */
    public function reject(
        EnrichmentResult $enrichmentResult,
        string $reviewedBy,
        ?string $reason = null,
    ): void {
        $enrichmentResult->update([
            'status' => EnrichmentReviewStatus::Rejected,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewedBy,
            'rejection_reason' => $reason,
        ]);

        $enrichmentResult->product->update([
            'enrichment_status' => \App\Modules\Product\Domain\Enums\EnrichmentStatus::Rejected,
            'platform_submission_id' => null,
        ]);
    }

    /**
     * List enrichment results for review.
     *
     * @return LengthAwarePaginator<EnrichmentResult>
     */
    public function listForReview(
        string $tenantId,
        string $companyId,
        ?EnrichmentReviewStatus $status = null,
        ?string $quality = null,
    ): LengthAwarePaginator {
        $query = EnrichmentResult::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->with('product:id,name,barcode,sku')
            ->orderByDesc('created_at');

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($quality !== null) {
            $query->where('enrichment_quality', $quality);
        }

        return $query->paginate(20);
    }
}
```

- [ ] **Step 3: Create `ProcessEnrichmentEventListener`**

Create `apps/api/app/Modules/Product/Application/Listeners/ProcessEnrichmentEventListener.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Listeners;

use App\Modules\Product\Application\Notifications\EnrichmentCompletedNotification;
use App\Modules\Product\Application\Services\EnrichmentReviewService;
use App\Modules\Product\Domain\Enums\EnrichmentStatus;
use App\Modules\Product\Domain\Events\EnrichmentWebhookReceived;
use App\Modules\Product\Domain\Product;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

final class ProcessEnrichmentEventListener
{
    public function __construct(
        private readonly EnrichmentReviewService $reviewService,
    ) {}

    public function handle(EnrichmentWebhookReceived $event): void
    {
        // Find product by platform_submission_id
        $product = Product::where('platform_submission_id', $event->trackingId)->first();

        if ($product === null) {
            Log::warning('Enrichment webhook received for unknown submission', [
                'tracking_id' => $event->trackingId,
            ]);

            return;
        }

        // Update enrichment status
        $erpStatus = EnrichmentStatus::fromPlatformStatus($event->status);
        $product->update(['enrichment_status' => $erpStatus]);

        // For terminal statuses, fetch and store enrichment results
        if (in_array($erpStatus, [EnrichmentStatus::Completed, EnrichmentStatus::Failed, EnrichmentStatus::NotEnrichable], true)) {
            $enrichmentResult = $this->reviewService->fetchAndStore($event->trackingId, $product);

            // Notify company users if enrichment completed successfully
            if ($enrichmentResult !== null && $erpStatus === EnrichmentStatus::Completed) {
                $enrichmentResult->load('product');

                $users = $product->company->users()->get();
                Notification::send($users, new EnrichmentCompletedNotification($enrichmentResult));
            }
        }
    }
}
```

- [ ] **Step 4: Wire event listener in ProductServiceProvider**

In `apps/api/app/Modules/Product/ProductServiceProvider.php`, update the `boot()` method:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Product;

use App\Modules\Product\Application\Listeners\ProcessEnrichmentEventListener;
use App\Modules\Product\Domain\Events\EnrichmentWebhookReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class ProductServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/routes.php');

        Event::listen(
            EnrichmentWebhookReceived::class,
            ProcessEnrichmentEventListener::class,
        );
    }
}
```

- [ ] **Step 5: Write tests for EnrichmentReviewService**

Create `apps/api/tests/Unit/Modules/Product/EnrichmentReviewServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Product;

use App\Modules\PlatformIntegration\Application\Services\ProductSubmissionService;
use App\Modules\Product\Application\DTOs\EnrichedProductData;
use App\Modules\Product\Application\Services\EnrichmentReviewService;
use App\Modules\Product\Domain\EnrichmentResult;
use App\Modules\Product\Domain\Enums\EnrichmentReviewStatus;
use App\Modules\Product\Domain\Enums\EnrichmentStatus;
use App\Modules\Product\Domain\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EnrichmentReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private EnrichmentReviewService $service;
    private ProductSubmissionService $submissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->submissionService = Mockery::mock(ProductSubmissionService::class);
        $this->service = new EnrichmentReviewService($this->submissionService);
    }

    public function test_accept_merges_selected_fields_into_product(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $product = Product::factory()->create([
            'name' => 'Original Name',
            'description' => 'Original Description',
            'enrichment_status' => EnrichmentStatus::Completed,
            'platform_submission_id' => 'track-123',
        ]);

        $enrichmentResult = EnrichmentResult::create([
            'tenant_id' => $product->tenant_id,
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'tracking_id' => 'track-123',
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => new EnrichedProductData(
                name: 'Enriched Name',
                brand: 'Enriched Brand',
                description: 'Enriched Description',
                classification: [],
                ingredients: [],
                images: [],
                confidence_score: 85,
                enrichment_tier: 'high',
                field_confidence: null,
                enrichment_sources: null,
                assigned_barcode: null,
                assigned_barcode_type: null,
            ),
            'enrichment_quality' => 'high',
        ]);

        $this->service->accept(
            $enrichmentResult,
            ['name', 'description'],
            'user-uuid-reviewer',
        );

        $product->refresh();
        $enrichmentResult->refresh();

        // Accepted fields merged
        $this->assertSame('Enriched Name', $product->name);
        $this->assertSame('Enriched Description', $product->description);

        // Enrichment state cleared
        $this->assertNull($product->enrichment_status);
        $this->assertNull($product->platform_submission_id);

        // Result marked accepted
        $this->assertSame(EnrichmentReviewStatus::Accepted, $enrichmentResult->status);
        $this->assertNotNull($enrichmentResult->reviewed_at);
        $this->assertSame(['name', 'description'], $enrichmentResult->accepted_fields);
    }

    public function test_reject_updates_statuses(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $product = Product::factory()->create([
            'enrichment_status' => EnrichmentStatus::Completed,
            'platform_submission_id' => 'track-456',
        ]);

        $enrichmentResult = EnrichmentResult::create([
            'tenant_id' => $product->tenant_id,
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'tracking_id' => 'track-456',
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => new EnrichedProductData(
                name: 'Test',
                brand: null,
                description: null,
                classification: [],
                ingredients: [],
                images: [],
                confidence_score: 50,
                enrichment_tier: 'medium',
                field_confidence: null,
                enrichment_sources: null,
                assigned_barcode: null,
                assigned_barcode_type: null,
            ),
            'enrichment_quality' => 'medium',
        ]);

        $this->service->reject($enrichmentResult, 'user-uuid-reviewer', 'Data is incorrect');

        $product->refresh();
        $enrichmentResult->refresh();

        $this->assertSame(EnrichmentStatus::Rejected, $product->enrichment_status);
        $this->assertNull($product->platform_submission_id);
        $this->assertSame(EnrichmentReviewStatus::Rejected, $enrichmentResult->status);
        $this->assertSame('Data is incorrect', $enrichmentResult->rejection_reason);
    }

    public function test_fetch_and_store_creates_enrichment_result(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $product = Product::factory()->create();

        $this->submissionService->shouldReceive('checkStatus')
            ->with('track-789')
            ->once()
            ->andReturn([
                'tracking_id' => 'track-789',
                'status' => 'approved',
                'enriched_data' => [
                    'name' => 'Platform Name',
                    'brand' => 'Platform Brand',
                    'description' => null,
                    'classification' => [],
                    'ingredients' => [],
                    'images' => [],
                    'confidence_score' => 90,
                    'enrichment_tier' => 'high',
                    'field_confidence' => null,
                    'enrichment_sources' => ['open_database'],
                    'assigned_barcode' => '6191234567890',
                    'assigned_barcode_type' => 'syneriva_619',
                ],
                'enrichment_quality' => 'high',
                'assigned_barcode' => '6191234567890',
            ]);

        $result = $this->service->fetchAndStore('track-789', $product);

        $this->assertNotNull($result);
        $this->assertSame('track-789', $result->tracking_id);
        $this->assertSame(EnrichmentReviewStatus::PendingReview, $result->status);
        $this->assertSame('high', $result->enrichment_quality);
        $this->assertSame('6191234567890', $result->assigned_barcode);
    }
}
```

- [ ] **Step 6: Run tests**

Run: `cd apps/api && php artisan test --filter=EnrichmentReviewServiceTest`
Expected: All 3 tests PASS (may need adjustments based on Product factory defaults).

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Modules/Product/Application/Services/EnrichmentReviewService.php apps/api/app/Modules/Product/Application/Listeners/ProcessEnrichmentEventListener.php apps/api/app/Modules/Product/Application/Notifications/EnrichmentCompletedNotification.php apps/api/app/Modules/Product/ProductServiceProvider.php apps/api/tests/Unit/Modules/Product/EnrichmentReviewServiceTest.php
git commit -m "feat(enrichment): add review service, event listener, and notification

EnrichmentReviewService handles fetch/store, accept, reject flows.
ProcessEnrichmentEventListener wires webhook events to review service.
EnrichmentCompletedNotification uses database channel for in-app bell."
```

---

## Task 10: EnrichmentReviewController, FormRequests, Routes

**Files:**
- Create: `apps/api/app/Modules/Product/Presentation/Controllers/EnrichmentReviewController.php`
- Create: `apps/api/app/Modules/Product/Presentation/Requests/AcceptEnrichmentRequest.php`
- Create: `apps/api/app/Modules/Product/Presentation/Requests/RejectEnrichmentRequest.php`
- Modify: `apps/api/app/Modules/Product/routes.php`
- Create: `apps/api/tests/Feature/Modules/Product/EnrichmentReviewControllerTest.php`

- [ ] **Step 1: Create FormRequests**

Create `apps/api/app/Modules/Product/Presentation/Requests/AcceptEnrichmentRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcceptEnrichmentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'accepted_fields' => ['required', 'array', 'min:1'],
            'accepted_fields.*' => ['required', 'string'],
        ];
    }
}
```

Create `apps/api/app/Modules/Product/Presentation/Requests/RejectEnrichmentRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectEnrichmentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
```

- [ ] **Step 2: Create `EnrichmentReviewController`**

Create `apps/api/app/Modules/Product/Presentation/Controllers/EnrichmentReviewController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Controllers;

use App\Modules\Product\Application\DTOs\EnrichmentResultData;
use App\Modules\Product\Application\Services\EnrichmentReviewService;
use App\Modules\Product\Domain\EnrichmentResult;
use App\Modules\Product\Domain\Enums\EnrichmentReviewStatus;
use App\Modules\Product\Presentation\Requests\AcceptEnrichmentRequest;
use App\Modules\Product\Presentation\Requests\RejectEnrichmentRequest;
use App\Services\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class EnrichmentReviewController extends Controller
{
    public function __construct(
        private readonly EnrichmentReviewService $reviewService,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user?->can('enrichment.view')) {
            abort(403);
        }

        $company = $this->companyContext->requireCompany();

        $status = $request->query('status')
            ? EnrichmentReviewStatus::tryFrom((string) $request->query('status'))
            : null;
        $quality = $request->query('quality') ? (string) $request->query('quality') : null;

        $results = $this->reviewService->listForReview(
            $company->tenant_id,
            $company->id,
            $status,
            $quality,
        );

        return response()->json([
            'data' => $results->items(),
            'meta' => [
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total(),
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-Id', (string) Str::uuid()),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (! $user?->can('enrichment.view')) {
            abort(403);
        }

        $company = $this->companyContext->requireCompany();

        $result = EnrichmentResult::where('company_id', $company->id)
            ->where('id', $id)
            ->with('product:id,name,barcode,sku')
            ->firstOrFail();

        return response()->json([
            'data' => $result,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-Id', (string) Str::uuid()),
            ],
        ]);
    }

    public function accept(AcceptEnrichmentRequest $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (! $user?->can('enrichment.review')) {
            abort(403);
        }

        $company = $this->companyContext->requireCompany();

        $enrichmentResult = EnrichmentResult::where('company_id', $company->id)
            ->where('id', $id)
            ->with('product')
            ->firstOrFail();

        /** @var list<string> $acceptedFields */
        $acceptedFields = $request->validated('accepted_fields');

        $this->reviewService->accept($enrichmentResult, $acceptedFields, $user->id);

        return response()->json([
            'data' => ['status' => 'accepted'],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-Id', (string) Str::uuid()),
            ],
        ]);
    }

    public function reject(RejectEnrichmentRequest $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (! $user?->can('enrichment.review')) {
            abort(403);
        }

        $company = $this->companyContext->requireCompany();

        $enrichmentResult = EnrichmentResult::where('company_id', $company->id)
            ->where('id', $id)
            ->with('product')
            ->firstOrFail();

        $this->reviewService->reject(
            $enrichmentResult,
            $user->id,
            $request->validated('reason'),
        );

        return response()->json([
            'data' => ['status' => 'rejected'],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-Id', (string) Str::uuid()),
            ],
        ]);
    }
}
```

- [ ] **Step 3: Add enrichment routes to Product routes.php**

In `apps/api/app/Modules/Product/routes.php`, add these routes inside the existing `module:Inventory` gated group. Add the import at the top and the routes after the existing product routes (after the product images section):

Add import:
```php
use App\Modules\Product\Presentation\Controllers\EnrichmentReviewController;
```

Add routes inside the module-gated group:

```php
// Enrichment Review
Route::middleware('can:enrichment.view')->group(function () {
    Route::get('/enrichment-results', [EnrichmentReviewController::class, 'index'])
        ->name('enrichment.index');
    Route::get('/enrichment-results/{id}', [EnrichmentReviewController::class, 'show'])
        ->name('enrichment.show');
});

Route::middleware('can:enrichment.review')->group(function () {
    Route::post('/enrichment-results/{id}/accept', [EnrichmentReviewController::class, 'accept'])
        ->name('enrichment.accept');
    Route::post('/enrichment-results/{id}/reject', [EnrichmentReviewController::class, 'reject'])
        ->name('enrichment.reject');
});
```

- [ ] **Step 4: Write feature test**

Create `apps/api/tests/Feature/Modules/Product/EnrichmentReviewControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Product;

use App\Modules\Product\Application\DTOs\EnrichedProductData;
use App\Modules\Product\Domain\EnrichmentResult;
use App\Modules\Product\Domain\Enums\EnrichmentReviewStatus;
use App\Modules\Product\Domain\Enums\EnrichmentStatus;
use App\Modules\Product\Domain\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrichmentReviewControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_index_returns_paginated_enrichment_results(): void
    {
        $user = $this->createAuthenticatedUser(['enrichment.view']);
        $product = Product::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $user->company_id,
        ]);

        EnrichmentResult::create([
            'tenant_id' => $product->tenant_id,
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'tracking_id' => 'track-list-1',
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => $this->makeSampleEnrichedData(),
            'enrichment_quality' => 'high',
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/enrichment-results');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'meta' => ['total', 'per_page']]);
    }

    public function test_accept_merges_fields_into_product(): void
    {
        $user = $this->createAuthenticatedUser(['enrichment.view', 'enrichment.review']);
        $product = Product::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $user->company_id,
            'name' => 'Old Name',
            'enrichment_status' => EnrichmentStatus::Completed,
            'platform_submission_id' => 'track-accept-1',
        ]);

        $result = EnrichmentResult::create([
            'tenant_id' => $product->tenant_id,
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'tracking_id' => 'track-accept-1',
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => $this->makeSampleEnrichedData('New Enriched Name'),
            'enrichment_quality' => 'high',
        ]);

        $response = $this->actingAs($user)->postJson(
            "/api/v1/enrichment-results/{$result->id}/accept",
            ['accepted_fields' => ['name']],
        );

        $response->assertStatus(200);

        $product->refresh();
        $this->assertSame('New Enriched Name', $product->name);
    }

    public function test_reject_updates_status(): void
    {
        $user = $this->createAuthenticatedUser(['enrichment.view', 'enrichment.review']);
        $product = Product::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $user->company_id,
            'enrichment_status' => EnrichmentStatus::Completed,
            'platform_submission_id' => 'track-reject-1',
        ]);

        $result = EnrichmentResult::create([
            'tenant_id' => $product->tenant_id,
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'tracking_id' => 'track-reject-1',
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => $this->makeSampleEnrichedData(),
            'enrichment_quality' => 'medium',
        ]);

        $response = $this->actingAs($user)->postJson(
            "/api/v1/enrichment-results/{$result->id}/reject",
            ['reason' => 'Data is wrong'],
        );

        $response->assertStatus(200);

        $result->refresh();
        $this->assertSame(EnrichmentReviewStatus::Rejected, $result->status);
        $this->assertSame('Data is wrong', $result->rejection_reason);
    }

    public function test_unauthorized_user_gets_403(): void
    {
        $user = $this->createAuthenticatedUser([]); // no enrichment permissions

        $response = $this->actingAs($user)->getJson('/api/v1/enrichment-results');

        $response->assertStatus(403);
    }

    private function makeSampleEnrichedData(string $name = 'Enriched Product'): EnrichedProductData
    {
        return new EnrichedProductData(
            name: $name,
            brand: 'Test Brand',
            description: 'Test description',
            classification: [],
            ingredients: [],
            images: [],
            confidence_score: 85,
            enrichment_tier: 'high',
            field_confidence: null,
            enrichment_sources: null,
            assigned_barcode: null,
            assigned_barcode_type: null,
        );
    }

    /**
     * Helper to create an authenticated user with specific permissions.
     * Adjust this to match your existing test helper pattern.
     *
     * @param  list<string>  $permissions
     */
    private function createAuthenticatedUser(array $permissions): \App\Modules\Identity\Domain\User
    {
        // This should use your existing test setup pattern.
        // Create user, assign tenant/company context, assign permissions.
        // The exact implementation depends on your test helpers.
        $user = \App\Modules\Identity\Domain\User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }
}
```

Note: The `createAuthenticatedUser` helper will need adjustment to match the project's existing test setup patterns (tenant/company context, sanctum auth). Check existing feature tests for the pattern.

- [ ] **Step 5: Run tests**

Run: `cd apps/api && php artisan test --filter=EnrichmentReviewControllerTest`
Expected: All 4 tests PASS (may need test helper adjustments).

- [ ] **Step 6: Commit**

```bash
git add apps/api/app/Modules/Product/Presentation/ apps/api/app/Modules/Product/routes.php apps/api/tests/Feature/Modules/Product/EnrichmentReviewControllerTest.php
git commit -m "feat(enrichment): add review controller, routes, and FormRequests

EnrichmentReviewController with index, show, accept, reject endpoints.
Permission-gated routes under the Inventory module group."
```

---

## Task 11: Polling Command, Permissions, Schedule

**Files:**
- Create: `apps/api/app/Modules/PlatformIntegration/Application/Commands/CheckPendingEnrichmentsCommand.php`
- Modify: `apps/api/routes/console.php`
- Modify: `apps/api/database/seeders/RolesAndPermissionsSeeder.php`

- [ ] **Step 1: Create the polling command**

Create `apps/api/app/Modules/PlatformIntegration/Application/Commands/CheckPendingEnrichmentsCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\Commands;

use App\Modules\Product\Domain\Enums\EnrichmentStatus;
use App\Modules\Product\Domain\Events\EnrichmentWebhookReceived;
use App\Modules\Product\Domain\Product;
use App\Modules\PlatformIntegration\Application\Services\ProductSubmissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class CheckPendingEnrichmentsCommand extends Command
{
    protected $signature = 'enrichment:check-pending';

    protected $description = 'Poll platform for stuck enrichment submissions (fallback for failed webhooks)';

    public function __construct(
        private readonly ProductSubmissionService $submissionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $staleProducts = Product::query()
            ->whereIn('enrichment_status', [
                EnrichmentStatus::Pending,
                EnrichmentStatus::Enriching,
            ])
            ->whereNotNull('platform_submission_id')
            ->where('updated_at', '<', now()->subMinutes(10))
            ->limit(50)
            ->get();

        if ($staleProducts->isEmpty()) {
            $this->info('No stale enrichment submissions found.');

            return self::SUCCESS;
        }

        $this->info("Checking {$staleProducts->count()} stale submissions...");

        foreach ($staleProducts as $product) {
            try {
                $statusData = $this->submissionService->checkStatus($product->platform_submission_id);

                if ($statusData === null) {
                    continue;
                }

                $platformStatus = $statusData['status'] ?? null;
                if ($platformStatus === null) {
                    continue;
                }

                $currentErpStatus = EnrichmentStatus::fromPlatformStatus($platformStatus);

                // Only dispatch if status actually changed
                if ($currentErpStatus !== $product->enrichment_status) {
                    EnrichmentWebhookReceived::dispatch(
                        trackingId: $product->platform_submission_id,
                        status: $platformStatus,
                        enrichmentQuality: $statusData['enrichment_quality'] ?? null,
                        hasBarcodeAssigned: isset($statusData['assigned_barcode']),
                        vertical: $statusData['vertical'] ?? '',
                    );

                    $this->info("  Updated: {$product->name} ({$product->platform_submission_id})");
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to check enrichment status', [
                    'product_id' => $product->id,
                    'tracking_id' => $product->platform_submission_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Register in scheduler**

In `apps/api/routes/console.php`, add after line 31:

```php

// Schedule: Check for stale enrichment submissions every 15 minutes
Schedule::command('enrichment:check-pending')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
```

- [ ] **Step 3: Add enrichment permissions to seeder**

In `apps/api/database/seeders/RolesAndPermissionsSeeder.php`, add these permissions to the `$permissions` array in the `createPermissions()` method, after the existing product permissions (after `'products.import'`):

```php
// Enrichment
'enrichment.view',
'enrichment.review',
'enrichment.submit',
```

Then in the `createRoles()` method, add `'enrichment.view'`, `'enrichment.review'`, `'enrichment.submit'` to the admin and manager role permission lists, and `'enrichment.submit'` to the operator role (or whichever role maps to inventory_manager in the existing seeder).

- [ ] **Step 4: Run seeder to verify**

Run: `cd apps/api && php artisan db:seed --class=RolesAndPermissionsSeeder`
Expected: No errors. Permissions created successfully.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/PlatformIntegration/Application/Commands/CheckPendingEnrichmentsCommand.php apps/api/routes/console.php apps/api/database/seeders/RolesAndPermissionsSeeder.php
git commit -m "feat(enrichment): add polling command, permissions, and schedule

CheckPendingEnrichmentsCommand polls stale submissions every 15 min.
Enrichment permissions added to seeder for admin/manager/operator roles."
```

---

## Task 12: Final Verification

- [ ] **Step 1: Run PHPStan**

Run: `cd apps/api && ./vendor/bin/phpstan analyse --level=8`
Expected: Zero errors (fix any type issues found).

- [ ] **Step 2: Run Pint**

Run: `cd apps/api && ./vendor/bin/pint`
Expected: Code style applied.

- [ ] **Step 3: Run full test suite**

Run: `cd apps/api && php artisan test`
Expected: All tests pass including existing tests (no regressions).

- [ ] **Step 4: Generate TypeScript types**

Run: `cd apps/api && php artisan typescript:transform`
Expected: Types generated for `EnrichedProductData`, `BarcodeLookupResultData`, `SubmissionResultData`, `EnrichmentResultData`.

- [ ] **Step 5: Verify route list**

Run: `cd apps/api && php artisan route:list --name=enrichment`
Expected: Enrichment routes listed with correct middleware.

Run: `cd apps/api && php artisan route:list --name=webhook`
Expected: Webhook route listed WITHOUT auth:sanctum.

- [ ] **Step 6: Commit any fixes**

```bash
git add -A
git commit -m "chore: fix static analysis and code style issues"
```
