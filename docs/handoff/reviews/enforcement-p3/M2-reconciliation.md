# enforcement-P3 — M2 (package 3(b)) — purpose reconciliation + per-country seeder completeness

**Lane:** `codex/enforcement-p3-money-lanes` · worktree `.worktrees/enforcement-p3`
**Milestone:** `p3-M2` — 3(b), per-country purpose completeness as a CONSUMER of `ProvisioningRequiredPurposesV1`
**Lenses:** `treasury`, `fiscal-pos`
**Authority:** `apps/api/app/Modules/CountryDefaults/Domain/Services/ProvisioningRequiredPurposesV1.php` — **consumed, never edited.**

M2 touched neither the manifest, nor `SystemAccountPurpose::requiredPurposes()`, nor any frozen country
seeder class body. Everything below is either a CI test keyed to the manifest, or a REPORTED finding
handed to the country-defaults lane / parent.

---

## 0. Correction to the dispatch's own numbers (read first)

Brief §4 3(b) describes the manifest as *"the complete 41-case partition (27 REQUIRED / 1 SCOPE-REQUIRED
/ 4 CONDITIONAL / 9 SOFT)"*. **The landed manifest is a 43-case partition: 28 REQUIRED / 1 SCOPE_REQUIRED
/ 4 CONDITIONAL / 10 SOFT**, self-enforced at
`ProvisioningRequiredPurposesV1::assertConforms()` (`:249-254`), which throws unless the counts are exactly
`28 + 1 + 4 + 10` and unless every `SystemAccountPurpose` case appears exactly once (43 cases).

The brief's prose is stale, not the manifest. M2 keys to the **landed manifest**, as instructed
("CONSUMES the manifest; never redefines it"). No action is requested — this is recorded so a later
reviewer does not read the count mismatch as drift introduced by P3.

The four partitions as landed:

| Class | N | Purposes |
|---|---|---|
| REQUIRED | 28 | bank, cash, cost_of_goods_sold, customer_advance, customer_receivable, general_expense, goods_received_not_invoiced, inventory, inventory_shrinkage_expense, marketing_goodwill_expense, opening_balance_equity, payment_tolerance_expense, payment_tolerance_income, pos_tender_clearing, product_revenue, purchase_expenses, purchase_price_variance_expense, purchase_price_variance_income, purchase_stamp_duty, rounding_loss_expense, sales_discount, sales_returns_clearing, service_revenue, supplier_advance, supplier_payable, vat_collected, vat_deductible, voucher_liability |
| SCOPE_REQUIRED | 1 | sales_stamp_duty_payable |
| CONDITIONAL | 4 | refund_write_off, sales_return, sales_rounding_difference_expense, sales_rounding_difference_income |
| SOFT | 10 | inventory_gain_income, meals_expense, office_expense, realized_fx_gain, realized_fx_loss, retained_earnings, travel_expense, uninvoiced_revenue, utilities_expense, voucher_breakage_income |

---

## 1. Reconciliation table (deliverable A)

Every **local required-purposes list** in production code — the two `TreasuryReceiptBridge` lists the
dispatch names, plus every other list surfaced by grepping `hasAccountForPurpose` callers and
`SystemAccountPurpose` array literals across `app/` — reconciled against the manifest's classification.

"Shape on miss" is what the live path actually does when the purpose is unmapped. It is the column that
matters: the manifest classifies a purpose by whether a **registered throwing** resolution site exists,
so a path that probes non-throwingly and degrades is classified by its *other* call sites, not by this one.

