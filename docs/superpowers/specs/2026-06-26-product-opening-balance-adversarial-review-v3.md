## Final Adversarial Review v3 — Opening Balance Design Spec v2.2
_Date: 2026-06-26 | Reviewer: Codex | Gate: pre-implementation_

### Verdict
BLOCKING — one remaining design contradiction prevents the reset flow from delivering its stated reversal-and-re-enter correction path.

### Findings (new only — not previously resolved)

- **[SEV: BLOCKING] §8/§9/§7/§5 — Reset preserves audit rows but the spec never provides a usable re-entry path**
  Evidence: §8 says reset reverses the opening, keeps both original and reversal rows, and "unlocks the fields for re-entry." §9 says the Opening stock section is "create-only" and locks "when `has_movements === true`." §7 defines only a coarse `has_movements: bool` for `ProductData`, and §5 only describes posting an opening inside `ProductController::store` during product creation. Source confirms the reversal model preserves rows: `StockMovement` has `reverses_movement_id` and `reversalOf()` relationships in `apps/api/app/Modules/Inventory/Domain/StockMovement.php:43` and `apps/api/app/Modules/Inventory/Domain/StockMovement.php:163`; the migration adds a nullable reversal pointer plus a unique non-null reversal guard in `apps/api/database/migrations/tenant/2026_06_25_120000_add_reverses_movement_id_to_stock_movements.php:35` and `apps/api/database/migrations/tenant/2026_06_25_120000_add_reverses_movement_id_to_stock_movements.php:60`.
  Impact: After reset, the product still has movement rows by design, so a literal `has_movements` lock remains true. The spec also has no existing-product endpoint or update-flow adapter that can post the corrected opening after reset. The correction flow can zero stock and clear cost, but cannot complete the promised "reverse + re-enter" path, leaving a product stuck with historical reversal rows and no active opening.
  Fix: Add an explicit existing-product opening re-entry path, e.g. `POST /products/{id}/opening`, gated by `can:inventory.adjust` and backed by `OpeningBalancePostingService`. Replace the UI lock decision with an opening-state field such as `can_enter_opening`, `has_active_opening`, and `has_downstream_movements`, not raw `has_movements`.

- **[SEV: MAJOR] §2.4/§5 — "Strict company default" cites a non-strict existing method**
  Evidence: §2.4 says location is resolved via `LocationContext::getDefaultLocation(companyId)`, "strict company default," with 422 if none exists. The existing method is not strict: its docblock says it falls back to the first active location in `apps/api/app/Modules/Company/Services/LocationContext.php:95`, and the implementation returns the first active location when no default exists in `apps/api/app/Modules/Company/Services/LocationContext.php:111`. `resolveLocationId()` also calls this fallbacking method in `apps/api/app/Modules/Company/Services/LocationContext.php:140`.
  Impact: An implementation that follows the spec literally and calls `getDefaultLocation()` will not produce the promised 422 when no default is configured; it will post stock into the first active location. That reopens the substance of H1 despite §13 marking it accepted/resolved.
  Fix: Specify a new strict API such as `LocationContext::getStrictDefaultLocation()` or explicitly require changing `getDefaultLocation()` semantics and auditing callers that rely on first-active fallback.

- **[SEV: MINOR] §10 — Concurrent same-product wording still mentions a DB index that §2.5/§4.3 removed**
  Evidence: §2.5 says there is "No DB partial-unique index," and §4.3 says the partial index is dropped in favor of the advisory-locked service check. §10 still says concurrent openings on the same product are blocked by "the in-lock enter-once check + DB index."
  Impact: This is not a runtime flaw if §2.5/§4.3 govern implementation, but it is an internal spec contradiction that can leak into the implementation plan or tests.
  Fix: Remove "+ DB index" from §10 and state the protection is the product advisory lock plus the in-service active-opening check.

### v2.1/v2.2 Change Validation

Reversal-based reset reusing `reverses_movement_id`: Partially coherent. The backend has the intended primitive: `StockMovement::$fillable` includes `reverses_movement_id` and relationship helpers (`apps/api/app/Modules/Inventory/Domain/StockMovement.php:60`, `apps/api/app/Modules/Inventory/Domain/StockMovement.php:152`), and the migration enforces at most one reversal for an original movement (`apps/api/database/migrations/tenant/2026_06_25_120000_add_reverses_movement_id_to_stock_movements.php:19`). The contradiction is not the reversal primitive; it is the post-reset re-entry state described in the blocking finding above.

In-lock active-opening enter-once check with no DB index: Coherent as a design invariant if every opening caller goes through `OpeningBalancePostingService`. `ProductCostLock` provides sorted PostgreSQL transaction advisory locks per product (`apps/api/app/Modules/Inventory/Domain/Services/ProductCostLock.php:40`), and §4.1 step 3 re-reads active openings inside that lock. The only issue is the stale §10 "DB index" wording captured as a minor finding.

`is_historical=true` hash-chain exclusion: Coherent with existing backend behavior. `AccountingOpeningService::postBatch()` directly creates Posted historical entries with `is_historical=true` and a "Skip fiscal hash chain" comment (`apps/api/app/Modules/Accounting/Application/Services/AccountingOpeningService.php:221`). `InventoryOpeningService::postBatch()` does the same for inventory openings (`apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php:327`). `GeneralLedgerService::postEntry()` only accepts Draft entries and assigns chain fields during posting (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1258`, `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1295`), while chain verification only walks entries with `fiscal_hash` (`apps/api/app/Modules/Accounting/Application/Services/GeneralLedgerHashService.php:103`). The immutability gap remains correctly parked in §14 as system-wide, not introduced by this feature.

`inventory.adjust` gate: Coherent. The existing stock-adjustment route is gated with `can:inventory.adjust` (`apps/api/app/Modules/Inventory/Presentation/routes.php:98`), while product create is currently only `can:products.create` (`apps/api/app/Modules/Product/routes.php:57`). §2.3/§5 correctly require adding the conditional server-side inventory gate for opening fields; the absence of current implementation is expected at this design stage.

Concurrency-safe `INV-OB` numbering: Coherent as a required design change. Current `InventoryOpeningService::generateEntryNumber()` is read-max-and-increment (`apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php:449`), so §4.1 step 4 correctly requires replacing it with an advisory-locked sequence or dedicated sequence table. No new contradiction found beyond needing the implementation plan to choose one concrete mechanism.

### §13 Disposition Re-examination

Reopen C4 in part: §13 says C4 is resolved by §8 reset. The reset now preserves audit rows, which fixes the v2 audit objection, but the spec does not define a working re-entry path after those rows persist. Evidence and fix are in the blocking finding above.

Reopen H1: §13 says strict default location is accepted/resolved by §2.4. The named backend method still falls back to first active location, so the spec must require a strict method or a behavior change. Evidence and fix are in the major finding above.

All other §13 dispositions hold under this review scope. No additional incorrectly closed dispositions found.

### No-finding confirmation
No additional source-verified defects found in the `is_historical=true` hash-chain exclusion, `inventory.adjust` gate, or concurrency-safe `INV-OB` numbering design beyond the findings listed above.
