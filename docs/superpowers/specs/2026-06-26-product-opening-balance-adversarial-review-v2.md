# Adversarial Review v2 — Opening Balance Design Spec (2026-06-26)

## Section 13 Disposition Verification

- C1 authz: STILL-OPEN — `POST /products` is still gated only by `can:products.create`, with no conditional `inventory.adjust` gate for opening fields (`apps/api/app/Modules/Product/routes.php:57`).
- C2 `is_historical`: PARTIALLY-RESOLVED — existing opening imports do use directly-posted historical entries (`apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php:327`), but the new inline posting service does not exist in this worktree.
- C3 enter-once invariant: STILL-OPEN — current inventory opening posting creates `StockMovement` rows without any duplicate-opening check (`apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php:280`) and the stock movement schema has only non-unique movement indexes (`apps/api/database/migrations/tenant/2025_11_30_110000_create_inventory_tables.php:46`).
- C4 correction/reset: STILL-OPEN — no `opening/reset` route exists; product routes still expose only CRUD and stock-level routes around products (`apps/api/app/Modules/Product/routes.php:53`).
- C5 location access: STILL-OPEN — `ProductController` injects no `LocationContext` and `store()` has no location access validation (`apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:41`).
- H1 strict default location: STILL-OPEN — `LocationContext::getDefaultLocation()` still falls back to the first active location when no default exists (`apps/api/app/Modules/Company/Services/LocationContext.php:111`).
- H2 vertical gate declined: CONFIRMED-RESOLVED — the existing product create route is generic Inventory-gated, not vertical-gated (`apps/api/app/Modules/Product/routes.php:42`).
- H3 physical guard: STILL-OPEN — `CreateProductRequest` validates type/physical coherence, but it has no opening-field prohibition for non-physical products (`apps/api/app/Modules/Product/Presentation/Requests/CreateProductRequest.php:147`).
- H4 movement events: STILL-OPEN — `InventoryOpeningService` creates opening movements and returns after marking rows posted, but dispatches no `StockMovementRecorded` event in that path (`apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php:280`).
- H5 afterCommit product side effects: STILL-OPEN — `ProductCreated` is still fired immediately after `Product::create()` in `store()` (`apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:348`).
- H6 entry-number race: STILL-OPEN — `generateEntryNumber()` still reads the last `INV-OB` entry and increments without a lock or sequence table (`apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php:449`).
- H7 required cost: STILL-OPEN — `CreateProductRequest::rules()` has no `opening_qty` or `opening_unit_cost` validation rules (`apps/api/app/Modules/Product/Presentation/Requests/CreateProductRequest.php:147`).
- Med1 canonicalization: PARTIALLY-RESOLVED — import staging canonicalizes inventory quantity/cost (`apps/api/app/Modules/Accounting/Application/Services/OpeningBalanceBatchService.php:196`), but the claimed shared `OpeningBalanceLine` DTO boundary is absent.
- Med2 movement scope contract: STILL-OPEN — `InventoryServiceInterface` exposes only `upsertStockLevel()` and has no company-scoped `hasMovements()` or `hasOnlyOpeningMovement()` methods (`apps/api/app/Shared/Contracts/InventoryServiceInterface.php:19`).
- Med3 qty/cost UX: STILL-OPEN — the product form data/payload has no opening quantity or unit-cost fields to clear, disable, or reject (`apps/web/src/features/inventory/ProductForm.tsx:92`).
- Med4 negative opening policy: PARTIALLY-RESOLVED — v2 documents the policy, but there is no inline opening validation in code to enforce it (`apps/api/app/Modules/Product/Presentation/Requests/CreateProductRequest.php:180`).
- Med5 concurrency tests: STILL-OPEN — no inline opening request fields or service exist for the planned concurrency tests to target (`apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:315`).
- Med6 missing handoff note: CONFIRMED-RESOLVED — the revised spec explicitly states the handoff file is not present in this worktree and claims to inline its open questions (`docs/superpowers/specs/2026-06-26-product-opening-balance-design.md:7`).
- Low1 route/action specificity: CONFIRMED-RESOLVED — `/inventory/stock` maps to `StockLevelsPage` (`apps/web/src/routes/index.tsx:883`) and that page posts adjustments to `/stock-movements/adjust` (`apps/web/src/features/inventory/StockLevelsPage.tsx:93`).
- Low2 valuation label: STILL-OPEN — the product form has pricing and WAC display fields, but no opening-stock section or valuation-cost label exists (`apps/web/src/features/inventory/ProductForm.tsx:741`).

## New Surface Attacks

### Section 8 — hard-delete reset-opening flow

Severity: BLOCKER

