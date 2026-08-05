# L4 currency-emission lane — API half, precision-contract merge gate

**Reviewer:** fiscal-pos-reviewer (adversarial, code-grounded)
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.fix-l4-currency` · branch `fix/l4-currency-emission`
**Diff scope:** `git diff 7d8e6c861..HEAD -- apps/api` (3 commits: `132357c4f`, `843ee67e7` (web, not gated here), `e90c9f2fd`)
**Tickets:** W-6 D6/D3 · W-7 F-2/F-7 · W-8 F-3 · plan §2 L4
**Primary lens:** CLAUDE.md rule 19 + `docs/architecture/precision-contract.md`; rule 13 (constructor injection); rule 20 (queue/console scale resolution)
**apps/web is gated in parallel by frontend-conventions-reviewer — not covered here except where a web file is the tripwire for an API behaviour.**

---

## VERDICT

**spec ✅ · quality APPROVE-WITH-FIXES**

The three defects the lane was chartered to kill are killed, and killed correctly:

- `FormatsReportNumbers::decimalString()` no longer casts to float, no longer hardcodes scale 2, no longer `rtrim`s
  (`apps/api/app/Modules/Accounting/Application/Services/Reports/FormatsReportNumbers.php:53-55`), and `$scale` has
  **no default** — verified no call site regained a silent 2 (all 12 call sites pass an explicit scale;
  `SalesReportService.php:83,131,187,341`, `CashRegisterReportService.php:71-73`).
- Quantities left the currency formatter entirely (`FormatsReportNumbers.php:72-75` → `QuantityScale::formatForUnit`;
  `StockAlertReportService.php:56-57`, `SalesReportService.php:135,196`).
- The trial balance emits every figure — both sides of every line, both totals, populated or zero — at one
  resolved-once scale (`TrialBalanceService.php:162-186, 388-393, 422-427`), which closes W-8 F-3 and W-6 D3 together.
- **Zero live `(float)` casts remain** in the five touched files; the only `(float)`/`number_format` tokens left are
  inside docblocks (`FormatsReportNumbers.php:19`). Verified by grep over the diff and the files.

Nothing in this diff touches a fiscal event, a hash chain, a projection, or a device-authored fact. It is purely a
server-side read-model presentation change. **No Critical findings.**

The five Important findings below are all *contract-hygiene* items, not money bugs: an unfalsifiable rounding
decision, a false docblock justification, and an inconsistency with the sibling service sitting in the same directory.

---

## Verification actually performed (not taken on trust)

| Check | Result |
|---|---|
| `phpunit tests/Feature/Accounting/ReportNumberEmissionTest.php tests/Feature/Accounting/Reports/TrialBalanceCurrencyScaleTest.php` | **OK — 11 tests, 43 assertions** (7 + 4 as claimed) |
| `phpunit tests/Feature/Accounting/OwnerReportingTest.php` | **OK — 16 tests, 217 assertions** |
| `phpunit tests/Feature/Accounting/Reports/TrialBalanceTest.php` (pre-existing) | **OK — 8 tests, 33 assertions** |
| `phpunit SalesReportServiceReturnsTest + SalesReportServicePaymentBreakdownTest + CashMovementsReportTest` | **OK — 16 tests, 98 assertions** |
| `phpstan analyse app/Modules/Accounting/Application/Services/Reports` (live DB, level 8) | **No errors** |
| `pint --test` on the changed files | clean (the 2 failures are `AgedPayablesService` / `AgedReceivablesService`, untouched pre-existing files) |
| PG behaviour of the new string-bound threshold `whereRaw('… <= (min_quantity * ?)', [$thresholdRatio])` (`StockAlertReportService.php:28,37`) | **verified live against PG 5433**: `numeric * <text-bound param>` resolves to numeric. Safe — not just assumed from the SQLite test run |
| `bcround` vs `bcformat` divergence, executed | `'300.0005'` → `300.001` vs `300.000`; `'-0.5005'` → `-0.501` vs `-0.500` |
| POS device rounding mode, executed (`big.js`, `Big.RM = 1`) | `'300.0005'` → `300.001`, `'-0.5005'` → `-0.501` — **half away from zero, identical to `CurrencyScale::bcround`** |

`phpstan.neon` declares `paths: app/` — tests are not analysed in CI, so the `collect()` template-type noise surfaced by
forcing an analysis of `OwnerReportingTest.php` is not a finding.

### OwnerReportingTest assertion moves — each checked against the ticket

The fixture company is `Company::factory()` with no `countries` row, so `CurrencyScaleResolver` falls through to
`CurrencyScale::for('EUR') = 2` (`CurrencyScaleResolver.php:59-70`). Every moved assertion goes from the *defect*
shape named in W-7 F-2 (scale-2 **and** `rtrim`ed) to the *ticket-expected* shape (currency scale, untrimmed):

`'120'→'120.00'`, `'80'→'80.00'` (`OwnerReportingTest.php:182-183`) · `'60'→'60.00'` (`:357`) ·
`'-5'→'-5.00'` on the cash variance (`:452`) · `'80'→'80.00'`, `'20'→'20.00'` (`:477,:482`).
The two negative assertions `assertNull(firstWhere('gross_sales','999'/'777'))` were correctly reshaped to
`'999.00'/'777.00'` (`:184-185`) so they still exclude the training/void rows rather than passing vacuously.
**No assertion was moved toward the defect, and none was weakened.**

### Tripwire flips (web files, API behaviour — checked for faithfulness only)

- `apps/web/e2e/money-campaign/w7-multilocation.spec.ts` MTP-MLC-01: flipped from `some(v => !/\.\d{3}$/)` **true** to an
  `offenders == []` assertion. Faithful, and strictly stronger than the old tripwire.
- `apps/web/e2e/money-campaign/finance-reports.spec.ts` MTP-GL-19: `'0.00'` → `'0.000'`, and the comment now *records*
  that P&L and balance sheet still emit at the fixed report scale 4. That residual is honestly disclosed rather than
  hidden — good. It does mean the finance report family still carries two zero-shapes; see F-5.

---

## FINDINGS

### Critical
None.

### Important

**[IMPORTANT] `apps/api/app/Modules/Accounting/Application/Services/Reports/SalesReportService.php:32-35`,
`CashRegisterReportService.php:62`, `TrialBalanceService.php:87-90` — the lane picked the WEAKER of two in-repo
patterns for resolving the report currency, and did so without saying why.**
The sibling service in the same directory, `OwnerSalesSummaryService.php:39-40` + `:80-87`, derives the scale from the
DATA (`resolveCurrency($companyIds)` → `getScale($currency)`) and **refuses** a mixed-currency scope outright:

```php
if ($currencies->count() > 1) {
    throw new AuthorizationException('Owner reporting cannot aggregate across companies with different currencies.');
}
```

The new code instead takes the scale from `CompanyContext` via `getScaleSafe()`. Result: on one owner dashboard,
`/reports/sales/summary` **403s** a mixed-currency parent/child scope while `/reports/sales/by-location` and
`/reports/cash-register/reconciliation` silently return cross-currency sums rendered at the ROOT company's scale.
`ReportsController.php:184` makes it unavoidable on the cash surface — `companyIds(null, $user)` is always root + **all**
children (`OwnerReportScope.php:28-41`), so an operator cannot filter the foreign-currency child out. **Failure mode:** a
TND child under a EUR root renders its Z/EOD variance at 2 dp — the millime loss this very ticket exists to kill,
relocated rather than removed. Not launch-blocking (tenant #1 is a single TND company) and the underlying cross-currency
`SUM` is already meaningless (pre-existing), but this is the permanent contract.
**Fix before merge (minimum):** replace the misleading comment (next finding) with the real limitation and file the
ticket. **Fix soon:** two of the three surfaces are per-company-row and can carry `companies.currency` for near-free —
`salesByLocation` already joins `companies` (`SalesReportService.php:51`), and `CashRegisterReportService.php:35-38` has
`pos_terminals.company_id` in hand.

**[IMPORTANT] `SalesReportService.php:24-31` and `TrialBalanceService.php:81-86` — the docblock justification for
`getScaleSafe()` is factually false, and the safe variant masks a real error.**
Both comments state the reports "are also reachable from console contexts where no company is bound". They are not.
Grep over `app/` shows the ONLY consumer of all four services is `ReportsController` (`ReportsController.php:85-95`), and
every action resolves `CompanyContext::requireCompanyId()` *before* calling them (`:106`, `:169`, `:184`, `:317`). No
queued job, no console command, no scheduler entry. So the `fallback = 3` branch is dead — and if it ever becomes live
it will silently render a **EUR** tenant at 3 dp instead of failing loudly. Rule 20's `getScaleSafe` carve-out is for
queued/console code; this is a request-only path, where `getScale()` is the correct call precisely because it throws.
**Fix:** switch these three to `getScale()`, or keep `getScaleSafe()` and replace the false justification with the true
one (the multi-company limitation from the previous finding).

**[IMPORTANT] `apps/api/tests/Feature/Accounting/ReportNumberEmissionTest.php:134-140`,
`tests/Feature/Accounting/Reports/TrialBalanceCurrencyScaleTest.php:134,157,193` — the diff's single most consequential
semantic decision is pinned by ZERO tests.**
`decimalString`/`emit` now **round** (`CurrencyScale::bcround`) where the old code rounded at the wrong scale, and where
a plausible "fix" would have been to **truncate** (`bcformat`). Every fixture value in both new test classes has a zero
4th decimal — `'300.000'`, `'299.500'`, `'-0.500'`, `'777.770'` — so round and truncate produce byte-identical output
and a future revert to `bcformat` goes **green**. Executed proof of the gap: `bcround('300.0005',3) = '300.001'` vs
`bcformat('300.0005',3) = '300.000'`; `pos_shifts.expected_cash|actual_cash|variance` are `DECIMAL(16,4)`
(`database/migrations/tenant/2026_04_25_000002_widen_pos_shifts_monetary_columns_to_scale_4.php:20-22`), so a 4th
decimal is representable at rest.
**Fix:** add one cash-reconciliation case with `expected_cash '300.0005'` on a TND company asserting `'300.001'`, and one
trial-balance case at `777.775` → `'777.78'`. This is the assertion that makes the Q4 ruling durable.

**[IMPORTANT] `FormatsReportNumbers.php:55` + `TrialBalanceService.php:100-103` vs
`docs/architecture/precision-contract.md:17` — the diff deviates from the written display contract without amending it.**
The contract says: *"Display **truncates** to `getDecimals(currency)`"* (`:17`), and `:26` names `bcformat`'s truncation
"the canonical write-boundary behavior". The diff makes report display **round**. Per Q4 below this is the *right* call,
but the doc now contradicts the code on the exact surface its own open item `:55`/`:181` flags
(*"POS device Big.RM half-up vs server truncation — coordinated rounding alignment"*). The next reader will file this
code as a rule-19 violation.
**Fix:** one paragraph in `precision-contract.md` § Emission & display recording that **report presentation** rounds
half-away-from-zero for device-display parity, while **write/canonicalization boundaries** still truncate — and that the
two are deliberately different.

**[IMPORTANT] `FormatsReportNumbers.php:55` vs `OwnerSalesSummaryService.php:57-59,65` vs
`LiveSalesReportService.php:82` — the owner dashboard now carries three emission conventions in one viewport.**
The new code rounds (`bcround`); the four KPI tiles rendered directly above it truncate (`bcformatStrict`); and
`LiveSalesReportService.php:82` emits `total: (string) $row->total` — completely unscaled and currency-blind, the exact
W-7 F-2 shape, in the directory the lane says it standardised. `LiveSalesReportService` was not in F-2's blast-radius
list so this is not a lane failure, but "one contract fix" (plan §2 L4) is not yet true.
**Fix:** ticket + a one-line note in the lane handoff so the residual is not rediscovered by wave 10.

### Minor

**[MINOR] `FormatsReportNumbers.php:97-110`** — the float branch normalises a MONEY value at `QuantityScale::SCALE`, i.e.
the quantity domain constant used as money precision. Safe today (4 > every currency scale) but a category error in a
money path. Note the asymmetry: PostgreSQL returns `numeric` as a **string**, so the float branch is *dead in
production*; SQLite's `SUM()` returns a **float**, so the float branch is the one the test suite exercises. The shipping
path is the untested one. Suggest a locally-named `MONEY_NORMALISATION_SCALE` constant and one test that feeds a
numeric-string directly.

**[MINOR] `CashRegisterReportService.php:88-92`** — the docblock explains the `+4` as "the currency scale plus the
quantity storage scale". It is really the storage scale of the two operands: `pos_shifts.variance` `DECIMAL(16,4)`
(`…widen_pos_shifts_monetary_columns_to_scale_4.php:20-22`) and `company_fraud_settings.cash_variance_*` `decimal(12,4)`
(`…add_cash_variance_settings_to_company_fraud_settings.php:15`). `QuantityScale` is unrelated. Cosmetic, but this
docblock is what a future maintainer will trust when they change the scale.

**[MINOR] `TrialBalanceService.php:438-439`** — `bcadd('0','0',self::DECIMAL_SCALE)` where `'0'` suffices; every
subsequent accumulation is already at `DECIMAL_SCALE` (`:446-447`). Harmless indirection.

**[MINOR] claim-vs-code, `StockAlertReportService.php:11-20`** — the handoff claims all four services
constructor-inject `CurrencyScaleResolverInterface`. This one has **no constructor** and injects nothing. The **code is
right** (that surface emits only quantities and a severity — no money, so no currency scale to resolve); the *claim* is
wrong. Correct the handoff so nobody "fixes" it into a needless dependency.

**[MINOR] `SalesReportService.php:196`** — `revenueByCategory.quantity` now emits at the canonical storage scale 4
(`12.5000`) and `CategoryRevenueData` carries no `quantity_decimals`, so no client can render it at a unit precision.
Verified inert: the sole consumer `apps/web/src/features/owner-dashboard/components/RevenueByCategoryDonut.tsx:27` reads
only `revenue` and never touches `quantity`. Ticket-faithful as shipped; either drop the field or add
`quantity_decimals`. (`topSkus` and stock alerts are fine — the FE re-formats via
`formatQuantity(row.quantity, getQuantityDecimals(row))`, `TopSkusWidget.tsx:62`, `LowStockAlertsList.tsx:36-37`.)

### Recorded — out of this lane, no action requested here

- **Server/device Z-report hash canonicalization genuinely diverges.** Server
  `app/Modules/POS/Domain/Services/ZReportHashService.php:114` normalises with `CurrencyScale::bcformat(..., 3)` —
  **truncates**. Device `apps/pos/src/lib/fiscal/zReportHashService.ts:58` calls `bcformat` from
  `apps/pos/src/lib/decimal.ts:77` = `new Big(v).toFixed(3)` with `Big.RM = 1` — **rounds half away from zero**
  (executed and confirmed). This is precision-contract.md `:55`/`:181`'s open item, it is a live fiscal-hash divergence,
  and it is **not** touched by this diff. It belongs on the L1 fiscal lane.
- **The trial balance's lines have never footed to its totals.** `lines[]` show the NET per-account balance in one
  column (`TrialBalanceService.php:365-393`) while `total_debit`/`total_credit` are the GROSS `Σ total_debit` /
  `Σ total_credit` per account (`:436-454`). Pre-existing, unchanged by this diff, outside the ticket. Recorded so it is
  not mistaken for an L4 regression.
- `RevenueByCategoryDonut.tsx:27` does `Number(row.revenue)` on money — apps/web surface, pre-existing, flagged for the
  frontend-conventions reviewer, not gated here.

---

## Explicit verdicts on the implementer's open questions

### Q4 — `bcround` (half away from zero) vs `bcformat` (truncate) at the presentation boundary, on the Z/EOD cash surface

**RULING: `bcround` is CORRECT. Do NOT switch to truncation.** Conditioned on the two fixes I3 and I4.

Grounds, all verified rather than recalled:

1. **The device rounds.** The POS Z-report display path is `ZReportModal.tsx:27,132-141` → `useCurrency().format`
   (`apps/pos/src/lib/currency.ts:65-77`) → `formatCurrency` (`apps/pos/src/lib/currency.ts:43-63`) →
   `Intl.NumberFormat` with `maximumFractionDigits: d`, whose default rounding mode is `halfExpand` (half away from
   zero). The sibling `apps/pos/src/lib/decimal.ts:101-116` `formatCurrency` uses `Big.toFixed` under `Big.RM = 1`,
   also half away from zero — **executed**: `'300.0005' → 300.001`, `'-0.5005' → -0.501`, identical to
   `CurrencyScale::bcround`. Truncating server-side would have **manufactured** the exact server-vs-device millime
   disagreement the question is worried about. Rounding removes it.
2. **The prior behaviour already rounded.** The old helper was `number_format((float)$v, 2, …)`, and `number_format`
   rounds. Choosing `bcround` fixes the scale, the float and the `rtrim` while holding the rounding semantic constant;
   choosing `bcformat` would have smuggled a *second*, undiscussed behaviour change into a ticket that asked for none.
3. **`bcround` is the designated helper for this position.** Its own docblock at
   `app/Shared/Domain/CurrencyScale.php:147-171` states it is the **presentation / GL-posting boundary** helper, in
   contrast to `bcformat`'s write-boundary truncation, and cites NC 01 §62 for carrying higher precision at rest and
   rounding only at the boundary. This diff is exactly that boundary.
4. **Nothing hash-signed or fiscally attested is affected.** `CashRegisterReportService` reads `pos_shifts` for display
   only, and its sole caller is `ReportsController::cashRegisterReconciliation` (`:181-194`) — verified by grep over
   `app/`. The fiscal Z attestation runs off `z_reports.report_data` through `ZReportHashService::normalizeForHash`
   (`:93-140`), which this diff does not touch. Device-side Z totals are compared against the device's own
   `report_data`, not against this Accounting read model.
5. **Practical impact today is nil**, which is precisely why it must be documented and tested rather than left implicit:
   every current writer of these columns produces a zero 4th decimal — `CashDrawerService::calculateExpectedCash`
   accumulates at `$this->scale()` (`CashDrawerService.php:48-51, 400-407`), and
   `ZSessionLifecycleProjection.php:254,263` stores the device-authored string verbatim with
   `variance = bcsub($counted, $expected, 4)` over two scale-3 inputs.

**Conditions:** (a) pin it — see the Important finding on untested rounding; (b) amend
`docs/architecture/precision-contract.md:17` so the code stops contradicting the doc.

### Q5 — per-report scale from `CompanyContext::getScaleSafe()` rather than per-row `companies.currency`

**RULING: acceptable for tenant #1, NOT acceptable as the permanent contract. CHANGES-REQUESTED at the comment level;
ticket for the code.**

- **Not launch-blocking.** Tenant #1 is a single TND company; `TrialBalanceService` is immune by construction because
  `ReportsController.php:317,336-341` passes `CompanyContext::requireCompanyId()` as the report's own `companyId`, so
  scale and subject can never disagree there.
- **But the failure mode is the ticket's own defect, relocated.** In a mixed-currency parent/child group,
  `cashRegisterReconciliation` cannot avoid the foreign-currency child (`ReportsController.php:184` forces
  `companyIds(null, …)` = root + all children, `OwnerReportScope.php:33-41`), and a TND child under a EUR root has its
  Z/EOD variance rendered at 2 dp. W-8 proved a tenant can hold TND + EUR companies.
- **The repo already has a better answer, three files away.** `OwnerSalesSummaryService.php:80-87` *refuses* the
  mixed-currency aggregate and derives the scale from the data. Picking the weaker pattern is defensible only if the
  reason is written down — and the reason that *is* written down (console reachability) is false (I2).
- **The cheap fix exists for 2 of 3 surfaces.** `salesByLocation` already joins `companies`
  (`SalesReportService.php:51`) and `reconciliationSummary` has `pos_terminals.company_id`
  (`CashRegisterReportService.php:36-39`); both are per-company-row and can call `getScale($row->currency)`.
  `topSkus` / `revenueByCategory` / `paymentMethodBreakdown` aggregate *across* companies, so a per-row currency is
  impossible there — those need the `OwnerSalesSummaryService` refusal, not a per-row scale.
- **Minimum before merge:** replace the false console justification with the true limitation, e.g. *"scale comes from the
  request's root company; a mixed-currency parent/child scope renders children at the root's scale — the underlying SUM
  is already cross-currency-meaningless (pre-existing); tracked in `<ticket>`"*, and file that ticket.

### Q6 — `varianceSeverity` `bccomp` at `$scale + 4`

**RULING: CORRECT and guard-conformant.**

- **Exactness:** `$comparisonScale = $scale + 4` (`CashRegisterReportService.php:108`) is strictly finer than both
  operands' storage scale — `pos_shifts.variance` `DECIMAL(16,4)` and `company_fraud_settings.cash_variance_*`
  `decimal(12,4)` — so every `bccomp` is exact and no threshold boundary can be tipped. For TND that is scale 7.
- **Semantic preservation:** `abs($variance) === 0.0` → `bccomp($variance,'0',$s) === 0`; `$absolute > $hard` →
  `bccomp(...) > 0`. Equality at a threshold still yields the *lower* severity, exactly as before. The
  `sign < 0 ? bcmul($variance,'-1',$s) : $variance` absolute-value form is correct, and bcmath normalises negative zero
  (executed: `bcround('-0.0004', 3) = '0.000'`, not `'-0.000'`), so no sign artefact leaks onto the Z surface.
- **Guard conformance:** `$comparisonScale` is a variable, and `ForbidHardcodedBcmathScale` fires only on a **literal**
  int scale argument inside `Application/Services/` or `Domain/Services/`
  (`app/PHPStan/Rules/ForbidHardcodedBcmathScale.php:44-49, 88-92, 113-120`). Note this directory **is** inside the
  rule's path filter, so the whole file is under the guard — and PHPStan is clean on it (verified). The pre-existing
  literal at `SalesReportService.php:310` keeps its `// precision-ok:` exemption.
