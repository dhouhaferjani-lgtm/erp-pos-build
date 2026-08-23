# C-7 residuals — committed-state bleed in `tests/Feature/Fiscal/`

Opened 2026-08-23 by the C-7 test-infra lane. LEDGER row **C-7** closes on the
scoping fix (`PosCoreReceiptProjectionTest` is now deterministic); these three
residuals are the *cause* and its blast radius, deliberately left out of that
lane's scope. C-7's closure note should point here.

**R-1 — the actual cure (highest value).**
`apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionRefundDispositionStockTest.php:63-66`
overrides `connectionsToTransact()` to return `[]`, disabling the
`RefreshDatabase` wrapping transaction, and has **no `tearDown()`** — so every
fixture and projection row it writes commits and survives for the rest of the
PHP process. It is the only such class in `tests/Feature/Fiscal/`. Fix: add the
cleanup `tearDown()` that `tests/Feature/Inventory/CountCorrectionGlPostingTest.php:168-178`
already models (delete its `stock_movements` / `journal_entries` /
`pos_receipt*` / `tenants` rows by company id). Expected effect: the 12 sibling
reds in the `PosCoreReceiptProjection*` cluster go green and the R-3 collision
surface disappears for this directory. Needs its own review round — it moves
the directory's inherited-red baseline (currently 53 failed / 3 skipped /
798 passed, stable across three consecutive PG runs).

**R-2 — siblings with the same unscoped-read pattern (listed, not fixed).**
Unscoped whole-table `count()` / `first()` reads on projection tables:
`TreasuryReceiptBridgeTest` 24 · `PosCoreReceiptProjectionRefundNoDecrementTest` 6 ·
`ApplyFiscalEventProjectionJobTest` 5 · `PosCoreReceiptProjectionVariantStockTest` 3 ·
`NewSaleServerAuthoringDispositionTest` 2 · `AccountPaymentProjectionTest` 1 ·
`AccountChargeProjectionTest` 1. Each is latently order-dependent the same way
C-7 was; only those after R-1's class alphabetically are red today. Fixing R-1
masks them again rather than removing the pattern.

**R-3 — global `TenantFactory` slug collision.**
`apps/api/database/factories/TenantFactory.php:36` is
`'slug' => Str::slug($this->faker->unique()->company())`. `unique()` de-dupes
the *company name*; `Str::slug()` then collapses distinct names onto the same
slug ("Collier PLC" / "Collier, PLC" → `collier-plc`). Harmless while
`RefreshDatabase` rolls tests back, but against committed tenants it fires a
random `tenants_slug_unique` violation **in `setUp()`** — observed once in
three runs during this lane, and worked around locally by pinning explicit
slugs in `PosCoreReceiptProjectionTest`. Global fix: apply `unique()` to the
slug itself, not to the company name. Affects any class creating many tenants
in one process.
