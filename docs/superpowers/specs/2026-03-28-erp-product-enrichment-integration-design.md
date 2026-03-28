# ERP-Side Product Enrichment Integration — Design Spec

> **Sub-project 1 of 3:** Backend Foundation
> **Date:** 2026-03-28
> **Platform spec:** `docs/superpowers/specs/2026-03-27-universal-product-entry-and-enrichment-design.md`
> **Depends on:** Platform branch `feat/universal-product-entry-platform`

---

## 1. Scope & Responsibilities

### What the ERP does (thin client)

- Sends barcode lookups to the platform
- Submits products for enrichment (with photos via presigned URLs)
- Receives webhook notifications when enrichment completes
- Stores enrichment results locally for the review UX
- Lets the user accept/reject enriched fields and merges accepted data into the local product
- Polls the platform as a fallback if webhooks fail
- Sends in-app notifications to the user

### What the ERP does NOT do

- No enrichment logic (quality scoring, source resolution, AI inference — platform only)
- No barcode assignment logic (619/299 prefix — platform only)
- No cross-tenant deduplication (platform only)
- No enrichment pipeline orchestration (platform only)

The ERP is a consumer of the platform's enrichment API. It delegates all data intelligence to the platform and focuses on local UX and product data management.

---

## 2. Module Boundaries

### PlatformIntegration module (Infrastructure concern)

Owns all HTTP communication with the Syneriva platform. No business logic — just transport, resilience, and payload mapping.

**Layers:**

| Layer | Contents |
|-------|----------|
| Domain/ValueObjects | `PlatformProductData` — readonly mapping of platform lookup response |
| Domain/Enums | `PlatformLookupStatus` (existing) |
| Application/DTOs | `ProductSubmissionData`, `SubmissionResultData`, `EnrichmentWebhookPayload` |
| Application/Services | `BarcodeLookupService` (refactored), `ProductSubmissionService` (new) |
| Application/Commands | `CheckPendingEnrichmentsCommand` (new) |
| Application/Jobs | `ProcessEnrichmentWebhookJob` (new) |
| Infrastructure/Http | `PlatformHttpClient` (extended with `postRaw`/`getRaw`) |
| Infrastructure/Middleware | `VerifySynerivaWebhookSignature` (new) |
| Presentation/Controllers | `BarcodeLookupController` (updated), `EnrichmentWebhookController` (new) |

### Product module (Domain concern)

Owns enrichment results, review logic, and the merge of accepted fields into local products.

**Layers:**

| Layer | Contents |
|-------|----------|
| Domain/EnrichmentResult | `EnrichmentResult` Eloquent model |
| Domain/Enums | `EnrichmentStatus`, `EnrichmentReviewStatus` |
| Domain/Events | `EnrichmentWebhookReceived` |
| Application/DTOs | `EnrichmentResultData`, `EnrichedProductData` (typed DTO for JSONB) |
| Application/Services | `EnrichmentReviewService` |
| Application/Listeners | `ProcessEnrichmentEventListener` |
| Application/Notifications | `EnrichmentCompletedNotification` |
| Presentation/Controllers | `EnrichmentReviewController` |
| Presentation/Requests | `AcceptEnrichmentRequest`, `RejectEnrichmentRequest` |

### Cross-module communication

`PlatformIntegration` dispatches `EnrichmentWebhookReceived` event (from `ProcessEnrichmentWebhookJob`).
`Product` module's `ProcessEnrichmentEventListener` listens and calls `EnrichmentReviewService::fetchAndStore()`.
Wired in `ProductServiceProvider` via `Event::listen()` (matches `InventoryServiceProvider` pattern).

No direct model imports across modules. The event carries only primitive data (tracking_id, status, quality, barcode, vertical).

---

## 3. Data Model

### 3.1 Migration: Add columns to `products` table

```sql
ALTER TABLE products ADD COLUMN platform_product_id UUID NULL;
ALTER TABLE products ADD COLUMN platform_submission_id UUID NULL;
ALTER TABLE products ADD COLUMN enrichment_status VARCHAR(20) NULL;

CREATE INDEX idx_products_enrichment ON products (tenant_id, enrichment_status)
    WHERE enrichment_status IS NOT NULL;
```

- `platform_product_id` — links to the platform's canonical product (set when lookup finds a match, or after enrichment accept with barcode assignment)
- `platform_submission_id` — tracking ID for a pending enrichment submission (set on submit, cleared on accept/reject)
- `enrichment_status` — backed by `EnrichmentStatus` enum

