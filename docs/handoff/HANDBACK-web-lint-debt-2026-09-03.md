# HANDBACK — web lint-gate debt (lane/rh-web-lint-debt, 2026-09-03)

Base commit: `6f16fd8f7` (dev). Worktree: `apps/erp/.worktrees/rh-lint`. Scope: make `cd apps/web && pnpm lint` green **without touching `apps/web/src/features/import/**`**.

## Result

`pnpm lint` runs the chain `lint:eslint && audit:keys && audit:design-system && audit:quantity && audit:i18n:local && test:eslint-rules && test:tools`.

- **Item 1 (e2e-local parse errors): FIXED.** `lint:eslint` now runs clean (0 errors, warnings only).
- **Item 2 (uom no-op invalidation): FIXED.** `audit:keys` now reports 0 violations.
- **Item 3 (i18n keys): FIXED for the two keys named in the brief.** Both `ar|uom|unitUpdated` and `fr|import|unitErrors.line_many` are gone from the `audit:i18n:local` gap list.
- **Item 4 (import feature design-system entries): NOT fixed, as instructed.** These are the only entries still failing `audit:design-system` — see §4.
- **New finding, also fixed (not in the original brief): a `audit:design-system` C2 violation in `src/features/uom/components/UnmappedUnitTextsPanel.tsx:138`** (raw `<select>`), in the same feature directory as item 2. Not part of the import lane's surface, so fixed with the same minimal-diff approach — see §5.
- **New finding, NOT fixed (out of scope, size): 62 pre-existing `audit:i18n:local` gaps unrelated to items 1–3**, almost entirely the Arabic `uom` namespace and Arabic plural categories for `import.unitErrors`. See §6 — this is real, pre-existing debt discovered while verifying, not something introduced by this lane, and not something a "minimal diff" lane should absorb.