- **Residual, not a regression:** the thresholds are compared against `|variance|` and assumed non-negative; the DB CHECK
  only enforces `over_soft < over_hard` (`…add_cash_variance_settings_to_company_fraud_settings.php:26`). Identical to
  the pre-diff float behaviour.
- Only nit: the docblock's `+4` rationale (see the Minor finding).

### Q7 — the PHPStan-guard extension trial

**CONFIRMED — the claim is right, and the follow-up ticket recommendation is right.**

`ForbidHardcodedBcmathScale` fires only inside `app/Modules/*/Application/Services/` and `app/Modules/*/Domain/Services/`
(`:113-120`) and only on a literal int scale arg (`:44-49, :88-92`). Adding `'number_format' => 1` to
`BCMATH_FUNCTIONS` would surface **exactly 3** errors, all in
`app/Modules/Taxation/Application/Services/CertificatePDFService.php:89-91, 94-96, 98-100`. Every other
`number_format` in `app/` is excluded for a checkable reason:

- `DocumentPdfService.php:256,264,301`, `ReceiptPdfService.php:200,208,261`, `ReportGenerationService.php:813,821,857` —
  in scope, but the scale arg is `$this->scale()` / `$decimals`, not a literal.
- `PointEarningService.php:113,130,235,281,360` — in scope, scale arg is `$scale + 4` (derived, not a literal).
- `PointsAmount.php:253`, `LoyaltyBalance.php:202` — `Domain/ValueObjects/`, outside the path filter.
- `DocumentAttachmentController.php:232-240` — `Presentation/Controllers/`, outside the path filter, and byte sizes
  rather than money.

