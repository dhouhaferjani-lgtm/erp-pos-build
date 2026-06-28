# Resume Kickoff — POS Caisse redesign (continue at Header)

> Paste into a fresh Claude Code session started **inside the redesign worktree**:
> `/Users/houssamr/Projects/syneriva/apps/erp.pos-caisse`. Continues the IziPOS
> "Caisse Parapharmacie" redesign on branch `feat/pos-caisse-redesign`.

---

You are continuing the IziPOS "Caisse Parapharmacie" redesign. **Read the resume anchor first, in full:** `docs/superpowers/pos-caisse-redesign-STATUS.md` (it has a "Session pause 2026-06-28" block with exactly what's done + next). Then the spec `docs/superpowers/specs/2026-06-27-pos-caisse-redesign-design.md` and tracker `docs/superpowers/pos-caisse-redesign-tracker.yaml`. Also `apps/erp/CLAUDE.md`.

## Context (already shipped on this branch — do NOT redo)
- P1 theme foundation (light/dark + accent/corner/density tokens via Tailwind v4 `@theme inline`, self-hosted fonts, ergonomic scale). **CTA stays BLUE** (`action`=blue); `accent` (orange, swappable) = selected/highlight only.
- Full atom library in `components/ui/` (StockBadge, ProductThumb, Avatar, Stepper, Pill, Tabs, Toggle, KpiCard, BreakdownBar, Divider) + DEV `/theme-preview` gallery (auth-free visual harness — `pnpm dev` → http://localhost:1420/theme-preview).
- P2 `NavRail` wired into `AppShell` (rail opposite cart; `/customers` placeholder route). P3 `CartLineItem` collapse/expand + `TransactionCart` accordion.
- Access gating (owner): nav `Caisse-Shift`/`/reports/z` = managers only (`isManagerRole`); blind-count `expected_cash` leak in `EndOfDayPreviewModal` fixed (+regression tests). Read-only `SaleDetailModal` ("Voir") in `TodaySalesPanel`.

## PRE-FLIGHT (before coding)
1. `git -C . log --oneline origin/dev..HEAD` and `git status` — confirm clean, on `feat/pos-caisse-redesign`.
2. `git fetch origin dev` — have the **parapharmacy** and/or **loyalty** sessions merged to dev? If so, `git merge origin/dev` (or rebase) FIRST and resolve, since they may have landed `index.css`/types/sync changes. They own `ProductCard`/`ProductGrid`/product+customer data; **do not touch those** (coordination handoff: `docs/superpowers/kickoffs/2026-06-28-coordination-productcard-grid-handoff.md`).
3. Confirm `apps/pos` builds: `pnpm typecheck` and the redesign tests `pnpm vitest run src/components/ui/__tests__ src/components/molecules/CartLineItem`.

## NEXT WORK (in order)
1. **Header restyle (P2)** — `apps/pos/src/components/Header.tsx` (the `<header>` block, ~lines 540–644). Mock §5.0: 62px, `--surface` + bottom border + slight elevation; left = brand (font-display) + terminal `Badge` + online dot; grouped clusters separated by vertical `Divider`s (atom); operator `Avatar` + name + switch; lock/reports/settings as ghost `IconButton`s. **PRESERVE ALL LOGIC**: the B3 single `StatusPill` (connectivity+sync), the sync `IconButton`, `StockFreshness`, the shift chip → EndOfDay, and every handler/modal (EOD, Reports, X-report, CashDrawer, manager-PIN). Tokens only (Header is in ESLint `tokenMigratedGlobs`), i18n, both themes.
2. **ReportsMenu manager-gating** — `components/pos/ReportsMenu.tsx`: gate X-report + Z-report-history (+ cash-drawer ops) to managers (`isManagerRole(operator?.roles)`); leave Today-sales/transaction-history open. (X-report payload carries no expected/opening cash, but still manager-gate per owner point 1.)
3. **Logged-in visual verification pass** — the authed shell can't render past the offline gate in a headless env. Run the app logged-in (or have the owner) and visually confirm: nav-rail layout (rail opposite cart), Header, cart collapse/expand, SaleDetailModal, blind-close (expected hidden). Screenshot.
4. Then continue per tracker: full `TransactionCart` panel restyle, P4 payments, P5 modals (+ build `ModalShell`/`Drawer`/`Toast` shells), P7 reports restyle (tokenize `TodaySalesPanel`).

## Guardrails
Worktree off dev; merge to LOCAL dev first, promote to origin/dev as clean fast-forwards; never force-push dev. **NEVER run the full PHPUnit/Vitest suite** unprompted (laptop crash risk) — run by path. Tokens only in `apps/pos/src`; i18n all strings; TDD; **Codex for CODE review** per chunk (Claude agent for any DOC review). Commit frequently (crash-safety). Stay on shell/cart/header/nav/atoms; avoid `ProductCard`/`ProductGrid`/`product.ts`/`customerTypes`/`syncService`/`migrations.ts`/`companyConfig` (the 2 data sessions own those).
