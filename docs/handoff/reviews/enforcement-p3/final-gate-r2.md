# FINAL-GATE REGISTER — package p3 M3 round 2 attempt 1
accepted_sha: 8c8c3735894270b87aa33a80774ea2469574413e
base_sha: 0ca7bbb091863b160383d630e6062c820cada9b1   # from the parent DISPATCH RECEIPT (/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p3.receipt.yaml), ancestry-verified
dispatch_receipt: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p3.receipt.yaml
snapshot: detached-worktree @ 8c8c3735894270b87aa33a80774ea2469574413e (sealed; re-asserted post-run)
handback_sha256: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p3-registers/HANDBACK-enforcement-p3-2026-08-21-r2.md=e5cdc4576064f309e139d36f7c9551bb85a0d3f13f1bdb9de6f1e5b57cc71306   # copied+hashed by this bridge; not in the prompt
manifest_sha256: 7c298d5fbe0b35d9cd3970f57d3c49c03fa219a6eb44976dbe1d478c7da6a811
control_sha256: adversarial-review-final.sh=55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88
control_sha256: brief=63cc3e182d0ab1bd9a2c03f147cb4bd786aa708f8b9f8e234a567cab4261c6c8
control_sha256: harness=6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
control_sha256: lens:treasury=408258d068f2ef640b701533f985f0e7ab378a94a0583bab4f808e0727b35d5d
control_sha256: lens:fiscal-pos=419ac2083583210ff007956a00cdfded8de009cc763cdd2563411c24372b5a82
control_sha256: lens:stock-gl-interaction=7e1c3c7c2c2dbdedcfaab8aba0ce19803e87f798fbf057a527d97a030fedaf9e
control_sha256: lens:inventory-costing=7f7691e86715d97e8c58bef1dc13b1b5218aa19841c53417e6f5651f1e5829ea
max_fix_rounds: 5
---
I've completed the review. Here is the register.

---

# FINAL-GATE ADVERSARIAL REGISTER — enforcement-P3, milestone M3, round 2

**Snapshot reviewed:** `/var/folders/yb/rbv331vn481c6blwf61pyp840000gn/T/tmp.1d7AkiZpFe/snap`, detached at **A = `8c8c3735894270b87aa33a80774ea2469574413e`** (HEAD verified equal to A).
**Range:** `0ca7bbb091863b160383d630e6062c820cada9b1..8c8c3735894270b87aa33a80774ea2469574413e` — 18 commits, 21 files, +3018/−22.
**Handback evaluated:** `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p3-registers/HANDBACK-enforcement-p3-2026-08-21-r2.md` (466 lines).
**Lenses applied in full:** `treasury`, `fiscal-pos`, `stock-gl-interaction`, `inventory-costing`.

---

## 1. Content-derived evidence of inspection — required tables

