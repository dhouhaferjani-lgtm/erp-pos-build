# Gate r1 — PR #216 "fix(i18n): correct ImportProgress aria-label key path"

| Field | Value |
|---|---|
| PR | #216 · `dhouhaferjani-lgtm` · head `fix/i18n-import-aria-key` · base `dev` |
| PR head sha | `cfaf90dc6666bb36341b10028c26252b2e418478` |
| Merged (gate) sha | `e7251d5357a5075e6b818195942915e580144c76` (merge of local dev `d56d62535` + PR) |
| Worktree | `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-216` |
| Reviewer | Fable adversarial merge gate, 2026-09-05 |
| Files | `apps/web/src/features/import/components/ImportProgress.tsx` (1 line), `.../ImportProgress.test.tsx` (new) |

## Verdict: **MERGE**

One-line key-path correction, a falsifiable render test against the real i18n
instance, zero collateral. Every claim in the PR body — including the
DEV-QA-037/005 false-positive call — was independently re-verified and holds. All
findings below are MINOR / informational and none blocks the merge.

---

## Verified facts (with citations)

**The bug was real and the corrected path is the one that exists.**
Before: `aria-label={t('wizard.progressAriaLabel')}`; after:
`apps/web/src/features/import/components/ImportProgress.tsx:25`
`aria-label={t('wizard.execute.progressAriaLabel')}` on namespace `'import'`
(`ImportProgress.tsx:22`). Key location confirmed by parse:
- `apps/web/src/locales/en/import.json:168` → `"progressAriaLabel": "Import progress"` (under `wizard.execute`)
- `apps/web/src/locales/fr/import.json:168` → `"progressAriaLabel": "Progression de l'import"`
- `wizard` top-level keys are `['steps','upload','mapping','validation','execute','complete','proceedWithErrors','confirmPartialImport','partialImportDescription','proceedWithValid','proceedWithValidCounts','retired']` — **no** `progressAriaLabel` at that level, so the old call resolved to the raw key string.

**Arabic: falls back to English, by design, and this is a *baselined* gap.**
`apps/web/src/locales/ar/import.json` exists and is registered
(`src/lib/i18n.ts:147`), but its `wizard` object holds only
`['validation','complete','proceedWithValidCounts']` and `wizard.execute` is
**absent** (verified by parse). `fallbackLng: 'en'` (`src/lib/i18n.ts:483`)
resolves the key per-key, so Arabic now announces "Import progress" instead of
`wizard.progressAriaLabel` — strictly better, exactly as the PR body states. The
i18n authority audit accepts this: `import` is inside the "21 ns / 1921 keys served
in English" alias set, with 2816 known gaps held at the baseline. The author's
decision to leave it to DEV-QA-042 is correct and honestly declared.

**No other mis-pathed key in the feature.** Enumerated every `t('wizard.…')` literal
under `src/features/import/` (49 distinct keys): all resolve to real paths under
`wizard.upload` / `wizard.mapping` / `wizard.validation` / `wizard.execute` /
`wizard.complete` / `wizard.retired` or to real top-level `wizard.*` keys
(`confirmPartialImport`, `partialImportDescription`, `proceedWithValidCounts`).
`wizard.progressAriaLabel` no longer appears anywhere in `src/`.

**The test genuinely proves key resolution (rule 17 — assert rendered output).**
`ImportProgress.test.tsx:18-23` renders the real component with no i18n mock;
`src/test/setup.ts:2` imports the real `../lib/i18n`. It asserts the *accessible
name* (`toHaveAccessibleName('Import progress')`) plus a structural guard
(`expect(nav.getAttribute('aria-label')).not.toContain('wizard.')`).
**Red without the fix:** the old path yields the literal `wizard.progressAriaLabel`,
which fails both assertions. Falsifiable.

**No collateral.** The only production consumer is
`src/features/import/pages/ImportWizardPage.tsx:1609`; `ImportWizardPage.options.test.tsx:110`
stubs `ImportProgress: () => null` and no import test asserts a `navigation` role
or the aria-label, so nothing else can break.

**The dropped-scope claim (DEV-QA-037/005) is verified as a genuine false positive.**
`grep -rn 'navigation\.stockByLocation' src/` → **zero hits**. The only
`stockByLocation` references are `inventory:stockByLocation.*`
(`StockByLocationPage.tsx:28-30`, `ProductLocationMatrix.tsx:47-51`,
`RebalancingView.tsx:21-22`) and `batches:detail.stockByLocation`
(`BatchDetailPage.tsx:244`) — the latter defined in both `en/batches.json` and
`fr/batches.json` (parse-verified). Correctly investigated, correctly dropped,
correctly disclosed with a PR retitle rather than silently absorbed.

