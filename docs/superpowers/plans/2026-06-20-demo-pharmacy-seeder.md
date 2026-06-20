# Demo Pharmacy Seeder Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a reusable, additive `DemoPharmacySeeder` that provisions a Tunisia multi-branch parapharmacy demo tenant (1 warehouse + 4 shops) with GL-consistent balances, a purchase-order pipeline, stock transfers, and a barcode mix — enough to run a live Tauri-POS demo and grow organically.

**Architecture:** Extend the non-`final` `ParapharmacySeeder` (overriding newly-extracted `protected` locale hooks so France behaviour is unchanged) and **port** the multi-branch topology logic from the `final` `ParapharmacyMultiBranchSeeder`. Seed Tunisia COA/tax via the existing `TunisiaChartOfAccountsSeeder`/`TunisiaTaxConfigurationSeeder`. Tenant-DB provisioning is inherited via `createParapharmacyTenant()→provisionTenantDatabase()`. All money is TND scale-3.

**Tech Stack:** Laravel 12 / PHP 8.2 strict, PostgreSQL (database-per-tenant via Stancl), PHPUnit feature tests, `bcmath` via `CurrencyScale`.

## Global Constraints

- **Locale = Tunisia only.** Country `TN`, currency `TND`, scale **3** (`CurrencyScale::bcformat($v, 3)` — never floats).
- **Matricule fiscal MUST satisfy** `CountryTaxNumberRules` TN regex `/^[0-9]{7,8}[A-Z]{2}[0-9]{3}$/` after stripping `/`. Canonical form `1234567AM000`; per-branch varies the last 3 digits (establishment code). Never seed the `TN…`-prefixed form.
- **Additive/idempotent.** Never trigger the parent's destructive delete path. Guard every section: if it already exists, skip. Re-running must be a safe no-op.
- **TDD.** Test first (red), minimal impl (green), commit. Mirror the existing `apps/api/tests/Feature/Seeders/ParapharmacyMultiBranchSeederTest.php` patterns.
- **Run tests scoped** — `php artisan test --filter=<Class or method>` only. NEVER run the full suite (crashes the machine) and never `./scripts/preflight.sh` without explicit OK.
- **Strict typing**, constructor injection only (no `app()` in production code — seeders may resolve via container where the existing seeders already do), enums for status/type.
- **No France output regressions** — the locale-hook extraction (Task 1) must leave `ParapharmacySeeder`/`ParapharmacyMultiBranchSeeder` output byte-identical for France.
- Work in worktree `apps/erp.demo-pharmacy` on branch `feat/demo-pharmacy-account`. Tests run against **local PostgreSQL** (db-per-tenant), not SQLite.
- **Execution of the seeder against staging is GATED on deploy** and is operational (see §Operational checklist), not part of these TDD tasks.

---

## File Structure

- **Modify** `apps/api/database/seeders/ParapharmacySeeder.php` — extract France-hardcoded locale values (country, currency, COA seeder class, default VAT %, barcode prefix, partner-factory locale, default cities/tax-id) into `protected` hook methods with France defaults. No behaviour change.
- **Create** `apps/api/database/seeders/DemoPharmacySeeder.php` — the Tunisia demo seeder. Extends `ParapharmacySeeder`; overrides locale hooks; ports the multi-branch topology, terminals, scoped cashiers, stock, balances, POs, transfers; all sections additively guarded.
- **Create** `apps/api/tests/Feature/Seeders/DemoPharmacySeederTest.php` — feature tests asserting seeded state (one test method per task slice).
- **Reference only (read, do not edit):** `ParapharmacyMultiBranchSeeder.php` (topology source to port), `CoffeeShopSeeder.php:985–1116` (balance-via-GL pattern), `TunisiaChartOfAccountsSeeder.php`, `TunisiaTaxConfigurationSeeder.php`, `CountryTaxNumberRules.php` (TN regex), `app/Modules/Document/.../PurchaseOrderService.php`, `app/Modules/Inventory/.../GoodsReceiptService.php`, `app/Modules/Inventory/.../StockTransferService.php`, `app/Modules/Accounting/.../PartnerBalanceService.php`.

---

## Task 1: Extract locale hooks into `ParapharmacySeeder` (France-preserving refactor)

**Files:**
- Modify: `apps/api/database/seeders/ParapharmacySeeder.php`
- Test: `apps/api/tests/Feature/Seeders/ParapharmacySeederLocaleHooksTest.php` (create)

