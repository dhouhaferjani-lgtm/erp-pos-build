# FINDINGS — 0 Critical, 3 Important

Final whole-branch adversarial review of Session D on local branch `dev`, range
`8f50d7d543e2e50c97cdd326cbe1190047ad2997..4b9f9fc76085a1ea8ad872275d2a2513f3b3074c`.
Code, migrations, tests, and existing records were read-only. The only repository write made by this
review is this report.

## Executive conclusion

Promotion should wait for four bucket-A items:

1. unify and enforce one cash-tender predicate across N-12/Treasury, O-30 expected cash, and the device Z path;
2. restore a durable r3 acceptance record for B-13 (both authz and fiscal records currently end at r2 CHANGES-REQUESTED);
3. restore a durable r3 acceptance record for O-30 (its record currently ends at r2 CHANGES-REQUESTED); and
4. run the N-9 per-tenant units/categories census that the committed promotion checklist explicitly requires before promotion.

No Critical-severity finding was found. I did not run the full test suite. The only executable checks run were the requested
manifest checker and `actionlint`; no path-scoped PHPUnit/Vitest test was needed to establish a finding.

## Evidence and commands actually observed

- `git branch --show-current` → `dev`.
- `git rev-parse --verify 8f50d7d54^{commit}` → `8f50d7d543e2e50c97cdd326cbe1190047ad2997`.
- `git rev-parse HEAD` → `4b9f9fc76085a1ea8ad872275d2a2513f3b3074c`.
- `git diff 8f50d7d54..HEAD --name-status -- apps/api/database/migrations` → six added migrations, enumerated below.
- `cd apps/api && php tools/feature-lane-manifest-check.php` → PASS: `1452 Feature classes in 74 groups`; every group has a disposition, every declared lane is present in `ci.yml`, and every `--filter` entry is anchored and uniquely matched against 1852 test classes. The same output reports 70 parked groups / 1194 classes and one coverage-debt group / one class.
- `actionlint .github/workflows/ci.yml` → exit 0, no output.
- I did **not** execute PHPUnit, Vitest, PHPStan, Pint, or the full suite in this review. Where an earlier gate record reports execution, this report identifies it as prior evidence rather than a run made here.

## Important findings

### I-1 — There is not one cash-tender definition across N-12, O-30, and the device; brownfield rows can be classified in opposite directions

**Severity:** Important. **Promotion bucket:** A.

Verified from current code:

