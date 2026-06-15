# UI Consistency Remediation — Follow-up Session Handover

**Written:** 2026-06-15 · **Author:** prior session (Claude) · **Pairs with:** [`PROGRESS.md`](./PROGRESS.md), [`REPORT.md`](./REPORT.md), [`CANONICALIZATION-SPEC.md`](./CANONICALIZATION-SPEC.md)

> **Goal of the follow-up session:** test the merged UI work on dev/local, then promote LOCAL `dev` → `origin/dev`.

---

## 1. Current state (where everything is)

- **All work is MERGED into LOCAL `dev`** — merge commit **`e2e872006`** ("Merge feat/ui-consistency-clusters into dev"), `--no-ff`, in the **`apps/erp.dev-consolidation`** worktree (that worktree is the one checked out on `dev`).
- The source branch **`feat/ui-consistency-clusters`** (36 commits off `04873f158`) still exists in worktree `apps/erp/.worktrees/ui-consistency-clusters` — safe to delete once promotion is done.
- **Merge was clean, no conflicts.** Dev's 13 parallel-session commits (POS offline-approval/security) touched **0 `apps/web/src` files** — fully disjoint from this work (which is all `apps/web/src` + these audit docs).
- **NOT pushed.** `origin/dev` was deliberately left untouched. Local `dev` is ~82 commits ahead of `origin/dev` (this work + the parallel POS session + earlier unpushed dev work) — see §6 before promoting.

### Verified gates (on the merged dev tree)
- `tsc --noEmit` → **0 errors**
- `eslint .` → **8459 warnings, 0 errors** (down from 11512 baseline; **−3053**, ~27% of all color/consistency drift removed)
- Every batch was TDD'd + vitest-verified during development; ~200 source files changed, ~60 new co-located test suites added.

---

## 2. How to test on dev/local (visual + functional)

### A. Build the merged dev into the served dist
The Docker web container (`erp-dev-web-1`, nginx at **`http://localhost:8089`**) serves a **bind-mounted static `dist/`** from `apps/erp.dev-consolidation/apps/web/dist`. To see the merged work, rebuild that dist FROM the dev-consolidation worktree:
```bash
cd /Users/houssamr/Projects/syneriva/apps/erp.dev-consolidation/apps/web
node_modules/.bin/vite build --outDir dist --emptyOutDir       # ~8s
```
Then open **`http://localhost:8089`** · login **`owner@cafe-tunis.tn`** / **`password`** (cafe-tunis IziPOS demo).
- API is reachable (axios baseURL is relative `/api/v1`, proxied by the same nginx).
- WebSocket/Pusher console errors at :8089 are **pre-existing env noise** (Reverb not proxied), not regressions.
- ⚠ A parallel session may also rebuild this shared dist — if a screenshot looks wrong, rebuild first.

### B. Frontend gates (run from `apps/erp.dev-consolidation/apps/web`)
```bash
node_modules/.bin/tsc --noEmit
node_modules/.bin/eslint .                 # expect 8459 warnings, 0 errors
node_modules/.bin/vitest run <path>        # scope to a feature; see §3 for the known-failing set
```
**Do NOT run the full PHPUnit/preflight suite** (crashes the laptop — CLAUDE rule / memory). Frontend gates only.