**Net: `pnpm lint` is GREEN except for item 4 (the import lane's 14 design-system entries) and the 62 pre-existing i18n gaps in §6.** Since `audit:design-system` runs before `audit:i18n:local` in the chain, the invocation currently stops at item 4; the i18n step was verified independently (§6) and confirmed to have the same shape of residual once design-system is unblocked.

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

## 2. `pnpm lint` — after

```
> eslint .
✖ 6686 problems (0 errors, 6686 warnings)
  0 errors and 899 warnings potentially fixable with the `--fix` option.

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

`eslint .` is 0 errors (down from 5). `audit:keys` is 0 violations (down from 1 new). `audit:design-system` dropped from 15 new to 14 new — the 1 non-import entry (`UnmappedUnitTextsPanel.tsx`) is fixed; the remaining 14 are 100% `src/features/import/pages/ImportWizardPage.tsx`, i.e. exactly item 4.

Independently verified once design-system is set aside:
- `audit:quantity`: 0 total (clean).
- `audit:i18n:local`: 62 NEW gaps remain — 0 of them are the two keys this lane was asked to fix; all 62 are pre-existing, unrelated debt (§6).
- `test:eslint-rules`: all RuleTester suites pass (6 rule files, all green).
- `test:tools`: 8 files / 160 tests pass (the `git cat-file … bad file` / `FAIL CLOSED` / `RATCHET GROWTH` lines in the output are intentional planted-tamper fixtures inside `audit-i18n-completeness.test.mjs`, not real failures — the suite exits 0).
- `pnpm typecheck`: exit 0.
- `pnpm vitest run src/features/uom`: 5 files / 40 tests pass, including the full `tenantScope.test.tsx` predicate/cascade suite.
- `npx tsc -p apps/web/e2e/tsconfig.json --noEmit`: exit 0 (confirms e2e-local now type-checks cleanly under the shared e2e project, not just eslint-parses).

## 3. Fixes (items 1–3)

### 3.1 `apps/web/e2e/tsconfig.json:12` — e2e-local parser project (item 1)

Diff:
```diff
-  "include": ["campaign/**/*.ts", "../playwright.campaign.config.ts"]
+  "include": ["campaign/**/*.ts", "../playwright.campaign.config.ts", "../e2e-local/*.ts"]
```

**Rationale.** `eslint.config.js`'s top-level `ignores` block ignores `e2e/*` wholesale except `!e2e/campaign` (only `e2e/campaign` is un-ignored), and the typed parser's `parserOptions.project` list is `['./tsconfig.json', './tsconfig.node.json', './e2e/tsconfig.json']`. `e2e/tsconfig.json` in turn only `include`s `campaign/**/*.ts` + `playwright.campaign.config.ts` — so most of `e2e/` (e.g. `add-to-inventory.spec.ts`) is simply never linted at all; only the curated `e2e/campaign` subtree is both un-ignored AND covered by a tsconfig project. `e2e-local/` was NOT covered by the `e2e/*` ignore glob (that pattern has no `**` and is anchored, so it only matches literal `e2e/*` paths, not `e2e-local/*` — confirmed empirically, `npx eslint e2e-local/pw.config.ts` was not skipped) and was not in any `parserOptions.project`, so the 5 files under it hit the generic "file not found in any project" parse error.

Per the brief's stated preference ("prefer including e2e-local in the same tsconfig project as e2e over ignoring it"), and because `e2e-local`'s own `tsconfig.verify.json` already extends `e2e/tsconfig.json` and imports from `../e2e/campaign/{selectors,journey}` (confirming it's meant to share that project's compiler settings — `@playwright/test`/`node` types, relaxed `exactOptionalPropertyTypes`/`noPropertyAccessFromIndexSignature`/`noUnusedLocals`/`noUnusedParameters`/`verbatimModuleSyntax`), the fix adds `../e2e-local/*.ts` to `e2e/tsconfig.json`'s `include`. This is additive only — no ignore-list or eslint-config change was needed, and no compiler-option relaxation beyond what `e2e/tsconfig.json` already grants `e2e/campaign`.

**Verification.** `npx eslint e2e-local/` → 0 errors, 232 warnings (same style as `e2e/campaign/**` — `restrict-template-expressions`, `dot-notation`, `require-await`, etc., all pre-existing warn-level rules; no parse errors). `npx tsc -p e2e/tsconfig.json --noEmit` → exit 0.

### 3.2 `apps/web/src/features/uom/hooks/useUnits.ts:46-88` — uom no-op invalidation (item 2)

**Rationale.** `tools/audit-tanstack-keys.mjs` treats `invalidateQueries` (and `removeQueries`/`resetQueries`/`refetchQueries`/`cancelQueries`) as cache-**filter** factories: React Query matches their `queryKey` option as a positional **prefix** against stored keys. `tenantScopedKey()` appends `[tenantId, companyId]` at the **suffix**. Wrapping an `invalidateQueries` filter key in `tenantScopedKey(...)` (as the pre-existing `useApplyUnitTextMapping` did at line 53) is therefore flagged as a no-op pattern — it only matches when the filter key happens to equal the *entire* stored key, which breaks the moment any intervening segment (like `{ categoryId }` in the sibling `unitsByCategory` key) is present, and is banned outright by the gate regardless.

The file already established the correct convention for the sibling `units`/`categories` namespaces: `uomUnitsInvalidationPredicate` / `uomCategoriesInvalidationPredicate`, predicate functions that check `k[0]`/`k[1]` (namespace) and the tenant/company suffix positionally from the end, used by `useCreateUnit`/`useUpdateUnit`/`useDeleteUnit`. The fix adds a third predicate, `uomUnmappedUnitTextsInvalidationPredicate(tenantId, companyId)`, matching `k[0] === 'uom' && k[1] === 'unmapped-unit-texts'` plus the tenant/company suffix (mirroring the existing two predicates' shape exactly), and switches `useApplyUnitTextMapping` to read `tenantId`/`companyId` via the same `useAuthStore`/`useCompanyStore` selector hooks the other mutation hooks in the file already use, then calls `invalidateQueries({ predicate: ... })` instead of the `queryKey`-filter form. Behavior preserved: only the `unmapped-unit-texts` cache for the mutating user's tenant/company is invalidated after a mapping is applied — same target namespace as before, just via a filter shape that actually matches its full namespace (not only an exact-key coincidence) and that the gate accepts.

**Verification.** `node tools/audit-tanstack-keys.mjs` → 0 violations. `pnpm vitest run src/features/uom` → 40/40 tests pass, including `src/features/uom/__tests__/tenantScope.test.tsx`'s predicate/cascade/cross-tenant-isolation suite (this test file didn't previously cover `useApplyUnitTextMapping`, so it wasn't exercising the fixed code path directly, but the passing suite confirms no regression to the three predicates it does test). `pnpm typecheck` → exit 0.

### 3.3 i18n keys (item 3)

- `apps/web/src/locales/ar/uom.json:2` — added `"unitUpdated": "تم تحديث الوحدة بنجاح"` (top-level, matching en `"Unit updated successfully"` / fr `"Unité mise à jour avec succès"`). Placed before the existing `"unmapped"` block, mirroring en/fr's ordering (title/addUnit/editUnit/unitCreated/unitUpdated/unitDeleted precede the `unmapped` section there). Only `unitUpdated` was added — the sibling `addUnit`/`editUnit`/`unitCreated`/`unitDeleted` keys are also missing from `ar/uom.json` but are **not** part of this task's named scope (see §6, they're part of the same 62-gap pre-existing debt).
- `apps/web/src/locales/fr/import.json:198` — added `"line_many"` to `unitErrors`, text identical to the existing `line_other` (`"{{count}} lignes utilisent l'unité inconnue « {{unit}} » — mappez-la dans Paramètres → Unités ou corrigez le fichier"`). French CLDR's cardinal `many` category is a narrow edge case (exact non-zero multiples of 1,000,000 with no visible fraction) where the noun form is identical to `other` — confirmed against the repo's existing convention: every other `_many`/`_other` pair in the fr locale tree (`fr/adminCountryDefaults.json:63,116`, `fr/treasury.json:738`) uses the same string for both.
- The `fr|import` key lives in `apps/web/src/locales/fr/import.json`, not under `src/features/import/**` — allowed per the brief.

**Verification.** `bash ../../scripts/i18n-baseline-authority.sh`: gap count dropped from 64 → 62; `ar|uom|unitUpdated` and `fr|import|unitErrors.line_many` are both absent from the remaining list (confirmed by grep). Both JSON files parse (`python3 -c 'json.load(...)'`).

## 4. Item 4 — left for the import lane (NOT fixed)

All 14 remaining `audit:design-system` violations, verbatim, all in `src/features/import/pages/ImportWizardPage.tsx`:

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

Per the task brief, `src/features/import/**` was left untouched.

## 5. Additional fix beyond the brief — uom `<select>` (in scope, small, non-import)

`audit:design-system` also flagged (independent of item 4):
```
src/features/uom/components/UnmappedUnitTextsPanel.tsx:138:23 C2 Raw <select> should use the form atom (Input/Select/Textarea)
```
This is in the same `uom` feature directory as item 2, not owned by the import lane, and a one-line-shape fix (swap the raw `<select>` for the existing `Select` atom, `src/components/atoms/Select/Select.tsx`, which already accepts standard `<select>` props including `aria-label`/`disabled`/`onChange`/`value`/`className`). Since the task's actual goal is "green except item 4," and this violation would otherwise silently keep `pnpm lint` red for a reason outside item 4's scope, it was fixed:

```diff
-                      <select
+                      <Select
                         aria-label={...}
-                        className={`w-full rounded-md border px-3 py-2 text-sm ${colorTokens.border.default} ${colorTokens.surface.base} ${colorTokens.text.primary}`}
+                        className="w-full"
                         disabled={...}
                         onChange={...}
                         value={targetId}
                       >
                         ...
-                      </select>
+                      </Select>
```

The hand-rolled Tailwind classes (`rounded-md border px-3 py-2 text-sm` + the 3 color tokens) are dropped since `Select`'s own `tokens.select.base` supplies equivalent styling; `className="w-full"` is kept for the layout-specific width the call site needs. `colorTokens` import is still used 16 other times in the file (verified via grep), so no unused-import fallout.

**Verification.** `node tools/audit-design-system.mjs` → new-violation count dropped 15 → 14 (the `UnmappedUnitTextsPanel.tsx` entry is gone; all 14 remaining are §4's import entries). `npx eslint src/features/uom/components/UnmappedUnitTextsPanel.tsx` → clean. `pnpm vitest run src/features/uom` → still 40/40 (covers `UnmappedUnitTextsPanel.test.tsx`).

## 6. Discovery — 62 pre-existing `audit:i18n:local` gaps (NOT fixed, out of scope)

Running `bash ../../scripts/i18n-baseline-authority.sh` on the **base commit**, before any edit in this lane, already reports 64 new gaps — not the 2 named in the brief. After fixing the 2 named keys (§3.3), 62 remain, entirely unrelated to items 1–4:

- **61 entries**: `ar|uom|missing|*` — essentially the entire `en/uom.json` key set (title, addUnit, editUnit, unitCreated, unitDeleted, category/code/name/symbol/*Help/*Placeholder, conversionFactor*, decimalPlaces, roundingMethod, isBaseUnit/isSystem/isActive, categories.*, roundingMethods.*, errors.*, noUnits, systemUnitInfo, selectUnit, conversionCalculator*, quantity, fromUnit/toUnit, swapUnits, result, convert, precisionSettings.* (11 sub-keys)) is missing from `ar/uom.json`, which today only has a `"unmapped"` block.
- **4 entries**: `ar|import|plural|unitErrors.line_{few,many,two,zero}` — Arabic's 6 CLDR plural categories (`zero/one/two/few/many/other`) aren't fully covered for `unitErrors` in `ar/import.json` (only `line_one`/`line_other` exist there).

**Root cause (traced, not fixed):** the local authority script (`scripts/i18n-baseline-authority.sh`) doesn't compare against the working-tree's `apps/web/tools/i18n-completeness-baseline.json` — it derives the comparison blob from a pinned **seed commit** (`i18n_baseline_seed_commit: 6a0c1cd72b...` in `docs/handoff/progress/enforcement-p2.progress.yaml`), which lives on branch `codex/enforcement-p2-ci-guards` and is **not an ancestor of `dev`/HEAD** (`git merge-base --is-ancestor 6a0c1cd72b... HEAD` → false). `dev` has since merged commit `5edc719a9` ("feat(imports,uom): K-8 validation-time unit honesty..., K-9 unit-text mapping panel...") which added most of the current `en/uom.json` and `import.json unitErrors` keys — keys the frozen seed blob (from the unrelated CI-guards branch) has never seen, so they show up as "NEW" gaps rather than recognized pre-existing debt. This is a baseline/seed-branch divergence, not something introduced by this lane's diff (confirmed: none of this lane's 5 changed files touch `en/uom.json`, `ar/import.json`, or the baseline/progress-YAML pins).

**Why not fixed here:** translating ~61 keys into Arabic (plus 4 Arabic plural forms) is not a "minimal diff" mechanical fix and isn't part of the brief's named scope (item 3 named exactly 2 keys). Recommend routing to the team that already owns Arabic-backfill work (git history shows a dedicated `ar-backfill` commit — `3a2db0e97`, "shrink the i18n completeness baseline by the 161 closed entries" — and a `uom`/`import` translation owner from commit `5edc719a9`), and separately re-pinning `i18n_baseline_seed_commit`/`i18n_baseline_protected_blob` once `codex/enforcement-p2-ci-guards` and `dev` reconcile, so the local authority script stops comparing against a stale, off-branch snapshot.

## Files changed (all outside `src/features/import/**`)

- `apps/web/e2e/tsconfig.json`
- `apps/web/src/features/uom/hooks/useUnits.ts`
- `apps/web/src/features/uom/components/UnmappedUnitTextsPanel.tsx`
- `apps/web/src/locales/ar/uom.json`
- `apps/web/src/locales/fr/import.json`
