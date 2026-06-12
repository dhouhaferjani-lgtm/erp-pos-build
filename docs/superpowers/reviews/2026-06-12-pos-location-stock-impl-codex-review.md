# POS Location-Aware Stock — Final Implementation Codex Review + Adjudication

- Date: 2026-06-12
- Scope: full branch diff `c2c92007b..HEAD` (`feat/pos-location-aware-stock`, 14 tasks)
- Reviewer: Codex adversarial pass (sandbox could not write this file; findings transcribed verbatim from the run, adjudication appended by the orchestrating session)
- Codex verdict: REQUEST-CHANGES, 86% confidence → **after adjudication: 2 real P1s FIXED, 4 findings REJECTED with code evidence**

## Codex findings (verbatim summary)

### BLOCKER — Delta pull cursor lost under READ COMMITTED + offset pagination
Claim: rows inserted between page-fetches shift offsets; "the cursor written to SQLite is the one from page 1, so the delta baseline anchors before those interstitial writes. A stock update that lands between two pages will be included in neither the current full pull (already past) nor the next delta pull (cursor is post-update)."

**ADJUDICATION: REJECTED — premise factually wrong.** The cursor IS page 1's `as_of`, captured server-side BEFORE the first read (`PosStockLevelController` captures `$asOf` before `read()`; client stores page 1's value — `syncService.ts:922,941`). Any row inserted/updated mid-pull has `updated_at > cursor` and is therefore picked up by the NEXT delta. A row skipped by offset drift in the current pull is re-sent next delta; nothing is ever permanently lost. This re-send-not-skip direction is the documented design choice (syncService.ts:889-892 region, Task 9).

### P1 — replaceAllStock not transactional; DELETE-then-INSERT race empties the table
**ADJUDICATION: REJECTED as stated — premise wrong; residual ACCEPTED + documented.** `replaceAllStock` is upsert-FIRST, delete-absent-AFTER (`locationStockRepository.ts`) — the table is never empty mid-replace. A concurrent reader sees at worst a transient old+new mix that the next pull self-heals. Codex's secondary claim ("a delta pull will read an empty table and write a zero-quantity snapshot") is also wrong: pulls write server rows; they never read-modify-write local state. A race-acknowledging comment was added to `replaceAllStock` in the fix commit.

### P1 — `warn` policy still hard-blocks at ProductCard activation
**ADJUDICATION: ACCEPTED — REAL. FIXED.** The tile disabled activation on `isOutOfStock` regardless of policy, so `warn` could never reach the gate. Fix: `hardBlockOutOfStock` prop (HomePage derives `policy === 'block'`; threads ProductGrid → ProductCard; default true = fail-safe). Out-of-stock styling shows regardless; only activation gating is policy-aware. Pinned by `ProductCard.stock.test.tsx` ("keeps an out-of-stock tile TAPPABLE under warn/off").

### P1 — Stale cached terminal payload (no `pos_stock_policy`) hard-blocks Menu tenants
**ADJUDICATION: ACCEPTED — REAL. FIXED.** A cached pre-deploy terminal payload falls back to `'block'`; Menu tenants skip the stock pull, so product-type menu items read available=0 → blocked sales until the terminal record refreshes. Fix: `gateStockForAdd` short-circuits to PASS for Menu-module tenants (`hasModule(companyConfig, 'Menu')`) BEFORE the policy read — mirroring the pull skip; made-to-order tenants can never be frozen by the fallback. Pinned by `stockGate.test.ts` ("Menu-module tenant → PASS even with a stale terminal payload").

### P2 — SQLite migration v51 not idempotent on re-run
**ADJUDICATION: REJECTED.** The POS migration runner is version-gated (applies only versions above the recorded one); bare `ALTER TABLE ADD COLUMN` is the established house pattern (cf. v41 `add_account_charge_credit_controls_to_customers` and every other column migration). Test harnesses build fresh in-memory DBs. No re-run path exists that the rest of the migration history doesn't already share.

### NIT — Laravel transfer-line index migration not re-run safe
**ADJUDICATION: REJECTED — misattributed.** The cited file (`2026_06_09_..._add_variant_support_to_stock_transfer_lines.php`) is from PR #182, already merged to dev — not part of this branch. This branch's index migration (`2026_06_12_000100`) follows standard Laravel migration tracking; `migrate:fresh` drops first.

## Seams Codex verified clean (verbatim)
- seller identity / `requireText` null paths — behavior identical to old company sourcing
- `companyId` fail-open bounded to the pre-auth window
- `decrementStock` policy threading scoped to ReceiptCreationService only
- no float leaks on quantity fields in the diff
- all `t()` keys present in en+fr
- no double-count window on the receipt synced-flip (atomic status write + `!= 'synced'` predicate)
- no new raw `addItem` callers bypassing the ingress pin

## Post-fix verification
- POS vitest full suite: 225 files / 2167 tests → after fixes: stockGate 17, ProductCard.stock 11, pages/stock sweep 124 — all green; typecheck + ESLint clean.
- PHP: 80 tests / 352 assertions across all 12 touched test files; PHPStan clean (sole report = pre-existing `HasTranslations` scope artifact, disappears when app/Modules/Product is in the analyze path).