**The 3 are real.** `CertificatePDFService.php:88` emits `'currency' => $certificate->currency` immediately above three
figures hardcoded to 3 dp — and each also does `(float)` on the bcmath string *and* `CurrencyScale::bcformat(…, 3)` with
a literal 3. A **EUR** withholding certificate therefore prints `1,234.560`. File the follow-up ticket; the guard
extension is worth shipping with it.

**Nothing in this lane's diff would trip the extended guard** — the only `number_format` token added anywhere in the
diff is inside the `FormatsReportNumbers` history docblock (`:19`).

---

## What to fix before merge

Replace the false "console contexts" justification in `SalesReportService.php:24-31` / `TrialBalanceService.php:81-86`
with the real mixed-currency limitation (+ ticket), and add the two rounding tests (`300.0005` → `300.001`,
`777.775` → `777.78`) that pin the Q4 ruling — everything else can ship as a follow-up ticket.

---
---

# ROUND 2 — narrow re-verification of `dc71099b8`

**Scope:** `git diff HEAD~1..HEAD` on `dc71099b8` ("address the L4 API/precision merge gate"). Re-gate limited to the
five items the coordinator asked about. Round-1 findings not re-litigated.

## VERDICT — **CLEAR TO MERGE**

No blockers. One documentation-coherence item (R2-1) that I'd like landed with the merge but which the new test net
already protects against; four Minors.

