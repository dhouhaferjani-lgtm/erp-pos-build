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
