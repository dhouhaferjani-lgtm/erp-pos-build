# Gate record — Q1 credit-note stamp/GL alignment (`fix/cn-stamp-gl-alignment`)

Reviewer: treasury/GL adversarial gate. Date 2026-08-07.
Worktree `/Users/houssamr/Projects/syneriva/apps/erp.fix-q1-cn-stamp-gl`,
commits `f698d1975` (live path) + `eb7730a3b` (dead-path parity), base `8cc674c6d`.
Diff: 7 files, 589 insertions / 39 deletions. **Zero migrations** (`git diff --stat`) — no
data rewrite of sealed entries. Confirmed.

## VERDICT: REJECT (do not merge as-is)

The accounting SHAPE the lane implements is correct and the engineering is careful. But the
whole new behaviour is keyed on `documents.stamp_duty_amount`, and **no sales-side
credit-note code path ever writes that column** — so the fix is INERT on every real credit
note. N1 is not closed. See C-1.

---

## Verification of the lane's own claims

| Claim | Verdict | Evidence |
|---|---|---|
| `GeneralLedgerService::createFromCreditNote()` is dead code | **TRUE** | Only definition `GeneralLedgerService.php:214` + 4 test call sites. Zero `app/` callers; `createFromInvoice` likewise. No dynamic dispatch (`grep 'createFrom''`, `call_user_func` → nothing in `app/Modules/Accounting/`). |
| `AccountingService::createCreditNoteGLEntries` via `InvoicePostedListener` is the live path | **TRUE** | `InvoicePostedListener.php:29-30`, registered `app/Providers/EventServiceProvider.php:96`; event emitted `DocumentPostingService.php:471`. |
| No third CN GL writer | **TRUE** | Only other `InvoicePosted` consumer is `Compliance/Listeners/DomainEventSubscriber.php:113` (audit, no GL). `DOCUMENT_SOURCE_TYPE` referenced only in `AccountingService.php`. |
| Invoices byte-identical | **TRUE** | `AccountingService.php:200` — `$stampDutyAmount` forced `'0'` for non-CN ⇒ `$arFacingTotal === $documentTotal` (`:203-206`); ladder restructure at `:211-236` is logically identical to the old early-return chain (same order: `<0` refuse → 4375 unconditional → rounding acct → tolerance). `InvoiceGLIntegrationTest` + `InvoiceAndCreditNoteGLIntegrationTest` + `DocumentGlPreflightTest` + `InvoicePostedListenerTest` 35/35 green. |
| TN dust-absorption ladder preserved | **TRUE** | `:218-235`: `SalesStampDutyPayable` still absorbs ANY positive residual with no tolerance check; rounding account still tolerance-bounded. |
| Ex-stamp AR is bcmath, string-exact | **TRUE** | `AccountingService.php:625-630` (`bccomp`/`bcsub` at `$scale` from `documentScale()` → `getScaleSafe($document->currency, 3)`, `:66-69`). No float anywhere in the new hunks. |
| Fail-closed → 422, document stays Confirmed/unsealed | **TRUE** | `assertDocumentGlIsPostable` `:83-98` → `UnpostableDocumentGlException extends DomainException` (422); called at `DocumentPostingService.php:105` **before** `postWithFiscalChain()` `:107`, inside the transaction; `CreditNoteController.php:388-397` maps `DomainException` → 422. Refusal branch exercised and passing (`CreditNoteGLIntegrationTest:777-836`). |
| Posting-time explicit throw (defence-in-depth reasoning) | **TRUE and correct** | `AccountingService.php:700-710`. The reasoning is sound: `assertLegsBalance` (`:314-357`) only checks Σdebits==Σcredits, and dropping BOTH legs of a self-balancing pair is invisible to it. Test bypasses the preflight and proves the throw + zero surviving entry (`:817-835`). |
| L2 cancel-reversal mirrors the stamp pair generically | **TRUE** | `AccountingService.php:860-878` mirrors every sealed leg by `account_id`/`partner_id` with debit/credit swapped — shape-agnostic. Asserted string-exact in `CreditNoteGLIntegrationTest:752-775`. |
| Stamp legs carry no `partner_id` ⇒ invisible to the subledger reconciler | **TRUE, both ends** | Leg construction `AccountingService.php:712-726` omits `partner_id`; reconciler predicates filter on `accounts.system_purpose` + `journal_lines.partner_id` (`PartnerBalanceService.php:44-50`, `:120-127`, `:209-213`). |
| VAT declaration untouched | **TRUE** | `EloquentVatDataRepository.php:29-30,68` aggregates `document_tax_details` ∪ `pos_receipt_vat_details` — no GL join. Stamp never reached `VatCollected` before or after (`groupTaxByRate` is line-tax only). |
| Stampless CN byte-identical | **TRUE** | Pre-existing string-exact AR pins `CreditNoteGLIntegrationTest:311-312` (`'1190.000'`) and `:499` (`'595.000'`) unchanged and green. (Not every leg is string-exact in those older tests — some use `(float)` sums — but the AR leg, the one this lane moves, is pinned.) |