### 3.2 New enum: `EnrichmentStatus`

Location: `Product/Domain/Enums/EnrichmentStatus.php`

```php
enum EnrichmentStatus: string
{
    case Pending = 'pending';
    case Enriching = 'enriching';
    case Completed = 'completed';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case NotEnrichable = 'not_enrichable';
}
```

**Platform status mapping:**

| Platform API status | ERP `enrichment_status` | Meaning |
|---------------------|------------------------|---------|
| `submitted` | `pending` | Received by platform, not yet processed |
| `enriching` | `enriching` | Platform enrichment pipeline running |
| `enriched` | `completed` | Platform review done, ready for tenant review |
| `approved` | `completed` | Canonical product created, ready for tenant review |
| `rejected` | `rejected` | Platform rejected the submission |
| `failed` | `failed` | Infrastructure error, retriable |
| `not_enrichable` | `not_enrichable` | No data available |

### 3.3 New enum: `EnrichmentReviewStatus`

Location: `Product/Domain/Enums/EnrichmentReviewStatus.php`

```php
enum EnrichmentReviewStatus: string
{
    case PendingReview = 'pending_review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
```

### 3.4 New table: `enrichment_results`

```sql
CREATE TABLE enrichment_results (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    company_id UUID NOT NULL REFERENCES companies(id),
    product_id UUID NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    tracking_id UUID NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending_review',
    enriched_data JSONB NOT NULL,
    enrichment_quality VARCHAR(10) NOT NULL,  -- high, medium, low
    assigned_barcode VARCHAR(50) NULL,
    reviewed_at TIMESTAMP NULL,
    reviewed_by UUID NULL REFERENCES users(id),
    accepted_fields JSONB NULL,
    rejection_reason TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_enrichment_results_product ON enrichment_results (product_id);
CREATE INDEX idx_enrichment_results_status ON enrichment_results (tenant_id, company_id, status);
CREATE UNIQUE INDEX idx_enrichment_results_tracking ON enrichment_results (tracking_id);
```

### 3.5 Typed DTO for `enriched_data` JSONB

Location: `Product/Application/DTOs/EnrichedProductData.php`

Per CLAUDE.md rule 3 (JSONB columns must have a corresponding PHP DTO):

```php
class EnrichedProductData extends Data
{
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

---

## 4. PlatformIntegration Module Changes

### 4.1 PlatformHttpClient — new raw methods

The platform's ProductLookup endpoints return responses without a `data` wrapper (unlike the existing Catalog endpoints). Add methods that return the full response body:

```php
public function postRaw(string $path, array $data): ?array
// Same resilience (circuit breaker, retry, timeout) as post()
// Returns $response->json() instead of $response->json('data')

public function getRaw(string $path, array $queryParams = []): ?array
// Same resilience as get()
// Returns $response->json() instead of $response->json('data')
```

Existing `get()` and `post()` methods remain unchanged — they serve the Catalog browse endpoints that use the `{ data: ... }` wrapper.

### 4.2 Vertical mapping — new method on ERP `Vertical` enum

Location: `app/Enums/Vertical.php`

```php
public function platformVertical(): ?string
{
    return match ($this) {
        self::Mechanic, self::BodyShop, self::PartsRetailer,
        self::CarGlass, self::TireShop, self::ServiceStation => 'automotive',
        self::Parapharmacy => 'parapharmacy',
        self::Pharmacy => 'pharmacy',
        default => null,
    };
}
```

Returns `null` for verticals without a platform equivalent (retail, restaurant, coffee_shop, fashion). The lookup/submit services must check for null and return an appropriate error rather than calling the platform.

### 4.3 PlatformProductData value object (replaces PlatformArticle for lookup)

Location: `PlatformIntegration/Domain/ValueObjects/PlatformProductData.php`

Matches the actual `LookupProductResource` response shape from the platform:

```php
final readonly class PlatformProductData
{
    public function __construct(
        public string $id,
        public string $barcode,
        public string $name,
        public ?string $brand,
        public ?string $description,
        public array $classification,    // array<string, mixed>
        public array $ingredients,       // list<array{name: string, position: int}>
        public array $images,            // list<array{url: ?string, thumbnail: ?string, type: ?string}>
        public int $confidenceScore,
        public ?string $enrichmentTier,
    ) {}

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
            confidenceScore: $data['confidence_score'] ?? 0,
            enrichmentTier: $data['enrichment_tier'] ?? null,
        );
    }
}
```

`PlatformArticle` VO remains in place — it is still used by `CatalogBrowseService` for automotive article responses (different API shape). No shared base class; they map different APIs.

### 4.4 BarcodeLookupService — refactored

**Breaking change:** Calls `POST /api/v1/products/lookup` instead of `GET /api/v1/automotive/articles/barcode/{barcode}`.

```php
final class BarcodeLookupService
{
    public function __construct(
        private readonly PlatformHttpClient $client,
        private readonly CompanyContext $companyContext,
        private readonly CacheManager $cache,
    ) {}

