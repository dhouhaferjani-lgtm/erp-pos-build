# L1 fiscal-integrity fix lane — adversarial merge gate

**Branch:** `fix/l1-fiscal-integrity` @ `6f54871ae` (base `7d8e6c861`)
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.fix-l1-fiscal`
**Diff scope:** `git diff 7d8e6c861..6f54871ae` — 11 files, +645/−104
**Reviewer:** fiscal-pos-reviewer (adversarial, code-grounded). Nothing modified; nothing merged.

## VERDICT

**spec ❌ (partially met) + quality CHANGES-REQUESTED — REJECT for merge as-is.**

W-5c **D1 is correctly and faithfully fixed** (matches the ticket's prescribed 1+2+is_active
escalation, tests real, tripwire flip is envelope-accurate). W-6 **D1a is fixed at the wrong
layer and over-shoots the ticket's prescription**, producing two Critical consequences that
were not assessed: (C-1) the throw lands *after* the document is fiscally sealed and there is
no re-drive path, and (C-2) on FR/Generic charts an *ordinary rounding residual* — not a bug —
now hard-fails invoice posting.

If the lane must land before launch, the acceptable split is: **merge `d0fabd896` (W-5c D1) after
the `bail` fix; hold `6f54871ae` (W-6 D1a) for a narrowed guard + an owner ruling.**

---

## Findings

### CRITICAL

**[C-1] `apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:286` and `:439` —
the D1a guard throws AFTER the document is already sealed, and there is no re-drive path.**

- `DocumentPostingService::postWithFiscalChain()` seals the document (status `Posted`,
  `fiscal_hash`, `chain_sequence`) inside `DB::transaction` at
  `apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:82-98`, then defers the
  `InvoicePosted` dispatch to `DB::afterCommit(...)` at `:422-424` — explicitly *"after transaction
  commits to prevent listener failures from rolling back the fiscal chain"* (`:421`).
- Laravel executes afterCommit callbacks **after** the PDO commit and **outside** any try/catch:
  `vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php:54`
  (`$this->getPdo()->commit()`) precedes `:66` (`$this->transactionsManager?->commit(...)`), and the
  latter is not wrapped. The exception propagates uncaught with the commit already durable.
- `InvoicePostedListener` (`apps/api/app/Modules/Accounting/Listeners/InvoicePostedListener.php:18-34`)
  is **not** `ShouldQueue`, so `UnbalancedJournalEntryException` surfaces as a 500 on the posting
  request with the document permanently `Posted`.
- Retry does not heal it: `DocumentPostingService::post():63-67` idempotently returns on an
  already-posted document **before** `postWithFiscalChain()`, so `InvoicePosted` never re-fires.
  There is no GL backfill command (`apps/api/app/Console/Commands/` has `Backfill*` for banks,
  fiscal years, goods receipts, tax details, tolerance purposes — none for document GL).

Net effect of the change on this shape: **before** = sealed document + an unbalanced GL entry
(visible as a trial-balance gap); **after** = sealed document + **no GL entry at all** — AR,
revenue, VAT and the partner balance are all missing while the trial balance reports "balanced".
For a receivable that is arguably the worse of the two failures, and it is invisible to exactly
the tooling (`verifyChain()`, trial balance) the fix cites as the reason for existing.

The in-code claim is therefore over-stated: `AccountingService.php:284-285` ("Fail CLOSED:
throwing aborts the surrounding transaction, so nothing persists and no chain sequence is
consumed") is true **only of the journal-entry transaction**. Verified true for the JE and the
chain sequence (`JournalEntry::getNextChainSequence()` is `MAX+1`, `apps/api/app/Modules/Accounting/Domain/JournalEntry.php:140-146`
— rollback leaves no gap, and the two new tests assert this and pass). It is false at the
business level.

*Fix options (pick one before merge):*
(a) move the balance assertion **before** the document is posted (pre-flight in
`DocumentPostingService::post()` / `confirm()`), so the refusal is a clean 422 on an unsealed
document; or
(b) keep the guard, but make the listener re-drivable — queue it with retries + a
`documents:repost-gl` console command + an alert — so a refusal is an operational task, not
silent GL absence; or
(c) narrow the guard to the ticket's actual prescription (refuse a **negative** residual only,
see C-2) and route a positive unbookable residual to a rounding account.

**[C-2] `apps/api/app/Modules/Accounting/Application/Services/AccountingService.php:261-262` (and
`:418`) — on FR/Generic charts the guard now hard-fails ORDINARY invoices, not just bugs.
(This is the implementer's open question 4; verdict below is REGRESSION, needs a ruling.)**

- The residual leg is only written when `Account::findByPurpose(..., SalesStampDutyPayable)`
  resolves (`:261-262`). That account is seeded **only** by
  `apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:200` and the TN-only backfill
  `apps/api/database/migrations/tenant/2026_06_30_120000_backfill_sales_stamp_duty_account.php:30`
  (`where('country_code','TN')`). `FranceChartOfAccountsSeeder.php:255` and
  `GenericChartOfAccountsSeeder.php:181` carry `PurchaseStampDuty` only — **no
  `SalesStampDutyPayable`**.
- A **positive** residual is not only a stamp-duty artefact; it is produced by ordinary
  tax rounding, because the two sides of the equation round differently:
  - GL VAT: `groupTaxByRate()` at `:545-549` computes `bcmul(line_total, rate/100, scale)` —
    **truncated per line**.
  - Header tax: `apps/api/app/Modules/Taxation/Domain/Services/TaxCalculationService.php:151-157`
    accumulates each line's tax at `scale+1` and truncates **once per rate bucket** at `:207-209`
    (`CurrencyScale::bcformat` truncates).
  - Since `Σ trunc(xᵢ) ≤ trunc(Σ xᵢ)`, the header tax can exceed the GL VAT by up to `(n−1)`
    units of the last place. Concrete EUR case (`CurrencyScale::DEFAULT_SCALE = 2`,
    `apps/api/app/Shared/Domain/CurrencyScale.php:49`): two lines of net `12.13` at `20%` →
    per-line `trunc2(2.426) = 2.42` ×2 = `4.84` in GL, bucket `trunc2(4.852) = 4.85` on the
    header → `total 29.11` vs `Σcr 29.10` → **residual +0.01 with no 4375 account to absorb it →
    the guard throws.** Two ordinary lines on a French invoice.
- The W-6 ticket prescribes exactly *"A negative residual is a bug, never a rounding artefact"*
  (`docs/superpowers/tickets/2026-08-05-w6-finance-gl-defects.md:130-135`). The implementation
  refuses **any** imbalance, so it converts a known-benign rounding artefact into a hard failure
  on every non-TN chart — combined with C-1, into a sealed-but-GL-less invoice.

*Verdict on open question 4: NOT acceptable as fail-closed-for-launch. It is a regression on
FR/Generic tenants, not a theoretical edge.* Minimum acceptable mitigations, in preference order:
1. Narrow the guard to the ticket's shape — refuse only a residual that the code cannot book
   (i.e. compute the residual, book it when an absorbing account exists, and throw only when the
   residual is **negative** or when a positive residual has no home) — plus seed
   `SalesStampDutyPayable` (or a generic rounding-difference account) into
   `FranceChartOfAccountsSeeder` / `GenericChartOfAccountsSeeder` with a backfill for existing
   non-TN companies; **or**
2. Ship the guard TN-only for launch behind an explicit country/chart predicate, with the FR/Generic
   path ticketed; **or**
3. Owner ruling accepting FR/Generic invoice-posting 500s — only defensible if C-1 is also fixed so
   nothing is left sealed-without-GL.

### IMPORTANT

**[I-3] Residual orphan-minting vectors on the deposit path are NOT closed (open question 1).**
`RecordCustomerDepositService.php:83-109` commits the fiscal event, then runs projections at `:115`
— so *every* invariant `TreasuryDepositBridge` can still fail on mints a permanent sealed orphan.
The lane closes two of them. Still open, all after the seal:
- **Currency (plain client input — same shape as D1).**
  `apps/api/app/Modules/Partner/Presentation/Requests/RecordDepositRequest.php:333` validates
  `currency` as `sometimes|nullable|string|size:3` only, and
  `PartnerDepositController.php:58-60` passes it straight through. A `USD` deposit against a TND
  repository seals the event, then throws `CurrencyMismatchException` at
  `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:81-83`.
  **This is the ticket's own D1 sentence — "reachable with plain client input, no privilege
  needed" — and it survives the fix.** Cheapest closure: validate `currency` against the
  repository/company currency in the same FormRequest, or add it to the pre-flight service.
- **Frozen repository (routine ops — a cash count freezes the drawer).**
  `TreasuryDepositBridge.php:272` passes `allowWhileFrozen: false` for the server-only event →
  `RepositoryFrozenException` at `TreasuryMovementService.php:88-95`, post-seal.
- **Repository resolvable but unusable.** `TreasuryDepositBridge::resolveRepository()` additionally
  requires `gl_account_id ?? account_id` non-null (`:367-370`) **and** an *active* `Account`
  (`:372-387`). `DepositReferenceResolutionService::activeRepositoryExists()`
  (`apps/api/app/Modules/Treasury/Application/Services/DepositReferenceResolutionService.php:38-50`)
  checks none of that — so the "predicates mirror the bridge exactly" claim in its docblock
  (`:26-30`) and in `RecordDepositRequest.php:288-290` is **accurate for the payment method, not
  for the repository**. Fix the docblock or extend the predicate.
- **Actor without active company membership** → `TreasuryDepositBridge.php:418-429`, post-seal.

I-3 is not a regression introduced here, but it means the lane's stated goal ("no plain client
input error can mint a permanent orphan") is not met. The currency leg should be closed in-lane;
the rest can be ticketed.

**[I-4] `apps/api/app/Modules/Partner/Presentation/Requests/RecordDepositRequest.php:327-332` — a
malformed `repository_id` is now a PostgreSQL 500, and the SQLite suite cannot see it.**
Laravel does **not** stop validating an attribute after a non-implicit rule fails:
`vendor/laravel/framework/src/Illuminate/Validation/Validator.php:969-988` only short-circuits on
`bail`, `uploaded`, or a failed *implicit* rule. So with `['required','uuid', ScopedExists…]` a
value like `"abc"` fails `uuid` **and still runs `exists`**, issuing `where id = 'abc'` against a
native PG `uuid` column (`apps/api/database/migrations/tenant/2025_11_30_120000_create_treasury_tables.php:14`
`$table->uuid('id')->primary()`) → SQLSTATE 22P02 → 500. Before this lane, `repository_id` had no
`exists` rule and a malformed value was a clean 422. The test suite runs on SQLite
(`apps/api/phpunit.xml:41-42`), which compares TEXT and silently passes.
*Fix (one word, in-lane):* prepend `'bail'` to the `repository_id` rule array.
(The same latent shape exists in `PayExpenseRequest.php:51-54`; out of scope, worth a ticket.)

**[I-5] `apps/api/app/Modules/Accounting/Domain/Services/DoubleEntryValidator.php:27-29` — the guard
is not "Σdr == Σcr only".** `isBalanced()` returns `false` for `count($lines) < 2` regardless of the
sums. A document with **zero lines** produces exactly one leg (the AR debit at
`AccountingService.php:203-210`) and now throws even though debits equal credits. No empty-lines
guard exists on the confirm/post path (searched `apps/api/app/Modules/Document/` — `lines->isEmpty()`
appears only in the conversion converters and `Document.php:669`). The docblock claim at
`AccountingService.php:40-41` ("a document posting can never legitimately produce" a single-line
entry) is unproven. Either assert `Σdr == Σcr` directly, or add the empty-lines refusal upstream
where it produces a clean 422.

**[I-6] Exception mapping (open question 5) — both new exceptions extend `\RuntimeException`, which
is unmapped.** `bootstrap/app.php:375-384` maps `\DomainException` → 422 `BUSINESS_ERROR`.
- `UnresolvableDepositReferenceException` (`apps/api/app/Modules/Partner/Application/Exceptions/UnresolvableDepositReferenceException.php:22`):
  **should be mapped in-lane.** Nothing is sealed, the caller's input is at fault, and extending
  `\DomainException` instead of `\RuntimeException` gets the correct 422 envelope for free with a
  one-line change. Leaving it a 500 reproduces exactly the D1 symptom the ticket calls out
  ("indistinguishable from an outage in monitoring") for the belt-and-braces path.
- `UnbalancedJournalEntryException` (`.../Accounting/Domain/Exceptions/UnbalancedJournalEntryException.php:24`):
  **must NOT be mapped to 422** while C-1 stands — a 422 would tell the client the request was
  invalid when the document is already sealed. Keep it a 500 + alert, and fix C-1.

**[I-7] Test fixture masks the C-2 blast radius.**
`apps/api/tests/Feature/Accounting/InvoiceGLIntegrationTest.php:106` builds a **`FR`** company but
hand-seeds `SystemAccountPurpose::SalesStampDutyPayable` at `:209` — a chart shape
`FranceChartOfAccountsSeeder` never produces. Consequently the entire FR/Generic behaviour of the
new guard is unexercised, and the 20/20 green run proves nothing about it. The two new tests cover
only the negative-residual (bug) shape. **Missing test:** a multi-line invoice with a 1-unit tax
truncation residual on a company built from the real FR/Generic chart, asserting it still posts.

### MINOR

**[N-8] `AccountingService.php:69-70` — no-arg scale in a method that has an entity currency.**
`assertBalanced()` totals use `$this->scale()` → `CurrencyScaleResolverInterface::getScale()` with
no argument (`:43-46`), and `DoubleEntryValidator::scale()` (`:15-18`) does the same. Per rule 19/20
this throws without a bound `CompanyContext`. **No new exposure** — `createInvoiceGLEntries()`
already resolves scale no-arg at `:232`, `:240`, `:260`, and the only production caller
(`InvoicePostedListener`) is synchronous — but if the listener is ever queued (a plausible fix for
C-1), this is the first thing that breaks. Prefer `getScaleSafe($invoice->currency, 3)`.

**[N-9] Rounding noise is booked to a State liability on TN (pre-existing, now load-bearing).**
Because of the truncation asymmetry in C-2, part of what `AccountingService.php:263-269` credits
to `4375 — droit de timbre à reverser` is tax-rounding residual, not timbre. Pre-existing, but the
new guard makes that account the only thing keeping TN postings green. Worth a ticket.

### AFFIRMED (verified, no action)

- **W-5c D1 fix matches the ticket exactly** — steps 1 and 2 of
  `docs/superpowers/tickets/2026-08-03-w5c-expense-income-deposit-findings.md:88-97` plus the
  2026-08-04 `is_active` escalation at `:78-81`.
- **`is_active` parity on the payment-method leg is EXACT.**
  `DepositReferenceResolutionService.php:26-35` == `TreasuryDepositBridge::resolvePaymentMethod():329-334`
  (tenant, company, code, `is_active`). Neither `PaymentMethod` nor `PaymentRepository` uses
  `SoftDeletes`, so `Rule::exists` (query builder) and `Model::query()` (Eloquent) see the same rows —
  no scope drift.
- **Single production seal path.** `appendDepositReceipt` has exactly one non-test caller
  (`RecordCustomerDepositService.php:93`), and `RecordCustomerDepositService` exactly one
  (`PartnerDepositController.php:69`). The guard at `:72-78` sits before the authoring transaction.
- **Rollback claim (D1a) is true for the JE + chain.** `chain_sequence` is `MAX+1`
  (`JournalEntry.php:140-146`), not a DB sequence — a rollback leaves no gap or reuse. The two new
  tests assert zero surviving entries and pass.
- **`hasValidLines()` omission is CORRECT and should stay omitted.** `DoubleEntryValidator:53-71`
  requires an XOR of `debit>0` / `credit>0`; a legitimate zero-value revenue line (free item, 100%
  discount) has neither and would be rejected. Including it would have been a defect.
- **No floats.** All new math is `bcadd`/`bccomp`; no `(float)`, `parseFloat`, `Number()`, or
  `number_format`. PHPStan level 8 clean on all six changed API files (`[OK] No errors`).
- **Module boundary (rule 6) is sanctioned.** Partner → Treasury via a public Application service
  mirrors the pre-existing `DepositAllocationSummaryService` import in the same constructor
  (`RecordCustomerDepositService.php:13,45`). FormRequest constructor injection of `CompanyContext`
  + `parent::__construct()` matches the canonical `PayExpenseRequest.php:22-26,45-46`.
- **Tripwire flip fidelity (open question 6) is CORRECT.** The app's envelope is
  `{error:{code:'VALIDATION_ERROR', errors:{…}}}` (`bootstrap/app.php:206-217`,
  `apps/api/tests/Traits/AssertsApiValidation.php:14-24`), and the campaign helper flattens
  `parsed.data ?? parsed.error` (`apps/web/e2e/money-campaign/treasury-support.ts:131-136`), so the
  flipped `.code` / `.errors` reads at
  `apps/web/e2e/money-campaign/income-deposits.spec.ts:431-447` resolve correctly.
- **Removing the cross-tenant gate is safe.** The flipped block mints nothing (both probes now 422
  pre-seal); `ALLOW_CROSS_TENANT_SIDE_EFFECTS` / `CROSS_TENANT_SKIP_REASON` are no longer referenced
  in `income-deposits.spec.ts` and remain used by `expenses-analytics.spec.ts` / `w5c-support.ts`, so
  the import removal is clean. Restoring the `repository_id` twin probe is justified for the same
  reason. **Caveat:** this rests on I-4 — with a *malformed* (not merely unknown) uuid the block
  would 500; the probe uses the nil uuid, which is well-formed, so the spec as written is fine.
- **D1b tripwires correctly unmoved.** `apps/web/e2e/money-campaign/finance-reports.spec.ts` changes
  are comments only; `MTP-GL-08/09/15` pin the immutable stranded `19.000` entry, which is data
  (`ticket:137-150`), not code. The reasoning in the new comment block (`:53-68`) is sound.
- **The reported pre-existing red is genuinely pre-existing.** `TreasuryDepositBridgeTest`
  fails with `ArgumentCountError` (constructor arity 4 vs 3 passed at
  `apps/api/tests/Feature/Fiscal/TreasuryDepositBridgeTest.php:300`). Neither
  `TreasuryDepositBridge.php` nor that test appears in `git diff --name-only 7d8e6c861..6f54871ae`;
  last touch was `86b68d4ce`/`57328a7c3`, both before the base commit.

### Test runs performed (by path, SQLite, this worktree)

| Suite | Result |
|---|---|
| `tests/Feature/Accounting/InvoiceGLIntegrationTest.php` + `CreditNoteGLIntegrationTest.php` | **OK 20/20, 89 assertions** |
| `tests/Feature/Partner/RecordCustomerDepositTest.php` | **OK 10/10, 66 assertions** |
| `tests/Feature/Fiscal/TreasuryDepositBridgeTest.php` | 9 tests, 5 errors / 4 failures — **pre-existing** (see above) |
| `phpstan` on the 6 changed API files | **[OK] No errors** |

---

## Answers to the implementer's open questions

1. **FR/Generic fail-closed blast radius** → **REGRESSION, needs a ruling before merge.** See C-2:
   the trigger is not a missing account plus a bug, it is a missing account plus *ordinary tax
   truncation asymmetry*, and it fires on a two-line French invoice. Compounded by C-1, the result
   is a sealed invoice with no GL at all. Recommended: narrow the guard to the ticket's negative-
   residual prescription and seed an absorbing account for non-TN charts.
2. **Unmapped `UnresolvableDepositReferenceException`** → **map it in-lane** (extend
   `\DomainException`, one-line, gives 422 `BUSINESS_ERROR` via `bootstrap/app.php:375-384`).
   Do **not** map `UnbalancedJournalEntryException` to 422 while C-1 stands.
3. **Remaining seal-before-resolve paths** → **yes, four** (I-3). Close the `currency` one in-lane;
   ticket frozen-repository / missing-GL-account / actor-membership.
4. **`hasValidLines()` omission** → **correct, keep it omitted** (zero-value lines are legitimate).
   But `isBalanced()`'s `count < 2` clause is an unadvertised second rejection rule (I-5).

## What to fix before merge

Split the lane: land W-5c D1 (`d0fabd896`) after adding `'bail'` to `repository_id`, mapping
`UnresolvableDepositReferenceException` to 422, and validating `currency` against the repository;
hold W-6 D1a (`6f54871ae`) until the guard runs *before* the document is sealed (or is re-drivable)
and the FR/Generic rounding residual has an absorbing account plus a test on the real chart.

---

# ROUND 2 — narrow re-gate (`6f54871ae..HEAD`)

**Commits:** `39153158d` (D1 bail + 422 mapping), `f2d521f77` (full bridge parity + refusal enum),
`9c174a4f5` (D1a rework: pre-seal preflight + narrowed residual rule).
**Reviewer:** fiscal-pos-reviewer, adversarial. Nothing modified; nothing merged.

## VERDICT (round 2)

**REJECT — narrow. The DESIGN is ACCEPTED; three mechanical defects block the merge.**

The pre-seal architecture is exactly right and C-1/C-2/I-5/I-6/I-7/N-8 are genuinely closed,
with real end-to-end proof. But the branch is **objectively red on tests it caused**, it ships
**no backfill** for the new accounts, and the lineless carve-out **silently changed Tunisian
behaviour** into a new unbalanced-entry vector on the chain that is E-7 evidence.

One round-1 finding (**I-4**) was **WRONG and is withdrawn** — see the adjudication below.

## Closure table

| # | Round-1 finding | Claim | Verdict | Evidence |
|---|---|---|---|---|
| C-1 | guard threw post-seal, unrecoverable | closed | **CLOSED** | `DocumentPostingService.php:103` preflight, `:105` seal, both inside the `DB::transaction` opened at `:84`; DI sweep clean; `DocumentGlPreflightTest:134-197` |
| C-2 | FR/Generic ordinary invoices hard-fail | closed | **CLOSED for new companies / OPEN for existing** | `InvoiceGLIntegrationTest` on the real FR seeder; `DocumentGlPreflightTest:204-247`. See **R2-2** |
| I-3 | 4 residual orphan vectors | partial per ruling | **ACCEPTED** | `DepositReferenceRefusal` 5 cases; `DepositReferenceResolutionService.php:44-105`; 2 TOCTOU vectors ticketed |
| I-4 | malformed uuid → PG 500 | disputed | **WITHDRAWN — my finding was wrong** | probe below |
| I-5 | `count<2` second rule | closed | **CLOSED** | `DoubleEntryValidator.php:63-79` `isSumBalanced()` |
| I-6 | exception mapping | closed | **CLOSED** | `UnresolvableDepositReferenceException` now `extends DomainException`; `UnbalancedJournalEntryException` correctly still a 500 |
| I-7 | FR fixture hand-seeded 4375 | closed | **CLOSED** | `InvoiceGLIntegrationTest` runs `FranceChartOfAccountsSeeder` and asserts 4375 is ABSENT |
| N-8 | no-arg `getScale()` | closed | **CLOSED** | `documentScale()` = `getScaleSafe($document->currency, 3)`; zero no-arg `getScale()` remains in `AccountingService` |
| N-9 | TN books rounding noise as timbre | — | **OPEN, NOT TICKETED** | neither new ticket covers it |

## New findings

### [R2-1] CRITICAL (merge-blocking) — three existing tests are RED because of this lane

`apps/api/tests/Feature/Document/CreditNoteMoneyLaneTest.php` `:212`, `:477`, `:537` —
`POST /credit-notes/{id}/post` returns **422 where the test asserts 200**:

- `test_amount_based_credit_note_reconstructs_exactly_with_tn_stamp`
- `test_over_credit_allocation_clamps_balance_due_at_zero`
- `test_line_based_discount_is_prorated_and_draft_equals_confirm_equals_posted`

Cause: the fixture builds a **TN** company (`:84` `country_code 'TN'`, `:87` TND) with a
hand-rolled chart (`:118-164`) carrying `CustomerReceivable`, `ProductRevenue`, `VatCollected`,
`SalesReturn`, `ServiceRevenue` — and **no `SalesStampDutyPayable`, no rounding pair**. The
credit note carries the `0.600` TN stamp, so `residualPlan()` sees a positive residual of
`0.600`, finds no absorbing account, returns `GlResidualRefusal::NoAbsorbingAccount`, and the
preflight throws `UnpostableDocumentGlException` → `DomainException` → 422 `BUSINESS_ERROR`.
Previously the residual was silently dropped and the post succeeded (with an unbalanced entry).

The file is **untouched by the lane** (`git diff --name-only 7d8e6c861..HEAD` does not list it;
last touched `ba7be2ce2`), so this is lane-caused. The implementer's "document/compliance 109
passed" claim did not cover `tests/Feature/Document` in full.

**My run — `phpunit tests/Feature/Document tests/Feature/Compliance`: 562 tests, 13 errors,
3 failures.** The 13 errors are `IngressPrecisionTest` / `CreateDocumentRequest` constructor
arity and are **PRE-EXISTING** (last touched `1a0f366ca`, verified an ancestor of the lane base
`7d8e6c861` via `git merge-base --is-ancestor`). The 3 failures are **NEW**.

*Fix:* seed the absorbing account into that fixture (it is a TN chart — it should have 4375), or
switch the fixture to `TunisiaChartOfAccountsSeeder` the way `InvoiceGLIntegrationTest` now does
for FR. Do not weaken the guard for this.

### [R2-2] IMPORTANT (CRITICAL for any existing non-TN tenant) — no backfill migration for 6581/7581

The new absorbing accounts are added to the **seeders only**:
`apps/api/database/seeders/FranceChartOfAccountsSeeder.php:276-277` and `:309-311`,
`apps/api/database/seeders/GenericChartOfAccountsSeeder.php:186-193` and `:214-216`.
`git diff --name-only 7d8e6c861..HEAD` contains **no migration**. The TN precedent did ship one
(`apps/api/database/migrations/tenant/2026_06_30_120000_backfill_sales_stamp_duty_account.php`).

Consequence: every **already-provisioned** FR/Generic company has no `SalesRoundingDifference*`
account, so the first ordinary per-line truncation residual now returns `NoAbsorbingAccount` and
the invoice becomes **unpostable (422)**. That is strictly better than round 1 (nothing is
stranded, and the document stays `Confirmed` and retryable) but it is still a functional outage
on staging/demo and on any non-TN tenant.

Mitigating: the chart seeders are **idempotent** — `FranceChartOfAccountsSeeder.php:39-43` skips
any code that already exists and only promotes `is_system` — so a per-company re-run is a valid
backfill. Edge case to handle: a company that already owns a *user-created* account with code
`6581`/`7581` is skipped **without** the `system_purpose` being mapped, so `findByPurpose()`
still returns null and the document is still refused.

*Fix:* add a tenant migration that maps/creates the pair for every company whose chart lacks the
purpose (mirroring `2026_06_30_120000`), or call the seeder per company from a migration.

### [R2-3] IMPORTANT — the lineless carve-out silently CHANGED Tunisian behaviour and mints a new unbalanced entry

`AccountingService::residualPlan()`, the `$document->lines->isEmpty()` branch, returns
`new DocumentGlResidualPlan('0', '0', $total, [], null, null, false)` — `absorbingAccount = null`.
Both posting methods then write **no residual leg** (`if ($plan->absorbingAccount !== null)`).

Before this lane, the inline code computed `$stampDuty = total − 0 − 0 = total` and, **on a chart
with 4375, wrote the leg** — so a lineless TN document produced a *balanced* (if nonsensical)
entry. Under round 2 it produces a **one-legged, `Posted`, hash-chained, UNBALANCED entry on
every chart, including Tunisia**.

That is a behaviour change, not a preservation, and it directly contradicts:
- the ticket header — `docs/superpowers/tickets/2026-08-05-lineless-document-gl-posting.md`
  "**Status:** OPEN — behaviour deliberately left UNCHANGED by the L1 lane";
- the ticket body `:15-17`, which still describes the OLD TN behaviour ("the whole `total` is
  swept into `4375` … The entry balances") as if it still held;
- the lane's own goal, and the D1b tripwire premise now conceded in
  `apps/web/e2e/money-campaign/finance-reports.spec.ts:110-113`.

It is a NEW unbalanced-entry vector on `demo-pharmacy-tn`, the chain that is E-7 evidence.

*Fix (small, and it preserves the ~35-test suppression exactly):* in the lineless branch resolve
the absorbing account the way the old inline code did — positive residual **and** an account
found ⇒ carry it in the plan so the leg is written; otherwise carry `null`; never refuse; keep
`balanceAssertable = false`. That restores TN byte-for-byte and leaves FR/Generic's pre-existing
one-legged shape untouched (which is what the ~35 fixtures assert).

### [R2-4] MINOR — `ResidualExceedsRoundingTolerance` prescribes the wrong remedy

`GlResidualRefusal::message()` says *"Correct the document totals before posting."* When the
cause is a legitimately-configured document-level charge, the correct remedy is to map an
absorbing account, not to alter the totals. No live FR/Generic configuration produces one today
(`FranceTaxConfigurationSeeder.php:63` seeds `LINE_ITEMS` only; only
`TunisiaTaxConfigurationSeeder.php:137` seeds `DOCUMENT_TOTAL`), so this is wording, not a defect.

### [R2-5] MINOR — tolerance docblocks assert a bound that is one ULP looser than the derivation

`AccountingService::roundingTolerance()` and `GlResidualRefusal`'s docblock both state the bound
as "one unit of the last place **per line**". The provable bound is `(n−1)·ULP`: with
`trunc_s(x) > x − ULP` for each line, `Σ trunc_s(aᵢ) > Σaᵢ − n·ULP`, while the header
`trunc_s(Σ trunc_{s+1}(aᵢ)) ≤ Σaᵢ`, so the difference is `< n·ULP` — and being an integer
multiple of ULP, `≤ (n−1)·ULP`. See the ruling below: `n` is **accepted** as a deliberate margin,
but the docblocks should say so rather than present `n` as derived.

### [R2-6] MINOR — cross-module model import

`DepositReferenceResolutionService.php` now imports `App\Modules\Accounting\Domain\Account`
(Treasury → Accounting **model**), which CLAUDE.md rule 6 forbids. Precedent exists inside the
same module (`TreasuryDepositBridge` already imports it for the identical check), so this is
consistency with existing debt rather than new drift — worth a ticket, not a block.

## Adjudication: the I-4 dispute — **the implementer is RIGHT, I was wrong**

`Validator::isValidatable()` (`vendor/laravel/framework/src/Illuminate/Validation/Validator.php:817-827`)
gates every rule on `hasNotFailedPreviousRuleIfPresenceRule()` (`:902-905`):

```php
return in_array($rule, ['Unique', 'Exists']) ? ! $this->messages->has($attribute) : true;
```

— documented in-source as *"This is to avoid possible database type comparison errors."* Round 1
checked `shouldStopValidating()` (`:969-988`), which indeed does not stop, and **missed this
second, independent gate**. Empirically settled with a probe (illuminate/validation + an
exploding `PresenceVerifier`, so any DB touch is visible):

| rule array (value `'not-a-uuid'`) | DB hit? |
|---|---|
| `['required','uuid', Rule::exists(...)->where(...)]` (round-1 shape) | **no** |
| `['bail','required','uuid', Rule::exists(...)]` (round-2 shape) | **no** |
| `['required', Rule::exists(...), 'uuid']` (order flipped) | **YES** — would be SQLSTATE 22P02 |

Also confirmed: `ScopedExists::tenantAndCompany()` uses scalar `where()`, so `queryCallbacks()`
is empty and `ValidationRuleParser::prepareRule():135-140` stringifies the rule to `exists:…`,
which is what makes the `'Exists'` name match above fire. (A `ScopedExists::tenantOrSystem()`
rule, which uses a Closure, would stay an object — out of scope here.)

**I-4 is WITHDRAWN.** The round-1 record's I-4 entry is incorrect and is superseded by this
section. `'bail'` remains a correct and worthwhile addition: the probe shows the protection is
**order-dependent**, so `'bail'` converts an implicit framework behaviour into an explicit,
order-independent guarantee. Keep it.

## Rulings requested

**1. Tolerance `n` vs `n−1` ULP → ACCEPT `n`.** The tight bound is `(n−1)·ULP` (derivation in
R2-5), so `n` grants exactly one ULP of slack. Can `n` mask a real defect one line could produce?
Yes, in the strict sense: for `n = 1` the truncation bound is exactly `0`, so a single-line
document is allowed 1 ULP of unexplained money that books to the rounding account. That is the
smallest representable amount, and the slack buys robustness against the other sub-ULP sources
this lane did not model — the document-discount proration branch in `TaxCalculationService` and
any `line_total` vs `calculateTotal()` divergence — each of which a tight `n−1` bound would turn
into a hard 422. Round 1 rejected an over-tight guard for exactly that reason; do not repeat it.
**Keep `n`; correct the docblocks to present it as a deliberate 1-ULP margin over the derived
`(n−1)` bound.**

**2. Beyond-tolerance POSITIVE residual on FR/Generic → REFUSE is correct; do NOT absorb-with-alert.**
Absorbing routes unexplained money into PCG 758 *"produits divers de gestion courante"* — a real
revenue account — which mis-books a genuine document-level charge as miscellaneous income,
understating the corresponding liability and its tax treatment. That is a silent money
misstatement, which is strictly worse for a fiscal gate than a **recoverable** refusal: the
document stays `Confirmed`, nothing is sealed, and `DocumentGlPreflightTest:170-197` proves it
posts once corrected. An alert nobody reads does not undo a mis-booking; a 422 cannot be ignored.
This is consistent with my round-1 stance — "negative refuses, positive is rounding" — because a
residual **beyond** the truncation bound is by definition **not** rounding. Fix the remedy
wording (R2-4).

**3. TN absolute precedence → byte-identical for documents WITH lines; NOT byte-identical for
lineless ones.** `residualPlan()` checks `SalesStampDutyPayable` before any tolerance and returns
immediately, so any-size positive residual still credits 4375 with the unchanged
`'Stamp duty (timbre)'` description — proven by
`InvoiceGLIntegrationTest::test_the_tunisian_chart_still_credits_the_timbre_to_4375_and_balances`.
Z/X/EOD and E-7 aggregates are untouched. The lineless exception is R2-3 and must be fixed.
**N-9 (rounding noise booked as timbre on TN) is still open and, contrary to the round-1
recommendation, is NOT covered by either new ticket** — file it.

**4. Zero-line skip deviation → acceptable in principle, NOT as implemented.** The unreachability
claim holds: `CreateDocumentRequest.php:97` requires `lines` `min:1`, and every credit-note mode
in `CreditNoteController::store():148-165` requires either `lines min:1` or a source invoice
whose lines are derived (confirmed: the amount-based credit note in `CreditNoteMoneyLaneTest`
reaches the *lined* branch, not the lineless one). Supplier documents are a distinct
`DocumentType` and the preflight no-ops on them. The ticket is thorough and honest. But the
carve-out as written is not behaviour-preserving (R2-3) and the ticket text misstates the current
behaviour — fix both.

## Round-2 test runs (by path, SQLite, this worktree)

| Suite | Result |
|---|---|
| `DocumentGlPreflightTest` + `InvoiceGLIntegrationTest` + `CreditNoteGLIntegrationTest` + `RecordCustomerDepositTest` | **OK 42/42, 223 assertions** |
| `tests/Feature/Document` + `tests/Feature/Compliance` | **562 tests — 13 errors (PRE-EXISTING, `IngressPrecisionTest`), 3 failures (NEW, `CreditNoteMoneyLaneTest`)** |
| `PosBridgeSpineTest` + `DepositReceiptProjectionTest` | **OK 12/12, 55 assertions** |
| `phpstan` on Accounting + Partner + Treasury deltas + `DocumentPostingService` + `Shared/Contracts/Accounting` | **[OK] No errors** |

## What to fix before merge (round 2)

Three mechanical items, no redesign: (1) green the 3 `CreditNoteMoneyLaneTest` cases by giving
that TN fixture its 4375 account; (2) ship a backfill migration mapping `SalesRoundingDifference*`
for existing FR/Generic companies; (3) restore the absorbing-account resolution in the lineless
branch so Tunisian lineless postings stay balanced, and correct the two ticket statements that
describe it as unchanged. Then re-run `tests/Feature/Document` in full.
