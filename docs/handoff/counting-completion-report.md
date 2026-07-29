# Live inventory counting completion report

**Date:** 2026-07-28
**Branch:** `feat/live-counting-completion`
**Scope:** A1–A6 from `CODEX-live-counting-completion-2026-07-28.md`

## Delivered

### A1 — preserve precise placements

Finalizing a zone-scoped count now keeps a product's live placement when it is already inside the counted subtree. Unplaced products and products outside the subtree are still assigned to the counted node. The membership check uses the canonical collision-safe subtree scope, and regression coverage pins descendant preservation, outside re-homing, unplaced assignment, `A1`/`A10` collision handling, cross-location isolation, and unchanged stock rows.

### A2 — refresh POS counting blocks during sync

The POS terminal-state pull now consumes `active_counting_block` and `counting_zone_advisories` on every successful sync. Active blocks reach the existing stock gate without a restart, clearing the server block restores selling, and omitted fields from stale servers fail open to no block / no advisories.

### A3 — surface late-sync counting risk and residuals

Before finalize, the review flow reports physical-terminal sync health and requires a fresh acknowledgement when pending, stale, or unknown terminal state creates risk. After finalize, a read-only detector attributes sales movements that arrived too late for their count and exposes the residual quantity on the count detail/report. It deliberately does not auto-correct stock.

### A4 — preview replay-adjusted posting

Pending-review reconciliation now performs a non-mutating replay dry-run using the same replay computation used by final application. The API and review UI show movements since count, expected quantity now, the adjustment that would post, and block/skip reasons. Display precision is resolved from the product UoM, and finalized rows retain the actual replay audit.

### A5 — complete Arabic counting translations

The Arabic counting subtree now matches EN and FR exactly at 254 scalar leaves with identical interpolation placeholders. Full Arabic plural categories were added for late-sale messaging; matching leaf keys in EN/FR preserve strict structural parity. Arabic terminology was aligned for replay, POS tills, opening balance cost, counters, and compact variance labels.

### A6 — validate batch-added products

`batchAddProducts` now rejects malformed and non-company product references per item while keeping valid siblings. UUID candidates are resolved in one bounded, company-scoped query; uppercase UUIDs normalize for comparison and persist the canonical database ID. Barcode-only and empty-ID-plus-barcode fallback behavior remains available. Errors now include stable codes for malformed IDs, missing products, and malformed barcodes.

## Review gates

Every task has a committed Opus approval record under `docs/handoff/gate-reviews-counting/`:

- A1: `inventory-costing-reviewer`, approved round 3.
- A2: `fiscal-pos-reviewer`, approved round 2.
- A3: `inventory-costing-reviewer` and `fiscal-pos-reviewer`, approved round 4.
- A4: `frontend-conventions-reviewer`, approved round 3; `inventory-costing-reviewer`, approved round 4.
- A5: `frontend-conventions-reviewer`, approved round 2.
- A6: `inventory-costing-reviewer`, approved round 2.

## Verification

- Backend named paths: 58 tests, 273 assertions, all green:
  - `ZoneScopedCountingTest.php`
  - `LateSyncResidualTest.php`
  - `TerminalSyncHealthTest.php`
  - `PreFinalizeReplayPreviewTest.php`
  - `ReplayFinalizeTest.php`
  - `BatchAddProductsValidationTest.php`
- Pint on every PHP file changed by A1–A6: clean.
- PHPStan level 8 on every PHP file changed by A1–A6: no errors.
- Web TypeScript: clean.
- Web focused Vitest: 3 files, 22 tests, all green.
- Web lint and its query-key, design-system, quantity, and ESLint-rule audits: exit 0.
- POS TypeScript: clean.
- POS focused Vitest: 2 files, 73 tests, all green. Expected failure-path log output was emitted by tests that intentionally exercise non-fatal sync errors.
- POS lint: 0 errors; 83 pre-existing warnings. Both custom UoM ESLint rule suites passed.
- UoM quantity-display scanner: `0 total (0 baselined, 0 new, 0 stale baseline entries)`; `quantity-display-baseline.json` remains exactly `[]`.
- Locale parity: EN / FR / AR each have 254 `counting.*` scalar leaves; zero missing, extra, or placeholder-mismatch keys.
- Frozen fiscal/projection/parser/golden-vector surfaces and `packages/shared/types/generated.d.ts`: unchanged.
- `git diff --check`: clean.

## Not run

- The full PHPUnit suite was intentionally not run; the brief explicitly prohibits it. Only named test files were run by path.
- `scripts/preflight.sh` was not run because the brief permits the bounded verification above in this worktree. Its required constituent checks for this lane were run directly.
- No staging, browser E2E, or physical-terminal test was performed from this worktree.

## Migrations and generated artifacts

No migration was added. There is therefore no migration ordering or self-guarding concern. No generated shared type artifact changed.

## Residual risks and rollout notes

- A3 is detection and attribution only. Operators must decide how to correct an identified late-sync double reduction; automatic reversal remains intentionally out of scope.
- A1 row-lock contention is not exercised by the SQLite test database. PostgreSQL lock placement was reviewed, but real concurrent finalization remains an integration risk.
- Draft counts already poisoned before A6 with barcode strings or foreign IDs in `scope_filters.product_ids` are not repaired by this prevention change. Check or recreate suspicious staging drafts before testing.
- ~~The new A6 `invalid_barcode` error branch is statically reviewed but does not have a dedicated test; the required valid barcode path is covered.~~ **Closed by `cdc7d170d` (2026-07-29):** the branch now emits the typed constant `INVALID_BARCODE` and has a dedicated test (`BatchAddProductsValidationTest::test_non_string_barcode_returns_typed_code_and_unchanged_message`).
- Release sequencing matters: an old mobile build still sending a barcode in `productId` will receive a per-item `invalid_product_id` error after A6 instead of false success. That is the correct server behavior. Deploy the mobile B1 fix before or with this lane when possible, while accepting that already-installed old builds will surface explicit scan errors until updated.
- Existing POS lint warnings remain outside this lane; this work introduced no lint errors.

No merge or push was performed.