### C. Highest-risk areas to test first (most-changed / business-critical)
1. **POS checkout (live sale)** — heavily color-migrated (POSPage, ProductGrid, CartLineItem, PaymentPanel, AdvancedPaymentsModal, Calculator). Verify a full sale completes. *(POS offline sale/PIN/Z is Tauri-only; the web demo can't run the offline layer.)*
2. **Z-reports & shift reports** — money columns got `tabular-nums`; **no math was changed**, only display. Confirm totals still correct (ZReportList/Detail, ShiftDashboard/History).
3. **Pagination footer (app-wide)** — the m3 fix: it used to leak raw key names ("showing", "rowsPerPage:"). Now every list page should show **"Showing 1 to 25 of N results"** + a working rows-per-page Select. Check a few list pages (documents, partners, stock, vouchers, loyalty members).
4. **Modals → Modal organism** — many bespoke modals were swapped to the shared `Modal` (now `role="dialog"`): refund/reverse, deposit, add-payment-method, withholding preview/rule-form, finance add/edit-account, workshop time-entry/time-off/certification/bundle-component, settings user/role/location modals, POS modals (kept POS-native). Open/close/submit each.
5. **Money/precision (rule-19) changes** — `MenuCategoryItemManager` override-price → MoneyInput (was `Number()`); Expense min-amount validator → `bccomp`; PaymentAllocationForm float math → `lib/decimal`. Verify these submit the correct string values.
6. **Status badges** — status pills everywhere → `StatusBadge` + `statusTone`. Spot-check tones (e.g. work-order statuses, document statuses, kitchen line states, instrument/payment statuses) look right.
7. **Hub pages** (Finance/Inventory/POS/Marketing) — now `HubCard`/`HubGrid` (no rainbow chips); gating + Finance sections + POS "Open POS" CTA preserved.
8. **Location switcher (TopBar)** — renamed `LocationSelector` → `LocationSwitcher` (organism). Confirm the top-bar location switch still works + invalidates correctly.
9. **Workshop** — work-order list/detail/create, technician pages, StatusPill, badges, the 6 modals.
10. **Channels** — `channelPageStyles.ts` was deleted; the 6 channel pages now use PageHeader/atoms/tokens.

---

## 3. Known pre-existing test failures (NOT regressions — do not chase)

These failed **identically before this work** (verified by stashing the changes and re-running). They are tenant-scope invalidation-key assertions, error-state i18n mismatches, and a clear-button mock issue — none caused by this remediation:

| Test | Failing case(s) |
|---|---|
| `documents/__tests__/DocumentForm.tenantScope` | "invalidates create/update cascades…" |
| `documents/__tests__/DetailPagesAndRepository.tenantScope` | "scopes repository detail reads and GL account invalidation" |
| `documents/components/__tests__/DocumentComponents.tenantScope` | product/service reads; additional-cost reads (2) |
| `components/__tests__/SharedSelectors.tenantScope` | AddVehicleModal invalidations; header location-switch invalidations (2) |
| `catalog/components/__tests__/CompositeItemSearchSelect` | "clear button" shows / onChange empty (2) — i18n-mock returns key not "Clear selection" |
| `inventory/components/__tests__/ProductDocumentsTab` + `ProductMovementsTab` | "renders error state on API failure" (2) — `/error.*loading/i` regex vs the resolved string |

If you want these green, they need their own (separate) fixes — they're outside this remediation's scope.

---

## 4. Judgment calls made during migration (review these if anything looks "off")

- **Deliberate shade shifts** where no exact token existed: e.g. `red-600`→`textColors.error` (red-700), `gray-500`→`tertiary` (gray-600), POS "mark served" `purple-600`→brand primary, danger-outline buttons → solid `danger` (no `dangerOutline` variant). All on-theme; visually near-identical.
- **POS deliberate accents kept**: `amber`/`emerald` accents in POS (loyalty, kitchen-fire, near-expiry, coupon-applied) were intentionally retained — they're not off-theme drift and aren't flagged by the POS color rule.
- **Selection "ring" emphasis** (AdvancedPaymentsModal, TerminalSelector) replaced `ring-2 ring-blue-500` with shadow/border (no `ring` token exists).
- **POS modals NOT swapped to the web Modal organism** — POS is a separate touch-first design; its modals were tokenized in place, structure kept.
- **Admin super-admin panel (`src/features/admin/*`) deliberately NOT touched** — it's an intentional separate **dark** design language (REPORT M10). Forcing it into light tokens would break it; its English-only localization is an **owner decision** (see §5).

---

## 5. Remaining work (deferred — for a later session, with rationale)

### Deliberately deferred (need an owner decision or are high-risk/low-value)
- **M10 — admin panel localization (OWNER DECISION):** the dark super-admin panel is English-only. Either confirm that's intentional (staff-only) or localize it. Until decided, `src/features/admin` stays as-is.
- **Phase 4.4 — collapse `features/location/` + `features/locations/`:** NOT done on purpose. They're different concerns (`location/` = global active-location context/provider/switcher; `locations/` = locations data/CRUD + types + multi-select). Merging + unifying the `Location` type is a large, opinionated, high-risk refactor for low visual value.
- **5.5 — enforce `@/` imports via lint:** deferred — enabling globally would flood the ratchet with existing relative-import drift; needs a baseline regen + coordination.

### Minor polish not done (cheap, low-risk, do anytime)
- **m4** — curly punctuation / placeholder copy ("Select a customer…" ellipsis).
- **m5** — reserve body-space for the fixed cookie bar (it overlaps the last bit of page content until dismissed). The bar itself is now tokenized; only the layout padding remains.
- **m8** — one legacy `bg-opacity-75` overlay → Modal (not audited this pass).

### Shared-atom follow-ups (flagged repeatedly by subagents — need an atoms-layer pass)
- **`atoms/Textarea`** declares `error?: boolean` but doesn't destructure it → it leaks `error` to the DOM (React warning). `Input`/`Select` handle it correctly. One-line fix in the atom.
- **No `ring`/selection-emphasis token** in `designTokens.ts` — selection UIs currently use shadow/border. Add one if you want consistent selection rings.
- **No `Checkbox` atom** — raw `<input type="checkbox" className={tokens.checkbox.base}>` is used throughout (sanctioned pattern). A `Checkbox` atom would let those drop their last raw element.

### Optional: lock in the lint gains
The lint ratchet **baseline** (`apps/web/scripts/lint-warning-baseline.json`) still reflects the old ~11.7k count. Current is 8459. Consider regenerating it (`node scripts/lint-ratchet.mjs --update-baseline`) to lock the gains and tighten the gate — **coordinate with any parallel session first** (it affects the shared baseline).

---

## 6. Promotion (LOCAL dev → origin/dev) — AFTER testing passes

Follow the dev-sync discipline (CLAUDE.md rule 21 / the `dev-push-guard` hook):
```bash
cd /Users/houssamr/Projects/syneriva/apps/erp.dev-consolidation
git fetch origin dev
# If origin/dev advanced since, reconcile FIRST so the push is a clean fast-forward:
git merge origin/dev        # (or rebase local commits onto it)
# Then promote:
git push origin dev
```
**Heads-up before promoting:** local `dev` is ~82 commits ahead of `origin/dev`. That includes **(a)** this UI work (36 commits), **(b)** the parallel POS offline-approval/security session, and **(c)** earlier unpushed dev work. Promoting pushes **all of it** — confirm the owner is OK shipping the POS session's work too, or coordinate. The `dev-push-guard` PreToolUse hook blocks force-pushes and pushes of a behind/diverged local `dev`; if it blocks, run the exact reconcile command it prints.

**Do NOT** force-push or reset shared `dev`.

---

## 7. Quick reference — commit map (this work, on `dev`)

```
e2e872006  Merge feat/ui-consistency-clusters into dev   ← merge commit
  Phase 3.7  8a40dea3b  hub pages → HubCard/HubGrid
  Phase 3.2  02b79390e (reports) 706e988b8 (lists) a99495fd4 (forms)
             8d32dfff0 + 75372f309 (detail) e31e50edf (withholding)
             9575e1f9b (treasury pages/components) 9a8e6e5f9 (ledger/CoA + Modal a11y)
  Phase 3.3  96b72e559 (core settings) 3387f65da (settings leftovers)
  Phase 3.4  bf7c90639 (inventory) 368fde8a6 (catalog/menu) b7f931e09 (residuals)
  Phase 3.5  dc1828837 00d7d0a6c fb6970186 cc7aeeb13 69c9d0757 (POS, incl. Analytics)
  Phase 3.6  afa2fe5f9 (workshop) 90b8cb515 (lint-gap guard)
  Phase 4    5aecf7f26 (shims) c15c487a6 (channelPageStyles) c6d7c4d0d (paginator) 46f5d01c6 (LocationSelector)
  Phase 5    bb2001723 (pagination i18n/m3) b5f38f164 (cookie bar)
```
Full per-phase detail + lint deltas: the **Change log** table in [`PROGRESS.md`](./PROGRESS.md).
