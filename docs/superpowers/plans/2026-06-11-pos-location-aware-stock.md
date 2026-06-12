# POS Location-Aware Stock — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Tauri POS enforce per-location stock availability (policy-configurable), surface in-transit/incoming stock, and complete location fiscal identity — per the approved spec `docs/superpowers/specs/2026-06-11-pos-location-aware-stock-design.md` (Codex r2 APPROVE-WITH-MINOR-EDITS, 88%).

**Architecture:** Catalog stays company-grain. A new location-scoped stock feed (POS endpoint backed by a `Shared/Contracts/LocationStockReader` implemented in Inventory) syncs into a new local SQLite `location_stock` table (delta + full-reconciliation modes, server-issued cursor). One client-side availability selector (`snapshot − pending unsynced sales − cart`, clamped ≥0) feeds every cart ingress, gated by a company-level `pos_stock_policy` enum delivered via the terminal payload. Seller fiscal identity becomes atomic (complete-location-or-wholesale-company).

**Tech Stack:** Laravel 12 / PHPUnit / PHPStan L8 (server); React 19 / Zustand / Vitest / Tauri SQLite (client). Quantities are decimal strings end-to-end — no floats, no hardcoded bcmath scales.

**Branch/worktree:** `feat/pos-location-aware-stock-spec` at `/Users/houssamr/Projects/syneriva/apps/erp.pos-location-stock` (off `origin/dev`). Rename branch to `feat/pos-location-aware-stock` at execution start (`git branch -m`).

**Test safety:** NEVER run the full PHPUnit suite or `scripts/preflight.sh` — always `--filter`/file-scoped (crashes the laptop; standing rule).

**Spec cross-reference:** §-references below point at the spec. Read the spec FIRST.

**Codex plan-review r1 adjudication** (review: `docs/superpowers/reviews/2026-06-11-pos-location-aware-stock-plan-codex-review.md`, REQUEST-CHANGES 86%): applied — migration object shape (P1-3), `@/lib/decimal` path + explicit scale 4 (P1-4), JSON-blob pending aggregate (P1-5), unconditional `api.get` for pagination meta (P1-7), backfill moved out of the migration into a console command (NIT — no `tenant()` precedent in tenant migrations), permission gate + spec-wording alignment on `terminal_id` (BLOCKER-1, downgraded: no server-side terminal session exists; `ShiftController.php:50` takes `terminal_code` from the request — same trust boundary), PHP+TS fixture pairing (P2-1), accumulator/as_of clarifications (P2-2/P2-3). **Rejected with evidence:** P1-1 (`Terminal::company()` exists, `Terminal.php:146`), P1-2 (`QuantityScale::round(value, places, method)` exists, `QuantityScale.php:33`), P1-6 (no `location_id`/NOT NULL constraint anywhere in Task 1 — misread).

---

## Phase 1 — Server (apps/api)

### Task 1: `PosStockPolicy` enum + `companies.pos_stock_policy` column + backfill

**Files:**
- Create: `apps/api/app/Modules/Company/Domain/Enums/PosStockPolicy.php`
- Create: `apps/api/database/migrations/tenant/2026_06_12_000000_add_pos_stock_policy_to_companies.php`
- Modify: `apps/api/app/Modules/Company/Domain/Company.php` (add property + cast)
- Test: `apps/api/tests/Unit/Company/PosStockPolicyTest.php`
- Test: `apps/api/tests/Feature/Company/PosStockPolicyMigrationTest.php`

- [x] **Step 1: Write the failing enum test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Company;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Enums\PosStockPolicy;
use PHPUnit\Framework\TestCase;

final class PosStockPolicyTest extends TestCase
{
    public function test_values(): void
    {
        self::assertSame('block', PosStockPolicy::Block->value);
        self::assertSame('warn', PosStockPolicy::Warn->value);
        self::assertSame('off', PosStockPolicy::Off->value);
    }

    public function test_default_for_vertical_is_off_for_menu_verticals(): void
    {
        self::assertSame(PosStockPolicy::Off, PosStockPolicy::defaultForVertical(Vertical::Restaurant));
        self::assertSame(PosStockPolicy::Off, PosStockPolicy::defaultForVertical(Vertical::CoffeeShop));
    }

    public function test_default_for_vertical_is_block_for_stock_verticals(): void
    {
        self::assertSame(PosStockPolicy::Block, PosStockPolicy::defaultForVertical(Vertical::Retail));
        self::assertSame(PosStockPolicy::Block, PosStockPolicy::defaultForVertical(Vertical::Parapharmacy));
        self::assertSame(PosStockPolicy::Block, PosStockPolicy::defaultForVertical(Vertical::Mechanic));
    }
}
```

- [x] **Step 2: Run to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Company/PosStockPolicyTest.php`
Expected: FAIL — `Class "App\Modules\Company\Domain\Enums\PosStockPolicy" not found`

- [x] **Step 3: Implement the enum**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Company\Domain\Enums;

use App\Enums\Vertical;

/**
 * What the POS does when a cashier tries to sell beyond the terminal
 * location's available stock (spec §4.2). Per-location stock AWARENESS is
 * unconditional — this only selects the enforcement behavior.
 */
enum PosStockPolicy: string
{
    case Block = 'block';
    case Warn = 'warn';
    case Off = 'off';

    /**
     * Made-to-order verticals (Menu module in their default set) have no
     * finished-goods stock rows — hard blocking would freeze their POS.
     */
    public static function defaultForVertical(Vertical $vertical): self
    {
        return in_array('Menu', $vertical->defaultModules(), true)
            ? self::Off
            : self::Block;
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Unit/Company/PosStockPolicyTest.php`
Expected: PASS (3 tests)

- [x] **Step 5: Write the failing migration/model test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\PosStockPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PosStockPolicyMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_companies_have_pos_stock_policy_defaulting_to_block(): void
    {
        $company = Company::factory()->create();

        self::assertSame(PosStockPolicy::Block, $company->refresh()->pos_stock_policy);
    }

    public function test_pos_stock_policy_casts_to_enum(): void
    {
        $company = Company::factory()->create(['pos_stock_policy' => PosStockPolicy::Warn]);

        self::assertSame(PosStockPolicy::Warn, $company->refresh()->pos_stock_policy);
    }
}
```

- [x] **Step 6: Run to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Company/PosStockPolicyMigrationTest.php`
Expected: FAIL — column `pos_stock_policy` does not exist

- [x] **Step 7: Write the migration (column default `block`, backfill by tenant vertical)**

```php
<?php

declare(strict_types=1);

use App\Enums\Vertical;
use App\Modules\Company\Domain\Enums\PosStockPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('pos_stock_policy', 10)
                ->default(PosStockPolicy::Block->value)
                ->after('compliance_profile');
        });

        // NO in-migration backfill: zero tenant migrations in this repo read
        // tenant context (verified — grep tenant() in database/migrations/tenant/
        // is empty), and on fresh signup this migration runs before companies
        // exist. Existing-tenant backfill = the console command below (Step 8a);
        // new companies derive the default at creation (Step 10).
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('pos_stock_policy');
        });
    }
};
```

- [x] **Step 8: Add the cast + property docblock to `Company`**

In `apps/api/app/Modules/Company/Domain/Company.php`: add `@property PosStockPolicy $pos_stock_policy` to the class docblock, add `'pos_stock_policy'` to `$fillable`, and in `casts()` add:

```php
'pos_stock_policy' => PosStockPolicy::class,
```

(Import `App\Modules\Company\Domain\Enums\PosStockPolicy`.)

- [x] **Step 8a: Backfill console command** — `apps/api/app/Modules/Company/Presentation/Console/BackfillPosStockPolicyCommand.php`, signature `pos:stock-policy-backfill {--dry-run}`. Walks the central tenant directory (mirror how `tenant:migrate-rolling` iterates tenants and initializes tenancy per DB), and inside each tenant context runs:

```php
$vertical = $tenant->vertical; // Vertical cast on the central Tenant model (Tenant.php:121-127)
$target = PosStockPolicy::defaultForVertical($vertical);
if ($target === PosStockPolicy::Off) {
    DB::table('companies')->update(['pos_stock_policy' => $target->value]);
}
```

Test (`tests/Feature/Company/BackfillPosStockPolicyCommandTest.php`): a restaurant-vertical tenant's companies flip to `off`; a retail tenant's stay `block`; `--dry-run` reports without writing. Deploy runbook note: run once after `tenant:migrate-rolling`.

- [x] **Step 9: Run tests + static analysis**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Company/PosStockPolicyMigrationTest.php tests/Unit/Company/PosStockPolicyTest.php tests/Feature/Company/BackfillPosStockPolicyCommandTest.php && ./vendor/bin/phpstan analyse app/Modules/Company --no-progress && ./vendor/bin/pint --dirty --test`
Expected: PASS, PHPStan `[OK]`, Pint clean