Tests run (by path, live): `CreditNoteGLIntegrationTest` 12/12 · `DocumentGLIntegrationTest` +
`GLIntegrationTest` 42/42 · `DocumentCancellationGlReversalTest` + `GLHashIntegrationTest` +
`CreditNoteMoneyLaneTest` 28/28 (1 skip) · `InvoiceGLIntegrationTest` +
`InvoiceAndCreditNoteGLIntegrationTest` + `DocumentGlPreflightTest` +
`InvoicePostedListenerTest` 35/35 · `CreditNoteAllocationTest` + `CreditNoteIntegrationTest` +
`PartnerBalanceServiceTest` + `CompleteSalesCycleWithReturnTest` 46/46. PHPStan level 8 on all
5 changed app files: **OK, no errors**.

---

## Findings

### [CRITICAL] C-1 — the fix is INERT on every real credit note: nothing populates `documents.stamp_duty_amount` on the sales side

`AccountingService.php:200` gates the entire Q1 behaviour on
`$document->stamp_duty_amount`. That column has DB default `'0.000'`
(`database/migrations/tenant/2025_12_30_107000_add_stamp_duty_to_documents.php:16`) and is
written by exactly four places in the codebase:

- `Document/Domain/Services/DocumentTotalsCalculator.php:53` — called ONLY by
  `DraftPersistenceService.php:199` (draft editor) and the Workshop
  `DocumentGenerationAdapter.php:59,102`;
- `Procurement/Application/CreateSupplierInvoiceService.php:135,201` — purchase side;
- `database/seeders/DemoPharmacySeeder.php:1721` — invoices.

None of them is on the credit-note path. Every live CN creation writes only
`subtotal`/`tax_amount`/`total`:

- `CreditNoteService.php:842-860` (amount-based), `:976-994` (line-based shell),
  `:1157-1175` (standalone shell);
- `CreditNoteService::applyConfirmEquivalentTotals()` `:96-107` — `update(['subtotal', 'tax_amount', 'total'])`, no `stamp_duty_amount`;
- `CreditNoteController::confirm()` `:293-297` — same three columns.

Exhaustive sweep: `grep -rn "'stamp_duty_amount' =>" app/` → the 4 writers above;
`grep -rn "stamp_duty_amount\s*=" app/ | grep -v "=>"` → **nothing** (no property-assignment
form); no `stamp_duty_amount` in any `Document/Presentation/Requests/*`; the only `Document`
observer is `DocumentMediaCascadeObserver` (`DocumentServiceProvider.php:68`). Corroborating
in-repo signal: `tests/Feature/Taxation/TaxRecoverabilityTest.php:215,274` carry
**commented-out** `assertEquals('1.000', $invoice->stamp_duty_amount)` assertions, and
`tests/Feature/Procurement/SupplierInvoiceApiTest.php:2057-2061` explicitly records that the
purchase side needed a "BLOCKER 1 fix" to start writing this very column.

