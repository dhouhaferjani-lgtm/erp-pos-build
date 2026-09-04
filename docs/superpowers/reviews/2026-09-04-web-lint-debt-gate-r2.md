# Gate r2 — web lint-debt lane (`lane/rh-web-lint-debt`)

- **Date:** 2026-09-04
- **Reviewer:** frontend-conventions-reviewer (adversarial gate, read-only)
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-lint`
- **Branch / tip:** `lane/rh-web-lint-debt` @ `ec5810b2f` — base `6f16fd8f7`
- **Commits reviewed:** `4b5b58789`, `f77eb40be`, `a930da581`, `ec5810b2f`
- **Diff:** 7 files (`apps/web/eslint.config.js`, `src/features/uom/hooks/useUnits.ts`, `src/features/uom/components/UnmappedUnitTextsPanel.tsx`, `src/features/uom/__tests__/tenantScope.test.tsx`, `src/locales/ar/uom.json`, `src/locales/fr/import.json`, `docs/handoff/HANDBACK-web-lint-debt-2026-09-03.md`)

## VERDICT: **MERGE**

Gate r1's ruling was implemented exactly as ruled: `e2e-local/**` is ESLint-**ignored**, `e2e/tsconfig.json` is byte-identical to base, and the two MINOR predicate/cascade test items landed with genuinely two-sided falsifying coverage. Ratchet honesty holds under independent replay: **the lane's net warning contribution is 0** (not +1 as the handback still says), it removes 5 hard parse errors, it touches no baseline file, and it uses no suppression comment or detector indirection. Zero blocking findings. Five non-blocking items, four of them documentation-accuracy in the handback.

---

## 1. Blocking findings

**None.**

---

## 2. Non-blocking findings

### MINOR-1 — Handback ratchet numbers are stale by one commit (overstates the lane's own debt)
`docs/handoff/HANDBACK-web-lint-debt-2026-09-03.md` §2b (decomposition table + "Net | +1" row) and §7 ("Outstanding" bullet 1) report `@autoerp/web current = 6455`, lane contribution `+1 warning`, attributed to `` `text-${unmappedCalls}` `` at `src/features/uom/__tests__/tenantScope.test.tsx:213:39`. Commit `ec5810b2f` (landed *after* the handback rewrite `a930da581`) changed that line to `` `text-${String(unmappedCalls)}` ``, eliminating the `restrict-template-expressions` warning. Measured tip = **6454 warnings, net 0**.
**Fix directive:** update §2b's table row and total, and §7's first outstanding bullet, to `current = 6454`, lane net `0 warnings / −5 errors`.

### MINOR-2 — Handback "Files changed" lists a file that is net-unchanged
`docs/handoff/HANDBACK-web-lint-debt-2026-09-03.md` final "Files changed" list includes `apps/web/e2e/tsconfig.json`, but `git diff 6f16fd8f7..ec5810b2f -- apps/web/e2e/tsconfig.json` is empty (touched in `4b5b58789`, reverted in `f77eb40be`).
**Fix directive:** drop it from the list or annotate it "touched in round 1, reverted in round 2 — net unchanged".

### MINOR-3 — Handback §6 gap arithmetic does not add up
§6 states "**61 entries**: `ar|uom|missing|*`" and "**4 entries**: `ar|import|plural|…`" against a stated total of 62. Measured today: **58** `ar|uom|missing|*` + **4** `ar|import|plural|*` = 62.
**Fix directive:** correct 61 → 58 in §6.

### MINOR-4 — Merging this lane does not turn CI `frontend-lint` green (not this lane's debt, but state it)
`.github/workflows/ci.yml:2411` (`pnpm audit:design-system`) and `:2472` (`pnpm lint:ratchet`) both still fail on the merged tree: 14 new design-system entries, **all** in `src/features/import/pages/ImportWizardPage.tsx` (fenced out of this lane's scope by the brief), and the ratchet at 6448 → 6454 (+6 pre-existing `dev` drift, present at the lane's base before any edit — independently measured, see §4).
**Fix directive:** open two owner items — (a) route the 14 `ImportWizardPage.tsx` C2/C3 entries + 11 stale baseline entries to the import lane; (b) an owner-acked re-baseline decision for the 6448 → 6454 pre-existing drift (LEDGER D-J0-10 precedent). Do **not** let this lane absorb either.

### MINOR-5 — `e2e-local/` now has no automated guard at all; its manual path is undiscoverable
`apps/web/eslint.config.js:47-48` removes `e2e-local/**` from ESLint, and `apps/web/e2e/tsconfig.json:12` (correctly) does not include it. Its only remaining guard is `apps/web/e2e-local/tsconfig.verify.json` — verified green today (`tsc --noEmit -p e2e-local/tsconfig.verify.json` → exit 0) — but no `package.json` script invokes it, so a future edit to the harness can rot silently.
**Fix directive:** add `"typecheck:e2e-local": "tsc --noEmit -p e2e-local/tsconfig.verify.json"` to `apps/web/package.json` scripts (manual-run only, not wired into CI) so the ruled manual path is one discoverable command.

---

## 3. Item-by-item verification

### Item 1 — `e2e-local/**` ignored, e2e tsconfig restored — **PASS**

- `apps/web/eslint.config.js:47-48`:
  ```js
  // e2e-local/ is a manual-only harness, never run in CI (docs/handoff/HANDOVER-SESSION-M-DHOUHA-WAVE2-2026-09-02.md:12).
  'e2e-local/**',
  ```
  Citation verified exact: `docs/handoff/HANDOVER-SESSION-M-DHOUHA-WAVE2-2026-09-02.md:12` — "…— **run manually only, never in CI**."
- `apps/web/e2e/tsconfig.json` — **byte-identical to base**: `git diff 6f16fd8f7..ec5810b2f -- apps/web/e2e/tsconfig.json` → empty; base content `"include": ["campaign/**/*.ts", "../playwright.campaign.config.ts"]` confirmed by direct checkout of `6f16fd8f7`.
- No e2e-local file is linted:
  ```
  $ node ./node_modules/eslint/bin/eslint.js e2e-local
  You are linting "e2e-local", but all of the files matching the glob pattern "e2e-local" are ignored.
  $ node ./node_modules/eslint/bin/eslint.js e2e-local --no-ignore
  ✖ 5 problems (5 errors, 0 warnings)     # parse errors — reachable only via --no-ignore, as ruled
  ```
