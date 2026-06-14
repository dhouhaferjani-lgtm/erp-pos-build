# POS Offline Variant Support — Implementation Plan (Spec A)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the offline-first POS support variants offline — sync the variant catalog into an indexed local SQLite table, rewire the picker to read local-first, and resolve a scanned variant barcode directly to the exact variant (Square-style, offline), fixing the variant-product scan bug.

**Architecture:** A new `GET /pos/variants` feed (company-scoped, delta cursor like `/pos/stock-levels`, tombstones like the `/products` pull) implemented behind a Shared contract (mirroring how `PosStockLevelController` uses `LocationStockReader`). On the POS: a `product_variants` SQLite table (v53) + `variantRepository`, a `pullProductVariants` sync step, a local-first `useProductVariants`, and a new `variant-barcode` tier + `variant-hit` result kind in the existing `resolveScannedCode` resolver.

**Tech Stack:** Laravel 12 / PHP 8.2 strict / PHPUnit / PHPStan L8 / Spatie Data. React 19 / TS strict / Tauri SQLite / Zustand / TanStack Query / Vitest.

**Spec:** `docs/superpowers/specs/2026-06-14-pos-offline-variants-design.md` (rev2). **Adversarial review:** `docs/superpowers/reviews/2026-06-14-pos-offline-variants-adversarial-review.md`.

**Milestones** (each gets an adversarial review at its boundary):
- **M1 — Backend feed** (Tasks BV1, BV2)
- **M2 — Offline catalog** (Tasks FV1, FV2)
- **M3 — Local-first picker** (Task FV3)
- **M4 — Scan-to-variant** (Tasks FV4, FV5)
- **M5 — i18n** (Task FV6)

**Verification commands** (scope tests — NEVER run the full suites):
- Backend: `cd apps/api && ./vendor/bin/phpstan analyse <files>` ; `./vendor/bin/pint <files>` ; `./vendor/bin/phpunit <testfile>`
- Frontend: `cd apps/pos && pnpm vitest run <path>` ; `pnpm typecheck` ; `pnpm lint <path>`

---

## File Structure

**Backend (`apps/api`)**
- Create: `app/Shared/DTOs/PosVariantData.php`, `app/Shared/DTOs/PosVariantFeedPageDTO.php` — slim cross-module DTOs.
- Create: `app/Shared/Contracts/PosVariantFeedReader.php` — the feed contract (POS depends on this, not the Catalog model).
- Create: `app/Modules/Catalog/Application/Services/PosVariantFeedService.php` — implements the contract (snapshot/delta/tombstone). Bound in a service provider.
- Create: `app/Modules/POS/Presentation/Controllers/PosVariantController.php` — `GET /pos/variants`.
- Modify: `app/Modules/POS/routes.php` — register the route.
- Modify: a service provider (the one binding `LocationStockReader`) — bind `PosVariantFeedReader`.
- Create: `tests/Feature/POS/PosVariantFeedEndpointTest.php`, `tests/Unit/Catalog/PosVariantFeedServiceTest.php`.

**Frontend (`apps/pos`)**
- Modify: `src/lib/db/migrations.ts` — v53 `product_variants` table.
- Create: `src/lib/db/repositories/variantRepository.ts` — local catalog repo.
- Create: `src/api/variantSyncApi.ts` — `/pos/variants` client.
- Modify: `src/lib/sync/syncService.ts` — `pullProductVariants` + runFullSync wiring + product-tombstone cascade.
- Modify: `src/hooks/useProductVariants.ts` — local-first.
- Modify: `src/components/pos/VariantPickerModal.tsx` — offline-no-cache state.
- Modify: `src/lib/scan/resolveScannedCode.ts` — variant-barcode tier + `variant-hit`.
- Modify: `src/pages/HomePage.tsx` — handle `variant-hit`, fix `addProductToCartWithToast`.
- Modify: `src/locales/en/pos.json`, `src/locales/fr/pos.json` — new keys.
- Tests: repository, sync, hook, resolver, i18n test files.

---

## MILESTONE 1 — Backend feed

### Task BV1: Slim DTOs + `PosVariantFeedReader` contract + `PosVariantFeedService`

**Files:**
- Create: `apps/api/app/Shared/DTOs/PosVariantData.php`, `apps/api/app/Shared/DTOs/PosVariantFeedPageDTO.php`
- Create: `apps/api/app/Shared/Contracts/PosVariantFeedReader.php`
- Create: `apps/api/app/Modules/Catalog/Application/Services/PosVariantFeedService.php`
- Modify: `apps/api/app/Modules/Catalog/Providers/CatalogServiceProvider.php` (bind the contract here — Catalog owns variants; verified it already binds `ProductVariantLookup` at ~line 42)
- Test: `apps/api/tests/Unit/Catalog/PosVariantFeedServiceTest.php`

Background: `ProductVariant` (`App\Modules\Catalog\Domain\Entities\ProductVariant`) uses `SoftDeletes`, has `tenant_id, company_id, product_id, variant_code, sku, barcode, name_suffix, is_default, is_active, display_order, price_override, cost_override, image_url, updated_at, deleted_at`. The POS must NOT import this model directly (module boundaries) — it consumes a Shared contract, exactly like `PosStockLevelController` injects `LocationStockReader`.

- [ ] **Step 1: Write the DTOs**

`PosVariantData.php` (slim — no `tenant_id`/`company_id`/`cost_override`):
```php
<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

final readonly class PosVariantData
{
    public function __construct(
        public string $id,
        public string $productId,
        public string $sku,
        public ?string $barcode,
        public string $nameSuffix,
        public bool $isDefault,
        public int $displayOrder,
        public ?string $priceOverride, // decimal string or null
        public ?string $imageUrl,
        public ?string $updatedAt,     // ISO-8601 or null
    ) {}
}
```

`PosVariantFeedPageDTO.php`:
```php
<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

final readonly class PosVariantFeedPageDTO
{
    /**
     * @param list<PosVariantData> $variants
     * @param list<string> $deletedIds
     */
    public function __construct(
        public array $variants,
        public array $deletedIds,
        public int $page,
        public int $lastPage,
        public int $total,
    ) {}
}
```

- [ ] **Step 2: Write the contract** `PosVariantFeedReader.php`:
```php
<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\DTOs\PosVariantFeedPageDTO;
use Carbon\CarbonImmutable;

interface PosVariantFeedReader
{
    public function read(
        string $tenantId,
        string $companyId,
        ?CarbonImmutable $updatedSince,
        int $page,
        int $perPage,
    ): PosVariantFeedPageDTO;
}
```

