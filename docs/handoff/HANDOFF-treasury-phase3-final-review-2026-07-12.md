# HANDOFF — Treasury Phase ③ (cash-visibility) final whole-branch review

> Written 2026-07-12 by the session that ran both Codex-track final reviews (treasury-ui-gaps + bank-directory, both merged same day). Use when the owner reports the Phase ③ Codex desktop build DONE in worktree `../erp.treasury-phase3` (branch `feat/treasury-phase3-cash-visibility`).

**Session-start prompt:** "Read docs/handoff/HANDOFF-treasury-phase3-final-review-2026-07-12.md and run the final whole-branch review it mandates."

## Protocol

The exact Phase-② orchestrated pattern, freshly exercised twice today — use these as templates:
- `docs/superpowers/audits/2026-07-12-treasury-ui-gaps-final-review.md` (FE track, 6 lanes incl. live Playwright drive)
- `docs/superpowers/audits/2026-07-12-bank-directory-final-review.md` (full-stack track, 7 lanes incl. one Fable lane)

Session = ORCHESTRATOR ONLY. Sonnet discovery lanes (diff map/scope drift + gate-trail honesty) + Opus verification lanes (treasury-reviewer for GL/spine, fiscal-pos-reviewer if POS surfaces touched, frontend-conventions-reviewer, contract lane, fresh-runs lane, live drive). Fable ONLY on a crucial money surface — for Phase ③ that is the **G13 transfer path** (atomic two-repository movement + GL) IF a lane raises BLOCKER/HIGH there or declares uncertainty; otherwise Opus throughout. Codex's self-run gates do NOT replace this review.

## First checks (from the dispatch decision)

1. All 4 gate APPROVEs present in the worktree's gate artifacts + progress file (per brief `docs/handoff/CODEX-treasury-phase3-cash-visibility-2026-07-12.md`).
2. The A-1 deviation entry exists (spec amendment A-1 acknowledgment).
3. **Movement-port byte-diff empty** — the spine port must be untouched.

## ⚠️ Facts the Phase ③ build does NOT know (landed on dev after its dispatch base `f1d6c1d30`)

- **dev tip is now `73e9bd156`** — BOTH parallel Codex tracks merged 2026-07-12: treasury-ui-gaps (`a2c0968f2`) and bank-directory phases 1–3 (`ba8b338a4`).
- **`RepositoryDetailPage.tsx` conflict is now REAL:** Track 1 added the balance-adjustment dialog (button in the header actions, `AdjustBalanceDialog` mount, `treasury.adjust` gating) to the same page where Phase ③ adds the G13 transfer action; `en/fr/treasury.json` gained `repositories.adjustBalance.*` (Track 1) AND `validation.*` (Track 2). Expect merge conflicts on that page + locales; resolve additively (both actions coexist in the header) and re-run the page tests from BOTH branches after resolution.
- Repositories now expose `bank_id`/`bank_account_validation` in API responses and `AddRepositoryModal` uses `BankPicker` — cosmetic overlap only, but the FE conventions lane should confirm Phase ③ didn't fork stale copies of these files.
- Follow-up register items from today's reviews that touch the same surfaces (don't re-litigate, don't fold in): repository `currency` not exposed by `formatRepository()` (LOW ticket), `TICKET-instruments-bankpicker-fk-2026-07-12.md` (separate track).

## Deploy owes to verify are documented in-branch before merge

Phase ③ likely adds: tenant migrations (notification center tables?), **new named queues — every `onQueue('x')` needs a `config/horizon.php` entry (HorizonQueueCoverageTest pins this)**, new permissions (→ reseed + `permission:cache-reset`, the tenant-blind cache bug), and any scheduled jobs. The review must confirm a deploy checklist exists in-branch (pattern: `bank-directory-deploy-checklist.md`, `treasury-phase2-deploy-checklist.md`). Outstanding cross-track owes for the next real deploy, in order: `tenants:migrate` → banks backfill (checklist §2 — bare `tenants:run db:seed` silently no-ops) → chart re-seed if new tenants → perm reseed + cache-reset.

## Merge protocol

Identical to today's: all lanes APPROVE (or fix-wave + re-review touched lanes) → merge to LOCAL dev → ff-push origin/dev (dev-push-guard hook: don't put `--force` of any kind in the same compound command as the push — it pattern-matches) → prune worktree+branch → memory update (`project_treasury_phase3_cash_visibility.md` + MEMORY.md line). Reviews saved to `docs/superpowers/audits/`. Tests by path only; vitest zombie-worker kill; live drive against the local stack (recipe: `reference_local_db_per_tenant_demo_launch.md`, API from the WORKTREE this time — Phase ③ has backend changes).
