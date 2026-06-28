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
Do these in a later pass (NOT regressions — known follow-ups):
- **Cart-line remove ✕ sits next to the expand chevron → mis-tap risk.** Separate them (move remove into the expanded controls, or add a clear gap / larger hit-targets), so tapping to expand can't accidentally delete the line.
- **Line deletion has no confirmation.** Add a guard — **ideally a configurable setting** (e.g. `confirmLineDelete` in `settingsStore` + an Appearance/behaviour toggle) so a store can turn confirm-on-delete on/off. Consider optimistic-delete + Undo toast vs a confirm tap.
- General touch-friendliness sweep of the restyled surfaces against the §6 ergonomics floor (48px targets, 12–16px gaps) — several controls are still sub-floor (e.g. cart-line buttons were `h-7`/28px before the Stepper; audit the rest as restyled).
- (owner: "some other stuff" — capture more as they surface during the visual passes.)

## Guardrails (always)
Worktree off dev; merge to LOCAL dev first, promote to origin/dev as clean fast-forwards; never force-push dev. NEVER run the full PHPUnit suite (crashes laptop) — run by path. Tokens only in `apps/pos/src` (ESLint guard); i18n all strings; TDD; Codex for CODE review (Claude agent for DOC review). Commit frequently (crash-safety).