The consequential discovery is **real, correctly diagnosed, and correctly fixed** — and it was a genuine live bug, not
just a test-harness artefact. I reproduced the root cause independently:

```
value      OLD numericString (bcformat@4, TRUNCATES)   NEW (number_format@4, ROUNDS)
777.775 -> 777.7749  -> bcround@2 -> 777.77   (WRONG)  777.7750 -> bcround@2 -> 777.78  (right)
300.0005-> 300.0004  -> bcround@3 -> 300.000  (WRONG)  300.0005 -> bcround@3 -> 300.001 (right)
```

The float `777.775` is held as `777.77499999999998`, so truncating the binary expansion at the normalisation scale ate
the half **before** the currency rounding ever ran. My round-1 Minor 1 was indeed "with teeth": the money path was
truncating twice, and the second truncation silently defeated the Q4 ruling on every value the float branch touches.
Good catch by the implementer, and the right fix — `number_format` rounds and is scientific-notation safe.

## Re-verification performed

| Check | Result |
|---|---|
| `phpunit ReportNumberEmissionTest + TrialBalanceCurrencyScaleTest` | **OK — 15 tests, 63 assertions** (matches the claim) |
| `phpunit` accounting-report regression (7 files: the 2 above + OwnerReporting, TrialBalance, SalesReportServiceReturns, SalesReportServicePaymentBreakdown, CashMovementsReport) | **OK — 55 tests, 411 assertions** |
| `phpstan analyse app/Modules/Accounting/Application/Services/Reports` (16 files, level 8, live DB) | **No errors** |
| `pint --test` on the 5 services + 2 test files | **pass** |
| **Falsifiability, verified independently** (not by trusting the swap report) | all four new fixtures now DIFFER between the two helpers: `300.0005@3` → `300.001` vs `300.000`; `-0.5005@3` → `-0.501` vs `-0.500`; `299.4995@3` → `299.500` vs `299.499`; `777.775@2` → `777.78` vs `777.77`. **Genuinely falsifiable now.** |
| Float-branch fix is load-bearing | under the OLD normalisation, `300.0005` and `777.775` fail **with `bcround` intact** — confirming the tests were unfalsifiable-and-red as reported |