- [x] **Step 10: New-company creation derives the default** — find the company-creation service (`grep -rn "Company::create\|companies()->create" apps/api/app --include='*.php' | grep -iv test`) and set `pos_stock_policy => PosStockPolicy::defaultForVertical($tenant->vertical)` where the tenant's companies are first created (signup/reference seed path). Add an assertion to the signup/company-creation feature test that a restaurant-vertical tenant's company lands `off`. If creation flows rely on the column default, the explicit derivation in the creation service still wins for Menu verticals (the column default alone would wrongly give them `block`).

- [x] **Step 11: Commit**

```bash
git add apps/api && git commit -m "feat(pos): PosStockPolicy enum + companies.pos_stock_policy with vertical-aware backfill"
```

---

### Task 2: Terminal payload carries `pos_stock_policy` + location address

**Files:**
- Modify: `apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php`
- Test: `apps/api/tests/Feature/POS/TerminalResourcePolicyTest.php`

Spec §4.2 (Codex P1-D): policy is delivered via the terminal payload (terminal → company is unambiguous), NOT `/company/config`. Spec §4.6: the client seller resolver needs the location's address.

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\PosStockPolicy;
use App\Modules\Company\Domain\Location;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Presentation\Resources\TerminalResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TerminalResourcePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminal_resource_exposes_policy_and_location_address(): void
    {
        $company = Company::factory()->create(['pos_stock_policy' => PosStockPolicy::Warn]);
        $location = Location::factory()->for($company)->create([
            'tax_id' => 'TN-BR-001',
            'address_street' => '12 Rue de Marseille',
            'address_city' => 'Tunis',
            'address_postal_code' => '1001',
            'address_country' => 'TN',
        ]);
        $terminal = Terminal::factory()->create([
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);

        $payload = TerminalResource::make($terminal->load('location'))->resolve();

        self::assertSame('warn', $payload['pos_stock_policy']);
        self::assertSame('12 Rue de Marseille', $payload['location']['address_street']);
        self::assertSame('Tunis', $payload['location']['address_city']);
        self::assertSame('1001', $payload['location']['address_postal_code']);
        self::assertSame('TN', $payload['location']['address_country']);
    }
}
```

- [x] **Step 2: Run to verify it fails**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/POS/TerminalResourcePolicyTest.php`
Expected: FAIL — undefined index `pos_stock_policy`

- [x] **Step 3: Extend `TerminalResource`**

In the resource's `toArray()`: alongside the existing location block (`TerminalResource.php:28-36`), add the four address fields; at the top level add the policy resolved through the terminal's company relation (eager-load tolerant):

```php
'pos_stock_policy' => $this->company?->pos_stock_policy->value
    ?? \App\Modules\Company\Domain\Enums\PosStockPolicy::Block->value,
// inside the existing 'location' => [...] block:
'address_street' => $this->location->address_street,
'address_city' => $this->location->address_city,
'address_postal_code' => $this->location->address_postal_code,
'address_country' => $this->location->address_country,
```

`Terminal::company()` already exists (`Terminal.php:146` — verified). Update every `TerminalResource::make($terminal->load('location'))` call site in `TerminalController` to `->load(['location', 'company'])` to avoid N+1 (`grep -n "load('location')" apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php`).

- [x] **Step 4: Run tests + scoped checks**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/POS/TerminalResourcePolicyTest.php tests/Feature/POS/ --filter=Terminal && ./vendor/bin/phpstan analyse app/Modules/POS/Presentation --no-progress`
Expected: PASS (incl. existing terminal tests), PHPStan `[OK]`

- [x] **Step 5: Commit**

```bash
git add apps/api && git commit -m "feat(pos): terminal payload carries pos_stock_policy + location address (policy via terminal, not /company/config)"
```

---

### Task 3: `LocationStockReader` contract + DTOs

**Files:**
- Create: `apps/api/app/Shared/Contracts/LocationStockReader.php`
- Create: `apps/api/app/Shared/DTOs/LocationStockRowDTO.php`
- Create: `apps/api/app/Shared/DTOs/LocationIncomingRowDTO.php`
- Create: `apps/api/app/Shared/DTOs/LocationStockPageDTO.php`

No test yet (interfaces/DTOs are exercised by Task 4's implementation tests). Strict typing — no `mixed`.

- [x] **Step 1: Write the contract + DTOs**

```php
<?php
// app/Shared/Contracts/LocationStockReader.php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\DTOs\LocationStockPageDTO;
use Carbon\CarbonImmutable;

/**
 * Location-scoped stock + incoming read model for the POS device sync.
 * Implemented by Inventory, consumed by POS (spec §4.1).
 *
 * Contract notes:
 * - $updatedSince = null  ⇒ FULL mode: complete stock set for the location
 *   (client treats the union of all pages as replace-all).
 * - $updatedSince set     ⇒ DELTA mode: stock rows with updated_at > cursor.
 * - The incoming set is ALWAYS complete for the location regardless of mode
 *   (incoming changes don't touch destination stock_levels.updated_at).
 */
interface LocationStockReader
{
    public function read(
        string $tenantId,
        string $companyId,
        string $locationId,
        ?CarbonImmutable $updatedSince,
        int $page,
        int $perPage,
    ): LocationStockPageDTO;
}
```

```php
<?php
// app/Shared/DTOs/LocationStockRowDTO.php

declare(strict_types=1);

namespace App\Shared\DTOs;

final readonly class LocationStockRowDTO
{
    public function __construct(
        public string $productId,
        public ?string $variantId,
        /** @var numeric-string scale-4 */
        public string $quantity,
        /** @var numeric-string scale-4 */
        public string $reserved,
        /** @var numeric-string scale-4 quantity − reserved */
        public string $available,
        public string $updatedAt,
    ) {}
}
```

```php
<?php
// app/Shared/DTOs/LocationIncomingRowDTO.php

declare(strict_types=1);

namespace App\Shared\DTOs;

final readonly class LocationIncomingRowDTO
{
    public function __construct(
        public string $productId,
        public ?string $variantId,
        /** @var numeric-string scale-4 in-transit transfer qty toward this location */
        public string $incomingTransfer,
        /** @var numeric-string scale-4 confirmed-PO unreceived qty for this location (product-grain: variantId always null) */
        public string $incomingPo,
    ) {}
}
```

```php
<?php
// app/Shared/DTOs/LocationStockPageDTO.php

declare(strict_types=1);

namespace App\Shared\DTOs;

final readonly class LocationStockPageDTO
{
    public function __construct(
        /** @var list<LocationStockRowDTO> */
        public array $stock,
        /** @var list<LocationIncomingRowDTO> complete set; only on page 1, [] on later pages */
        public array $incoming,
        public int $page,
        public int $lastPage,
        public int $total,
    ) {}
}
```

- [x] **Step 2: Scoped checks + commit**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Shared --no-progress && ./vendor/bin/pint --dirty --test`
Expected: `[OK]`, clean

```bash
git add apps/api/app/Shared && git commit -m "feat(inventory): LocationStockReader contract + DTOs (POS location stock feed)"
```

---

### Task 4: Inventory implementation — `LocationStockQueryService`

**Files:**
- Create: `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php`
- Modify: Inventory service provider (find with `grep -rn "class .*ServiceProvider" apps/api/app/Modules/Inventory/Infrastructure/Providers/`) — bind `LocationStockReader::class => LocationStockQueryService::class`
- Test: `apps/api/tests/Feature/Inventory/LocationStockQueryServiceTest.php`

