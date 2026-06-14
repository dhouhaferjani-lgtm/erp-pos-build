# UI Consistency Remediation — Progress & Handover

**Branch:** `feat/ui-consistency-remediation` (off `origin/dev`)
**Worktree:** `apps/erp/.worktrees/ui-consistency`
**Audit:** see [`REPORT.md`](./REPORT.md) · **Started:** 2026-06-14
**Live app for verification:** `http://localhost:8089` · login `owner@cafe-tunis.tn` / `password` (cafe-tunis demo). The Docker stack serves a *built* image, so it reflects `dev`, **not** this worktree — use `apps/web` vite dev (or rebuild) to see local changes; use the live stack for unchanged-page regression baselines.

## Rules of engagement
- TDD: test first (Vitest), red → green → refactor. Strict types (no `any`). Tokens only — no new off-theme literals.
- **Never run the full PHPUnit/preflight suite** (crashes laptop). Frontend gates only here: `tsc --noEmit`, `eslint`, `vitest run --filter`/path-scoped.
- One PR per phase (or per cluster in Phase 3), each with before/after screenshots.
- Parallel session active → stay in this worktree; rebase on `origin/dev` before each push.

## Verification commands (run from `apps/web/`)
```
node_modules/.bin/tsc --noEmit
node_modules/.bin/eslint <changed paths>
node_modules/.bin/vitest run <changed test paths>
```

---

## Phase 0 — Stop new drift  ☐ (RESEQUENCED — see notes; ratchet-entangled)
> **Sequencing correction discovered during impl:** the lint *bans* can't come "first." (a) A broad full-palette WARN rule floods the per-app ratchet (fails `lint:ratchet` until the baseline is regenerated — coordinate with parallel session). (b) Raw-element **bans** (no raw `<button>/<input>`) must come AFTER the migration that removes those elements, else they instantly fail CI for both this work and the parallel session. So Phase 0 bans are effectively the *last* gate, enabled per-dir as each cluster is migrated.
- [x] 0.4 chartColors arch-guard — covered by `src/lib/chartColors.theme.test.ts` (asserts theme hex, forbids `#2563eb` et al.). ✅
- [ ] 0.1 Extend full-palette color lint to all dirs — **WARN globally requires `--update-baseline` (+~? warnings) → coordinate**. Safer interim: enable as ERROR only in dirs *after* they're migrated clean.
- [ ] 0.2 Custom rule: ban raw `<button>` outside `components/atoms/**` — enable per-dir post-migration (Phase 3).
- [ ] 0.3 Custom rule: ban raw `<input|select|textarea>` outside `components/atoms/**` — enable per-dir post-migration.
- [ ] 0.5 Verify new rules fire on a known offender and don't break gold-standard dirs.

## Phase 1 — High-ROI token fixes  ◧ (1.1 done; rest folded into RC-1)
- [x] 1.1 Rewrite `chartColors` in `designTokens.ts` → theme hex (primary `#1A6FB5`, success/warning/error/neutral + copper `secondary`). TDD: `src/lib/chartColors.theme.test.ts` (4 tests). ✅ committed.
- [ ] 1.2/1.3/1.4 **RESEQUENCED → see RC-1 below.** Why: piecemeal palette swaps (amber→yellow in the banner, orange→warning in MarginIndicator) are NOT ratchet-safe — `amber` is *invisible* to the color lint but `yellow` is warn-flagged, so swapping *grows* the warning count and fails `lint:ratchet`. The correct fix is the `@theme` remap (RC-1): keep the class strings, change what they resolve to → 0 new warnings, fixes ~130 files at once. The banner/MarginIndicator swaps were reverted.
- [ ] 1.5 Visual check via Playwright MCP — pending RC-1 / a worktree vite dev server (live :8089 runs the built dev image, not this branch).

### ⚠ Implementation findings (must read before continuing)
- **chartColors is per-vertical-blind.** A static JS object can't follow the CSS `[data-product="otospex"]` theme switch. My fix is correct for **IziPOS** (Deep Ocean, the live vertical) but Otospex (deep-blue + pink) charts still won't match. PROPER fix (follow-up): expose `--chart-*` CSS vars in *both* theme blocks in `index.css` and read them at runtime via `getComputedStyle`, with the static object as SSR/jsdom fallback. Touches the 3 owner-dashboard chart components + adjusts the test to check var names + fallback.
- **`@theme` only remaps blue→primary and gray→neutral.** green/yellow/red/amber/emerald/etc. are NOT remapped, so even "semantic" classes drift from `--theme-success/warning/error`. This is the heart of RC-1.
- **The lint baseline is stale.** `origin/dev` already lints at **11747** warnings vs baseline **11739** (+8) BEFORE this branch — pre-existing dev drift, not us. This branch adds **0**. Whoever regenerates `scripts/lint-warning-baseline.json` (`node scripts/lint-ratchet.mjs --update-baseline`) should coordinate with the parallel session.

