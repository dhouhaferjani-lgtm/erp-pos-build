# Gate 2 controller review — UoM baseline burn-down Wave 2 — APPROVED 2026-07-28

Controller gate on `chore/uom-baseline-burndown` @ `0aa3e6931` (range `b209afd68..0aa3e6931`, 17 commits, ~110 files). Codex gate report: `2026-07-27-uom-burndown-gate2-codex-report.md` (self-reported CHANGES REQUIRED; the repeated-product ShiftController defect was controller-adjudicated CONFIRMED and fixed in `0aa3e6931`). Two independent Opus lanes (fiscal-pos + frontend-conventions), run by the controller; Codex's internal review not relied upon.

## Verdict: APPROVE — Wave 3 may proceed
Quantity baseline **42 → 0 → `[]`**. Part A of the ticket is COMPLETE.

## fiscal-pos lane — APPROVE (zero findings)
- `0aa3e6931` verified: three sequential passes (map → enrich → cleanup); regression `ShiftReceiptQuantityPrecisionTest` pins `[2,2,3,4]`, fails on the old unset-in-loop code, and asserts `quantity_decimals` absent from persisted attributes (22 assertions, run by path).
- POS device paths display-only: `String(quantity)` everywhere, zero parseFloat/Number; offline enrichment batches sqlite lookups (500/chunk, numbered placeholders); missing product → undefined → scale-4 clamp; `toSqliteUtc` boundary untouched; sync/outbox diff EMPTY.
- Tauri bridge: `display.rs` `quantity: u32 → f64` + `quantity_decimals: Option<u8>` — pure serde passthrough, no float math; the u32 was silently rejecting fractional quantities (latent bug fixed). NO migration (max still v62), zero ALTER/CREATE in diff.
- Order resources additive + batched (`loadMissing`, N+1 pinned by test); analytics regroup preserves SUM math (only same-named distinct products split), fixes an `(int)` truncation of fractional quantities, money untouched at currency scale, legacy null-product snapshot degrades to 4 with unique FE keys.
- Full-diff fiscal sweep: no production touch of hashes/signatures/projections/Z-report/receipt storage.

## frontend-conventions lane — APPROVE-WITH-FIXES (2 MINOR, non-blocking)
- Scanner byte-identical in range; no audit/eslint tooling edits; all ratchets re-run green (`audit:quantity` 0/0/0, keys, design-system 743/0/0, RuleTesters); lint 0 errors, typecheck clean; targeted vitest green (POS 101, web 136).
- Baseline honesty replayed entry-level: 19→0 all genuine; one design-system baseline removal matched by the real `InvoicesPage` Input→QuantityInput migration; `generated.d.ts` +5 matches DTO edits one-for-one (transformer output).
- 14 sites sampled end-to-end: real unit-derived backend emission (COALESCE joins / unitOfMeasure), no fallback-4 cosmetics; `ServiceBundleComponentData` also fixes quantity formatted at MONEY scale (latent bug); ProductInfoModal reads pre-existing emission.
- `BundleComponentFormModal:370` correct QuantityInput migration (derived decimals, string-emitting, RHF wiring intact); billing scale-2 exception SANCTIONED (`InvoiceItem.php:88` documented accessor `getQuantityDecimalsAttribute(): int { return 2; }` — central-billing decimal(N,2) non-product contract; cleared rules structurally, no disable comments).
- **MINOR-1 (fold into Wave 3 opening commit):** null-safe chain hardening — `DocumentAdditionalCostController.php:147` → `$product?->unitOfMeasure?->decimal_places ?? 4`; `OrderLineResource.php:39` → `$unit?->decimal_places ?? 4` (log-noise only, no functional bug).
- **MINOR-2 (pre-existing, NOT this wave):** `useOwnerReports.test.ts` fails on untouched import chain (`viewScopeStore.ts:99` getState) — owner-dashboard test-harness debt; 🎫 tracked, not owed by this branch.

## Conditions attached to approval (non-blocking)
1. MINOR-1 null-safe chains → first Wave 3 commit.
2. MINOR-2 + the 3 Gate-1 red inventory tests remain pre-existing debt — do not chase.