- [x] **Step 1: Write the failing tests** (one test class, real models, `RefreshDatabase`; use existing factories — `StockLevel`, `Product`, `Location`, `StockTransfer`/`StockTransferLine` (see `tests/Feature/Inventory/StockTransferVariantTest.php` for transfer factory/setup idioms), `Document`/`DocumentLine` for the PO term)

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Application\Services\LocationStockQueryService;
use App\Shared\Contracts\LocationStockReader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LocationStockQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function reader(): LocationStockReader
    {
        return app(LocationStockQueryService::class); // test bootstrap only — production callers constructor-inject
    }

    public function test_full_mode_returns_complete_location_stock_with_variant_grain(): void
    {
        // Arrange: 2 stock rows at location A (one variant, one product-grain),
        // 1 row at location B. Act: read(tenant, company, locationA, null, 1, 500).
        // Assert: exactly the 2 location-A rows; available = quantity − reserved
        // as scale-4 strings; location-B row absent.
    }

    public function test_delta_mode_filters_on_updated_at(): void
    {
        // Arrange: row1 updated 2026-06-10, row2 updated 2026-06-12.
        // Act: read(..., updatedSince: 2026-06-11T00:00:00Z, ...).
        // Assert: only row2 in stock; incoming still complete.
    }

    public function test_incoming_transfer_counts_only_in_transit_toward_this_location(): void
    {
        // Arrange transfers toward location A: one InTransit (qty 6, variant V),
        // one Draft, one Completed, one Cancelled; plus one InTransit toward
        // location B. Assert: incoming has exactly one row (product, V,
        // incoming_transfer '6.0000'); Draft/Completed/Cancelled and the
        // location-B transfer contribute nothing.
    }

    public function test_incoming_po_is_location_scoped_unreceived_remainder(): void
    {
        // Arrange: confirmed PO line at location A qty 10, quantity_received 4;
        // confirmed PO line at location B; draft PO line at location A.
        // Assert: incoming_po '6.0000' on the product-grain row (variantId null);
        // other lines contribute nothing.
    }

    public function test_incoming_is_complete_even_in_delta_mode_with_empty_stock_delta(): void
    {
        // Arrange: stock untouched since cursor, but an InTransit transfer exists.
        // Act: delta read with cursor after all stock updates.
        // Assert: stock = [], incoming non-empty. (Spec §4.1 — the reason
        // incoming is never delta-filtered.)
    }

    public function test_incoming_present_only_on_page_one(): void
    {
        // Arrange: 3 stock rows, perPage 2 → 2 pages. Assert page 1 has the
        // incoming set, page 2 has incoming = [].
    }
}
```

Write the Arrange/Act/Assert bodies with real factories — every assertion above is the contract; do not weaken them. Use `bcadd`-free assertions on exact strings (`'6.0000'`).

- [x] **Step 2: Run to verify failure**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/LocationStockQueryServiceTest.php`
Expected: FAIL — class not found

- [x] **Step 3: Implement the service**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\StockLevel;
use App\Shared\Contracts\LocationStockReader;
use App\Shared\DTOs\LocationIncomingRowDTO;
use App\Shared\DTOs\LocationStockPageDTO;
use App\Shared\DTOs\LocationStockRowDTO;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class LocationStockQueryService implements LocationStockReader
{
    public function read(
        string $tenantId,
        string $companyId,
        string $locationId,
        ?CarbonImmutable $updatedSince,
        int $page,
        int $perPage,
    ): LocationStockPageDTO {
        $paginator = StockLevel::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('location_id', $locationId)
            ->when($updatedSince !== null, fn ($q) => $q->where('updated_at', '>', $updatedSince))
            ->orderBy('product_id')->orderBy('variant_id')
            ->paginate(perPage: $perPage, page: $page);

        $stock = [];
        /** @var StockLevel $level */
        foreach ($paginator->items() as $level) {
            $stock[] = new LocationStockRowDTO(
                productId: (string) $level->product_id,
                variantId: $level->variant_id !== null ? (string) $level->variant_id : null,
                quantity: $level->quantity,
                reserved: $level->reserved,
                available: $level->getAvailableQuantity(),
                updatedAt: (string) $level->updated_at?->toIso8601String(),
            );
        }

        return new LocationStockPageDTO(
            stock: $stock,
            incoming: $page === 1 ? $this->incoming($companyId, $locationId) : [],
            page: $paginator->currentPage(),
            lastPage: $paginator->lastPage(),
            total: $paginator->total(),
        );
    }

    /** @return list<LocationIncomingRowDTO> */
    private function incoming(string $companyId, string $locationId): array
    {
        // Term 1 — in-transit transfers toward this location, variant grain.
        // (Source rows were already decremented at initiate; spec §2.1.)
        $transfers = DB::table('stock_transfer_lines')
            ->join('stock_transfers', 'stock_transfer_lines.transfer_id', '=', 'stock_transfers.id')
            ->where('stock_transfers.company_id', $companyId)
            ->where('stock_transfers.destination_location_id', $locationId)
            ->where('stock_transfers.status', TransferStatus::InTransit->value)
            ->groupBy('stock_transfer_lines.product_id', 'stock_transfer_lines.variant_id')
            ->selectRaw('stock_transfer_lines.product_id, stock_transfer_lines.variant_id, SUM(stock_transfer_lines.quantity) AS qty')
            ->get();

        // Term 2 — confirmed-PO unreceived remainder for this location
        // (mirrors ProductController::stockLevels:612-626, location-scoped;
        // product-grain — document_lines has no variant_id; spec §4.7.3).
        $pos = DB::table('document_lines')
            ->join('documents', 'document_lines.document_id', '=', 'documents.id')
            ->where('documents.type', \App\Modules\Document\Domain\Enums\DocumentType::PurchaseOrder->value)
            ->where('documents.status', \App\Modules\Document\Domain\Enums\DocumentStatus::Confirmed->value)
            ->where('documents.company_id', $companyId)
            ->where('document_lines.location_id', $locationId)
            ->whereRaw('document_lines.quantity > COALESCE(document_lines.quantity_received, 0)')
            ->groupBy('document_lines.product_id')
            ->selectRaw('document_lines.product_id, SUM(document_lines.quantity - COALESCE(document_lines.quantity_received, 0)) AS qty')
            ->get();

        /** @var array<string, array{t: numeric-string, p: numeric-string}> $byKey */
        $byKey = [];
        foreach ($transfers as $row) {
            $key = $row->product_id.'|'.($row->variant_id ?? '');
            $byKey[$key] = ['t' => $this->qty($row->qty), 'p' => '0.0000'];
        }
        foreach ($pos as $row) {
            $key = $row->product_id.'|';
            $byKey[$key] = [
                't' => $byKey[$key]['t'] ?? '0.0000',
                'p' => $this->qty($row->qty),
            ];
        }

        $out = [];
        foreach ($byKey as $key, $sums) {
            [$productId, $variantId] = explode('|', $key, 2);
            $out[] = new LocationIncomingRowDTO(
                productId: $productId,
                variantId: $variantId === '' ? null : $variantId,
                incomingTransfer: $sums['t'],
                incomingPo: $sums['p'],
            );
        }

        return $out;
    }

    /** Normalize a DB aggregate to a scale-4 quantity string via the shared helper. */
    private function qty(mixed $value): string
    {
        /** @var numeric-string $str */
        $str = is_string($value) ? $value : (string) $value;

        return \App\Shared\Domain\QuantityScale::round($str, 4, \App\Shared\Domain\QuantityScale::FLOOR);
    }
}
```

> NOTE for the implementer: `foreach ($byKey as $key, $sums)` is pseudocode — use `foreach ($byKey as $key => $sums)`. Check `QuantityScale::round()`'s real signature (`apps/api/app/Shared/Domain/QuantityScale.php:33`) and the DocumentType/DocumentStatus enum namespaces (memory: the column is `documents.type`, enum `Document\Domain\Enums\DocumentType`) before relying on the snippets — match the codebase, not this listing. The PHPStan rule `ForbidHardcodedBcmathScale` governs bcmath calls; the literal `4` here is QuantityScale's documented quantity scale parameter, not a bcmath scale — if the rule still flags it, use the module's existing scale constant (grep `QTY_SCALE`).
> Cross-DB caution (memory: T1 transfer migrations broke under db-per-tenant): both joins above are tenant-DB-internal tables — no central-DB join. Keep it that way.

- [x] **Step 4: Bind in the Inventory provider**

```php
$this->app->bind(
    \App\Shared\Contracts\LocationStockReader::class,
    \App\Modules\Inventory\Application\Services\LocationStockQueryService::class,
);
```

- [x] **Step 5: Run tests + checks**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/LocationStockQueryServiceTest.php && ./vendor/bin/phpstan analyse app/Modules/Inventory app/Shared --no-progress && ./vendor/bin/pint --dirty --test`
Expected: 6 tests PASS, `[OK]`, clean. Also run `composer deptrac` if a ratchet exists (Shared←Inventory is an allowed direction; verify no new violation).

- [x] **Step 6: Commit**

```bash
git add apps/api && git commit -m "feat(inventory): LocationStockQueryService — location stock delta/full + in-transit transfer & confirmed-PO incoming"
```

---

### Task 5: POS endpoint `GET /pos/stock-levels`

**Files:**
- Create: `apps/api/app/Modules/POS/Presentation/Controllers/PosStockLevelController.php`
- Modify: `apps/api/app/Modules/POS/routes.php`
- Test: `apps/api/tests/Feature/POS/PosStockLevelEndpointTest.php`