**Interfaces:**
- Produces (new `protected` hooks, France defaults): `protected function localeCountryCode(): string` (`'FR'`), `localeCurrency(): string` (`'EUR'`), `localeChartOfAccountsSeeder(): string` (`FranceChartOfAccountsSeeder::class`), `localeDefaultVatRate(): float` (`20.00`), `localeBarcodePrefix(): string` (`'300'`), `localePartnerFactoryState(): string` (`'france'`), `localeTenantSlug(): string` (`'pharmabio-france'`). `DemoPharmacySeeder` overrides these.

- [ ] **Step 1: Write the characterization test** (France output unchanged after refactor)

```php
// ParapharmacySeederLocaleHooksTest.php
public function test_parapharmacy_seeder_still_produces_french_company(): void
{
    $this->seed(\Database\Seeders\ParapharmacySeeder::class);
    $tenant = \App\Modules\Tenant\Domain\Tenant::where('slug', 'pharmabio-france')->firstOrFail();
    $tenant->run(function () {
        $company = \App\Modules\Company\Domain\Company::firstOrFail();
        $this->assertSame('FR', $company->country_code);
        $this->assertSame('EUR', $company->currency);
    });
}
```

- [ ] **Step 2: Run it to confirm current green baseline**

Run: `php artisan test --filter=test_parapharmacy_seeder_still_produces_french_company`
Expected: PASS (baseline before refactor).

- [ ] **Step 3: Extract the hooks** — replace the inline France literals with hook calls. Find the company-create array in `createParapharmacyTenant()` (around the `'country_code' => 'FR'` / `'currency' => 'EUR'` lines ~376–379), the COA call (~420), the default VAT (~1197), `generateBarcode()` prefix (~1154), and partner `->france()` states (~778–805). Introduce the `protected` hooks above and call them, e.g.:

```php
'country_code' => $this->localeCountryCode(),
'currency' => $this->localeCurrency(),
// ...
$this->call($this->localeChartOfAccountsSeeder()); // where France COA was hardcoded
```

```php
protected function localeCountryCode(): string { return 'FR'; }
protected function localeCurrency(): string { return 'EUR'; }
protected function localeChartOfAccountsSeeder(): string { return \Database\Seeders\FranceChartOfAccountsSeeder::class; }
protected function localeDefaultVatRate(): float { return 20.00; }
protected function localeBarcodePrefix(): string { return '300'; }
protected function localePartnerFactoryState(): string { return 'france'; }
protected function localeTenantSlug(): string { return 'pharmabio-france'; }
```

For the partner factory state, replace `->france()` with `->{$this->localePartnerFactoryState()}()`.

- [ ] **Step 4: Run France characterization + the existing seeder tests**

Run: `php artisan test --filter=ParapharmacySeederLocaleHooksTest && php artisan test --filter=ParapharmacyMultiBranchSeederTest`
Expected: PASS (France behaviour unchanged).

- [ ] **Step 5: Commit**

```bash
git add apps/api/database/seeders/ParapharmacySeeder.php apps/api/tests/Feature/Seeders/ParapharmacySeederLocaleHooksTest.php
git commit -m "refactor(seeder): extract locale hooks from ParapharmacySeeder (France unchanged)"
```

---

## Task 2: `DemoPharmacySeeder` — Tunisia tenant + company + COA + tax config

**Files:**
- Create: `apps/api/database/seeders/DemoPharmacySeeder.php`
- Test: `apps/api/tests/Feature/Seeders/DemoPharmacySeederTest.php`

**Interfaces:**
- Consumes: the `protected` locale hooks from Task 1; `createParapharmacyTenant()` (inherited; provisions tenant DB via `provisionTenantDatabase()`).
- Produces: tenant slug `demo-pharmacy-tn`; a Tunisia `Company` (TND/TN); Tunisia COA + tax config seeded. `public function run(): void`.

- [ ] **Step 1: Write the failing test**

