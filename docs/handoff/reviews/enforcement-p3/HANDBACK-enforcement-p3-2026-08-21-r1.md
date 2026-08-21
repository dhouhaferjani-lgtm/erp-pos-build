# HANDBACK — enforcement-P3 (money lanes) — whole-package gate (M3)

> **This file is UNTRACKED BY DESIGN and must never be committed.** It is the single dirty entry the
> M3 handover seal requires (`git status --porcelain` shows exactly `?? docs/handoff/HANDBACK-enforcement-p3-2026-08-21.md`).
> The final-gate bridge copies and hashes it itself (gate-r9 R9-H-2); a tracked-modified handback, or
> any second entry, is rejected.

---

## 1. Header

| Field | Value |
|---|---|
| Package | enforcement-P3 — money lanes (brief §4 items 3(a) + 3(b)) |
| Branch | `codex/enforcement-p3-money-lanes` |
| Worktree | `.worktrees/enforcement-p3` |
| `base_sha` | `0ca7bbb091863b160383d630e6062c820cada9b1` |
| **Final tip (A)** | **`390275a901ed242aca2df36463c8180fe39d66e9`** (`390275a90`, Phase 6.3.1) |
| Commits in `base..HEAD` | **17** |
| Phase series | `Phase 6.<milestone>.<seq>` — 6.0.1 (M0), 6.1.1–6.1.11 (M1), 6.2.1–6.2.4 (M2), 6.3.1 (M3) |
| Closing pin tag | `ci-pin/enforcement-p3-r1` — **pre-allocated and non-null in the candidate** (gate-r6 R6-H-4). The OWNER creates the annotated tag at exactly A at promotion step 3. |
| `pre_promotion_ci_dispatch` | `null` — **correctly**, see §6 |
| Pushed? | **No.** The executor never pushes (gate-r1 H-7). The parent promotes. |

### Commit list (`base..HEAD`, in order)

```
6c93cbbbf Phase 6.0.1: enforcement-p3 M0 — all 28 precondition machine checks PASS …
ffe6cd018 Phase 6.1.1: M1 — behavioural census of every journal_entries creator (3(a))
4ad66ca31 Phase 6.1.2: M1 deliverable D — normalize the GL chokepoint's unbalanced-post failure mode
6558a7c42 Phase 6.1.3: M1 milestone record — status review, execution record, deviations, findings
1d6fbe63c Phase 6.1.4: M1 round-1 fixes — catcher census, real queued swallow closed, two round-0 changes withdrawn
d5910040c Phase 6.1.5: M1 round-1 milestone record — fix_rounds 1, status stays review
bea3b4936 Phase 6.1.6: M1 — split the two unbalanced-entry types; retract a false blast-radius claim
a81b1c07c Phase 6.1.7: M1 record — repoint milestone commit to the type-split tip (bea3b4936)
ebbfca144 Phase 6.1.8: M1 round-2 — documentation-only corrections (docblocks, census refs, R-4 wording)
de71689ca Phase 6.1.9: M1 round-3 — documentation-truth corrections (row 3 type-mismatch, YAML supersession, R-11 split, R-4 ruling, counts)
253534973 Phase 6.1.10: M1 CLOSED — round-4 ACCEPT (census 53 creators, 0 closable class-(c) gaps, chokepoint type split, R-1..R-11 register incl. two parent rulings)
d7c9d187a Phase 6.2.1: M2 — per-country seeded-chart completeness gate keyed to ProvisioningRequiredPurposesV1
c131b0c79 Phase 6.2.2: M2 record — purpose reconciliation report, two reported findings, milestone status review
0bc2fdd34 Phase 6.1.11: record M1's round-4 ACCEPT register (the verdict: artifact the YAML references; untracked would break the M3 one-dirty-entry seal)
79a330e00 Phase 6.2.3: M2 round-1 fixes — correct the class count, withdraw an invalid red-first claim, de-brittle tamper 2
429bbbf1e Phase 6.2.4: M2 CLOSED — round-2 ACCEPT; commit mid-wave registers (M1 r1-r3, M2 r1-r2) so the M3 handover seal shows exactly one dirty entry
390275a90 Phase 6.3.1: M3 handover — whole-package evidence re-run green, status review, closing pin tag already allocated
```

---

## 2. Per-milestone summary