- [x] **Step 1: Write the failing endpoint tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PosStockLevelEndpointTest extends TestCase
{
    use RefreshDatabase;

    // Setup helper: authenticated user + company (X-Company-Id header),
    // terminal T at location A — follow the existing POS endpoint test
    // idioms in tests/Feature/POS/ (seeded permissions via
    // RolesAndPermissionsSeeder; valid UUIDs for all FKs).

    public function test_returns_stock_for_the_terminals_location_only(): void
    {
        // Stock at location A and location B; GET /api/v1/pos/stock-levels?terminal_id=T
        // → only location-A rows; quantities are strings.
    }

    public function test_terminal_id_is_required_and_company_scoped(): void
    {
        // Missing terminal_id → 422. terminal_id belonging to ANOTHER company
        // → 404 — a device cannot read across companies.
    }

    public function test_requires_pos_operate_terminal_permission(): void
    {
        // A user without pos.operate_terminal → 403 (same gate as ShiftController).
    }

    public function test_as_of_is_server_issued_and_echoes_into_next_delta(): void
    {
        // Response carries data.as_of (ISO8601). Calling again with
        // updated_since = as_of returns only rows updated after it.
    }

    public function test_full_vs_delta_modes(): void
    {
        // No updated_since → all rows. updated_since in the future → stock []
        // but incoming still present (page 1).
    }

    public function test_unauthenticated_is_401(): void
    {
        // No token → 401 (the 'api' + auth:sanctum middleware contract).
    }
}
```

- [x] **Step 2: Run to verify failure**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/POS/PosStockLevelEndpointTest.php`
Expected: FAIL — 404 route not found

- [x] **Step 3: Implement controller + route**

```php
<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Application\Services\CompanyContext;
use App\Modules\POS\Domain\Terminal;
use App\Shared\Contracts\LocationStockReader;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PosStockLevelController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LocationStockReader $stockReader,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // terminal_id is client-supplied + company-scoped + permission-gated —
        // the SAME trust boundary as every POS endpoint (ShiftController takes
        // terminal_code from the request, ShiftController.php:50). There is no
        // server-side terminal session to resolve from. The LOCATION is never
        // client-supplied (spec §4.1).
        \Illuminate\Support\Facades\Gate::authorize('pos.operate_terminal');

        $company = $this->companyContext->requireCompany();

        $validated = $request->validate([
            'terminal_id' => ['required', 'uuid'],
            'updated_since' => ['sometimes', 'date'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        /** @var Terminal|null $terminal */
        $terminal = Terminal::query()
            ->where('company_id', $company->id)
            ->where('id', $validated['terminal_id'])
            ->first();

        if ($terminal === null || $terminal->location_id === null) {
            return response()->json([
                'error' => ['code' => 'TERMINAL_NOT_FOUND', 'message' => 'Terminal not found for this company'],
            ], 404);
        }

        // Location comes from the TERMINAL, never from the request (spec §4.1).
        $asOf = now()->toIso8601String();
        $page = $this->stockReader->read(
            tenantId: (string) $company->tenant_id,
            companyId: (string) $company->id,
            locationId: (string) $terminal->location_id,
            updatedSince: isset($validated['updated_since'])
                ? CarbonImmutable::parse((string) $validated['updated_since'])
                : null,
            page: (int) ($validated['page'] ?? 1),
            perPage: 500,
        );

        return response()->json([
            'data' => [
                'stock' => array_map(fn ($r) => [
                    'product_id' => $r->productId,
                    'variant_id' => $r->variantId,
                    'quantity' => $r->quantity,
                    'reserved' => $r->reserved,
                    'available' => $r->available,
                    'updated_at' => $r->updatedAt,
                ], $page->stock),
                'incoming' => array_map(fn ($r) => [
                    'product_id' => $r->productId,
                    'variant_id' => $r->variantId,
                    'incoming_transfer' => $r->incomingTransfer,
                    'incoming_po' => $r->incomingPo,
                ], $page->incoming),
                'as_of' => $asOf,
            ],
            'meta' => [
                'pagination' => [
                    'current_page' => $page->page,
                    'last_page' => $page->lastPage,
                    'total' => $page->total,
                ],
            ],
        ]);
    }
}
```

Route (inside the existing `Route::prefix('api/v1')->middleware([...])` group in `apps/api/app/Modules/POS/routes.php` — the group already carries `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]`, Rule 12):

```php
// Location stock feed for the device sync (spec 2026-06-11 §4.1)
Route::get('/pos/stock-levels', [PosStockLevelController::class, 'index']);
```

`as_of` is captured BEFORE the read so a row updated mid-request is re-sent next delta rather than skipped (overlap is safe — upserts are idempotent; gaps are not). This is advisory-only overlap handling under READ COMMITTED — no isolation-level change (Codex P2-3).

- [x] **Step 4: Run tests + checks**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/POS/PosStockLevelEndpointTest.php && ./vendor/bin/phpstan analyse app/Modules/POS --no-progress && ./vendor/bin/pint --dirty --test`
Expected: PASS, `[OK]`, clean

- [x] **Step 5: Commit**

```bash
git add apps/api && git commit -m "feat(pos): GET /pos/stock-levels — terminal-located stock feed (delta/full + incoming, server as_of cursor)"
```

---

### Task 6: Policy-aware draft enforcement

**Files:**
- Modify: `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php` (`decrementStock` ~`:868-948`, composite leaf path `deductCompositeItemStock` ~`:1158-1209`)
- Test: `apps/api/tests/Feature/POS/ReceiptStockPolicyTest.php`

- [x] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReceiptStockPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_block_policy_rejects_insufficient_stock(): void
    {
        // company pos_stock_policy = Block; stock 1, sell 2 → RuntimeException
        // (current behavior preserved — pin it).
    }

    public function test_warn_policy_proceeds_and_goes_negative(): void
    {
        // policy = Warn; stock 1, sell 2 → receipt created, stock_levels
        // quantity '-1.0000', a warning log entry.
    }

    public function test_off_policy_proceeds(): void
    {
        // policy = Off; same arrangement → receipt created.
    }

    public function test_composite_leaf_deduction_respects_policy(): void
    {
        // Composite item whose leaf product has 0 stock; policy = Off →
        // sale succeeds; policy = Block → rejected.
    }

    public function test_projection_path_is_unchanged(): void
    {
        // Regression pin: PosCoreReceiptProjection still warn-and-continue
        // under Block (a signed fiscal event always lands). Reuse the
        // existing projection test arrangement from tests covering
        // PosCoreReceiptProjection::decrementStock.
    }
}
```

- [x] **Step 2: Run to verify failure**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/POS/ReceiptStockPolicyTest.php`
Expected: warn/off tests FAIL (RuntimeException currently always thrown)

- [x] **Step 3: Implement**

In `ReceiptCreationService::decrementStock`, replace the unconditional throw (`:896-912`) with:

```php
$available = $stockLevel->getAvailableQuantity();
if (bccomp($available, $quantity, 4) < 0) {
    $policy = $company->pos_stock_policy; // Company resolved via CompanyContext at the call boundary; thread it as a param if not already in scope
    if ($policy === PosStockPolicy::Block) {
        throw new \RuntimeException(
            "Insufficient stock for '{$productName}'. Available: {$available}, Requested: {$quantity}"
        );
    }
    Log::warning('POS sale proceeding despite insufficient stock (policy '.$policy->value.')', [
        'product_id' => $productId,
        'available' => $available,
        'requested' => $quantity,
    ]);
}
```

Keep the existing bcmath scale source exactly as the surrounding code does (it already uses the stock scale there — don't introduce a new literal if the file uses a constant). Thread the company (already available via `$this->companyContext->requireCompany()` at the createReceipt boundary) down to both `decrementStock` and the composite leaf path — one policy read per receipt, not per line. Do NOT touch `PosCoreReceiptProjection`.

- [x] **Step 4: Run tests + checks**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/POS/ReceiptStockPolicyTest.php tests/Feature/POS/ --filter=Receipt && ./vendor/bin/phpstan analyse app/Modules/POS --no-progress`
Expected: PASS (incl. pre-existing receipt tests — `block` default preserves old behavior), `[OK]`

- [x] **Step 5: Commit**

```bash
git add apps/api && git commit -m "feat(pos): policy-aware stock enforcement on the draft path (block throws; warn/off proceed; projection untouched)"
```

---

### Task 7: Seller-coherence validator test (server-side pin for §4.6)

**Files:**
- Test: `apps/api/tests/Feature/Fiscal/LocationSellerCoherenceTest.php`

No production change — this pins that a location-sourced seller block passes canonical validation (the no-version-bump rationale, Codex BLOCKER-E adjudication).

- [x] **Step 1: Write the test (it should PASS immediately — it is a contract pin, not TDD red)**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use Tests\TestCase;

final class LocationSellerCoherenceTest extends TestCase
{
    public function test_location_identity_seller_block_passes_canonical_validation(): void
    {
        // Build a canonical SALE_RECEIPT v2 payload (reuse the existing
        // canonical fixture builder used by FiscalPayloadConstraintValidator
        // tests — grep "seller" tests/Feature/Fiscal/ or tests/Unit/Fiscal/
        // for the fixture idiom) whose seller block uses a LOCATION identity:
        //   tax_number  = 'TN-BR-001' (branch), country 'TN',
        //   street/city/postal_code = the branch address.
        // Assert FiscalPayloadConstraintValidator accepts it (no findings /
        // no quarantine) — validation is structural (key set, tax-number
        // format vs country, full address), never equality-vs-company
        // (FiscalPayloadConstraintValidator.php:1541-1569, 2391-2420).
    }