    public function lookup(string $barcode, ?string $vertical = null): BarcodeLookupResultData
    {
        // 1. Resolve vertical
        $company = $this->companyContext->requireCompany();
        $resolvedVertical = $vertical ?? $company->tenant->vertical->platformVertical();
        if ($resolvedVertical === null) {
            return BarcodeLookupResultData::error($barcode, 'vertical_not_supported');
        }

        // 2. Normalize barcode (trim, UPC-12→EAN-13, check digit validation)
        $normalized = $this->normalizeBarcode($barcode);

        // 3. Check cache
        $cacheKey = "platform:lookup:{$resolvedVertical}:{$normalized}";
        // ... (same cache pattern as current, 1hr TTL)

        // 4. Check circuit breaker
        if ($this->client->isCircuitOpen()) {
            return BarcodeLookupResultData::error($normalized, 'platform_unavailable');
        }

        // 5. Call platform
        $response = $this->client->postRaw('/api/v1/products/lookup', [
            'barcode' => $normalized,
            'vertical' => $resolvedVertical,
        ]);

        // 6. Map response
        if ($response === null) {
            return BarcodeLookupResultData::error($normalized, 'platform_error');
        }

        if ($response['status'] === 'found') {
            $product = PlatformProductData::fromApiResponse($response['product']);
            return BarcodeLookupResultData::found($normalized, $product);
        }

        // not_found — may include tracking_id for enrichment
        return BarcodeLookupResultData::notFound(
            $normalized,
            trackingId: $response['tracking_id'] ?? null,
        );
    }
}
```

**BarcodeLookupResultData update:**

```php
class BarcodeLookupResultData extends Data
{
    public function __construct(
        public string $status,              // found, not_found, error
        public ?string $barcode,
        public ?PlatformProductData $product,  // replaces ?array $article
        public ?string $trackingId,            // new — for enrichment flow
        public ?array $suggestedProduct,       // built from PlatformProductData
        public ?string $errorReason,
    ) {}
}
```

**Barcode normalization** gains EAN-13 check digit validation:

```php
private function normalizeBarcode(string $barcode): string
{
    $barcode = trim($barcode);
    $barcode = preg_replace('/[^a-zA-Z0-9]/', '', $barcode);

    // UPC-12 to EAN-13
    if (strlen($barcode) === 12 && ctype_digit($barcode)) {
        $barcode = '0' . $barcode;
    }

    // EAN-13 check digit validation
    if (strlen($barcode) === 13 && ctype_digit($barcode)) {
        $this->validateEan13CheckDigit($barcode);
    }

    return $barcode;
}
```

### 4.5 ProductSubmissionService — new

Location: `PlatformIntegration/Application/Services/ProductSubmissionService.php`

```php
final class ProductSubmissionService
{
    public function __construct(
        private readonly PlatformHttpClient $client,
        private readonly CompanyContext $companyContext,
    ) {}

    public function requestUploadUrl(
        string $filename,
        string $contentType,
        int $sizeBytes
    ): array
    // Calls POST /api/v1/products/upload-url via postRaw()
    // Returns ['photo_id' => string, 'upload_url' => string, 'expires_at' => string]

    public function uploadPhoto(
        string $uploadUrl,
        string $fileContents,
        string $contentType
    ): void
    // Direct HTTP PUT to presigned MinIO URL (NOT through PlatformHttpClient)
    // Uses Laravel Http facade directly — no circuit breaker (different host)

    public function submit(
        ProductSubmissionData $data
    ): SubmissionResultData
    // Generates Idempotency-Key (UUID v4)
    // Calls POST /api/v1/products/submit via postRaw()
    // Returns SubmissionResultData with tracking_id, status, status_url

    public function bulkSubmit(
        string $vertical,
        array $submissions,
        bool $autoEnrich = true
    ): array
    // Calls POST /api/v1/products/bulk-submit via postRaw()
    // Returns array with job_id, total, submissions list

