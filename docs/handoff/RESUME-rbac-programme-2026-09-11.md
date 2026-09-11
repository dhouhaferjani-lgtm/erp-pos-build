# RESUME — Roles & permissions programme (session end 2026-09-11)

> Branch `docs/rbac-audit-2026-09-09` in worktree `.worktrees/rbac-audit`, HEAD `d9cbb8368`. Everything below is committed there and NOT merged to dev. Memory file: `~/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/project_rbac_audit_and_roadmap_2026_09_09.md`.

## Where things stand

| Item | State | Location |
|---|---|---|
| Audit + benchmark + synthesis | DONE, Opus-verified | `docs/superpowers/audits/2026-09-09-roles-permissions/` (01–07) |
| Spec | ACCEPTED rev 9 (Codex gate r9 after 9 rounds), amended to **rev 9.2** (A-1: 0a-4 → 0b-15; A-2: Q-w2-7 key re-spelling) | `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md` |
| S-1 tenant-scoped permission cache | MERGED to local dev `6415062b9` (gates r1/r2), NOT pushed | `docs/superpowers/reviews/2026-09-10-rbac-w0a-s1-gate-r{1,2}.md` |
| Wave 0a plan | DISPATCH-READY rev 5.1 (gate r5) — **IN FLIGHT in owner's Codex Desktop** (`.worktrees/rbac-w0a`, `lane/rbac-w0a`) | `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md`; brief `docs/handoff/CODEX-DISPATCH-RBAC-W0a-2026-09-10.md` |
| Wave 0b plan | DISPATCH-READY rev 6.3 (gate r8); brief WRITTEN, **hold** | `…-rbac-wave-0b.md`; brief `docs/handoff/CODEX-DISPATCH-RBAC-W0b-2026-09-11.md` |
| Wave 1 plan | DISPATCH-READY rev 6.4 (gate r8; 6.4 = editorial); brief NOT yet written | `…-rbac-wave-1.md` |
| Wave 2 plan | DISPATCH-READY rev 7 (gate r7) | `…-rbac-wave-2.md` |
| Wave 3 plan | rev 7, gate r7 = 2 blockers (PermissionRegistry namespace `App\Shared\Domain\Authorization`, two POS artifact filenames) — fix round 7 was interrupted by an auth error, NOTHING partially committed | `…-rbac-wave-3.md` |
| Wave 4 plan | rev 7, gate r7 = 2 blockers + 1 minor (evidence generator must be committed with prerequisites + 58 generated rows; INT/TERM trap must terminate; --check duplicates) — same interrupted round | `…-rbac-wave-4.md` |
| Programme plan | rev 5 | `…-rbac-programme-execution-plan.md` |

## Exact next steps (in order)

1. **Re-run waves 3–4 fix round 7** (Opus subagent, execute-then-write): apply gate r7 register `docs/superpowers/reviews/2026-09-11-rbac-plan-234-codex-gate-r7.md` (wave 3 + wave 4 sections); add a one-line "Gate r7: DISPATCH-READY" note under the wave-2 header; headers → rev 8; then Codex plan gate r8 (prompt template: `docs/handoff/CODEX-PROMPT-plan-gate-RBAC-234-round-7-2026-09-11.md` — regenerate the context paragraph, do not sed-carry stale lines).
2. **Wave 0a handback**: when `.worktrees/rbac-w0a/docs/handoff/HANDBACK-rbac-w0a-2026-09-10.md` appears (Status: review) → `tenancy-authz-reviewer` gate (PG DB `autoerp_test_r`, PHPUnit by path) → fix round if needed → merge `lane/rbac-w0a` into LOCAL dev from the main checkout (`cd /Users/houssamr/Projects/syneriva/apps/erp && git merge --no-ff lane/rbac-w0a`), no push.
3. **Wave 0b handover** only when ALL of `lane/w-lot-a-1a`, `lane/t2-receipt-spine`, `lane/rbac-w0a` are ancestors of dev (`git merge-base --is-ancestor <b> dev`) — then give the owner the 0b brief path.
4. **Wave 1**: after 0b is merged AND deployed to staging with `permissions:ensure-fleet` green → re-pin wave 1 to the final 0b revision, rerun its Phase 0, reconcile `$writeLock` vs `$permissionWriteLock` (R-w1-19) → write `docs/handoff/CODEX-DISPATCH-RBAC-W1-<date>.md` → hand over.
5. **Owner-owed**: promote local dev (S-1 `6415062b9`) to origin/dev when convenient (push = staging deploy); OQ-3 (SoD baseline retirement) before wave 1 Task 13; OQ-1/OQ-2 before wave 2b; OQ-4 after the wave-1 soak; runner-flip checklist (remove `ci.yml:1271` allow-list entry when `SELF_HOSTED_RUNNER_READY` flips).

## Standing method (what worked)
- Fable orchestrates only; Opus writes specs/plans/fix rounds; Sonnet scribes; Codex (`gpt-5.6-sol`, high, `--sandbox read-only -C <worktree>`, detached with nohup + pid file, Monitor on the pid) gates every revision; registers persisted verbatim to `docs/superpowers/reviews/`, prompts to `docs/handoff/`.
- Plans that embed scripts/manifests must be RUN by the writer before committing (rev 4+ of waves 2–4).
- Import passes must cover fragment blocks ("add to file X"), not only namespace-bearing blocks.
- Gate prompts regenerated each round (sed-carried context produced a false "producer changes required" finding once).
- Laptop swap runs 10–13 GB of 11–15: at most two Codex processes at once; Opus agents are remote.
- Commit attribution from 2026-09-11 evening: `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>` only (no Claude-Session line).

## Watches that were armed in the old session (re-arm in the new one)
- Handback file: `ls .worktrees/*/docs/handoff/HANDBACK-rbac-w0a-2026-09-10.md`
- Entry lanes: the three `merge-base --is-ancestor` checks above.