    public function test_tunisian_branch_tax_number_format_validates_against_tn(): void
    {
        // Same, with a real TN-format branch tax number — guards the
        // tax-number-vs-country rule against the branch values we will author.
    }
}
```

- [x] **Step 2: Run; expected PASS. If it FAILS, STOP — the no-version-bump rationale is broken; escalate to the owner before continuing.**

Run: `cd apps/api && ./vendor/bin/phpunit tests/Feature/Fiscal/LocationSellerCoherenceTest.php`

- [x] **Step 3: Commit**

```bash
git add apps/api/tests && git commit -m "test(fiscal): pin — location-identity seller block passes canonical validation (no version bump needed)"
```

---

## Phase 2 — Client (apps/pos)

### Task 8: SQLite migration + `locationStockRepository`

**Files:**
- Modify: `apps/pos/src/lib/db/migrations.ts` (append next version — last is 49; use 50, or the actual next if dev moved)
- Create: `apps/pos/src/lib/db/repositories/locationStockRepository.ts`
- Test: `apps/pos/src/lib/db/repositories/__tests__/locationStockRepository.test.ts`

- [x] **Step 1: Append the migration**

```ts
{
  version: 50,
  name: 'create_location_stock',
  sql: '',
  async run(db) {
    const statements = [
      `CREATE TABLE IF NOT EXISTS location_stock (
      product_id TEXT NOT NULL,
      variant_id TEXT NOT NULL DEFAULT '',
      quantity TEXT NOT NULL DEFAULT '0',
      reserved TEXT NOT NULL DEFAULT '0',
      available TEXT NOT NULL DEFAULT '0',
      incoming_transfer TEXT NOT NULL DEFAULT '0',
      incoming_po TEXT NOT NULL DEFAULT '0',
      updated_at TEXT,
      PRIMARY KEY (product_id, variant_id)
    )`,
      `CREATE INDEX IF NOT EXISTS idx_location_stock_product ON location_stock(product_id)`,
    ];
    for (const sql of statements) await db.execute(sql);
  },
},
```

(The real migration-object shape is `{version, name, sql, async run(db)}` — Codex plan-review P1-3, verified at `migrations.ts:1238-1248`. Match the `run(db)` body style of the v41 entry exactly. `variant_id` uses `''` for product-grain because SQLite PKs reject NULL; spec §4.3.)

- [x] **Step 2: Write failing repository tests** (follow the existing repository test setup in `apps/pos/src/lib/db/repositories/__tests__/` — they run against the SQL mock/in-memory harness used by sibling repo tests; if repos there are tested via integration-only, mirror that decision and note it)

Cases:
- `upsertStockRows` inserts then updates on conflict (variant `null` → `''` key).
- `replaceAllStock` deletes rows absent from the payload, keeps/updates present ones, preserves incoming columns only for surviving rows.
- `replaceIncoming` zeroes `incoming_transfer`/`incoming_po` on ALL rows then applies the new set (creating rows that have incoming but no stock row — available stays '0').
- `getStockFor(productId, variantId | null)` returns the row or null.
- `deleteForProducts(ids)` removes rows (catalog tombstone hook).

- [x] **Step 3: Implement**

```ts
import type Database from '@tauri-apps/plugin-sql';
import { execute, queryAll, queryOne } from '../client'; // match the helper import used by sibling repositories (read productRepository.ts:1-20 for the exact names)

export interface LocationStockRow {
  product_id: string;
  variant_id: string; // '' = product-grain
  quantity: string;
  reserved: string;
  available: string;
  incoming_transfer: string;
  incoming_po: string;
  updated_at: string | null;
}

export interface ServerStockRow {
  product_id: string;
  variant_id: string | null;
  quantity: string;
  reserved: string;
  available: string;
  updated_at: string;
}

export interface ServerIncomingRow {
  product_id: string;
  variant_id: string | null;
  incoming_transfer: string;
  incoming_po: string;
}

const key = (variantId: string | null): string => variantId ?? '';

export async function upsertStockRows(db: Database, rows: ServerStockRow[]): Promise<void> {
  for (const r of rows) {
    await execute(
      db,
      `INSERT INTO location_stock (product_id, variant_id, quantity, reserved, available, updated_at)
       VALUES ($1, $2, $3, $4, $5, $6)
       ON CONFLICT(product_id, variant_id) DO UPDATE SET
         quantity = excluded.quantity,
         reserved = excluded.reserved,
         available = excluded.available,
         updated_at = excluded.updated_at`,
      [r.product_id, key(r.variant_id), r.quantity, r.reserved, r.available, r.updated_at],
    );
  }
}

export async function replaceAllStock(db: Database, rows: ServerStockRow[]): Promise<void> {
  // Full-reconciliation mode (spec §4.1/§4.3): replace-all = upsert the full
  // set, then delete local rows the server no longer has.
  await upsertStockRows(db, rows);
  const keep = rows.map((r) => `${r.product_id}|${key(r.variant_id)}`);
  const existing = await queryAll<{ product_id: string; variant_id: string }>(
    db,
    'SELECT product_id, variant_id FROM location_stock',
  );
  for (const row of existing) {
    if (!keep.includes(`${row.product_id}|${row.variant_id}`)) {
      await execute(
        db,
        'DELETE FROM location_stock WHERE product_id = $1 AND variant_id = $2',
        [row.product_id, row.variant_id],
      );
    }
  }
}

export async function replaceIncoming(db: Database, rows: ServerIncomingRow[]): Promise<void> {
  // Incoming arrives COMPLETE every pull (spec §4.1) — wholesale replace:
  await execute(db, `UPDATE location_stock SET incoming_transfer = '0', incoming_po = '0'`);
  for (const r of rows) {
    await execute(
      db,
      `INSERT INTO location_stock (product_id, variant_id, incoming_transfer, incoming_po)
       VALUES ($1, $2, $3, $4)
       ON CONFLICT(product_id, variant_id) DO UPDATE SET
         incoming_transfer = excluded.incoming_transfer,
         incoming_po = excluded.incoming_po`,
      [r.product_id, key(r.variant_id), r.incoming_transfer, r.incoming_po],
    );
  }
}

export async function getStockFor(
  db: Database,
  productId: string,
  variantId: string | null,
): Promise<LocationStockRow | null> {
  return queryOne<LocationStockRow>(
    db,
    'SELECT * FROM location_stock WHERE product_id = $1 AND variant_id = $2',
    [productId, key(variantId)],
  );
}

export async function deleteForProducts(db: Database, productIds: string[]): Promise<void> {
  for (const id of productIds) {
    await execute(db, 'DELETE FROM location_stock WHERE product_id = $1', [id]);
  }
}
```

Use a `keep` `Set` rather than `Array.includes` if row counts are large (5K products) — `const keep = new Set(...)`. No `parseFloat` anywhere in this file (ESLint `no-parsefloat-on-money` guards money; quantities follow the same string discipline).

- [x] **Step 4: Run tests**

Run: `cd apps/pos && pnpm vitest run src/lib/db/repositories/__tests__/locationStockRepository.test.ts`
Expected: PASS

- [x] **Step 5: Hook catalog tombstones** — in `syncService.ts` where `deleteProducts(db, deletedIds)` is called (~`:573-575`), also call `deleteForProducts(db, deletedIds)`. Add a test case to the existing pullProducts test file pinning that product deletion cascades to `location_stock`.

- [x] **Step 6: Commit**

```bash
git add apps/pos && git commit -m "feat(pos): location_stock SQLite table + repository (upsert/replace-all/incoming/tombstone-cascade)"
```

---

### Task 9: `pullLocationStock` sync

**Files:**
- Create: `apps/pos/src/api/stockApi.ts`
- Modify: `apps/pos/src/lib/sync/syncService.ts`
- Test: `apps/pos/src/lib/sync/__tests__/pullLocationStock.test.ts`

- [x] **Step 1: stockApi**

```ts
import { api, type ApiRequestOptions } from '@/lib/api'; // raw client — NOT apiGet (see note below); match the actual raw-request export in lib/api.ts
import type { ServerIncomingRow, ServerStockRow } from '@/lib/db/repositories/locationStockRepository';

export interface LocationStockPage {
  data: {
    stock: ServerStockRow[];
    incoming: ServerIncomingRow[];
    as_of: string;
  };
  meta: {
    pagination: { current_page: number; last_page: number; total: number };
  };
}

