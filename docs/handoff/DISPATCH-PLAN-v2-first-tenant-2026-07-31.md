# Dispatch plan v2 — first-tenant launch program

**Date:** 2026-07-31. **Supersedes:** `DISPATCH-PLAN-pre-onboarding-2026-07-30.md` (REJECTED — review
record `docs/superpowers/reviews/2026-07-30-codex-dispatch-plan-review.md`).
**Status:** AWAITING ADVERSARIAL REVIEW. Nothing dispatches until APPROVE.

Every v1 finding is incorporated: A1 deleted (refuted — `whereNotNull` is belt-and-braces over `>0`),
A3 demoted to hygiene (predicate is case-insensitive by construction; write API enforces
`is_cash_tender ⇒ code='CASH'`), A2/B1 enlarged, Lane C rescoped around the unproven v3 refund chain,
Lane D split, Lane E added, and every lane now carries an explicit WRITE MANIFEST. Owner rulings
2026-07-31: manual/owner items (secret rotation, device smoke, legal sign-off) are sequenced LAST;
everything dispatchable proceeds now.

---

## Lane A — `SalesReportService::paymentMethodBreakdown` (3 defects, one query)

Worktree `../erp.report-truth`, branch `fix/pos-cash-report-truth` off `origin/dev`.

`apps/api/app/Modules/Accounting/Application/Services/Reports/SalesReportService.php:160-185`:
1. Sums `pos_receipt_payments.amount` = TENDERED cash; overstates by change given back once v3
   traffic starts. Subtract `change_due` — **exactly once per receipt, and only from the cash
   group**. A naïve subtraction after the payment-row join duplicates change across tender legs of
   a split-tender receipt. (Model: `ReportGenerationService.php:504-532` pre-aggregates change per
   receipt in a subquery before summing.)
2. `COUNT(*)` at `:185` counts payment rows, not distinct transactions — a split-tender sale counts
   twice. Fix to `COUNT(DISTINCT pos_receipts.id)` or equivalent.
3. Optional hygiene (only if trivially co-located): note — NOT a live defect — the case-variant
   framing from the checklist was refuted.

TDD: fixture with a split-tender receipt (cash + card legs) + change due; assert cash total and
transaction count discriminate against the unfixed query (verify RED first, state it).

**WRITE MANIFEST:** `SalesReportService.php`; one test file under
`apps/api/tests/Feature/Accounting/` (new or existing report test). Nothing else.

Review gate: treasury-reviewer (Opus). Merge to LOCAL dev, stop.

## Lane B — signed-payload guards + inert ESLint guard

Worktree `../erp.payload-guards`, branch `fix/pos-payload-guards` off `origin/dev`.

- **B1** Pin `V3_DENOMINATION_CAP_BY_SCALE` / `DENOMINATION_CAP_BY_SCALE` against the PHP authority
  `CashRoundingCaps::CAPS` (`apps/api/app/Shared/Domain/CashRoundingCaps.php:40-44`).
  ⚠️ v1 under-scoped this: `readPhpNamedConst` (`FiscalPayloadKeyDrift.test.ts:104`) parses only
  `public const` with lowercase string keys; `CAPS` is `private` with int keys → write a small new
  parser (or extend, without weakening the existing key-set pin). PHP file is READ-ONLY.
- **B2** Real-SQLite round-trip through `insertOfflineReceipt` (`offlineReceiptRepository.ts:99-116`,
  29→32 placeholders) asserting three DISTINCT non-null values land in the right columns. No mocks
  on the insert path.
- **B3** Assert the `appendZSessionCloseAndZReport` handoff — `tolerance_summary` now enters SIGNED
  Z bytes with real values. Extend the existing `zReportService.cashRounding.test.ts` region.
- **B4** `apps/pos/eslint.config.js`: spread `...cartMutatorSelectors` into the third
  `no-restricted-syntax` block (~`:315-332`). Confirm with `--print-config` before/after; the
  pre-existing red `lib/stock/__tests__/cartMutatorGuard.eslint.test.ts` must go green.

**WRITE MANIFEST:** `apps/pos/eslint.config.js`; test files only under `apps/pos/src/lib/fiscal/__tests__/`,
`apps/pos/src/lib/db/__tests__/`, `apps/pos/src/lib/offline/__tests__/`; a parser helper colocated
with the drift test if needed. NO production `src` code besides the eslint config. NO `apps/api`.

Review gate: fiscal-pos-reviewer (Opus). Merge to LOCAL dev, stop.

## Lane C — v3 refund chain: SPEC PHASE ONLY (code phase gets its own manifest after spec approval)

Worktree `../erp.refund-chain`, branch `feat/v3-refund-chain` off `origin/dev`.

Deliverable: `docs/superpowers/specs/2026-07-31-v3-refund-chain-integration.md` answering, from code:
1. What ACTUALLY happens today when a return posts against a v3 terminal — trace
   `refundCheckoutStore.ts:357,379` → `/return` → `ReceiptReturnService` →
   `ReceiptFinalizationService:97-101` (v3 hash computer) → `pos_terminals.last_hash/current_sequence`
   advance, vs the device's own `fiscal_events` chain head. Name the failure mode precisely
   (duplicate sequence? forked chain? quarantine on next device sale? survives?). Write the missing
   integration test's shape: sale → online return → next device sale → Z close at schema 3.
2. The integration design: who authors the refund fiscal event on a v3 terminal (device-authored
   REFUND flow vs server-authored with chain reconciliation), respecting: `receiptService.ts` is the
   only device SALE_RECEIPT authoring site; refund model = SALE_RECEIPT + `invoice_type_code=REFUND`
   (REFUND_RECEIPT enum is vestigial); V1/V2/V3 payload builders are byte-immutable.
