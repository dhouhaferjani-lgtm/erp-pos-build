# Adversarial code review — P2P entry points, Waves 1+2

**Date:** 2026-07-05 · **Reviewer:** Claude (adversarial code review)
**Diff:** uncommitted working tree in `apps/erp.p2p-flow` (branch `feat/p2p-entry-points`)
**Against:** plan Rev 2 W1+W2 + Global Constraints (`docs/superpowers/plans/2026-07-05-p2p-entry-points-plan.md`);
task log (`docs/sessions/TASK-LOG-w1w2.md`); spec §3/§4/§10.

**Verdict: NEEDS-FIX-ROUND** — one MAJOR (explicit plan-constraint violation, cheap fix) + minors.
No BLOCKER. Backend logic, GL grain, fail-closed policy, tests are sound.

---

## BLOCKER
None.

## MAJOR

### M1 — Permission naming breaks the same-resource convention the plan explicitly mandated
`apps/api/database/seeders/RolesAndPermissionsSeeder.php:122`
New permission `goods-receipts.create-standalone` is **plural**, but the existing sibling
permission for the same resource is **singular**: `goods-receipt.edit-price` (seeder:121).
Global Constraints (plan §"Routes", [CL-m2]) states verbatim: *"naming MUST mirror the
seeder's existing prefixes for the same resource (e.g. `goods-receipt.edit-price` is
singular — inspect and match; cite chosen names in TASK-LOG)."* The task log (Task 2.4)
lists the chosen names but does not flag or justify the singular→plural split. Result: the
`goods-receipt` resource now carries two divergent prefixes (`goods-receipt.*` and
`goods-receipts.*`); the W4 standalone-receipt route + FE gate must reference this exact
string, and a split prefix is a real gating-mismatch hazard.
- **Fix:** rename to `goods-receipt.create-standalone` (singular) in the seeder + the
  permissions test (`P2pEntryPointPermissionsTest.php:19`). The `supplier-invoices.*`
  names are FINE — there is no pre-existing `supplier-invoice(s).*` permission in the
  seeder, so plural correctly follows the dominant resource convention (purchase-orders,
  invoices, credit-notes …).

## MINOR

### m1 — PO guard is coarse (blocks all line edits if any line is receipted); partial-edit case untested
`PurchaseOrderController.php:479-491`, `PurchaseOrderLineMutationGuardTest.php`
The guard blocks the whole line payload when ANY existing PO line has a receipt, rather
than diffing incoming-vs-received lines. This is **safe and correct** given the controller
replaces all lines (delete-all + recreate, :492+), so editing one line necessarily deletes
the received line's id and orphans the receipt reference. But the reviewer's off-by-one
case — a 2-line PO, one line receipted, editing only the other line — is neither pinned nor
documented; behavior is "blocked". Acceptable-but-note; add a multi-line pin if the coarse
semantics are intended to be permanent.

### m2 — `poLineIdsWithReceipts` ignores receipt status (no `goods_receipts.status = Posted` filter)
`GoodsReceiptService.php:457-471`
The lock query matches `GoodsReceiptLine` of ANY status. Harmless in W1/W2 (only Posted
receipts exist today), but once W3 lands Draft receipts, a Draft will lock PO lines.
Whether a Draft *should* lock is a design call not made here — flag for W3 reconciliation.

### m3 — drift command `--tenant` diverges from the reference command's scoping and only WHERE-filters
`GrirDriftReportCommand.php:17,41-43`
The cited reference `RematchDraftSupplierInvoicesCommand` scopes with `--company=` on the
already-bootstrapped tenant connection (both share the `@cross-tenant-by-design` docblock).
This command instead exposes `--tenant=` which only adds `where('goods_receipts.tenant_id',
…)` — it does NOT switch DB connections. Under db-per-tenant each tenant DB already isolates
rows, so `--tenant` is redundant and misleads operators into thinking it selects a tenant.
`goods_receipts.tenant_id` does exist (migration `2026_07_04_100000…:16`), so it won't error
— purely a consistency/clarity nit. Prefer `--company=` (within-tenant) to mirror the sibling.

### m4 — Rule 18: hardcoded `border-gray-200` on new toggle cards
`CompanyPage.tsx:571,586,601`
The three new entry-point toggle cards use hardcoded `border border-gray-200`, while the
adjacent new `border-t` (:570) correctly uses `borderColors.light`. Migrate the card borders
to a `borderColors` token per rule 18 (touched code).

### m5 — Out-of-scope Product edit changes ingredient-ingestion behavior, untested
`EnrichmentReviewService.php:337-361` (`ingredientsFromPayload`)
The refactor now DROPS any ingredient whose `position` is not a strict PHP `int`
(`is_int($position)`), where the old code passed the raw array through. json_decode yields
`int` for JSON numbers so real-world risk is low, but a payload delivering `position` as a
numeric string would silently lose ingredients. No test accompanies these Product-module
edits. Also note the scope-creep: these Product fixes (justified in the task log as needed
for the repo-wide PHPStan gate) belong in a separate commit from the P2P waves.