export async function fetchLocationStock(
  terminalId: string,
  params: { updated_since?: string; page?: string },
  opts?: ApiRequestOptions,
): Promise<LocationStockPage> {
  const response = await api.get<LocationStockPage>(
    '/pos/stock-levels',
    { terminal_id: terminalId, ...params },
    opts,
  );
  return response.data; // preserve the { data, meta } wrapper — meta carries pagination
}
```

> MANDATORY (Codex plan-review P1-7 + r2 minor edit): do NOT use `apiGet` here — it unwraps `response.data.data` and silently DROPS `meta`, so pagination would stop after page 1 (the memory-documented paginated-endpoint pitfall, cf. CompositeItemListPage). The raw form above preserves `meta.pagination.last_page`. Match the exact raw-request helper that `lib/api.ts` actually exports (grep how web-admin paginated fetches do it).

- [x] **Step 2: Write failing sync tests**

Cases (mock `fetchLocationStock` + in-memory repo, following the existing pullProducts test idiom in `apps/pos/src/lib/sync/__tests__/`):
- Delta pull sends the PERSISTED server `as_of` as `updated_since`, never device time (assert the param equals the stored metadata value, then that the NEW `as_of` from the response is stored).
- Full pull (mode 'full') omits `updated_since` and calls `replaceAllStock`; delta calls `upsertStockRows`.
- `replaceIncoming` called on every pull with the complete incoming array.
- Menu tenant (`hasModule(config, 'Menu')`) → returns 0, no API call.
- FetchTimeoutError propagates; 5xx → typed error class consistent with `PullProductsError`; a failed pull leaves cursor + table untouched.
- Multi-page: pages until `last_page`, single `replaceIncoming` from page 1's incoming.

- [x] **Step 3: Implement `pullLocationStock(db, mode: 'full' | 'delta')` in syncService.ts**

```ts
const STOCK_CURSOR_KEY = 'location_stock_as_of';
const STOCK_LAST_SYNC_KEY = 'stock_last_sync';

export async function pullLocationStock(
  db: Database,
  mode: 'full' | 'delta',
  opts?: ApiRequestOptions,
): Promise<{ count: number }> {
  const config = await getCompanyConfigCached(); // same source pullProducts uses (syncService.ts:625-667)
  if (config && hasModule(config, 'Menu')) return { count: 0 };

  const terminal = useTerminalStore.getState().terminal;
  if (!terminal) return { count: 0 };

  const cursor = mode === 'delta' ? await getSyncMetadata(db, STOCK_CURSOR_KEY) : null;

  let page = 1;
  let lastPage = 1;
  let asOf: string | null = null;
  let total = 0;
  // Accumulators live OUTSIDE the page loop (Codex P2-2): replaceAllStock gets
  // the union of ALL pages — per-page replace would delete everything but the
  // last page. No DB write happens until every page has been fetched.
  const allStock: ServerStockRow[] = [];
  let incoming: ServerIncomingRow[] = [];

  do {
    const result = await fetchLocationStock(
      terminal.id,
      { ...(cursor ? { updated_since: cursor } : {}), page: String(page) },
      opts,
    ); // error classification mirrors pullProductsCore (FetchTimeoutError verbatim; 5xx/parse/network typed)
    allStock.push(...result.data.stock);
    if (page === 1) {
      incoming = result.data.incoming;
      asOf = result.data.as_of;
    }
    lastPage = result.meta.pagination.last_page;
    total += result.data.stock.length;
    page += 1;
  } while (page <= lastPage);

  if (mode === 'full') {
    await replaceAllStock(db, allStock);
  } else {
    await upsertStockRows(db, allStock);
  }
  await replaceIncoming(db, incoming);

  if (asOf) await setSyncMetadata(db, STOCK_CURSOR_KEY, asOf); // server-issued cursor — NEVER new Date() (spec §4.1, Codex P2-B)
  await setSyncMetadata(db, STOCK_LAST_SYNC_KEY, asOf ?? '');

  return { count: total };
}
```

(Adjust the `result.data`/`result.meta` access to the `api.get` form chosen in Step 1. Persist cursor/table only after ALL pages succeed — partial multi-page failure must not advance the cursor.)

- [x] **Step 4: Wire the cadence** (spec §4.3) — in the existing sync orchestration (`syncService.ts:1601-1643` push-then-pull cycle):
- periodic tick: `pullLocationStock(db, 'delta')` alongside the product pull;
- after a successful offline-receipt drain (where receipts are marked synced): `pullLocationStock(db, 'delta')`;
- terminal claim/boot and shift open: `pullLocationStock(db, 'full')` (claim flow + `ShiftManagementService`/shift-open hook on the client — grep `openShift` in `apps/pos/src` for the call site).
A stock-pull failure must never block the cycle: wrap in the same swallow-and-log pattern `pullProducts` uses.

- [x] **Step 5: Run tests**

Run: `cd apps/pos && pnpm vitest run src/lib/sync/__tests__/pullLocationStock.test.ts && pnpm typecheck`
Expected: PASS, clean

- [x] **Step 6: Commit**

```bash
git add apps/pos && git commit -m "feat(pos): pullLocationStock — delta/full sync with server-issued cursor, drain re-baseline, Menu skip"
```

---

### Task 10: Availability selector

**Files:**
- Create: `apps/pos/src/lib/stock/availability.ts`
- Test: `apps/pos/src/lib/stock/__tests__/availability.test.ts`

- [x] **Step 1: Write failing tests**

Cases (pure function — pass inputs explicitly, no store mocking needed):
- missing stock row ⇒ 0; `'10.0000'` available, none pending, empty cart ⇒ `'10.0000'`.
- pending unsynced receipts subtract (product+variant matched; `''`/`null` variant normalization both directions).
- current-cart lines subtract.
- clamp at `'0'` (never negative).
- exemptions: `is_physical === false` ⇒ `null` (= not stock-managed); Menu/composite sellable (`sellable_type !== 'product'`) ⇒ `null`.
- string decimal comparison helper used (no `parseFloat` — reuse the existing bc-style helpers the POS uses for money, grep `bccomp|bcsub` in `apps/pos/src/lib`).

```ts
import { describe, expect, it } from 'vitest';
import { effectiveAvailable, type AvailabilityInputs } from '../availability';

const base: AvailabilityInputs = {
  product: { id: 'p1', is_physical: true, sellable_type: 'product' },
  variantId: null,
  stockRow: { available: '10.0000', incoming_transfer: '0', incoming_po: '0' },
  pendingSaleQty: '0',
  cartQty: '0',
};

describe('effectiveAvailable', () => {
  it('returns server available when nothing is pending', () => {
    expect(effectiveAvailable(base)).toBe('10.0000');
  });

  it('subtracts pending unsynced sales and cart lines', () => {
    expect(effectiveAvailable({ ...base, pendingSaleQty: '3.0000', cartQty: '2.0000' })).toBe('5.0000');
  });

  it('clamps at zero', () => {
    expect(effectiveAvailable({ ...base, pendingSaleQty: '11.0000' })).toBe('0.0000');
  });

  it('treats a missing stock row as zero', () => {
    expect(effectiveAvailable({ ...base, stockRow: null })).toBe('0.0000');
  });

  it('exempts non-physical products', () => {
    expect(effectiveAvailable({ ...base, product: { ...base.product, is_physical: false } })).toBeNull();
  });

  it('exempts non-product sellables (menu/composite)', () => {
    expect(effectiveAvailable({ ...base, product: { ...base.product, sellable_type: 'composite_item' } })).toBeNull();
  });
});
```

- [x] **Step 2: Run to verify failure**, then **Step 3: implement** —

```ts
// apps/pos/src/lib/stock/availability.ts
// Single source of truth for location sellability (spec §4.4).
// `null` = not stock-managed (exempt) — callers treat as always sellable.

import { bcsub, bccomp } from '@/lib/decimal'; // verified: apps/pos/src/lib/decimal.ts:26,42 — NOTE its default scale is 3; ALWAYS pass 4 explicitly for quantities

export interface AvailabilityInputs {
  product: { id: string; is_physical: boolean; sellable_type?: string | null };
  variantId: string | null;
  stockRow: { available: string } | null;
  pendingSaleQty: string; // Σ unsynced offline-receipt lines for (product, variant)
  cartQty: string;        // Σ current-cart lines for (product, variant)
}

const QTY_DP = 4;

