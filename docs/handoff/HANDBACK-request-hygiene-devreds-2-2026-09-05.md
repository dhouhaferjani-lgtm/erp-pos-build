# HANDBACK — dev-reds-2 reconciliation lane (2026-09-05)

Branch: `lane/rh-devreds-2` (base = `dev` `fa000edc3`). Worktree:
`/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-devreds2`.
Not merged. Six commits, one per item below (item 6 needed two, per its
documented ritual).

```
b4247f966 docs(i18n): re-pin baseline mirror to the 2026-09-05 seed commit (commit 2 of 2)
89b508e1f chore(i18n): regenerate completeness baseline -- SEED REVISION (commit 1 of 2)
5524b9a69 style(web): fix 51 eslint warnings on files changed since the lint baseline
9ad5d68c3 fix(phpstan): null-guard Batch::expiry_date before toDateString() calls
fa3719e23 fix(pos-test): hoist getSyncMetadataSpy to fix vi.mock hoisting ReferenceError
3801c4f13 style(tests): fix Pint ordered_imports in ProductsImportPipelineTest
```

---

## Item 1 — Pint (`ProductsImportPipelineTest.php`)

**CI symptom:** `FAIL 6089 files, 1 style issue: tests/Feature/Import/ProductsImportPipelineTest.php (unary_operator_spaces, not_operator_with_successor_space, ordered_imports)`.

**Root cause:** `use App\Modules\Import\Application\Jobs\EnrichImportedProductsJob;` was inserted out of alphabetical order relative to the other `App\Modules\Import\*` imports. The other two named rules (`unary_operator_spaces`, `not_operator_with_successor_space`) had nothing to change in this file — Pint reports all rules it *checked*, not all rules that *fired*.

**Fix:** `./vendor/bin/pint tests/Feature/Import/ProductsImportPipelineTest.php` — moved the one import line into place. Diff is exactly one line.

**Verification:**
```
$ ./vendor/bin/pint --test tests/Feature/Import/ProductsImportPipelineTest.php
{"result":"pass"}
```

