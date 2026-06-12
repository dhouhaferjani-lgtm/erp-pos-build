# POS Location-Aware Stock Spec — Codex Adversarial Review

- Date: 2026-06-11
- Spec path: `docs/superpowers/specs/2026-06-11-pos-location-aware-stock-design.md`
- Reviewer: Codex adversarial review

## Factual Anchor Verification

| Anchor | Status | Verification |
|---|---:|---|
| `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php` | CONFIRMED | `initiate()` creates draft lines then calls `moveSourceToInTransit()` (`StockTransferService.php:129-176`); `complete()` receives into destination (`StockTransferService.php:217-252`); `cancel()` restocks source only from `InTransit` (`StockTransferService.php:310-357`); source issue sets `InTransit` (`StockTransferService.php:440-473`). |
| `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php` (`decrementStock`) | CONFIRMED | Decrements a `StockLevel` scoped by `product_id`, `location_id`, `company_id`, and optional `variant_id`, locks it, throws on insufficient stock, then saves the decremented quantity (`ReceiptCreationService.php:878-924`). |
| `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php` | CONFIRMED | Projection path uses terminal location (`PosCoreReceiptProjection.php:883-894`), warns on insufficient stock, and continues to decrement/save (`PosCoreReceiptProjection.php:935-957`). |
| `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php` (`stockLevels`) | CONFIRMED | `stockLevels()` reads `stock_levels` across locations and computes incoming only from confirmed purchase orders (`ProductController.php:603-626`); no transfer incoming term exists there. |
| `apps/pos/src/stores/paymentStore.ts` (seller blocks) | CONFIRMED | `branchTaxNumberFromTerminal()` reads `terminal.location.tax_id` (`paymentStore.ts:477-484`); SALE_RECEIPT and ACCOUNT_PAYMENT use branch tax fallback (`paymentStore.ts:584-590`, `paymentStore.ts:696-702`); ACCOUNT_CHARGE still uses company tax/address (`paymentStore.ts:786-792`). |
| `apps/pos/src/lib/sync/syncService.ts` | CONFIRMED | Product pull uses `/products` with `updated_since` and no location parameter (`syncService.ts:512-532`); it writes the product cursor from device time (`syncService.ts:577-578`); sync pushes fiscal events before pulls (`syncService.ts:1601-1643`). |
| `apps/pos/src/lib/db/migrations.ts` | CONFIRMED | Local `products.stock_quantity` is `INTEGER NOT NULL DEFAULT 0` (`migrations.ts:13-25`); `terminal_state.location_code` is added only in migration v13 (`migrations.ts:243-256`). |
| `apps/api/app/Modules/Company/Application/Services/TaxIdentityResolver.php` | CONFIRMED | Resolves `tax_id`, `vat_number`, merged `legal_identifiers`, and country from location over company (`TaxIdentityResolver.php:12-24`); it does not resolve address fields. |
| `apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php` | CONFIRMED | Embeds `location.id/name/code/tax_id/vat_number/legal_identifiers` (`TerminalResource.php:28-36`) and does not embed location address fields. |
| `apps/api/app/Modules/POS/Application/Projections/CanonicalPayloadReader.php` | NOT FOUND | Requested path does not exist. Equivalent is `apps/api/app/Modules/Fiscal/Application/Services/CanonicalPayloadReader.php`; it reads typed canonical views and does not re-run validation (`CanonicalPayloadReader.php:37-50`, `CanonicalPayloadReader.php:62-80`). Fiscal validation is in `StrictCanonicalParser` and `FiscalPayloadConstraintValidator`. |

## Findings By Axis

### A. Offline-First Availability Formula

No finding. The double-count boundary is addressed if the selector subtracts only receipts not yet synced/acked: the spec says pending local subtraction is limited to unsynced/acked receipts (`2026-06-11-pos-location-aware-stock-design.md:160-164`) and schedules a stock re-pull after successful receipt drain (`2026-06-11-pos-location-aware-stock-design.md:151-153`). Current sync marks a source `offline_receipts` row `synced` after its fiscal event succeeds (`syncService.ts:263-271`) before the pull phase runs (`syncService.ts:1601-1643`).

Stale snapshots are honestly bounded, not solved: the spec explicitly accepts that enforcement uses the last pulled snapshot while offline and only surfaces a staleness hint (`2026-06-11-pos-location-aware-stock-design.md:192-193`).

### B. Delta-Sync Semantics

