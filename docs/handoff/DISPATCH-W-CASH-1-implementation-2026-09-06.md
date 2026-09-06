# Dispatch — W-CASH-1 implementation (slice plan rev 10, gate r10 DISPATCH-READY)

Plan of record: `docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md` (rev 10, commit `327727bdc`). Gate ladder r1–r10 under `docs/superpowers/reviews/2026-09-06-w-cash-1-slice-codex-gate-r*.md`. Owner rulings: `docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md` + `OWNER-QUESTIONS-consolidated-2026-09-06.md` (A1: storage decimal(15,4), precision = country preset). Staging manifest: `docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md`.

## Lane setup
- Worktree: `git worktree add .worktrees/w-cash-1 -b lane/w-cash-1-custody-transfer-doc dev` (from the main checkout, absolute path). Never `git stash`. Copy `vendor` if the symlinked vendor is stale (memory: worktree backend env gotchas); `CACHE_STORE=array` for `typescript:transform`.
- PG: private container on port 5455, DB `autoerp_test_wc1` (DB_DATABASE + DB_CENTRAL_DATABASE override); run tests by path; PG lane for every P0/T1/T3/T4/T6 test the plan marks PG.
- Order: **P0-a (census command, run per tenant, captured output) → P0-b (widening migration, NON-additive push: host-side backup first) → T1 → T2 → T3 → T4 → T5 → T6**, exactly as the plan's dispatch order. TDD red-first per the plan's named tests; no placeholder code; no `app()`; enums for every status/type column; JSONB DTOs; strings/BCMath only.
- **Ownership note:** the precision widening (P0) is executed HERE, in this lane; the parallel hardening session's queue item 1 is superseded — it must NOT run its own widening. (Recorded in memory and in its handover.)
- Push discipline: NOTHING is pushed to origin from this lane. Local dev merge only after the reviewer gates below.

## Reviewer gates (Opus agents, run by the orchestrator)
treasury-reviewer + stock-gl-interaction-reviewer after P0-b; treasury-reviewer + tenancy-authz-reviewer after T3/T4; frontend-conventions-reviewer after T5; all three plus fiscal-pos-reviewer (for the fiscal-projection system-intent branch) at the end. A lane merges only on explicit MERGE/APPROVE.

## Handback
`docs/handoff/HANDBACK-W-CASH-1-<date>.md`: per task — files, tests run with exact commands and outputs (PG lane), gate verdicts with review paths, deploy variables for the manifest, open residuals. Update `docs/architecture/precision-contract.md` (money storage floor scale 4) as part of P0-b.

## Execution options
1. **Codex Desktop (owner present):** new thread; paste: "Implement docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md (rev 10, DISPATCH-READY) in worktree .worktrees/w-cash-1 per docs/handoff/DISPATCH-W-CASH-1-implementation-2026-09-06.md. TDD red-first, task order P0-a → P0-b → T1..T6, PG lane on port 5455, no pushes, handback file at the end. Stop after each task's tests are green and report."
2. **Autonomous (orchestrator):** Opus implementer agent per task in the same worktree, or detached Codex CLI `--sandbox workspace-write -C .worktrees/w-cash-1`, gated between tasks.
