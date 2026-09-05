# HANDBACK — dev-reds-2 reconciliation lane (2026-09-05)

Branch: `lane/rh-devreds-2` (base = `dev` `fa000edc3`). Worktree:
`/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-devreds2`.
Not merged. Ten commits total: one per item below (item 6 needed two, per
its documented ritual), plus a deptrac follow-up (item 4's ADDENDUM,
applied per orchestrator ruling), a fix-round-1 commit (F-1, restoring
two type assertions gate r1 found had been dropped — see below), and
this handback doc (amended in place through several rounds rather than
appended commit-by-commit, per the harness's own note-taking).

```
$ git log --oneline dev..lane/rh-devreds-2
61a9c5fef fix(web tests): restore the two HTMLInputElement assertions the eslint pass dropped (tsc TS2339)
e8829bcd0 docs(handoff): append item-4 addendum -- deptrac ceiling bump applied per ruling
3ccf6a26a chore(deptrac): carry the waived CustomerAdvanceClearingInterface->Document edge in the SharedContracts→ModuleDomain ceiling (36->37)
317b12a5b docs(handoff): dev-reds-2 reconciliation handback
b4247f966 docs(i18n): re-pin baseline mirror to the 2026-09-05 seed commit (commit 2 of 2)
89b508e1f chore(i18n): regenerate completeness baseline -- SEED REVISION (commit 1 of 2)
5524b9a69 style(web): fix 51 eslint warnings on files changed since the lint baseline
9ad5d68c3 fix(phpstan): null-guard Batch::expiry_date before toDateString() calls
fa3719e23 fix(pos-test): hoist getSyncMetadataSpy to fix vi.mock hoisting ReferenceError
3801c4f13 style(tests): fix Pint ordered_imports in ProductsImportPipelineTest
```

## Gate r1 — CHANGES → fix round 1 applied

Gate r1 (`docs/superpowers/reviews/2026-09-05-request-hygiene-devreds-2-gate-r1.md`) returned
**CHANGES**, one BLOCKER (F-1) and six lower-severity findings (F-2 through F-7). Fix round 1,
in this same worktree/branch, addressed everything actionable:

- **F-1 (BLOCKER, fixed):** commit `5524b9a69` had removed two `as HTMLInputElement` type
  assertions at `ProductForm.test.tsx:772,816`, breaking `tsc --noEmit` (TS2339) and turning the
  required CI job `frontend-typecheck` red — the exact opposite of this lane's purpose. Restored
  both assertions verbatim in commit `61a9c5fef`. Verified:
  ```
  $ cd apps/web && ./node_modules/.bin/tsc --noEmit
  (no output, exit 0)

  $ cd .. && node scripts/lint-ratchet.mjs
  @autoerp/web     baseline=  6448  current=  6401  improved — warnings fell 6448 → 6401 (-47)
  @autoerp/pos     baseline=    84  current=    84  held — 84 warnings
  RESULT: PASS — no lint-warning regression against baseline.
  ```
- **F-2 (MAJOR, documented):** item 6's owner-action list was incomplete. Rewritten below with
  the full ordered ritual (merge → tag → variable) and the ordering-constraint warning.
- **F-3 (MINOR, documented):** 4 of the 62 new baseline entries are real, previously-unbaselined
  Arabic plural debt, not granularisation. Called out explicitly below with a follow-up-ticket
  note (owner/next-session, not this lane).
- **F-4 (MINOR, documented):** commit `5524b9a69`'s message undercounted its rule list by one
  (`@typescript-eslint/consistent-type-definitions` also fired). Disclosed in item 5 below;
  the historical commit message itself was not amended (would require rebasing three already
  gate-reviewed commits after it for a message-only fix).
- **F-5 (MINOR, documented):** corrected ".gitignore" → the actual mechanism,
  `.git/info/exclude:21`, in item 1 below. Gate r1's `markTestSkipped` suggestion recorded as a
  follow-up, out of this lane's remit (see item 1).
- **F-6 (INFO, documented):** corrected item 6's CI-symptom attribution (the exit 1 comes from
  62 fresh findings, not the harmless 9-stale `console.log`) and the seed commit's 8→7 miscount.
- **F-7 (INFO):** no action — awareness only, acknowledged.