#### [P1] B: Stock-level deletions are not synchronized, so stale positive local stock can survive indefinitely.

Evidence: The client design only says to upsert stock rows and wholesale-replace incoming columns (`2026-06-11-pos-location-aware-stock-design.md:151`); it never defines tombstones, `deleted_ids`, a full reconciliation mode, or delete-on-absence for the `stock` array. The selector says a missing server row means zero availability (`2026-06-11-pos-location-aware-stock-design.md:160-162`), but a previously cached local row will not become missing if the delta feed never tells the client to delete it. Product sync already has explicit tombstone support (`ProductController.php:103-120`, `syncService.ts:573-575`), while the proposed stock feed does not. `stock_levels` can be removed by cascades from product/location deletion (`2025_11_30_110000_create_inventory_tables.php:19-20`) and by any future direct cleanup path, so the local stock table needs deletion semantics.

#### [P2] B: The stock cursor source is ambiguous; using device time would reintroduce clock-skew bugs.

Evidence: The spec returns server `as_of` (`2026-06-11-pos-location-aware-stock-design.md:110`) and says to record `stock_last_sync` plus server `as_of` (`2026-06-11-pos-location-aware-stock-design.md:151`), but it does not state which value is sent as the next `updated_since`. The existing product pull advances its cursor with device time (`syncService.ts:577-578`). If `pullLocationStock()` copies that pattern, device clock skew can create gaps or repeated pulls. The design should explicitly say the next stock `updated_since` is the previous server `as_of`, not `new Date().toISOString()`.

No finding on destination updates from remote transfers. The spec correctly identifies that transfer initiation updates the source row, not the destination row, and therefore returns `incoming` complete on every pull (`2026-06-11-pos-location-aware-stock-design.md:116-118`). Current transfer code confirms initiation issues source stock (`StockTransferService.php:440-473`) and completion receives destination stock (`StockTransferService.php:217-252`).

### C. Incoming Computation

No finding. Current transfers have a single status field with `Draft`, `InTransit`, `Completed`, and `Cancelled` only (`TransferStatus.php:15-20`); `complete()` is allowed only from `InTransit` (`TransferStatus.php:32-35`) and receives all lines (`StockTransferService.php:217-252`); `cancel()` is allowed only from `Draft` or `InTransit` (`TransferStatus.php:37-40`) and then sets `Cancelled` (`StockTransferService.php:353-357`). A "partially received, then cancelled" state is not representable in the current model. The proposed transfer incoming term is variant-grain (`2026-06-11-pos-location-aware-stock-design.md:118`), matching `stock_transfer_lines.variant_id` (`2026_06_09_120000_add_variant_id_to_stock_transfer_lines.php:26-53`) and `stock_levels.variant_id` (`2026_06_02_100005_add_variant_id_to_stock_levels.php:16-57`). The PO asymmetry is documented (`2026-06-11-pos-location-aware-stock-design.md:119`, `2026-06-11-pos-location-aware-stock-design.md:194`).

### D. Policy Enum Design And Backfill

#### [P1] D: Company-level policy exposure conflicts with the current tenant-level `/company/config` shape.

Evidence: The spec chooses a company-level `companies.pos_stock_policy` (`2026-06-11-pos-location-aware-stock-design.md:28-31`, `2026-06-11-pos-location-aware-stock-design.md:123-128`) and says to expose it through `/company/config` (`2026-06-11-pos-location-aware-stock-design.md:127`). Current `CompanyConfigService` builds configuration from `Tenant::$vertical` and tenant extras (`CompanyConfigService.php:34-60`), and `CompanyConfigController` gets currency/locale from the authenticated user's primary company rather than a selected company from `X-Company-Id` (`CompanyConfigController.php:58-68`). In a multi-company tenant, a company-level stock policy can be misreported unless the endpoint is changed to resolve the active company context.

No finding on F&B/Menu detectability. Runtime vertical is stored on `Tenant` and cast to `Vertical` (`Tenant.php:121-127`); restaurant and coffee shop configs include `Menu` in default modules (`config/verticals.php:70-90`, `config/verticals.php:99-118`); POS uses `all_enabled_modules.includes('Menu')` (`productStore.ts:100-102`). Existing tenants without an explicit vertical are unlikely under current schema because `tenants.vertical` has default `retail` (`2025_12_30_115627_add_vertical_to_tenants_table.php:14-22`).

### E. Fiscal Identity Changes