```php
// DemoPharmacySeederTest.php
use Database\Seeders\DemoPharmacySeeder;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Company\Domain\Company;
use App\Modules\Accounting\Domain\Account; // adjust namespace to actual

public function test_seeds_a_tunisia_tenant_with_coa_and_tax(): void
{
    $this->seed(DemoPharmacySeeder::class);
    $tenant = Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail();
    $tenant->run(function () {
        $company = Company::firstOrFail();
        $this->assertSame('TN', $company->country_code);
        $this->assertSame('TND', $company->currency);
        // Tunisia COA system-purpose accounts present
        $this->assertTrue(Account::where('code', '411')->exists()); // Clients
        $this->assertTrue(Account::where('code', '401')->exists()); // Fournisseurs
        $this->assertTrue(Account::where('code', '419')->exists()); // Clients créditeurs
    });
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=test_seeds_a_tunisia_tenant_with_coa_and_tax`
Expected: FAIL ("Class DemoPharmacySeeder not found").

- [ ] **Step 3: Write minimal implementation** — extend the parent, override locale hooks, override the COA hook to the Tunisia seeder, and call the Tunisia tax config after tenant init.

```php
<?php
declare(strict_types=1);

namespace Database\Seeders;

final class DemoPharmacySeeder extends ParapharmacySeeder
{
    protected function localeCountryCode(): string { return 'TN'; }
    protected function localeCurrency(): string { return 'TND'; }
    protected function localeChartOfAccountsSeeder(): string { return TunisiaChartOfAccountsSeeder::class; }
    protected function localeDefaultVatRate(): float { return 19.00; }
    protected function localeBarcodePrefix(): string { return '619'; }
    protected function localePartnerFactoryState(): string { return 'tunisia'; }
    protected function localeTenantSlug(): string { return 'demo-pharmacy-tn'; }

    public function run(): void
    {
        parent::run(); // creates tenant (slug demo-pharmacy-tn), company (TN/TND), Tunisia COA via hook
        $tenant = \App\Modules\Tenant\Domain\Tenant::where('slug', $this->localeTenantSlug())->firstOrFail();
        $tenant->run(function (): void {
            $this->call(TunisiaTaxConfigurationSeeder::class); // VAT 19/13/7, stamp config
        });
    }
}
```