| # | Local list site | Purposes in the list | Shape on miss | Manifest classification | Converges? |
|---|---|---|---|---|---|
| 1 | `Treasury/Application/Projections/TreasuryReceiptBridge.php:446-455` (`postCashRoundingEntry`) | `ProductRevenue`; then `PaymentToleranceIncome` if rounded up else `PaymentToleranceExpense` | non-throwing probe → record operator alert, **skip the GL entry**, return; fail-CLOSED only if the alert itself cannot be persisted (`:565-577`) | all three **REQUIRED** | ✅ yes — see D-1 on the gate shape |
| 2 | `TreasuryReceiptBridge.php:528-531` (`postToleranceWriteoffEntry`) | `ProductRevenue`, `PaymentToleranceExpense` | same as #1 | both **REQUIRED** | ✅ yes — see D-1 |
| 3 | `Fiscal/Application/Services/RefundCompensationService.php:187` | `RefundWriteOff`, `SalesReturn` | throws `RefundCompensationRefusedException(reason: 'missing_account_purpose')` — a domain precheck ahead of the transaction | both **CONDITIONAL**, gate `DOMAIN_PRECHECK_4XX`, evidence cites this exact site (`:187`) | ✅ exact match |
| 4 | `Fiscal/Infrastructure/Commands/EnableV4RefundAuthoringCommand.php:121` | `RefundWriteOff`, `SalesReturn` | collects missing, command returns `FAILURE` — refuses to enable v4 refund authoring | both **CONDITIONAL** | ✅ consistent (console mirror of #3; correctly unregistered — not a throwing resolution site) |
| 5 | `Inventory/Application/Services/InventoryGlPostingService.php:49-54` | `Inventory`; then `InventoryGainIncome` if direction `in` else `InventoryShrinkageExpense` | `Log::warning` and **return null** — fail-soft, no journal entry | `Inventory` **REQUIRED**, `InventoryShrinkageExpense` **REQUIRED**, `InventoryGainIncome` **SOFT** | ✅ yes — the SOFT entry's own evidence string names this exact behaviour ("Count-correction GL posting fail-softs when unmapped; no CountCorrection producer until T21") |
| 6 | `Treasury/Application/Services/RepositoryAdjustmentService.php:79-83` | `PaymentToleranceExpense` if direction `Out` else `PaymentToleranceIncome` | throws `AdjustmentToleranceAccountMissingException` (typed refusal before the transaction) | both **REQUIRED**; the manifest's evidence for both cites the downstream `GeneralLedgerService::createRepositoryAdjustmentJournalEntry` `:1241/:1242` this path feeds | ✅ yes |
| 7 | `Accounting/Infrastructure/Commands/BackfillRefundCompensationAccountsCommand.php:144,196` | `SalesReturn`, `RefundWriteOff` | backfill/idempotency probe — skips an already-mapped purpose | both **CONDITIONAL** | ✅ N/A — remediation tool, not a resolve-or-fail path |
| 8 | `Console/Commands/BackfillTolerancePurposesCommand.php` | `PaymentToleranceExpense`, `PaymentToleranceIncome` | backfill; promotes/creates, hard-fails only on a missing parent | both **REQUIRED** | ✅ N/A — remediation tool |

**Result: zero misclassifications.** No purpose that a live path resolves-or-fails on is classified
differently by the manifest, and no such purpose is omitted from it. The manifest's AST ratchet
(`registeredThrowingCallSites()`) independently carries the bridge's downstream GL writers —
`GeneralLedgerService.php:3747/3749` (`createPosCashRoundingEntry`) and `:3925/3926`
(`createPosToleranceWriteoffEntry`) — so the bridge's purposes are represented on the throwing side too.

Two observations that are **not** misclassifications but which the country-defaults lane and the parent
should hold, are raised as D-1 and D-2 below.

---

## 2. Reported findings (routed to the country-defaults lane / parent — NOT fixed here)

### D-1 — a REQUIRED purpose does not mean "resolve-or-fail" on every path: the bridge's unmodeled fourth gate shape

`ProvisioningRequiredPurposesV1::allowedGateKinds()` (`:23-26`) admits exactly two gate kinds,
`MODULE_GATE` and `DOMAIN_PRECHECK_4XX`, and `assertConforms()` attaches a gate only to CONDITIONAL
entries. `TreasuryReceiptBridge` rows #1 and #2 use a **third shape that the schema cannot express**:
a non-throwing precheck on a Horizon worker that, on a miss, **persists an operator alert and silently
skips the GL entry while the projection completes successfully**. There is no 4xx — there is no HTTP
request — and it is not a module gate.

This is schema-legal today only because the purposes involved (`ProductRevenue`,
`PaymentToleranceExpense`, `PaymentToleranceIncome`) are REQUIRED, and REQUIRED entries carry
`gate_kind: null`. So nothing throws. But the consequence is worth stating plainly:

> On the POS cash-rounding and tolerance-writeoff bridge paths, an unmapped REQUIRED purpose does not
> fail the receipt and does not fail the job. It produces a receipt that projects as fully successful
> with a missing journal entry, and an alert row as the only durable signal.

This is materially the same incident class as P3-M1's R-11 (a reachable synchronous swallow inside the
fiscal projection path), and it belongs with that cluster. **Recommendation for the country-defaults
lane:** either add a third allowed gate kind (e.g. `PROJECTION_PRECHECK_ALERT`) so the shape is
modelled and reviewable, or record explicitly that REQUIRED carries no uniform failure semantics.
**Recommendation for the parent:** fold D-1 into the fiscal projection-discipline family alongside R-11
rather than ruling on it piecemeal. **P3 proposes; it does not change the manifest.**