- Full run: base `6f16fd8f7` printed `✖ 6459 problems (5 errors, 6454 warnings)` with 10 `e2e-local` mentions; merged dev+lane printed `✖ 6454 problems (0 errors, 6454 warnings)` with **0** `e2e-local` mentions.
- `pnpm typecheck:e2e` (`tsc --noEmit -p e2e/tsconfig.json`) → **exit 0**, 0 lines of output.
- No `src/` or `e2e/` file references `e2e-local`; no `.github/workflows/` file references it. Ignoring it hides no CI-exercised surface.

### Item 2 — `useUnits.ts` invalidation fix — **PASS**

- `apps/web/src/features/uom/hooks/useUnits.ts:54-56` now uses `predicate: uomUnmappedUnitTextsInvalidationPredicate(tenantId, companyId)`; the `tenantScopedKey(...)`-wrapped `queryKey` filter (a proven prefix-vs-suffix no-op) is gone.
- `apps/web/src/features/uom/hooks/useUnits.ts:73-88` — new predicate matches the shape of the two sibling predicates exactly (`k[0]==='uom'`, `k[1]==='unmapped-unit-texts'`, tenant/company read from the **suffix**), consistent with the key produced at `useUnits.ts:40`.
- `pnpm audit:keys` → `Gate C … : 0` / `0 acknowledged, 0 new, 0 stale`, **exit 0**, on both the lane tip and the merged tree.
- **Falsification replayed by me** (in a disposable worktree at `ec5810b2f`, never in the lane worktree; worktree since deleted, lane `git status` clean):
  - Predicate body → `return (_q) => false`: **2 failed | 13 passed** — `expected false to be true` (predicate unit test) and `expected 1 to be 2` (cascade refetch counter).
  - Predicate body → `return (_q) => true`: **2 failed | 13 passed** — `expected true to be false` twice (namespace/tenant rejection test and the company-2 `isInvalidated` isolation assertion).
  The tests are genuinely **two-sided** — an over-broad predicate fails them as surely as an always-false one. Counter design at `tenantScope.test.tsx:197-240` is per-call in `queryFn`, so a non-firing cascade leaves the counter at 1 deterministically.

### Item 3 — ar/fr i18n keys — **PASS**

- `apps/web/src/locales/ar/uom.json:2` — `"unitUpdated"` added top-level, correct namespace, matching `en/uom.json:6` / `fr/uom.json:6`. No plural forms on the en key, so none required.
- `apps/web/src/locales/fr/import.json:198` — `"line_many"` added inside `unitErrors`, matching the French CLDR `many` category alongside existing `line_one`/`line_other`; text identical to `line_other`, consistent with repo convention.
- Audit before/after (same script, same machine):
  - base `6f16fd8f7`: `64 NEW gap(s)`, list contains `ar|uom|missing|unitUpdated` and `fr|import|plural|unitErrors.line_many`.
  - lane tip: `62 NEW gap(s)`; both keys **absent**. Remaining: `4 × ar|import|plural|*` (incl. the *Arabic* `line_many`, a different key) + `58 × ar|uom|missing|*` — the K-9 Arabic backlog, fenced out of this lane by the brief.

