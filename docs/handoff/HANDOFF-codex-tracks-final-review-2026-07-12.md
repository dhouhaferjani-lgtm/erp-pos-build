# HANDOFF — Final reviews of the two returned Codex tracks (2026-07-12)

**Mandate:** both autonomous Codex tracks have REPORTED DONE. This session runs their final whole-branch reviews — **the exact Phase-② protocol** (see `docs/superpowers/audits/2026-07-11-treasury-phase2-final-review.md` for the shape): the session is ORCHESTRATOR ONLY; dispatch Sonnet discovery lanes + Opus verification lanes as parallel subagents; the session synthesizes, owns the merge decision, merges to local dev, ff-pushes origin/dev, prunes worktrees. Codex's self-run gates do NOT replace this review.

## The two tracks

### Track 1 — Treasury UI Gaps (FE-only)
- Worktree `../erp.treasury-ui`, branch `feat/treasury-ui-gaps`. Brief: `docs/handoff/CODEX-treasury-ui-gaps-2026-07-10.md` (autonomous-gates revision at top). Gate artifacts: `docs/handoff/gate-reviews-tug/` + `docs/handoff/treasury-ui-gaps-progress.md` (in the worktree).
- Scope: Wave A = repository balance-adjustment dialog (`RepositoryDetailPage`), Wave B = expense pay dialog (`ExpenseDetailPage`). FRONTEND-ONLY over shipped backends — any `apps/api` production change in the diff is automatic scope drift.
- Review lanes (all Opus — FE-only track; Fable only if a money-adjacent BLOCKER emerges): D1 diff map/scope-drift (Sonnet), D2 gate-trail audit (Sonnet), V1 frontend-conventions-reviewer (post-sweep atoms/tokens/i18n/tenant-keys/design-audit-0-new), V2 contract verification (the TWO 422 error shapes — flat-string vs canonical envelope — per the brief's Wave A/B contract notes; invalidation keys incl. cross-feature repository+cash-position), V3 fresh runs (vitest by path, typecheck, lint, both audits).
- Acceptance criteria are in the brief (Playwright-verifiable) — a live drive on the local stack or staging is part of the review.

### Track 2 — Bank Directory phases 1–3
- Worktree `../erp.banks`, branch `feat/bank-reference-verification` (was rebase-first onto current dev — VERIFY the rebase happened; the design-doc commit must sit on top of a recent origin/dev). Brief: `docs/handoff/CODEX-bank-directory-2026-07-10.md` (autonomous revision). Gates: `docs/handoff/gate-reviews-bank/` + `docs/handoff/bank-directory-progress.md`.
- Scope: Phase 1 banks table + TN.json data + BanksSeeder + GET /banks; Phase 2 `Shared/Banking` RIB/IBAN validator + BankPicker + PaymentRepository bank_id wiring; Phase 3 partner_bank_accounts + B2BFieldsSection.
- **The crucial surface is the mod-97 validator math** (`app/Shared/Banking/`): must be bcmath/GMP on strings end-to-end — a 20-digit RIB overflows int64; PHPStan's decimal guards do NOT cover it. **This one verification lane runs on Fable 5** (owner tiering: crucial financial spine); everything else Opus. Verify against the design doc's worked vector `07040005810111129653 → TN59 0704 0005 8101 1112 9653` by RE-DERIVING the math, not trusting the fixture.
- Other lanes: module boundaries (Partner → Shared contract only, never Treasury internals), seeder idempotency, warn-but-allow (NO hard FormRequest rejection — brief §6), FE conventions, fresh runs.
- **Deploy-owe check (the lesson staging just taught us):** `BanksSeeder` runs for NEW tenants via `TenantInitializationService` — EXISTING staging tenants won't have banks rows, same gap class as the Phase-② chart accounts (that smoke failed on exactly this pattern; remediation + corrected command in `docs/handoff/treasury-phase2-deploy-checklist.md` §2). The review must confirm the track documents its existing-tenant seeding step; if not, add it to their progress/deploy notes before merge.
- **Post-merge unlock:** the Phase-② interlock ticket — instruments adopt `BankPicker` + a real FK on `payment_instruments.bank_id` (shipped as plain uuid). Create a small follow-up task/brief after this merges; do NOT fold it into this review.

## Merge protocol (both tracks)
Per track: all lanes APPROVE → merge to LOCAL dev → ff-push origin/dev → prune worktree+branch → memory update. Findings → fix-wave (subagents) → re-review touched lanes → then merge. `dev` is shared and parallel sessions push constantly (Phase ③ spec session is live): `git fetch origin dev` before every merge/push; ff-only promotion; never force-push (dev-push-guard hook enforces).

## Wider state (context for judgment calls)
- Phase ② fully closed: shipped `18ad9e67c`, follow-ups `3b4f11434`, staging smoke PASS after chart remediation, checklist corrected `1223dcc37`. Memory: `project_treasury_phase2_instruments`.
- Phase ③ (cash-visibility) spec/plan cycle runs in ITS OWN session (`docs/handoff/HANDOFF-treasury-phase3-spec-kickoff-2026-07-12.md`). Interlock: Phase ③ FE wants a transfer action on `RepositoryDetailPage`, which Track 1 owns — merging Track 1 FIRST removes that collision; prefer that order (Track 1 review → merge → Track 2).
- 🎫 Pre-launch tickets open: productize `accounting:seed-charts` + opening-balance backfill as real artisan commands (ad-hoc tinker forms must not reach real-tenant deploys).
- Standing rules: review tiering (Opus standard / Fable crucial-spine only); reviews saved to files; tests by path only, NEVER the full PHPUnit suite; vitest zombie-worker kill; worktree gotchas (stale vendor symlink, `CACHE_STORE=array` for transform).

## Session-start prompt
"Read docs/handoff/HANDOFF-codex-tracks-final-review-2026-07-12.md and run the two final reviews it mandates, Track 1 first."