## RC-1 — Wire the full palette to the theme (the lever)  ☐ NOT STARTED
The single highest-impact fix; deferred to its own visually-verified step because it changes color resolution app-wide and must be checked across both verticals + many screens.
- [ ] Define `--theme-success-50..900`, `--theme-warning-50..900`, `--theme-error-50..900`, `--theme-info-*` scales in BOTH `:root` and `[data-product="otospex"]`.
- [ ] In `@theme`, remap `--color-green-* → success`, `--color-yellow-*`+`--color-amber-* → warning`, `--color-red-*+--color-rose-* → error`, `--color-emerald-* → success`, `--color-sky-* → primary` (and decide on slate/violet/teal/cyan). Class strings stay identical → **0 ratchet impact**, ~130 off-theme files snap to brand.
- [ ] Visually verify each major screen in BOTH verticals (run `apps/web` vite dev from this worktree, or rebuild the container) via Playwright MCP. Watch for status palettes that were *intentionally* multi-hued (scheduling).
- [ ] Add an architecture/CSS test asserting the remaps exist.

## Phase 2 — Build missing primitives (TDD)  ☑ DONE (48 tests, tsc clean, 0 lint warnings)
- [x] 2.1 `PageHeader` molecule — 5 tests
- [x] 2.2 `DataTable<T>` molecule (header/stripe/hover, `tabular-nums`, numeric right-align, skeleton loading, empty slot) — 10 tests
- [x] 2.3 `StatusBadge` atom + `statusTone()` helper (own module for fast-refresh) — 14 tests
- [x] 2.4 `ListPageLayout` (PageHeader + filter slot + body + pagination slot) — 7 tests
- [x] 2.5 `HubCard` + `HubGrid` (one tokenized icon-chip, no per-card color) — 8 tests
- [x] 2.6 Wired into atoms/molecules barrels. ALL built only from existing baselined tokens → 0 new lint warnings.
- [ ] 2.7 (follow-up) update `components/README.md` to document the 5 new primitives.

## Phase 3 — Cluster migrations (worst first, 1 PR each)  ☐
- [ ] 3.1 `documents/` (epicenter: 65 raw controls, 5 bespoke modals, ~50 off-theme)
- [ ] 3.2 finance + treasury (11 bespoke modals, tabular-nums, parseFloat-on-money cross-ref)
- [ ] 3.3 admin/settings (10 bespoke modals, 169 raw controls, 3 settings layouts)
- [ ] 3.4 inventory/catalog (8 bespoke modals, rainbow hub, status badges)
- [ ] 3.5 POS color drift (keep POSButton/touch layout; adopt tokens; kill glassmorphism/dark)
- [ ] 3.6 workshop-* (close lint gap; status pills → StatusBadge; modals → Modal)
- [ ] 3.7 Convert 4 hub pages (Finance/Inventory/POS/Marketing) → HubCard/HubGrid

## Phase 4 — Kill duplicates  ☐
- [ ] 4.1 Remove 9 `ui/` re-export shims (update importers)
- [ ] 4.2 Rename two `LocationSelector` → `LocationField` (ui) / `LocationSwitcher` (organism)
- [ ] 4.3 Pick one paginator (`Pagination` vs `OffsetPagination`); migrate
- [ ] 4.4 Collapse `location/` + `locations/` → one dir + one `Location` type
- [ ] 4.5 Reconcile `channelPageStyles.ts` with atoms/tokens

## Phase 5 — Polish  ☐
- [ ] 5.1 list footer "showing __ rowsPerPage" label/i18n gap
- [ ] 5.2 placeholder copy ("Select a customer…"), curly punctuation
- [ ] 5.3 cookie-consent bar overlap (reserve body space)
- [ ] 5.4 POS smart-prompts: text-glyph icons → lucide
- [ ] 5.5 enforce `@/` imports via lint
- [ ] 5.6 admin localization decision (M10) — confirm with owner

---

## Change log (append per commit)
| Date | Phase | Commit | Notes |
|---|---|---|---|
| 2026-06-14 | setup | — | worktree off origin/dev @ 9177950d8; baseline tsc green |
| 2026-06-14 | audit docs | (this commit) | REPORT.md + PROGRESS.md + screenshot harness onto branch |
| 2026-06-14 | 1.1 | (this commit) | chartColors → Deep Ocean theme (IziPOS); 4 tests; per-vertical caveat logged |
| 2026-06-14 | 2 | (this commit) | 5 primitives (PageHeader/DataTable/StatusBadge/ListPageLayout/HubCard+HubGrid); 48 tests; tsc clean; 0 new lint warnings; barrels wired |