#### [BLOCKER] E: Seller-block value sourcing is a fiscal semantic change, but the spec explicitly avoids an event-version bump.

Evidence: The spec says "no event-version bump" in the locked decisions (`2026-06-11-pos-location-aware-stock-design.md:30-34`) and repeats that the seller shape is unchanged while values authored going forward differ (`2026-06-11-pos-location-aware-stock-design.md:181-188`). Current fiscal registry has SALE_RECEIPT authoring at version 2 (`FiscalEventPayloadRegistry.php:53-58`) and accepts only versions 1 and 2 for SALE_RECEIPT (`FiscalEventPayloadRegistry.php:105-107`); the strict parser rejects unsupported versions (`StrictCanonicalParser.php:621-634`). Because SALE_RECEIPT is signed and append-only, changing the source-of-truth semantics for `seller` should be represented as an explicit fiscal contract/version decision, even if the JSON shape is identical.

Server validation does not currently block per-location seller values by comparing them to company records. The reader only materializes DTOs (`CanonicalPayloadReader.php:62-80`), and the validator checks seller key set, non-empty name, country code, tax-number format, and required address (`FiscalPayloadConstraintValidator.php:1541-1569`, `FiscalPayloadConstraintValidator.php:2391-2420`), not equality to `companies.tax_id`.

#### [P1] E: Per-field location/company fallback can create invalid or legally incoherent seller identity blocks.

Evidence: The spec says seller address fields source from location "when present" with company fallback per field (`2026-06-11-pos-location-aware-stock-design.md:183-185`). The validator requires the seller object to include `tax_jurisdiction_country_code`, `tax_number`, and a complete address (`FiscalPayloadConstraintValidator.php:1548-1569`), and validates the tax number against the seller country (`FiscalPayloadConstraintValidator.php:2391-2420`). If a branch has `location.tax_id` but only partial address/country data, per-field fallback can pair a branch tax number with a company country/address, causing quarantine or a mixed fiscal identity. `TaxIdentityResolver` currently resolves tax fields and country only, not street/city/postal-code (`TaxIdentityResolver.php:16-24`), and `TerminalResource` does not yet expose address fields (`TerminalResource.php:28-36`).

### F. Module Boundary Correctness

No finding. The spec routes the new POS endpoint through a `Shared\Contracts\LocationStockReader` interface implemented in Inventory and states "No cross-module model imports" (`2026-06-11-pos-location-aware-stock-design.md:120`). That is the correct boundary for a POS read model over Inventory data.

### G. Precision Contract Compliance

#### [P2] G: The precision contract is directionally correct but under-specified for implementation.

Evidence: The spec requires stock quantities as decimal strings at scale 4 and no floats (`2026-06-11-pos-location-aware-stock-design.md:97-108`, `2026-06-11-pos-location-aware-stock-design.md:150`). Current inventory storage and model casts are scale 4 (`2026_05_29_120000_widen_inventory_quantity_columns_to_scale_4.php:8-23`, `StockLevel.php:57-64`), but adjacent code still uses literal bcmath scales (`ReceiptCreationService.php:899-920`, `PosCoreReceiptProjection.php:939-954`) and `ProductController::stockLevels()` totals use scale 2 for stock quantities (`ProductController.php:642-646`). The implementation plan should require `QuantityScale`/shared quantity helpers for the new endpoint and selector rather than copying hardcoded `4` or the existing scale-2 stock totals.

### H. Multi-Terminal Race Documentation Honesty

No finding. The spec explicitly documents that two terminals at the same location can oversell between syncs because each subtracts only its own pending sales, and it names the mitigation/backstop limits (`2026-06-11-pos-location-aware-stock-design.md:190-193`). Current server draft path is a stock-locked hard throw (`ReceiptCreationService.php:888-912`), while fiscal projection warns and records reality (`PosCoreReceiptProjection.php:935-957`), matching the spec's stated boundary.

## Overall Verdict

REQUEST-CHANGES (86% confidence)

Summary: The stock-aware POS design is broadly aligned with the existing codebase and gets the important transfer-incoming and offline-first shape mostly right. The required changes are concentrated in a few high-risk seams: stock delta sync needs deletion and explicit server-cursor semantics, company-level policy cannot be exposed through the current tenant/primary-company config path without active-company resolution, and the fiscal identity change needs a real versioning/contract decision plus atomic branch identity fallback instead of per-field mixing.