### D-2 — `requiredPurposes()` is a strict 14-of-28 subset of the manifest's REQUIRED set (the F-4 surface)

Computed from the landed code, not by hand:

- `SystemAccountPurpose::requiredPurposes()` — **14** purposes. This is what gates
  `ChartOfAccountsService::validateCompanyAccounts()` (`:82`), i.e. what **existing, live tenants** are
  measured against, and what `AccountPurposeController:70` surfaces to operators.
- Manifest REQUIRED — **28** purposes.
- `requiredPurposes()` ⊂ manifest REQUIRED, strictly. **Zero** purposes are in `requiredPurposes()` but
  not manifest-REQUIRED. **Fourteen** are manifest-REQUIRED but absent from `requiredPurposes()`:

  `goods_received_not_invoiced`, **`inventory`**, `marketing_goodwill_expense`,
  `payment_tolerance_expense`, `payment_tolerance_income`, `pos_tender_clearing`, `purchase_expenses`,
  `purchase_price_variance_expense`, `purchase_price_variance_income`, `purchase_stamp_duty`,
  `rounding_loss_expense`, `sales_discount`, `sales_returns_clearing`, `voucher_liability`

**What this means operationally.** A brownfield tenant missing any of these 14 reports
`validateCompanyAccounts() => valid: true` — a clean bill of health — and then hits a hard runtime
failure the first time the corresponding path runs. `inventory` is the sharpest instance: it is resolved
via `findByPurposeOrFail` at GR/IR (`GeneralLedgerService:1897`), supplier-invoice clearing (`:2054`),
cost capitalization (`:4339`), inventory movement (`:4591`) and write-off (`:4815`), and by
`OpeningBalancePostingService:229` / `ResetOpeningBalanceService:152`. A tenant without it passes
validation and then cannot receive goods.

**This is exactly the F-4 owner gate and P3 does NOT close it.** Widening `requiredPurposes()` would
tighten validation for tenants that are already live, which needs a per-purpose backfill/seed story
(`BackfillChartPurposesCommand` exists; pushing to `origin/dev` auto-deploys migrations to staging
including `tenants:migrate`, so anything with a manual prerequisite must be self-guarding). It is a
**cross-lane PROPOSAL routed through the country-defaults authority**, never a unilateral P3 change.

**Scoping note that bounds the exposure:** M2's new CI test proves all three seeded charts (TN, FR,
Generic) map all 28 REQUIRED purposes with correct types today. So **newly provisioned tenants are not
exposed** — the gap is brownfield-only, which is precisely why the remedy is a backfill decision rather
than a seeder fix.

---

## 3. Per-country seeder completeness CI test (deliverable B)

**File:** `apps/api/tests/Feature/Accounting/SeededChartManifestRequiredPurposeCompletenessTest.php`

### Countries enumerated (not assumed)

Enumerated from the provisioning dispatch itself, `ChartOfAccountsService::getSeederForCountry()`
(`:172-178`) — a three-arm match, and there is **no** MA/DZ/UK/IT/DE chart:

| Country code exercised | Seeder reached | Notes |
|---|---|---|
| `TN` | `Database\Seeders\TunisiaChartOfAccountsSeeder` | frozen class body |
| `FR` | `Database\Seeders\FranceChartOfAccountsSeeder` | frozen class body |
| `XX` | `Database\Seeders\GenericChartOfAccountsSeeder` | the `default` arm — `XX` is not a fourth chart, it is an unassigned code that routes to the generic international chart every non-TN/FR country receives |

`ChartOfAccountsService::getSupportedCountries()` returns `['TN', 'FR']`;
`StaticCountryCatalogProvider`'s ~250 ISO codes are a catalog of assignable countries, not seeded charts.

### Which provisioning arm this covers, and why that is the uncovered one

`seedForCompany()` branches on `config('country_defaults.provisioning_enabled')` (default `false`):

- the **TEMPLATE** arm was already manifest-gated — `TemplatePublishingService:255-315` enforces the
  manifest's REQUIRED set *and* `$account->type === $purpose->expectedAccountType()` at publish, covered
  by `tests/Feature/CountryDefaults/CertifiedFixtureDeltaTest.php` and
  `tests/Feature/CountryDefaults/TemplatePublishGateTest.php`;
- the **LEGACY FROZEN-SEEDER** arm — the config default, and what actually provisions today — had
  neither guarantee. **That is the gap M2 closes.** The test pins
  `provisioning_enabled => false` in `setUp()` so it cannot silently drift onto the template arm.

### Scope asymmetry between the two arms — the one dimension this gate structurally cannot see