**Failure scenario (the launch tenant's exact case).** TN parapharmacy issues a credit note:
`CreditNoteMoneyLaneTest:487` pins the live shape — CN `total = '120.600'` including its own
0.600 timbre, `tax_amount = VAT + 0.600`, `stamp_duty_amount = '0.000'`. At posting,
`residualPlan()` computes `$hasStampDuty = false` (`:201`), `$arFacingTotal = $documentTotal`
(`:203-206`), residual `= 0.600` → absorbed by 4375 (`:219-221`) → `createCreditNoteGLEntries`
credits AR the **full stamp-inclusive 120.600** (`:625-630` takes the `$documentTotal`
branch) and debits 4375 0.600 (`:673-687`). **That is the pre-ruling shape, byte for byte.**
No 6354 leg, no ex-stamp 411. The expert's ruling is not implemented in production.

The two new tests pass only because their fixtures hand-write `'stamp_duty_amount' => '0.600'`
(`CreditNoteGLIntegrationTest:690`, `:800`) / `'1.000'` (`DocumentGLIntegrationTest:513`,
`:594`) — a document shape the application never produces. This is the "test asserts a
fabricated shape" failure mode.

**Fix before merge:** populate `stamp_duty_amount` (and, for coherence, `line_tax_amount`)
from `$taxResult->documentTaxTotal` / `->lineItemsTaxTotal` in
`CreditNoteService::applyConfirmEquivalentTotals()` and `CreditNoteController::confirm()` —
i.e. do on the sales/CN side what `CreateSupplierInvoiceService.php:201` already does on the
purchase side (ideally by routing both through `DocumentTotalsCalculator`). Then add ONE
end-to-end test that creates + confirms + posts a duty-bearing CN **through the service/API**
(the `CreditNoteMoneyLaneTest` TN fixture already exists) and asserts the 411/6354/4375 legs.
Until that test exists, no fixture-authored test can prove this lane works.

### [CRITICAL] C-2 — once C-1 is fixed, GL 411 and the document ledger diverge by the stamp on every allocated CN

`allocateCreditNote()` allocates the **stamp-INCLUSIVE** total:
`CreditNoteService.php:1279-1284` — `$clampedAmount = min($creditNote->total, $currentBalance)`
with no stamp subtraction. The PG trigger
(`2026_05_27_100001_fix_credit_note_allocation_balance_trigger.php:41-56`) sets
`invoice.balance_due = total − Σpayment_allocations − Σcredit_note_allocations.amount`, and
`AgedReceivablesService::getOutstandingInvoices()` (`:151-158`) reads exactly that column.

Scenario (TN, invoice 100.000 + 19.000 VAT + 0.600 timbre = 119.600, fully credited by a CN
of 119.600 whose own timbre is 0.600): with C-1 fixed, GL credits 411 by **119.000**, while
the allocation reduces `documents.balance_due` by **119.600 → 0.000**. Aged AR and the
customer statement say the customer owes nothing; the trial balance's 411 carries a permanent
0.600 debit per credit note that nobody will ever bill or collect. That is the *same* class of
ledger desync N1 was raised to kill, relocated — and the source ticket explicitly requires
both sides: `docs/superpowers/tickets/2026-08-03-credit-note-regate-carryovers.md:19` — "Then
align BOTH ledgers to the ruling." Note `CheckSubledgerReconciliationCommand` cannot detect it:
`PartnerBalanceService::reconcileSubledger()` (`:197-224`) compares GL-to-GL.

**Fix before merge (with C-1, same lane):** allocate `total − stamp_duty_amount` in
`allocateCreditNote()` (`remainingCreditHeadroom()` `:138-163` is *already* duty-exclusive, so
this makes the two consistent), and update `CreditNoteAllocationTest` /
`CreditNoteMoneyLaneTest:487-506` expectations. If the owner/accountant instead wants
`balance_due` to keep dropping stamp-inclusive, that must be an explicit recorded ruling —
because it means the AR control account is knowingly permanently unreconcilable.

### [IMPORTANT] I-1 — `PurchaseStampDuty` (6354) has no backfill for existing charts; a stamp-bearing CN on a pre-2026-06-25 tenant becomes unpostable

`PurchaseStampDuty` was added to the three chart seeders on `72517d986` (2026-06-25) with
**no accompanying migration** (`git show --stat 72517d986` — 3 seeders + 1 test, zero
migrations), it is **not** in `SystemAccountPurpose::requiredPurposes()`
(`SystemAccountPurpose.php:160-175`, so `validateCompanyAccounts()` never flags it), and no
backfill exists (`grep -rln '6354\|6350' database/migrations/` → empty). Contrast
`SalesStampDutyPayable`, which *did* get one
(`2026_06_30_120000_backfill_sales_stamp_duty_account.php`).

Once C-1 is fixed, any company whose chart was seeded before 2026-06-25 hits
`residualPlan():246-253` → `NoCreditNoteStampAccount` → hard 422 on every credit note that
carries a timbre. Today that same gap only bites the purchase path
(`GeneralLedgerService.php:1976` `findByPurposeOrFail`), which a tenant may never have
exercised. **Fix:** add a 6354/6350 backfill migration (mirror the 4375 one) and/or add
`PurchaseStampDuty` to `requiredPurposes()`; plus a pre-deploy check on the launch tenant.

### [IMPORTANT] I-2 — dead-path parity commit adds new money arithmetic on a no-arg `getScale()` (rule 19)

`GeneralLedgerService::createFromCreditNote()` `:253-258,293` performs new `bccomp`/`bcsub`
at `$this->scale()`, which is `getScale()` with **no currency argument**
(`GeneralLedgerService.php:57-60`). Per CLAUDE.md rule 19 / rule 20 that throws outside HTTP
request context. It matches the surrounding (pre-existing) idiom and the method is dead, so
the practical risk is nil today — but it is new code written after the "pass the currency"
doctrine, and this method is the one someone would revive. **Fix:** pass
`$creditNote->currency` via `getScaleSafe(...)`, or leave with an explicit comment that the
method is dead and slated for deletion.

### [minor] m-1 — the residual leg's description is now wrong on the stamp-bearing branch

`AccountingService.php:673-687`: when the plan resolves 4375 as the absorbing account the leg
is labelled "Stamp duty (timbre) reversal". After C-1 is fixed, that leg carries only rounding
dust (the real stamp is the `:712-726` pair), so a TN credit note produces a 0.001 line on
4375 labelled "stamp duty reversal" *and* a 0.600 line on 4375 labelled "stamp duty payable".
Relabel the residual leg (e.g. "tax rounding difference") when a stamp pair is also written.

### [minor] m-2 — reuse of `SystemAccountPurpose::PurchaseStampDuty` for the avoir's charge

**Verdict: acceptable-with-docblock. Not a merge blocker.** The accounting nature is identical
(a timbre borne by the company, PCG class 63 "Impôts, taxes et versements assimilés") and on
the two charts where the branch is reachable the account NAME is already neutral:
`TunisiaChartOfAccountsSeeder.php:247` and `FranceChartOfAccountsSeeder.php:254` both read
"Droits d'enregistrement et de timbre". Only `GenericChartOfAccountsSeeder.php:180` carries the
purchase-flavoured English label "Purchase Stamp Duty" — and the Generic chart seeds **no**
`SalesStampDutyPayable`, so the CN branch refuses there and the mislabel is unreachable. The
docblock amendment (`SystemAccountPurpose.php:83-90`) is honest and points at the caller.
Follow-up ticket (not blocking): rename the Generic 6350 account label to a
purchase-neutral "Stamp Duty / Droits de timbre", and consider renaming the enum CASE (not
its backing string, which is persisted) to `StampDutyExpense`.

### [minor] m-3 — legacy CNs whose timbre is baked into `total` but not into the column keep the old treatment silently

By construction of C-1's gate, any credit note with `stamp_duty_amount = 0` but a
stamp-inclusive `total` (exactly the pre-existing fixture
`CreditNoteGLIntegrationTest:619-660`, and — per C-1 — every production CN today) posts the
old shape with no signal. Once C-1 is fixed this becomes the disposition question the ticket
already defers to the accountant list; it should be written down as such, and any backfill of
the column on already-posted CNs must NOT retro-change their sealed GL.

---

# Fix-round re-verify (2026-08-07, commits `f55a7e2c4` · `da4a442d8` · `823d119cf` · `52cf6b84d` · `b778b20ce`)

Scope: ONLY the round-1 findings. Diff `eb7730a3b..HEAD` = 9 files, +962/−12, of which
**one tenant migration** — the lane is now MIGRATION-BEARING and must ride its own push (D-2).

## VERDICT: CLEAR TO MERGE

All five findings resolved and re-verified from the code and from live runs. Two carry-over
notes below, neither blocking.

### C-1 — RESOLVED (verified from the real flow, not a fixture)
`CreditNoteService::applyConfirmEquivalentTotals()` `:104-116` (stamp at `:113`) and
`CreditNoteController::confirm()` `:293-306` now persist `stamp_duty_amount`
(`$taxResult->documentTaxTotal`) and `line_tax_amount` (`->lineItemsTaxTotal`).
`applyConfirmEquivalentTotals()` is the single funnel for all three create paths, and
`confirm()` recomputes independently — both patched, so the draft→confirm→post lifecycle is
covered end to end.

Decisive E2E rerun **by name**, green:
`CreditNoteMoneyLaneTest::test_amount_based_credit_note_with_tn_stamp_persists_column_and_posts_ex_stamp_gl_and_allocation`
(1 test, 16 assertions). It goes through the real `POST /api/v1/credit-notes` → `/confirm` →
`/post`, writes **no** `stamp_duty_amount` fixture, and asserts: column `'0.600'` at draft AND
after confirm; 411 credit `'50.000'` (ex-stamp); 6354 debit `'0.600'`; 4375 credit `'0.600'`;
entry balances; `CreditNoteAllocation.amount === $arLine->credit`; invoice `balance_due`
118.810 → 68.810. Full file 12/12 green.

**The round-1 evidence pin (`CreditNoteMoneyLaneTest:487`, `120.600`/`0.000`) — how it was
updated:** the `120.600` total assertion is UNCHANGED (correct: `total` always folded the
stamp in); a NEW assertion was inserted at `:504-509` flipping the column expectation to
`'0.600'`, i.e. the exact pin whose old implicit value proved the round-1 defect. The
fixture's allocation assertions (`120.000` allocated / `0.600` unallocated) are unchanged and
still green — correctly acknowledged in the commit message as a degenerate case where old and
new clamps coincide (`min(120.600, 120.000) == min(120.000, 120.000)`), which is why the new
partial-credit E2E (clamp never engages) is the one that actually discriminates.

**m-3 draft case:** covered — a pre-fix DRAFT confirmed post-fix gets the column from
`confirm()`'s own recompute (asserted in the E2E). **Residue (see N-1 below):** a CN already in
`Confirmed` status at deploy time is short-circuited by the idempotency guard
`CreditNoteController.php:269-273` and never recomputes.

### C-2 — RESOLVED
`CreditNoteService::allocateCreditNote()` `:1298-1307` allocates `total − stamp_duty_amount`
(bcmath, at `scaleFor($invoice)`, guarded by `bccomp(...) > 0`), mirroring the already
duty-exclusive `remainingCreditHeadroom()` `:138-163`. GL 411 movement and the
`credit_note_allocations.amount` that drives the `balance_due` trigger are now the same
number — asserted directly against each other in the E2E (`assertSame($arLine->credit,
$allocation->amount)`), so the two can no longer drift by construction.

**Clamp path:** `test_over_credit_allocation_clamps_balance_due_at_zero` green — allocation
floors at `balance_due` 120.000 while GL 411 credits 120.000 (ex-stamp), i.e. the two AGREE on
this path too. Reruns: `CreditNoteAllocationTest` + `AgedReceivablesScalingTest` +
`AgedOutstandingSourceTest` + `AgedAgingBucketsTest` + backfill test = 32/32;
`CreditNoteAllocationExhaustiveProbeTest` + `CreditNoteGLIntegrationTest` = 16/16.
Aged-AR is `documents.balance_due`-driven (`AgedReceivablesService.php:151-158`) and that
column now moves by exactly the GL's 411 amount.

### I-1 — RESOLVED
`database/migrations/tenant/2026_08_07_100000_backfill_purchase_stamp_duty_account.php`.
Guard ladder verified line-by-line against the modeled
`2026_08_05_120000_backfill_sales_rounding_difference_accounts.php:75-140`: per-company loop
over `companies`; chartless skip (`:74-76`); already-purpose-mapped skip (`hasPurpose`,
`:101-103`); claim-an-existing-unpurposed-account-at-the-preferred-code (`:117-141`, avoids
the `accounts_company_code_unique` violation); `nextFreeCode()` fallback (`:190-208`);
`Log::warning` + `return` — **never throws** (`:145-156`); `down()` deliberately a no-op.
Codes correct and match the seeders: `'TN','FR' => '6354'` (cf. `TunisiaChartOfAccountsSeeder.php:247`,
`FranceChartOfAccountsSeeder.php:254`), `default => '6350'` (cf.
`GenericChartOfAccountsSeeder.php:180`), country dispatch mirroring
`ChartOfAccountsService::getSeederForCountry()`. Type forced `'expense'`, parent resolved
`63`→`6000` / `6000`. Idempotent by the two skips. `BackfillPurchaseStampDutyAccountTest`
(8 cases: TN/FR/Generic, chartless, already-has, map-onto-user-account, next-free-code,
idempotency) green.

**`requiredPurposes()` decision — advisory claim VERIFIED, reasoning ACCEPTED.**
`SystemAccountPurpose::requiredPurposes()` (`:160-175`) has exactly one consumer chain:
`ChartOfAccountsService::validateCompanyAccounts()` (`:47-51`) →
`AccountPurposeController::validate()` (`:58-76`), a read-only `GET
/companies/{id}/accounts/purposes/validate` (`routes.php:65-67`). Nothing gates posting,
seeding or company creation on it. So omitting `PurchaseStampDuty` neither weakens nor
strengthens any enforcement — the migration plus the runtime 422
(`GlResidualRefusal::NoCreditNoteStampAccount`) are the real protection. Accepted.
Non-blocking follow-up: adding it would be zero-risk (all three seeders + this backfill now
guarantee it) and would surface the gap in the admin advisory screen.

### I-2 — RESOLVED
`GeneralLedgerService::createFromCreditNote()` `:258` resolves once via
`$this->scaleResolver->getScaleSafe($creditNote->currency, 3)` and reuses `$scale` at all
three arithmetic sites. Verified mechanically: `git diff 8cc674c6d..HEAD -- apps/api/app |
grep 'getScale()'` → **no matches**; no `$this->scale()` call remains anywhere in the method
body (only in the explanatory comment).

### m-1 — RESOLVED
`AccountingService.php:691-692`: `$isStampDuty` now additionally requires
`$plan->stampExpenseAccount === null`, so the "Stamp duty (timbre) reversal" wording survives
only for the legacy stampless-CN shape; any entry that also writes a real stamp pair labels
its residual leg "Tax rounding difference reversal". New test
`test_credit_note_residual_leg_is_labelled_rounding_dust_not_stamp_duty_when_a_stamp_pair_is_also_written`
is a genuine discriminator: TND (scale 3, deliberately not the file's default EUR/scale 2 —
which would truncate the ULP away), `total` bumped to 1.791 so a real 0.001 dust leg coexists
with the 0.600 stamp on the SAME 4375 account; asserts two distinct lines matched by amount
(not line order) and checks the descriptions both ways.

### New regression surface from persisting `line_tax_amount` on CNs — NONE
`documents.line_tax_amount` has **zero readers** in `app/`: every occurrence is a write
(`DocumentTotalsCalculator.php:52`, `CreditNoteService.php:112`,
`CreditNoteController.php:303`, `CreateSupplierInvoiceService.php:134`) plus the model
property/cast/fillable (`Document.php:59,140,190`). It is not exposed by `DocumentData`. The
frontend total strip (`apps/web/src/features/documents/components/DocumentTotals.tsx:101,148`)
reads the LIVE tax-breakdown endpoint (`DocumentController.php:336-337` →
`DocumentTaxBreakdownResource:45-46`), not the persisted columns, so CN displays are
unchanged. The newly-populated `stamp_duty_amount` on CNs is read only by the Q1 paths
(`residualPlan()`, `createCreditNoteGLEntries()`, `createFromCreditNote()`) and
`allocateCreditNote()`; the other readers (`CreateSupplierInvoiceService.php:208`,
`SupplierInvoicePostingService.php:274`) are purchase-side and never see a customer CN.

### Full re-run (by path, live)
`CreditNoteMoneyLaneTest` 12/12 · backfill + `CreditNoteAllocationTest` +
`AgedReceivablesScalingTest` + `AgedOutstandingSourceTest` + `AgedAgingBucketsTest` 32/32 ·
`CreditNoteAllocationExhaustiveProbeTest` + `CreditNoteGLIntegrationTest` 16/16 ·
`DocumentGLIntegrationTest` + `GLIntegrationTest` + `DocumentCancellationGlReversalTest` +
`GLHashIntegrationTest` + `InvoiceGLIntegrationTest` + `DocumentGlPreflightTest` +
`CompleteSalesCycleWithReturnTest` 79/79 (1 skip). PHPStan level 8 on all four changed app
files **and the migration**: OK, no errors.

## Open, non-blocking

- **N-1 (deploy note, m-3 residue).** A credit note already in `Confirmed` status when this
  ships is short-circuited by `CreditNoteController.php:269-273` (idempotent early return) and
  never recomputes, so it posts with `stamp_duty_amount = '0.000'` → the legacy stamp-inclusive
  shape. Self-consistent (GL and `balance_due` still agree with each other), just pre-ruling.
  Bounded to the deploy window; add to the same accountant-disposition list as the already-posted
  CNs. Cheap mitigation if wanted: post-deploy, recompute the column on Confirmed-not-Posted CNs.
- **N-2 (pre-existing, out of Q1 scope).** When the allocation clamp genuinely bites (CN
  ex-stamp amount > remaining `balance_due`), GL 411 still moves by the full ex-stamp amount
  while the allocation is floored at the balance — the ORIGINAL N1 clamp divergence, structurally
  unchanged by this lane (before: full stamp-inclusive vs clamped; now: full ex-stamp vs clamped).
  The expert answered the stamp question, not the over-credit question. Keep on the N1 line.
- **N-3.** Generic chart account label "Purchase Stamp Duty" (`GenericChartOfAccountsSeeder.php:180`)
  and the enum case name — cosmetic follow-up per round-1 m-2, still unreachable (Generic seeds no
  `SalesStampDutyPayable`).
- **D-2.** Lane is migration-bearing (`2026_08_07_100000_backfill_purchase_stamp_duty_account.php`):
  push separately; `tenants:migrate` auto-runs on staging. The migration is self-guarding and
  needs no manual prerequisite.