- [ ] **Step 3: Write the failing service test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Shared\Contracts\PosVariantFeedReader;
use Carbon\CarbonImmutable;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class PosVariantFeedServiceTest extends TestCase
{
    use RefreshDatabase;

    /** create a product + variant via the real factories; mirror PosCoreReceiptProjectionTest */
    private function makeVariant(string $tenantId, string $companyId, string $productId, array $attrs = []): ProductVariant
    {
        return ProductVariant::factory()->create(array_merge([
            'tenant_id' => $tenantId, 'company_id' => $companyId, 'product_id' => $productId, 'is_active' => true,
        ], $attrs));
    }

    public function test_snapshot_returns_active_variants_only_company_scoped(): void
    {
        // Arrange: a tenant/company/product (use the test harness from the codebase),
        // one active variant, one inactive variant, one variant of another company.
        // Act: $reader->read(tenantId, companyId, null, 1, 500)
        // Assert: only the active same-company variant is in ->variants; deletedIds empty.
        $this->markTestIncomplete('fill in arrange with the real tenant/company/product harness');
    }

    public function test_delta_returns_changed_and_tombstones_deactivated_and_softdeleted(): void
    {
        // Arrange: cursor = T0. After T0: variant A updated (active), variant B deactivated
        // (is_active=false), variant C soft-deleted (delete()). variant D unchanged before T0.
        // Act: $reader->read(tenantId, companyId, CarbonImmutable(T0), 1, 500)
        // Assert: ->variants contains A (not D); ->deletedIds contains B and C; A not in deletedIds.
        $this->markTestIncomplete('fill in arrange');
    }

    public function test_reactivated_variant_appears_in_variants_not_deleted(): void
    {
        // variant deactivated before cursor, reactivated after cursor → is_active=true, updated_at>cursor
        // Assert: in ->variants, NOT in ->deletedIds.
        $this->markTestIncomplete('fill in arrange');
    }
}
```

> Replace the `markTestIncomplete` bodies with the real arrange/act/assert using the project's tenant/company/product test harness. For how `Product` + `ProductVariant` are created, see `tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php`. For the tenant/company + `CompanyContext` bootstrap (the model to copy — it exercises the same `requireTenantId`/`requireCompanyId` path), see **`tests/Feature/POS/PosStockLevelEndpointTest.php`** (NOT `SyncShiftCloseTest`, which doesn't go through `CompanyContext`). Note `ProductVariantFactory` defaults `barcode => null` — set it explicitly when a case needs a non-null barcode. Use `CarbonImmutable` cursors. Resolve the reader via `app(PosVariantFeedReader::class)`.

- [ ] **Step 4: Run — expect FAIL** (binding/class missing). `cd apps/api && ./vendor/bin/phpunit tests/Unit/Catalog/PosVariantFeedServiceTest.php`

- [ ] **Step 5: Implement `PosVariantFeedService`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Shared\Contracts\PosVariantFeedReader;
use App\Shared\DTOs\PosVariantData;
use App\Shared\DTOs\PosVariantFeedPageDTO;
use Carbon\CarbonImmutable;

final class PosVariantFeedService implements PosVariantFeedReader
{
    public function read(
        string $tenantId,
        string $companyId,
        ?CarbonImmutable $updatedSince,
        int $page,
        int $perPage,
    ): PosVariantFeedPageDTO {
        $page = max(1, $page);

        // Active variants (snapshot = all active; delta = active updated since cursor).
        $paginator = ProductVariant::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->when($updatedSince !== null, fn ($q) => $q->where('updated_at', '>', $updatedSince))
            ->orderBy('product_id')
            ->orderBy('display_order')
            ->orderBy('id')
            ->paginate(perPage: $perPage, page: $page);

        $variants = [];
        foreach ($paginator->items() as $v) {
            /** @var ProductVariant $v */
            $variants[] = new PosVariantData(
                id: $v->id,
                productId: $v->product_id,
                sku: $v->sku,
                barcode: $v->barcode,
                nameSuffix: $v->name_suffix,
                isDefault: $v->is_default,
                displayOrder: $v->display_order,
                priceOverride: $v->price_override,
                imageUrl: $v->image_url,
                updatedAt: $v->updated_at?->toIso8601String(),
            );
        }

        // Tombstones: only meaningful in delta mode (page 1 carries the complete set).
        $deletedIds = [];
        if ($updatedSince !== null && $page === 1) {
            // (a) soft-deleted since cursor
            $softDeleted = ProductVariant::onlyTrashed()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->where('deleted_at', '>', $updatedSince)
                ->pluck('id')
                ->all();
            // (b) deactivated (is_active=false) since cursor, not trashed
            $deactivated = ProductVariant::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->where('is_active', false)
                ->where('updated_at', '>', $updatedSince)
                ->pluck('id')
                ->all();
            $deletedIds = array_values(array_unique([...$softDeleted, ...$deactivated]));
        }

        return new PosVariantFeedPageDTO(
            variants: $variants,
            deletedIds: $deletedIds,
            page: $paginator->currentPage(),
            lastPage: $paginator->lastPage(),
            total: $paginator->total(),
        );
    }
}
```

> Verify: `ProductVariant` casts `updated_at` to Carbon (it extends Model with default timestamps) so `?->toIso8601String()` works; `onlyTrashed()` is available (SoftDeletes). `is_default`/`is_active`/`display_order` casts exist (confirmed in the model).

- [ ] **Step 6: Bind the contract** in `apps/api/app/Modules/Catalog/Providers/CatalogServiceProvider.php`, in `register()` alongside the existing `ProductVariantLookup` binding (~line 42). Add the `use` imports for both classes and:
```php
$this->app->bind(\App\Shared\Contracts\PosVariantFeedReader::class, \App\Modules\Catalog\Application\Services\PosVariantFeedService::class);
```
(Bind here, NOT in `InventoryServiceProvider` — `LocationStockReader` lives there but variants are a Catalog concern; a Catalog binding in the Inventory provider would be a module-boundary violation.)

- [ ] **Step 7: Fill in the test arrange/act/assert, run — expect PASS.** `./vendor/bin/phpunit tests/Unit/Catalog/PosVariantFeedServiceTest.php`

- [ ] **Step 8: PHPStan + Pint + Commit**
```bash
cd apps/api && ./vendor/bin/phpstan analyse app/Shared/DTOs/PosVariant*.php app/Shared/Contracts/PosVariantFeedReader.php app/Modules/Catalog/Application/Services/PosVariantFeedService.php && ./vendor/bin/pint app/Shared app/Modules/Catalog/Application/Services/PosVariantFeedService.php
git add apps/api/app/Shared/DTOs/PosVariant*.php apps/api/app/Shared/Contracts/PosVariantFeedReader.php apps/api/app/Modules/Catalog/Application/Services/PosVariantFeedService.php apps/api/tests/Unit/Catalog/PosVariantFeedServiceTest.php apps/api/app/Modules/Catalog/Providers/CatalogServiceProvider.php
git commit -m "feat(api): PosVariantFeedReader contract + PosVariantFeedService (snapshot/delta/tombstone)"
```