| M | Scope | Status | fix_rounds | Verdict artifact | Milestone commit |
|---|---|---|---|---|---|
| **M0** | Preconditions — 28 machine checks (P1 whole-package, P2 whole-package, country-defaults whole-lane, two-predicate tag bindings, pins, control manifest) | **passed** | 0 | setup-only (no adversarial register per the milestone contract) | `0ca7bbb09` (the base pin — a YAML commit cannot carry its own SHA) |
| **M1** | 3(a) — behavioural census of every `journal_entries` creator; close only genuine class-(c) seams; chokepoint failure-mode normalization | **passed** | **3** | `docs/handoff/reviews/enforcement-p3/M1-round4.md` (ACCEPT) | `bea3b4936` |
| **M2** | 3(b) — purpose reconciliation as a CONSUMER of `ProvisioningRequiredPurposesV1`; per-country seeder completeness CI test | **passed** | **1** | `docs/handoff/reviews/enforcement-p3/M2-round2.md` (ACCEPT) | `d7c9d187a` |
| **M3** | Whole-package gate — **no new implementation** (gate-r1 H-8) | **review** | 0 | pending (this handover) | — |

**M1 outcome in one line.** 53 real `journal_entries` creator sites across 9 files; classification (a) draft-only 21 · (b) chokepoint-guarded 24 · (c) writes Posted without `sealAndPersistEntry` 8. **Genuine closable class-(c) balance gaps: ZERO** — every candidate was green-at-base and therefore DISQUALIFIED under the gate-r1 C-2 red-first rule, so no new validator and no placeholder green was submitted. The shipped implementation is deliverable D only: a **sibling type split** — `UnbalancedJournalEntryException` stays `\RuntimeException` (byte-identical to pre-M1) and the chokepoint throws the new `UnbalancedJournalEntryPostException extends \InvalidArgumentException` (`GeneralLedgerService.php:3418`), the same hierarchy its bare throw already had, so **no catch site anywhere changed** — plus one genuinely swallowed unbalanced post closed red-first at `PostShiftCashVarianceAdjustment:210`.

**M2 outcome in one line.** Eight local required-purposes lists reconciled against the landed manifest with **zero misclassifications**; a manifest-keyed per-country completeness gate (TN/FR/Generic × 28 REQUIRED purposes, existence **and** `expectedAccountType()`) wired into a CI lane that actually runs at **+0 coverage debt**; two findings reported, not fixed.

### Evidence pointers

| Artifact | Path |
|---|---|
| M1 census (creator + catcher census, acceptance commands §5.8, findings register §7) | `docs/handoff/reviews/enforcement-p3/M1-census.md` |
| M1 review rounds | `…/M1-round1.md`, `…/M1-round2.md`, `…/M1-round3.md`, `…/M1-round4.md` (ACCEPT) |
| M2 reconciliation report (reconciliation table §1, findings §2, test §3, tamper §4, CI wiring §5, F-4 §6, acceptance §7, deviations §9, round-1 fixes §11) | `docs/handoff/reviews/enforcement-p3/M2-reconciliation.md` |
| M2 review rounds | `…/M2-round1.md` (CHANGES-REQUIRED), `…/M2-round2.md` (ACCEPT) |

---

## 3. Evidence re-run over the integrated branch — ALL GREEN

Run from `apps/api` at the final tip `390275a90`. Every PHPUnit summary below shows a **nonzero
selected-test count** (gate-r2 R2-H-7: `apps/api/phpunit.xml` sets no `failOnEmptyTestSuite`, so an
empty selection exits 0 and the exit code alone proves nothing).

### 3.1 M1 — census-derived acceptance set (census §5.8), anchored filters

Counts match the census's recorded values exactly.

