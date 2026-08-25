# W4-3 + W4-4 — treasury/GL + opening-balance gate r1

**Lane** `fix/campaign-w43-ap-opening-partner-ledger` · worktree
`/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w43-openings` · HEAD `740e99e69`
(impl `800cf019c` `77498ad39` `3a4bf3489` `7e9303abd`; dev merged last at `2f239eaf4`) ·
current dev at review time `c97a735ad`.

Handback: `docs/superpowers/reviews/2026-08-25-w43-handback.md` (worktree).
Brief: `docs/sessions/session-A-2026-08-24/BRIEF-W4-3-ap-opening-partner-ledger.md`.

## VERDICT

**spec ✅ · quality ❌ CHANGES-REQUESTED**

Every acceptance criterion in the brief is met and I re-verified each one by execution, not by
reading the handback. The lane is well built: real red-first proof, real endpoint tests, no
floats, no hardcoded account codes, correct exclusion from both hash chains, deptrac/pint/phpstan
green, PG green. It is held for **one defect the lane itself introduces** (C-1: the aged reports
now contradict the GL for credit-note openings, proven with numbers below), for **three
completeness gaps in the new control-account guard** (I-2/I-3/I-4), for the **containment claim in
handback §10 being wider than the guard actually is** (I-1), and for **stale manifest arithmetic
against current dev** (I-5, mechanical).

---

## What I executed (evidence, not restatement)

| Check | Result |
|---|---|
| 3 new classes, sqlite, one process | `OK (14 tests, 66 assertions)` |
| 3 new classes + `PartiesImportBalancesTest`, PG 16 throwaway `autoerp_test_w43g` (127.0.0.1:5433, dropped after) | `OK (18 tests, 100 assertions)` |
| Independent red proof — APFS clone of `apps/api`, `patch -p3 -R` of `git diff dev...HEAD -- apps/api/app` (658 lines), same 3 classes | `Tests: 14, Failures: 12` — the 2 that stay green are the two negative-control tests, as the handback states |
| `./vendor/bin/pint --test` | `{"result":"pass"}` |
| `./vendor/bin/phpstan analyse` (level 8, incl. `DocumentStatusWriteOnlyViaStatusService`, `phpstan.neon:36`) | `[OK] No errors` |
| `php tools/deptrac-ratchet.php` | `PASS — 183 / 183, no boundary regression` |
| `php tools/feature-lane-manifest-check.php` on lane HEAD | `EXIT=0`, 1409 classes / 74 groups, gated 1168 |
| `ProvisioningRequiredPurposes{RegistrationRatchet,V1Conformance}Test` on HEAD | 2 failures — **re-run with the lane's production patch reverted: identical 2 failures ⇒ INHERITED RED on dev, not this lane** |

### Brief item-by-item

1. **W4-3 AP opening is supplier-side.** `ArApOpeningService.php:182-183` (side-aware match),
   `:500-501` (`HIST-SINV` / `HIST-SCN`). Supplier payment takes the supplier arm: proven through
   the real `POST /payments` in `OpeningItemPaymentDirectionTest.php:162-233` — `supplier_payment`
   JE, `Dr SupplierPayable 500.000 partner=<supplier>`, `Cr Bank 500.000`, movement
   `direction=out`, repository −500.000, `assertDatabaseMissing(journal_entries, customer_payment)`.
   Both direction refusals return 422 `PAYMENT_DIRECTION_MISMATCH`
   (`PaymentController.php:508-522` + `:1426-1433`), and the customer-typed-on-supplier case fails
   **closed** (no payment row, no allocation, no movement — asserted at
   `OpeningItemPaymentDirectionTest.php:276-279`). AR openings still mint `Invoice` / `HIST-INV`
   (`ArApOpeningLedgerTest.php:175-186`).