Deliverable 2 is scoped by the brief to the manifest's **REQUIRED** classification, so M2's legacy-arm
gate enforces REQUIRED and nothing else. The template arm is broader: `TemplatePublishingService`
(`:309-317`) additionally enforces the **SCOPE_REQUIRED** purpose for timbre countries. The asymmetry
that follows is worth stating plainly:

> A future edit dropping `SalesStampDutyPayable` from the Tunisian chart would be caught on the
> **template** arm and **not** on the legacy one — even though `GeneralLedgerService.php:295` resolves
> that purpose through the **throwing** `getAccountByPurpose()` on the TN credit-note path.

**This is not a live gap and not a scope violation.** `TunisiaChartOfAccountsSeeder.php:210` maps the
purpose today, and SCOPE_REQUIRED is outside deliverable 2's scope by the brief's own wording — M2
enforcing it unilaterally would be the lane widening its own mandate. It is recorded here because it is
the single classification the legacy-arm gate cannot observe, so a later reader does not mistake "the
legacy arm is gated" for "the legacy arm is gated on everything the template arm is". Closing it is a
scope decision for the country-defaults lane, not a defect in this guard.

### What it asserts, and how it differs from what already existed

For each of TN / FR / XX, for each of the **28 manifest-REQUIRED** purposes: an account is mapped, **and**
that account's `type` equals `SystemAccountPurpose::expectedAccountType()`.

It is keyed to `ProvisioningRequiredPurposesV1::entries()` and calls `assertConforms()` first, so a
drifted manifest fails with the manifest's own message instead of silently shrinking the enforced set.
It never reads `requiredPurposes()` — per the country-defaults lane's D-6 ruling that `requiredPurposes()`
is not the operational manifest and conformance must not key off it.

Two seeder-side tests already existed; neither covers this:

| Existing test | Keyed to | Checks type? |
|---|---|---|
| `ChartOfAccountsPurposeParityTest::test_every_country_chart_seeds_every_purpose_except_its_documented_exemptions` | `SystemAccountPurpose::cases()` minus a hand-maintained per-country exemption list | **no** |
| `ChartOfAccountsServiceTest::test_every_country_seeder_satisfies_all_required_system_purposes` | `requiredPurposes()` (the 14) — the very list D-6 forbids for conformance | **no** |

**No existing test asserts, for any seeded country chart, that `accounts.type` matches
`expectedAccountType()`.** That dimension is the substantive addition: a type-mismatched mapping resolves
without error and then posts the leg to the wrong side of the balance sheet.

---

## 4. Tamper tests + red-first evidence (deliverable C)

Per the dispatch's working rules ("for scanners/ratchets the failing test is the fixture-based tamper
case"), the guard carries two tamper cases. Both drive the **same** private assertion body the main test
uses, so they prove that body discriminates rather than exercising a parallel copy.

1. `test_a_chart_missing_one_required_purpose_fails_and_names_the_country_and_the_purpose` — nulls the
   `cost_of_goods_sold` mapping on a seeded **FR** fixture chart and asserts the failure message contains
   both `Country FR` and `cost_of_goods_sold`.
2. `test_a_required_purpose_mapped_to_the_wrong_account_type_fails_and_names_both_types` — leaves
   `customer_receivable` mapped on a seeded **TN** chart but moves it onto a Revenue account **that
   carries no `system_purpose`**, and asserts the failure names the country, the purpose, and
   `expectedAccountType`.

   The destination account's being *unmapped* is load-bearing. Re-pointing onto an account that already
   holds a purpose — the `ProductRevenue` account, as round 0 did — **vacates** that purpose as a side
   effect, because `accounts_company_purpose_unique` is `UNIQUE(company_id, system_purpose)`. That
   injects a second, unrelated defect (a *missing* mapping) alongside the intended type mismatch, and
   which one the gate reports then depends on the order of `ProvisioningRequiredPurposesV1::entries()`
   — order that `assertConforms()` does not pin, since it pins counts and uniqueness only. Round 1
   finding 3. Using an unmapped account keeps the chart's only defect the type mismatch itself.

### Red-first mutation proof that the type assertion is load-bearing

The completeness assertion is green at base (all three charts are complete), so a green run proves
nothing on its own. The type assertion was therefore **deleted from the shared assertion body** and the
class re-run.

**Round 0's mutant did not prove what it claimed, and the claim is withdrawn** (round 1 finding 2).
Because round-0 tamper 2 re-pointed onto the already-mapped `ProductRevenue` account, it vacated
`product_revenue`. With the type assertion removed, the loop reached `customer_receivable` (still
mapped, so it passed) and then died on `product_revenue` being **missing** — a collateral fixture
side-effect, not a type mismatch. The 108-assertion mutant was therefore not evidence for the type
assertion at all, and should not have been presented as such.

