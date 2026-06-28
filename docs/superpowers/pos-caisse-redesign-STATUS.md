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
- **Atoms** (TDD, both themes verified in /theme-preview): `StockBadge`, `ProductThumb`, `Avatar`, `Stepper`, `Pill`. Plus DEV `/theme-preview` gallery.
- **Owner decisions applied:** CTA stays **blue** (`action`=blue; `accent`=separate swappable highlight); cart-line uses **mock collapse/expand**.
- **Two feature handovers + kickoff prompts** written (parapharmacy, loyalty).

## IN PROGRESS / NEXT (this session continues the redesign UI; data features go to new sessions)
- **This session:** redesign phases that DON'T need the new backend — remaining atoms (`Tab`, `KpiCard`, `BreakdownBar`, `Divider`, `Toggle`, `ModalShell`/`Drawer`/`Toast` shells), then **P2 app shell + nav rail**, **P3 sell screen** (TransactionCart restyle + **cart-line collapse/expand**, ProductGrid/ProductCard using new atoms + displayMode/density reconcile), P4 payments, P5 modals, P7 reports. Leave **seams** for parapharmacy (Filtres/skin-advice/detail-tabs) + loyalty (points display) — gated/empty until those sessions land the data.
- **New session A (parapharmacy):** kickoff prompt above. Owns data model + seed + offline sync + data-bound UI.
- **New session B (loyalty):** kickoff prompt above. Land `feat/loyalty-earn-per-product` first, then POS gating + offline balance mirror + earn estimate.
- **Shared prereq (one owner):** wire orphaned `pullCustomers()` into `runFullSync()` — needed by both A (customer skin_type) and B (loyalty balance). First to land owns it; other rebases.

## Guardrails (always)
Worktree off dev; merge to LOCAL dev first, promote to origin/dev as clean fast-forwards; never force-push dev. NEVER run the full PHPUnit suite (crashes laptop) — run by path. Tokens only in `apps/pos/src` (ESLint guard); i18n all strings; TDD; Codex for CODE review (Claude agent for DOC review). Commit frequently (crash-safety).