2. **W4-4 ledger.** One historical entry per open item, control leg partner-tagged, counterpart
   resolved from `SystemAccountPurpose::OpeningBalanceEquity`, amount = `balance_due`
   (`ArApOpeningLedgerService.php:159-238`); partially-settled probe (200 total / 150 open) pinned
   at `ArApOpeningLedgerTest.php:254-266`. Sub-ledger refreshed explicitly once per distinct
   partner (`ArApOpeningService.php:378-383` → `ArApOpeningLedgerService.php:253-258`), which is
   necessary because the entry never goes through `sealAndPersistEntry()` and so never fires
   `JournalEntryPosted`. Partner pages read 150 / 500 and the supplier goes to 0.000 after payment.
   Aged AR excludes AP openings (`AgedReceivablesService.php:155` filters `type = Invoice`); aged AP
   lists historical suppliers (`AgedPayablesService.php:205-226`). **But see C-1.**
3. **Judgement call** — see the ruling section.
4. **Status machine / fiscal chain.** The opening document is a birth-state `Posted` write inside
   `Document::create()` (`ArApOpeningService.php:336-380`), which the N-6 docblock exempts, and the
   PHPStan write-path rule is green. It is `FiscalCategory::NonFiscal` with `fiscal_status = Draft`
   and — since W4-3 — `SupplierInvoice` / `SupplierCreditNote`, which are **not** in
   `DocumentPostingService::FISCAL_DOCUMENT_TYPES` (`:50-53`, only `Invoice` + `CreditNote`), so the
   type change actually removes AP openings from the fiscal type set entirely. The GL entry is
   created with no `fiscal_hash` / `chain_sequence` (`ArApOpeningLedgerService.php:202-215`) and
   `GeneralLedgerHashService::verifyChain()` walks only `whereNotNull('fiscal_hash')`
   (`:103-107`); `JournalEntry` has no observer/`booted` hook. **Neither chain is entered — proven.**
   `DocumentStatusService::wasNeverSealed():191-203` keys the exemption on `is_historical`, not on
   type, so it survives the retype (demonstrated by the opening reaching `paid` through the real
   endpoint).
5. **Rule 19 / account codes.** No float anywhere in the new code; `CurrencyScale::bcformatStrict`
   + `bccomp`/`bcadd` at an explicit scale; scale via `getScaleSafe($currency, 3)`
   (`ArApOpeningLedgerService.php:299-305`) — no bare no-arg `getScale()` added. `journal_lines`
   is genuinely `decimal(15,3)` (`2026_03_11_200000_widen_monetary_columns_to_scale_3.php:27-30`,
   confirmed on the live PG test DB: `numeric_scale = 3`), so the class docblock is accurate and
   the known 15,2-vs-3 drift does not bite here. Every account is resolved from a seeded purpose
   (`Account::findByPurposeOrFail`), including the guard, which is keyed on
   `SystemAccountPurpose` values and never on `411`/`401`
   (`AccountingOpeningService.php:44-56`). A missing purpose surfaces as a typed 422 `POST_FAILED`
   (`Account.php:252-266` throws `RuntimeException`; `OpeningBalanceBatchController.php:621-628`
   catches it) — the handback's claim holds.
6. **Merge collision** — see I-5 / M-1.

---

## FINDINGS

### [CRITICAL] C-1 — the new aged-AP arm counts a credit-note opening as a POSITIVE payable; aged AR ignores one entirely. Both reports now contradict the GL this lane just started posting.

`apps/api/app/Modules/Accounting/Application/Services/Reports/AgedPayablesService.php:209-210`
(`whereIn('type', [SupplierInvoice, SupplierCreditNote])`) and `:405-420` (`openBalance()` — no sign
handling anywhere in the bucketing).

**Proven by probe** (reviewer probe run in a scratch clone, one AP batch: invoice 500.000 +
credit_note 120.000, same supplier, TN/TND):

```
PROBE GL401 net payable       = 380.000  (Cr 500.000 / Dr 120.000)
PROBE partner payable_balance = 380.000
PROBE aged AP grand_total     = 620.0000     <-- 240.000 too high
```

and the mirror on AR (one AR batch: invoice 150.000 + credit_note 40.000):

