# Track Restart Guide — 2026-07-07 (post-crash, pre-reboot)

Every track below survived the crash; each has a durable handover and a copy-paste kickoff prompt
for a fresh Claude session. Companion doc: `docs/sessions/CRASH-RECOVERY-INVENTORY-2026-07-07.md`
(post-reboot container checklist).

## Track A — Scan-to-Document S3/S4 (P0, board T-0014) — ACTIVE, orchestrated here
- **State:** S1 + S1-fixes + contracts-refactor committed & pushed (`380997e8a` on
  `origin/feat/scan-to-document`). S2 (erp-ml endpoint) merged to parent-repo main (PR #32).
- **Unblock:** owner pastes `docs/sessions/CODEX-TASK-scan-s3.md` (worktree `apps/erp.scan-to-doc`)
  into Codex desktop — Task 7-pre FIRST. Orchestrator session verifies each wave, then hands S4.
- **Kickoff prompt (if orchestrator session is lost):**
  > Read memory `project_scan_to_document_spec_b`. Worktree `apps/erp.scan-to-doc`, branch
  > `feat/scan-to-document`. Verify the latest Codex desktop wave (S3: committers, Tasks 7-pre/7/8)
  > against `docs/sessions/CODEX-TASK-scan-s3.md` + TASK-LOG, run tests BY PATH + PHPStan L8 + pint
  > fresh, commit, then run inventory-costing/treasury/tenancy reviewer gates. Then hand S4.

## Track B — Live inventory counting → owner test → promote to dev
- **State:** fully shipped on `origin/feat/live-inventory-counting` `cdfbfb6be`; all gates cleared.
- **Unblock:** owner tests per `docs/handoff/MORNING-BRIEF-live-counting.md` (worktree
  `apps/erp.live-counting`) — QUEUE WORKER REQUIRED — then promote to dev (local-dev-first, ff-only).
- **Kickoff prompt:**
  > Read memory `project_live_inventory_counting` and `docs/handoff/MORNING-BRIEF-live-counting.md`.
  > Owner has tested/approved. Merge `feat/live-inventory-counting` into local dev, verify
  > (tests by path + Playwright critical path), promote to origin/dev as clean fast-forward,
  > then run local deploy checklist (tenants:migrate, perm reseed, permission:cache-reset).

## Track C — Location placement hierarchy Phase 1 (GATED on Track B)
- **State:** spec+review committed `1d84090bd`; Phase-1 backend plan committed `569dfee86`
  (`apps/erp.location-hierarchy`, `feat/location-placement-hierarchy`, pushed).
- **Unblock:** Track B merged to dev → rebase this branch onto post-merge dev → execute plan.
- **Kickoff prompt:**
  > Worktree `apps/erp.location-hierarchy`. Verify `location_zones`/`product_zone_assignments`
  > exist on dev (live-counting merged) — STOP if not. Rebase onto origin/dev, then execute
  > `docs/superpowers/plans/2026-07-07-location-placement-hierarchy-phase1-backend.md` via
  > superpowers:subagent-driven-development.

## Track D — POS prepaid drawdown (GATED on owner §9 answers)
- **State:** spec Rev 2 on `origin/feat/pos-prepaid-drawdown` `d13add2d0`→`d13ad...` (worktree
  `apps/erp.prepaid-drawdown`); dual reviews reconciled; deposit-pipeline preludes on dev.
- **Unblock:** owner answers spec §9 (6 decision points, lines 125-132 of
  `docs/superpowers/specs/2026-07-07-pos-prepaid-drawdown-design.md`). All have recommendations —
  "all recommended" is a valid answer.
- **Kickoff prompt:**
  > Read memory `project_pos_prepaid_drawdown` + spec §9 answers (in this doc or from owner).
  > Write the implementation plan (Wave A server → B device → C credit-line per spec §10),
  > adversarial-review it, then dispatch Codex waves. Don't forget §10.2 tsy-M5 actor-null
  > advance-JE fix lands BEFORE drawdown ships.

## Track E — Loyalty completion (T-0002; T-0005 CLOSED)
- **State:** T-0005 double-earn was ALREADY MERGED to dev (`dce626340`+`c99fa762a`) — board updated
  2026-07-07. Remaining: device-side POS earn verify (owner, Tauri device) + PL roadmap
  (`project_loyalty_launch_prs`): reward_type still never read by earn math; rule-edit modal strips
  rich condition keys.
- **Kickoff prompt:**
  > Read memory `project_loyalty_launch_prs`. T-0005 merged. Verify enrollment+earn on dev with a
  > device sale (or Playwright approximation), then work PL roadmap items in order.

## Track F — Small unblocked chores (any session, low context)
- `fix/auth-401-loop` `ffaf80bb4`: committed+pushed, needs review gate + merge to dev.
- T-0003: rebase `feat/owner-dashboard-demo` (`414db3771`, behind ~273) onto dev + PR.
- T-0004: rebase `feat/db-per-tenant-deploy` onto dev + PR.
- T-0012: inspect PG-suite ReturnNoteService diff (`triage/pg-suite-98-failures`).

## Track G — VPS dark-factory (T-0013)
- **Unblock:** owner completes VPS env + first SUPERVISED run. Codex ChatGPT quota was exhausted
  2026-07-06 but RECOVERED late evening; fallback = Claude agents on VPS.

## Owner-only actions (no session needed)
1. §9 answers (Track D) — can be done on paper during reboot.
2. Live-counting test (Track B) — needs the machine + queue worker.
3. S3 prompt paste into Codex desktop (Track A).
4. Tauri-device visual verifies: T-0001 add-customer + loyalty earn.

## Addendum 2026-07-07 pm — Codex desktop misfire on S3
Desktop session opened in the MAIN apps/erp repo couldn't find the S3 brief (docs/sessions/ is
gitignored — brief lives only in the erp.scan-to-doc worktree) and improvised an unrelated POS
local-cache scanner re-review, mutating the historical May review file (restored via git checkout;
its BLOCK verdict — sync-envelope detector false negative when handlers read envelope.tenant_id
without a real auth-context check — preserved at
docs/sessions/2026-07-07-pos-scanner-rerun-misfire-BLOCK.md; ticket later, tooling-only).
RULE: Codex desktop prompts must pin absolute paths AND instruct STOP-if-brief-missing; open the
desktop project in the worktree, not the main repo.