export function effectiveAvailable(input: AvailabilityInputs): string | null {
  if (!input.product.is_physical) return null;
  if ((input.product.sellable_type ?? 'product') !== 'product') return null;

  const server = input.stockRow?.available ?? '0';
  const afterPending = bcsub(server, input.pendingSaleQty, QTY_DP);
  const afterCart = bcsub(afterPending, input.cartQty, QTY_DP);
  return bccomp(afterCart, '0') < 0 ? (0).toFixed(QTY_DP) : afterCart;
}
```

Plus an async assembler `getEffectiveAvailable(db, product, variantId, cartLines)` in the same file that loads `stockRow` via `getStockFor`, computes `pendingSaleQty` over the unsynced offline receipts, and sums `cartQty` from the passed cart lines. **`offline_receipts.lines` is a JSON TEXT blob (`migrations.ts:99`, Codex plan-review P1-5)** — so: `SELECT lines FROM offline_receipts WHERE synced = 0` (match the actual unsynced predicate used by the drain — grep `synced` in `receiptService.ts`), then `JSON.parse` each and `bcsum` the matching (product, variant) quantities at scale 4 in TypeScript. Pending receipts are a small bounded set; no `json_each` SQL needed. Snapshot changes never evict cart lines (spec §4.4, Codex r2): the selector only gates FURTHER adds.

- [x] **Step 4: Run + commit**

Run: `cd apps/pos && pnpm vitest run src/lib/stock/__tests__/availability.test.ts && pnpm typecheck`

```bash
git add apps/pos && git commit -m "feat(pos): effectiveAvailable — location availability selector (snapshot − pending − cart, clamped, exemptions)"
```

---

### Task 11: Cart-ingress enforcement + i18n

**Files:**
- Modify: `apps/pos/src/stores/cartStore.ts` (`addItem` ~`:240`, `addItemWithDefaults` ~`:325`, quantity-increment action)
- Modify: the barcode-scan add path (grep `fetchProductByBarcode` consumers → the scan resolver that calls `addItem`)
- Modify: `apps/pos/src/locales/en/pos.json`, `apps/pos/src/locales/fr/pos.json`
- Test: `apps/pos/src/stores/__tests__/cartStockEnforcement.test.ts`

- [x] **Step 1: Write failing tests**

Cases:
- policy `block` + effectiveAvailable `'0.0000'` ⇒ `addItem` refuses (cart unchanged), returns/flags a typed result the UI can toast on.
- policy `block` + available `'2'` and cart already holds 2 ⇒ increment refused.
- policy `warn` ⇒ add succeeds + a warning flag is surfaced (assert the store records `lastStockWarning` or the agreed signal — see Step 3).
- policy `off` ⇒ no checks (assert availability is not even consulted — pass a throwing stub).
- exempt product (`null` from the selector) ⇒ adds regardless of policy.
- every ingress (tile add, addItemWithDefaults, barcode add, manual increment) routes through ONE guard function (export it and pin all four call sites with a unit test that the guard is invoked — L9 lesson: enumerate ingress sites).

- [x] **Step 2: Implement** — one guard in `cartStore.ts`:

```ts
export type StockGateResult =
  | { ok: true; warn: false }
  | { ok: true; warn: true; available: string }
  | { ok: false; available: string };