```
PROBE GL411 net               = 110.000  (Dr 150.000 / Cr 40.000)
PROBE receivable_balance      = 110.000
PROBE aged AR grand_total     = 150.0000     <-- 40.000 too high
```

**Why it matters.** This is the same defect class the campaign logged against this very lane (aged
AR reading 650.000 on a tenant owed 150.000), reproduced one layer down. It is not hypothetical: a
negative `opening_balance_supplier` / `opening_balance_customer` in the standard Parties CSV becomes
`document_type = 'credit_note'` by sign (`Import/Services/PartiesRowMapper.php:111`), which W4-3 now
maps to `SupplierCreditNote` / `CreditNote`. So a first-tenant import that includes one prepaid
supplier ships an aged AP that overstates the debt by twice the credit note, while the partner page
and `GL 401` are correct. The operator pays from the aged report. Worse on AP: an opening
`SupplierCreditNote` can never be cleared through the payment path either —
`DocumentAllocationClassifier::classifyOrNull()` refuses every type outside `Invoice` /
`SalesOrder` / `PurchaseOrder` (`:88-110`, "Everything else is refused. NO default"), so it sits in
aged AP forever.

The lane's own test only asserts the invoice-only case
(`ArApOpeningLedgerTest.php:268-278`, aged AP `500.0000`), so nothing catches this.

**Fix.** In `historicalSupplierOpenItems()`, either (a) exclude `SupplierCreditNote` from the arm
and net opening credit notes against the same partner's opening invoices before bucketing, or (b)
carry a signed amount through `openBalance()`/`calculateAging()` for credit-note types. (a) is the
smaller change and matches what aged AR already does by omission — but then aged AR must net its
`CreditNote` openings too, or it keeps the 150-vs-110 overstatement. Add a two-row (invoice +
credit note) case to `ArApOpeningLedgerTest::test_aged_reports_list_the_opening_items_on_the_correct_side`
asserting aged total == GL control net.

### [IMPORTANT] I-1 — the direction guard is on ONE of four allocation entry points, so the "containment" claimed in handback §10 does not hold.

`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:508-522` is the only
call site of `documentSideMatchesPartner()` (grep-verified: the method at `:1426`, its call at
`:508`, one comment at `:513`).

The legacy mis-typed row the guard exists to contain — a `HIST-INV` **customer** invoice owned by a
**supplier**, which the campaign tenant holds as `HIST-INV-2026-00002` — is `type = Invoice`,
`status = Posted`, so `DocumentAllocationClassifier` returns `ReceivableClearing` and the AR arm
(`Dr bank / Cr 411`, movement IN) is still reachable through:
- `PaymentController::storeMultiple()` (`:1438`) — its only type guard is
  `rejectSupplierInvoiceInMultiline()` (`:1395-1407`), a pure `=== SupplierInvoice` test;
- `MultiPaymentController::createSplitPayment()` (`:172-175`) — same pure type test;
- `MultiPaymentController::applyDeposit()` (`:376`) and `SmartPaymentController::applyAllocation()`
  → `PaymentAllocationService` (`:204`, `:679`) — same shape.

Handback §10 says "the new `PAYMENT_DIRECTION_MISMATCH` guard means that row can no longer be paid
again in the wrong direction, which is the containment." That is true only for
`POST /payments` single-payment.

**Fix.** Hoist `documentSideMatchesPartner()` into a shared guard next to
`Treasury\Application\Services\DocumentAllocationStateGuard` (same rationale as W-7 F-6: one guard
on the shared per-allocation path, not five callers), call it from all four sites, and correct
§10. If that is judged out of P0 scope, the §10 sentence must be narrowed and the residual raised
as its own row — the campaign tenant is being re-provisioned, but any other tenant that ran an AP
opening before this fix carries the same row.

### [IMPORTANT] I-2 — the control-account guard is validate-time only; `postBatch` re-posts already-Valid rows without re-checking.

`apps/api/app/Modules/Accounting/Application/Services/AccountingOpeningService.php:204-224` (guard,
inside `validateRow()`) vs `:299-360` (`postBatch()` reads
`rows()->where('status', OpeningImportRowStatus::Valid)` and posts them, with no re-validation).