After finding 3's de-brittling (tamper 2 now re-points onto an *unmapped* Revenue account, so the chart's
only defect is the type mismatch), the mutation was re-run and is now **genuine**: with the type
assertion gone, nothing in the chart fails, the loop completes clean, and tamper 2 falls through to its
`$this->fail()`. That failure message is reachable only if the type assertion is what catches a type
mismatch:

```
--- MUTANT RUN v2 (type assertion removed; tamper 2 de-brittled) ---
..F                                                                 3 / 3 (100%)

1) Tests\Feature\Accounting\SeededChartManifestRequiredPurposeCompletenessTest::test_a_required_purpose_mapped_to_the_wrong_account_type_fails_and_names_both_types
A type-mismatched REQUIRED purpose mapping did not fail the completeness gate.

.../tests/Feature/Accounting/SeededChartManifestRequiredPurposeCompletenessTest.php:274

FAILURES!
Tests: 3, Assertions: 127, Failures: 1.
```

Independently of the mutation, the invariant also holds without any mutant at all: unmutated tamper 2
asserts the failure message contains `expectedAccountType`, a string **only** the type assertion emits,
and the class is green. The mutation was reverted and the class re-run green (§7).

---

## 5. CI wiring (deliverable D)

**Wired lane: `treasury-spine-pgsql` / group `Accounting` — a whole-directory lane that runs on PR→dev.**
No `ci.yml` edit was required.

The dispatch anticipated placing the class in `tests/Feature/CountryDefaults/` and, because that group is
**deferred**, wiring it class-by-class into the `backend-test-pgsql` `--filter` allowlist while raising
the `CountryDefaults` ceiling 28→29 and `debt_ceiling` 1131→1132. Reading P2's landed manifest showed a
strictly better option the dispatch did not have:

| | `tests/Feature/CountryDefaults` (dispatch's assumption) | `tests/Feature/Accounting` (chosen) |
|---|---|---|
| Manifest disposition | `deferred: true`, ceiling 28 | `lane: treasury-spine-pgsql/feature-accounting` |
| Selector | none — no lane runs the directory | `./vendor/bin/phpunit tests/Feature/Accounting`, `runs_on_pr_dev: true` |
| How the new class runs | only as a class-level `--filter` entry | picked up automatically by the directory selector |
| Coverage debt | +1 (and the global ceiling is at **zero slack**: 1131 deferred / `debt_ceiling` 1131) | **+0** |
| `ci.yml` touched | yes | **no** |

Four reasons the `Accounting` placement is the stronger read of the brief's actual rule — *"wire it into a
CI lane that ACTUALLY RUNS — worst case, the pgsql allowlist"*:

1. A **whole-directory lane is strictly stronger than the allowlist**, which the brief itself calls the
   worst case. A `--filter` entry is class-level and does not make the group laned (P2's checker,
   `:288-314`); a directory selector picks up this class and every future sibling.
2. **Zero coverage-debt growth.** The deferred total is exactly at `debt_ceiling` (1131/1131), so the
   `CountryDefaults` route would have required raising the global debt ceiling to add a guard — moving the
   P2 ratchet in the loosening direction to land a P3 guard.
3. **Semantic fit and precedent.** The subject under test is the legacy chart seeders dispatched by
   `ChartOfAccountsService` (an Accounting concern). Its two closest siblings —
   `ChartOfAccountsPurposeParityTest`, `ChartOfAccountsServiceTest` — already live in
   `tests/Feature/Accounting`.
4. **No `.github/workflows/**` touch**, so M2 does not by itself trigger the `pre_promotion_ci_dispatch`
   obligation that `enforcement-p3.progress.yaml:92-104` attaches to any accepted branch touching the
   workflows. Promotion stays simpler.

The lane runs on **PostgreSQL** (job-level `DB_CONNECTION: pgsql`, `ci.yml:1048`), so the class was
verified against a real Postgres as well as the sqlite fast loop (§7).

**Manifest edit made:** `apps/api/tests/feature-lane-manifest.json`, the `Accounting` group's
informational `classes` count 81 → **83**, with the note updated to say why. 83 is the real count at the
P3 tip: 81 at the 2026-08-21 accepted tip, plus **two** additions to this directory in this package —
`ChokepointUnbalancedGuardTest` (M1) and `SeededChartManifestRequiredPurposeCompletenessTest` (M2).
Round 0 wrote `82`, counting only M2's own file; that was false at HEAD and is corrected here (round 1
finding 1). For **laned** groups this field
is documentation only — P2's checker enforces ceilings for deferred/excluded groups exclusively
(`:336-352`) — so this is a truthfulness edit, not a ratchet change. `debt_ceiling` is **untouched at
1131**.