Every table below was parsed from the shipped bytes at A. Format: **row count · first row key · last row key** (keys quoted as the row's leading cell, verbatim).

### 1a. Handback tables

| Table | Rows | First row key | Last row key |
|---|---|---|---|
| §2 Per-milestone summary | **4** | `M0` | `M3` |
| §3.6 PHPStan-scope observation | **4** | `tests/Feature/Accounting/ChokepointUnbalancedGuardTest.php` | `tests/Feature/Accounting/SeededChartManifestRequiredPurposeCompletenessTest.php` (M2) |
| §4 Bucketed path list (scope proof) | **21** | `[PROD-ALLOWED] apps/api/app/Modules/Accounting/Application/Services/AccountingService.php` | `[DOCS-HANDOFF] docs/handoff/reviews/enforcement-p3/M2-round2.md` |
| §6 Deviations — M0 | **1** | `M0-D1` | `M0-D1` |
| §6 Deviations — M1 | **8** | `M1-D1` | `M1-D8` |
| §6 Deviations — M2 | **5** | `M2-D1` | `M2-D5` |
| §7.1 Reported findings (M1) | **11** | `R-1` | `R-11` |
| §7.3 Reported findings (M2) | **2** | `D-1` | `D-2` |
| §10 Final-gate round-1 fix record | **9** | `1` (census §5.1 pre-split survivor) | `5` (findings register cited as §7, corrected to §6) |
| §10 Round-1 re-verification | **12** | `M1 acceptance filter A — shift-variance unbalanced refusal` | `Scope proof at 8c8c37358 (HEAD-equality inside)` |

### 1b. Census / classification tables — `docs/handoff/reviews/enforcement-p3/M1-census.md` (the brief §7 item 3 review target)

| Table (line) | Rows | First row key | Last row key |
|---|---|---|---|
| §0 Executive result (`:10`) | **14** | `` `journal_entries` creation sites found (all patterns) `` | `net production behaviour change to existing catch sites` |
| §1(iii) Posted-writer structural proof (`:109`) | **7** | `0` — `GeneralLedgerService.php:3450` (the chokepoint) | `6` — `ResetOpeningBalanceService.php:163` |
| §2.1 GLS self-posting creators (`:153`) | **23** | `1` — `:434` `createCustomerAdvanceJournalEntry` | `23` — `:4953` `reverseInventoryWriteOffEntry` |
| §2.1 Conditional creators (`:189`) | **2** | `52` — `:1583` `createPaymentToleranceJournalEntry` | `53` — `:1711` `clearCustomerAdvanceToReceivable` |
| §2.1 Bucket reconstruction (`:198`) | **4** | `**(a)** draft-only` | (totals row) |
| §2.2 GLS draft-only creators (`:216`) | **17** | `24` — `:3563` `createPOSPaymentEntry` | `40` — `:353` `createPaymentEntry` |
| §2.3 Non-GLS creators (`:238`) | **11** | `41` — `JournalEntryController.php:97` `store` | `51` — `DemoPharmacySeeder.php:872` `seedTunisiaBalances` |
| §5.3 Catch-site regression table (`:484`) | **5** | `` `DeliveryNoteController.php:587` `` | `` `CreditNoteController.php:406` `` |
| §5.3 Type/parent table (`:500`) | **2** | `` `UnbalancedJournalEntryException` `` | `` `UnbalancedJournalEntryPostException` **(new)** `` |
| **§5.5 CATCHER CENSUS** (`:563`) | **23** | `1` — `Treasury/…/PostShiftCashVarianceAdjustment.php:184`/`:210` | `23` — `Console/TenantScopedCommand.php:333` |
| §5.7 Regression suite (`:734`) | **10** | `` tests/Feature/Accounting/ChokepointUnbalancedGuardTest.php `` | `` tests/Feature/Treasury/ShiftCashVarianceTriggerPathsTest.php `` |
| §6 Reported findings (`:793`) | **11** | `R-1` | `R-6` ⚠︎ |
| §7 Deviations (`:811`) | **8** | `M1-D1` | `M1-D5` ⚠︎ |

⚠︎ Both registers are **complete but non-monotonically ordered** (§6 runs R-1…R-5, R-7…R-11, then R-6 at `:805`; §7 runs M1-D1…M1-D4, M1-D8, M1-D7, M1-D6, M1-D5). All 11 `R-` keys and all 8 `M1-D` keys are present exactly once — verified by key-set extraction, not by reading the last row. Presentation only; the handback §7.1/§6 render both in numeric order.

### 1c. Purpose reconciliation — `docs/handoff/reviews/enforcement-p3/M2-reconciliation.md`

| Table (line) | Rows | First row key | Last row key |
|---|---|---|---|
| §0 Manifest partition correction (`:28`) | **4** | `REQUIRED` | `SOFT` |
| **§1 Reconciliation table** (`:53`) | **9** | `1` — `Treasury/Application/Projections/TreasuryReceiptBridge.php:446-455` (`postCashRoundingEntry`) | `9 †` — `Console/Commands/BackfillChartPurposesCommand.php:318-364` |
| §3 Countries enumerated (`:155`) | **3** | `` `TN` `` | `` `XX` `` |
| §3 Existing-test comparison (`:206`) | **2** | `ChartOfAccountsPurposeParityTest::…` | `ChartOfAccountsServiceTest::test_every_country_seeder_satisfies_all_required_system_purposes` |
| §5 CI-wiring route comparison (`:287`) | **5** | `Manifest disposition` | `` `ci.yml` touched `` |
| §8 Files touched (`:463`) | **3** | `apps/api/tests/Feature/Accounting/SeededChartManifestRequiredPurposeCompletenessTest.php` | `docs/handoff/reviews/enforcement-p3/M2-reconciliation.md` |
| §9 Deviations (`:478`) | **5** | `M2-D1` | `M2-D5` |
| §11 Round-1 fix record (`:508`) | **5** | `1 — Accounting.classes: 82 false at HEAD; real count 83` | `5 — scope asymmetry between the two arms` |
| §11 Re-verification (`:522`) | **7** | `phpunit …SeededChartManifestRequiredPurposeCompletenessTest` | `phpstan analyse … --level=8` |

---

## 2. Independent re-derivation of the load-bearing claims

Each verified against the snapshot bytes, not against the handback's assertion of them.

| Claim | Method | Result |
|---|---|---|
| Only Posted-writers are the chokepoint + 6 direct creators (census §1(iii)) | `grep -rn "JournalEntryStatus::Posted" app/`, assignments isolated from reads | **Reproduced exactly.** `AccountingOpeningService:268`, `AccountingService:429/581/928`, `OpeningBalancePostingService:238`, `ResetOpeningBalanceService:163`, `GeneralLedgerService:3450`. No raw-SQL flip, no relation-create. |
| Fix 2b recount: **20** real literal / **50** real broad `catch` clauses (23 raw − 3, 54 raw − 4) | Two greps over `app/`, comment lines isolated | **Exact on all four numbers.** The 3 excluded literal matches are docblock prose at `AccountingService.php:309`, `UnbalancedJournalEntryException.php:34`, `UnbalancedJournalEntryPostException.php:41`. |
| Fix 1: `forChokepoint()` exists only on the Post class | `grep -rn "function forChokepoint" app/` | **1 definition**, `UnbalancedJournalEntryPostException.php:56`. Chokepoint throw at `GeneralLedgerService.php:3418`. |
| Fix 1 sweep: retired test name eliminated | `grep -rn "test_chokepoint_raises_the_house" apps/api/ docs/` | **3 survivors, all deliberate and documented**: `ChokepointUnbalancedGuardTest.php:49` (the rename note), `M1-census.md:717`/`:719` (the verbatim red-first transcript, explicitly protected). No live selector. |
| Fix 2c: `postEntryNow` at `GeneralLedgerService.php:1308` | `sed -n '1300,1315p'` | **Correct.** `:1307` is blank; `:1308` is `$this->postEntryNow($entry, $user, $currencyCode);`. |
| "Blast radius byte-identical to the pre-M1 bare throw" | Probed the only ways a subtype swap can change behaviour: `get_class(…)===`/`::class ===` against `InvalidArgumentException`, `instanceof \InvalidArgumentException`, `catch (\LogicException)` | **Zero hits in all three.** Claim holds. `expectException(\InvalidArgumentException::class)` sites in `tests/` still match by inheritance; message is byte-identical. |
| `assertLegsBalance()` is the ONLY source of `UnbalancedJournalEntryException` (shipped docblock) | `grep -rn UnbalancedJournalEntryException app/` minus the class file and docblocks | **1 throw site**, `AccountingService.php:375`. |
| Fix 2e binding test is non-vacuous | Read `getSeederForCountry()` at `ChartOfAccountsService.php:172-179` | Three-arm match `'TN' / 'FR' / default`; the reflection source scan, the `assertNotEmpty($casedCountries)` staleness trap, the `default =>` presence check and the fall-through probe all bind against real source. |
| M2-D2: manifest is 28/1/4/10 = 43, brief's "41-case 27/1/4/9" stale | `ProvisioningRequiredPurposesV1::assertConforms()` `:249-254` | **Confirmed** — hard-fails unless `[REQUIRED=>28, SCOPE_REQUIRED=>1, CONDITIONAL=>4, SOFT=>10]`, plus a total-coverage assertion. Consuming `$entry['classification'] === 'REQUIRED'` is correct (`private const REQUIRED = 'REQUIRED'`, `:12`). |
| Lane wiring is real and needs no workflow edit | `ci.yml:1112` / `:1443`; `git diff --name-only base..A -- .github` | `run: ./vendor/bin/phpunit tests/Feature/Accounting` — whole-directory, recursive; job `treasury-spine-pgsql` present in `all-checks-pass` `needs` (`:1443`); `if:` at `:1012` covers PR→dev. **Zero `.github/**` files in range** ⇒ the no-workflow promotion path and `pre_promotion_ci_dispatch: null` are both correct. |
| M2-D3: editing `Accounting.classes` 81→83 cannot loosen a P2 ratchet | `tools/feature-lane-manifest-check.php:335-350` | The ceiling branch is gated on `if ($isDeferred \|\| $isExcluded)`. `Accounting` is `lane`-dispositioned ⇒ informational. **83 matches disk** (83 `*Test.php` under `tests/Feature/Accounting`, recursive); total **1350** Feature classes matches the pasted checker line. |
| Module boundary / deptrac | `deptrac.yaml` ruleset; import census | `Treasury/Application/…` → `Accounting/Domain/Exceptions` is ModuleApplication → ModuleDomain, **permitted**; 57 pre-existing Treasury→Accounting imports. No new violation, no baseline pressure. |
| Scope allowlist | `git diff --stat base..A`, path bucketing | **21 files**, all inside `apps/api/app/Modules/{Accounting,Document,Treasury}` · `apps/api/tests/**` · `docs/handoff/**`. Zero control-surface files (`adversarial-review*.sh`, brief, harness, control manifest, `.claude/agents`), zero frozen-seeder / `ProvisioningRequiredPurposesV1` / `SystemAccountPurpose` / `ChartOfAccountsService` edits. |
| Candidate-YAML projection (the bridge's field-check surface) | `enforcement-p3.progress.yaml` | `base_sha` == range base ✓; exactly one final milestone `M3` at `status: review` ✓; `fix_rounds: 1` ≤ `max_fix_rounds: 5` ✓; structured `control_manifest: {path, sha256}` ✓; `p3_closing_pin_tag: ci-pin/enforcement-p3-r1` **non-null** ✓. |

---

## 3. Lens dispositions

**treasury** — No float touches money anywhere in the diff. The chokepoint's `bcadd`/`bccomp` arithmetic and `$balanceScale` are untouched; only the throw expression changed. No scale resolution added, so no no-arg `getScale()` exposure. The new `refuse($event, 'unbalanced_journal_entry', […], level: 'error')` matches the real signature at `PostShiftCashVarianceAdjustment.php:520` (`string $level = 'warning'`), routes to `Log::error` and stamps `severity` on the audit row. Catch ordering is sound: the two preceding clauses are unrelated concrete types, so the new specific clause is reachable and precedes `catch (Throwable)`. Balance-sign, GR/IR and PCG-TN matrices untouched. Module boundary respected — an exception class, not an Eloquent model. No defect.

**fiscal-pos** — No device-authored fact is re-authored; no Event class renamed, restructured or deleted; no new `onQueue()`; no SQLite time comparison; no `unit_price` tax-basis assertion. `PosCoreReceiptProjection` is untouched by the diff — its `:506` swallow is **reported** as R-11 with a quoted parent ruling routing it to the post-P3 fiscal projection-discipline lane, and the framing the ruling insists on ("projection error discipline — fail loudly, retryably, or dead-letter — NOT HTTP catch narrowing") is preserved verbatim in the census, the handback §7.2 and §8. Correctly *not* silently closed. No defect.

**stock-gl-interaction** — The diff adds no writer to `stock_movements`, `stock_levels`, `inventory_batch_stock` or `journal_entries`; the single-writer property, the append-only posture and COGS-at-stock-exit are all untouched. Both sides traced for the one behavioural change: at `PostShiftCashVarianceAdjustment`, whether the GL leg books is unchanged (the chokepoint already refused) — only the refusal's *record* changes from the generic `exception` bucket to a queryable `unbalanced_journal_entry` reason at `error`; and the test asserts the cash side too, `JournalEntry::query()->where('source_type','repository_adjustment')->count() === 0`, pinning fail-closed. The census's row-23 stock↔GL seam note (`reverseInventoryWriteOffEntry`) and the `ReverseWriteOffService` §3 lens note are consistent with the code. No defect.

**inventory-costing** — No WAC, FEFO, batch, transfer, landed-cost, opening-balance or movement code in the diff. The two inventory Posted-writers the census classifies balanced-by-construction (`OpeningBalancePostingService:238`, `ResetOpeningBalanceService:163` — two legs written from one variable through `bcformatStrict`) are among the six I re-derived independently. `workingScale()`/`QuantityScale` untouched. No defect.

---

## 4. Findings

All three are Minor documentation-precision items. **None changes a conclusion, a count, a classification, a guard, or a behaviour.** No lens found a production defect at A.

**[Minor] `M1-census.md:355-356` — stale internal cross-references survive the round-1 sweep.**
The §5 lead-in reads *"§5.7 adds the missing **catcher census**; §5.2 records the withdrawal; §5.8 records the real fix."* At A the catcher census is **§5.5** (`:545`) and the real fix is **§5.6** (`:623`); §5.7 is Red-first evidence and §5.8 is Acceptance commands. Every other reference in the document is correct (§0 `:22` "§5.5", `:21`/`:35` "§5.6, §5.7", M1-D7 `:818` "§5.5 adds the catcher census"). Same mis-citation class as final-gate round-1 items 2a and 5. *Suggested fix:* `§5.7 → §5.5`, `§5.8 → §5.6` at `:355-356`.

**[Minor] `M2-reconciliation.md:38-46` — the narrowed basis claim over-includes row 8.**
Round-1 fix 2f narrowed the enumeration basis to *"the complete set of `hasAccountForPurpose()` caller sites in `app/`"* (handback §10: *"the 8-row `hasAccountForPurpose` caller set"*). Verified at A: **row 8, `app/Console/Commands/BackfillTolerancePurposesCommand.php`, never calls `hasAccountForPurpose`** — the only occurrence in that file is docblock prose at `:17`; its two purposes appear as chart-definition literals at `:338`/`:345`. That is structurally the same out-of-basis category as row 9 (`BackfillChartPurposesCommand.php:318-364`), which correctly carries the `†` marker; row 8 does not. **Consequence is nil, and the error runs in the safe direction:** the 8 real call sites in `app/` — `BackfillRefundCompensationAccountsCommand:144,196`, `RefundCompensationService:188`, `EnableV4RefundAuthoringCommand:122`, `InventoryGlPostingService:53,54`, `TreasuryReceiptBridge:458,534`, `RepositoryAdjustmentService:83` — are **fully covered by rows 1–7**, which I re-derived by grep. The set is over-inclusive, never under-inclusive, so "zero misclassifications" stands unmoved. *Suggested fix:* extend the `†` out-of-basis marker to row 8 and call the caller set 7 rows. (Rows 3 and 4 cite `:187`/`:121`, the `foreach ([...])` list-declaration lines — correct for a column headed "Local list site", not an error.)

**[Minor] `ShiftCashVarianceAdjustmentTest.php:459` docblock still cites `GeneralLedgerService.php:1307`.**
Final-gate round-1 fix 2c corrected exactly this citation to `:1308` in the production comment at `PostShiftCashVarianceAdjustment.php:191`, but the test docblock documenting the same call was not swept. Verified: `:1307` is a blank line; `:1308` is `$this->postEntryNow(...)`.

---

## 5. Assessment

The five round-1 findings are genuinely closed, and I verified each against the bytes rather than against the fix record — including the two that were most forgeable: the `forChokepoint` split (one definition, on the Post class only) and the catch-clause recount (all four numbers exact). The handback's own §6 now carries M1-D7 and M1-D8, and **M1-D8 — a retracted "verified exhaustively" claim that had itself introduced a 422 regression — is stated in full rather than smoothed**, which is the disclosure the brief's silent-deviation rule exists to force.

The package's central engineering claim survives adversarial probing: the sibling type split leaves the chokepoint's blast radius provably identical to base (no exact-class comparison, no `instanceof`, no `\LogicException` catch anywhere in `app/`), while giving callers a name to narrow on. Zero class-(c) guards is the correct outcome under the gate-r1 C-2 red-first rule, not an omission, and the census proves the "can a draft seal outside the chokepoint" question structurally rather than by assertion. **R-4 is correctly carried as OPEN BY RULING and is nowhere reported as "balance invariant closed"** — the handback opens §8 with that warning. The M2 gate consumes the landed manifest without redefining it, adds the type dimension no existing seeder test could see, and lands in a lane that genuinely runs at **+0 coverage debt** against a ceiling with zero slack.

Two disclosed conditions the parent must carry into promotion, neither owed by this package: the **pre-existing 5 errors in `tests/Feature/Accounting`** (`DeliveryRequiredBeforeInvoiceException`, `DocumentPostingService.php:649`) mean landing P3 does not by itself make `treasury-spine-pgsql` green; and **M0-D1** — the landed P1/P2 pin-tag annotations carry r19-era field shapes, so M0 verified binding *substance* rather than the r12 schema literal. Both are correctly recorded rather than implied away.

The three findings above are cosmetic. Returning CHANGES-REQUIRED for them would be gate churn against a package whose evidence I was able to re-derive independently at every load-bearing point.

VERDICT: ACCEPT