### Item 4 — Ratchet honesty — **PASS** (net 0, see §4)

### Item 5 — Test/typecheck evidence, re-run by me — **PASS**

| Command (cwd `…/.worktrees/rh-lint/apps/web`) | Result |
|---|---|
| `node ./node_modules/vitest/vitest.mjs run src/features/uom/__tests__/tenantScope.test.tsx` | exit 0 — `Test Files 1 passed (1)` / `Tests 15 passed (15)` |
| `node ./node_modules/typescript/bin/tsc --noEmit` (`pnpm typecheck`) | exit 0, 0 lines |
| `node ./node_modules/typescript/bin/tsc --noEmit -p e2e/tsconfig.json` (`pnpm typecheck:e2e`) | exit 0, 0 lines |
| `pnpm test:eslint-rules` | exit 0 — 6 RuleTester suites green (`no-dead-tailwind-token-interpolation` 5/5, `no-hardcoded-step` 6/3, `no-literal-decimal-places` 6/3, `no-parsefloat-on-money` 10/5, `no-untranslated-literal` 10/6, `no-hardcoded-entity-route` 8/4) |
| `node ./node_modules/vitest/vitest.mjs run tools/__tests__` (`pnpm test:tools`) | exit 0 — `8 passed (8)` / `160 passed (160)` — detectors untampered |
| `node tools/audit-quantity-display.mjs` | `0 total (0 baselined, 0 new, 0 stale)` |
| `node ./node_modules/typescript/bin/tsc --noEmit -p e2e-local/tsconfig.verify.json` | exit 0 (manual path intact) |

No leftover vitest/eslint workers after the run (`ps aux | grep -c '[v]itest'` → 0).

---

## 4. Ratchet numbers (independently measured, not taken from the handback)

`node scripts/lint-ratchet.mjs` from `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-lint`:

```
=== Lint-warning ratchet ===

  @autoerp/web     baseline=  6448  current=  6454  FAIL — warnings rose 6448 → 6454 (+6)
  @autoerp/pos     baseline=    84  current=    84  held — 84 warnings

RESULT: FAIL — fix new errors / warnings, or justify a baseline bump.
```

Independent base measurement — disposable detached worktree at `6f16fd8f7` with `node_modules` symlinked, `node ./node_modules/eslint/bin/eslint.js .`:

```
✖ 6459 problems (5 errors, 6454 warnings)
```

Independent post-merge measurement — same worktree at `fbae84cb3` (current `dev`) merged with `lane/rh-web-lint-debt`, same command:

```
✖ 6454 problems (0 errors, 6454 warnings)
```

| Tree | errors | warnings | vs 6448 baseline |
|---|---|---|---|
| base `6f16fd8f7` (pre-lane `dev`) | **5** | **6454** | +6 (pre-existing drift) |
| lane tip `ec5810b2f` | **0** | **6454** | +6 |
| current `dev` `fbae84cb3` + lane (real merge) | **0** | **6454** | +6 |
| `@autoerp/pos` | 0 | 84 | held at baseline |

**Lane net contribution: 0 warnings, −5 errors.** The entire +6 over baseline exists at the lane's base commit, before any edit in this lane. Per-file attribution is therefore unnecessary and was superseded by the stronger whole-tree before/after: the only lintable files the diff touches are 3 files under `src/features/uom/`, and the aggregate warning count is unchanged at 6454 across base → tip. (`eslint.config.js` is itself ESLint-ignored via the pre-existing `*.config.js` entry; the two `.json` locale files are outside the `**/*.{ts,tsx}` files glob.)

**Baseline honesty:** `scripts/lint-warning-baseline.json`, `apps/web/tools/audit-design-system-baseline.json`, `audit-tanstack-keys` and `audit-quantity` baselines are **all untouched** by the diff — no `--update-baseline` / `--write-baseline` absorption. Verified: `git diff --name-only 6f16fd8f7..ec5810b2f | grep -i baseline` → empty.