    public function bulkLookup(
        array $barcodes,
        string $vertical
    ): array
    // Calls POST /api/v1/products/bulk-lookup via postRaw()
    // Returns ['found' => PlatformProductData[], 'not_found' => [...]]

    public function checkStatus(string $trackingId): ?array
    // Calls GET /api/v1/products/lookup-status/{trackingId} via getRaw()
    // Returns raw response array (tracking_id, status, enriched_data, etc.)

    public function triggerEnrichment(string $trackingId): SubmissionResultData
    // Calls POST /api/v1/products/{trackingId}/enrich via postRaw()

    public function getCategoryAttributes(
        string $vertical,
        string $category
    ): array
    // Calls GET /api/v1/verticals/{vertical}/categories/{category}/attributes via getRaw()
    // Note: different URL prefix from /products/
}
```

### 4.6 DTOs

**ProductSubmissionData** — `PlatformIntegration/Application/DTOs/ProductSubmissionData.php`

```php
class ProductSubmissionData extends Data
{
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

**SubmissionResultData** — `PlatformIntegration/Application/DTOs/SubmissionResultData.php`

```php
class SubmissionResultData extends Data
{
    public function __construct(
        public string $trackingId,
        public string $status,
        public string $statusUrl,
    ) {}
}
```

**EnrichmentWebhookPayload** — `PlatformIntegration/Application/DTOs/EnrichmentWebhookPayload.php`

```php
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

    public static function fromWebhook(array $payload): self
}
```

### 4.7 Webhook receiver

**Route** (in `PlatformIntegration/Presentation/routes.php`):

```php
// Separate route group — NO auth:sanctum (platform calls this, not a user)
Route::middleware(['api', VerifySynerivaWebhookSignature::class])
    ->prefix('api/v1/webhooks')
    ->group(function () {
        Route::post('/syneriva', EnrichmentWebhookController::class);
    });
```

**VerifySynerivaWebhookSignature middleware** — `PlatformIntegration/Infrastructure/Middleware/VerifySynerivaWebhookSignature.php`

```php
final class VerifySynerivaWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Syneriva-Signature');
        $timestamp = $request->header('X-Syneriva-Timestamp');
        $secret = config('services.platform.webhook_secret');

        // 1. Reject if missing headers
        // 2. Reject if timestamp older than 5 minutes (replay protection)
        // 3. Compute expected: hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $secret)
        // 4. Timing-safe comparison: hash_equals('sha256=' . $expected, $signature)
        // 5. Reject with 403 if mismatch
    }
}
```

**EnrichmentWebhookController** — `PlatformIntegration/Presentation/Controllers/EnrichmentWebhookController.php`

```php
final class EnrichmentWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = EnrichmentWebhookPayload::fromWebhook($request->json()->all());

        ProcessEnrichmentWebhookJob::dispatch($payload)
            ->onQueue('enrichment');

        return response()->json(['received' => true], 200);
    }
}
```

Returns `200` immediately. Processing is async.

**ProcessEnrichmentWebhookJob** — `PlatformIntegration/Application/Jobs/ProcessEnrichmentWebhookJob.php`

```php
final class ProcessEnrichmentWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly EnrichmentWebhookPayload $payload,
    ) {}

    public function handle(): void
    {
        // Handles both 'enrichment.resolved' and 'enrichment.batch_resolved'
        // For single: dispatch one EnrichmentWebhookReceived event
        // For batch: dispatch one event per item
        EnrichmentWebhookReceived::dispatch(
            trackingId: $this->payload->trackingId,
            status: $this->payload->status,
            enrichmentQuality: $this->payload->enrichmentQuality,
            assignedBarcode: $this->payload->hasBarcodeAssigned,
            vertical: $this->payload->vertical,
        );
    }
}
```

### 4.8 Polling fallback command

Location: `PlatformIntegration/Application/Commands/CheckPendingEnrichmentsCommand.php`

```php
final class CheckPendingEnrichmentsCommand extends Command
{
    protected $signature = 'enrichment:check-pending';
    protected $description = 'Poll platform for stuck enrichment submissions';

    public function __construct(
        private readonly ProductSubmissionService $submissionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Query products where enrichment_status IN (pending, enriching)
        // AND platform_submission_id IS NOT NULL
        // AND updated_at < now() - 10 minutes (avoid racing with webhooks)
        // For each: call checkStatus(), dispatch EnrichmentWebhookReceived if status changed
    }
}
```

Registered in `routes/console.php`:
```php
Schedule::command('enrichment:check-pending')->everyFifteenMinutes();
```

### 4.9 Config addition

```php
// config/services.php — add to existing 'platform' key
'platform' => [
    'url' => env('SYNERIVA_PLATFORM_URL', 'http://localhost:8080'),
    'api_key' => env('SYNERIVA_PLATFORM_API_KEY'),
    'webhook_secret' => env('SYNERIVA_WEBHOOK_SECRET'),  // new
],
```

---

## 5. Product Module Changes

### 5.1 EnrichmentResult model

Location: `Product/Domain/EnrichmentResult.php`

```php
final class EnrichmentResult extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'company_id', 'product_id', 'tracking_id',
        'status', 'enriched_data', 'enrichment_quality',
        'assigned_barcode', 'reviewed_at', 'reviewed_by',
        'accepted_fields', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => EnrichmentReviewStatus::class,
            'enriched_data' => EnrichedProductData::class,
            'accepted_fields' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    // Relationships: product(), reviewer()
}
```

### 5.2 EnrichmentWebhookReceived event

Location: `Product/Domain/Events/EnrichmentWebhookReceived.php`

```php
final class EnrichmentWebhookReceived
{
    use Dispatchable;

    public function __construct(
        public readonly string $trackingId,
        public readonly string $status,
        public readonly ?string $enrichmentQuality,
        public readonly bool $assignedBarcode,
        public readonly string $vertical,
    ) {}
}
```

### 5.3 ProcessEnrichmentEventListener

Location: `Product/Application/Listeners/ProcessEnrichmentEventListener.php`

```php
final class ProcessEnrichmentEventListener
{
    public function __construct(
        private readonly EnrichmentReviewService $reviewService,
    ) {}

    public function handle(EnrichmentWebhookReceived $event): void
    {
        // 1. Find product by platform_submission_id = event.trackingId
        // 2. If not found, log and return (orphan webhook)
        // 3. Call reviewService->fetchAndStore(event.trackingId, product)
        // 4. Update product.enrichment_status based on event.status (using mapping)
        // 5. Dispatch EnrichmentCompletedNotification to product's company users
    }
}
```

### 5.4 EnrichmentReviewService

Location: `Product/Application/Services/EnrichmentReviewService.php`

```php
final class EnrichmentReviewService
{
    public function __construct(
        private readonly ProductSubmissionService $submissionService,
    ) {}

    public function fetchAndStore(string $trackingId, Product $product): EnrichmentResult
    {
        // 1. Call submissionService->checkStatus(trackingId)
        // 2. Create/update EnrichmentResult record:
        //    - product_id, tenant_id, company_id from product
        //    - enriched_data cast to EnrichedProductData
        //    - enrichment_quality, assigned_barcode from response
        //    - status = pending_review
        // 3. Return the result
    }

    public function accept(
        EnrichmentResult $enrichmentResult,
        array $acceptedFields,
        string $reviewedBy
    ): void
    {
        // 1. Load the product via enrichmentResult->product
        // 2. Build update array from accepted fields:
        //    - For each field in acceptedFields, take value from enriched_data
        //    - Map platform fields to product columns (name, brand→supplier, description, barcode)
        // 3. Update product directly: $product->update($acceptedFields)
        // 4. If enriched_data has vertical-specific metadata (ingredients, classification),
        //    sync via existing relationship methods (parapharmacyMetadata, automotiveMetadata)
        // 5. If assigned_barcode present and 'barcode' in acceptedFields, set product.barcode
        // 6. Set product.platform_product_id = enriched_data.id (if available)
        // 7. Set product.enrichment_status = null (enrichment cycle complete)
        // 8. Clear product.platform_submission_id
        // 9. Update enrichment_result: status=accepted, reviewed_at, reviewed_by, accepted_fields
    }

    public function reject(
        EnrichmentResult $enrichmentResult,
        string $reviewedBy,
        ?string $reason = null
    ): void
    {
        // 1. Update enrichment_result: status=rejected, reviewed_at, reviewed_by, rejection_reason
        // 2. Update product.enrichment_status = rejected
        // 3. Clear product.platform_submission_id
    }

    public function listForReview(
        string $tenantId,
        string $companyId,
        ?EnrichmentReviewStatus $status = null,
        ?string $quality = null
    ): LengthAwarePaginator
    {
        // Paginated query with optional filters
        // Eager-loads product (name, barcode, sku)
        // Ordered by created_at desc
    }
}
```

### 5.5 EnrichmentReviewController

Location: `Product/Presentation/Controllers/EnrichmentReviewController.php`

```php
final class EnrichmentReviewController extends Controller
{
    public function __construct(
        private readonly EnrichmentReviewService $reviewService,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    // Permission: enrichment.view
    // Paginated, filterable by status and quality

    public function show(string $id): JsonResponse
    // Permission: enrichment.view
    // Returns full enrichment result with product data

    public function accept(AcceptEnrichmentRequest $request, string $id): JsonResponse
    // Permission: enrichment.review
    // Payload: { accepted_fields: string[] }

    public function reject(RejectEnrichmentRequest $request, string $id): JsonResponse
    // Permission: enrichment.review
    // Payload: { reason?: string }
}
```

**Routes** (in `Product/routes.php`, inside existing authenticated group):

```php
Route::middleware('can:enrichment.view')->group(function () {
    Route::get('/enrichment-results', [EnrichmentReviewController::class, 'index']);
    Route::get('/enrichment-results/{id}', [EnrichmentReviewController::class, 'show']);
});

Route::middleware('can:enrichment.review')->group(function () {
    Route::post('/enrichment-results/{id}/accept', [EnrichmentReviewController::class, 'accept']);
    Route::post('/enrichment-results/{id}/reject', [EnrichmentReviewController::class, 'reject']);
});
```

### 5.6 FormRequests

**AcceptEnrichmentRequest:**
```php
public function rules(): array
{
    return [
        'accepted_fields' => ['required', 'array', 'min:1'],
        'accepted_fields.*' => ['required', 'string'],
    ];
}
```

**RejectEnrichmentRequest:**
```php
public function rules(): array
{
    return [
        'reason' => ['nullable', 'string', 'max:1000'],
    ];
}
```

### 5.7 EnrichmentCompletedNotification

Location: `Product/Application/Notifications/EnrichmentCompletedNotification.php`

Uses `database` channel for in-app notification bell. Carries: product name, enrichment quality, whether a barcode was assigned.

### 5.8 Event-listener wiring

In `ProductServiceProvider::boot()`:

```php
Event::listen(
    EnrichmentWebhookReceived::class,
    ProcessEnrichmentEventListener::class,
);
```

---

## 6. Permissions

New permissions to register via the permissions seeder:

| Permission | Description |
|------------|-------------|
| `enrichment.view` | View enrichment results and queue |
| `enrichment.review` | Accept or reject enrichment results |
| `enrichment.submit` | Submit products for enrichment |

Assigned to `admin` and `manager` roles by default. `enrichment.submit` also assigned to `inventory_manager`.

---

## 7. Queue Configuration

`ProcessEnrichmentWebhookJob` runs on the `enrichment` queue. This queue should be added to Horizon's supervisor config to ensure dedicated processing separate from the default queue.

---

## 8. Testing Strategy

### Unit tests

- `BarcodeLookupServiceTest` — verify normalization, EAN-13 check digit validation, cache behavior, vertical resolution, circuit breaker graceful degradation
- `ProductSubmissionServiceTest` — verify submit payload construction, idempotency key generation, status mapping
- `EnrichmentReviewServiceTest` — verify accept merges correct fields, reject updates statuses, listForReview scoping
- `VerifySynerivaWebhookSignatureTest` — valid signature passes, invalid rejects, expired timestamp rejects, missing headers reject
- `EnrichedProductData` cast — verify JSONB round-trip

### Integration tests

- `EnrichmentWebhookController` — full webhook flow: signed request → job dispatched → event fired → enrichment result created → notification sent
- `EnrichmentReviewController` — accept/reject with permission checks, verify product data actually updated
- `CheckPendingEnrichmentsCommand` — verify only stale submissions are polled

### What NOT to test

- Platform API behavior (that's the platform's test suite)
- PlatformHttpClient internals (already tested, unchanged)

---

## 9. Out of Scope (Sub-projects 2 & 3)

The following are NOT part of this spec and will be designed separately:

- **Frontend: BarcodeLookupInput component** — scanner detection, debounce, product form integration
- **Frontend: useBarcodeLookup and useProductSubmission hooks** — TanStack Query, photo upload flow
- **Frontend: Enrichment review UI** — side-by-side view, field-level accept/reject, batch queue
- **Frontend: Notification bell integration** — badge count for enrichment notifications

These depend on the backend endpoints defined here.
