# HANDBACK — web lint-gate debt (lane/rh-web-lint-debt, 2026-09-03, fix round 2026-09-04)

Base commit: `6f16fd8f7` (dev). Worktree: `apps/erp/.worktrees/rh-lint`. Scope: make `cd apps/web && pnpm lint` (local chain) and `pnpm lint:ratchet` (the actual CI gate, root `package.json`) green **without touching `apps/web/src/features/import/**`**.

**Revision note (2026-09-04):** the first pass (`4b5b58789`) fixed item 1 by adding `e2e-local/*.ts` to `apps/web/e2e/tsconfig.json`'s `include`, which brought those 5 files under the shared e2e TypeScript project and ESLint's typed parser. A gate review returned CHANGES on that approach (MAJOR-2): `e2e-local/**` is a manual-only harness never run in CI (`docs/handoff/HANDOVER-SESSION-M-DHOUHA-WAVE2-2026-09-02.md:12`: "run manually only, never in CI") and should be **excluded from the lint/typecheck surface entirely**, not included and linted. This revision reverts the `tsconfig.json` include and instead adds `e2e-local/**` to `apps/web/eslint.config.js`'s `ignores` block — see §3.1 below for the corrected fix and rationale. It also adds the gate's requested MINOR-1/MINOR-2 predicate + cascade tests for `useApplyUnitTextMapping` (§3.4) and reports the `pnpm lint:ratchet` numbers honestly (§2b) rather than only the local `pnpm lint` chain, since `lint:ratchet` — not `pnpm lint` — is the actual CI gate (`.github/workflows/ci.yml`'s `frontend-lint` job runs discrete `audit:*` steps + `pnpm lint:ratchet`, which its own in-file comment says "supersedes a raw `pnpm lint`"; the `package.json` `lint` chain is local-only).

## Result

`pnpm lint` (local chain: `lint:eslint && audit:keys && audit:design-system && audit:quantity && audit:i18n:local && test:eslint-rules && test:tools`):

- **Item 1 (e2e-local parse errors): FIXED, corrected approach.** `e2e-local/**` is now ESLint-ignored (never parsed, never linted) instead of being pulled into the e2e TypeScript project. `lint:eslint` runs clean (0 errors, warnings only).
- **Item 2 (uom no-op invalidation): FIXED.** `audit:keys` reports 0 violations. `uomUnmappedUnitTextsInvalidationPredicate` now has dedicated falsifying tests (§3.4).
- **Item 3 (i18n keys): FIXED for the two keys named in the brief.** Both `ar|uom|unitUpdated` and `fr|import|unitErrors.line_many` are gone from the `audit:i18n:local` gap list.
- **Item 4 (import feature design-system entries): NOT fixed, as instructed.** Still the only entries failing `audit:design-system` — see §4.
- **`UnmappedUnitTextsPanel.tsx` raw `<select>` (found during item 2, not in the original brief): FIXED**, same minimal-diff approach as before — see §5.
- **62 pre-existing `audit:i18n:local` gaps (found during verification, not fixed, out of scope): unchanged**, entirely unrelated to items 1–4 — see §6.

**`pnpm lint:ratchet` (root `package.json`, the actual CI gate) — RED, but the great majority of the redness pre-dates this lane and is not caused by it.** See §2b for the full breakdown: `dev` at this lane's base (`6f16fd8f7`) was already 6454 warnings against a 6448 baseline (+6, pre-existing, independent of this lane) plus 5 parse errors (caused by the same e2e-local/tsconfig gap this lane fixes). After this lane's full diff, `@autoerp/web` is 6455 warnings / 0 errors — i.e. this lane's own net contribution over the pre-existing dev drift is **+1 warning**, entirely from the new falsifying test added in §3.4 (mirrors an existing pattern already present 3× in the same file), while it also **removes** the 5 pre-existing parse errors. `@autoerp/pos` is unaffected, held at 84.

## 1. `pnpm lint` — before (base commit `6f16fd8f7`)

```
> eslint .

apps/web/e2e-local/pw.config.ts
  0:0  error  Parsing error: "parserOptions.project" has been provided for @typescript-eslint/parser.
The file was not found in any of the provided project(s): e2e-local/pw.config.ts

apps/web/e2e-local/wave2-po.part1.spec.ts
  0:0  error  Parsing error: ... e2e-local/wave2-po.part1.spec.ts

apps/web/e2e-local/wave2-po.part2.spec.ts
  0:0  error  Parsing error: ... e2e-local/wave2-po.part2.spec.ts

apps/web/e2e-local/wave2-shared.ts
  0:0  error  Parsing error: ... e2e-local/wave2-shared.ts

apps/web/e2e-local/wave2-support.ts
  0:0  error  Parsing error: ... e2e-local/wave2-support.ts

...
✖ 6459 problems (5 errors, 6454 warnings)

 ELIFECYCLE  Command failed with exit code 1.
```

`lint:eslint` fails the whole `pnpm lint` chain immediately (it's the first `&&` link), so `audit:keys`, `audit:design-system`, `audit:quantity`, `audit:i18n:local`, `test:eslint-rules`, `test:tools` never ran at the base commit. Verified independently after the eslint fix landed:

- `node tools/audit-tanstack-keys.mjs` (pre-fix, base `useUnits.ts`): flags `useApplyUnitTextMapping`'s `invalidateQueries({ queryKey: tenantScopedKey([...uomKeys.unmappedUnitTexts()]) })` at `src/features/uom/hooks/useUnits.ts:53` — a `tenantScopedKey(...)`-wrapped `queryKey` filter is explicitly flagged by the gate's own doc comment (`tools/audit-tanstack-keys.mjs:64-72`): `invalidateQueries` matches by positional **prefix**, `tenantScopedKey()` appends tenant/company as a **suffix**, so wrapping a filter key in it is a proven no-op pattern the gate default-denies.
- `bash ../../scripts/i18n-baseline-authority.sh` (pre-fix): reports `ar|uom|unitUpdated` and `fr|import|unitErrors.line_many` among its gaps (plus the 62 unrelated pre-existing gaps in §6, present before any of this lane's edits).

The base-commit numbers above (`✖ 6459 problems (5 errors, 6454 warnings)`) were re-verified this round by checking out `6f16fd8f7` into a disposable worktree (`git worktree add --detach /tmp/... 6f16fd8f7`, symlinked `node_modules`, `pnpm exec eslint .`) — exact match, confirming they are `dev`'s real pre-lane state, not a transcription.

## 2. `pnpm lint` (local chain) — after

```
> eslint .
✖ 6686 problems (0 errors, 6686 warnings)   [round-1 number, e2e-local included in tsconfig — since reverted, see §3.1]

> audit:keys
[sweep-progress] Gate C — useQuery/useQueries/queryClient queryKeys without an approved tenant scope: 0
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries

> audit:design-system
[sweep-progress] Design-system audit C1-C6 violations: 810
[gate-summary] Design-system baseline: 796 acknowledged, 14 new, 11 stale baseline entries

New design-system violations:
  src/features/import/pages/ImportWizardPage.tsx:1041:19 C2 ...
  src/features/import/pages/ImportWizardPage.tsx:1213:23 C2 ...
  src/features/import/pages/ImportWizardPage.tsx:867:15  C3 ...
  ... (14 entries total, all in src/features/import/pages/ImportWizardPage.tsx — see §4)

 ELIFECYCLE  Command failed with exit code 1.
```

`eslint .` is 0 errors (down from 5). `audit:keys` is 0 violations (down from 1 new). `audit:design-system` dropped from 15 new to 14 new — the 1 non-import entry (`UnmappedUnitTextsPanel.tsx`) is fixed; the remaining 14 are 100% `src/features/import/pages/ImportWizardPage.tsx`, i.e. exactly item 4 (re-confirmed this round: `node tools/audit-design-system.mjs` → same 14 entries, same 11 stale-baseline entries, all `ImportWizardPage.tsx`).

Independently verified once design-system is set aside (re-confirmed this round unless noted):
- `audit:quantity`: 0 total (clean).
- `audit:i18n:local`: 62 NEW gaps remain (re-confirmed this round, same 62, same shape) — 0 of them are the two keys this lane was asked to fix; all are pre-existing, unrelated debt (§6).
- `test:eslint-rules`: all RuleTester suites pass (6 rule files, all green) — re-confirmed this round.
- `test:tools`: 8 files / 160 tests pass (the `git cat-file … bad file` / `FAIL CLOSED` / `RATCHET GROWTH` lines in the output are intentional planted-tamper fixtures inside `audit-i18n-completeness.test.mjs`, not real failures — the suite exits 0) — re-confirmed this round.
- `pnpm typecheck`: exit 0 — re-confirmed this round.
- `pnpm typecheck:e2e` (`tsc --noEmit -p e2e/tsconfig.json`): exit 0 — re-confirmed this round, now WITHOUT `e2e-local` in the project (§3.1 revision); it never needed to be there.
- `pnpm vitest run src/features/uom/__tests__/tenantScope.test.tsx`: 15/15 tests pass, including the new `uomUnmappedUnitTextsInvalidationPredicate` unit tests and the `useApplyUnitTextMapping` cascade/cross-company test (§3.4) — re-confirmed this round, plus a falsification check (see §3.4).
- `pnpm exec eslint src/features/uom src/features/uom/__tests__/tenantScope.test.tsx eslint.config.js`: 0 errors (54 pre-existing warnings across the `uom` feature dir; `tenantScope.test.tsx` alone carries 10, up from 9 before this round's test additions — see §2b for why).

## 2b. `pnpm lint:ratchet` — the actual CI gate — numbers, honestly

`apps/web/package.json`'s `lint` chain is **local-only**; it is never invoked by CI. The `frontend-lint` CI job runs discrete `audit:*` steps plus `pnpm lint:ratchet` (root `package.json:11` → `node scripts/lint-ratchet.mjs`), which runs `pnpm --filter <app> lint` for `@autoerp/web` and `@autoerp/pos`, parses each app's ESLint summary line, and ratchets the **warning** count against `scripts/lint-warning-baseline.json` (errors always hard-fail; warnings may shrink or hold, never grow). This is the gate this lane must be honest about, not just the local `pnpm lint` chain.

**Baseline:** `@autoerp/web` 6448 warnings / 0 errors, `@autoerp/pos` 84 warnings / 0 errors (`scripts/lint-warning-baseline.json`).

**Before this lane (dev at `6f16fd8f7`):** `@autoerp/web` = 6459 problems = **5 errors + 6454 warnings** (the 5 errors are the e2e-local parse errors from §1; the 6454 warnings are **already +6 over the 6448 baseline, independent of anything in this lane** — `docs/handoff/LEDGER.md` D-J0-10 documents this exact pre-existing drift being re-baselined 6458→6448 on 2026-08-29 and dev has since drifted again). Since `lint-ratchet.mjs` hard-fails on any error, `pnpm lint:ratchet` was **already RED on dev before this lane touched anything**, for a different reason (parse errors) than the one it's red for now (warning count).

**After this lane's full diff (both commits):**
```
=== Lint-warning ratchet ===
@autoerp/web     baseline=  6448  current=  6455  FAIL — warnings rose 6448 → 6455 (+7)
@autoerp/pos     baseline=    84  current=    84  held — 84 warnings
RESULT: FAIL — fix new errors / warnings, or justify a baseline bump.
```
0 errors (down from 5). `@autoerp/pos` untouched (this lane doesn't touch `apps/pos`), held exactly at baseline.

**Decomposition of the `@autoerp/web` 6454 → 6455 delta (+1), isolated file-by-file** (each file linted standalone at its base-commit content vs. its current content, `pnpm exec eslint <file>`, to attribute warnings precisely rather than infer from the aggregate number):

| File | Base (`6f16fd8f7`) | Current | Δ | Why |
|---|---|---|---|---|
| `e2e-local/*` (5 files) | 5 parse **errors**, 0 warnings (unignored, not in any tsconfig project) | ignored entirely — 0 problems | **−5 errors, 0 warnings** | Now ESLint-ignored (§3.1); never parsed at all. (Round 1 had instead included them in the tsconfig project, which made them parse successfully and added +232 warnings — that path is reverted.) |
| `src/features/uom/hooks/useUnits.ts` | 0 errors, 0 warnings | 0 errors, 0 warnings | **0** | The `uomUnmappedUnitTextsInvalidationPredicate` addition and `useApplyUnitTextMapping` rewrite (item 2) introduce no new lint-flagged patterns. |
| `src/features/uom/components/UnmappedUnitTextsPanel.tsx` | 0 errors, 0 warnings | 0 errors, 0 warnings | **0** | The raw-`<select>`→`Select` atom swap (§5) is lint-neutral. |
| `src/features/uom/__tests__/tenantScope.test.tsx` | 9 warnings (base commit had no `useApplyUnitTextMapping` coverage yet) | 10 warnings | **+1** | New `restrict-template-expressions` warning at the new `` `text-${unmappedCalls}` `` line (213:39) — `unmappedCalls` is a `number` interpolated into a template literal, the exact same lint-flagged pattern already present 3× in the same file for the sibling `categoriesCalls`/`unitsCalls` counters (lines 205:30, 205:60, 209:31, all pre-existing). Kept for consistency with the file's established mock-counter convention rather than singling out one line for a style workaround that would make the file inconsistent. |
| `apps/web/e2e/tsconfig.json`, `apps/web/eslint.config.js` | — | — | **0** | Config-only; type-checked by `typecheck:e2e`, not by `eslint .` itself. |
| **Net** | | | **+1** | Fully accounted for by the one new warning above. |

`6454 (pre-existing dev drift) + 1 (this lane) = 6455` — matches the ratchet's reported `current` exactly.

**Honest bottom line:** `pnpm lint:ratchet` is red on the tip of this branch, but **6 of the 7 warnings over baseline pre-date this lane and are not caused by any file this lane touches** (they're diffuse pre-existing `dev` drift per the LEDGER D-J0-10 pattern — not attributable to any of this lane's 3 changed files, confirmed by the file-level isolation above). This lane's own, isolated contribution is **+1 warning**, intentionally kept for file-internal consistency rather than eliminated by a one-off style deviation, and this lane's fix also **removes 5 pre-existing hard errors** that were independently failing the ratchet before this lane existed. Re-baselining the 6448→6454 (or higher) pre-existing drift is an **owner decision outside this lane's scope** (per the LEDGER precedent, drift re-baselining is an explicit owner-acked action, not something a scoped lint-debt lane should absorb unilaterally).

## 3. Fixes (items 1–3)

### 3.1 `apps/web/eslint.config.js` — e2e-local ignored, NOT included in a tsconfig project (item 1, CORRECTED per gate MAJOR-2)

**What round 1 did (reverted):** added `../e2e-local/*.ts` to `apps/web/e2e/tsconfig.json`'s `include`, so `e2e-local/`'s 5 files were parsed and linted as part of the shared e2e TypeScript project (same as `e2e/campaign/**`).

**Why that was wrong (gate MAJOR-2):** `e2e-local/` is explicitly a **manual-only harness, never run in CI** — `docs/handoff/HANDOVER-SESSION-M-DHOUHA-WAVE2-2026-09-02.md:12`: "Playwright harness committed at `apps/web/e2e-local/` (...) — **run manually only, never in CI**." Pulling it into the linted/typechecked surface treats it as production-adjacent code subject to the same gates as `e2e/campaign` (which *is* the CI-run campaign harness) — that's the wrong classification. The correct treatment for code that is deliberately never exercised by CI is to keep it **out of the lint/typecheck surface entirely**, the same way `*.config.ts`/`*.config.js` and the handful of not-yet-wired `inventory-counting` files already are in this same `ignores` block (with a comment explaining why, matching the existing convention in the file).

**The fix (this round):**

`apps/web/e2e/tsconfig.json` — reverted to its original `include` (no `e2e-local`):
```diff
-  "include": ["campaign/**/*.ts", "../playwright.campaign.config.ts", "../e2e-local/*.ts"]
+  "include": ["campaign/**/*.ts", "../playwright.campaign.config.ts"]
```

`apps/web/eslint.config.js` — `e2e-local/**` added to the top-level `ignores` array, with a comment citing the source of truth:
```diff
       'dist',
       'e2e/*',
       '!e2e/campaign',
+      // e2e-local/ is a manual-only harness, never run in CI (docs/handoff/HANDOVER-SESSION-M-DHOUHA-WAVE2-2026-09-02.md:12).
+      'e2e-local/**',
       '*.d.ts',
       '*.config.ts',
```

**Why the existing `e2e/*` ignore didn't already cover it:** `e2e/*` (no `**`) is a single-segment glob anchored to direct children of `e2e/` — it matches `e2e/campaign` (then re-included by `!e2e/campaign`) but does **not** match `e2e-local/` at all, since `e2e-local` is a sibling directory of `e2e`, not a child of it (confirmed empirically pre-fix: `npx eslint e2e-local/pw.config.ts` was not skipped by the ignore — it hit the parse error instead). A dedicated `e2e-local/**` entry was required.

**Verification.** `npx eslint e2e-local/` → 0 problems reported at all (files skipped, ignored). `npx eslint .` → e2e-local no longer appears anywhere in the output (neither errors nor warnings). `pnpm typecheck:e2e` (`tsc --noEmit -p e2e/tsconfig.json`) → exit 0, confirming the e2e project's own typecheck is unaffected by removing `e2e-local` from its `include` (it was never meant to type-check as part of that project; nothing in `e2e/campaign` imports from `e2e-local`). `e2e-local/tsconfig.verify.json` (a separate, pre-existing config scoped to that directory) is untouched and remains the correct way to typecheck `e2e-local` manually, standalone, exactly matching its "manual-only" classification.

### 3.2 `apps/web/src/features/uom/hooks/useUnits.ts:46-88` — uom no-op invalidation (item 2)

Unchanged from round 1; re-verified this round, see the original rationale below.

**Rationale.** `tools/audit-tanstack-keys.mjs` treats `invalidateQueries` (and `removeQueries`/`resetQueries`/`refetchQueries`/`cancelQueries`) as cache-**filter** factories: React Query matches their `queryKey` option as a positional **prefix** against stored keys. `tenantScopedKey()` appends `[tenantId, companyId]` at the **suffix**. Wrapping an `invalidateQueries` filter key in `tenantScopedKey(...)` (as the pre-existing `useApplyUnitTextMapping` did) is therefore flagged as a no-op pattern — it only matches when the filter key happens to equal the *entire* stored key, which breaks the moment any intervening segment (like `{ categoryId }` in the sibling `unitsByCategory` key) is present, and is banned outright by the gate regardless.

The file already established the correct convention for the sibling `units`/`categories` namespaces: `uomUnitsInvalidationPredicate` / `uomCategoriesInvalidationPredicate`, predicate functions that check `k[0]`/`k[1]` (namespace) and the tenant/company suffix positionally from the end, used by `useCreateUnit`/`useUpdateUnit`/`useDeleteUnit`. The fix adds a third predicate, `uomUnmappedUnitTextsInvalidationPredicate(tenantId, companyId)`, matching `k[0] === 'uom' && k[1] === 'unmapped-unit-texts'` plus the tenant/company suffix (mirroring the existing two predicates' shape exactly), and switches `useApplyUnitTextMapping` to read `tenantId`/`companyId` via the same `useAuthStore`/`useCompanyStore` selector hooks the other mutation hooks in the file already use, then calls `invalidateQueries({ predicate: ... })` instead of the `queryKey`-filter form.

**Verification.** `node tools/audit-tanstack-keys.mjs` → 0 violations. `pnpm typecheck` → exit 0. See §3.4 for the falsifying test coverage added this round.

### 3.3 i18n keys (item 3)

Unchanged from round 1:

- `apps/web/src/locales/ar/uom.json:2` — added `"unitUpdated": "تم تحديث الوحدة بنجاح"` (top-level, matching en `"Unit updated successfully"` / fr `"Unité mise à jour avec succès"`). Only `unitUpdated` was added — the sibling `addUnit`/`editUnit`/`unitCreated`/`unitDeleted` keys are also missing from `ar/uom.json` but are **not** part of this task's named scope (§6, same 62-gap pre-existing debt).
- `apps/web/src/locales/fr/import.json:198` — added `"line_many"` to `unitErrors`, text identical to the existing `line_other`, matching the repo's existing convention for French `_many`/`_other` pairs (`fr/adminCountryDefaults.json:63,116`, `fr/treasury.json:738`).

**Verification (re-confirmed this round).** `pnpm audit:i18n:local` → 62 gaps; grep confirms `ar|uom|unitUpdated` and `fr|import|unitErrors.line_many` are absent from the list (`pnpm audit:i18n:local 2>&1 | grep -i -E "unitUpdated|line_many"` → only `ar|import|plural|unitErrors.line_many` matches, a **different, pre-existing** key — the Arabic plural `many` category for the same `unitErrors` string, one of the 4 Arabic-plural gaps documented in §6; it is not the French key this lane fixed). Both JSON files still parse.

### 3.4 `apps/web/src/features/uom/__tests__/tenantScope.test.tsx` — predicate + cascade tests for item 2 (NEW this round, gate MINOR-1/MINOR-2)

The gate noted item 2's fix (§3.2) shipped without dedicated test coverage — the existing suite covered `uomUnitsInvalidationPredicate`/`uomCategoriesInvalidationPredicate` but not the new `uomUnmappedUnitTextsInvalidationPredicate`/`useApplyUnitTextMapping`. Two additions, mirroring the file's existing conventions exactly:

1. **`describe('uomUnmappedUnitTextsInvalidationPredicate', ...)`** — a direct unit test of the predicate factory, mirroring the file's existing `uomCategoriesInvalidationPredicate` describe block: asserts it matches `['uom', 'unmapped-unit-texts', tenantId, companyId]` and rejects a wrong namespace (`'units'`), a wrong tenant, and a wrong company.
2. **A new `it(...)` inside the existing `'uom mutation cascades'` describe block** — extends `CascadeProbe` with a `useUnmappedUnitTexts()` query, a `useApplyUnitTextMapping()` mutation, and a `m: () => unmappedCalls` fetch counter (same shape as the pre-existing `c`/`u` counters for categories/units). The test calls `applyMapping.mutateAsync(...)` for `tenant-A`/`company-1`, asserts the own-company `unmapped-unit-texts` query refetches (counter 1→2), and — mirroring the file's existing cross-tenant-isolation test for units — seeds a `company-2` cache entry directly via `queryClient.setQueryData` and asserts it is **not** invalidated (`isInvalidated` stays `false`, data unchanged), proving the predicate's tenant/company suffix match, not just its namespace match.

**Falsification check (this round, not committed — confirms the tests actually test something):** temporarily replaced `uomUnmappedUnitTextsInvalidationPredicate`'s body with `return (_q) => false` (a predicate that invalidates nothing) and reran `pnpm vitest run src/features/uom/__tests__/tenantScope.test.tsx`. Result: **2 tests failed** — the dedicated predicate unit test (`'matches the [uom, unmapped-unit-texts, ...] tenant-scoped key'`) and the cascade test (`expected 1 to be 2` on the refetch-counter assertion), exactly as expected for an always-false predicate. Reverted immediately (`git diff` on `useUnits.ts` confirmed empty afterward — the file was never actually modified in the committed diff, only used for this one-off falsification check). This confirms the new tests are genuinely falsifying, not tautological.

**Verification.** `pnpm vitest run src/features/uom/__tests__/tenantScope.test.tsx` → 15/15 pass (13 pre-existing + 2 new). `pnpm exec eslint src/features/uom/__tests__/tenantScope.test.tsx` → 0 errors, 10 warnings (up from 9 — see §2b for the one new warning's provenance and why it was kept). All 4 identifiers imported in the new test (`uomUnmappedUnitTextsInvalidationPredicate`, `useApplyUnitTextMapping`, `useUnmappedUnitTexts`) are confirmed exported from `src/features/uom/hooks/useUnits.ts` (`useUnmappedUnitTexts` at line 36, `useApplyUnitTextMapping` at line 46, `uomUnmappedUnitTextsInvalidationPredicate` at line 73) — `pnpm typecheck` exit 0 would otherwise have caught a missing export.

## 4. Item 4 — left for the import lane (NOT fixed)

Unchanged from round 1, re-confirmed this round (`node tools/audit-design-system.mjs` → identical 14 entries, identical 11 stale-baseline entries). All 14 remaining `audit:design-system` violations, verbatim, all in `src/features/import/pages/ImportWizardPage.tsx`:

| Line:Col | Rule | Element |
|---|---|---|
| 1041:19 | C2 | `<input type="checkbox" aria-label={t('options.enrichmentLabel')} ...>` — enrichment checkbox |
| 1213:23 | C2 | `<input type="radio" name="duplicate_policy" ...>` — duplicate-policy radio |
| 867:15 | C3 | `<button data-testid="import-wizard-next" ... onClick={handleUploadComplete} ...>` |
| 911:15 | C3 | `<button data-testid="import-wizard-back" ... onClick={() => setCurrentStep('upload')} ...>` |
| 920:15 | C3 | `<button data-testid="import-wizard-validate" ... onClick={handleMappingComplete} ...>` |
| 1061:15 | C3 | `<button data-testid="import-wizard-back" ... onClick={() => setCurrentStep('mapping')} ...>` |
| 1070:15 | C3 | `<button data-testid="import-wizard-next" ... onClick={() => void handleOptionsComplete()} ...>` |
| 1199:23 | C3 | `<button ... onClick={() => setShowAllNameMatches((shown) => !shown)} ...>` |
| 1250:15 | C3 | `<button data-testid="import-wizard-back" ... onClick={() => setCurrentStep(shouldShowOptionsStep ? 'options' : 'mapping')} ...>` |
| 1259:15 | C3 | `<button data-testid="import-wizard-next" ... onClick={() => void handleValidationComplete()} ...>` |
| 1372:15 | C3 | `<button data-testid="import-wizard-back" ... onClick={() => setCurrentStep('validation')} ...>` |
| 1384:17 | C3 | `<button data-testid="import-wizard-execute" ... onClick={handleExecute} ...>` |
| 1498:21 | C3 | `<button data-testid="import-complete-download-workbook" ... onClick={() => authenticatedDownload(...)} ...>` |
| 1511:23 | C3 | `<button data-testid="import-complete-download-rows_export_csv" ... onClick={() => authenticatedDownload(...)} ...>` |

C2 = raw `<input>` should use the form atom (Input/Select/Textarea). C3 = raw `<button>` should use the `Button` atom. These are new (unbaselined) relative to `tools/audit-design-system-baseline.json`; the audit also reports 11 *stale* baseline entries for the same file (near-identical button literals whose exact text drifted slightly, e.g. `jobData.id` interpolation), which the import lane owner should fold into the same cleanup pass when they shrink `tools/audit-design-system-baseline.json`.

Per the task brief, `src/features/import/**` was left untouched — including in this fix round.

## 5. Additional fix beyond the brief — uom `<select>` (in scope, small, non-import)

Unchanged from round 1. `audit:design-system` also flagged (independent of item 4):
```
src/features/uom/components/UnmappedUnitTextsPanel.tsx:138:23 C2 Raw <select> should use the form atom (Input/Select/Textarea)
```
This is in the same `uom` feature directory as item 2, not owned by the import lane. Fixed by swapping the raw `<select>` for the existing `Select` atom (`src/components/atoms/Select/Select.tsx`), dropping the hand-rolled Tailwind classes in favor of `Select`'s own `tokens.select.base` styling, keeping `className="w-full"` for the layout-specific width.

**Verification (re-confirmed this round).** `node tools/audit-design-system.mjs` → the `UnmappedUnitTextsPanel.tsx` entry remains gone (0 problems for this file); all 14 remaining new entries are §4's import entries. `npx eslint src/features/uom/components/UnmappedUnitTextsPanel.tsx` → clean. `pnpm vitest run src/features/uom` was not re-run in full this round (scope was `tenantScope.test.tsx`); `UnmappedUnitTextsPanel.tsx` itself is unchanged since round 1.

## 6. Discovery — 62 pre-existing `audit:i18n:local` gaps (NOT fixed, out of scope)

Unchanged from round 1, re-confirmed this round (`pnpm audit:i18n:local` → same 62 gaps, same shape). Running `bash ../../scripts/i18n-baseline-authority.sh` on the **base commit**, before any edit in this lane, already reports 64 new gaps — not the 2 named in the brief. After fixing the 2 named keys (§3.3), 62 remain, entirely unrelated to items 1–4:

- **61 entries**: `ar|uom|missing|*` — essentially the entire `en/uom.json` key set is missing from `ar/uom.json`, which today only has an `"unmapped"` block.
- **4 entries**: `ar|import|plural|unitErrors.line_{few,many,two,zero}` — Arabic's 6 CLDR plural categories (`zero/one/two/few/many/other`) aren't fully covered for `unitErrors` in `ar/import.json` (only `line_one`/`line_other` exist there). `ar|import|plural|unitErrors.line_many` is one of these 4 — it is the Arabic plural gap, distinct from the French `fr|import|unitErrors.line_many` key this lane fixed in §3.3.

**Root cause (traced, not fixed):** the local authority script (`scripts/i18n-baseline-authority.sh`) derives its comparison blob from a pinned **seed commit** (`i18n_baseline_seed_commit` in `docs/handoff/progress/enforcement-p2.progress.yaml`) that lives on a branch not merged into `dev`, so keys added to `dev` since that pin (mostly `en/uom.json` and `import.json unitErrors`, from merged commit `5edc719a9`) show up as "NEW" gaps rather than recognized pre-existing debt. Not introduced by this lane's diff.

**Why not fixed here:** translating ~61 keys into Arabic (plus 4 Arabic plural forms) is not a "minimal diff" mechanical fix and isn't part of the brief's named scope (item 3 named exactly 2 keys). Recommend routing to the team that owns Arabic-backfill work, and separately re-pinning the i18n baseline seed commit once the two branches reconcile.

## 7. Fix-round summary (2026-09-04)

**What changed vs. the first pass (`4b5b58789`):**
- `apps/web/e2e/tsconfig.json` — reverted the `e2e-local` include (back to base-commit content).
- `apps/web/eslint.config.js` — added `e2e-local/**` to `ignores`, with a comment citing the manual-only-harness source of truth.
- `apps/web/src/features/uom/__tests__/tenantScope.test.tsx` — added the `uomUnmappedUnitTextsInvalidationPredicate` unit test and the `useApplyUnitTextMapping` cascade/cross-company test (§3.4), verified falsifying.
- This handback rewritten to describe the ignore approach (not the tsconfig-include approach) and to report `pnpm lint:ratchet` numbers, not just the local `pnpm lint` chain.

**Not touched, not found:** no other MINOR items were named in this task's gate-ruling summary beyond MINOR-1/MINOR-2 (both addressed in §3.4); no separate gate-review file was found in the worktree to check against — this handback was written from the gate ruling as relayed in the fix-round task brief. No further gate items found in the worktree.

**Outstanding, not this lane's to fix:**
- `pnpm lint:ratchet` remains RED for `@autoerp/web` (6455 vs 6448 baseline) — see §2b for the full, file-by-file honest breakdown. 6 of the 7 over-baseline warnings are pre-existing `dev` drift this lane does not touch; the 7th is this lane's own single new warning, kept intentionally for in-file consistency (§2b, §3.4). Re-baselining the pre-existing drift is an owner decision, same precedent as `docs/handoff/LEDGER.md` D-J0-10.
- Item 4 (14 `ImportWizardPage.tsx` design-system entries) — belongs to the import lane, per the original brief's scope fence.
- The 62 pre-existing i18n gaps (§6) — belongs to Arabic-backfill ownership + i18n baseline re-pinning, per §6's root-cause trace.

## Files changed (all outside `src/features/import/**`)

- `apps/web/e2e/tsconfig.json`
- `apps/web/eslint.config.js`
- `apps/web/src/features/uom/hooks/useUnits.ts`
- `apps/web/src/features/uom/components/UnmappedUnitTextsPanel.tsx`
- `apps/web/src/features/uom/__tests__/tenantScope.test.tsx`
- `apps/web/src/locales/ar/uom.json`
- `apps/web/src/locales/fr/import.json`

## Re-gate r2 corrections (2026-09-04) — supersede the numbers above

Gate r2 (`docs/superpowers/reviews/2026-09-04-web-lint-debt-gate-r2.md`) = **MERGE**, with documentation corrections:

- **Ratchet:** commit `ec5810b2f` (landed after §2b/§7 were written) removed the lane's one new warning. Measured at the lane tip and on a real merge with current `dev` (`7f86dbf0c`): `@autoerp/web` **0 errors, 6454 warnings** (base `6f16fd8f7`: 5 errors, 6454 warnings). **Lane net contribution: 0 warnings, −5 errors.** The ratchet stays FAIL 6448→6454 (+6) purely from pre-lane `dev` drift — owner re-baseline decision (LEDGER D-J0-10 precedent). Wherever §2b/§7 say `6455` / `+1`, read `6454` / `0`.
- **Files changed:** `apps/web/e2e/tsconfig.json` is net-unchanged versus base (touched in r1, reverted in r2); drop it from the changed-files list.
- **i18n count:** §6's "61 entries `ar|uom|missing|*`" is 58 (58 + 4 = the stated 62 total).
- **MINOR-5 applied:** `apps/web/package.json` gains `typecheck:e2e-local` (`tsc --noEmit -p e2e-local/tsconfig.verify.json`) so the ignored manual harness keeps a manual typecheck entry point (not wired into CI by design).
- **Owner items (not this lane):** CI `frontend-lint` stays red until the 14 `ImportWizardPage.tsx` design-system entries are routed to the import lane and the 6448→6454 drift is re-baselined or fixed.