**Mechanism audit (no evasion):** `git diff 6f16fd8f7..ec5810b2f -- apps/web | grep -E '^\+.*(eslint-disable|@ts-ignore|@ts-expect-error|baseline|it\.skip|describe\.skip)'` → empty. No detector file (`tools/audit-*.mjs`, `eslint-rules/*.js`) is modified. The one metric that improved (5 errors → 0) improved by an owner-ruled **scope exclusion of a manual-only harness**, not by suppression or alias indirection. The design-system improvement (`UnmappedUnitTextsPanel.tsx` C2 entry gone) is a **real atom swap** to `Select` (`src/components/atoms/Select/Select.tsx`, which renders a real `<select>` with `tokens.select.base`), not a rename — `apps/web/src/features/uom/components/UnmappedUnitTextsPanel.tsx:139-155`, with the hand-rolled Tailwind class string dropped in favour of the atom's tokens and only a layout-specific `className="w-full"` retained.

`node tools/audit-design-system.mjs` (lane tip **and** merged tree, identical):
```
[sweep-progress] Design-system audit C1-C6 violations: 810
[gate-summary] Design-system baseline: 796 acknowledged, 14 new, 11 stale baseline entries
```
All **14** new entries are `src/features/import/pages/ImportWizardPage.tsx` (brief-fenced, other lane). Zero new entries in any file this lane touches.

---

## 5. Merge readiness

```
# dev @ fbae84cb3 (session start)
$ git -C /Users/houssamr/Projects/syneriva/apps/erp merge-tree --write-tree dev lane/rh-web-lint-debt
a94d53441f0dc31af836d1ff11eb34d17743e41e     # exit 0 — clean tree, no conflict section

# dev @ 7f86dbf0c (advanced mid-review by a parallel lane merge — re-run)
$ git -C /Users/houssamr/Projects/syneriva/apps/erp merge-tree --write-tree dev lane/rh-web-lint-debt
281deb1227b1001263d16841de25f5ff4d9cb160     # exit 0 — still clean, no conflict section
```

- **Conflicting files: none.**
- `git diff --name-only 6f16fd8f7..dev` ∩ `git diff --name-only 6f16fd8f7..lane/rh-web-lint-debt` = **∅** — re-confirmed at both `fbae84cb3` (31 commits since base; 11 touch `apps/web`) and `7f86dbf0c`. None overlap this lane's 7 files.
- A real `git merge` of the lane into detached `fbae84cb3` in a disposable worktree succeeded with no conflict (`7 files changed, 341 insertions(+), 13 deletions(-)`), and the merged tree measures 0 errors / 6454 warnings (§4).
- Rebase onto current `dev` is optional; a plain merge is safe.

---

## 6. Design-system / owner-principle spot checks on the touched UI

- `UnmappedUnitTextsPanel.tsx:106-171` uses the `DataTable` molecule (not a raw `<table>`) — pre-existing and unchanged.
- All user-facing strings in the touched region go through `t()` (`uom:unmapped.*`); the new `Select` keeps its `aria-label` translated (`:140`).
- No new color literals, no interpolated token variants/opacity, no `colorClasses` usage introduced. The file's `semanticColorTokens as colorTokens` import remains used at 14 pre-existing sites.
- No dead/disabled control introduced (OQ-11), no new high-contrast band (OQ-5), no hardcoded brand string, no new route or nav surface.

---

## 7. Commands run (for replay)

```bash
W=/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-lint
cd $W && node scripts/lint-ratchet.mjs
cd $W/apps/web && node tools/audit-tanstack-keys.mjs
cd $W/apps/web && node tools/audit-design-system.mjs
cd $W/apps/web && node tools/audit-quantity-display.mjs
cd $W/apps/web && bash ../../scripts/i18n-baseline-authority.sh
cd $W/apps/web && node ./node_modules/typescript/bin/tsc --noEmit
cd $W/apps/web && node ./node_modules/typescript/bin/tsc --noEmit -p e2e/tsconfig.json
cd $W/apps/web && node ./node_modules/typescript/bin/tsc --noEmit -p e2e-local/tsconfig.verify.json
cd $W/apps/web && pnpm test:eslint-rules
cd $W/apps/web && node ./node_modules/vitest/vitest.mjs run src/features/uom/__tests__/tenantScope.test.tsx
cd $W/apps/web && node ./node_modules/vitest/vitest.mjs run tools/__tests__
cd $W/apps/web && node ./node_modules/eslint/bin/eslint.js e2e-local            # all ignored
cd $W/apps/web && node ./node_modules/eslint/bin/eslint.js e2e-local --no-ignore # 5 errors
git -C /Users/houssamr/Projects/syneriva/apps/erp merge-tree --write-tree dev lane/rh-web-lint-debt
# base + merged full-eslint replays in a disposable detached worktree (created, measured, removed)
```

Lane worktree left untouched (`git status --porcelain` → empty). Disposable worktree removed. No merge, no push performed.