3. E1 refund rounding ON TOP of that design (adopted pattern:
   `docs/superpowers/specs/2026-07-27-refund-rounding-research.md` — independent Swedish rounding of
   the cash payout, no unwinding, partials independent, VAT exact, delta → 6580/7580, own receipt
   line), including whether refund adjustments enter the Z `cash_rounding_summary` (coordinate with
   B3's test surface — B owns that file until C's code phase).
4. Constraints: `hydrateFromReceipt.ts:53` never reads `receipt.total`; `ReceiptReturnService.php:1058-1084`
   caps returns by QUANTITY not amount; the avoir carries no rounding line (`buildReceiptData.ts:712-713`);
   `ReceiptReturnRefactorTest.php:490` pins v2 and must gain a v3 twin, not be edited in place.

**WRITE MANIFEST (spec phase):** the spec file only. Zero code.

Review gate on the spec: fiscal-pos-reviewer + treasury-reviewer (Opus) + Codex adversarial pass.
Then a code-phase plan with its own manifest.

## Lane D1 — provisioning correctness

Worktree `../erp.first-tenant`, branch `feat/first-tenant-provisioning` off `origin/dev`.

1. Wire `CountryPaymentSettingsSeeder` into `DatabaseSeeder`, `CoffeeShopSeeder`,
   `ParapharmacySeeder`, `DemoPharmacySeeder` (today only `ProductionSeeder:31` +
   `TenantInitializationService` call it). ⚠️ memory `project_seeder_optional_company_container_trap`:
   seeders with `?Company $company = null` silently no-op under `tenants:run db:seed`.
2. Establish the provision-at-v3 path: new tenant's terminals at `fiscal_schema_version = 3` from
   creation. Report whether landed code already supports it or what changed. Note
   `TenantProvisioningService.php:161` provisions the main location POS-disabled — establish intent,
   document the enable step, don't silently flip it.
3. Launch-contract test extending `TenantProvisioningServiceTest.php:72`: a freshly provisioned
   tenant has country payment settings, a valid CASH tender (`is_cash_tender=true`), terminal v3,
   and a resolvable payment policy (`PosPaymentPolicyResolver` returns coherent enabled/denomination
   state). This is the executable definition of "tenant #1 starts correct".

**WRITE MANIFEST:** the four seeder files; `TenantProvisioningService.php` (+ its test) ONLY if step 2
requires it and the report justifies it; `TenantProvisioningServiceTest.php` or a new sibling test.
NO reporting services, NO `apps/pos`.

Review gate: tenancy-authz-reviewer (Opus). Merge to LOCAL dev, stop.

## Lane D2 — ops docs (single session, two deliverables, docs only)

Worktree `../erp.launch-ops`, branch `docs/launch-ops-runbook` off `origin/dev`.

1. **One ordered staging runbook** consolidating the 7 open checklists: treasury ③/④/⑤a/⑤b,
   multiloc, `RolesAndPermissionsSeeder` + `permission:cache-reset` (Spatie cache is TENANT-BLIND),
   Horizon restart, `DemoPharmacySeeder --force` rerun. Use the Task-8 artisan commands
   (`treasury:backfill-banks`, chart seeding — fleet form `--option=dry-run=1`), never raw SQL.
   Mark what staging evidence shows already done. DO NOT execute anything against staging.
2. **Runbook placeholder burn-down:** fill every placeholder in
   `docs/pos-operations/{install,backup,support,walkthrough-rehearsal}.md` that is derivable from
   the repo (versions, paths, commands); compile the rest (installer checksum, support phone, backup
   destination, rehearsal record…) into ONE owner-manual checklist
   `docs/handoff/OWNER-manual-launch-gates-2026-07-31.md`, alongside the other owner items: secret
   rotation (12 pending rows in `docs/security/secret-rotation-2026-05-12.md`), P0 real-device smoke
   (`docs/qa/2026-05-13-first-tenant-handoff.md:59`), TN accountant/legal sign-off (`:88`), erp-mobile
   push (24 commits), E1 acceptance decision if Lane C is descoped. Target: `preflight-runbooks.sh`
   failures reduced to exactly the owner-input rows.

**WRITE MANIFEST:** `docs/handoff/` (two new files), `docs/pos-operations/*.md`. Nothing outside `docs/`.

Review gate: one Opus doc-accuracy pass (citations vs code/checklists). Merge to LOCAL dev, stop.

## Lane E — owner-manual gate (tracked, NOT dispatched)

Sequenced LAST by owner ruling 2026-07-31. Items live in D2's owner checklist. Orchestrator surfaces
them at every sync; no agent attempts them.

---

## Fencing summary (disjoint by construction)

| Lane | apps/api | apps/pos | docs |
|---|---|---|---|
| A | `SalesReportService.php` + 1 test | — | — |
| B | — (reads `CashRoundingCaps.php`) | eslint.config.js + tests only | — |
| C (spec) | — | — | 1 spec file |
| D1 | 4 seeders + provisioning svc/test | — | — |
| D2 | — | — | handoff + pos-operations |

No file appears in two manifests. B3's Z-test file is B's until C's code phase, which will declare
its own manifest against then-current state.

## Execution order

A, B, C(spec), D1, D2 dispatch in parallel on APPROVE. Merges to LOCAL dev as they land; batched ff
promotion. C's code phase follows its spec gate. Lane E last. Enable of cash rounding remains blocked
on C (or a signed acceptance at `cash-rounding-phase2-deploy-checklist.md:226`).