Any GL opening batch whose rows were marked `Valid` **before** this ships posts a 411/401 line
after it ships, and double-counts the control account against the AR/AP openings. The window is
small but it is exactly the shape the campaign tenant was in.

**Fix.** In `postBatch()`'s row loop, re-assert the account's `system_purpose` against
`CONTROL_ACCOUNT_BATCHES` and throw the same message (it is a `RuntimeException` → typed 422 at
`OpeningBalanceBatchController.php:621`). Six lines, no migration.

### [IMPORTANT] I-3 — the guard is one-directional: nothing stops the AR/AP batch from double-counting against an ALREADY-LOCKED GL opening that stated 411/401.

`ArApOpeningService::validateRow()` / `postBatch()` (`:134-268`, `:286-403`) contain no
corresponding check.

The guard makes the AR/AP batch the single writer of the control accounts **going forward**, but
posting order decides everything: a company that already locked a GL opening containing `411 150` /
`401 500` (the campaign's §A.6, and what the shipped CSV template taught) and then imports parties
balances gets both. A locked batch is not deletable, so there is no recovery path in product.

**Fix.** Before posting an AR/AP batch, look for a posted `source_type = 'opening_balance'` journal
entry carrying a line on the `CustomerReceivable` / `SupplierPayable` account for this company and
refuse (or, at minimum, return the count in the post preview so the operator sees it). The
`getPostPreview()` note (`ArApOpeningService.php:470`) currently tells the operator not to restate
the control accounts, but only *after* they may already have done so.

### [IMPORTANT] I-4 — the guard is purpose-keyed, so the same restatement lands one account down.

`AccountingOpeningService.php:44-56` keys on `system_purpose`. In the TN chart only `401` and `411`
carry the purposes; `4011 Fournisseurs - Achats de biens`, `4017 Fournisseurs - Retenues de
garantie` and `413`/`416` carry none (`database/seeders/TunisiaChartOfAccountsSeeder.php:172-186`),
and FR is the same shape with `4011` / `4111`
(`database/seeders/FranceChartOfAccountsSeeder.php:167-189`). An operator whose old TB is stated at
the child level passes the guard and restates the payable in a sibling account the sub-ledger
(`PartnerBalanceService::getPartnerBalance()` filters `accounts.system_purpose = <purpose>`,
`:41-47`) can never see.

**Fix.** Extend the refusal to any account whose ancestor chain carries a control purpose
(`parent_code` is already on the chart), or accept it and record it as a named residual with the
reconciliation surface (I-8) as the compensating control. Do not leave it silent.

### [IMPORTANT] I-5 — manifest arithmetic is stale against current dev; the merge value is Document **84** / `gated_ceiling` **1170**.

`apps/api/tests/feature-lane-manifest.json` — lane HEAD carries `groups.Document.classes = 83`,
`gated_ceiling = 1168` (computed against the older dev the lane merged, `2f239eaf4`). Current dev
`c97a735ad` already carries `groups.Document.classes = 83` / `gated_ceiling = 1169`, raised by a
**different** class: W2-6's `PurchaseOrderUnpricedLineConfirmTest`. `git ls-tree` diff of
`tests/Feature/Document` between the two refs returns exactly one file each way, so the union is 84
classes.

**Fix at merge.** `classes = 84`, `gated_ceiling = 1170`, and the note must keep both raises. Re-run
`php tools/feature-lane-manifest-check.php` on the merged tree (it must stay EXIT=0).

### [MINOR] M-1 — expected merge collisions: name both files.

Code: `apps/api/app/Modules/Accounting/Application/Services/AccountingOpeningService.php` — the
in-flight W4-2 lane (`fix/campaign-w42-opening-cash-float`, worktree `w42-cash-float`) touches it at
`@@ -16`, `@@ -43`, `@@ -115`, `@@ -168`, `@@ -224`, `@@ -231`, `@@ -328`, `@@ -416`; this lane at
`@@ -40` (the const, adjacent to W4-2's constructor insertion at `-43`) and `@@ -186` (the
`validateRow()` `elseif`). The `-40`/`-43` pair will conflict textually. Semantically, W4-2 adds
`validateRepositoryColumn()` which runs **after** the account branch and assumes
`mappedData['account_id']` — the merger must re-read the whole account branch of `validateRow()`
after resolving, not just accept both hunks.
Second collision, unavoidable: `apps/api/tests/feature-lane-manifest.json` (see I-5).

### [MINOR] M-2 — the "no partner" guard cannot fire on the case its own docblock names.

`apps/api/app/Modules/Accounting/Application/Services/ArApOpeningLedgerService.php:172-181`: the
docblock says "the COLUMN is nullable … Fail the batch closed rather than write an untraceable
line", but the test is `if ($partnerId === '')` — a `null` `partner_id` passes straight through and
writes `partner_id => null` on the control leg at `:220`. Unreachable today (`validateRow()` requires
`partner_code` and `postBatch()` skips rows without `partner_id`), so this is a
documentation-vs-code mismatch, not a live bug. Make it `if ($partnerId === null || $partnerId === '')`
(PHPStan will want the model docblock widened) or soften the comment.

### [MINOR] M-3 — the new `Partner` load runs on every payment and downgrades a soft-deleted partner from 201 to an untyped 404.

`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:467-475`. It is placed
before the allocations loop, so a pure advance payment (no allocations) pays for a query it never
uses. More substantively, `partner_id` is validated with `ScopedExists::tenantAndCompany`
(`:370-374`), and `Rule::exists` does **not** apply the `SoftDeletes` scope
(`app/Shared/Presentation/Validation/ScopedExists.php:28-37`) while `Partner` does
(`Partner.php:88`) — so a soft-deleted partner now yields `findOrFail` → 404 where it previously
reached 201. Arguably a hardening, but it is an untyped 404 in a controller whose every other
refusal is a coded 422. Move the load inside `if ($allocations !== [])` and use `->first()` with a
typed 422, or leave it and record the behaviour change.

### [MINOR] M-4 — the GL template CSV still teaches account codes that exist in no seeded chart.

`apps/web/src/features/opening-balances/components/FileUpload.tsx:52-67`. The lane correctly removed
the `401000 Opening Payables` line (it taught exactly what the new guard refuses), but
`101000`, `213000` and `301000` appear in **none** of the TN / FR / generic chart seeders (grepped
all three). A downloaded ACCOUNTING template therefore still fails validation on every row —
"Account '101000' not found or inactive" — and because the import is all-or-nothing
(`Import/Services/AccountingBalancesPhase.php:229-241`) the whole file is rejected. Pre-existing, but
the lane owns this line now. Emit the sample from the company's actual chart, or use codes that
exist.

### [MINOR] M-5 — the two new purpose-resolution call sites are not registered in `ProvisioningRequiredPurposesV1`.

`ArApOpeningLedgerService.php:133-134` (`findByPurposeOrFail` for the DYNAMIC control purpose and for
`OpeningBalanceEquity`) are absent from
`app/Modules/CountryDefaults/Domain/Services/ProvisioningRequiredPurposesV1.php:32-70`. No new
provisioning requirement results — `CustomerReceivable`, `SupplierPayable` and
`OpeningBalanceEquity` are all already `REQUIRED` there via other call sites — and the registration
ratchet is **inherited red on dev** (verified: identical 2 failures with this lane's production
patch reverted). Register the citations when that ratchet is repaired, so the AR/AP opening path is
named as a consumer.

---

## Judgement call — should the GL opening batch refuse partner control accounts?

**Ruling: YES, keep the refusal — but it is not yet the guard it is presented as, and it needs one
more sentence in its message before it meets a real tenant.**

*Why it is right.* Control accounts are sub-ledger territory; that is the whole content of W4-4. A
411 balance with no partner dimension is not a receivable in any useful sense — it cannot be
collected, it never ages, it never appears on a partner page, and `PartnerBalanceService`
(`:41-47`) cannot see it because it filters on the purpose-tagged account and a partner id. The
refusal is also what makes the smoke sheet's cutover reconciliation satisfiable by construction, and
it fires at validation, before anything locks, which is the right place.

*The TB-only tenant.* A tenant that arrives with a trial balance and no per-partner detail is a real
shape, and this refusal does block their literal file. It does not block them, though: the standard
ERP answer is a `DIVERS CLIENTS` / `DIVERS FOURNISSEURS` catch-all partner with one open item per
side, which is strictly better than a partnerless 411 (it ages, it is collectible, and the
sub-ledger ties). The problem is that **the error message does not say this**
(`AccountingOpeningService.php:216-221` says only "Remove this line and import the open items
instead"), and openings lock forever with no in-product correction path — so an operator who hits
this at cutover has a support ticket, not a workaround. **Required change:** name the catch-all
partner escape in the message.

*What I would NOT do.* Do not add an `allow_control_accounts` opt-in, and do not downgrade to a
warning. A warning leaves a knowingly-wrong trial balance behind a lock, and an opt-in re-opens the
double count while `PartnerBalanceService::reconcileSubledger()` (`:197-213`) — the one thing that
could prove the cutover ties — still has no caller and no report surface. Revisit the opt-in only
after that reconciliation is surfaced.

*Conditions on keeping it.* I-2 (validate-time only), I-3 (one-directional / non-retroactive) and
I-4 (child accounts escape) all make the guard weaker than the handback's §5(a) presentation. I-2
and I-3 must land with this lane; I-4 may be a named residual.

---

## Residuals confirmed (pre-existing, out of lane, worth register rows)

1. **Aged AP is blind to any supplier invoice raised without a PO** — `AgedPayablesService::getOutstandingInvoices()`
   (`:151-179`) reads posted `PurchaseOrder` + the auto-received-PO accrual + (new) historical
   openings only. `CreateSupplierInvoiceService` treats `source_document_ids` as optional
   (`:60-64`), so a PO-less supplier invoice is creatable and **invisible as a payable**.
   **Severity: MAJOR (reporting), pre-existing, confirmed.** The lane's narrowing to
   `is_historical = true` is correct — widening the arm to all `SupplierInvoice` would double-count
   against the PO arms — so this needs its own lane, not a wider `whereIn`.
2. `PaymentType::SupplierPayment` still has zero writers in `app/` while three readers depend on it.
3. `JournalLineData` carries no `partner_id`, so the dimension this lane depends on is invisible to
   any consumer reading a JE through that DTO.
4. `PartnerBalanceService::reconcileSubledger()` has no caller and no report surface — the exact
   control-vs-subledger proof this lane makes meaningful.
5. `AccountingOpeningService::postBatch()` still writes `partner_id => null` explicitly; correct only
   while the guard holds (see I-2/I-3/I-4).
6. `OpeningBalancePosted` has no listener.
7. `PartiesImportBalancesTest` was PG-fragile (JSONB key order); fixed in place. The `Import` group
   has no pgsql CI lane, so the same pattern likely exists elsewhere.

## Campaign-tenant residual

`tenant01a03028-…` holds one `HIST-INV-2026-00002` (500.000, `paid`) with the inverted
`JE-2026-000007 (customer_payment) Dr 512 / Cr 411` behind it. **Greenfield ⇒ re-provision; no
backfill command is needed and none should be written** — repairing it in place means reversing a
posted payment entry and re-typing a posted document, which is a `CorrectingEntryService` /
R2-F4 problem with an owner ruling attached. Note that until I-1 is fixed, the containment on that
row is partial (single-payment path only).

## What must change before merge

C-1 (net credit-note openings so the aged reports agree with the control accounts, + a test),
I-2 and I-3 (make the control-account guard post-time and bidirectional), I-1 (hoist the direction
guard to the shared allocation path, or narrow the §10 containment claim and raise it as a row),
I-5 (Document 84 / gated_ceiling 1170), and one sentence in the guard's message naming the
catch-all-partner escape. I-4 and the M-series may be recorded as named residuals.