export async function gateStockForAdd(
  product: POSProduct,
  variantId: string | null,
  requestedQty: string,
  cartLines: CartLine[],
): Promise<StockGateResult> {
  const policy = useTerminalStore.getState().terminal?.pos_stock_policy ?? 'block';
  if (policy === 'off') return { ok: true, warn: false };

  const db = await getDatabase(useAuthStore.getState().companyId!);
  const available = await getEffectiveAvailable(db, product, variantId, cartLines);
  if (available === null) return { ok: true, warn: false }; // exempt

  const insufficient = bccomp(requestedQty, available, 4) > 0;
  if (!insufficient) return { ok: true, warn: false };
  return policy === 'block'
    ? { ok: false, available }
    : { ok: true, warn: true, available };
}
```

Call sites show the result with sonner (`toast.error(t('pos:stock.blocked', { available }))` / `toast.warning(t('pos:stock.warned', { available }))`). The `'block' fallback default` when the terminal payload predates the field is deliberate fail-safe-for-retail; Menu tenants always have `off` from the backfill. NOTE the cartStore `addItem` is currently synchronous — make the GUARD async at the call sites (HomePage tile handler, barcode resolver, quantity stepper) rather than making the store action async, so the store API stays sync (follow how other async pre-checks are done before `addItem` — grep `addItem(` callers first; if a precedent makes the store action async instead, follow the precedent).

- [x] **Step 3: i18n keys** — `apps/pos/src/locales/en/pos.json` (and fr):

```json
"stock": {
  "blocked": "Not enough stock at this branch (available: {{available}})",
  "warned": "Selling beyond branch stock (available: {{available}})",
  "incoming": "{{count}} arriving",
  "incomingFromTransfer": "Incoming from branch transfer",
  "incomingOnOrder": "On order (purchase)",
  "asOf": "Stock as of {{time}}"
}
```

French (`fr/pos.json`):

```json
"stock": {
  "blocked": "Stock insuffisant dans cette agence (disponible : {{available}})",
  "warned": "Vente au-delà du stock de l'agence (disponible : {{available}})",
  "incoming": "{{count}} en arrivage",
  "incomingFromTransfer": "En transfert depuis une agence",
  "incomingOnOrder": "En commande (achat)",
  "asOf": "Stock au {{time}}"
}
```

- [x] **Step 4: Run + commit**

Run: `cd apps/pos && pnpm vitest run src/stores/__tests__/cartStockEnforcement.test.ts && pnpm typecheck && pnpm lint --max-warnings=0 src/stores src/lib/stock`

```bash
git add apps/pos && git commit -m "feat(pos): single stock gate on every cart ingress (block/warn/off) + i18n"
```

---

### Task 12: ProductCard real data + incoming badge

**Files:**
- Modify: `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx` (`:32-81`)
- Modify: the product-grid data assembly (where `POSProduct`s are loaded from local DB for display — `productStore.ts` / `productRepository.getAll`) to join `location_stock`
- Test: `apps/pos/src/components/molecules/ProductCard/__tests__/ProductCard.stock.test.tsx`

- [x] **Step 1: Failing tests** — render with: stock-managed product available `'0.0000'` ⇒ out-of-stock styling + not clickable (existing behavior, now from `location_stock`); available `'3'` ⇒ low-stock label; exempt (Menu/composite or `is_physical=false`) ⇒ no stock chrome at all (Menu 999 path unaffected); `incoming_transfer '6'` ⇒ renders `t('pos:stock.incoming')` badge. Test rendered output, not class names (testing convention).

- [x] **Step 2: Implement** — retail display stops reading `products.stock_quantity`; the grid assembler attaches `{ available, incoming_transfer, incoming_po } | null` to each product from one batched `location_stock` read (single `SELECT ... WHERE product_id IN (...)`, keyed client-side — NOT one query per tile). `ProductCard` consumes the new optional prop; when `null`/undefined (exempt or Menu) renders exactly as today. Design tokens only for any new/touched color classes; PostToolUse hook will flag hardcoded colors.

- [x] **Step 3: Staleness hint (spec §4.7.2)** — surface `t('pos:stock.asOf', { time })` from the `stock_last_sync` metadata in the existing sync-status surface (grep `products_last_sync` consumers in `apps/pos/src` to find where sync freshness is already displayed; add the stock line beside it). Test: renders the formatted timestamp when metadata present, nothing when absent.

- [x] **Step 4: Run + commit**

Run: `cd apps/pos && pnpm vitest run src/components/molecules/ProductCard && pnpm typecheck`

```bash
git add apps/pos && git commit -m "feat(pos): ProductCard location availability + arriving badge (display-only incoming)"
```

---

### Task 13: Atomic seller identity resolver + display headers

**Files:**
- Create: `apps/pos/src/lib/fiscal/sellerIdentity.ts`
- Modify: `apps/pos/src/stores/terminalStore.ts` (Location interface: add the 4 address fields + `pos_stock_policy` on Terminal)
- Modify: `apps/pos/src/stores/paymentStore.ts` (`:584-590`, `:696-702`, `:786-792` — all three seller blocks; delete `branchTaxNumberFromTerminal` `:477-484`)
- Modify: `apps/pos/src/lib/buildReceiptData.ts` (`:132` company.tax_id → resolved identity) + the printed Z header builder (grep `tax_id` in the Z print path)
- Test: `apps/pos/src/lib/fiscal/__tests__/sellerIdentity.test.ts`

- [x] **Step 1: Failing tests**

```ts
import { describe, expect, it } from 'vitest';
import { resolveSellerIdentity } from '../sellerIdentity';

const company = {
  legal_name: 'ACME SARL', name: 'ACME', tax_id: 'TN-HQ-9',
  country_code: 'TN', address_street: '1 Av HQ', address_city: 'Tunis', address_postal_code: '1000',
};
const completeLocation = {
  tax_id: 'TN-BR-1', address_street: '12 Rue Branche', address_city: 'Sfax',
  address_postal_code: '3000', address_country: 'TN', vat_number: null, legal_identifiers: null,
};

describe('resolveSellerIdentity (atomic — spec §4.6)', () => {
  it('uses the FULL location identity when the location is fiscally complete', () => {
    const s = resolveSellerIdentity(company, completeLocation);
    expect(s).toEqual({
      name: 'ACME SARL', taxNumber: 'TN-BR-1', countryCode: 'TN',
      street: '12 Rue Branche', city: 'Sfax', postalCode: '3000',
    });
  });

  it.each([
    ['tax_id', { ...completeLocation, tax_id: null }],
    ['street', { ...completeLocation, address_street: null }],
    ['city', { ...completeLocation, address_city: null }],
    ['postal', { ...completeLocation, address_postal_code: null }],
    ['country', { ...completeLocation, address_country: null }],
  ])('falls back WHOLESALE to company when location %s is missing (no mixing)', (_field, loc) => {
    const s = resolveSellerIdentity(company, loc);
    expect(s.taxNumber).toBe('TN-HQ-9');
    expect(s.street).toBe('1 Av HQ');
    expect(s.city).toBe('Tunis');
  });

  it('handles a null location (web terminal / unconfigured)', () => {
    expect(resolveSellerIdentity(company, null).taxNumber).toBe('TN-HQ-9');
  });
});
```

- [x] **Step 2: Implement**

```ts
// apps/pos/src/lib/fiscal/sellerIdentity.ts
// ATOMIC seller identity (spec §4.6, Codex BLOCKER-E/P1-E): the location's
// identity is used ONLY when fiscally complete (tax_id + full address);
// otherwise the company identity wholesale. NEVER mix fields across the two —
// a branch tax number with a company address is legally incoherent even when
// it passes FiscalPayloadConstraintValidator.

export interface SellerIdentity {
  name: string | null;
  taxNumber: string | null;
  countryCode: string | null;
  street: string | null;
  city: string | null;
  postalCode: string | null;
}

interface LocationFiscalFields {
  tax_id?: string | null;
  address_street?: string | null;
  address_city?: string | null;
  address_postal_code?: string | null;
  address_country?: string | null;
}

const text = (v: unknown): string | null =>
  typeof v === 'string' && v.trim() !== '' ? v : null;

export function locationIsFiscallyComplete(loc: LocationFiscalFields | null | undefined): boolean {
  return Boolean(
    loc && text(loc.tax_id) && text(loc.address_street) && text(loc.address_city)
      && text(loc.address_postal_code) && text(loc.address_country),
  );
}

export function resolveSellerIdentity(
  company: unknown,
  location: LocationFiscalFields | null | undefined,
): SellerIdentity {
  const name = companyField(company, 'legalName', 'legal_name')
    ?? companyField(company, 'name', 'name');

  if (locationIsFiscallyComplete(location)) {
    const loc = location as LocationFiscalFields;
    return {
      name, // legal name stays the company's registered name; the establishment shares it
      taxNumber: text(loc.tax_id),
      countryCode: text(loc.address_country)?.toUpperCase() ?? null,
      street: text(loc.address_street),
      city: text(loc.address_city),
      postalCode: text(loc.address_postal_code),
    };
  }

  return {
    name,
    taxNumber: companyField(company, 'taxId', 'tax_id'),
    countryCode: companyField(company, 'countryCode', 'country_code'),
    street: companyField(company, 'addressStreet', 'address_street'),
    city: companyField(company, 'addressCity', 'address_city'),
    postalCode: companyField(company, 'addressPostalCode', 'address_postal_code'),
  };
}
```

(Move/duplicate the small `companyField` helper out of `paymentStore.ts` into this module and re-export, or import it — one definition only, DRY.)

- [x] **Step 3: Migrate the three `paymentStore.ts` seller blocks** to `seller: resolveSellerIdentity(company, terminal?.location ?? null)` (spread into the existing seller object shape — same six keys). Delete `branchTaxNumberFromTerminal`. The ACCOUNT_CHARGE path (`:786-792`) thereby gains the branch override — Gap #1 closed. Pin with a store-level test: charge path seller.taxNumber = branch when location complete.

- [x] **Step 4: Display headers** — `buildReceiptData.ts:132`: tax_id from `resolveSellerIdentity(...)`, and append `vat_number` + `legal_identifiers` display lines from `terminal.location` when present (display may exceed the signed shape — spec §4.6). Same for the printed Z header. Update the print-template tests by rendered output.

- [x] **Step 5: Existing-test sweep** — `pnpm vitest run src/stores src/lib/fiscal src/lib/buildReceiptData* --silent` — the canonical-parity tests (`saleReceiptV2CanonicalParity.test.ts`) must stay green: the payload SHAPE is unchanged. If any parity fixture hardcodes the company tax number with a branch-configured terminal, the fixture's terminal must be made fiscally-INcomplete (or the expectation updated to the branch value) — judge which preserves the fixture's intent. **Any fixture change is a cross-language PAIR (Codex P2-1): the PHP counterpart under `apps/api/tests/` fiscal fixtures must change in the same commit, or the fixture-sync CI gate goes red** (see `project_fiscal_chain_ci_gates` — fixture-deletion + cross-language drift gates).

- [x] **Step 6: Run + commit**

Run: `cd apps/pos && pnpm vitest run src/lib/fiscal src/stores && pnpm typecheck && pnpm lint src/lib/fiscal`

```bash
git add apps/pos && git commit -m "feat(pos): atomic seller identity (complete-location-or-company) + branch identity on printed receipt/Z headers"
```

---

### Task 14: Documentation + smoke checklist

**Files:**
- Modify: the fiscal SoT/spec doc that defines the SALE_RECEIPT seller block (locate: `grep -rln "seller" apps/erp/docs --include='*.md' | grep -i "sot\|canonical\|fiscal"` — the Phase-1 fiscal SoT v3) — add the clause: *"seller block = fiscal identity of the selling ESTABLISHMENT (location) when fiscally complete; company identity wholesale otherwise; never mixed per-field."*
- Modify: canonical fixtures — add one location-identity SALE_RECEIPT fixture case to the cross-language fixture set (the fixture-sync CI gate will force the PHP+TS pair).
- Create: `docs/sessions/`-bound deploy note is NOT enough — append to the realignment/communication doc the repo uses for fiscal-shape decisions (or `docs/superpowers/tickets/` if none): the operator-facing note from spec §4.9 (branch with tax_id but incomplete address now authors COMPANY identity — was a mixed identity).
- Create: `docs/superpowers/smoke/2026-06-12-pos-location-stock-smoke.md` — Tauri-desktop manual checklist:

```markdown
# POS location-stock Tauri smoke (manual — browser cannot run the offline layer)

Setup: ParapharmacySeeder (Tier-A multi-branch) + `pnpm tauri dev`; claim a terminal at Branch B.

- [x] Full pull on claim: location_stock populated for Branch B only (inspect SQLite).
- [x] Tile shows branch availability; zero-stock product visible but blocked (policy block).
- [x] Add-to-cart beyond available → blocked toast; warn-policy company → warning toast, line added.
- [x] Offline (disable network): sell available stock down; effectiveAvailable falls with each queued sale; reconnect → drain → delta pull → availability re-baselines (no double-count).
- [x] Initiate a transfer (web admin) toward Branch B → next pull shows the arriving badge; complete the transfer → badge clears, available rises.
- [x] Confirmed PO toward Branch B shows in incoming (on-order label).
- [x] Branch with complete fiscal identity: SALE_RECEIPT + ACCOUNT_CHARGE seller tax_number/address = branch; printed header + Z header show branch tax/vat/legal IDs.
- [x] Branch with tax_id but NO address: seller = full company identity (atomic fallback).
- [x] Coffee-shop tenant (CoffeeShopSeeder): no stock chrome, no blocking, no stock pulls (Menu skip).
```

- [x] **Commit**

```bash
git add docs apps/erp 2>/dev/null; git add -A docs && git commit -m "docs(pos): fiscal seller-establishment clause, location-identity fixture, deploy note, Tauri smoke checklist"
```

---

## Post-plan gates

- [x] Scoped quality gates per task (NEVER the full suite): PHPUnit by file, `phpstan analyse` per touched module, `pint --dirty`, `pnpm vitest run <paths>`, `pnpm typecheck`, ESLint ratchet held.
- [x] `php artisan typescript:transform` if any shared DTO feeds generated types (LocationStock DTOs are POS-internal — confirm nothing in `packages/shared/types` drifts; the Types-Drift CI gate backstops).
- [x] Codex adversarial review of the implementation diff before PR (per-phase if large).
- [x] PR → `dev` (never main; merge dev back in first if promoting later). PG-only invariants run at the dev→main gate.

## Execution notes

- Tasks 1→7 (server) and 8→12 (client) are sequential within phase; Task 13 is independent of 8–12 (can run parallel-laned); Task 14 last.
- Task 7 failing = STOP-and-escalate (fiscal rationale broken).
- The plan's PHP/TS snippets follow harvested idioms but MUST yield to the file's local conventions on contact (imports, helper names, scale constants). When a snippet and the codebase disagree, the codebase wins — note the divergence in the commit body.