### m6 — FE preset entry-point map duplicates backend mapping (drift risk)
`CompanyPage.tsx:96-100` (`procurementPresetEntryPoints`) hardcodes the same
complet/standard/leger → receipt/invoice mapping that lives in
`ProcurementPreset::values()`. Display-only, but a second source of truth that can drift.

---

## Verified sound (checked, no issue)

- **EnrichmentReviewService silent-skip on non-string `trackingId`** (:211,:257) — NOT a
  regression. `$trackingId = $enrichmentResult->tracking_id` (varchar, nullable); non-string
  ⇒ null (locally-curated rows with no platform submission id). The OLD code would TypeError
  on null → caught → logged a spurious "dispatch failed" warning; the NEW guard cleanly
  skips. Behavior for the real non-null feedback path is **identical**; only spurious noise
  is removed. Correct — should skip, not log/throw, since null = nothing to correlate.
- **ProductData `stock_quantity` guard** (`ProductData.php:139-140`) — `is_scalar &&
  is_numeric` + `CurrencyScale::bcformatStrict(…,4)` is rule-19 compliant and behaviorally
  identical for null / numeric input; only diverges on a non-numeric scalar (e.g. `''`),
  which does not arise for a numeric aggregate/column. Safe improvement over `bcadd`.
- **Guard (Task 1.1)** uses the EXISTING `GoodsReceiptService` injection
  (`PurchaseOrderController` ctor :17), returns 422 via `validationErrorResponse`
  (`HandlesDocuments.php:292`) with code `PO_LINES_LOCKED_BY_RECEIPTS`, only inside
  `if ($lines !== null)` so header-only edits pass. Single whereIn+distinct query (no N+1);
  po_line_ids sourced from the loaded (company-scoped) PO + db-per-tenant ⇒ no cross-tenant
  leak. Test matrix pins replace-rejected / unreceipted-editable / header-editable.
- **Drift command (Task 1.2)** — movement-grain join correct:
  `journal_entries.source_id = goods_receipt_lines.movement_id AND source_type='goods_receipt'`
  (matches `GeneralLedgerService::createGoodsReceiptGrIrEntry` :1060/:1108-1109). Free
  zero-cost exclusion correct: only `whereNotNull('movement_id')` (paid) is expected, and
  the GL method returns null for `amount <= 0` (:1078), so free/`free_movement_id` movements
  legitimately have no entry and are not flagged — pinned by the test (paid entry present,
  free entry absent, exit 0; delete paid entry, exit 1). Exit codes 0/1, grouped output sane.
- **Migration (2.1)** adds 3 booleans (false/false/true) + explicit backfill UPDATE (spec
  §3.1 "no NULL window"); model casts `boolean`; `defaultForVertical`/`firstOrCreateForCompany`
  populate all three.
- **Fail-closed accessors (2.2)** — `booleanAttributeOrDefault` keys on
  `array_key_exists(…, $this->attributes)` then null-check; the old-shape test constructs
  `new ProcurementPolicy([...])` WITHOUT the new keys and asserts false/false/true — genuine,
  non-tautological. Missing-row test iterates Pharmacy+Mechanic verticals.
- **Preset mapping (2.3)** matches spec §3.3 exactly (complet f/f, standard t/f, leger t/t);
  `invoice_first_requires_approval` NOT preset-mapped; the preservation test
  (`ProcurementPolicyApiTest` :282-298) pins that applyPreset(Leger) leaves a hand-set
  `invoice_first_requires_approval=false` intact while flipping the two toggles.
- **API validation (2.5)** — three booleans `required_without:preset`; preset/raw exclusivity
  list extended with all three; GET/PUT/preset/raw tests assert response + DB. `generated.d.ts`
  additions sit in DTO field order (after `variance_tolerance_max_amount`, before
  `created_at`) — consistent with `typescript:transform` output, not hand-edited.
- **FE** — Toggle atom resolves (`components/atoms/Toggle/index.ts`, `role="switch"` +
  `aria-checked`); all copy via `t()` in the `purchases` ns across en/fr/**ar (RTL present)**;
  reuses `tenantScopedKey(['procurement-policy'])`, introduces no new query key; booleans not
  money so MoneyInput/parseFloat N/A. Test pins render-state + save payload booleans.
- **Role assignments** — admin (Permission::all), manager (all 4), accountant (3
  supplier-invoices.* only), viewer/cashier/operator (none); no permission removed. Pinned.
- No `app()` helper introduced; no floats on money/qty (rule 19 clean).

---

## Verdict: NEEDS-FIX-ROUND
Single required fix: **M1** rename `goods-receipts.create-standalone` →
`goods-receipt.create-standalone` (seeder + permissions test) to honour the explicit
same-resource naming constraint. Recommended-but-optional: m4 (border token), m5 (Product
edits to a separate commit + a test for the ingredient filter). m1/m2/m3/m6 are notes for
W3/W4. Everything else is verified sound.