> Note: if `ParapharmacySeeder::run()` does work beyond tenant creation that must be re-pointed for Tunisia, override the specific `protected` sub-steps rather than duplicating `run()`. Confirm `TunisiaChartOfAccountsSeeder::run($companyId, $tenantId)` is invoked with the right args by the parent's COA-call site (it passes the company/tenant); adapt the hook to return the class and let the parent call it, or override the COA-call step if the signature differs.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_seeds_a_tunisia_tenant_with_coa_and_tax`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/api/database/seeders/DemoPharmacySeeder.php apps/api/tests/Feature/Seeders/DemoPharmacySeederTest.php
git commit -m "feat(seeder): DemoPharmacySeeder Tunisia tenant + COA + tax config"
```

---

## Task 3: 5-location topology + validator-conformant matricule fiscal

**Files:**
- Modify: `apps/api/database/seeders/DemoPharmacySeeder.php`
- Test: `apps/api/tests/Feature/Seeders/DemoPharmacySeederTest.php`

**Interfaces:**
- Consumes: Task 2 company.
- Produces: `protected function seedTunisiaBranches(Company $company): array` returning `['warehouse'=>Location, 'shops'=>Location[]]`. Location codes `WH-01`, `STORE-TUN1`, `STORE-TUN2`, `STORE-SOU`, `STORE-SFA`.

- [ ] **Step 1: Write the failing test**

```php
public function test_seeds_warehouse_and_four_shops_with_valid_matricule(): void
{
    $this->seed(DemoPharmacySeeder::class);
    Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail()->run(function () {
        $locations = \App\Modules\Company\Domain\Location::all();
        $this->assertCount(5, $locations);
        $wh = $locations->firstWhere('code', 'WH-01');
        $this->assertSame('warehouse', $wh->type->value);
        $this->assertFalse($wh->pos_enabled);
        $this->assertNull($wh->tax_id); // inherits company

        $tunisRegex = '/^[0-9]{7,8}[A-Z]{2}[0-9]{3}$/';
        foreach (['STORE-TUN1','STORE-TUN2','STORE-SOU','STORE-SFA'] as $code) {
            $shop = $locations->firstWhere('code', $code);
            $this->assertSame('shop', $shop->type->value);
            $this->assertTrue($shop->pos_enabled);
            $this->assertMatchesRegularExpression($tunisRegex, str_replace('/', '', (string) $shop->tax_id));
        }
    });
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=test_seeds_warehouse_and_four_shops_with_valid_matricule`
Expected: FAIL (only the default single location exists).

- [ ] **Step 3: Write the implementation** — port `createCompanyWithBranches()` from `ParapharmacyMultiBranchSeeder.php:195–291`, replacing France values per the table below; call it from `run()` after the company exists.

Transformation table (apply to the ported block):

| Field | France source | Tunisia value |
|---|---|---|
| company `country_code` | `FR` | `TN` |
| company `currency` | `EUR` | `TND` |
| company `tax_id` | `FR12345678901` | `1234567AM000` |
| warehouse city / `tax_id` | Roissy / NULL | Sousse / NULL (inherits) |
| shop 1 | Paris `…00015` (siren/siret/nic) | Tunis-Lac, `tax_id='1234567AM001'`, `legal_identifiers=['matricule_fiscal'=>'1234567AM001','establishment_code'=>'001']` |
| shop 2 | Lyon `…00023` | Tunis-Centre, `…AM002`, est `002` |
| shop 3 | — | Sousse-Médina, `…AM003`, est `003` |
| shop 4 | — | Sfax-Centre, `…AM004`, est `004` |

```php
protected function seedTunisiaBranches(\App\Modules\Company\Domain\Company $company): array
{
    // Ported from ParapharmacyMultiBranchSeeder::createCompanyWithBranches (195-291).
    // Warehouse: type=warehouse, pos_enabled=false, tax_id=null (inherits company).
    // 4 shops: type=shop, pos_enabled=true, each tax_id = '1234567AM00{1..4}',
    // legal_identifiers = ['matricule_fiscal'=>$taxId, 'establishment_code'=>'00{n}'].
    // Use LocationType::Warehouse / LocationType::Shop enums; codes per table.
    // Return ['warehouse'=>$wh, 'shops'=>[$tun1,$tun2,$sou,$sfa]].
}
```

> Guard additively: `Location::where('code', 'WH-01')->exists()` → skip creation. Use `firstOrCreate` keyed on `(company_id, code)`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_seeds_warehouse_and_four_shops_with_valid_matricule`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(seeder): 5-location Tunisia topology + validator-conformant matricule"
```

---

## Task 4: Terminals (active + unclaimed) + location-scoped cashiers

**Files:**
- Modify: `DemoPharmacySeeder.php`
- Test: `DemoPharmacySeederTest.php`

**Interfaces:**
- Consumes: Task 3 shops.
- Produces: `protected function seedTunisiaTerminals(array $shops): void`, `protected function seedTunisiaCashiers(Company $company, array $shops): void`.

- [ ] **Step 1: Write the failing test**

```php
public function test_seeds_active_unclaimed_terminals_and_scoped_cashiers(): void
{
    $this->seed(DemoPharmacySeeder::class);
    Tenant::where('slug', 'demo-pharmacy-tn')->firstOrFail()->run(function () {
        $terminals = \App\Modules\POS\Domain\Terminal::all();
        $this->assertCount(4, $terminals);
        foreach ($terminals as $t) {
            $this->assertTrue($t->is_active);
            $this->assertNull($t->hardware_identifier); // unclaimed → POS-claimable
            $this->assertNotNull($t->genesis_seed);
            $this->assertSame('POS01', $t->code);
        }
        $tun1 = \App\Modules\Company\Domain\Location::where('code','STORE-TUN1')->firstOrFail();
        $cashier = \App\Models\User::where('email','tunis1.cashier@demo-pharmacy.tn')->firstOrFail();
        $membership = \App\Modules\Company\Domain\UserCompanyMembership::where('user_id',$cashier->id)->firstOrFail();
        $this->assertContains($tun1->id, $membership->allowed_location_ids);
    });
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=test_seeds_active_unclaimed_terminals_and_scoped_cashiers`
Expected: FAIL (no terminals).

- [ ] **Step 3: Write the implementation** — port `seedTerminals()` (`ParapharmacyMultiBranchSeeder.php:560–590`) and `createLocationScopedCashiers()` (`:598–657`). Critical: keep `is_active=true`, do **not** set `hardware_identifier` (stays NULL), keep `genesis_seed=bin2hex(random_bytes(32))`. One `POS01` per shop. Cashier emails `tunis1/tunis2/sousse/sfax.cashier@demo-pharmacy.tn`, each `allowed_location_ids=[shop.id]`; owner/manager NULL. Guard additively (`firstOrCreate` on `(company_id, location_id, code)` for terminals, on email for users).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_seeds_active_unclaimed_terminals_and_scoped_cashiers`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(seeder): active/unclaimed POS terminals + location-scoped cashiers"
```

---

## Task 5: Catalog with barcode mix (619-prefixed + nulls) and variants

**Files:**
- Modify: `DemoPharmacySeeder.php`
- Test: `DemoPharmacySeederTest.php`

**Interfaces:**
- Consumes: catalog generation inherited from `ParapharmacySeeder` (locale-agnostic) + `localeBarcodePrefix()` from Task 1.
- Produces: a `protected function localeBarcodePolicy()` controlling the with/without-barcode split (override the parent's blanket-barcode generation).

- [ ] **Step 1: Write the failing test**

```php
public function test_catalog_has_a_barcode_mix(): void
{
    $this->seed(DemoPharmacySeeder::class);
    Tenant::where('slug','demo-pharmacy-tn')->firstOrFail()->run(function () {
        $withBarcode = \App\Modules\Product\Domain\Product::whereNotNull('barcode')->count();
        $withoutBarcode = \App\Modules\Product\Domain\Product::whereNull('barcode')->count();
        $this->assertGreaterThan(0, $withBarcode, 'need scan-resolves demo set');
        $this->assertGreaterThan(0, $withoutBarcode, 'need no-barcode demo set');
        $sample = \App\Modules\Product\Domain\Product::whereNotNull('barcode')->first();
        $this->assertStringStartsWith('619', $sample->barcode); // Tunisia GS1
    });
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=test_catalog_has_a_barcode_mix`
Expected: FAIL (parent gives every product a `300`-prefixed barcode → `withoutBarcode == 0`).

- [ ] **Step 3: Write the implementation** — override the barcode assignment so ~70% of products get a `619`-prefixed EAN-13 (reuse the parent's `generateBarcode()` digit logic but with `localeBarcodePrefix()`) and ~30% get `null`. Simplest: add a `protected function shouldAssignBarcode(int $ordinal): bool { return $ordinal % 10 < 7; }` consulted at the product-create site, and have the parent's barcode step call it. If the parent generates barcodes inline without a seam, extract a `protected function productBarcode(int $ordinal): ?string` hook in Task 1's spirit (France returns always-`300`; Tunisia returns null for the 30%).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_catalog_has_a_barcode_mix`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(seeder): Tunisia barcode mix (619-prefixed subset + nulls)"
```

---

## Task 6: Multi-branch stock distribution

**Files:**
- Modify: `DemoPharmacySeeder.php`
- Test: `DemoPharmacySeederTest.php`

**Interfaces:**
- Consumes: Tasks 3 (locations) + 5 (products/variants).
- Produces: `protected function seedTunisiaStock(Company $company, array $topology): void` (ported from `seedMultiBranchStock` `:304–350` + variant stock `:473–509`).

- [ ] **Step 1: Write the failing test**

```php
public function test_distributes_stock_across_locations(): void
{
    $this->seed(DemoPharmacySeeder::class);
    Tenant::where('slug','demo-pharmacy-tn')->firstOrFail()->run(function () {
        $wh = \App\Modules\Company\Domain\Location::where('code','WH-01')->firstOrFail();
        $tun1 = \App\Modules\Company\Domain\Location::where('code','STORE-TUN1')->firstOrFail();
        $whLevels = \App\Modules\Inventory\Domain\StockLevel::where('location_id',$wh->id)->count();
        $shopLevels = \App\Modules\Inventory\Domain\StockLevel::where('location_id',$tun1->id)->count();
        $this->assertGreaterThan($shopLevels, $whLevels, 'warehouse holds more SKUs than a shop');
        $this->assertGreaterThan(0, $shopLevels);
    });
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=test_distributes_stock_across_locations`
Expected: FAIL (no per-location stock seeded yet for the 5-location topology).

- [ ] **Step 3: Write the implementation** — port `seedMultiBranchStock` (warehouse ~90% coverage in bulk; each shop ~60% in small quantities) extended to 4 shops, plus variant-level `stock_levels` rows per (location, variant_id). Quantities via `CurrencyScale`/`QuantityScale` as the source uses. Guard additively (`firstOrCreate` on the stock-level unique key).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_distributes_stock_across_locations`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(seeder): multi-branch stock distribution across 5 locations"
```

---

## Task 7: Partners + GL-consistent balances (TND)

**Files:**
- Modify: `DemoPharmacySeeder.php`
- Test: `DemoPharmacySeederTest.php`

**Interfaces:**
- Consumes: Task 2 company + Tunisia COA accounts 411/419/401; `PartnerBalanceService::refreshPartnerBalance($companyId, $partnerId)`.
- Produces: `protected function seedTunisiaBalances(Company $company): void`.

- [ ] **Step 1: Write the failing test**

```php
public function test_seeds_gl_consistent_partner_balances(): void
{
    $this->seed(DemoPharmacySeeder::class);
    Tenant::where('slug','demo-pharmacy-tn')->firstOrFail()->run(function () {
        $debtor = \App\Modules\Partner\Domain\Partner::where('code','CUST-DEBTOR-01')->firstOrFail();
        $this->assertTrue(bccomp($debtor->receivable_balance, '0', 3) === 1, 'has outstanding receivable');
        $credited = \App\Modules\Partner\Domain\Partner::where('code','CUST-CREDIT-01')->firstOrFail();
        $this->assertTrue(bccomp($credited->credit_balance, '0', 3) === 1, 'has store credit');
        // GL-consistency: recompute and confirm the cached column matches
        app(\App\Modules\Accounting\Application\Services\PartnerBalanceService::class)
            ->refreshPartnerBalance($debtor->company_id, $debtor->id);
        $this->assertTrue(bccomp($debtor->fresh()->receivable_balance, '0', 3) === 1);
    });
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=test_seeds_gl_consistent_partner_balances`
Expected: FAIL (no debtor/credit partners with balances).

- [ ] **Step 3: Write the implementation** — mirror `CoffeeShopSeeder.php:985–1116`: create posted `JournalEntry` + `JournalLine`s against accounts **411** (debit, partner_id) / revenue (credit) for a receivable; **419** (credit, partner_id) for store credit; **401** for a supplier payable; all amounts `CurrencyScale::bcformat($v, 3)` TND. Tag a few partners with stable codes (`CUST-DEBTOR-01`, `CUST-CREDIT-01`, `SUPP-PAYABLE-01`, house account). Then call `refreshPartnerBalance()` per partner. Guard additively (skip if the journal entries / tagged partners already exist).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_seeds_gl_consistent_partner_balances`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(seeder): GL-consistent TND partner balances (receivable/credit/payable)"
```

---

## Task 8: Purchase-order pipeline

**Files:**
- Modify: `DemoPharmacySeeder.php`
- Test: `DemoPharmacySeederTest.php`

**Interfaces:**
- Consumes: suppliers (Task 7), warehouse (Task 3), `PurchaseOrderService::confirm()`, `GoodsReceiptService::receiveGoods()/receiveAll()`.
- Produces: `protected function seedTunisiaPurchaseOrders(Company $company, Location $warehouse): void`.

- [ ] **Step 1: Write the failing test**

```php
public function test_seeds_purchase_order_pipeline(): void
{
    $this->seed(DemoPharmacySeeder::class);
    Tenant::where('slug','demo-pharmacy-tn')->firstOrFail()->run(function () {
        $byStatus = \App\Modules\Document\Domain\Document::where('type','purchase_order')
            ->get()->groupBy(fn ($d) => $d->status->value);
        $this->assertArrayHasKey('draft', $byStatus->toArray());
        $this->assertArrayHasKey('confirmed', $byStatus->toArray()); // incl. the partially-received one
        $this->assertArrayHasKey('received', $byStatus->toArray());
        // partial receipt incremented warehouse stock on at least one line
        $received = \App\Modules\Document\Domain\Document::where('type','purchase_order')
            ->where('status','received')->firstOrFail();
        $this->assertGreaterThan(0, $received->lines()->sum('quantity_received'));
    });
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=test_seeds_purchase_order_pipeline`
Expected: FAIL (no POs).

- [ ] **Step 3: Write the implementation**

```php
// For each PO: create via factory, add document_lines (product + qty + unit_price as bcformat(...,3)).
$po = \App\Modules\Document\Domain\Document::factory()->purchaseOrder()->create([
    'tenant_id' => $company->tenant_id, 'company_id' => $company->id,
    'partner_id' => $supplier->id, 'location_id' => $warehouse->id, /* currency TND */
]);
// ... attach lines ...
app(\App\Modules\Document\Domain\Services\PurchaseOrderService::class)->confirm($po);            // confirmed
app(\App\Modules\Inventory\Application\Services\GoodsReceiptService::class)
    ->receiveGoods($po, [$lineId => '10.0000']);                                                  // partial → stays confirmed
app(\App\Modules\Inventory\Application\Services\GoodsReceiptService::class)->receiveAll($po2);    // received
// Leave one PO as draft (no confirm).
```

Create 4 POs: draft, confirmed-awaiting, partially-received (confirm + `receiveGoods` on some lines), fully-received (`receiveAll`). Guard additively (skip if POs with a stable `reference`/number prefix exist).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_seeds_purchase_order_pipeline`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(seeder): purchase-order pipeline (draft/confirmed/partial/received)"
```

---

## Task 9: Stock transfers (warehouse → shops, incl. variant)

**Files:**
- Modify: `DemoPharmacySeeder.php`
- Test: `DemoPharmacySeederTest.php`

**Interfaces:**
- Consumes: warehouse + shops (Task 3), products/variants + stock (Tasks 5/6), `StockTransferService`.
- Produces: `protected function seedTunisiaTransfers(Company $company, array $topology): void`.

- [ ] **Step 1: Write the failing test**

```php
public function test_seeds_completed_and_in_transit_transfers(): void
{
    $this->seed(DemoPharmacySeeder::class);
    Tenant::where('slug','demo-pharmacy-tn')->firstOrFail()->run(function () {
        $transfers = \App\Modules\Inventory\Domain\StockTransfer::all();
        $this->assertTrue($transfers->contains(fn ($t) => $t->status->value === 'completed'));
        $this->assertTrue($transfers->contains(fn ($t) => $t->status->value === 'in_transit'));
        $this->assertTrue(
            $transfers->flatMap->lines->contains(fn ($l) => $l->variant_id !== null),
            'at least one transfer line is variant-scoped'
        );
    });
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=test_seeds_completed_and_in_transit_transfers`
Expected: FAIL (no transfers).

- [ ] **Step 3: Write the implementation** — via `StockTransferService`: `initiate` two transfers warehouse→shop (one with a variant line), `complete` one (moves stock), leave the other after `moveSourceToInTransit` (status `in_transit`). Match the exact DTO/param shapes the service's `initiate()` expects (see `StockTransferService.php`). Guard additively (skip if transfers with a stable reference exist).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_seeds_completed_and_in_transit_transfers`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(seeder): warehouse→shop stock transfers (completed + in-transit, variant-aware)"
```

---

## Task 10: Additive idempotency (re-run safety, no destructive parent path)

**Files:**
- Modify: `DemoPharmacySeeder.php`
- Test: `DemoPharmacySeederTest.php`

**Interfaces:**
- Consumes: all prior sections.
- Produces: an early-out / per-section guards so a second `run()` is a no-op and never deletes/drops the tenant.

- [ ] **Step 1: Write the failing test**

```php
public function test_re_running_is_idempotent_and_non_destructive(): void
{
    $this->seed(DemoPharmacySeeder::class);
    $tenant = Tenant::where('slug','demo-pharmacy-tn')->firstOrFail();
    $countBefore = $tenant->run(fn () => \App\Modules\Company\Domain\Location::count());

    $this->seed(DemoPharmacySeeder::class); // second run

    $tenantAfter = Tenant::where('slug','demo-pharmacy-tn')->firstOrFail();
    $this->assertSame($tenant->id, $tenantAfter->id, 'tenant was NOT deleted+recreated');
    $countAfter = $tenantAfter->run(fn () => \App\Modules\Company\Domain\Location::count());
    $this->assertSame($countBefore, $countAfter, 'no duplicate locations on re-run');
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=test_re_running_is_idempotent_and_non_destructive`
Expected: FAIL (parent's `createParapharmacyTenant` deletes+recreates the tenant by slug, changing the id and/or the inherited path runs destructively).

- [ ] **Step 3: Write the implementation** — override the tenant-bootstrap entry so that if a tenant with `localeTenantSlug()` already exists, we **reuse it** (do not call the parent's delete/drop branch). Add a top-of-`run()` guard:

```php
public function run(): void
{
    $existing = \App\Modules\Tenant\Domain\Tenant::where('slug', $this->localeTenantSlug())->first();
    if ($existing === null) {
        parent::run(); // first-time provision (create DB → migrate → seed base)
    }
    $tenant = \App\Modules\Tenant\Domain\Tenant::where('slug', $this->localeTenantSlug())->firstOrFail();
    $tenant->run(function (): void {
        $company = \App\Modules\Company\Domain\Company::firstOrFail();
        $this->call(TunisiaTaxConfigurationSeeder::class);
        $topology = $this->seedTunisiaBranches($company);
        $this->seedTunisiaTerminals($topology['shops']);
        $this->seedTunisiaCashiers($company, $topology['shops']);
        $this->seedTunisiaStock($company, $topology);
        $this->seedTunisiaBalances($company);
        $this->seedTunisiaPurchaseOrders($company, $topology['warehouse']);
        $this->seedTunisiaTransfers($company, $topology);
    });
}
```

Every `seedTunisia*` method must itself be additively guarded (Tasks 3–9 already use `firstOrCreate`/existence checks), so the second pass is a no-op. **Do not** invoke the parent's destructive delete branch.

> If `parent::run()` itself deletes-by-slug before creating, override the specific bootstrap step (e.g., the method around `ParapharmacySeeder.php:254–275`) in `DemoPharmacySeeder` to skip the delete when the tenant exists, or branch on existence as above so it's never reached on re-run.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=DemoPharmacySeederTest`
Expected: PASS (all DemoPharmacySeederTest methods green).

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(seeder): additive idempotent DemoPharmacySeeder (no destructive re-run)"
```

---

## Operational checklist (NOT TDD — execute against staging after deploy)

These are runbook steps, tracked here for completeness; they run after the seeder is green and the deploy is confirmed.

- [ ] Confirm staging target (`erp.otospex.dev`?) + API/Reverb URLs with owner.
- [ ] Set staging env: `CORS_ALLOWED_ORIGINS` (POS/web origin), `SYNERIVA_PLATFORM_URL`/`_API_KEY`/`_WEBHOOK_SECRET`; confirm Horizon consuming `fiscal-projections` (`php artisan horizon:status`).
- [ ] Run seeder on staging: `php artisan db:seed --class=DemoPharmacySeeder --force` (AUTO_SEED=false → manual).
- [ ] Add `apps/pos/.env.staging` (staging API + Reverb), build staging POS binary; install on demo laptop.
- [ ] Claim the 4 `POS01` terminals; verify a sync round-trip projects to web-admin.
- [ ] Manual sales must satisfy the **reporting acceptance criteria** (reporting-session note, 2026-06-20): ≥2 shops with current-period sales, ≥1 return receipt, varied payment methods (cash/card/split), enough products for a top-SKU list.
- [ ] **Prior-period (B1, owner-decided):** ring a handful of sales across **2–3 days before the demo** so prior-period deltas are nonzero. Confirm the report's comparison window is day-/short-window-based with the reporting session (if month-over-month, revisit).
- [ ] Dry-run (day before): 1 sale/branch + Z-report → confirm all appear in web-admin; enrichment smoke test on 2–3 products (one `619` barcode, one null).
- [ ] Produce the §8 gap report from observations; feed transaction-list/reporting items to the parallel reporting session.

---

## Self-Review

**Spec coverage:** §5.1 topology → Tasks 3–4; §5.1 matricule format → Task 3; §5.2 balances → Task 7; §5.3 POs → Task 8; §5.4 transfers → Task 9; §5.5 manual-sales preconditions (priced+stocked+claimable+customers) → Tasks 4–7; §5.6 barcode mix → Task 5; §9 idempotency → Task 10; locale reuse (§5.1 construction) → Task 1; provisioning inheritance → Task 2. §6/§7/§8 (POS build, dry-run, gap report) → Operational checklist (non-TDD by design). No code gaps.

**Placeholder scan:** Implementation steps that port from a named source give exact source line ranges + a transformation table (Tasks 3,4,6) rather than vague "similar to" — acceptable since the source is the production seeder. Service-call steps (Tasks 7,8,9) show the concrete call signatures verified during design. No "TBD/handle edge cases".

**Type consistency:** `localeTenantSlug()` used identically in Tasks 1/2/10; `seedTunisiaBranches()` returns `['warehouse'=>Location,'shops'=>Location[]]` and is consumed with that shape in Tasks 4/6/9; status string values (`draft`/`confirmed`/`received`, `completed`/`in_transit`) match the enums referenced in the spec. One open verification flagged inline: exact `StockTransferService::initiate()` DTO shape (Task 9 step 3) and whether the parent's COA call site passes `($companyId,$tenantId)` (Task 2 note) — both to be confirmed against the file at implementation time, not guessed.
