# POS Caisse Redesign — STATUS / Resume (crash-recovery anchor)

> Single source for "where are we, how to resume." Updated each work session. If the
> laptop restarts, read this first, then the tracker + spec.

**Last updated:** 2026-06-28
**Branch:** `feat/pos-caisse-redesign` · **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.pos-caisse` (off `origin/dev`) · **Not pushed** (local-first per branch discipline).

## How to resume
```bash
cd /Users/houssamr/Projects/syneriva/apps/erp.pos-caisse
git log --oneline origin/dev..HEAD     # what's done on this branch
git status                              # clean?
cd apps/pos && pnpm install             # if node_modules missing after restart
pnpm typecheck && pnpm vitest run src/lib/__tests__/theme.test.ts src/components/ui/__tests__
pnpm dev                                # http://localhost:1420/theme-preview  (DEV gallery, no auth)
```
Authed POS screens need online + login (offline gate blocks); **`/theme-preview` is the auth-free visual harness** for tokens/atoms in both themes.

## Key docs (all committed on this branch)
- Spec: `docs/superpowers/specs/2026-06-27-pos-caisse-redesign-design.md`
- Per-screen tracker (SoT): `docs/superpowers/pos-caisse-redesign-tracker.yaml`
- Reviews: `docs/superpowers/audits/2026-06-28-pos-caisse-p1-theme-codex-review.md`
- Handovers (full-stack feature plans, await owner review): `docs/superpowers/specs/2026-06-28-parapharmacy-merchandising-handover.md`, `...-loyalty-pos-gating-offline-handover.md`
- New-session kickoff prompts: `docs/superpowers/kickoffs/2026-06-28-parapharmacy-merchandising-kickoff.md`, `...-loyalty-pos-kickoff.md`
- Project memory: `~/.claude/.../memory/project_pos_caisse_redesign.md`

## DONE (committed)
- **P1 theme foundation** — light/dark + accent/corner/density tokens (Tailwind v4 `@theme inline`, runtime-verified), self-hosted fonts (Montserrat/Public Sans/IBM Plex Mono), ergonomic type/touch scale, ThemeProvider + Appearance settings, stock-token exception. Codex-reviewed + fixed.
- **Atom library COMPLETE** (TDD, both themes verified in /theme-preview): `StockBadge`, `ProductThumb`, `Avatar`, `Stepper`, `Pill`, `Tabs`, `Toggle`, `KpiCard`, `BreakdownBar`, `Divider` (+ reuse existing `Button`/`IconButton`/`Badge`/`SegmentedControl`/`StatusPill`/`MoneyInput`). Only shells (`ModalShell`/`Drawer`/`Toast`) remain — build during the modal phases. DEV `/theme-preview` gallery shows them all.
- **Owner decisions applied:** CTA stays **blue** (`action`=blue; `accent`=separate swappable highlight); cart-line uses **mock collapse/expand**.
- **Two feature handovers + kickoff prompts** written (parapharmacy, loyalty).

## IN PROGRESS / NEXT (this session continues the redesign UI; data features go to new sessions)
- **P2 NavRail** — component BUILT + verified (TDD + /theme-preview sell-layout). **Pending: wire into AppShell** (replace flex-col layout with rail + header + main; route Caisse/Clients/Rapports/Caisse-Shift) — needs a logged-in visual pass.
- **P3 cart-line collapse/expand** — DONE: `CartLineItem` collapse/expand (mock pattern) + `TransactionCart` accordion state. Verified in /theme-preview. Remaining P3: full `TransactionCart` panel restyle (header/quick-actions/summary), and `ProductGrid`/`ProductCard` + displayMode/density reconcile — **ProductCard/ProductGrid deferred to coordinate with the parapharmacy session** (they render brand/skin and would conflict).
- **Header restyle** (P2) — NOT started (62px, dividers, operator Avatar, ghost actions); store-coupled, needs logged-in verification. Keep the B3 single status-pill.
- **Still queued:** P4 payments, P5 modals (+ ModalShell/Drawer/Toast shells), P6 returns, P7 reports. Leave **seams** for parapharmacy (Filtres/skin-advice/detail-tabs) + loyalty (points display) — gated/empty until those sessions land the data.
- **Conflict-avoidance with the 2 live data sessions:** stay on shell/cart/nav (redesign-only) files; avoid `product.ts`/`customerTypes`/`syncService`/`productRepository`/`migrations.ts`/`companyConfig`/customer pages and `ProductCard`/`ProductGrid` (parapharmacy owns data rendering there). Rebase onto dev as they land.
- **New session A (parapharmacy):** kickoff prompt above. Owns data model + seed + offline sync + data-bound UI.
- **New session B (loyalty):** kickoff prompt above. Land `feat/loyalty-earn-per-product` first, then POS gating + offline balance mirror + earn estimate.
- **Shared prereq (one owner):** wire orphaned `pullCustomers()` into `runFullSync()` — needed by both A (customer skin_type) and B (loyalty balance). First to land owns it; other rebases.

## Session pause 2026-06-28 (resume here)
Done since last note (all committed, tree clean, tests/typecheck/lint green):
- **P2 NavRail wired into AppShell** (rail opposite cart; `/customers` route + placeholder `CustomersPage` — parapharmacy/loyalty build out P8 in place).
- **P3 cart-line collapse/expand** (`CartLineItem` + `TransactionCart` accordion) — verified in `/theme-preview`.
- **Owner access constraints (points 1/2):** nav `Caisse-Shift` (Z-report history) = managers only (`isManagerRole`) + `/reports/z` route guarded; **SECURITY fix:** `EndOfDayPreviewModal` no longer leaks `expected_cash` via the legacy card during a blind count (+2 regression tests).
- **Owner point 3:** read-only **`SaleDetailModal`** + "Voir" action in `TodaySalesPanel` (view a past sale's lines/totals/payments without a DUPLICATA reprint).
- **Coordination handoff** for `ProductCard`/`ProductGrid` → parapharmacy session: `docs/superpowers/kickoffs/2026-06-28-coordination-productcard-grid-handoff.md` (reachable via `git checkout feat/pos-caisse-redesign -- <path>`).

NEXT on resume:
- **Header restyle (P2)** — NOT started (62px, group `Divider`s, operator `Avatar`, ghost actions; keep the B3 single status-pill + all EOD/reports/manager-PIN logic). Also gate the **X-report / manager-only items in the Header `ReportsMenu`** (X-report payload is safe but should still be manager-gated per point 1).
- Full `TransactionCart` panel restyle; P4 payments; P5 modals (+ shell atoms); P7 reports restyle (tokenize `TodaySalesPanel`/`SaleDetailModal` already tokenized).
- `ProductCard`/`ProductGrid` → owned by the parapharmacy session (handoff sent).
- **Verify all authed-screen layouts in a logged-in session** (offline gate blocks them here): nav-rail layout, header, cart, sale-detail, blind-close.
- React-doctor `--diff` warnings (button-type, only-export-components, untokenized `TodaySalesPanel`) — triage during the P7 restyle.

## Deferred touch-ergonomics fixes (owner feedback 2026-06-28, live `pnpm tauri dev`)
- ✅ **DONE (commit 834d73a33 / ref cleanup 791209cc5):** Cart-line remove ✕ moved OUT of the collapsed header (was next to the expand chevron → mis-tap) INTO the expanded controls (left, separated from the +/- · discount cluster). The whole collapsed header row is now a single `min-h-[48px]` expand/collapse `<button>` — tapping to expand can no longer delete the line.
- ✅ **DONE:** Configurable confirm-on-delete — `settingsStore.confirmLineDelete` (default ON) + a **Touch & Display** toggle in `SettingsPage`. When ON, the delete control arms on the first tap and removes on a confirming second tap (auto-disarms after 3s / on collapse). i18n `cart.confirmRemoveItem`, `settings.confirmLineDelete*` (en+fr). Chose two-tap-arm over optimistic-delete+Undo because the Toast shell isn't built yet (P5).
- ✅ **DONE (partial):** 48px floor applied to the new cart-line expanded controls (delete/discount/modifier) + the collapsed row min-height.
- ⏳ **REMAINING:** `ReturnLineItem` (refund section in `TransactionCart.tsx`) still uses `h-7`/28px qty+remove buttons — left for the **P6 returns restyle** (deferring to avoid unverified layout regressions in the unrestyled returns flow).
- (owner: "some other stuff" — capture more as they surface during the visual passes.)

## Session pause 2026-06-28 (cont. — resume here)
Done since the last pause (all committed on `feat/pos-caisse-redesign`, tree clean, typecheck + lint + react-doctor + targeted tests green):
- **Cart-line touch-ergonomics** (834d73a33, 791209cc5) — see the updated "Deferred touch-ergonomics fixes" block above (remove relocated, configurable confirm-on-delete). 9 CartLineItem tests + 2 settingsStore tests; TransactionCart test mocks the settings hook.
- **Header restyle (P2 §5.0)** (16664cec8) — 62px bar, `font-display` wordmark, decorative `aria-hidden` online dot, vertical `Divider` atoms between right-zone clusters (shift · operator · actions), operator `Avatar`. ALL logic preserved (B3 StatusPill, sync, StockFreshness, shift→EOD, manager-PIN, every modal/handler). Header.test.tsx green. Tokens-only (passes the ESLint token guard).
- **ReportsMenu manager-gating** (db2936fb3) — X-report + cash-drawer ops + Z-report history are manager-only (`isManagerRole(operator?.roles)`); transaction-history + today-sales stay open. New ReportsMenu.test.tsx (3 tests). NOTE: ReportsMenu still has hardcoded colors (bg-white/text-gray-*) — deliberately NOT tokenized here (gating-only scope); **P7 reports restyle owns the tokenization**.
- **P5 modal-shell foundation** (7aa7dfca7) — started the P5 shell consolidation on the canonical `components/pos/Modal.tsx` (13 consumers inherit): reduced-motion-aware entrance keyframes in `index.css` (`ez-fade-in` 160ms / `ez-sheet-rise` 200ms `cubic-bezier(.2,.8,.3,1)`, per spec §5 timings) applied to backdrop+content (ENTRANCE-only; exit transitions need mount-persistence → deferred to per-modal restyle); close ✕ 40px→48px (§6 floor). Additive — 38 modal-consumer tests green. **ProductCard/ProductGrid + product-detail fiche stay owned by the parapharmacy session (Phase D, rebase-gated on this branch) — do NOT touch.**
- **Code review FOLDED** (commit e49e34f9c): Codex detached-runtime produced NO output again (known-unreliable) → used a Claude reviewer agent per project precedent; findings → `docs/superpowers/audits/2026-06-28-pos-cart-line-header-review.md`. Verdict: 0 Blocking. Folded I1 (cleaner than the suggested revert — extracted a self-contained `CartLineRemoveButton` child so collapse-unmount resets the guard; eslint + react-doctor both clean) + M4 (operator Divider no longer orphans). Deferred to owner judgment: M2 (decorative online dot vs B3 single-pill — keep, aria-hidden) + M3 (decrement-to-zero bypasses confirm — by-design quantity gesture).

NEXT on resume (kickoff WORK IN ORDER item 4 onward):
- **Owner-driven logged-in visual pass** (BLOCKING the authed-surface sign-off; can't render past the offline gate headless): run `pnpm tauri dev` from this worktree and confirm — nav-rail layout (rail opposite cart), the new 62px Header (wordmark/dividers/operator avatar/online dot, both themes), cart collapse/expand + the relocated delete + armed confirm state (turn the Touch & Display "Confirm before removing a line" toggle on/off), SaleDetailModal, blind-close. Screenshot.
- Then per tracker: full `TransactionCart` panel restyle (header/quick-actions/summary), P4 payments, P5 modals (+ build `ModalShell`/`Drawer`/`Toast` shells), P7 reports restyle (tokenize `TodaySalesPanel` + `ReportsMenu`).
- `ProductCard`/`ProductGrid` → owned by the parapharmacy session (do not touch).

## Guardrails (always)
Worktree off dev; merge to LOCAL dev first, promote to origin/dev as clean fast-forwards; never force-push dev. NEVER run the full PHPUnit suite (crashes laptop) — run by path. Tokens only in `apps/pos/src` (ESLint guard); i18n all strings; TDD; Codex for CODE review (Claude agent for DOC review). Commit frequently (crash-safety).