---

## 6. F-4 discipline (deliverable E)

M2 tightens **nothing** for existing tenants.

- The new test gates **seeder/chart-template completeness for new-tenant provisioning**. That boundary is
  stated explicitly in the class docblock so a later reader cannot mistake it for live-tenant validation.
- `requiredPurposes()` and `ChartOfAccountsService::validateCompanyAccounts()` — the live-tenant surface —
  are **byte-unchanged**.
- The one place where a genuine tightening proposal exists (**D-2**, the 14-purpose gap) is **reported,
  not implemented**, and is routed to the country-defaults authority as an owner gate with its backfill
  obligation spelled out.
- The completeness test is green at base, so it does not fail any currently seeded chart, and it makes no
  claim about charts already in the field.

---

## 7. Acceptance evidence

All commands run from `apps/api` in the worktree. Selected-test counts are **nonzero** in every run.

### 7.1 Completeness + tamper tests — sqlite fast loop

```
$ ./vendor/bin/phpunit tests/Feature/Accounting/SeededChartManifestRequiredPurposeCompletenessTest.php
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.15
Configuration: .../apps/api/phpunit.xml

...                                                                 3 / 3 (100%)

Time: 00:03.926, Memory: 149.00 MB

OK (3 tests, 200 assertions)
```

### 7.2 Same class on real PostgreSQL (the lane's actual engine)

Scratch DB `p3m2_test` created on the local Homebrew instance; `autoerp_test` untouched.

```
$ DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=p3m2_test \
  DB_CENTRAL_DATABASE=p3m2_test DB_USERNAME=houssamr DB_PASSWORD= \
  ./vendor/bin/phpunit tests/Feature/Accounting/SeededChartManifestRequiredPurposeCompletenessTest.php

...                                                                 3 / 3 (100%)

Time: 00:05.548, Memory: 149.00 MB

OK (3 tests, 200 assertions)
```

### 7.3 Red-first mutation

See §4 — mutant **v2** (post-de-brittling): 3 tests, 127 assertions, 1 failure with the type assertion
removed, failing on tamper 2's own `$this->fail()`; reverted and re-run green. Round 0's 108-assertion
mutant is **withdrawn** as evidence — it died on a collateral missing mapping, not on a type mismatch.

### 7.4 P2 feature-lane manifest checker — exit 0, debt unchanged

```
$ php tools/feature-lane-manifest-check.php
tests/Feature lane manifest OK — 1350 Feature classes in 74 groups; every group has a disposition;
every declared lane is present in ci.yml; every --filter entry is anchored and uniquely matched
against 1734 test classes across all suites.
  ⚠ COVERAGE DEBT: 71 group(s) / 1131 class(es) sit in groups that NO CI lane runs as a whole, ...
EXIT=0
```

`1131` is unchanged from base — the new class added zero coverage debt.

### 7.5 Checker liveness suite

```
$ ./vendor/bin/phpunit tests/Architecture/FeatureLaneManifestCheckerTest.php
..............................................                    46 / 46 (100%)

OK (46 tests, 123 assertions)
```

### 7.6 Whole `Accounting` lane directory (the selector CI will actually run)

```
$ ./vendor/bin/phpunit tests/Feature/Accounting
...
ERRORS!
Tests: 750, Assertions: 3440, Errors: 5, PHPUnit Deprecations: 1, Skipped: 8.
```

The lane's 750 tests include M2's 3. **The 5 errors are PRE-EXISTING and are not M2's** — all five are
`InvoiceAndCreditNoteGLIntegrationTest::test_posting_invoice_automatically_creates_complete_gl_entries`,
`…_net_to_zero`, `…_proportional_gl_reversal`, `…_independently_of_gl_creation`,
`…_separate_gl_entries`, each raising
`App\Modules\Document\Domain\Exceptions\DeliveryRequiredBeforeInvoiceException` out of
`DocumentPostingService.php:649` — i.e. the delivery-before-invoice rule from the
document-per-action / DN lane, unrelated to chart purposes.

**What the file-removal experiment actually demonstrates — stated precisely** (round 1 finding 4). The M2
class was moved out of the tree and the failing class re-run on its own, reproducing **the identical 5
errors**:

```
$ # (M2 test file temporarily removed from tests/Feature/Accounting/)
$ ./vendor/bin/phpunit tests/Feature/Accounting/InvoiceAndCreditNoteGLIntegrationTest.php
ERRORS!
Tests: 8, Assertions: 32, Errors: 5.
```

That experiment proves **"not M2's"** — it does not by itself prove "red at base", since the branch also
carries M1's `GeneralLedgerService` edits and exception split. Base attribution is established
**separately**, by inspection of the range rather than by this run: nothing in `base..HEAD` touches
`DocumentPostingService.php:649` or the delivery-before-invoice rule, and M1's only edit to a conversion
path (`SalesOrderToInvoiceConverter`) is comment-only. The two together give the conclusion; the removal
experiment alone does not.

⚠️ **Flagged for the parent's red-gate reconciliation, not owned by P3.** The `treasury-spine-pgsql`
lane is the one M2 wires into, and it is already red at base on this class. M2 neither introduced nor
worsened it, and rule 4 forbids fixing it here — but the parent should know that landing M2 does not by
itself make that lane green.

### 7.7 Pint + PHPStan L8 on touched files

```
$ ./vendor/bin/pint --test tests/Feature/Accounting/SeededChartManifestRequiredPurposeCompletenessTest.php
{"result":"pass"}

$ ./vendor/bin/phpstan analyse tests/Feature/Accounting/SeededChartManifestRequiredPurposeCompletenessTest.php --level=8 --no-progress
 [OK] No errors
```

`feature-lane-manifest.json` is JSON — not in Pint/PHPStan scope; validated by the checker in §7.4.

---

## 8. Files touched

| File | Change |
|---|---|
| `apps/api/tests/Feature/Accounting/SeededChartManifestRequiredPurposeCompletenessTest.php` | **new** — completeness + 2 tamper cases |
| `apps/api/tests/feature-lane-manifest.json` | `Accounting.classes` 81 → 83 + note (informational for laned groups) |
| `docs/handoff/reviews/enforcement-p3/M2-reconciliation.md` | **new** — this document |

Explicitly **not** touched: `ProvisioningRequiredPurposesV1.php`, `SystemAccountPurpose::requiredPurposes()`,
the three frozen country seeder class bodies, `ChartOfAccountsService::validateCompanyAccounts()`,
`.github/workflows/ci.yml`, `debt_ceiling`, `scripts/adversarial-review*.sh`, the dispatch brief,
`SELF-REVIEW-HARNESS.md`, `enforcement-control-manifest.yaml`, `.claude/agents/*`.

---

## 9. Deviations register

