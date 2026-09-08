# Adversarial gate r1 — PR #218 (frontend half)

**PR**: #218 `fix(inventory-counting): liste des comptages — le filtre affiché ment (status=active hors vocabulaire)`
**Branch under review**: `gate/pr-218` @ `09927c932` (worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-218`)
**Base**: `origin/dev` @ `cdedc2830`
**Scope**: `apps/web` only. Backend controller, CI wiring and the feature-lane manifest are the inventory reviewer's half and were NOT reviewed here (one controller grep was used only to confirm the FE↔BE query-param contract).
**Reviewer**: frontend-conventions-reviewer (adversarial gate, r1)
**Date**: 2026-09-08

---

## Verdict: MERGE-WITH-FIXES

Zero Blockers. The fix itself is correct, well-scoped and genuinely tested; the invariant it claims ("displayed === applied") holds under every path I could construct. Two Majors are merge-readiness items (one user-visible raw i18n key on the very control being repaired, one red repo gate), plus seven Minors.

---

## Gates I ran myself (nothing accepted as reported)

Deps were absent in the worktree; installed with `pnpm install --frozen-lockfile --prefer-offline` from the worktree root.

| Gate | Command | Result |
|---|---|---|
| Feature tests | `pnpm vitest run src/features/inventory-counting --reporter=dot` | **11 files / 79 tests passed** — matches the PR's 79/79 claim exactly |
| Typecheck | `pnpm typecheck` | **clean** (`tsc --noEmit`, exit 0) |
| ESLint (feature dir) | `pnpm eslint src/features/inventory-counting` | **0 errors**, 36 warnings |
| ESLint (whole package) | `pnpm lint:eslint` | **0 errors**, 6410 warnings (pre-existing warning baseline) |
| Design-system audit | `pnpm audit:design-system` | `802 acknowledged, 0 new, 0 stale` |
| TanStack keys | `pnpm audit:keys` | `0 violations, 0 new, 0 stale` |
| Quantity display | `pnpm audit:quantity` | `0 total, 0 new, 0 stale` |
| i18n completeness | `pnpm audit:i18n:local` | **FAIL — 1 gap**: `ar\|sales\|missing\|documents.dueDateBeforeIssue` (pre-existing, see MAJOR-2) |

Vitest worker pools killed after the run (`pkill -f 'node (vitest'`).

---

## Mechanism / conservation audit (protocol steps 2-4)

**Baseline honesty — PASS.** Replayed `apps/web/tools/audit-design-system-baseline.json` entry-level (base blob vs HEAD blob, set difference):

- entries: **802 → 802**; exactly **1 removed / 1 added**, both `C2 | src/features/inventory-counting/pages/CountingListPage.tsx`.
- removed: `<select value={filters.status || 'all'} onChange={(e) => {…`
- added: `<select value={filters.status ?? 'all'} onChange={(e) => {…`

Same file, same category, same `#1` occurrence index — a genuine **signature re-acknowledgement of a pre-existing violation whose text moved**, not a new hardcoded-colour violation and not a `--write-baseline` absorption of this diff's own debt. The PR's "1 re-ack de signature, 802→802" claim is accurate.

**Detector-evasion scan — PASS.** Grepped every added line of the `apps/web` diff for `eslint-disable`, `@ts-`, `any`, alias/indirection tables and hardcoded Tailwind shades:
- no suppression comments, no `@ts-*`, no `any`;
- **zero hardcoded Tailwind colour classes added** (regex over `(bg|text|border|ring)-(gray|blue|red|…)-\d{2,3}` on `^\+` lines → empty);
- the only `as` cast in the entire diff is in a test helper, `apps/web/src/features/inventory-counting/api/__tests__/countingApi.test.ts:58` (`return call[0] as string`). **Production source carries zero `as`** — the PR's claim holds.
- No composed/interpolated token classes introduced; existing `${colorTokens.*}` usage is unchanged.

**Conservation — N/A** (not a refactor/sweep; no token expansion needed). The only rewritten class string is unchanged between base and HEAD (`CountingListPage.tsx:145` identical to base).

---

## Invariant verification (the PR's central claim)

`resolveStatusFromParams` (`apps/web/src/features/inventory-counting/pages/CountingListPage.tsx:47-55`) resolves through `.find()` over the same `STATUS_OPTIONS` array (`:19-34`) that renders the `<option>` list (`:147-151`), so `filters.status` is **structurally guaranteed** to be a rendered option. `handleStatusChange` (`:101-104`) applies the same whitelist. Traced by hand for every dashboard link (`CountingDashboardPage.tsx:99,106,113,120,131,160`):

| URL | select shows | filter applied | wire |
|---|---|---|---|
| `?status=active` | `active` | `active` | `?status=active` |
| `?status=pending_review` | `pending_review` | `pending_review` | `?status=pending_review` |
| `?status=finalized` | `finalized` | `finalized` | `?status=finalized` |
| `?overdue=true` | `overdue` | `overdue` | `?overdue=true` |
| `?status=overdue` | `overdue` | `overdue` | `?overdue=true` |
| `?status=bogus` / none / `?status=all` | `all` | `all` | no status param |
| `?status=active&overdue=true` | `overdue` | `overdue` | `?overdue=true` |

**URL round-trip confirmed**: `updateFilters` writes `params.set('overdue','true')` for the alias and never `status=overdue` (`:85-89`), mirroring `countingApi.list` (`apps/web/src/features/inventory-counting/api/countingApi.ts:28-32`). Pinned by the API test at `countingApi.test.ts:73-80` (`expect(url).not.toContain('status=overdue')`).

**FE↔BE contract confirmed** (sanity only, depth is the inventory reviewer's): `InventoryCountingController.php:239-249` reads `$request->boolean('overdue')`, the `status === 'active'` alias, and `CountingStatus::tryFrom` for exact values. The FE emits `overdue` and `status` mutually exclusively, so the documented precedence is never exercised from this client.

**Blast-radius grep — single caller confirmed**: `countingApi.list` is called only from `queries.ts:57`; `useCountingList` only from `CountingListPage.tsx:74`; `CountingFilters` has no consumer outside the feature dir. The widened `status` union cannot break any other surface. `CountingStatusBadge.tsx:11` keys its `Record<CountingStatus, string>` off the **unwidened** `CountingStatus`, so exhaustiveness is preserved (verified by clean typecheck).

**Query key — PASS**: `queries.ts:56` uses `tenantScopedKey([...countingKeys.list(filters)])`; `audit:keys` reports 0.

**i18n parity — PASS (verified empirically, not by eye).** Ran i18next in-package against the real locale files:
```
counting.status.active  -> "En cours"   (fr) | "In Progress" (en) | "قيد التنفيذ" (ar)
counting.status.overdue -> "En retard"  (fr) | "Overdue"     (en) | "متأخر"      (ar)
```
Both new keys exist under `counting.status` in all three locales (`fr/en/inventory.json:659-660`, `ar/inventory.json:64-65`) and resolve correctly.

---

## Findings

### BLOCKER
None.

### MAJOR

**M1 — The default option of the repaired dropdown renders a raw i18n key to users.**
`apps/web/src/features/inventory-counting/pages/CountingListPage.tsx:40` returns the key `allStatuses`, rendered at `:149`. The page binds `useTranslation('inventory')` (`:58`) and `inventory.json` has **no `allStatuses` key in fr, en or ar**; `src/lib/i18n.ts:492-496` sets `defaultNS: 'common'` but **no `fallbackNS`**, so there is no fallback to `common.json` (where the string does exist in all three locales). Verified empirically with i18next against the real resource files:
```
t('allStatuses')  -> "allStatuses"
t('actionsLabel') -> "actionsLabel"
```
The same defect hits the table's Actions column header at `:215`. Result: the very control this PR fixes ships with its **first and default option labelled `allStatuses`** — the filter no longer lies about what it applies, but it still lies about what it is. The PR declares both keys as pre-existing/out of scope, which is honest, but the fix is 2 keys × 3 files in files this PR **already edits**.
*Fix directive*: add `"allStatuses"` and `"actionsLabel"` to `src/locales/{fr,en,ar}/inventory.json` (or add `fallbackNS: 'common'` in `src/lib/i18n.ts:492` — repo-wide, needs its own gate).

**M2 — `pnpm --filter @autoerp/web lint` is RED on this branch, so this PR cannot present a green web gate.**
`pnpm audit:i18n:local` fails with `ar|sales|missing|documents.dueDateBeforeIssue`. I confirmed this is **not** caused by #218: the diff touches no `sales.json` (`git diff cdedc2830..HEAD --stat -- apps/web` lists only `inventory.json`), and the PR discloses it. It is nonetheless a fact of merge readiness, not a footnote — the composite `lint` script exits non-zero.
*Fix directive*: land the one-line `ar/sales.json` key in its own micro-PR (or against dev) **before** merging #218, so #218's CI is green on its own merit rather than merged past a known-red gate.

### MINOR

**m1 — The gate evidence "ESLint feature 0 erreur" gives zero coverage of the two files carrying the fix.**
`apps/web/eslint.config.js:57` and `:60` explicitly ignore `**/src/features/inventory-counting/api/countingApi.ts` and `**/src/features/inventory-counting/pages/CountingListPage.tsx`. Running ESLint on them directly returns `File ignored because of a matching ignore pattern`. The PR's ESLint claim is true but vacuous for the changed source. Type safety is still covered — `tsconfig.json` uses `"include": ["src"]`, so `pnpm typecheck` **does** check both files, which is why the "zero `as`" claim is meaningfully verified. Note also that the ignore-block's stated reason ("Files not included in tsconfig.json project references") is **stale for these two files**.
*Fix directive*: out of scope for #218 — open a follow-up to drop lines 57 and 60 from the ignore list and burn down whatever surfaces; state in the PR body that the two fixed files are ESLint-exempt so the claim is not read as coverage.

**m2 — `CountingStatus` is a hand-rolled FE type shadowing a generated DTO, and this PR builds a new exported type on top of it.**
`apps/web/src/features/inventory-counting/types.ts:16-27` re-declares the union that already exists generated at `packages/shared/types/generated.d.ts:1221` (identical members), and a **third** copy lives at `packages/shared/src/inventory-counting/types.ts:11` whose `CountingFilters` (`:232`, `status?: CountingStatus | 'all'`) is now **divergent** from the web one. `CountingStatusFilter` (`types.ts:35`) extends the shadow. Per `docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md` and rule 7 (types flow from backend) this is a declared-debt case, not a #218 regression: **no file in `apps/web/src` imports from `@autoerp/shared` at all**, so the generated surface is currently unreachable repo-wide, and the `packages/shared/src/inventory-counting` copy is dead (no `packages/shared/src/index.ts`, no importers). The new `CountingStatusFilter` itself is legitimate — a FE-only presentation superset that has no backend counterpart.
*Fix directive*: no code change in #218. Add a `docs/glossary.md` row for **Inventory counting** declaring `CountingStatus` (generated) as the one surface and the dead `packages/shared/src/inventory-counting/types.ts` copy as a known duplicate to delete; note the divergence in the follow-up ticket.

**m3 — Filter state is initialised once and never re-synced from the URL; Back/Forward desyncs the URL from the shown filter.**
`CountingListPage.tsx:61-72` seeds `useState` from `searchParams` on mount only, and `setSearchParams(params)` at `:96` **pushes** a history entry (no `{ replace: true }`). Sequence: land on `?status=active` → change the select to `finalized` (URL becomes `?status=finalized`) → press Back → URL reads `?status=active` while the select and the request still say `finalized`. The PR's tested invariant (displayed === applied) survives; the URL is the thing that lies, and a copy/paste or bookmark from that state reopens a different filter.
*Fix directive*: either derive `filters.status` from `searchParams` on every render (single source of truth) or pass `{ replace: true }` at `:96`; add a test that re-renders with changed search params.

**m4 — The FE overdue vocabulary is narrower than the backend's, so a hand-typed `?overdue=1` silently degrades.**
`CountingListPage.tsx:48` matches only the literal string `'true'`, while the backend uses `$request->boolean('overdue')` (`InventoryCountingController.php:239`) which also accepts `1`, `on`, `yes`. `?overdue=1` resolves to `all` — the select and the request agree (no lie), but the user's intent is dropped without signal. Currently unreachable from the only producer (`CountingDashboardPage.tsx:120` emits `overdue=true`).
*Fix directive*: accept the same truthy set as the backend at `:48`, or document the FE contract as `overdue=true` exactly.

**m5 — No test pins the new locale keys.**
`CountingListPageStatusFilter.test.tsx:10-14` mocks `react-i18next` so `t` returns the key verbatim — by construction the suite cannot detect a missing or misnamed `counting.status.active` / `.overdue`, nor the M1 raw-key defect. I verified all three locales manually (see above), but the guarantee is not in the repo. The codebase already has the pattern for this (`src/features/import/__tests__/ImportWarningLocales.test.ts:28`).
*Fix directive*: add a 6-assertion locale test asserting `counting.status.active` and `.overdue` exist in fr/en/ar.

**m6 — Declined shrink opportunity on the exact line being rewritten.**
The status filter at `CountingListPage.tsx:142-152` stays a raw `<select>` in a `features/` directory and its C2 baseline entry is re-acked (`tools/audit-design-system-baseline.json:131`) rather than migrated to the existing `Select` atom (`src/components/atoms/Select`, 27 consumers today). The ratchet is shrink-preferred, and this PR rewrote the element's `value`/`onChange` anyway. Not a violation of baseline honesty (the re-ack is legitimate), but the debt was carried forward by choice.
*Fix directive*: optional in-lane — swap to `<Select>` and delete the baseline entry (802 → 801); otherwise note the deferral.

**m7 — The diff's only `as` cast sits in a test helper and trips a lint warning.**
`api/__tests__/countingApi.test.ts:58` (`return call[0] as string`) → `@typescript-eslint/no-unsafe-type-assertion`.
*Fix directive*: type the mock (`mockApi.get.mock.calls[0]?.[0]` with a `typeof … === 'string'` guard) to keep the diff cast-free end to end.

---

## Cross-cutting checks (all clear)

- **Owner UI principles**: no new competing colours/badges/accents (two plain `<option>` rows added); no high-contrast decorative band; no disabled/coming-soon control; no brand string in copy; no refund/sales blending; no module-gate or permission heuristic touched; no new route (the PR *fixes* a mislinked-behaviour class — dashboard cards that landed on an unfiltered list now filter correctly).
- **Blind counting (A-9)**: this is the counting **list**; columns are id / scope / status / progress / created / actions (`CountingListPage.tsx:199-216`). No expected quantities or amounts are pre-shown. Unaffected.
- **Quantity display precision**: no quantity surface touched; `audit:quantity` 0/0/0.
- **Second-of-everything**: no catalogue entity, no table, no unique key touched — FE-only presentation filter. N/A.
- **Industry baseline (conventions/10)**: a status-filter bugfix on an existing flow, not a new user-facing flow spec. N/A.
- **Data-meaning tests**: the new FE tests assert real DOM (`select.value`) **and** the argument actually handed to the query hook (`CountingListPageStatusFilter.test.tsx:66-70,79-84`) — i.e. meaning, not "renders without throwing". This is the right shape.

---

## Summary

The fix is sound, minimally scoped, correctly tested at the level that matters, and its baseline touch is honest. Fix **M1** in-lane (2 keys × 3 files, in files the PR already edits), clear **M2** by landing the pre-existing `ar/sales.json` gap first so CI can go green, and record m1-m7 as follow-ups. Then merge.

**MERGE-WITH-FIXES** — I gate, I do not merge.
