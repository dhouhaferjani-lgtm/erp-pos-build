# Pre-existing Test-Failure Backlog — 2026-07-04

> Purpose: so an autonomous fleet can treat "green = green". These are the
> **known, pre-existing** red spots on `dev`@`8cb507faa`. Until each is either
> fixed or explicitly quarantined, a naive full run is red for reasons unrelated
> to the change under test. Triaged by **reading** the tests + code — **not** by
> running them (full PHPUnit suite is forbidden on the owner's laptop).
>
> All cites are into the main worktree
> `/Users/houssamr/Projects/syneriva/apps/erp` (branch `factory/audit-agent-bench`
> @ `8cb507faa` = real `dev` tip).

Legend: **REAL BUG** = product defect, tests are correct. **TEST-DEBT** = test
harness/isolation/fixture issue, product likely fine. Owner: **quick fix** =
small, do-it-now; **VPS queue** = run/verify on the VPS where the full suite is
allowed.

---

## 1. `RecordCustomerDepositTest` — CustomerAdvance GL line missing → **REAL BUG**

**Test:** `apps/api/tests/Feature/Partner/RecordCustomerDepositTest.php`
**Failing assertion:** `test_post_records_a_pure_advance_deposit_end_to_end_and_lists_it`,
lines 173–179 — expects a `journal_lines` row on the `CustomerAdvance` account,
partner-scoped, with `credit == 120`; asserts `assertNotNull($advanceCredit)`.

**Service under test:** `apps/api/app/Modules/Partner/Application/Services/RecordCustomerDepositService.php`
(authors the `DEPOSIT_RECEIPT` fiscal event, seeds + runs projections
synchronously). The GL leg is not written here — it is delegated down the
projection pipeline:

- `RecordCustomerDepositService::record()` → `FiscalEventProjectionDispatcher`
  → `TreasuryDepositBridge`
  (`apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php`)
  → `PaymentAllocationService::applyAllocationFromCommand()`
  (`apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php`).

**Suspected root cause — `account_id` vs `gl_account_id` divergence.** The
pure-advance overflow posts its GL entry only inside this guard
(`PaymentAllocationService.php:324`):

```php
if (bccomp($excessAmount, '0', 4) > 0 && $payment->repository && $payment->repository->gl_account_id) {
    if ($actor instanceof User) {
        $advanceEntry = $this->glService->createCustomerAdvanceJournalEntry(
            ...
            paymentMethodAccountId: $payment->repository->gl_account_id,
```

`PaymentRepository` has **two** distinct account columns
(`apps/api/app/Modules/Treasury/Domain/PaymentRepository.php:38-39, 77-78`;
the `account()` relation binds `gl_account_id` at `:117`):

- `account_id` (nullable)
- `gl_account_id` (nullable)

The test seeds the repository with **`account_id` only**
(`RecordCustomerDepositTest.php:312-319` →
`'account_id' => $cashAccount->id`), leaving `gl_account_id` **null**. So the
guard at `:324` is false, `createCustomerAdvanceJournalEntry` is never called,
and no CustomerAdvance journal line exists → `assertNotNull` fails.

This is a genuine product hole, not just a fixture gap: `TreasuryDepositBridge`
validates the repository via **`account_id`**
(`TreasuryDepositBridge.php:195-214` requires `account_id` non-null + the account
to exist) and even **fails loud on a null actor** specifically to protect the
CustomerAdvance GL posting (`TreasuryDepositBridge.php:219-259`). But the actual
GL posting keys off a *different* column (`gl_account_id`) and **silently
no-ops** when it's null. A production deposit into a repository that has
`account_id` set but `gl_account_id` null therefore creates a **Completed
Payment whose overflow never reaches the customer's credit balance / GL** — the
exact failure mode the bridge's own comment says it is guarding against. The
protection is on the wrong column.

**Classification:** REAL BUG (dev). Matches the "account_id vs gl_account_id"
trap already flagged in `project_treasury_payments_audit.md`.

**Fix direction (needs a decision, don't guess):** either (a) the deposit path
must populate `gl_account_id` on the repository (and `resolveRepository` should
validate *it*, not `account_id`), or (b) the GL posting + validation should
consistently use `account_id`, or (c) the two columns should be unified. All
three are correctness-sensitive (double-entry GL), so this is not a blind
one-liner.

**Suggested owner:** quick, targeted fix **but** route through treasury review
(`treasury-reviewer`) + a scoped test run
(`PREFLIGHT_TEST_PATHS='tests/Feature/Partner/RecordCustomerDepositTest.php'`) —
laptop-safe. Do not batch-fix without the column decision.

---

## 2. Document `tenantScope` failures (×11) + inventory key-shape (×1) → **TEST-DEBT (likely)**

**Where (candidate anchors):** the `apps/api/tests/Feature/Document/` tenant-isolation
family, e.g. `CreditNoteTenantIsolationTest.php`,
`DocumentConversionTenantIsolationTest.php`, plus the tenant-scoping assertions in
`ArApOpeningPostLifecycleTest.php`, `DeleteDocumentTest.php`,
`DocumentPaginationTest.php`, `DNConsolidationTest.php`,
`DocumentAdditionalCostTest.php`, `PartialDeliveryTest.php`,
`CreateDocumentTest.php`, `DocumentFillableAllowsWorkOrderIdTest.php`
(the 10 files that reference `tenant_id`/global-scope isolation under
`tests/Feature/Document/`). Inventory key-shape anchor:
`apps/api/tests/Feature/Inventory/InventoryTenantIsolationTest.php` (tenant
isolation) — the "1 inventory key-shape failure" is the response/array key-shape
assertion in that isolation family.

**Note on identification:** exact per-test attribution (which 11 of these fail)
requires a run, which is out of scope on the laptop. The set above is the
verified *candidate surface* (files that assert tenant scoping in the Document
suite). The VPS run should confirm the precise 11 + 1.

**Suspected root cause:** tenant/global-scope isolation assertions that depend on
cross-test state — under `RefreshDatabase` these pass in isolation but fail when
the whole directory runs together because a prior test leaves a
`PermissionRegistrar` team id or `CompanyContext` bound (CLAUDE.md rule 20:
"Projection tests must `app(CompanyContext::class)->clear()` before apply"). The
"key-shape" inventory failure is the same shape: an assertion on the exact array
keys of a tenant-scoped response that drifts when residual context bleeds in.
This is the classic "passes alone, fails in the directory" signature ⇒ isolation
debt, not a product bug.

**Classification:** TEST-DEBT (pending VPS confirmation). If any of these fail
*in isolation*, re-classify that one as a real scoping bug.

**Suggested owner:** VPS queue — run the Document + Inventory suites in isolation
vs. whole-directory to separate genuine scoping regressions from ordering debt,
then add `CompanyContext::clear()` / team-id resets to the offenders.

---

## 3. "Finance" whole-directory pollution failures (×9) → **TEST-DEBT (likely)**

**Where:** there is no single top-level `tests/Feature/Finance/` directory. The
"finance" surface is spread across
`apps/api/tests/Feature/{Income,Expense,Treasury,Accounting}/`. The "9
whole-directory pollution failures" are tests that **pass individually but fail
when their whole directory runs**, i.e. cross-test state pollution (shared
static/singleton state, uncleared `CompanyContext`, leftover seeded chart of
accounts, or Horizon/queue fakes not reset).

**Suspected root cause:** shared mutable state across the finance/GL tests —
CompanyContext not cleared between cases, chart-of-accounts / system-account
purpose rows seeded by one test and assumed absent by another, or
partner-balance cache not reset. The "whole-directory pollution" phrasing is the
tell: green in isolation, red in bulk.

**Classification:** TEST-DEBT (pending VPS confirmation). None appear to be
product defects from reading — but confirm on the VPS by running each finance
subdirectory both in isolation and together.

**Suggested owner:** VPS queue — reproduce the pollution, then fix by clearing
context/singletons in `setUp`/`tearDown` (rule 20) rather than touching product
code.

---

## 4. PHPStan baseline drift (~25 errors in unchanged files) → **TEST-DEBT (baselined suppressions)**

**Not run here.** Per instruction, documented from the committed baseline file
rather than executing PHPStan (vendor in worktrees can be a stale symlink;
laptop-safety). Baseline file: `apps/api/phpstan-baseline.neon` (~14 KB, a
`parameters.ignoreErrors` suppression list; the CI/preflight PHPStan runs at
level 8 *with* this baseline applied).

**Anchors requested in the handover, verified in the baseline:**

- **`ProductController` (~line 741 per handover; baseline entry at
  `phpstan-baseline.neon:909-913`):**
  `Using nullsafe property access "?->value" on left side of ?? is unnecessary`
  (`identifier: nullsafe.neverNull`, `count: 1`,
  `path: app/Modules/Product/Presentation/Controllers/ProductController.php`).
- **"EnrichedProductData" → `EnrichmentReviewController` + `EnrichmentResult`
  (`phpstan-baseline.neon:897-907`):**
  - `Parameter #1 $value of function collect expects ... array<...EnrichmentResult, mixed> given` (`argument.type`, `count: 1`),
  - `Unable to resolve the template type TKey in call to function collect` (`argument.templateType`, `count: 1`),
  both `path: app/Modules/Product/Presentation/Controllers/EnrichmentReviewController.php`.

**What "drift" means here:** these ~25 entries are **baselined suppressions** in
files nobody is actively touching. They are green *because* the baseline
swallows them; they would be red if the baseline were dropped. "Drift" = the
baseline no longer matches reality when unrelated edits shift line numbers or
change the error count under a baselined `count:` — a stale baseline entry then
fails as "ignored error not matched". The handover's "~line 741" vs the current
baseline "line 913" for `ProductController` is itself evidence of this line-number
drift.

**Classification:** TEST-DEBT (accepted technical debt, boxed by the baseline).
No new code should add to it (level-8 zero-errors on new code — CLAUDE.md quality
gate). These are legitimate small type-hygiene fixes (unnecessary nullsafe;
`collect()` template inference on a typed array) — none are correctness bugs.

**Suggested owner:** quick fix (opportunistic) — fix the nullsafe + `collect()`
call sites and delete their baseline entries, OR VPS-regenerate the baseline
(`./vendor/bin/phpstan analyse --generate-baseline`) to re-anchor line numbers.
Regenerate on the VPS, not the laptop.

---

## Green-means-green readiness summary

| Backlog item | Class | Blocks "green"? | Owner |
|---|---|---|---|
| 1. RecordCustomerDeposit CustomerAdvance GL | **REAL BUG** | Yes | Quick fix + treasury review (scoped run) |
| 2. Document tenantScope ×11 + inventory key-shape ×1 | TEST-DEBT (likely) | Yes | VPS queue (isolate vs bulk) |
| 3. Finance whole-directory pollution ×9 | TEST-DEBT (likely) | Yes | VPS queue (context/singleton reset) |
| 4. PHPStan baseline drift ~25 | TEST-DEBT (baselined) | No (baseline-boxed) | Quick fix or VPS baseline regen |

**Only #1 is a confirmed product bug.** #2 and #3 are almost certainly test
isolation debt (verify on the VPS by isolate-vs-bulk runs before touching product
code). #4 is boxed by the baseline and does not fail the gate today. A fleet can
trust "green" once #1 is fixed and #2/#3 are either fixed or quarantined.