### 1. Is `number_format` at a fixed high scale correct for all magnitudes? — **yes, with a stated ceiling**

I swept `10^k + 0.1235` for k = 3..15, comparing the string path against the float path end-to-end:

| magnitude | float-branch intermediate | emitted @ scale 3 (string vs float) |
|---|---|---|
| 10^3 … 10^11 | identical to the string | **agree** |
| 10^12 | `…1237` vs string `…1235` (fabricated digit) | **still agree** (`…124`) — the currency rounding absorbs it |
| 10^13 and above | `…1230` / `…1250` | **DIVERGE** (`…123` / `…125` vs `…124`) |

So: `number_format` *can* fabricate an intermediate digit from ~1e12 (PHP's pre-rounding, not a plain round of the
double: the double is `999999999999.12353515625` yet `number_format(…,4)` returns `…1237`), and the **emitted** value
only diverges from ~1e13. That threshold is unreachable in practice for three independent reasons:

- **the float branch is dead in production** — PostgreSQL returns `numeric` columns and aggregates as strings, so the
  shipping path never converts at all (re-confirmed; this is exactly the asymmetry the implementer documented at
  `FormatsReportNumbers.php:100-113`);
- `pos_shifts.expected_cash|actual_cash|variance` are `DECIMAL(16,4)` → 12 integer digits → **|v| < 1e12** at rest;
- 1e13 TND is ~3 trillion EUR of receipts in one report window.