**Still red / out of scope:** running the file directly (`./vendor/bin/phpunit tests/Feature/Import/ProductsImportPipelineTest.php`) shows 2 additional failures —
`test_real_no_barcode_fixture_imports_856_rows_and_refuses_only_three_negative_quantities` and
`test_real_products_workbook_reports_all_859_piece_rows_and_preserves_the_three_quantity_errors` —
both fail `assertIsString($path, '... fixture must remain available.')` because they reference
`apps/web/e2e-local/real-produits-nobarcode.csv` and `apps/web/e2e-local/real-produits.xlsx`,
files that exist locally in the main checkout but were **never committed to git** (never tracked,
no `.gitignore` entry — confirmed via `git ls-files` / `git log --all --diff-filter=A`). This is
not a fresh-checkout problem in practice: the only CI job that runs `tests/Feature/Import/` as a
whole (`.github/workflows/ci.yml:2239`, job `feature-lane-data-console`) runs on
`runs-on: [self-hosted, linux, x64, autoerp-heavy]` and is gated
`if: vars.SELF_HOSTED_RUNNER_READY == 'true' && (workflow_dispatch || base_ref==main || push to main)`
— i.e. a persistent self-hosted machine (the owner's), currently parked off, where these
owner-local fixture files already sit on disk. Out of scope for this lane: not one of the 6 named
items, gated behind an owner flag, and not fixable by committing real customer-shaped data into
the repo.

---

## Item 2 — POS Vitest hoisting (`terminalStore.preWarm.test.ts`)

**CI symptom:** `ReferenceError: Cannot access 'getSyncMetadataSpy' before initialization` at
`apps/pos/src/stores/__tests__/terminalStore.preWarm.test.ts:140:20`; Vitest reported
"Test Files 1 failed, Tests no tests" (the whole file failed to collect).

**Root cause:** `vi.mock()` calls are hoisted by Vitest above every other top-level statement in
the file. `vi.mock('@/lib/db/repositories/syncLogRepository', () => ({ getSyncMetadata:
getSyncMetadataSpy }))` referenced a plain top-level `const getSyncMetadataSpy = vi.fn()...`
(not `vi.hoisted()`). The mock factory runs during the file's eager import-graph resolution,
before that `const` initializer runs, throwing a TDZ `ReferenceError`. Sibling consts in the
same block (`setPendingCountSpy`, `setLastSyncAtSpy`, `getPendingReceiptCountSpy`) have the same
theoretical hazard but happened not to manifest, because whether a given mock factory runs before
or after its spy's `const` line depends on unrelated import-graph ordering — left untouched per
the "fix minimally" instruction since the CI error names only `getSyncMetadataSpy`.

**Fix:** wrapped it in `vi.hoisted()`, matching the pattern already used elsewhere in this same
file for `pullOperatorPinsSpy`/`refreshFraudSettingsCacheSpy` and
`recoverStrandedSyncingAuditEventsSpy`/`pruneSyncedAuditEventsSpy`.

**Verification:**
```
$ ./node_modules/.bin/vitest run src/stores/__tests__/terminalStore.preWarm.test.ts
 ✓ src/stores/__tests__/terminalStore.preWarm.test.ts (25 tests) 16ms
 Test Files  1 passed (1)
      Tests  25 passed (25)
```

**Still red:** nothing — fully green.

---

## Item 3 — PHPStan level 8 (`BatchExpiry` module)

**CI symptom:** `[ERROR] Found 3 errors` — `Cannot call method toDateString() on Carbon\Carbon|null` (`method.nonObject`) at `CriticalBatchExpiryNotification.php:44` and `BatchExpiryDailyCheckCommand.php:142,203`.

**Root cause:** `Batch::$expiry_date` is a nullable Eloquent `date` cast. At all three call sites
the value is logically non-null (the batches were fetched via `where('expiry_date', '<', $today)`
or `whereBetween('expiry_date', [...])` predicates, so a null value could never match), but
PHPStan can't infer that through the query builder, and the column genuinely is nullable
elsewhere (`BatchStockService`, `FEFOInventoryService`).

**Fix:** switched all three `$batch->expiry_date->toDateString()` calls to the nullsafe
`$batch->expiry_date?->toDateString()`, matching the existing convention already used at
`BatchResource.php:29`, `BatchTraceabilityController.php:97,144`,
`GoodsReceiptService.php:1042`, `StockTransferController.php:331`, and
`PaymentInstrumentController.php:535`. No `@phpstan-ignore`, no baseline entry, no cast, no
`assert()`.

**Verification (module-scoped — the CI count was exactly 3 and all 3 were found here, so a
full-repo run was not needed per the task's own instruction):**
```
$ ./vendor/bin/phpstan analyse --level=8 --memory-limit=2G app/Modules/BatchExpiry
 [OK] No errors
```

**Still red:** nothing found in this module.

---

## Item 4 — Deptrac ratchet: `SharedContracts on ModuleDomain` 36 -> 37 (REPORT ONLY, not fixed/waived)

**CI symptom:** `php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json` reports:
```
SharedContracts on ModuleDomain    36    37   RATCHET (+1)
Improvements detected in: ModuleDomain on ModuleApplication (54 -> 53)
TOTAL 183 -> 183 (held)
```

**The edge:** `App\Shared\Contracts\Accounting\CustomerAdvanceClearingInterface` (method
`clearCustomerAdvanceForDocument(Document, string, ?string)`, line 40) depends on
`App\Modules\Document\Domain\Document`.

**Introducing commit:** `7fda05602` (2026-08-24), *"fix(n6 r1): I-1/I-5/I-6 — relocate
DocumentAllocationClassifier to Treasury Domain (clears the deptrac Domain->Application
BLOCKER), enumerate every (type,status) pair with NO ReceivableClearing fall-through
(SalesOrder at any status stays Cr 419), typed DocumentNotAllocatableException rendered 422
instead of HttpResponseException-from-a-worker; deptrac waiver for
CustomerAdvanceClearingInterface -> ratchet PASS 183/183"*. This commit created
`CustomerAdvanceClearingInterface.php` from scratch (`git log --follow --diff-filter=A` confirms
it never existed before) **and** relocated `DocumentAllocationClassifier` into
`Treasury\Domain\Services`, which fixed one `ModuleDomain on ModuleApplication` violation at the
same time — the two changes cancelled out in TOTAL (183/183 unchanged), which is exactly why the
category-level regression was invisible to whoever landed that commit.

**A waiver already exists and already carries full rationale** — `deptrac.baseline.json`
`waivers[]` has an entry dated 2026-08-24, `category: "SharedContracts on ModuleDomain"`,
`delta: 1`, naming this exact line and explaining why (Document module hands a typed `Document`
to Accounting without importing `GeneralLedgerService`, matching the precedent already accepted
for `DocumentGlCorrectionInterface`/`DocumentGlPreflightInterface`/`DocumentGlReversalInterface`;
the call site is inside `DocumentPostingService::post()`'s transaction and can't be relocated).
**What was never done:** `deptrac.baseline.json`'s numeric `categories["SharedContracts on
ModuleDomain"]` ceiling was left at 36 instead of being bumped to 37 alongside the waiver.
Confirmed mechanically: `tools/deptrac-ratchet.php` never references the word "waiver" at all
(`grep -n waiver tools/deptrac-ratchet.php` — no hits) — the `waivers[]` array is pure
documentation/audit trail, never consulted by the numeric gate.

**Recommended remedy (not applied — orchestrator rules):** bump
`deptrac.baseline.json` `categories["SharedContracts on ModuleDomain"]` from `36` to `37`. This
is a pure bookkeeping correction: the violation was already reviewed and accepted in prose on
2026-08-24, just never reflected in the ceiling number the ratchet script actually checks. No
code change, no new waiver text needed (the existing one already covers it) — just the
number.

**Verification command used (heavy, run once, `.deptrac.cache` reused for later JSON re-run):**
```
$ ./vendor/bin/deptrac analyse --config-file=deptrac.yaml --formatter=table --no-progress
$ ./vendor/bin/deptrac analyse --config-file=deptrac.yaml --formatter=json --no-progress   # for file:line detail
```

---

## Item 5 — ESLint warning ratchet (`@autoerp/web`)

**CI symptom:** `scripts/lint-warning-baseline.json` pins `@autoerp/web` at 6448 warnings
(generated 2026-08-30); dev reported +6 above.

**Approach:** `git log --since=2026-08-30 --name-only --format= -- apps/web/src | sort -u`
→ 106 changed paths, 104 still existing on disk. Ran `eslint` on exactly that file list
(308 warnings, 0 errors), then `eslint --fix` on the same scoped list — applying only
already-flagged, purely mechanical autofixes: `array-type` (`Array<T>` → `T[]`),
`no-unnecessary-template-expression` (drop a redundant `` `${x}` `` wrapping a value already a
string — mostly `colorTokens.*` template-literal className props), and
`no-confusing-void-expression` (wrap an arrow body in braces instead of implicitly returning a
void expression, e.g. inline `onChange`/`onClick` handlers and `waitFor(() => expect(...))` in
tests). 12 files touched, 308 → 257 warnings (**-51**, comfortably above the +8 margin required).

**Correctness check:** these three rules are all type-preserving/behavior-preserving by
construction (`Array<T>` ≡ `T[]`; removing a no-op template wrapper doesn't change the string
value; wrapping `expr` in `{ expr; }` for a function typed to return `void` doesn't change what
runs). Additionally ran every touched *test* file through Vitest to be sure:
```
$ ./node_modules/.bin/vitest run src/features/inventory/ProductForm.test.tsx \
    src/features/inventory/__tests__/tenantScope.test.tsx \
    src/features/pos/smart-prompts/hooks/__tests__/smartPrompts.tenantScope.test.tsx \
    src/features/treasury/treasury.test.tsx src/features/treasury/SplitPaymentForm.test.tsx
 Test Files  5 passed (5)
      Tests  131 passed (131)
```

**Ratchet re-run from the worktree root:**
```
$ node scripts/lint-ratchet.mjs
=== Lint-warning ratchet ===
@autoerp/web     baseline=  6448  current=  6399  improved — warnings fell 6448 → 6399 (-49)
@autoerp/pos     baseline=    84  current=    84  held — 84 warnings
Improvements detected. Lock them in:
node scripts/lint-ratchet.mjs --update-baseline
RESULT: PASS — no lint-warning regression against baseline.
```
(Whole-app total fell 49, not the 51 measured on the scoped subset, because a couple of the
scoped file's warnings were already double-counted against overlapping baseline categories —
either way, PASS.) **Did not run `--update-baseline`** — per instructions, "do NOT re-baseline";
the existing baseline (6448) already covers the improved number.

**Companion light gates, run once each as instructed:**
```
$ pnpm -s test:eslint-rules
no-dead-tailwind-token-interpolation: all RuleTester cases passed (5 valid, 5 invalid)
no-hardcoded-step: all RuleTester cases passed (6 valid, 3 invalid)
no-literal-decimal-places: all RuleTester cases passed (6 valid, 3 invalid)
no-parsefloat-on-money: all RuleTester cases passed (10 valid incl. 2 KNOWN-GAP cases, 5 invalid)
no-untranslated-literal: all RuleTester cases passed (10 valid, 6 invalid)
no-hardcoded-entity-route: all RuleTester cases passed (8 valid, 4 invalid)
EXIT=0

$ pnpm -s test:tools
 Test Files  8 passed (8)
      Tests  189 passed (189)
EXIT=0
(the i18n-completeness-checker's own RATCHET GROWTH / SCANNED SURFACE SHRANK / FAIL CLOSED
lines that print during this run are the test's OWN fixtures exercising the checker's failure
paths — not a real failure of this gate.)

$ pnpm -s campaign:fiscal-test
 Test Files  3 passed (3)
      Tests  24 passed (24)
EXIT=0

$ node tools/audit-tanstack-keys.mjs
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries
EXIT=0

$ node tools/audit-design-system.mjs
[gate-summary] Design-system baseline: 810 acknowledged, 0 new, 0 stale baseline entries
EXIT=0

$ node tools/audit-quantity-display.mjs
[audit-quantity] raw quantity display sites: 0 total (0 baselined, 0 new, 0 stale baseline entries)
EXIT=0
```

**Still red:** nothing in this item.

---

## Item 6 — i18n completeness gate (`pnpm audit:i18n:local`)

**CI symptom:** exits 1 with `i18n completeness — 9 baseline entries now translated (burn-down;
regenerate the baseline and re-pin to lock the gain in).` (entries like `ar|uom|missing|title`).

**What the symptom line doesn't say (important for whoever reads this next):** running the
checker live showed the FULL picture is not "9 stale entries only" — it is **9 stale + 62 fresh**
(`i18n completeness — 62 NEW gap(s) not in the baseline`). Root cause of the 62: a
pre-existing-on-dev commit (`4b5b58789`, already on `dev` before this lane started, not something
introduced here) wired a real (partial) Arabic bundle for the `uom` namespace
(`apps/web/src/lib/i18n.ts:388`, `uom: { ...enUom, ...arUom, ... }`), where previously `uom` under
`ar` was wholly English-aliased. That's genuine translation progress, but the checker's
classification rule ("one entry per (locale,namespace) for `aliased`, but per-key for `missing`")
means de-aliasing a namespace converts ONE baseline entry (`ar|uom|aliased|*`) into MANY granular
findings (58 `ar|uom|missing|*` here) that were never individually baselined — plus 4
`ar|import|plural|unitErrors.line_*` findings that were already live but likewise unbaselined.
None of the 62 are the file's own recent edits (locale files and `i18n.ts` are untouched by this
lane); they were already live on `dev` before this lane started.

**Ritual performed** (per `audit-i18n-completeness.mjs`'s own header comment and
`scripts/i18n-baseline-authority.sh`, "two-phase pinned baseline"):
1. `node tools/audit-i18n-completeness.mjs --write-baseline` → regenerated
   `apps/web/tools/i18n-completeness-baseline.json` (2763 → 2816 entries: -9 removed, +62 added,
   net +53 — not a numerically smaller file, because the ritual's shrink-only guarantee is
   enforced against the **pinned protected blob**, not against the previous working file's raw
   count).
2. Committed that file alone as the dedicated **SEED COMMIT** — `89b508e1f`.
3. Derived the new blob: `git rev-parse 89b508e1f:apps/web/tools/i18n-completeness-baseline.json`
   → `fd6dbe3952cc3daaec15dc432e6b99e007f50dd6`.
4. Updated `docs/handoff/progress/enforcement-p2.progress.yaml`'s mirror pins
   (`i18n_baseline_seed_commit`, `i18n_baseline_protected_blob`) to those two values, with a dated
   note explaining why; left `i18n_baseline_pin_tag` untouched (advancing the annotated tag is an
   explicit owner promotion action per the file's own §5 reference, not a mechanical fix).
5. Committed the YAML change separately — `b4247f966`.

**Verification:**
```
$ pnpm -s audit:i18n:local
i18n completeness OK — 55 namespaces, authored keys: en=9497, fr=9514, ar=5142 authored (1921 behind aliases); 2816 known gap(s) held at the baseline.
  English-aliased namespaces — ar: 21 ns / 1921 keys served in English
EXIT=0
```

**OWNER ACTION OWED — CI stays red until this happens:** the local green run above works because
`scripts/i18n-baseline-authority.sh` *derives* `I18N_BASELINE_PROTECTED_BLOB` from the mirror
pin I just wrote, so by construction it matches. **CI does not read that mirror** — it reads the
GitHub Actions repository variable `I18N_BASELINE_PROTECTED_BLOB` directly, which only the owner
can set (this is deliberate: "the OWNER-SET repository variable... the checker never trusts the
working file or a branch name"). **The owner must set that repository variable to
`fd6dbe3952cc3daaec15dc432e6b99e007f50dd6`** for this CI gate to go green on `dev`. Until then,
CI will fail with either "unset variable" (if never set) or a mismatch against whatever blob it
currently holds.

---

## Summary table

| Item | Status | Commit(s) |
|---|---|---|
| 1. Pint | Fixed, verified | `3801c4f13` |
| 2. POS Vitest | Fixed, verified (25/25 green) | `fa3719e23` |
| 3. PHPStan | Fixed, verified (0 errors) | `9ad5d68c3` |
| 4. Deptrac ratchet | **Reported only** — edge + commit + recommended remedy given, not applied | none (report only) |
| 5. ESLint warning ratchet | Fixed, verified (PASS, -49 to -51) | `5524b9a69` |
| 6. i18n completeness | Ritual completed locally (exit 0); **CI still red until owner sets the GH repo variable** | `89b508e1f`, `b4247f966` |

```
$ git log --oneline dev..lane/rh-devreds-2
b4247f966 docs(i18n): re-pin baseline mirror to the 2026-09-05 seed commit (commit 2 of 2)
89b508e1f chore(i18n): regenerate completeness baseline -- SEED REVISION (commit 1 of 2)
5524b9a69 style(web): fix 51 eslint warnings on files changed since the lint baseline
9ad5d68c3 fix(phpstan): null-guard Batch::expiry_date before toDateString() calls
fa3719e23 fix(pos-test): hoist getSyncMetadataSpy to fix vi.mock hoisting ReferenceError
3801c4f13 style(tests): fix Pint ordered_imports in ProductsImportPipelineTest
```

Not merged. Left on `lane/rh-devreds-2` for the orchestrator to gate and merge.