---

### Task BV2: `PosVariantController` + route + feature tests

**Files:**
- Create: `apps/api/app/Modules/POS/Presentation/Controllers/PosVariantController.php`
- Modify: `apps/api/app/Modules/POS/routes.php`
- Test: `apps/api/tests/Feature/POS/PosVariantFeedEndpointTest.php`

- [ ] **Step 1: Write the failing feature test** (mirror **`tests/Feature/POS/PosStockLevelEndpointTest.php`** setUp — Tenant/Company/User/UserCompanyMembership/`setPermissionsTeamId`/Permission `pos.operate_terminal`/`CompanyContext`/Sanctum; it's the sibling `/pos/stock-levels` test and exercises the same auth+company path). `ProductVariantFactory` defaults `barcode => null` — set it explicitly per case. Cases:

```php
public function test_company_scoped_snapshot_no_terminal_required(): void
{
    // active variant for this company + an inactive one + another company's variant
    $this->getJson('/api/v1/pos/variants')
        ->assertOk()
        ->assertJsonPath('data.deleted_ids', [])
        ->assertJsonCount(1, 'data.variants'); // only the active same-company variant
}

public function test_response_is_slim_no_tenant_company_cost_leak(): void
{
    $resp = $this->getJson('/api/v1/pos/variants')->assertOk()->json('data.variants.0');
    $this->assertArrayNotHasKey('tenant_id', $resp);
    $this->assertArrayNotHasKey('company_id', $resp);
    $this->assertArrayNotHasKey('cost_override', $resp);
    $this->assertArrayHasKey('barcode', $resp);
    $this->assertArrayHasKey('price_override', $resp);
}

public function test_delta_returns_tombstones_for_deactivated_and_softdeleted(): void
{
    // create cursor, then deactivate one variant + soft-delete another;
    // GET /pos/variants?updated_since=<cursor> → deleted_ids contains both.
}

public function test_does_not_require_or_accept_terminal_id(): void
{
    // succeeds with NO terminal_id param (unlike /pos/stock-levels)
    $this->getJson('/api/v1/pos/variants')->assertOk();
}
```

- [ ] **Step 2: Run — expect FAIL** (route 404). `cd apps/api && ./vendor/bin/phpunit tests/Feature/POS/PosVariantFeedEndpointTest.php`

- [ ] **Step 3: Write the controller** (company-scoped, NO terminal — unlike `PosStockLevelController`):

```php
<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Shared\Contracts\PosVariantFeedReader;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Product-variant catalog feed for the offline POS device sync.
 * GET /api/v1/pos/variants
 *
 * COMPANY-SCOPED (no terminal_id): the variant catalog is company-wide, unlike
 * /pos/stock-levels which is per-terminal-location. Delta cursor mirrors
 * stock-levels (server as_of); tombstones (deleted_ids) capture deactivation +
 * soft-delete, mirroring the /products pull.
 */
final class PosVariantController extends Controller
{
    private const PER_PAGE = 500;

    public function __construct(
        private readonly PosVariantFeedReader $reader,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $updatedSince = $this->parseUpdatedSince($request);
        $asOf = now()->toIso8601String();

        $feed = $this->reader->read(
            tenantId: $this->companyContext->requireTenantId(),
            companyId: $this->companyContext->requireCompanyId(),
            updatedSince: $updatedSince,
            page: (int) ($validated['page'] ?? 1),
            perPage: self::PER_PAGE,
        );

        return response()->json([
            'data' => [
                'variants' => array_map(static fn ($v) => [
                    'id' => $v->id,
                    'product_id' => $v->productId,
                    'sku' => $v->sku,
                    'barcode' => $v->barcode,
                    'name_suffix' => $v->nameSuffix,
                    'is_default' => $v->isDefault,
                    'display_order' => $v->displayOrder,
                    'price_override' => $v->priceOverride,
                    'image_url' => $v->imageUrl,
                    'updated_at' => $v->updatedAt,
                ], $feed->variants),
                'deleted_ids' => $feed->deletedIds,
                'as_of' => $asOf,
            ],
            'meta' => [
                'pagination' => [
                    'current_page' => $feed->page,
                    'last_page' => $feed->lastPage,
                    'total' => $feed->total,
                ],
            ],
        ]);
    }

    private function parseUpdatedSince(Request $request): ?CarbonImmutable
    {
        $raw = $request->query('updated_since');
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        // Same +HH:MM offset restoration as PosStockLevelController.
        $normalised = preg_replace('/T(\d{2}:\d{2}:\d{2}(?:\.\d+)?) (\d{2}:\d{2})$/', 'T$1+$2', $raw);
        try {
            return CarbonImmutable::parse($normalised ?? $raw);
        } catch (InvalidFormatException) {
            throw ValidationException::withMessages(['updated_since' => ['The updated_since must be a valid date.']]);
        }
    }
}
```

- [ ] **Step 4: Register the route** in `apps/api/app/Modules/POS/routes.php` — add the import and a line right after the `/pos/stock-levels` route (line ~105):
```php
use App\Modules\POS\Presentation\Controllers\PosVariantController;
// ...
// Product-variant catalog feed for the device sync (offline availability)
Route::get('/pos/variants', [PosVariantController::class, 'index']);
```

- [ ] **Step 5: Run — expect PASS** (all cases). `./vendor/bin/phpunit tests/Feature/POS/PosVariantFeedEndpointTest.php`

- [ ] **Step 6: PHPStan + Pint + Commit**
```bash
cd apps/api && ./vendor/bin/phpstan analyse app/Modules/POS/Presentation/Controllers/PosVariantController.php && ./vendor/bin/pint app/Modules/POS
git add apps/api/app/Modules/POS/Presentation/Controllers/PosVariantController.php apps/api/app/Modules/POS/routes.php apps/api/tests/Feature/POS/PosVariantFeedEndpointTest.php
git commit -m "feat(api): GET /pos/variants company-scoped catalog feed (delta + tombstones)"
```

**→ MILESTONE 1 adversarial review here.**

---

## MILESTONE 2 — Offline catalog (POS)

### Task FV1: v53 table + `variantRepository`

**Files:**
- Modify: `apps/pos/src/lib/db/migrations.ts` (append v53)
- Create: `apps/pos/src/lib/db/repositories/variantRepository.ts`
- Test: `apps/pos/src/lib/db/repositories/__tests__/variantRepository.test.ts`

- [ ] **Step 1: Append migration v53** (latest is v52 — confirmed):
```ts
  {
    // Offline variant catalog (Spec A). Synced from GET /pos/variants. Only
    // active variants are stored; deactivated/soft-deleted come back as
    // deleted_ids and are removed. price_override is a decimal string.
    version: 53,
    name: 'create_product_variants',
    sql: `
      CREATE TABLE IF NOT EXISTS product_variants (
        id TEXT PRIMARY KEY,
        product_id TEXT NOT NULL,
        sku TEXT NOT NULL,
        barcode TEXT,
        name_suffix TEXT NOT NULL DEFAULT '',
        price_override TEXT,
        image_url TEXT,
        is_default INTEGER NOT NULL DEFAULT 0,
        display_order INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT,
        is_active INTEGER NOT NULL DEFAULT 1
      );
      CREATE INDEX IF NOT EXISTS idx_product_variants_product ON product_variants(product_id);
      CREATE INDEX IF NOT EXISTS idx_product_variants_barcode ON product_variants(barcode);
    `,
  },
```

- [ ] **Step 2: Write the failing repo test** (use the REAL harness — verified: `SqliteTestAdapter` + `applyAllMigrations`, exactly as `locationStockRepository.test.ts` does; there is NO `makeTestDb`):
```ts
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
import { upsertVariants, getVariantsForProduct, getVariantByBarcode, deleteVariantsById, deleteVariantsForProducts } from '@/lib/db/repositories/variantRepository';
import { upsertStockRows } from '@/lib/db/repositories/locationStockRepository';

const row = (over: Partial<Parameters<typeof upsertVariants>[1][number]> = {}) => ({
  id: 'v1', product_id: 'p1', sku: 'SKU1', barcode: 'BC1', name_suffix: ' — M',
  price_override: null, image_url: null, is_default: false, display_order: 0, updated_at: '2026-06-14T10:00:00Z', ...over,
});

describe('variantRepository', () => {
  let adapter: SqliteTestAdapter;
  let db: ReturnType<SqliteTestAdapter['asDatabase']>;
  beforeEach(async () => { adapter = new SqliteTestAdapter(); db = adapter.asDatabase(); await applyAllMigrations(adapter); });
  afterEach(() => { adapter.close(); });

  it('upserts and lists variants for a product (active, ordered)', async () => {
    await upsertVariants(db, [row({ id: 'v2', display_order: 1, barcode: 'BC2' }), row({ id: 'v1', display_order: 0 })]);
    const list = await getVariantsForProduct(db, 'p1');
    expect(list.map((v) => v.id)).toEqual(['v1', 'v2']); // display_order asc
  });

  it('getVariantByBarcode returns the active variant; ignores empty/null', async () => {
    await upsertVariants(db, [row({ barcode: 'BC1' })]);
    expect((await getVariantByBarcode(db, 'BC1'))?.id).toBe('v1');
    expect(await getVariantByBarcode(db, '')).toBeNull();
    expect(await getVariantByBarcode(db, 'NOPE')).toBeNull();
  });

  it('joins location_stock available into stock_quantity', async () => {
    await upsertVariants(db, [row()]);
    await upsertStockRows(db, [{ product_id: 'p1', variant_id: 'v1', quantity: '5.0000', reserved: '0.0000', available: '5.0000', updated_at: null }]);
    expect((await getVariantsForProduct(db, 'p1'))[0]!.stock_quantity).toBe(5);
  });

  it('deletes by id and by product', async () => {
    await upsertVariants(db, [row({ id: 'v1' }), row({ id: 'v2', barcode: 'BC2' })]);
    await deleteVariantsById(db, ['v1']);
    expect((await getVariantsForProduct(db, 'p1')).map((v) => v.id)).toEqual(['v2']);
    await deleteVariantsForProducts(db, ['p1']);
    expect(await getVariantsForProduct(db, 'p1')).toEqual([]);
  });
});
```

- [ ] **Step 3: Run — expect FAIL.** `cd apps/pos && pnpm vitest run src/lib/db/repositories/__tests__/variantRepository.test.ts`

- [ ] **Step 4: Implement `variantRepository.ts`** (mirror `locationStockRepository.ts` batching; the list query LEFT JOINs `location_stock` for per-variant `available` → `stock_quantity`):
```ts
import type Database from '@tauri-apps/plugin-sql';
import { queryAll, queryOne, execute } from '@/lib/db';
import type { POSProductVariant } from '@/types/product';

export interface ServerVariantRow {
  id: string;
  product_id: string;
  sku: string;
  barcode: string | null;
  name_suffix: string;
  price_override: string | null;
  image_url: string | null;
  is_default: boolean;
  display_order: number;
  updated_at: string | null;
}

interface VariantJoinRow {
  id: string;
  product_id: string;
  sku: string;
  barcode: string | null;
  name_suffix: string;
  price_override: string | null;
  image_url: string | null;
  is_default: number;
  display_order: number;
  available: string | null;
}

const UPSERT_PARAMS_PER_ROW = 10; // id, product_id, sku, barcode, name_suffix, price_override, image_url, is_default, display_order, updated_at
const UPSERT_BATCH_SIZE = 50;
const DELETE_BATCH_SIZE = 200;

function toNumber(raw: string | null): number {
  if (raw == null) return 0;
  const n = Number(raw);
  return Number.isFinite(n) ? n : 0;
}

function toVariant(r: VariantJoinRow): POSProductVariant {
  return {
    id: r.id,
    product_id: r.product_id,
    variant_code: r.sku, // variant_code not synced to device; sku stands in for display fallback
    sku: r.sku,
    barcode: r.barcode,
    name_suffix: r.name_suffix,
    is_default: r.is_default === 1,
    is_active: true,
    display_order: r.display_order,
    price_override: r.price_override,
    image_url: r.image_url,
    stock_quantity: toNumber(r.available), // per-variant available from location_stock
  };
}

export async function upsertVariants(db: Database, rows: ServerVariantRow[]): Promise<void> {
  if (rows.length === 0) return;
  for (let i = 0; i < rows.length; i += UPSERT_BATCH_SIZE) {
    const batch = rows.slice(i, i + UPSERT_BATCH_SIZE);
    const params: unknown[] = [];
    const clauses: string[] = [];
    for (let j = 0; j < batch.length; j++) {
      const r = batch[j]!;
      const o = j * UPSERT_PARAMS_PER_ROW;
      clauses.push(`($${o + 1}, $${o + 2}, $${o + 3}, $${o + 4}, $${o + 5}, $${o + 6}, $${o + 7}, $${o + 8}, $${o + 9}, $${o + 10})`);
      params.push(r.id, r.product_id, r.sku, r.barcode, r.name_suffix, r.price_override, r.image_url, r.is_default ? 1 : 0, r.display_order, r.updated_at);
    }
    await execute(
      db,
      `INSERT INTO product_variants
         (id, product_id, sku, barcode, name_suffix, price_override, image_url, is_default, display_order, updated_at)
       VALUES ${clauses.join(', ')}
       ON CONFLICT(id) DO UPDATE SET
         product_id = excluded.product_id, sku = excluded.sku, barcode = excluded.barcode,
         name_suffix = excluded.name_suffix, price_override = excluded.price_override,
         image_url = excluded.image_url, is_default = excluded.is_default,
         display_order = excluded.display_order, updated_at = excluded.updated_at, is_active = 1`,
      params,
    );
  }
}

export async function getVariantsForProduct(db: Database, productId: string): Promise<POSProductVariant[]> {
  const rows = await queryAll<VariantJoinRow>(
    db,
    `SELECT v.id, v.product_id, v.sku, v.barcode, v.name_suffix, v.price_override, v.image_url,
            v.is_default, v.display_order, ls.available AS available
       FROM product_variants v
       LEFT JOIN location_stock ls ON ls.product_id = v.product_id AND ls.variant_id = v.id
      WHERE v.product_id = $1 AND v.is_active = 1
      ORDER BY v.display_order, v.id`,
    [productId],
  );
  return rows.map(toVariant);
}

export async function getVariantByBarcode(db: Database, barcode: string): Promise<POSProductVariant | null> {
  if (!barcode) return null; // never match empty/whitespace against NULL/empty barcodes
  const row = await queryOne<VariantJoinRow>(
    db,
    `SELECT v.id, v.product_id, v.sku, v.barcode, v.name_suffix, v.price_override, v.image_url,
            v.is_default, v.display_order, ls.available AS available
       FROM product_variants v
       LEFT JOIN location_stock ls ON ls.product_id = v.product_id AND ls.variant_id = v.id
      WHERE v.barcode = $1 AND v.is_active = 1
      LIMIT 1`,
    [barcode],
  );
  return row ? toVariant(row) : null;
}

export async function deleteVariantsById(db: Database, ids: string[]): Promise<void> {
  if (ids.length === 0) return;
  for (let i = 0; i < ids.length; i += DELETE_BATCH_SIZE) {
    const chunk = ids.slice(i, i + DELETE_BATCH_SIZE);
    const ph = chunk.map((_, idx) => `$${idx + 1}`).join(', ');
    await execute(db, `DELETE FROM product_variants WHERE id IN (${ph})`, chunk);
  }
}

export async function deleteVariantsForProducts(db: Database, productIds: string[]): Promise<void> {
  if (productIds.length === 0) return;
  for (let i = 0; i < productIds.length; i += DELETE_BATCH_SIZE) {
    const chunk = productIds.slice(i, i + DELETE_BATCH_SIZE);
    const ph = chunk.map((_, idx) => `$${idx + 1}`).join(', ');
    await execute(db, `DELETE FROM product_variants WHERE product_id IN (${ph})`, chunk);
  }
}
```

- [ ] **Step 5: Run — expect PASS + typecheck.** `cd apps/pos && pnpm vitest run src/lib/db/repositories/__tests__/variantRepository.test.ts && pnpm typecheck`

- [ ] **Step 6: Commit**
```bash
git add apps/pos/src/lib/db/migrations.ts apps/pos/src/lib/db/repositories/variantRepository.ts apps/pos/src/lib/db/repositories/__tests__/variantRepository.test.ts
git commit -m "feat(pos): product_variants offline table (v53) + variantRepository"
```

---

### Task FV2: `variantSyncApi` + `pullProductVariants` + tombstone cascade

**Files:**
- Create: `apps/pos/src/api/variantSyncApi.ts`
- Modify: `apps/pos/src/lib/sync/syncService.ts` (add `pullProductVariants`, wire into `runFullSync`, cascade in `pullProductsCore`)
- Test: `apps/pos/src/lib/sync/__tests__/pullProductVariants.test.ts`

- [ ] **Step 1: API client** `variantSyncApi.ts` (mirror `stockApi.ts` — `apiGetRaw` preserves `meta.pagination`):
```ts
import { apiGetRaw, type ApiRequestOptions } from '@/lib/api';
import type { ServerVariantRow } from '@/lib/db/repositories/variantRepository';

export interface VariantFeedPage {
  data: { variants: ServerVariantRow[]; deleted_ids: string[]; as_of: string };
  meta: { pagination: { current_page: number; last_page: number; total: number } };
}

export async function fetchVariants(
  params: { updated_since?: string; page?: string },
  opts?: ApiRequestOptions,
): Promise<VariantFeedPage> {
  return apiGetRaw<VariantFeedPage>('/pos/variants', params, opts);
}
```

- [ ] **Step 2: Write the failing sync test** (mirror the stock-pull tests; mock `fetchVariants`):
```ts
// full pull (no cursor) upserts variants; delta applies deleted_ids; cursor persisted from as_of.
// Mock @/api/variantSyncApi.fetchVariants to return a page; mock the repo functions; assert calls.
```
> Mirror the existing `pullLocationStock`/`pullProductsCore` test files for the mocking style (vi.mock the api + repo, an in-memory or mocked db). Assert: variants upserted, `deleteVariantsById(deleted_ids)` called, `setSyncMetadata('product_variants_as_of', as_of)` written, pagination loop honored.

- [ ] **Step 3: Run — expect FAIL.** `cd apps/pos && pnpm vitest run src/lib/sync/__tests__/pullProductVariants.test.ts`

- [ ] **Step 4: Implement `pullProductVariants`** in `syncService.ts` (mirror `pullLocationStock`'s cursor/pagination + `pullProductsCore`'s tombstone application). Add near the other pull functions:
```ts
const VARIANTS_CURSOR_KEY = 'product_variants_as_of';
const VARIANTS_LAST_SYNC_KEY = 'product_variants_last_sync';

export async function pullProductVariants(
  db: Database,
  opts: { signal?: AbortSignal; timeoutMs?: number } = {},
): Promise<{ count: number }> {
  const gate = await resolveCatalogTenantGate();
  if (gate.decision !== 'standard') return { count: 0 }; // Menu tenants: no retail variants

  const cursor = await getSyncMetadata(db, VARIANTS_CURSOR_KEY);
  const allVariants: ServerVariantRow[] = [];
  let deletedIds: string[] = [];
  let asOf: string | null = null;
  let page = 1;
  let lastPage = 1;

  do {
    const params: { updated_since?: string; page?: string } = { page: String(page) };
    if (cursor !== null) params.updated_since = cursor;
    const result = await fetchVariants(params, { signal: opts.signal, timeoutMs: opts.timeoutMs });
    allVariants.push(...result.data.variants);
    if (page === 1) { deletedIds = result.data.deleted_ids; asOf = result.data.as_of; }
    lastPage = result.meta.pagination.last_page;
    page++;
  } while (page <= lastPage);

  if (allVariants.length > 0) await upsertVariants(db, allVariants);
  if (deletedIds.length > 0) await deleteVariantsById(db, deletedIds);
  if (asOf !== null) await setSyncMetadata(db, VARIANTS_CURSOR_KEY, asOf);
  await setSyncMetadata(db, VARIANTS_LAST_SYNC_KEY, new Date().toISOString());
  await logSyncOperation(db, 'pull', 'product_variants', null, 'success', `${allVariants.length} upserted, ${deletedIds.length} tombstoned`);
  return { count: allVariants.length };
}
```
Add the imports at the top of `syncService.ts`:
```ts
import { fetchVariants, type VariantFeedPage } from '@/api/variantSyncApi';
import { upsertVariants, deleteVariantsById, deleteVariantsForProducts, type ServerVariantRow } from '@/lib/db/repositories/variantRepository';
```

- [ ] **Step 5: Wire into `runFullSync`** — in the pull phase (after `pullReceiptQrIndex`), add a swallow-and-log call mirroring the `pullLocationStock` block:
```ts
  try {
    await pullProductVariants(db);
  } catch (error) {
    const message = coerceSyncError(error);
    try { await logSyncOperation(db, 'pull', 'product_variants', null, 'error', message); } catch { /* non-critical */ }
  }
```

- [ ] **Step 6: Cascade on product tombstone** — in `pullProductsCore`, alongside `deleteLocationStockForProducts(db, deletedIdsAccumulator)`, add:
```ts
    await deleteVariantsForProducts(db, deletedIdsAccumulator);
```

- [ ] **Step 7: Run — expect PASS + typecheck.** `cd apps/pos && pnpm vitest run src/lib/sync/__tests__/pullProductVariants.test.ts && pnpm typecheck`

- [ ] **Step 8: Commit**
```bash
git add apps/pos/src/api/variantSyncApi.ts apps/pos/src/lib/sync/syncService.ts apps/pos/src/lib/sync/__tests__/pullProductVariants.test.ts
git commit -m "feat(pos): pullProductVariants sync (delta + tombstone + product-cascade)"
```

**→ MILESTONE 2 adversarial review here.**

---

## MILESTONE 3 — Local-first picker

### Task FV3: `useProductVariants` local-first + picker offline state

**Files:**
- Modify: `apps/pos/src/hooks/useProductVariants.ts`
- Modify: `apps/pos/src/components/pos/VariantPickerModal.tsx`
- Test: `apps/pos/src/hooks/__tests__/useProductVariants.test.ts`

- [ ] **Step 1: Write failing tests** for the new local-first hook contract. The hook returns `{ variants, isLoading, status }` where `status: 'local' | 'cold-fetch' | 'offline-empty' | 'error'`:
```ts
// online + local variants exist → variants from local, status 'local'
// online + no local → cold fetch from /products/{id}/variants, upsert, status transitions to 'local'
// offline + no local → status 'offline-empty', variants []
// product with zero active variants synced → status 'local', variants [] (NOT offline-empty)
```
> Mock `@/lib/db/repositories/variantRepository.getVariantsForProduct`, `@/api/variantApi.fetchProductVariants`, `@/stores/connectivityStore.useConnectivityStore` (isOnline), and `getDatabase`. Use `renderHook` + `waitFor`.

- [ ] **Step 2: Run — expect FAIL.** `cd apps/pos && pnpm vitest run src/hooks/__tests__/useProductVariants.test.ts`

- [ ] **Step 3: Rewire `useProductVariants`** local-first:
```ts
import { useEffect, useState } from 'react';
import { getDatabase } from '@/lib/db';
import { getVariantsForProduct } from '@/lib/db/repositories/variantRepository';
import { fetchProductVariants } from '@/api/variantApi';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { useAuthStore } from '@/stores/authStore';
import type { POSProductVariant } from '@/types/product';

type VariantStatus = 'idle' | 'loading' | 'local' | 'cold-fetch' | 'offline-empty' | 'error';

export function useProductVariants(productId: string | null): {
  variants: POSProductVariant[];
  isLoading: boolean;
  status: VariantStatus;
} {
  const isOnline = useConnectivityStore((s) => s.isOnline);
  const [variants, setVariants] = useState<POSProductVariant[]>([]);
  const [status, setStatus] = useState<VariantStatus>('idle');

  useEffect(() => {
    if (!productId) { setVariants([]); setStatus('idle'); return; }
    let cancelled = false;
    setStatus('loading');

    void (async () => {
      const companyId = useAuthStore.getState().companyId;
      if (!companyId) { if (!cancelled) { setVariants([]); setStatus('error'); } return; }
      const db = await getDatabase(companyId);
      const local = await getVariantsForProduct(db, productId);
      if (cancelled) return;

      if (local.length > 0) {
        setVariants(local); setStatus('local');
        // best-effort background refresh when online
        if (isOnline) {
          try {
            const fresh = await fetchProductVariants(productId);
            if (!cancelled) {
              // upsert handled by sync; here we just reflect fresh ordering/stock if returned
              const reread = await getVariantsForProduct(db, productId);
              if (!cancelled && reread.length > 0) setVariants(reread);
            }
          } catch { /* keep local */ }
        }
        return;
      }

      // none local
      if (isOnline) {
        setStatus('cold-fetch');
        try {
          const fresh = await fetchProductVariants(productId);
          if (!cancelled) { setVariants(fresh); setStatus('local'); }
        } catch {
          if (!cancelled) { setVariants([]); setStatus('error'); }
        }
      } else {
        setVariants([]); setStatus('offline-empty');
      }
    })();

    return () => { cancelled = true; };
  }, [productId, isOnline]);

  return { variants, isLoading: status === 'loading' || status === 'cold-fetch', status };
}
```
> Note: a product with **synced-but-zero** active variants yields `local.length === 0` while online → it would hit the cold-fetch path and return `[]` with status `local` (server also returns none). That's fine (picker shows "no variants"). The `offline-empty` status only fires offline. If you want to distinguish synced-empty from never-synced offline, add a `product_variants_last_sync` check; v1's behavior above is acceptable.

- [ ] **Step 4: Update `VariantPickerModal`** to consume the new contract (replace `{ data: variants, isLoading, isError }`):
```tsx
const { variants, isLoading, status } = useProductVariants(productId);
// ...
{isLoading ? (
  <p className="py-6 text-center text-sm text-gray-600">{t('variants.loading')}</p>
) : status === 'offline-empty' ? (
  <p className="py-6 text-center text-sm text-gray-600">{t('variants.offlineNoCache')}</p>
) : status === 'error' ? (
  <p className="py-6 text-center text-sm text-red-600">{t('variants.loadError')}</p>
) : (
  <ProductVariantStockView basePrice={product.sale_price} variants={variants} selectedVariantId={selectedVariantId} onSelect={setSelectedVariantId} />
)}
```
Also update `handleConfirm`: the new `variants` is **always an array** (never undefined), so drop the `!variants` guard — `if (selectedVariantId === null) return; const variant = variants.find((v) => v.id === selectedVariantId); if (!variant) return; onConfirm(variant);`. Remove any `variants ?? []` / `isError` references left from the old React-Query contract.

- [ ] **Step 5: Run — expect PASS + typecheck + lint.** `cd apps/pos && pnpm vitest run src/hooks/__tests__/useProductVariants.test.ts && pnpm typecheck && pnpm lint src/components/pos/VariantPickerModal.tsx`

- [ ] **Step 6: Commit**
```bash
git add apps/pos/src/hooks/useProductVariants.ts apps/pos/src/components/pos/VariantPickerModal.tsx apps/pos/src/hooks/__tests__/useProductVariants.test.ts
git commit -m "feat(pos): local-first useProductVariants + offline-no-cache picker state"
```

**→ MILESTONE 3 adversarial review here.**

---

## MILESTONE 4 — Scan-to-variant

### Task FV4: variant-barcode tier + `variant-hit` in the resolver

**Files:**
- Modify: `apps/pos/src/lib/scan/resolveScannedCode.ts`
- Test: `apps/pos/src/lib/scan/__tests__/resolveScannedCode.variant.test.ts`

- [ ] **Step 1: Write failing tests:**
```ts
// scanning a variant barcode returns { kind: 'variant-hit', product, variant } (variant is the exact one)
// a deactivated variant's barcode (not in local table) → does NOT variant-hit (falls through to product/miss)
// a variant-hit is NOT written to the recent-scan LRU
// a product barcode still returns { kind: 'hit', product } (unchanged)
```
> Mock `getVariantByBarcode` (returns the variant) and `getProductsByBarcode`; verify `setCachedScan` is not called for the variant path.

- [ ] **Step 2: Run — expect FAIL.** `cd apps/pos && pnpm vitest run src/lib/scan/__tests__/resolveScannedCode.variant.test.ts`

- [ ] **Step 3: Extend the resolver.** Add to the union:
```ts
export type ResolveScannedCodeResult =
  | { kind: 'hit'; product: POSProduct }
  | { kind: 'variant-hit'; product: POSProduct; variant: POSProductVariant }
  | { kind: 'choose'; candidates: POSProduct[] }
  | { kind: 'miss' };
```
First add `getProductById` to `productRepository.ts` (verified: it does NOT exist — mirror the existing `getProductByBarcode` at lines 56–63):
```ts
export async function getProductById(db: Database, id: string): Promise<POSProduct | null> {
  const row = await queryOne<ProductRow>(db, 'SELECT * FROM products WHERE id = $1', [id]);
  return row ? rowToProduct(row) : null;
}
```
Then, in the SQLite tier of `resolveScannedCode` (where `getProductsByBarcode` is called), try the variant index first; the variant's `product_id` resolves the parent product from the in-memory snapshot, then local SQLite:
```ts
import { getVariantByBarcode } from '@/lib/db/repositories/variantRepository';
import { getProductsByBarcode, getProductById } from '@/lib/db/repositories/productRepository';
// ... inside the SQLite tier:
const variant = await getVariantByBarcode(deps.db, code);
if (variant) {
  const product = deps.products.find((p) => p.id === variant.product_id)
    ?? (await getProductById(deps.db, variant.product_id));
  if (product) {
    // Do NOT cache variant-hits in the recent-scan LRU (it stores POSProduct only).
    return { kind: 'variant-hit', product, variant };
  }
  // parent product not synced → fall through to the existing product tiers (don't crash).
}
```
> Keep the variant query INSIDE the existing SQLite tier so there's still one logical round-trip and the abort-signal/aborted checks still apply.

- [ ] **Step 4: Run — expect PASS + typecheck.** `cd apps/pos && pnpm vitest run src/lib/scan/__tests__/resolveScannedCode.variant.test.ts && pnpm typecheck`

- [ ] **Step 5: Commit**
```bash
git add apps/pos/src/lib/scan/resolveScannedCode.ts apps/pos/src/lib/db/repositories/productRepository.ts apps/pos/src/lib/scan/__tests__/resolveScannedCode.variant.test.ts
git commit -m "feat(pos): variant-barcode tier + variant-hit in scan resolver (offline scan-to-variant)"
```

---

### Task FV5: HomePage — handle `variant-hit` + fix the variant-scan bug

**Files:**
- Modify: `apps/pos/src/pages/HomePage.tsx`
- Test: `apps/pos/src/pages/__tests__/HomePage.scan.test.tsx` (create or extend an existing HomePage test)

- [ ] **Step 1: Write failing tests** (component or unit-extracting the handlers):
```ts
// resolver returns 'variant-hit' → addItemGated(product, { variant }) is called, success toast
// resolver returns 'hit' for a has_variants product → opens the variant picker (NOT base add) [bug fix]
// resolver returns 'hit' for a non-variant product → addItemGated(product) (unchanged)
```
> HomePage is large and store-driven; prefer testing the decision logic. Extract a pure helper `routeScanResult(result, { addItemGated, openPicker })` if needed, or test via the existing HomePage test harness with mocked stores. Mirror how other HomePage behaviors are tested in `apps/pos/src/pages/__tests__/`.

- [ ] **Step 2: Run — expect FAIL.** `cd apps/pos && pnpm vitest run src/pages/__tests__/HomePage.scan.test.tsx`

- [ ] **Step 3: Implement.** In `handleProductBarcode`, add a `variant-hit` branch before the `hit` branch:
```ts
if (result.kind === 'variant-hit') {
  const { autoAddToCart } = useScannerStore.getState();
  if (autoAddToCart) {
    void (async () => {
      const added = await addItemGated(result.product, { variant: result.variant });
      if (added) {
        setScanMessage({ text: t('barcode.productAdded', { name: `${result.product.name}${result.variant.name_suffix}` }), type: 'success' });
        setTimeout(() => setScanMessage(null), 2000);
      }
    })();
  }
  return;
}
```
Fix `addProductToCartWithToast` to mirror `handleAddToCart` (open the picker for has_variants instead of base-adding):
```ts
const addProductToCartWithToast = useCallback(
  (product: POSProduct) => {
    const { autoAddToCart } = useScannerStore.getState();
    if (!autoAddToCart) return;
    // BUG FIX: a scanned PARENT barcode of a variant product must open the
    // picker, not add the base product (matches handleAddToCart / tile-tap).
    if (product.has_variants) {
      setVariantPickerProduct(product);
      return;
    }
    void (async () => {
      const added = await addItemGated(product);
      if (!added) return;
      setScanMessage({ text: t('barcode.productAdded', { name: product.name }), type: 'success' });
      setTimeout(() => setScanMessage(null), 2000);
    })();
  },
  [t],
);
```
> `setVariantPickerProduct` is already in scope (declared at HomePage state). Add it to the `useCallback` deps. Confirm no double-fire: a `variant-hit` returns before reaching `addProductToCartWithToast`; a plain `hit` on a has_variants product opens the picker.

- [ ] **Step 4: Run — expect PASS + typecheck + lint.** `cd apps/pos && pnpm vitest run src/pages/__tests__/HomePage.scan.test.tsx && pnpm typecheck && pnpm lint src/pages/HomePage.tsx`

- [ ] **Step 5: Commit**
```bash
git add apps/pos/src/pages/HomePage.tsx apps/pos/src/pages/__tests__/HomePage.scan.test.tsx
git commit -m "feat(pos): scan variant-hit auto-add + fix variant-product scan to open picker"
```

**→ MILESTONE 4 adversarial review here.**

---

## MILESTONE 5 — i18n

### Task FV6: i18n keys + smoke test

**Files:**
- Modify: `apps/pos/src/locales/en/pos.json`, `apps/pos/src/locales/fr/pos.json`
- Test: `apps/pos/src/__tests__/variantsI18n.test.tsx`

- [ ] **Step 1: Add `variants.offlineNoCache` to BOTH locales** (the `variants` object already exists — it has `chooseVariant`, `loading`, `loadError`, `empty`, `inStock`, `outOfStock`, `addToCart`). Add:
  - en: `"offlineNoCache": "Connect to load variants"`
  - fr: `"offlineNoCache": "Connectez-vous pour charger les variantes"`

- [ ] **Step 2: Write the smoke test** (mirror `apps/pos/src/__tests__/crossLocationStockI18n.test.tsx` — direct `i18n.t(key, { ns: 'pos' })` in en + fr):
```tsx
import { describe, it, expect } from 'vitest';
import i18n from '@/lib/i18n';

describe('variants offline i18n', () => {
  for (const lng of ['en', 'fr']) {
    it(`${lng}: variants.offlineNoCache resolves`, async () => {
      await i18n.changeLanguage(lng);
      const v = i18n.t('pos:variants.offlineNoCache');
      expect(v).not.toBe('variants.offlineNoCache');
      expect(v).not.toContain('variants.');
    });
  }
});
```

- [ ] **Step 3: Run — expect PASS.** `cd apps/pos && pnpm vitest run src/__tests__/variantsI18n.test.tsx`

- [ ] **Step 4: Commit**
```bash
git add apps/pos/src/locales/en/pos.json apps/pos/src/locales/fr/pos.json apps/pos/src/__tests__/variantsI18n.test.tsx
git commit -m "feat(pos): variants.offlineNoCache i18n (en+fr) + smoke test"
```

**→ MILESTONE 5 adversarial review here.**

---

## Final verification (scoped — do NOT run full suites)
- Backend: `cd apps/api && ./vendor/bin/phpstan analyse <changed files> && ./vendor/bin/phpunit tests/Feature/POS/PosVariantFeedEndpointTest.php tests/Unit/Catalog/PosVariantFeedServiceTest.php`
- Frontend: `cd apps/pos && pnpm typecheck && pnpm vitest run src/lib/db/repositories/__tests__/variantRepository.test.ts src/lib/sync/__tests__/pullProductVariants.test.ts src/hooks/__tests__/useProductVariants.test.ts src/lib/scan/__tests__/resolveScannedCode.variant.test.ts src/pages/__tests__/HomePage.scan.test.tsx src/__tests__/variantsI18n.test.tsx`
- Manual Tauri smoke (post-merge): scan a variant barcode → exact variant auto-adds; tap a variant product offline → picker shows synced variants; deactivate a variant server-side → after sync it disappears and its barcode no longer resolves.

## Notes / decisions baked in
- Module boundaries: POS depends on `PosVariantFeedReader` (Shared contract), implemented in Catalog — mirrors `LocationStockReader`. POS never imports the `ProductVariant` model.
- Active-only safety (HIGH-3): the feed sends only active variants + tombstones deactivated ones; the local `getVariantByBarcode`/`getVariantsForProduct` filter `is_active=1` as defense-in-depth so a withdrawn variant can never scan-resolve.
- No-LRU for variant-hits (the LRU stores `POSProduct` only).
- Offline pricing (`cartStore` variant `price_override`), fiscal variant signing (`SaleReceiptV2`), and variant-grain stock gating already work — unchanged.
- Stock for the picker comes from the `location_stock` join (per-variant `available` → `stock_quantity`), consistent with today's number-based display.

## Review notes (Opus + Codex plan reviews, both APPROVE-WITH-EDITS — verified against code, folded in)
- **Test harness:** the real helper is `SqliteTestAdapter` + `applyAllMigrations` from `@/lib/db/__tests__/helpers/` — there is **no** `makeTestDb` (fixed in FV1).
- **Binding:** `PosVariantFeedReader` is bound in **`CatalogServiceProvider`** (variants are Catalog's; `LocationStockReader` lives in `InventoryServiceProvider` but binding there would cross module boundaries) — fixed in BV1.
- **`getProductById`:** verified it does **not** exist in `productRepository.ts` → FV4 adds it (mirrors `getProductByBarcode`). The earlier "verify/maybe" wording is now definitive.
- **`resolveCatalogTenantGate`:** it is module-private in `syncService.ts:662`; `pullProductVariants` is defined in that **same file**, so it's in scope with **no import** (a reviewer flagged a phantom import — non-issue).
- **`Number(available)` → `stock_quantity`:** intentional parity with the existing `variantApi.ts:28` (`POSProductVariant.stock_quantity` is typed `number` and used only for advisory in-stock/out-of-stock display). The real, precision-critical stock gate is `addItemGated` (decimal strings) — unchanged. Not a new precision violation; apps/pos ESLint does not flag it.
- **Backend test bootstrap:** model the feature test on `PosStockLevelEndpointTest` (exercises `CompanyContext`), not `SyncShiftCloseTest`.
- **Factory:** `ProductVariantFactory` defaults `barcode => null` — set it explicitly in barcode/scan cases.
- **Gate `!== 'standard'`:** `pullProductVariants` no-ops on Menu/defer tenants (mirrors `pullLocationStock`); harmless, next tick retries.
