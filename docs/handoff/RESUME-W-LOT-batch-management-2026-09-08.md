# Resume brief — W-LOT batch management (new orchestrator session, 2026-09-08)

Read first: `docs/handoff/HANDOVER-W-LOT-batch-management-a-to-z-2026-09-05.md` (lane authority, slice table S1–S7c, standing rules), then the owner rulings Q10–Q13 in `docs/handoff/OWNER-RULINGS-parapharmacy-remediation-*.md` (all four defaults ACCEPTED 2026-09-06).

## 1. Where it stands (facts at local dev `d80d421f3`)

No batch-management code has been written. Everything is plan-level and every document is still CHANGES-REQUIRED:

| Document | Rev | Last gate | Verdict | Blockers |
|---|---|---|---|---|
| W-LOT master plan `docs/superpowers/plans/2026-09-06-w-lot-batch-management-execution-plan.md` | — | r4 | CHANGES-REQUIRED | upstream; not the dispatch unit |
| W-LOT-A server lot core `…/2026-09-06-w-lot-a-server-lot-core-execution-plan.md` | 5 | r2 | CHANGES-REQUIRED | upstream; not the dispatch unit |
| W-LOT-B POS lot display/capture `…/2026-09-06-w-lot-b-pos-lot-display-capture-consumption-execution-plan.md` | — | r1 | CHANGES-REQUIRED | depends on A |
| **Slice W-LOT-A-1** permissions + hold `…/2026-09-06-WLOTA-1-permissions-hold-rev-5.md` | 5 | r5 `docs/superpowers/reviews/2026-09-06-w-lot-a-1-slice-codex-gate-r5.md` | CHANGES-REQUIRED | B1 Task 4 scope creep, B2 packets non-dispatchable, B3 Push 5 artifact set, B4 class-0 lock order; M1–M6 |
| **Slice W-LOT-A-1a** permissions / general-manager role / web gating (split out of A-1) `…/2026-09-06-WLOTA-1a-permissions-roles-web-rev-3.md` | 3 | r3 `docs/superpowers/reviews/2026-09-06-w-lot-a-1a-slice-codex-gate-r3.md` | CHANGES-REQUIRED | B-B1 migration regexes reject valid output, B-B2 deployment ignores the manifest's live topology branch (U-1), B-B3 fail-closed checks are prose; M-C protected role still editable in the task contract, M-D `tenants:run` VALUE_NONE normalisation, N-B ownership lists disagree |

**Owner decisions required: none** (gate r3 says so explicitly). Q10 (hold lifecycle) is ruled and applies to A-1.

Pattern to break: eight gate rounds across A-1/A-1a without convergence; the last three rounds loop on *deployment and evidence exactness* (shell regexes, cross-shell helpers, topology branches), not on domain design. The fixes the gate asks for are mechanical and enumerated.

## 2. Recommended sequence for the new session

1. **A-1a fix round 3 → rev 4.** Codex CLI, same thread pattern as `docs/handoff/CODEX-PROMPT-slice-fix-WLOTA-1-round-3-2026-09-06.md` (write `CODEX-PROMPT-slice-fix-WLOTA-1a-round-3-2026-09-08.md`). Instruct: close B-B1/B-B2/B-B3 with the gate's *minimum corrections verbatim* (fixed-string `grep -Fxc`, executable U-1 preflight with both topology branches, `cmp` against committed heredocs, named helper in the host shell), close M-C by making the protected role non-editable in the API/UI task contract, M-D, N-B; change log with CLOSED/REJECTED per item; do not add tasks; do not touch design sections. Then gate r4 (Codex CLI read-only, same prompt shape as r3). If r4 = DISPATCH-READY → owner dispatches implementation via Codex Desktop from rev 4 (feedback rule: long Codex work goes through the owner's desktop from a handover brief, never Claude via CLI).
2. **Implementation of A-1a** (after r4): worktree `apps/erp/.worktrees/w-lot-a-1a`, base local dev; gates at every milestone by `tenancy-authz-reviewer` (permissions/roles/route middleware) + `frontend-conventions-reviewer` (web gating); merge from the main checkout with an absolute `cd`.
3. **A-1 remainder** (hold/recall with Q10 ruling): fix round 4 on rev 5 closing B1–B4/M1–M6 only after A-1a is merged, because A-1a defines the role and permission vocabulary A-1 depends on.
4. **Upstream plans** (W-LOT master, A, B): do not spend gate rounds on them now; they are re-baselined after A-1a/A-1 land (their open findings are mostly baseline staleness and packet completeness that the slices resolve).

## 3. Coordination with the transfers/receiving session (this orchestrator, 2026-09-08)

- Slice **S6** (provenance labelling on `stock_transfer_line_batch_allocations`) and lane **T-2** (destination receipt with partial/discrepancy on transfers, `docs/handoff/BRIEF-parallel-transfers-blind-receiving-2026-09-08.md`) both touch `StockTransferService.php` and the transfer line/allocation tables. Order: **T-2 lands first** (it changes the transfer lifecycle), S6 rebases on it. Neither session edits `StockTransferService.php` without telling the other.
- Slice **S5** (lot-grain counts) touches `inventory_counting_items` and `StockAdjustmentService`; Dhouha's counting lanes (#217/#218/#221 merged 2026-09-08; QA-BUG-08 variance-note lane open on `fix/qa-bug-08-variance-note`) touch the same files. Check `docs/qa/DEV-QA-registry.md` before starting S5.
- Blind receiving (T-3) will reuse the BatchExpiry gating (`hasModule('BatchExpiry')`, ruling R2) for lot capture at receipt; S4 (`/batches/{id}/identify`) is the natural sibling — keep the endpoint vocabulary aligned via `docs/glossary.md`.

## 4. Recipe

- Worktree per lane off local `dev` (`git worktree add`), PG test DB `autoerp_test_<letter>`, never the full suites, ≤2 lanes.
- Codex CLI runs (fix rounds, gates): detached `nohup caffeinate -i codex exec … < /dev/null > $S/<name>.log 2>&1 & echo $! > $S/<name>.pid` (memory `reference_codex_exec_detach_nohup_pattern`); prompt kept in a file; `< /dev/null` mandatory.
- Commit the plan rev + gate report path-scoped: `git commit -m "docs(parapharmacy): …" -- docs/superpowers/plans/<file> docs/superpowers/reviews/<file>`; no origin push from that session (this orchestrator batches promotions; push = staging deploy).
- Every code milestone: adversarial reviewer gate → fix round → re-gate → merge into local dev from the main checkout.

## 5. Owner still owes (unchanged)

#210 A9 ruling; `ci-pin/enforcement-p2-r1` tag move to seed `89b508e1f`; `BUILD_SHA` build arg on the Dokploy web app.