Not re-merged after this round; still awaiting gate r2 / orchestrator sign-off on
`lane/rh-devreds-2`.

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
files that exist locally in the main checkout but were **never committed to git** (never tracked
— confirmed via `git ls-files` / `git log --all --diff-filter=A`). **Correction (gate r1 F-5):**
these are not merely un-gitignored; the whole `apps/web/e2e-local/` directory is deliberately
excluded via `.git/info/exclude:21` (a local, untracked-file exclude — `.gitignore` is unrelated
and, correctly, does not list it). That is the more relevant fact: it is *why* these files can
never be committed by accident, not just an absence of a rule. This is
not a fresh-checkout problem in practice: the only CI job that runs `tests/Feature/Import/` as a
whole (`.github/workflows/ci.yml:2239`, job `feature-lane-data-console`) runs on
`runs-on: [self-hosted, linux, x64, autoerp-heavy]` and is gated
`if: vars.SELF_HOSTED_RUNNER_READY == 'true' && (workflow_dispatch || base_ref==main || push to main)`
— i.e. a persistent self-hosted machine (the owner's), currently parked off, where these
owner-local fixture files already sit on disk. Out of scope for this lane: not one of the 6 named
items, gated behind an owner flag, and not fixable by committing real customer-shaped data into
the repo.

**Follow-up suggested by gate r1 (not this lane's remit):** replace the `assertIsString(...)`
hard-fail precondition with `markTestSkipped('...fixture is owner-local, see ...')` guarded on
`$path === false`. Rationale for: `assertIsString` makes an environment precondition
indistinguishable from a product regression — anyone who flips `SELF_HOSTED_RUNNER_READY` on a
machine without these fixtures gets a red that reads as a data-pipeline bug. Rationale against
(why it stays out of this lane): the hard failure is a deliberate tripwire so the owner notices
if the fixtures ever go missing from the self-hosted box, and converting it is a behaviour
change to a test, not a CI-reconciliation fix.

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

### ADDENDUM (2026-09-05, after orchestrator ruling): remedy applied

The orchestrator ruled to apply the recommended remedy rather than leave it report-only.
Read `tools/deptrac-ratchet.php` again specifically for the total-check question the ruling
asked about: the script has **two independent failure conditions** — (1) each category fails
if `current > baseline` for THAT category alone, entirely independent of the total; (2) total
fails only if `currentTotal > baselineTotal`, where `baselineTotal` is read as the literal
`"total"` JSON field (`$baselineRaw['total']`), **not** re-derived as `array_sum($baselineCategories)`
unless the field is absent. So bumping `SharedContracts on ModuleDomain` alone to 37 without
touching `"total"` would already have passed (183 == 183, using the old literal total field) —
the total bump to 184 was **not required** by the script's own logic. Applied anyway, per the
ruling's own fallback instruction, the version that mirrors exactly what
`--update-baseline` would write today: bumped `SharedContracts on ModuleDomain` 36 → 37 **and**
lowered `ModuleDomain on ModuleApplication` 54 → 53 (the same-commit, already-observed,
pre-existing improvement from `7fda05602`'s `DocumentAllocationClassifier` relocation), keeping
`"total"` at 183 — which now also equals `sum(categories)` (68+53+1+18+37+4+2 = 183), so the
file is internally self-consistent. Did **not** run `--update-baseline` (it would silently drop
the entire `waivers[]` array — its written payload is only `{generated_at, note, total,
categories}`); hand-edited `apps/api/deptrac.baseline.json` instead, and extended the existing
2026-08-24 waiver entry for `CustomerAdvanceClearingInterface` with a new `ceiling_note` field
(the required explanation text), leaving every other waiver entry and the `waivers[]` array
shape untouched.

**Verification:**
```
$ php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json
=== Deptrac ratchet ===
Config:   deptrac.yaml
Baseline: deptrac.baseline.json

Deptrac report: 183 violations, 14726 allowed, 13809 uncovered.

Category                                       baseline  current   status
------------------------------------------------------------------------------
ModuleApplication on ModuleInfrastructure            68       68   held
ModuleDomain on ModuleApplication                    53       53   held (domain)
ModuleInfrastructure on ModulePresentation            1        1   held
SharedContracts on ModuleApplication                 18       18   held
SharedContracts on ModuleDomain                      37       37   held
SharedDomain on ModuleDomain                          4        4   held
SharedInfrastructure on ModuleDomain                  2        2   held
------------------------------------------------------------------------------
TOTAL                                               183      183

RESULT: PASS — no boundary regression against baseline.
```

**Commit:** `3ccf6a26a` — `chore(deptrac): carry the waived CustomerAdvanceClearingInterface->Document edge in the SharedContracts→ModuleDomain ceiling (36->37)`.

**Still red:** nothing — item 4 is now fully closed (fixed + verified), not just reported.

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

**Correction (gate r1 F-4):** the original commit message named only the three rules above. A
fourth also fired and landed in the same diff — `@typescript-eslint/consistent-type-definitions`
(`ProductForm.test.tsx:70`, `type MutationOptions = {...}` → `interface MutationOptions {...}`),
behaviour-neutral (a type alias and an equivalent interface are interchangeable here). The commit
message should have named all four; it named three. (Gate r1 also flagged two `as
HTMLInputElement` removals in this same diff that were **not** produced by any of these four
rules and were not behaviour-neutral — see the F-1 fix-round commit `61a9c5fef`, which restored
them; `tsc --noEmit` is now clean.)

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

**Re-verified after the F-1 fix round** (restoring the two `as HTMLInputElement` assertions puts
2 warnings back — `@typescript-eslint/no-unsafe-type-assertion` fires on them again, as it does
on the file's ten sibling assertions):
```
$ node scripts/lint-ratchet.mjs
@autoerp/web     baseline=  6448  current=  6401  improved — warnings fell 6448 → 6401 (-47)
@autoerp/pos     baseline=    84  current=    84  held — 84 warnings
RESULT: PASS — no lint-warning regression against baseline.
```
Still comfortably under the 6448 ceiling.

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

**CI symptom — corrected (gate r1 F-6; the seed commit's own message misattributed this, see
below):** `pnpm audit:i18n:local` exits 1 with `i18n completeness — 62 NEW gap(s) not in the
baseline:` (a list including `ar|uom|missing|title`, `ar|import|plural|unitErrors.line_few`,
etc.). A second, non-fatal line also prints — `i18n completeness — 9 baseline entries now
translated (burn-down; regenerate the baseline and re-pin to lock the gain in).` — but **that
line can never cause the exit 1 on its own**: `audit-i18n-completeness.mjs`'s `stale` branch is a
bare `console.log` with no `failed = true` assignment, and the file's own header comment states
it explicitly ("Stale baseline entries are burn-down (a note, never a failure)"). The seed
commit's message (`89b508e1f`) quoted only the harmless stale line as "the CI red", which reads
as though burn-down alone tripped the gate — it did not. **The actual, exit-1-causing failure is
the 62 fresh findings** (`audit-i18n-completeness.mjs:781-785`, which does set `failed = true`).

Root cause of the 62 fresh findings splits into two genuinely different categories — **58 are
re-classification of already-known debt, 4 are new, real, previously-unbaselined debt:**

- **58 `ar|uom|missing|*` — granularisation, not new debt.** A pre-existing-on-dev commit
  (`4b5b58789`, confirmed an ancestor of `dev` — already on `dev` before this lane started, not
  something introduced here) wired a real (partial) Arabic bundle for the `uom` namespace
  (`apps/web/src/lib/i18n.ts:388`, `uom: { ...enUom, ...arUom, ... }`), where previously `uom`
  under `ar` was wholly English-aliased. `en/uom.json` authors 77 keys, `ar/uom.json` authors 19
  → exactly 58 missing. That's genuine translation progress (19 keys newly translated), but the
  checker's classification rule ("one entry per (locale,namespace) for `aliased`, but per-key for
  `missing`") means de-aliasing a namespace converts ONE baseline entry (`ar|uom|aliased|*`) into
  58 granular `missing` findings that were never individually baselined. Net effect is *less*
  debt, at finer grain — no debt hidden here.
- **4 `ar|import|plural|unitErrors.line_{zero,two,few,many}` — real, unbaselined Arabic plural
  debt, NOT granularisation.** `ar|import` was never aliased — the old baseline already carried
  170 per-key `ar|import|missing|*` entries for this namespace, so this is not the same
  reclassification mechanism as `uom`. `apps/web/src/locales/ar/import.json`'s `unitErrors`
  object authors only `line_one` and `line_other`; Arabic CLDR requires all six plural categories
  (`zero`, `one`, `two`, `few`, `many`, `other`). These 4 forms were always missing and were
  always live findings — they were simply never entered into the baseline before this lane's
  regeneration. Baselining plural-category gaps is the established, consistent treatment already
  used 42 times elsewhere in this file (`fr|*|plural|*`, `en|*|plural|*`), so folding these 4 in
  is not inconsistent — but it IS blessing 4 real gaps, not merely re-describing an old one, and
  the original framing understated that.

**FOLLOW-UP TICKET (owner/next-session, not this lane):** author the 4 missing Arabic plural
forms for `ar|import|unitErrors` —
`line_zero`, `line_two`, `line_few`, `line_many` (CLDR Arabic requires all six categories;
`line_one` and `line_other` already exist in `apps/web/src/locales/ar/import.json`). This is a
~4-string translation edit; once landed, these 4 entries drop out of the baseline on the next
regeneration and stop being blessed debt. Filed here rather than done in this lane because this
is a CI-reconciliation lane (mechanical fixes to make the gate accurately reflect existing state),
not a translation-authoring lane.

None of the 62 are the file's own recent edits (locale files and `i18n.ts` are untouched by this
lane); they were already live on `dev` before this lane started.

**Two inaccuracies in the seed commit's own message (`89b508e1f`), not amended (gate r1 F-6,
archaeology-only, not re-litigated by rewriting the reviewed commit):** (1) it says "CI red:
pnpm audit:i18n:local exits 1 ('9 baseline entries now translated…')" — as established above,
that line cannot cause an exit 1; the actual cause is the 62 fresh findings. (2) it says
"8x ar|sales|missing.partners.b2b.*" in the removed-entries list; the measured count is **7**
(9 removed total = 7 `ar|sales|missing` + 1 `ar|pos|missing` + 1 `ar|uom|aliased` catch-all).
Both corrected here; the commit's actual file diff (the regenerated baseline JSON) is unaffected
by either inaccuracy and was independently re-measured by gate r1 to match.

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

**OWNER ACTION OWED — CI stays red until this happens.** The local green run above works because
`scripts/i18n-baseline-authority.sh` *derives* `I18N_BASELINE_PROTECTED_BLOB` from the mirror
pin I just wrote, so by construction it matches. **CI does not read that mirror** — it reads the
GitHub Actions repository variable `I18N_BASELINE_PROTECTED_BLOB` directly (`ci.yml:2444`, `env:
I18N_BASELINE_PROTECTED_BLOB: ${{ vars.I18N_BASELINE_PROTECTED_BLOB }}`), which only the owner
can set (this is deliberate: "the OWNER-SET repository variable... the checker never trusts the
working file or a branch name").

**Correction (gate r1 F-2): setting the repository variable alone is necessary but NOT
sufficient, and doing it in the wrong order actively breaks CI for every other branch.** The
full ritual, in order, is:

1. **Merge `lane/rh-devreds-2` to `dev` and push to `origin/dev` FIRST.** Until this branch's
   mirror-pin commit (`b4247f966`, `i18n_baseline_seed_commit: 89b508e1f…`,
   `i18n_baseline_protected_blob: fd6dbe39…`) is on `origin/dev`, every other branch/PR still
   carries the OLD mirror pin (`6a0c1cd72…` / `26a9ae16…`) in its checked-out
   `enforcement-p2.progress.yaml`.

2. **Only after step 1, create a new durable pin tag reaching the new seed commit, and push it.**
   `ci.yml:2427` hardcodes a fixed tag name in a blob-fetch step:
   ```yaml
   - name: Fetch the pinned i18n baseline revision
     run: git fetch origin tag ci-pin/enforcement-p2-r1
   ```
   with the comment "actions/checkout fetches a single commit, so that blob is not in the object
   store until we fetch the durable pin tag that reaches it." **The tag currently only reaches
   the OLD seed commit and its OLD blob** — verified: `git rev-parse
   ci-pin/enforcement-p2-r1:apps/web/tools/i18n-completeness-baseline.json` → `26a9ae1688…` (the
   old blob), and `git merge-base --is-ancestor 89b508e1f ci-pin/enforcement-p2-r1` → NO. Exact
   commands for the owner, run from a checkout with push rights, once `origin/dev` contains
   `89b508e1f` (per step 1):
   ```
   git fetch origin dev
   git tag -f ci-pin/enforcement-p2-r1 origin/dev   # or the specific 89b508e1f sha directly
   git push origin refs/tags/ci-pin/enforcement-p2-r1 --force
   ```
   (Either target works — the blob is content-addressed, so any commit whose tree still has that
   exact baseline-file content, `89b508e1f` itself or any later `dev` commit that hasn't
   re-touched the file, brings the same blob object `fd6dbe3952cc3daaec15dc432e6b99e007f50dd6`
   into the fetched object store.) **WHY this step cannot be skipped:** on a **push to `dev`**,
   the new blob happens to already be in the checked-out tree (the full commit that introduced it
   is part of the branch history being built/tested), so `git cat-file blob fd6dbe…` succeeds
   even with a stale tag — which is exactly why this gap was invisible from this lane's own local
   verification. But on **any PR from a branch that does not already contain commit `89b508e1f`**
   (e.g. a feature branch cut from an older `dev` point, or any branch that legitimately shrinks
   the baseline further later), `actions/checkout` never fetches that specific historical blob
   object, and the ONLY mechanism that injects it is the tag-fetch step. With the tag still
   pointing at the old commit, `git cat-file blob fd6dbe3952cc3daaec15dc432e6b99e007f50dd6` fails
   ("cannot read the protected baseline blob") and the gate fails closed — for a reason nobody
   reviewing that PR's own diff would think to look for.

3. **Only after step 2's tag is pushed, set the repository variable:**
   `I18N_BASELINE_PROTECTED_BLOB = fd6dbe3952cc3daaec15dc432e6b99e007f50dd6`.

**Ordering constraint (why steps 1-3 must run in exactly this order, not merely "eventually
all three"):** if the owner sets the repository variable in step 3 *before* step 1 lands on
`origin/dev`, then from that moment until this branch merges, **every other open branch's CI
run fails closed** on the i18n gate — not just this lane's. Those branches still carry the OLD
mirror pin (`26a9ae16…`) in their own checked-out `enforcement-p2.progress.yaml`, so the
checker's `MIRROR DRIFT` check (`audit-i18n-completeness.mjs:723-731`) sees
`I18N_BASELINE_PROTECTED_BLOB` (now `fd6dbe39…`) ≠ the YAML mirror they carry (`26a9ae16…`) and
fails every one of them, immediately, for a reason that has nothing to do with their own change.

**Current status: none of steps 1-3 have happened.** With the variable still unset (or still on
the old blob), CI fails at "unset variable" or `MIRROR DRIFT` today; once this branch is merged
without steps 2-3, a push to `dev` will still pass this gate by the coincidence explained above,
but the underlying tag gap remains latent until the first PR that doesn't already contain
`89b508e1f` hits it.

---

## Summary table

| Item | Status | Commit(s) |
|---|---|---|
| 1. Pint | Fixed, verified; F-5 correction applied | `3801c4f13` |
| 2. POS Vitest | Fixed, verified (25/25 green) | `fa3719e23` |
| 3. PHPStan | Fixed, verified (0 errors) | `9ad5d68c3` |
| 4. Deptrac ratchet | Fixed, verified (PASS) — applied per orchestrator ruling, see ADDENDUM | `3ccf6a26a` |
| 5. ESLint warning ratchet | Fixed, verified (PASS, -47 to -51); F-1 BLOCKER fixed, F-4 rule-list correction applied | `5524b9a69`, `61a9c5fef` |
| 6. i18n completeness | Ritual completed locally (exit 0); F-2/F-3/F-6 corrections applied; **CI still red — full ordered owner-action ritual above, not just "set the variable"** | `89b508e1f`, `b4247f966` |

```
$ git log --oneline dev..lane/rh-devreds-2
61a9c5fef fix(web tests): restore the two HTMLInputElement assertions the eslint pass dropped (tsc TS2339)
e8829bcd0 docs(handoff): append item-4 addendum -- deptrac ceiling bump applied per ruling
3ccf6a26a chore(deptrac): carry the waived CustomerAdvanceClearingInterface->Document edge in the SharedContracts→ModuleDomain ceiling (36->37)
317b12a5b docs(handoff): dev-reds-2 reconciliation handback
b4247f966 docs(i18n): re-pin baseline mirror to the 2026-09-05 seed commit (commit 2 of 2)
89b508e1f chore(i18n): regenerate completeness baseline -- SEED REVISION (commit 1 of 2)
5524b9a69 style(web): fix 51 eslint warnings on files changed since the lint baseline
9ad5d68c3 fix(phpstan): null-guard Batch::expiry_date before toDateString() calls
fa3719e23 fix(pos-test): hoist getSyncMetadataSpy to fix vi.mock hoisting ReferenceError
3801c4f13 style(tests): fix Pint ordered_imports in ProductsImportPipelineTest
```
(plus the commit that lands this fix-round update to this doc, newest on the branch — see
`git log --oneline dev..lane/rh-devreds-2` after it lands.)

Not merged. Left on `lane/rh-devreds-2` for the orchestrator / gate r2.