It is also **not a regression**: the old `bcformat($float, 4)` path went through `number_format($v, max(4,14))` first —
the same primitive — and was equally wrong at 1e13+, while being catastrophically wrong at the half boundary, which is
the case that actually occurs.

**`MONEY_NORMALISATION_SCALE = 4` is sane — in fact it is the minimal correct value.** It must be ≥ 3 (max currency
scale in `CurrencyScale::SCALE_MAP`), ≥ 4 (quantity storage), and ≥ 4 (`company_fraud_settings.cash_variance_*` is
`decimal(12,4)`, and these values flow into `varianceSeverity` through `numericString`). A *larger* value would be
strictly worse — it would retain more of the float's binary garbage. Well chosen, and the docblock's reason for naming
it locally rather than reusing `QuantityScale::SCALE` is correct.

### 2. Tests run — done, above. 15/15 and 55/411, both green.

### 3. `precision-contract.md` wording — display-only, **but incomplete**

The new paragraph is correctly scoped: it says presentation rounds, explicitly names the § Storage-tier line it
supersedes *for display*, and closes with "**Write/canonicalization boundaries are unchanged — they still truncate via
`bcformat`**, including the fiscal-hash canonicalization". That does **not** contradict rule 19's storage rules
(§ Storage tier `decimal(N,3)`/`(N,4)`, § Service tier "intermediates at scale+1/scale+4, round once at the write
boundary via `bcformat`" — all still true and untouched). See R2-1 and R2-5 for what it misses.

### 4. Anonymous-class trait test — **sound, not fragile**

`use FormatsReportNumbers { decimalString as public money; … }` is valid PHP 8 aliasing; the originals stay private.
I checked the coupling that would make it brittle: the trait has **zero** `$this->`-property access — its only `$this->`
references are to its own `numericString()` (`:67`, `:86`, `:96`). So the anon class cannot fatal on a missing
dependency. If a future edit pulls a collaborator into the trait the test fails loudly, which is the *desirable*
direction: it pins "this trait is dependency-free". A rename of `decimalString` also fails loudly. The only nit is
placement — it touches no database, so it could live in `tests/Unit` and skip `RefreshDatabase`.

### 5. Docblock claims — **all now accurate**, including the one flagged for verification

- `TrialBalanceService.php:79-97` "**SINGLE-company by construction**" — **verified true.**
  `ReportsController.php:317` resolves `CompanyContext::requireCompanyId()` and `:336-341` passes that same id as the
  report's `companyId`; `CompanyContext::getCompany()` (`:86-93`) is `Company::find($this->currentCompanyId)` — the
  same bound id the scale resolver reads. Scale and subject cannot disagree. Grep confirms `ReportsController` is still
  the only caller. The docblock's further claim that the `getScaleSafe()` fallback is "consequently unreachable on
  today's only caller … kept as the family-wide convention rather than as a claim that this path runs without a
  company" is honest and precise — that is the right way to keep the safe variant without asserting a fiction.
- `SalesReportService.php:24-45` — the false "console contexts" justification is gone; the replacement states the real
  root+every-child limitation, names the `OwnerSalesSummaryService::resolveCurrency()` divergence, and points at the
  ticket. Accurate.
- `CashRegisterReportService.php:59-68` (limitation) and `:94-108` (the `+4` rationale, now correctly attributed to the
  operands' storage scale with `QuantityScale` explicitly disclaimed) — accurate.
- `StockAlertReportService.php:11-22` — records the no-resolver-by-design decision and corrects the handoff claim.
  Accurate.
- Ticket `docs/superpowers/tickets/2026-08-05-l4-mixed-currency-report-scale.md` filed, covering round-1 findings 1/2/5.

## Round-2 findings

**[IMPORTANT — documentation only, not a merge blocker]
`docs/architecture/precision-contract.md:28` vs `FormatsReportNumbers.php:121-125`.** The contract's § Service tier
still lists **"Forbidden: `number_format((float)$v, …)`"**, and the new float branch is now literally
`number_format($value, self::MONEY_NORMALISATION_SCALE, '.', '')` under an `is_float($value)` guard. The code is
*right* — nothing is **cast**; the value arrives as a float from SQLite/PHP and `number_format` is the least-lossy,
scientific-notation-safe way into bcmath (and the superseded `CurrencyScale::bcformat` used the same primitive
internally) — but the § Emission & display paragraph only rules on `bcround` and says nothing about float
normalisation. The next precision sweep will grep `number_format` in `app/`, find this, and "fix" it straight back
into the `777.775 → 777.77` bug.
*Mitigation that makes this non-blocking:* the new
`test_sales_by_location_rounds_at_the_eur_currency_scale` runs through the SQLite **float** branch, so that exact
revert now fails loudly. *Fix:* two sentences in § Emission & display recording the `numericString` float-branch ruling
— must round, at a fixed normalisation scale, dead on PG / alive in SQLite tests, and why it is not the forbidden
cast-then-format pattern.

**[MINOR] `tests/Feature/Accounting/ReportNumberEmissionTest.php:283-311`** — the digit-for-digit
string-vs-float agreement assertions are true only below ~1e13 (see the sweep above). Worth one comment line stating
that domain of validity, so the test is not later read as a universal guarantee.

**[MINOR] `FormatsReportNumbers.php:123-125`** — the `/** @var numeric-string */` over `number_format(...)` is unsound
for `INF`/`NAN`, which return `"inf"`/`"nan"` (verified). Downstream `CurrencyScale::bcround`'s `is_numeric` guard
converts that into a clear `InvalidArgumentException` rather than a corrupt figure, and the old path threw a rawer
bcmath `ValueError`, so this is an improvement, not a regression. Recorded only.

**[MINOR — carry-over, pre-existing]** `numericString()`'s string branch guards with `is_numeric()`, which accepts
scientific notation; bcmath does not (verified: `bcround('1e5', 3)` → `ValueError: bcadd(): Argument #1 ($num1) is not
well-formed`). Unreachable from PG `numeric` columns, which never render in scientific notation. Unchanged by this
commit.

**[MINOR] `docs/architecture/precision-contract.md:17`** — § Storage tier still reads "Display truncates to
`getDecimals(currency)`" with no inline pointer to the paragraph that supersedes it. A reader who lands on the storage
tier first gets the stale rule. One parenthetical would close it.

## What to land with the merge

R2-1's two sentences in § Emission & display (the `numericString` float-branch ruling). Everything else in round 2 is
a comment-level nicety and can ride the already-filed ticket.