Finding: Implementation gap plus audit-design flaw. The reset route/service is absent, and the proposed hard-delete design would erase the stock movement and unchained journal entry without a durable audit row. Current GL immutability only blocks deletion when `fiscal_hash` exists, so historical opening entries are deletable by design.

Evidence citation: no reset route exists in `apps/api/app/Modules/Product/routes.php:57`; `JournalEntryObserver::deleting()` blocks only chained entries (`apps/api/app/Modules/Accounting/Domain/Observers/JournalEntryObserver.php:43`); journal lines cascade on journal-entry deletion (`apps/api/database/migrations/tenant/2025_11_30_100000_create_journal_entries_table.php:49`).

Suggested mitigation: implement reset as a first-class service and route, but persist an immutable reset audit record before deletion, or prefer a historical reversal/tombstone that frees the uniqueness key while preserving who/when/why and original amounts.

### Section 4.1 step 4 — concurrency-safe INV-OB entry-number generation

Severity: HIGH

Finding: Implementation gap. The current code is still read-max-and-increment, so concurrent opening posts for different products can generate the same `INV-OB-{year}` number and collide with the unique `(tenant_id, entry_number)` constraint.

Evidence citation: `InventoryOpeningService::generateEntryNumber()` queries the last matching entry and increments it without a lock (`apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php:449`); the DB enforces unique `(tenant_id, entry_number)` (`apps/api/database/migrations/tenant/2025_11_30_100000_create_journal_entries_table.php:40`).

Suggested mitigation: add the advisory-locked sequence or sequence table before extracting the shared service, and test concurrent different-product openings against PostgreSQL, not only SQLite.

### Section 4.3 — partial-unique DB index migration

Severity: HIGH

Finding: Implementation gap and predicate flaw. No opening partial-unique migration exists, and the proposed “include `variant_id` where applicable” wording is unsafe if implemented as one unique index containing nullable `variant_id`: PostgreSQL permits multiple rows where a unique-index column is `NULL`, so product-level openings could duplicate.

Evidence citation: `variant_id` is nullable on `stock_movements` (`apps/api/database/migrations/tenant/2026_06_02_100006_add_variant_id_to_stock_movements.php:16`); the only current partial unique on `stock_movements` is for `reverses_movement_id`, not openings (`apps/api/database/migrations/tenant/2026_06_25_120000_add_reverses_movement_id_to_stock_movements.php:60`).

Suggested mitigation: create two partial unique indexes: one on `(company_id, product_id, location_id)` where `movement_type = 'opening' AND variant_id IS NULL`, and one on `(company_id, product_id, location_id, variant_id)` where `movement_type = 'opening' AND variant_id IS NOT NULL`. Include duplicate pre-checks and rollback that drops both indexes by name.

### Section 2.2 — `is_historical=true` hash-chain exclusion

Severity: HIGH

Finding: Spec-level invariant gap. Historical, directly-posted entries do not break the existing hash chain because verification only walks entries with `fiscal_hash`, but the design does not prevent an unchained historical posted entry from later being toggled to non-historical. That would recreate the original defect: `status=Posted`, no `fiscal_hash`, no `chain_sequence`.

Evidence citation: GL chain verification filters to `whereNotNull('fiscal_hash')` (`apps/api/app/Modules/Accounting/Application/Services/GeneralLedgerHashService.php:103`); immutability checks block updates only when the original entry already has `fiscal_hash` (`apps/api/app/Modules/Accounting/Domain/Observers/JournalEntryObserver.php:27`); `is_historical` is fillable on `JournalEntry` (`apps/api/app/Modules/Accounting/Domain/JournalEntry.php:49`).

Suggested mitigation: add a DB or model invariant forbidding changes to `is_historical`, `status`, `entry_date`, amounts, and source fields on any posted opening entry, even when `fiscal_hash` is null.

### Section 2.3 — `inventory.adjust` authorization gate

Severity: HIGH

Finding: Implementation gap. The existing stock-adjustment endpoint enforces `can:inventory.adjust` at the route layer, but the product create endpoint does not. Since no shared posting service exists, there is also no service-layer authorization or command object that prevents a future non-HTTP caller from bypassing the intended gate.

Evidence citation: stock adjustment route has `can:inventory.adjust` (`apps/api/app/Modules/Inventory/Presentation/routes.php:98`); product create route has only `can:products.create` (`apps/api/app/Modules/Product/routes.php:57`); `ProductController` injects no opening posting service or authorization dependency (`apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:41`).

Suggested mitigation: enforce `inventory.adjust` both in the HTTP adapter before building the opening command and in the application service by requiring an authorized actor/context, then add a negative feature test for `products.create` without `inventory.adjust`.

## Summary

Total: 1 BLOCKER, 4 HIGH, 0 MEDIUM, 0 LOW findings. Recommend: REJECT.