- N-12's shared repository rule declares `payment_methods.is_cash_tender` canonical and uses it for both the cash-only drawer filter and the non-cash preference (`apps/api/app/Modules/Treasury/Application/Services/TenderRepositoryResolver.php:111-155,163-208`). `TreasuryReceiptBridge` independently states the same canonical rule and reads the flag at `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:1074-1103`.
- O-30 does not use that flag. `ShiftExpectedCashService::cashTenderedNetOfChange()` and its change-due helper select `UPPER(pos_receipt_payments.payment_method_code) = 'CASH'` (`apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:185-205,319-326`).
- The device cash-method resolver uses `is_cash_tender` (`apps/pos/src/lib/payment/cashMethods.ts:12-31`), while its Z aggregation uses exact `method_code === 'CASH'` (`apps/pos/src/lib/offline/zReportService.ts:1035-1064`).
- This is not merely a hypothetical malformed row. The pre-range cash migration explicitly leaves mixed-case collision losers unflagged while their codes still satisfy `UPPER(code) = 'CASH'` (`apps/api/database/migrations/tenant/2026_07_28_100000_add_is_cash_tender_to_payment_methods.php:43-64,65-104`). The write guard is only one-way (`true => code === CASH`), and both create and update can persist canonical `CASH` with the flag false (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentMethodController.php:132-143,255-268,358-371`).
- W4R2-2's **direction** composes correctly: `PaymentType::POSRefund->isIncoming()` is false and `isOutgoing()` is true (`apps/api/app/Modules/Treasury/Domain/Enums/PaymentType.php:137-173`), `TreasuryReceiptBridge` writes `POSRefund` for refund receipts (`TreasuryReceiptBridge.php:1482-1489`), and O-30 subtracts return receipt payments (`ShiftExpectedCashService.php:163-176,197-200`). The defect is cash-ness, not incoming/outgoing direction.

Consequence: an unflagged mixed-case cash-code row is treated as non-cash by N-12/Treasury/device checkout, but as cash by O-30. A canonical `CASH` row explicitly unflagged through the API is also treated as cash by O-30 and the device Z but non-cash by the repository resolver. That can put the repository movement and an administratively closed shift's certified expected-cash figure on different tender classifications.

Suggested fix: establish and enforce a bidirectional invariant (`code === 'CASH'` iff `is_cash_tender === true`) with a brownfield census/remediation, then make every consumer use the same predicate. At minimum, stop O-30's case-insensitive `UPPER(...)` match from reclassifying the migration's deliberately unflagged collision losers, and add a cross-layer regression covering a mixed-case loser plus a canonical row with the flag false. I verified the code paths by reading them; I did not execute a test for this newly identified seam.

### I-2 — The committed B-13 gate records do not contain the r3 acceptance claimed by the session ledger

**Severity:** Important (promotion evidence / auditability). **Promotion bucket:** A.

The current authz gate file ends with r2 `quality CHANGES-REQUESTED` and a merge fix list (`docs/superpowers/reviews/2026-08-26-b13-pos-manager-gate-gate-r1-authz.md:394-400,529-534`). The fiscal gate file likewise ends at r2 `quality CHANGES-REQUESTED` (`docs/superpowers/reviews/2026-08-26-b13-pos-manager-gate-gate-r1-fiscal.md:222-228,350-354`). In contrast, the ledger claims “authz r3 + fiscal r3 mergeable” (`.superpowers/sdd/PLAN/progress.md:42-43`) and the session log describes the files as reviews “r1–r3” (`docs/sessions/session-D-2026-08-26/SESSION-LOG.md:30`). A repo-wide search for `947e3b655`, `authz r3`, and `B-13.*r3` found no durable r3 verdict artifact beyond those assertions.

The current code appears to contain the requested fixes: authority freshness reads the roster-only `operators_last_sync` key (`apps/pos/src/lib/auth/operatorAuthorityFreshness.ts:41-75`), the permission surface includes a separate terminal permission and treats an explicit empty permission list as closed (`apps/pos/src/lib/auth/roles.ts:57-63,109-130`), and a 401 is separated from a 403 in the report path (`apps/pos/src/api/reportApi.ts:214-255`). I did not execute the B-13 path tests here, and those observations are not a substitute for the missing final reviewer verdict.

Suggested fix: append or restore the actual r3 authz and fiscal verdicts, including reviewed SHA/range and executed evidence, before promotion. If no r3 reviewer output exists, re-run only the scoped B-13 fix-path gates rather than claiming the r2 files are approvals.

### I-3 — The committed O-30 gate record does not contain the r3 acceptance claimed by the session ledger

**Severity:** Important (promotion evidence / auditability). **Promotion bucket:** A.

The O-30 gate record ends at r2 `spec ❌ + quality CHANGES-REQUESTED`, with an open Critical receipt-window defect and an Important missing account-collection term (`docs/superpowers/reviews/2026-08-26-o30-orphaned-shift-gate-r1-fiscal.md:217-223,253-305`). The ledger says a later r3 was mergeable (`.superpowers/sdd/PLAN/progress.md:57-58`), but no r3 section is present in the gate file and a repo-wide search for `a5c62ead4` / `O-30.*r3` found no durable verdict artifact.

The current code appears to contain the named fixes: the close command bounds the calculation at the release's `occurred_at` (`apps/api/app/Modules/POS/Commands/CloseOrphanedShiftCommand.php:318-326`), `ShiftExpectedCashService` includes account collections (`apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:248-254,410-438`), the reconciler's own replay guard is now directly targeted by the test commentary (`apps/api/tests/Feature/POS/PosShiftProjectionTest.php:427`), and the emission ratchet documents the delegated event (`apps/api/tests/Architecture/ProjectorEmissionRatchetTest.php:176`). I did not execute those paths here. The code observations do not replace a missing final gate verdict.

Suggested fix: append or restore the actual O-30 r3 verdict with reviewed range and evidence. If it cannot be recovered, re-run the scoped O-30 fix-path gate before promotion.

## 1. Migration inventory and ordering

Command used: `git diff 8f50d7d54..HEAD --name-status -- apps/api/database/migrations`.

| Order | Migration | Introduced by | Objects touched | Review |
|---:|---|---|---|---|
| 1 | `tenant/2026_08_25_150000_widen_payments_payment_type_check_for_pos_refund.php` | `62853b47a` | `payments`; drops/re-adds/validates `chk_payments_payment_type_enum` (`:103-153`) | In range. Its own ordering contract says it must precede `150100` (`:50-64`). |
| 2 | `tenant/2026_08_25_150100_retype_supplier_and_pos_refund_payments.php` | `62853b47a`, amended by `5958a3415` | updates `payments`, reads `journal_entries`, `journal_lines`, `accounts` (`:213-273`) | In range. Runs after the constraint widening by filename. |
| 3 | `tenant/2026_08_26_100000_backfill_payment_repository_location_n12.php` | `2d147ba65`, amended through `5367b25a6` | `payment_repositories`, `locations`, `companies`, `pos_terminals`; adds partial unique index `payment_repositories_one_drawer_per_location_type` (`:113-165,382-432`) | Independent N-12 migration. |
| 4 | `tenant/2026_08_26_100000_make_batch_expiry_date_nullable.php` | `b7f2d3e3c`, amended by `e134b616c` | changes `product_batches.expiry_date` and `pos_receipt_line_batch_allocations.expiry_date` nullable (`:31-39`) | W4-1 schema prerequisite. |
| 5 | `tenant/2026_08_26_100000_seed_base_units_for_unit_less_tenants.php` | `40edf1793` | reads `companies`, `units`, `unit_categories`; invokes `UomSeeder` only when both unit tables are empty (`:68-80`) | Independent T9/N-9 migration. |
| 6 | `tenant/2026_08_26_100100_null_invented_default_lot_expiries.php` | `429ff4586`, amended through `a7d9f9a79` | reads/updates `product_batches`, joins `products` (`:76-126,214-222`) | W4-1 data backfill, after nullable migration. |

Ordering conclusions:

- **Verified:** both 08-25 W4R2-2 migrations are in this review range; they were not pre-existing on `dev`. `150000` precedes `150100` lexically and semantically.
- **Verified:** both W4-1 migrations are in this review range. `100000_make_batch_expiry_date_nullable` precedes `100100_null_invented_default_lot_expiries`; the latter also self-guards and emits a loud skip when nullability is absent (`2026_08_26_100100...php:76-94`).
- Laravel keys and sorts migration files by the full migration name (`apps/api/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:578-586`). Thus the three distinct `2026_08_26_100000_*` files have deterministic full-name order: `backfill_payment...`, `make_batch...`, `seed_base...`. No dependency exists between those three.
- **Same table/constraint check:** the two W4R2-2 files intentionally share `payments` and `chk_payments_payment_type_enum` within one lane; the two W4-1 files intentionally share `product_batches` within one lane. No two different Session-D lanes' migrations touch the same table or constraint. N-12 uses `payment_repositories`; W4R2-2 uses `payments`; N-9 uses units tables; W4-1 uses lot/allocation tables.

## 2. Cross-lane seams

### N-12 × W4R2-2 × O-30 — cash/drawer and direction

- **Direction is consistent:** `POSRefund` is outgoing/not incoming (`PaymentType.php:137-173`), the bridge writes it for return receipts (`TreasuryReceiptBridge.php:1482-1489`), and O-30's per-tender total subtracts returns (`ShiftExpectedCashService.php:163-176`).
- **Cash-ness is inconsistent:** see I-1. N-12/Treasury and device checkout use `is_cash_tender`; O-30 uses case-insensitive code; device Z uses exact code.
- `PostShiftCashVarianceAdjustment` reaches the same N-12 resolver rather than a duplicate repository rule, as the resolver's contract records (`TenderRepositoryResolver.php:13-23,142-148`). The drift is therefore outside repository selection: it is in which tender O-30 calls cash.

### B-19 VatPeriodBackdatingGuard × T9 W2-5 product tax writer

- I compared the file sets of the B-19 commits (`360bc3437` through `dad0c56f1`) and the W2-5 commits (`5dc3cbe78`, `831a45524`, `cb43b0be0`) with `comm -12`; the intersection was empty. They did **not** merge-edit one shared writer.
- T9's import writer resolves category before tax and feeds the result into `ProductService::upsert()` (`apps/api/app/Modules/Import/Services/ImportService.php:509-551`). `ProductService` enforces rate/configuration coherence on every upsert and compares rates with bcmath strings (`apps/api/app/Modules/Product/Application/Services/ProductService.php:187-274`).
- B-19 acts later and on a different aggregate: `SupplierInvoicePostingService` takes its idempotency no-op before the VAT/fiscal-period guard (`apps/api/app/Modules/Procurement/Application/SupplierInvoicePostingService.php:100-123,167-174`), and `PostedLineTaxSnapshotBuilder` aggregates the persisted invoice-line tax values (`apps/api/app/Modules/Taxation/Domain/Services/PostedLineTaxSnapshotBuilder.php:141-180`). Product defaulting therefore cannot bypass the posting-period guard, and B-19 does not recompute or overwrite the T9 product configuration.
- Rule 6 is respected at this seam: B-19 consumes `App\Shared\Contracts\Taxation\PeriodBackdatingGuardInterface`; T9 exposes its taxonomy default through `App\Shared\Contracts\TaxDefaultResolverInterface`. No direct Product↔Procurement model import was introduced.
- **Conclusion:** the lanes compose and did not conflict in one writer. This is code-reading verification; I did not execute a combined import→supplier-invoice→post test.

### B-13 permission gates × O-30 lifecycle × N-12 claim 422 — device typed errors

- All network errors originate in one transport type: `ApiRequestError` preserves HTTP `status`, API `code`, message, and details (`apps/pos/src/lib/api.ts:33-53,140-171`).
- N-12 claim handles the typed business code `LOCATION_HAS_NO_CASH_REGISTER` and translates it; other failures fall through to the shared message extractor (`apps/pos/src/pages/TerminalSetupPage.tsx:165-182`). The backend emits that code in the standard nested envelope (`apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:1080-1094`).
- B-13 uses HTTP semantics intentionally: 401 becomes a translated `ReauthenticationRequiredError`, 403 remains an authorization refusal, and only outage-shaped failures fall back locally (`apps/pos/src/api/reportApi.ts:203-255`; surfaced at `apps/pos/src/components/Header.tsx:446-455`).
- O-30's device reconciliation uses the same `ApiRequestError`: 404 means the device-authored open has not projected, while offline/5xx/403 remain advisory `unknown` and do not flip state (`apps/pos/src/lib/sync/shiftReconcile.ts:67-90`). The actual orphan-close command is an operator console surface with typed exit codes, not a device HTTP error.
- **Conclusion:** handling is consistent at the transport layer and deliberately surface-specific; I found no flattening of N-12's 422 into B-13/O-30 fallback behavior. The known structural server-vs-PIN authorization gaps remain owner decisions in bucket C.

### T9 N-14 lineless saveDraft × C-F0w proforma predicate

- A new lineless save with no resolvable existing document returns `null` before authoring a document or spending a number (`apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php:109-124,148-164`). The controller returns `draft_id: null` (`DraftController.php:181-191`), and the hook does not stamp “saved at” or call success for null (`apps/web/src/hooks/useDraftAutoSave.ts:158-185`). No document exists on which the proforma predicate could run.
- An **existing** draft can still be stripped to zero lines; that is explicitly a different residual (`DraftPersistenceService.php:148-155`). If it is an unsealed fiscal document, `Document::isProformaOutput()` still returns true regardless of line count (`apps/api/app/Modules/Document/Domain/Document.php:628-644`). `ProformaPresenter` handles an empty line collection and emits an empty `lines` array / zero gross projection (`apps/api/app/Modules/Document/Application/Services/ProformaPresenter.php:34-64`). Posting remains guarded elsewhere against a lineless document (`apps/api/tests/Feature/Document/AutoSaveRouteHardeningTest.php:759-773` documents that contract).
- **Conclusion:** the two lanes compose: the brand-new lineless case authors nothing; the existing-lineless residual renders fail-closed as a proforma and cannot become a posted fiscal document. No direct cross-lane regression test names both N-14 and C-F0w, so this conclusion is from current code reading, not a test executed here.

### Additional serial-import seam: W4-1 × T9

- The W4-1 and T9 commit file sets overlap only in `ImportService.php` and `ProductsImportPipelineTest.php`; `git log 8f50d7d54..HEAD -- ImportService.php` shows W4-1's expiry result edit (`eeedffe2e`) followed by T9's tax edits (`5dc3cbe78`, `831a45524`) and the final merge (`16088fdb7`).
- Current `ImportService::finalizeImport()` retains W4-1's row-result/warning merge (`ImportService.php:464-485`), while `importProduct()` retains T9's category/tax ladder (`:509-551`). No lost merge resolution was found.

## 3. Hand-made merge resolutions: manifest and CI

### Manifest

The requested expected counts are present in `apps/api/tests/feature-lane-manifest.json`:

| Group | Expected | Observed |
|---|---:|---:|
| Document | 90 | 90 |
| POS | 157 | 157 |
| Taxation | 33 | 33 |
| Uom | 6 | 6 |
| `gated_ceiling` | 1194 | 1194 |

`php tools/feature-lane-manifest-check.php` passed with the exact output summarized under Evidence. This is an executed verification.

### `backend-test-pgsql` filter / union regex

- `actionlint .github/workflows/ci.yml` passed (exit 0).
- The manifest checker parsed every `--filter` entry, required anchoring, and uniquely matched every listed class; this directly validates the hand-built PHPUnit union. The live filter is at `.github/workflows/ci.yml:1081-1082`.
- The job is live for `workflow_dispatch`, PR→main, PR→dev, and push→main, but **not** direct push→dev (`.github/workflows/ci.yml:578-588`). That S-14 posture is accurately documented next to Proforma and in the promotion checklist (`docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md:75-77`).

The four specifically named classes are all in that live filter:

| Class | CI disposition |
|---|---|
| `ProformaResourceTest` | Explicit in live `backend-test-pgsql` filter (`ci.yml:1016-1034,1082`). |
| `OpeningLotExpiryW41Test` | Explicit in live filter (`ci.yml:1066-1082`). |
| `NullInventedDefaultLotExpiryMigrationTest` | Explicit in live filter (`ci.yml:1066-1082`). |
| `SpreadsheetParserDateCellTest` | Explicit in live filter (`ci.yml:1066-1082`). |

All newly added Feature classes in the range were also dispositioned:

| Added class(es) | Disposition |
|---|---|
| `BackfillPaymentRepositoryLocationN12Test`, `BranchCashRepositoryRoutingTest`, `ShiftCashVarianceBranchDrawerTest` | `tests/Feature/Treasury` runs whole in live `treasury-spine-pgsql` (`ci.yml:1208-1228,1324-1328`). |
| Four named classes above | Explicit live `backend-test-pgsql` entries. |
| `CloseOrphanedShiftCommandTest` | POS feature lane is parked; manifest POS note explicitly says it is not in a live filter and records the gate/run-by-path evidence. The lane gate is controlled by `SELF_HOSTED_RUNNER_READY` (`ci.yml:1374-1380`). |
| `SupplierInvoiceVatDeclarationTest` | Taxation feature lane is parked; manifest Taxation note explicitly records that it is not in a live filter. The lane gate is `SELF_HOSTED_RUNNER_READY` (`ci.yml:1496-1502,1599-1608`). |
| `SeedBaseUnitsForUnitLessTenantsMigrationTest` | Uom feature lane is parked; manifest Uom note explicitly records that it is not in a live filter. The lane gate and Uom path are at `ci.yml:1610-1616,1713-1724`. |

Therefore every new Feature class has either a live CI path or an explicit parked manifest note. The parked classes do not execute today; that is verified disposition, not executed coverage.

## CLAUDE.md rule cross-check

- **Rule 6:** no new cross-module model import was found in the named seams; shared contracts are used. Minor layering debt remains in `RepositoryWriteRefusal.php:7-16` (Domain imports a same-module Presentation controller solely for `{@see}`), but CLAUDE rule 6 itself governs cross-module communication, so this is bucket B rather than a rule-6 blocker.
- **Rule 8:** the range adds two new event classes (`OrphanedShiftClosedByOperator`, `OrphanedShiftDeviceCloseApplied`) and modifies/deletes/renames no existing Event class; `git diff --name-status --diff-filter=DMR ...` returned no Event path.
- **Rule 12:** the changed POS routes remain inside `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` (`apps/api/app/Modules/POS/routes.php:37-43`); the change only groups UUID-constrained terminal routes (`:64-106`).
- **Rule 19:** the named cross-lane money paths use bcmath and explicit currency scale (`ShiftExpectedCashService.php:233-284`; `ProductService.php:265-270`; `PostedLineTaxSnapshotBuilder.php:148-180`). The open ImportType percent-ceiling ticket is triaged below.
- **Rule 20:** B-13's authority freshness reader explicitly handles ISO and SQLite-space timestamp shapes (`operatorAuthorityFreshness.ts:77-85`), and O-30's server-side service does not compare device SQLite text timestamps.
- **Rule 21:** review stayed on local `dev`; no fetch, merge, reset, push, branch mutation, or worktree operation was performed.

## 4. Complete A/B/C triage

Duplicate references in `progress.md`, the final gate sections, and the LEDGER are consolidated into one item below, with every source line cited. Each item has exactly one bucket.

### A — must fix before promotion to `origin/dev`

- **[A] Unify cash-tender cash-ness across N-12/Treasury, O-30, and device Z; add the brownfield mixed-case/canonical-false regression.** New cross-lane finding I-1; sources above.
- **[A] Restore or re-run the missing B-13 r3 gate verdicts.** The two durable gate files end CHANGES-REQUESTED (`...b13...authz.md:394-400,529-534`; `...b13...fiscal.md:222-228,350-354`) while progress claims r3 approval (`progress.md:42-43`).
- **[A] Restore or re-run the missing O-30 r3 gate verdict.** The durable gate file ends CHANGES-REQUESTED (`...o30...fiscal.md:217-223,303-305`) while progress claims r3 approval (`progress.md:57-58`).
- **[A] Run the N-9 units/categories census per tenant and manually seed any hand-worked partial tenant before promotion.** `progress.md:55,65`; `docs/handoff/LEDGER.md:201`; explicitly “BEFORE promotion” in `docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md:55-57`.

### B — can ride (safe to promote as-is, including resolved items)

- **[B] W4R2-2 enum widening + evidence-based retype is implemented and ordered.** Ruling at `progress.md:2`; migrations and direction pins verified above.
- **[B] Two-implementer/concurrent-worktree process ruling is historical only; the merges are complete.** `progress.md:3`.
- **[B] B-19 is in tenant #1 purchasing scope; the implemented supplier-invoice declaration path can ride.** `progress.md:4`; final B-19 approval in `...b19...treasury.md:396-474`.
- **[B] B2-6 `useActivateCounting` error-message deferral is resolved in the final tree.** `progress.md:7`; current `apps/web/src/features/inventory-counting/api/queries.ts:130-136` uses `getErrorMessage`.
- **[B] B2-6 remaining lock-order, stale-handle, terminal-activate 500, sentinel, attempted-status wording, unlocked resolved-item check, and `setOpeningCost` check-then-act debts can ride as the final gate ruled.** `...sb2...inventory.md:153-173,183-237,238-257`; registered at `docs/handoff/LEDGER.md:181`.
- **[B] C-F0w W-4 settlement-surface ruling is implemented and its docblock is now present.** `progress.md:10,17`; current `DocumentTotals.tsx:98-115`.
- **[B] C-F0w F-6/ticket N7 two-currency viewport is a separate lane and can ride for the current single-currency onboarding assumption.** `progress.md:13,17`; `docs/superpowers/tickets/2026-08-05-l4-web-followups.md:86-147`.
- **[B] C-F0w stale `=== true` comments and W-4 docblock deferrals are resolved in the final tree.** `progress.md:17-18`; current `types/document.ts:89-108`, `types/creditNote.ts:80-96`, `DocumentTotals.tsx:98-115`.
- **[B] C-F0w missing handback paragraph for `088cb9d74`, W-7 mount-or-delete ticket, W-5 degraded-state copy, F-4/F-5 test prose, F-7 locale scan, F-8 credit-note modal, and F-9 docblock import are non-blocking documentation/test/UI debt.** Final conventions record `...sc-f0w...conventions.md:189-207`; `progress.md:17-18`.
- **[B] W4R2-2 `scopeOutgoing()` non-exhaustive default, unconditional Reverse button, and residual-census wording can ride.** `progress.md:19`; `...w4r2-2...treasury.md:367-390`; `docs/handoff/LEDGER.md:180`.
- **[B] W4R2-2 migration/checklist ordering deferral is resolved in the committed checklist.** `progress.md:19`; `PROMOTION-CHECKLIST...md:53-64`.
- **[B] B-19 snapshot-at-post, persisted-line tax source, supplier-credit-note symmetry, cancel parity filing, dropped subtotal+tax equality, and exact scaled-sum N4 park are implemented/recorded and can ride.** Rulings `progress.md:20-22,31,40`; B-19 r3 dispositions `...b19...treasury.md:404-428`.
- **[B] B-19 successor-probe cache lifetime, fiscal-period remedy wording/coverage, and fully settled AP-opening 422 are minors.** `progress.md:47`; `...b19...treasury.md:429-472`.
- **[B] B-19 owner-executed VAT backfill is a controlled deploy step, not a pre-push code blocker, provided the checklist is followed.** `progress.md:47`; `PROMOTION-CHECKLIST...md:73`.
- **[B] N-12 provisioning/recovery/null-location/ambiguity rulings are implemented.** `progress.md:11-12,35`; N-12 r3 approval `...n12...fiscal.md:161-192`, `...n12...treasury.md:205-227`.
- **[B] N-12 missing regression for `drawer-provisioned-transfer-owed` and same-module Domain→Presentation docblock import can ride.** `progress.md:35`; current import at `RepositoryWriteRefusal.php:7-16`; missing test confirmed by search noted in N-12 gate `...n12...treasury.md:210-227`.
- **[B] N-12 RepositoryTransfer per branch is an explicit post-migration operator step, not a pre-push code fix.** `progress.md:35`; `PROMOTION-CHECKLIST...md:63`.
- **[B] B-13 `/sales` concealment, seven-day roster TTL, permission-derived gate, empty-permissions closed posture, per-surface terminal permission, and 401 reauthentication rulings are implemented in the current tree.** `progress.md:28-29,37,39,43`; current `roles.ts:57-130`, `operatorAuthorityFreshness.ts:41-75`, `reportApi.ts:214-255`.
- **[B] B-13 v2 X over-conceal, test-name/coverage notes, TTL-at-verify snapshot note, timezone-fixture note, and stale `roles.ts:74-77` comment can ride.** Final r2 notes `...b13...fiscal.md:312-348`; current behavior is fail-closed.
- **[B] O-30 variance-zero ruling, shared expected-cash derivation, late device-close replacement, release-bounded window, account-collection term, replay pin, and ratchet annotation appear implemented.** `progress.md:27,44,51,57-58`; current code citations in I-3. Promotion still waits on the missing gate artifact, not on these resolved code items.
- **[B] O-30 SAFE_DROP no-writer note, sign enums, audit-row readback note, and “one derivation” doc correction can ride.** `...o30...fiscal.md:281-295`; current service documents the remaining split at `ShiftExpectedCashService.php:43-58`.
- **[B] W4-1 past-expiry warning, invalid-date refusal, set-once/default-lot conflict, non-batch warning, no-default-lot warning, and `is_expired` reset+census rulings are implemented.** `progress.md:36,54`; `docs/handoff/LEDGER.md:192`.
- **[B] W4-1 CI allowlist deferral/false first claim is resolved by the live filter and r3 records.** `progress.md:30,38,53-60`; `...w4-1...imports.md:168-193`; `...inventory.md:327-389`.
- **[B] W4-1 durable `expiry_notices`, missing POST-result consumer, `ExpiryStatus::unknown` chip, and i18n baseline re-pin are follow-up UI/baseline work.** `progress.md:30`; `docs/handoff/LEDGER.md:194`. The preview warning is live; the POST-only channel remains nondurable.
- **[B] W4-1 no per-column i18n ruling is consistent with the existing raw column-name precedent.** `progress.md:49`.
- **[B] T9 category-before-company tax ladder, every-write import coherence guard, and widened exponent-free comparison regex are implemented.** `progress.md:56,63-65`; imports r3 `...t9...imports.md:394-462`.
- **[B] T9 R-2 numbering-at-confirm remains auditable sequence-gap debt and was explicitly ruled non-blocking for tenant #1.** `progress.md:52,56,65`; `docs/handoff/LEDGER.md:198-199`.
- **[B] T9 fabricated draft UUID is a data-harmless UX truthfulness defect and can ride.** `progress.md:55,65`; current `DraftController.php:205-219`; `docs/handoff/LEDGER.md:202`.
- **[B] T9 manual `PATCH tax_rate` mismatch is explicitly import-path-out-of-scope and can ride with its ticket visible.** `progress.md:63,65`; `docs/handoff/LEDGER.md:203`.
- **[B] T9 bcmath exponent reach is caught per row rather than killing the job; it can ride.** `progress.md:63,65`; `docs/handoff/LEDGER.md:205`.
- **[B] T9 `ProductPriceResolverTest` order fragility is pre-existing test-isolation debt and can ride.** `progress.md:65`; `docs/handoff/LEDGER.md:206`.
- **[B] T9 cross-company offboarding audit attribution is a pre-existing audit-label defect with no added privilege and can ride.** `...t9...authz.md:83-85`.
- **[B] Manifest catch-up counts and the W4-1 three-class filter union are correct and executed by the checker.** `progress.md:60-61`; command output above.

### C — needs an owner decision

- **[C] O-30(b)/(c): first-class orphan-resolution surface and NF525 administrative-close marker.** `progress.md:5,27,58`; `docs/handoff/RUNBOOK-orphaned-shift.md:257-271`; `docs/handoff/LEDGER.md:190`.
- **[C] W4R2-2 historical supplier-payment retype removes generic cash-reversal eligibility.** `progress.md:8,19`; `docs/handoff/LEDGER.md:180`.
- **[C] C-F0w W-8/spec §2.4 wording should say “read/rendering surface” and acknowledge the sanctioned authoring VAT check.** `progress.md:10,13,17`; `docs/sessions/session-C-lifecycle-2026-08-24/SESSION-LOG.md:260`.
- **[C] N-12 one active drawer per location/type, no branch GL dimension, and non-cash fallback-as-preference need owner acknowledgement.** `progress.md:23,35`; `docs/handoff/LEDGER.md:179`.
- **[C] B-19 supplier-invoice snapshot-at-post and persisted-line/bucket-truncation legal interpretation need accountant acknowledgement.** `progress.md:20-21`.
- **[C] B-19 cancel-after-post policy must cover both sales and purchase families before a supplier-invoice cancel lane ships.** `progress.md:22`; `docs/handoff/LEDGER.md:177`.
- **[C] B-19 period-locked draft invoices are stranded without edit/delete; choose correction-at-draft, refuse-at-create, or accept the trap.** `progress.md:40,47`; `docs/handoff/LEDGER.md:178`.
- **[C] B-13 device bearer credentials are owner-scoped and the PIN is not a server security boundary; decide whether this is accepted before non-owner staff receive tenant #1 tills.** `progress.md:43`; `docs/handoff/LEDGER.md:183-184`.
- **[C] B-13 accountant read parity on POS report surfaces.** `progress.md:39,43`; `docs/handoff/LEDGER.md:184`.
- **[C] B-13 blind-count headline/reprint derivability across reports, X, and sales.** `progress.md:28,43`; `docs/handoff/LEDGER.md:185`.
- **[C] B-13 cash-drawer deposit/payout remains server-authorized by `pos.operate_terminal` while the manager check is device-only.** `progress.md:43`; `docs/handoff/LEDGER.md:186`.
- **[C] T9 role strip does not clear `pos_pin`; decide whether role loss is offboarding.** `progress.md:55,65`; `docs/handoff/LEDGER.md:200`.
- **[C] C-13(ii) empty pin-data does not prune the device's last cached operator; decide whether this security residual must close before tenant #1 offline use.** `progress.md:55,65`; `...t9...authz.md:75-85` finding 2.
- **[C] T9 ImportType product tax-rate percent ceiling changes accepted-file behavior and needs an operator-communication decision.** `progress.md:65`; `docs/handoff/LEDGER.md:204`.
- **[C] O-30 post-release late `SESSION_OPEN` has no authorizing release evidence; retain refusal or define a new evidence-bearing recovery.** `progress.md:41,58`; `docs/handoff/LEDGER.md:187`.
- **[C] O-30 `CASH_CORRECTION` direction remains undefined if a writer is ever added.** `progress.md:46,51,58`; `docs/handoff/LEDGER.md:188`.
- **[C] O-30 legacy pre-v4 refund cash impact has no server mirror; decide before using orphan-close on a migrated legacy terminal.** `progress.md:46,58`; `docs/handoff/LEDGER.md:189`.
- **[C] S-14 candidate CI dispatch vs. CI-unverified acceptance.** The live PG filter does not run on direct push→dev (`ci.yml:588`); the promotion checklist asks the owner to dispatch `workflow_dispatch` if CI can run, otherwise accept the recorded local substitute (`PROMOTION-CHECKLIST...md:75-77`).

## Final verdict

**FINDINGS — 0 Critical, 3 Important.** The branch should not be promoted until the four A items are closed. The code-level blocker is the cross-layer cash-tender divergence; the other two Important issues are missing durable final gate evidence for B-13 and O-30. The N-9 census is a known, explicit pre-promotion operational blocker rather than a newly discovered Important defect.