---

## Findings

### 1. MINOR — the key stays semantically misfiled under `wizard.execute`
`ImportProgress.tsx:25` now reads an execute-step key, but `ImportProgress` is the
**whole-wizard stepper** rendered for every step
(`ImportWizardPage.tsx:1609`, steps `upload/map/execute`). The author fixed the
call site rather than relocating the key. That is the minimal, lowest-risk change
and I would not block on it — but the key is now a trap: a future cleanup of the
`wizard.execute` block could delete a string the global stepper depends on.
**Fix (optional follow-up):** move the string to `wizard.progressAriaLabel` in
`en/import.json` + `fr/import.json` and point the component back at the
step-agnostic path.

### 2. MINOR — the test hardcodes the English copy
`ImportProgress.test.tsx:21` asserts `toHaveAccessibleName('Import progress')`, so a
future en copy edit turns this into a red herring. The companion assertion at
`:22` (`not.toContain('wizard.')`) is the durable regression guard.
**Fix (optional):** assert against `i18n.t('import:wizard.execute.progressAriaLabel')`
and keep the `not.toContain('wizard.')` guard as the real check — that also lets
one case cover `fr`.

### 3. MINOR (informational) — line-number drift in the PR body
The PR body cites `BatchDetailPage.tsx:242`; in the merged tree the `t()` call is
at `BatchDetailPage.tsx:244`. Cosmetic, does not affect the claim.

---

## Guardrail evidence (verbatim, re-run by the reviewer in the merged worktree)

`cd .worktrees/pr-216/apps/web && ./node_modules/.bin/vitest run src/features/import/components/ImportProgress.test.tsx`:
```
 RUN  v3.2.4 /Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-216/apps/web

 ✓ src/features/import/components/ImportProgress.test.tsx (1 test) 87ms

 Test Files  1 passed (1)
      Tests  1 passed (1)
   Start at  14:46:15
   Duration  1.53s (transform 275ms, setup 502ms, collect 200ms, tests 87ms, environment 289ms, prepare 95ms)
```

`./node_modules/.bin/eslint src/features/import/components/ImportProgress.tsx src/features/import/components/ImportProgress.test.tsx`:
```
ESLINT EXIT: 0
```
(no output — 0 errors, 0 warnings)

`./node_modules/.bin/tsc --noEmit`:
```
TSC EXIT: 0
```
(no output)

`bash ../../scripts/i18n-baseline-authority.sh` (the `audit:i18n:local` leg of web lint):
```
i18n completeness OK — 55 namespaces, authored keys: en=9501, fr=9518, ar=5146 authored (1921 behind aliases); 2816 known gap(s) held at the baseline.
  English-aliased namespaces — ar: 21 ns / 1921 keys served in English
I18N EXIT: 0
```

Audit tools:
```
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries
[sweep-progress] Design-system audit C1-C6 violations: 810
[gate-summary] Design-system baseline: 810 acknowledged, 0 new, 0 stale baseline entries
[audit-quantity] raw quantity display sites: 0 total (0 baselined, 0 new, 0 stale baseline entries)
```
Baseline honesty: **no baseline or locale file appears in the diff** — `git diff dev HEAD --stat` is exactly 2 files (`ImportProgress.tsx` +1/-1, `ImportProgress.test.tsx` new). No `--write-baseline` absorption, no suppression comments, no alias indirection.

Merge cleanliness: `git diff-tree --cc e7251d535` is **empty** → clean merge, no
conflict resolution. `git log 4d5b8812e..d56d62535 -- ImportProgress.tsx` is empty →
the dev-side eslint autofix commit `5524b9a69` did not touch this file.

Commit hygiene: single commit, conventional prefix, body documents both the fix and
the dropped scope with reasons. Scope creep: **none** — in fact this PR *removed*
scope after disproving a reported defect, and said so.

## Could not verify
- **The DEV-QA registry is not in this repo.** `grep -rl 'DEV-QA-050'` across the
  checkout returns nothing — the ticket's original text/priority could not be read,
  and DEV-QA-042 (the Arabic backfill lane she defers to) could not be confirmed to
  exist as a tracked item. Ask the author for the registry.
- **Not manually recette'd**: no screen-reader / browser pass; the accessible name
  is proven in jsdom only.
- The `fr` and `ar` runtime resolutions were verified by reading the locale JSON and
  `fallbackLng: 'en'` (`src/lib/i18n.ts:483`), not by rendering under those languages.
- `ImportWizardPage.duplicates.test.tsx` / `.options.test.tsx` were **not** re-run
  (instructed to run by file only); their non-interaction with the aria-label was
  established by grep, not by execution.

## Merge to local dev: **YES**