| # | Deviation | Rationale |
|---|---|---|
| **M2-D1** | **Test placed in `tests/Feature/Accounting/`, not `tests/Feature/CountryDefaults/`; no `ci.yml` allowlist edit; no ceiling/`debt_ceiling` raise.** | The dispatch's preferred route presumed the class would sit in a deferred group. `Accounting` is a **laned** group whose selector runs the whole directory on PR→dev, which satisfies the brief's actual rule ("a CI lane that ACTUALLY RUNS") strictly better than the allowlist it called the worst case — at zero coverage debt, against a global ceiling that has zero slack. Full comparison in §5. |
| **M2-D2** | **Manifest partition is 28/1/4/10 (43 cases), not the brief's 27/1/4/9 (41).** | The landed manifest self-enforces 28/1/4/10 at `assertConforms():249`. Keyed to the landed authority per "CONSUMES the manifest; never redefines it". Recorded in §0 so the count mismatch is not later read as P3-introduced drift. |
| **M2-D3** | **`feature-lane-manifest.json` edited** (a P2-owned artifact). | One informational integer + its note, for a **laned** group where the checker enforces no ceiling. Required for documentation truth once a class is added. Corrected at round 1 to **83**, the real count at the P3 tip (round 0's `82` counted only M2's own file and omitted M1's `ChokepointUnbalancedGuardTest`). `debt_ceiling` untouched at 1131; checker and its liveness suite both re-run green (§7.4, §7.5). |
| **M2-D4** | **Tests run on the default sqlite `phpunit.xml` env, plus a PG cross-check** — no dedicated PG-only test env was built. | The existing chart-seeder conformance suite runs on the sqlite fast loop and the baseline was verified green there before any change. Because the wired lane is PG, the class was additionally run against scratch DB `p3m2_test` (§7.2). `autoerp_test` was never touched. |
| **M2-D5** | **D-1 and D-2 reported, not fixed.** | Both are cross-lane changes to authority the P3 lane does not own — the manifest's gate-kind schema and the live-tenant validation set. The dispatch requires exactly this (deliverable A: "a REPORTED finding … NEVER a unilateral manifest edit"; deliverable E: F-4 is an owner gate). |

---

## 10. Handback status

- Deliverable A (reconciliation) — **done**, §1, zero misclassifications, two reported findings.
- Deliverable B (per-country completeness CI test) — **done**, §3, 3 countries × 28 REQUIRED purposes,
  existence + type, manifest-keyed.
- Deliverable C (tamper test) — **done**, §4, two axes (tamper 2 de-brittled at round 1) plus a valid
  red-first mutation proof (mutant v2).
- Deliverable D (CI wiring) — **done**, §5, laned on `treasury-spine-pgsql`, checker exit 0, debt unchanged.
- Deliverable E (F-4 discipline) — **done**, §6, nothing tightened for live tenants; D-2 raised as the gate.

**Blocked on nothing.** D-1 and D-2 are owed *rulings*, not owed work from this lane.

---

## 11. Round-1 fix record

Verdict of record: `docs/handoff/reviews/enforcement-p3/M2-round1.md` — CHANGES-REQUIRED, five findings,
all documentation-truth or brittleness; the guard itself was accepted as "sound, non-vacuous, correctly
manifest-keyed, and genuinely CI-gated". All five are fixed.

| Finding | Pri | Fix |
|---|---|---|
| **1** — `Accounting.classes: 82` false at HEAD; real count 83 | P2 | Corrected to **83** in `feature-lane-manifest.json` with the note naming BOTH additions to that directory in this package (M1's `ChokepointUnbalancedGuardTest`, M2's own class). Round 0 counted only its own file. §5, §8, M2-D3. |
| **2** — red-first mutant died on a collateral missing mapping, not the type assertion | P2 | Round 0's 108-assertion mutant is **explicitly withdrawn** as evidence in §4, with the mechanism spelled out (re-pointing vacated `product_revenue` via `UNIQUE(company_id, system_purpose)`). Replaced by mutant **v2**, valid because finding 3's fix removes the collateral defect: with the type assertion gone the loop completes clean and tamper 2 falls through to its own `$this->fail()`. The independent non-mutation argument (the `expectedAccountType` string is emitted only by the type assertion) is stated alongside. |
| **3** — tamper 2 coupled to `entries()` order | P3 | Tamper 2 now re-points `customer_receivable` onto a Revenue account carrying **no** `system_purpose`, so the chart's only defect is the type mismatch and the case is order-independent. Guarded by an `assertNotNull` on the destination account with an actionable message, and the reasoning is in the method docblock so the coupling cannot be reintroduced silently. |
| **4** — pre-existing-red proof overclaimed | P3 | §7.6 now says what the file-removal experiment actually demonstrates (**"not M2's"**) and attributes "red at base" **separately**, by range inspection: nothing in `base..HEAD` touches `DocumentPostingService.php:649` or the delivery-before-invoice rule, and M1's `SalesOrderToInvoiceConverter` edit is comment-only. |
| **5** — scope asymmetry between the two arms | P3 | New subsection in §3: the legacy-arm gate enforces REQUIRED only, so a future drop of `SalesStampDutyPayable` from the TN chart is caught on the template arm (`TemplatePublishingService:309-317`) but not the legacy one, even though `GeneralLedgerService.php:295` resolves it through the throwing `getAccountByPurpose()`. Verified **not a live gap** — `TunisiaChartOfAccountsSeeder.php:210` maps it, and FR/Generic map it zero times, consistent with its SCOPE_REQUIRED classification — and **not a scope violation**, since the brief scopes deliverable 2 to REQUIRED. |

**No change to any finding's substance:** the guard, the country enumeration, the reconciliation table,
D-1 and D-2 all stand as delivered. Findings 1, 2 and 4 corrected claims *about* the work; finding 3 was
the only code change; finding 5 added a boundary note.

### Round-1 re-verification

| Check | Result |
|---|---|
| `phpunit …SeededChartManifestRequiredPurposeCompletenessTest.php` (sqlite) | **OK (3 tests, 200 assertions)** |
| same, real PostgreSQL (`p3m2_test`) | **OK (3 tests, 200 assertions)** |
| mutant v2 (type assertion removed) | **3 tests, 127 assertions, 1 failure** — tamper 2's own `$this->fail()`; reverted |
| `php tools/feature-lane-manifest-check.php` | **EXIT=0**, coverage debt **1131** unchanged |
| `phpunit tests/Architecture/FeatureLaneManifestCheckerTest.php` | **OK (46 tests, 123 assertions)** |
| `pint --test` on the touched test | **pass** |
| `phpstan analyse … --level=8` | **[OK] No errors** |