```
$ ./vendor/bin/phpunit tests/Feature/Treasury/ShiftCashVarianceAdjustmentTest.php \
    --filter '^Tests\\Feature\\Treasury\\ShiftCashVarianceAdjustmentTest::test_an_unbalanced_adjustment_entry_is_refused_under_its_own_reason$'
OK (1 test, 5 assertions)                          N = 1  ≥ 1  ✅

$ ./vendor/bin/phpunit tests/Feature/Accounting/ChokepointUnbalancedGuardTest.php \
    --filter '^Tests\\Feature\\Accounting\\ChokepointUnbalancedGuardTest::test_chokepoint_raises_the_house_unbalanced_exception_type$'
OK (1 test, 2 assertions)                          N = 1  ≥ 1  ✅

$ ./vendor/bin/phpunit tests/Feature/Accounting/ChokepointUnbalancedGuardTest.php \
    --filter '^Tests\\Feature\\Accounting\\ChokepointUnbalancedGuardTest::test_the_two_unbalanced_types_keep_their_load_bearing_parents$'
OK (1 test, 5 assertions)                          N = 1  ≥ 1  ✅

$ ./vendor/bin/phpunit tests/Feature/Document/DocumentConversionScenarioTest.php \
    --filter '^Tests\\Feature\\Document\\DocumentConversionScenarioTest::it_pins_the_deferral_that_keeps_an_unbalanced_prepayment_post_loud$'
OK (1 test, 1 assertion)                           N = 1  ≥ 1  ✅
```

**No acceptance command is submitted for any class-(c) census row**, because there are none of that
kind — per the milestone contract a module with zero class-(c) rows submits census evidence only and
**no placeholder green**.

### 3.2 M1 — the same classes run whole (stronger than the filtered set)

```
$ ./vendor/bin/phpunit tests/Feature/Accounting/ChokepointUnbalancedGuardTest.php
OK (2 tests, 7 assertions)

$ ./vendor/bin/phpunit tests/Feature/Treasury/ShiftCashVarianceAdjustmentTest.php
OK (23 tests, 98 assertions)

$ ./vendor/bin/phpunit tests/Feature/Document/DocumentConversionScenarioTest.php
OK (14 tests, 36 assertions)
```

### 3.3 M2 — completeness gate on BOTH engines

```
$ ./vendor/bin/phpunit tests/Feature/Accounting/SeededChartManifestRequiredPurposeCompletenessTest.php
OK (3 tests, 200 assertions)                                            # sqlite fast loop

$ DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=p3m2_test \
  DB_CENTRAL_DATABASE=p3m2_test DB_USERNAME=houssamr DB_PASSWORD= \
  ./vendor/bin/phpunit tests/Feature/Accounting/SeededChartManifestRequiredPurposeCompletenessTest.php
OK (3 tests, 200 assertions)                                            # real PostgreSQL
```

PostgreSQL matters because the wired lane runs `DB_CONNECTION: pgsql` (`ci.yml:1048`). Scratch DB
`p3m2_test`; **`autoerp_test` was never touched.**

### 3.4 M2 — CI-wiring ratchet

```
$ php tools/feature-lane-manifest-check.php
tests/Feature lane manifest OK — 1350 Feature classes in 74 groups; every group has a disposition;
every declared lane is present in ci.yml; every --filter entry is anchored and uniquely matched
against 1734 test classes across all suites.
  ⚠ COVERAGE DEBT: 71 group(s) / 1131 class(es) …
EXIT=0

$ ./vendor/bin/phpunit tests/Architecture/FeatureLaneManifestCheckerTest.php
OK (46 tests, 123 assertions)
```

Coverage debt **1131 — unchanged from base**; `debt_ceiling` untouched at 1131. The M2 guard added
**zero** coverage debt.

### 3.5 Static analysis + style

```
$ ./vendor/bin/pint --test <all 11 touched PHP files>
{"result":"pass"}

$ ./vendor/bin/phpstan analyse --memory-limit=4G --no-progress <the 7 touched app/ files>
 [OK] No errors

$ ./vendor/bin/phpstan analyse --memory-limit=4G --no-progress --level=8 \
    tests/Feature/Accounting/SeededChartManifestRequiredPurposeCompletenessTest.php
 [OK] No errors
```

### 3.6 ⚠️ M3 observation — PHPStan scope (NOT a gate failure, NOT owned by P3)

Forcing level 8 over the package's **four touched `tests/` files** reports **14 errors**:

| File | N | Identifiers |
|---|---|---|
| `tests/Feature/Accounting/ChokepointUnbalancedGuardTest.php` | 4 | `method.alreadyNarrowedType` ×2, `function.alreadyNarrowedType` ×2 |
| `tests/Feature/Document/DocumentConversionScenarioTest.php` | 1 | `if.alwaysFalse` (line 301) |
| `tests/Feature/Treasury/ShiftCashVarianceAdjustmentTest.php` | 9 | `if.alwaysFalse` ×1 (line 463) + `argument.type` ×8 (`bcadd` numeric-string, lines 709/737/738/760×2/799/800/804) |
| `tests/Feature/Accounting/SeededChartManifestRequiredPurposeCompletenessTest.php` (M2) | **0** | — |

**These are invisible to every gate.** `phpstan.neon` pins `paths: - app/`, so CI, `./scripts/preflight.sh`
and the canonical `./vendor/bin/phpstan` never analyse `tests/`. **No prior claim is falsified:** M1's
census §5.9 scoped its PHPStan claim to *"<touched app/ files>"*, which is clean, and M2 claimed only its
own test file, which is clean.

Attribution by diff-hunk against `base..HEAD`: **6 M1-introduced** (all 4 in `ChokepointUnbalancedGuardTest`,
which is a new class; `DocumentConversionScenarioTest:301`, inside the `+231,100` hunk;
`ShiftCashVarianceAdjustmentTest:463`, inside the `+434,72` hunk) and **8 PRE-EXISTING**
(`ShiftCashVarianceAdjustmentTest` lines past M1's hunk, shifted +72 from their base positions).

Recorded **only** so that a future widening of PHPStan's `paths` to include `tests/` is not misread as
new drift. Fixing them is outside the package's guard-only scope (rule 4).

---

## 4. Scope proof at the final tip

Re-run **at** `390275a90` with the HEAD-equality assertion **inside** the proof, so the output cannot be
stale relative to the tip it claims to describe.

```
=== HEAD-EQUALITY ASSERTION (inside the proof) ===
expected tip : 390275a901ed242aca2df36463c8180fe39d66e9
actual  HEAD : 390275a901ed242aca2df36463c8180fe39d66e9
PASS: proof is running at the asserted tip

=== commits in range ===
17

=== git diff --stat 0ca7bbb09..HEAD ===
 .../Application/Services/AccountingService.php     |  23 +
 .../Exceptions/UnbalancedJournalEntryException.php |  20 +
 .../UnbalancedJournalEntryPostException.php        |  64 ++
 .../Exceptions/UnpostableDocumentGlException.php   |  27 +
 .../Domain/Services/GeneralLedgerService.php       |  16 +-
 .../Converters/SalesOrderToInvoiceConverter.php    |  20 +
 .../Listeners/PostShiftCashVarianceAdjustment.php  |  27 +
 .../Accounting/ChokepointUnbalancedGuardTest.php   | 155 +++++
 ...hartManifestRequiredPurposeCompletenessTest.php | 286 ++++++++
 .../Document/DocumentConversionScenarioTest.php    | 102 +++
 .../Treasury/ShiftCashVarianceAdjustmentTest.php   |  72 ++
 apps/api/tests/feature-lane-manifest.json          |   4 +-
 docs/handoff/progress/enforcement-p3.progress.yaml | 460 ++++++++++++-
 docs/handoff/reviews/enforcement-p3/M1-census.md   | 761 +++++++++++++++++++++
 docs/handoff/reviews/enforcement-p3/M1-round1.md   |  39 ++
 docs/handoff/reviews/enforcement-p3/M1-round2.md   |  71 ++
 docs/handoff/reviews/enforcement-p3/M1-round3.md   |  44 ++
 docs/handoff/reviews/enforcement-p3/M1-round4.md   |  55 ++
 .../reviews/enforcement-p3/M2-reconciliation.md    | 517 ++++++++++++++
 docs/handoff/reviews/enforcement-p3/M2-round1.md   |  48 ++
 docs/handoff/reviews/enforcement-p3/M2-round2.md   |  58 ++
 21 files changed, 2848 insertions(+), 21 deletions(-)

=== BUCKETED PATH LIST (allowlist) ===
  [PROD-ALLOWED   ] apps/api/app/Modules/Accounting/Application/Services/AccountingService.php
  [PROD-ALLOWED   ] apps/api/app/Modules/Accounting/Domain/Exceptions/UnbalancedJournalEntryException.php
  [PROD-ALLOWED   ] apps/api/app/Modules/Accounting/Domain/Exceptions/UnbalancedJournalEntryPostException.php
  [PROD-ALLOWED   ] apps/api/app/Modules/Accounting/Domain/Exceptions/UnpostableDocumentGlException.php
  [PROD-ALLOWED   ] apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php
  [PROD-ALLOWED   ] apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/SalesOrderToInvoiceConverter.php
  [PROD-ALLOWED   ] apps/api/app/Modules/Treasury/Application/Listeners/PostShiftCashVarianceAdjustment.php
  [TESTS          ] apps/api/tests/Feature/Accounting/ChokepointUnbalancedGuardTest.php
  [TESTS          ] apps/api/tests/Feature/Accounting/SeededChartManifestRequiredPurposeCompletenessTest.php
  [TESTS          ] apps/api/tests/Feature/Document/DocumentConversionScenarioTest.php
  [TESTS          ] apps/api/tests/Feature/Treasury/ShiftCashVarianceAdjustmentTest.php
  [MANIFEST-JSON  ] apps/api/tests/feature-lane-manifest.json
  [DOCS-HANDOFF   ] docs/handoff/progress/enforcement-p3.progress.yaml
  [DOCS-HANDOFF   ] docs/handoff/reviews/enforcement-p3/M1-census.md
  [DOCS-HANDOFF   ] docs/handoff/reviews/enforcement-p3/M1-round1.md
  [DOCS-HANDOFF   ] docs/handoff/reviews/enforcement-p3/M1-round2.md
  [DOCS-HANDOFF   ] docs/handoff/reviews/enforcement-p3/M1-round3.md
  [DOCS-HANDOFF   ] docs/handoff/reviews/enforcement-p3/M1-round4.md
  [DOCS-HANDOFF   ] docs/handoff/reviews/enforcement-p3/M2-reconciliation.md
  [DOCS-HANDOFF   ] docs/handoff/reviews/enforcement-p3/M2-round1.md
  [DOCS-HANDOFF   ] docs/handoff/reviews/enforcement-p3/M2-round2.md

=== NEGATIVE ASSERTIONS ===
  .github/** files in range: 0  PASS (S-14 dispatch leg does not apply)
  control 'scripts/adversarial-review.sh': 0  PASS
  control 'scripts/adversarial-review-final.sh': 0  PASS
  control 'docs/handoff/enforcement-control-manifest.yaml': 0  PASS
  control 'docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md': 0  PASS
  control 'docs/handoff/SELF-REVIEW-HARNESS.md': 0  PASS
  control '.claude/agents': 0  PASS
  forbidden-edit 'ProvisioningRequiredPurposesV1.php': 0  PASS
  forbidden-edit 'SystemAccountPurpose.php': 0  PASS
  forbidden-edit 'ChartOfAccountsService.php': 0  PASS
  forbidden-edit 'TunisiaChartOfAccountsSeeder.php': 0  PASS
  forbidden-edit 'FranceChartOfAccountsSeeder.php': 0  PASS
  forbidden-edit 'GenericChartOfAccountsSeeder.php': 0  PASS

SCOPE PROOF: PASS
```

**21 files, every one inside the allowlist.** Production changes are confined to the 7 M1
accounting/treasury/document exception-and-listener files; the only other production-adjacent change is
the M2 test plus one informational integer in the lane manifest.

---

## 5. No workflow files → the S-14 dispatch leg does not apply

`git diff --name-only base..HEAD -- .github` returns **zero** files.

`enforcement-p3.progress.yaml:92-104` requires a PRE-PROMOTION CI DISPATCH receipt **only when the
accepted branch touches `.github/workflows/**`** — anticipated because "M2's CI wiring likely does". It
does not: M2 wired its guard into the **already-existing** `treasury-spine-pgsql` lane, whose selector
`./vendor/bin/phpunit tests/Feature/Accounting` (`ci.yml:1111-1112`) runs the whole directory on
PR→dev (`ci.yml:1012`) and which is already in `all-checks-pass` `needs` (`ci.yml:1443`). The new class
is therefore picked up by an unmodified workflow.

**Consequence:** `pre_promotion_ci_dispatch` stays `null` **correctly**, and this is the **no-workflow
promotion path**. The universal closing sequence (steps 0/1/4/4a/5, single-writer serialization, the
step-4a freshness re-assert, the annotated closing tag, the named package-specific closing check) still
applies in full — only the `workflow_dispatch` leg is out of scope. The structured `closing_receipt`
exists for every package including a no-workflow promotion.

---

## 6. Consolidated deviations register

### M0

| # | Deviation |
|---|---|
| **M0-D1** | The landed P1/P2 pin-tag annotations carry their binding data in the **r19-era field shapes** (`register_sha256: <hex>` without the `<path>=` prefix; the manifest digest on a `control_sha256: manifest=<hex>` line rather than a standalone `manifest_sha256:` line). The r12 schema-literal parse would miss them, so M0 verified the **SUBSTANCE** of every binding instead — digest equality against landed bytes, A equality, manifest-digest presence. Tags are immutable by protocol, so the shapes cannot be retrofitted; **P3-M3 and any future consumer must parse both.** |

### M1

| # | Deviation |
|---|---|
| **M1-D1** | Brief line numbers had drifted. The chokepoint `sealAndPersistEntry` is `:3379` and its THROW `:3418` at HEAD (not `:3480-3510`); `postEntry` `:2773`; `postEntryNow` `:3349`; `createPOSChargeEntry` `:3999`. Every fact was verified at the ACTUAL location. |
| **M1-D2** | The P1 baseline holds only 4 `journal_entries` keys — a violation set, not a creator inventory — so the census was rebuilt independently. |
| **M1-D3** | **Zero class-(c) guards is the CORRECT outcome, not an omission.** Every candidate was green-at-base and therefore disqualified by the gate-r1 C-2 red-first rule. |
| **M1-D4** | The worktree had no `vendor/`; `composer install` was run (a symlink would autoload stale main-repo classes). |
| **M1-D5** | PG facts verified on the port-5432 Homebrew instance via scratch DB `p3m1_test`; **`autoerp_test` NOT touched.** |
| **M1-D6** | An earlier claim in this lane — that the converter swallows the unbalanced post TODAY — was **FALSIFIED by its own red run** and corrected in place rather than dropped. The escape is real but incidental (`DB::afterCommit` defers the post past the `try` when `transactionLevel > 0`). No production incident is claimed. |

### M2

| # | Deviation |
|---|---|
| **M2-D1** | **Test placed in `tests/Feature/Accounting/`, not `tests/Feature/CountryDefaults/`; no `ci.yml` allowlist edit; no ceiling / `debt_ceiling` raise.** The dispatch's preferred route presumed a deferred group. `Accounting` is **laned**, and its selector runs the whole directory on PR→dev — satisfying the brief's actual rule ("a CI lane that ACTUALLY RUNS") strictly better than the allowlist it itself called the worst case, at **+0 coverage debt** against a global ceiling with **zero slack** (1131/1131). Taking the dispatch's route would have meant loosening a P2 ratchet in order to land a P3 guard. |
| **M2-D2** | **The landed manifest is a 43-case 28/1/4/10 partition, not the brief's 41-case 27/1/4/9.** Self-enforced at `ProvisioningRequiredPurposesV1::assertConforms():249-254`. The brief's prose is stale; M2 keyed to the LANDED authority per "CONSUMES the manifest; never redefines it". Recorded so the count mismatch is not read as P3-introduced drift. |
| **M2-D3** | **`feature-lane-manifest.json` edited** (a P2-owned artifact): one informational integer + its note, `Accounting.classes` 81 → **83**, for a **laned** group where the checker enforces no ceiling. Corrected at round 1 from an initial 82, which counted only M2's own file and omitted M1's `ChokepointUnbalancedGuardTest` in the same directory. `debt_ceiling` untouched at 1131. |
| **M2-D4** | **Tests run on the default sqlite `phpunit.xml` env plus a PG cross-check**, rather than a bespoke PG-only env. The existing chart-seeder suite runs on sqlite and its baseline was verified green before any change; because the wired lane is PG, the class was additionally run against scratch DB `p3m2_test`. `autoerp_test` never touched. |
| **M2-D5** | **D-1 and D-2 reported, not fixed** — both are changes to authority this lane does not own. |

---

## 7. Reported-findings register (carried forward, NOT closed by this package)

### 7.1 M1 — R-1 … R-11 (`M1-census.md` §7)

| # | Finding (abridged — full text and citations in the census) |
|---|---|
| **R-1** | **Live `journal_entries` DELETE.** `RepositoryTransferService.php:105` unconditionally `$draft->delete()`s a `journal_entries` row. Safe *today* only because the row is an unchained Draft inside the caller's own transaction. |
| **R-2** | **Orphan-draft surface.** Drafts reachable from production that are never posted: `treasury_transfer` (`GLS:1413`), `pos_account_charge` (`GLS:3999`), `payment_tolerance` (`GLS:1583`). |
| **R-3** | **Dead GL creators** with no production call site: `createFromInvoice:146`, `createFromCreditNote:230`, `createPaymentEntry:353`, and both `UninvoicedDeliveryNoteService` creators. |
| **R-4** ⚠️ | **Knowingly-sealed unbalanced entry — OPEN BY RULING.** See §7.2 — this is the one finding whose underlying defect is a genuine, live, chained imbalance. |
| **R-5** | **Rule-19 boundary drift (cosmetic, no reachable defect).** `AccountingOpeningService::postBatch` writes line amounts without `CurrencyScale::bcformatStrict`, relying on upstream `validateRow` normalisation. |
| **R-6** | **Census blind spot for the P1 scanner.** `JournalEntry::query()->create` is invisible to a `JournalEntry::create` grep and hides 6 creators. `DocumentPerActionWriteScanner` should be re-checked for the same pattern. |
| **R-7** | **`SalesOrderToInvoiceConverter.php:590`** — balance refusal protected only by transaction nesting. Unreachable today; reported, not guarded (a guard would be dead code plus a non-discriminating test). |
| **R-8** | **Shift-variance GL refusals have no retry or dead-letter** — residual of the §5.6 fix. `PostShiftCashVarianceAdjustment` is a plain synchronous listener, not `ShouldQueue`. |
| **R-9** | **One catcher protected only by a transaction-nesting accident** — `PostGrIrOnGoodsReceipt.php:41`. Unreachable today, so no guard and no test; the parent may want the nesting invariant pinned. |
| **R-10** | **PRE-EXISTING catch-site downgrades** of a chokepoint balance refusal to 4xx (`DocumentConversionController:418` → 422; `POS/ReceiptController:385` → 400; plus broad `catch` sites). **No exception hierarchy can fix this — only per-site narrowing can**, a multi-module change to HTTP error contracts needing the parent's scope ruling. |
| **R-11** ⚠️ | **Reachable SYNCHRONOUS swallow of a balance refusal inside the FISCAL PROJECTION path.** See §7.2. |

### 7.2 The two parent rulings on file — quoted

**R-4 — PARENT RULING ON FILE (round 3):**

> (a) The prior L1 cancellability deferral is **UPHELD for P3** — no unilateral behaviour change in this
> package; closing the carve-out would make lineless documents uncancellable. (b) The
> cancellability-vs-chained-balance trade-off is **ESCALATED as a named owner decision, to be added to the
> repo LEDGER at P3 close.** (c) **R-4 must NOT be carried into P3-M3 as "balance invariant closed" — it is
> OPEN BY RULING.** The whole-package gate should read the balance invariant as: closed for every
> class-(c) seam except this one, which stands deferred by an explicit ruling rather than by absence of a
> defect. The only reported finding here whose underlying defect is a genuine, live, chained imbalance.

**R-11 — PARENT RULING ON FILE:**

> **REPORTED, not fixed** — brief item 4 is explicitly "(scoped, optional)" and "propose/implement", so
> reporting is within scope. **Fix lane: the fiscal projection-discipline family, post-P3** — it converges
> with the OutboxIngestor / seal-branch ticket cluster in the parent ledger and should be ruled on there as
> one piece, not piecemeal here. **Framing (this is the part that must not be lost): this is PROJECTION
> ERROR DISCIPLINE — fail loudly, retryably, or dead-letter — NOT HTTP catch narrowing.**

### 7.3 M2 — D-1 and D-2 (`M2-reconciliation.md` §2)

| # | Finding |
|---|---|
| **D-1** | **A REQUIRED purpose does not mean "resolve-or-fail" on every path.** `TreasuryReceiptBridge` (`:446-455`, `:528-531`) uses a **third gate shape** `allowedGateKinds()` cannot express — neither `MODULE_GATE` nor `DOMAIN_PRECHECK_4XX`, but a non-throwing precheck on a Horizon worker that on a miss persists an operator alert and **silently skips the GL entry while the projection completes successfully.** Schema-legal only because those purposes are REQUIRED and so carry `gate_kind: null`. Materially the same incident class as R-11. **Recommendation:** the country-defaults lane either models the shape (e.g. a `PROJECTION_PRECHECK_ALERT` gate kind) or records that REQUIRED carries no uniform failure semantics; the parent folds it into the fiscal projection-discipline family alongside R-11. **P3 proposes; it does not change the manifest.** |
| **D-2** | **`requiredPurposes()` is a strict 14-of-28 subset of the manifest's REQUIRED set — the F-4 surface.** Computed from landed code: zero purposes go the other way; **fourteen** manifest-REQUIRED purposes are unchecked by live-tenant `validateCompanyAccounts()` — `goods_received_not_invoiced`, **`inventory`**, `marketing_goodwill_expense`, `payment_tolerance_expense`, `payment_tolerance_income`, `pos_tender_clearing`, `purchase_expenses`, `purchase_price_variance_expense`, `purchase_price_variance_income`, `purchase_stamp_duty`, `rounding_loss_expense`, `sales_discount`, `sales_returns_clearing`, `voucher_liability`. A brownfield tenant missing any of them reports `valid: true` and then hard-fails at runtime; `inventory` is the sharpest (resolved via `findByPurposeOrFail` at GR/IR `:1897`, supplier-invoice clearing `:2054`, cost capitalization `:4339`, movement `:4591`, write-off `:4815`, plus `OpeningBalancePostingService:229` / `ResetOpeningBalanceService:152`). **OWNER GATE, REPORTED NOT CLOSED (F-4):** widening `requiredPurposes()` tightens validation for already-live tenants and needs a per-purpose backfill story. **Exposure is brownfield-only** — M2's gate proves all three seeded charts map all 28 REQUIRED purposes with correct types today. |

### 7.4 Scope asymmetry (M2 round-1 finding 5, recorded in `M2-reconciliation.md` §3)

The legacy-arm gate enforces **REQUIRED only**, so a future edit dropping `SalesStampDutyPayable` from
the TN chart is caught on the **template** arm (`TemplatePublishingService:309-317`) but **not** on the
legacy one, even though `GeneralLedgerService.php:295` resolves it through the throwing
`getAccountByPurpose()`. **Not a live gap** (`TunisiaChartOfAccountsSeeder.php:210` maps it; FR and
Generic map it zero times, consistent with SCOPE_REQUIRED) and **not a scope violation** (the brief
scopes deliverable 2 to REQUIRED).

---

## 8. M3 carry-forward — read before reading the balance invariant as "closed"

> **R-4 IS NOT CLOSED.** Per the parent ruling quoted in §7.2, the balance invariant for this package
> must be read as: **closed for every class-(c) seam EXCEPT R-4, which stands deferred by an explicit
> ruling rather than by absence of a defect.** R-4 is the only reported finding whose underlying defect
> is a genuine, live, chained imbalance, and its cancellability-vs-chained-balance trade-off is
> **escalated as a named owner decision to be added to the repo LEDGER at P3 close.**

Also carried forward, none of it owed by this package:

- **R-11 + M2's D-1** → the **fiscal projection-discipline lane, post-P3**, to be ruled on as one piece
  with the OutboxIngestor / seal-branch cluster. Framing to preserve: *projection error discipline — fail
  loudly, retryably, or dead-letter — NOT HTTP catch narrowing.*
- **R-10** → per-site catch narrowing; a multi-module change to HTTP error contracts needing a parent
  scope ruling.
- **M2's D-2** → the **country-defaults authority**, as an F-4 owner gate with its backfill obligation.
- **R-1, R-2, R-3, R-5, R-6, R-7, R-8, R-9** → reported; no P3 work owed.
- **⚠️ Pre-existing red in the wired lane** (`M2-reconciliation.md` §7.6): `phpunit tests/Feature/Accounting`
  is 750 tests with **5 errors**, all `DeliveryRequiredBeforeInvoiceException` from
  `DocumentPostingService.php:649` — the DN / document-per-action delivery-before-invoice rule, unrelated
  to chart purposes. Proven **not M2's** by file-removal; base attribution established separately by range
  inspection. **Landing P3 does not by itself make `treasury-spine-pgsql` green** — this belongs to the
  parent's red-gate reconciliation, and rule 4 forbade fixing it here.

---

## 9. Seal state

```
$ git status --porcelain
?? docs/handoff/HANDBACK-enforcement-p3-2026-08-21.md
```

Exactly one entry, untracked, and it is this handback. Ready for the PARENT-run final bridge
(`scripts/adversarial-review-final.sh`) at **A = `390275a901ed242aca2df36463c8180fe39d66e9`**, lenses
`treasury, fiscal-pos, stock-gl-interaction, inventory-costing`.
