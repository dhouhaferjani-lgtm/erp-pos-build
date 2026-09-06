# Handback — parallel hardening session (autonomous window, 2026-09-07)

Session started from the main checkout on local `dev` (`db8fb1471` at start). Rules followed: no origin pushes, path-scoped commits, worktree per lane, ≤2 launches, launch pause above 9 GB swap.

## Status block 1 — session open + prep (2026-09-07, ~first hour)

**Machine:** swap 10.3–10.6 GB used at every check (7 `claude` sessions, 2 other-session Codex read-only gates, a locaplex `phpunit` run, Zed, Chrome). Above the 9 GB ceiling → **no agent or Codex process launched yet**; a background poll waits for swap < 9 GB before the plan gate.

**Previous testing session:** closed cleanly — local `dev` tip `189fe7d8a` matches its end-of-day handback; `git status` clean apart from three pre-existing untracked docs. Nothing half-merged.

**Item 1 — precision widening lane (`precision-4`):**
- Brief rev 1 committed `51dd84878`: `docs/superpowers/plans/2026-09-07-precision-widening-gl-repository-scale-4.md`.
- **Premise correction (evidence):** the four columns are already `decimal(15,3)` — widened by `2026_03_11_200000_widen_monetary_columns_to_scale_3.php` (`:27-30`, `:113-116`) and confirmed live on local tenant DB `tenant019fbe86-…` via `information_schema` (tuples in the brief §0). The ticket's "(15,2), no later widening" claim is false; W-CASH-1 gate r3 had already noted it. The lane widens 3→4 on the A1 follow-up ruling.
- Worktree `.worktrees/precision-4` on `lane/precision-4` (off `dev`). Codex gate prompt staged (scratchpad `gate-precision-4-prompt.md`). Gate verdict: **pending** (swap).
- Engineering assumption A (Eloquent casts stay `decimal:3`; 153 test assertions use 3-decimal literals; no writer can produce a 4th decimal with today's country presets) → recorded as **owner row A10** for veto.
- Local census snapshot: 49 numeric columns at scale 2 (percent/rate/geometry/hours), 143 at scale 3 (money + loyalty points), 95 at scale 4 — feeds the follow-up ticket the lane writes.

**Item 2 — Dhouha PRs:** #208, #209, #211, #212, #213, #214, #215, #216 were already merged to local `dev` on 2026-09-05 (request-hygiene session; gate files `docs/superpowers/reviews/2026-09-05-dhouha-pr-2xx-*`). **#210 is owner-gated** (operator role loses supplier-invoice posting; gate r1 CHANGES) → recorded as **owner row A9**. No autonomous action possible.

**Commits this block (local `dev`, unpushed):** `51dd84878` brief, `213b0bcc3` owner rows A9/A10.

**Needs the owner:** A9 (#210), A10 (casts), then promotion of the precision-4 push per the brief §T5 (host backup, non-additive push, compatibility-mode command).

## Status block 2 — STOP CONDITION: swap exhaustion (2026-09-07)

- Swap 10.7 GB used / 0.6 GB free; `vm_stat` pageouts 1.3 M; the harness killed this session's own swap-wait poll "because the system is running low on memory". Other sessions still run a `phpunit` leg (`tests/Feature/Treasury/PaymentRefundRefusalTest`) and a Codex read-only gate.
- Per the autonomous block: **no launches** (no Codex plan gate, no Opus implementer, no PG container). The precision-4 lane is fully briefed and staged; nothing implemented, nothing gated, nothing merged beyond docs.
- **Resume recipe (any session, after swap < 9 GB or a reboot):**
  1. `sysctl vm.swapusage` < 9 GB.
  2. Plan gate: `codex exec --sandbox read-only -C /Users/houssamr/Projects/syneriva/apps/erp -m gpt-5.6-sol -c model_reasoning_effort=high -o docs/superpowers/reviews/2026-09-07-precision-4-brief-codex-gate-r1.md "$(cat <scratchpad>/gate-precision-4-prompt.md)"` launched detached (nohup + pid file). Prompt text is also reproducible from the brief §3 + the seven gate questions (premise, casts, migration/lock/trigger/marker, tests/fixtures, census shape + classification, deployment vs manifest, scope vs handover item 1).
  3. On ACCEPT: Opus implementer agent in `.worktrees/precision-4` (branch `lane/precision-4`), TDD red-first, tasks T1–T6 of the brief, private PG on port 5453 (`pgh-test-pg-p4`, DB `autoerp_test_p`), handback `docs/handoff/HANDBACK-precision-4-2026-09-07.md`.
  4. Code gate: `treasury-reviewer` + `stock-gl-interaction-reviewer` → merge to local `dev` from the main checkout with an absolute `cd` → tell the parapharmacy orchestrator session (W-CASH-1 P0 depends on it) → ledger.
- Commits this session so far (local `dev`, unpushed): `51dd84878` brief, `213b0bcc3` owner rows A9/A10, `3641298ad` handback block 1.
- Queue beyond item 1 untouched (owner cap: "do not start more than that"); #210 owner-gated (A9).

## Status block 3 — owner back, rulings applied, lanes running (2026-09-07, ~2 h mark)

**Owner rulings (chat):** A9 = option (a), secure default, permission must stay grantable per role/user; A10 = casts stay `decimal:3` provisionally, benchmark first; queue CONTINUE until 2026-09-08 morning; greenfield.
**Supersession:** item 1 precision widening now lives inside W-CASH-1 (P0-a/P0-b, orchestrator session `erp-ea`); `precision-4` parked (`c65af2735`, brief kept as input). Orchestrator confirmed it cites the brief, keeps casts at 3, adds the `fourth_decimal_present` detector (rev 13).
**Benchmark note (A10 input):** `docs/superpowers/reviews/2026-09-07-benchmark-money-precision-4-decimals-casts.md` (`0c7bd49fb`) — recommended (A) casts stay 3 conditional on the census detector; NC 01 §62 verbatim; no 4-dp preset exists; 192 strict assertions would break under casts→4. Owner row A10 updated.
**Running now (2 agents, swap 9.3 GB → no further launches until < 9 GB):** PR #210 fix round 1 (Opus, `.worktrees/pr-210`, 21 files in progress); build-fingerprint lane (Opus, `.worktrees/build-fingerprint`, 8 files in progress).
**Briefs committed, dispatch-ready:** item 6 sales-extra `d636d8328`; item 4 q9-overpay `16ac29aad`; item 5 build-fingerprint `965931a32` (running).
**Found already done:** item 9 K-6/K-7 merged to local dev on 2026-09-02 (`8f8eca958`, `71b1eae26`). Item 2: all Dhouha PRs merged except #210 (in fix round).
**Item 12 prep:** `docs/superpowers/reviews/2026-09-07-rh-phase-a-ci-reconciliation-prep.md` (`d90fa8878`): candidate run fixes 8 job families red on origin/dev, keeps 5 red; 6 new PHPUnit failures vs baseline (TreasuryAccountChargeBridgeTest ×2, ReceiptReturnServiceTest ×2, UnitsInvariantTest, MediaUrlResolverTest); vitest 3 files + ESLint detail still to extract.
**Item 3 K-1:** brief lives in gitignored `docs/sessions/session-K-otospex-money-2026-08-30/LANE-K1-…-BRIEF.md` (copy into the worktree at dispatch); touches the account-charge arm that is also red in CI — dispatch after #210/build-fingerprint gates.
**Items 7/10/11:** doc lanes queued (Phase 2 brief: remove backfill arm per A6; impersonation runbook from `config/support_access.php` + SupportAccess routes; P0 roll-up `docs/handoff/PLAN-p0-fix-lanes-pre-production-2026-08-05.md` L1–L5 vs merged lanes).
**Commits this block (local dev, unpushed):** `c65af2735`, `965931a32`, `d636d8328`, `16ac29aad`, `0c7bd49fb`, `d90fa8878`.
**Restart:** not safe while the two agents run; will be announced.

## Status block 4 — second swap stop condition; lanes committed, gates owed (2026-09-07 evening)

**Stop condition:** swap 9.2–9.5 GB with none of this session's agents running (the orchestrator session runs two Codex read-only gates); the harness killed this session's swap-wait poll for low memory a second time → no launches, holding. **Safe to restart the laptop from this session's side** (everything committed, no agents); a restart kills the orchestrator's two Codex gates, which it must relaunch.

**Merged to local dev this session:** build-fingerprint `45d39347f` (gate r1 MERGE-WITH-FIXES → fix round → r2 MERGE; `docs/superpowers/reviews/2026-09-07-build-fingerprint-gate-r1.md`). Owed after reboot: one real `docker build -f apps/web/Dockerfile --build-arg BUILD_SHA=$(git rev-parse HEAD) .` (skipped for swap); U-9 = Dokploy/compose must pass `BUILD_SHA`.

**Committed on branches, gates owed (resume here):**
1. **PR #210** — `gate/pr-210` tip `4d7dccbd3` (fix rounds 1+2). Gate r1 CHANGES (2026-09-05), r2 MERGE-WITH-FIXES (`docs/superpowers/reviews/2026-09-07-dhouha-pr-210-gate-r2.md`, committed `c708ebc42`). **Owed: targeted re-gate r3** by `tenancy-authz-reviewer` on the r2 findings (FE real permissions on the three supplier-invoice routes + sidebar, `Both`-arm test, replay re-authorization at `PaymentController.php:389,1512`, `Str::isUuid` guard in `DocumentAttachmentController::resolveDocument`), then merge into local dev from the main checkout. Handback: `.worktrees/pr-210/docs/superpowers/reviews/2026-09-07-dhouha-pr-210-fix-round-1-handback.md` (has the Fix round 2 section). Deploy row added to `PROMOTION-CHECKLIST-2026-08-26.md` §3 (roles seeder + `permission:cache-reset`; new permissions `supplier-invoices.manage`, `payments.pay-supplier`; `purchase-orders.confirm` reused for PO revert). Owner-visible defaults: operator AND accountant denied PO revert by default (grantable).
2. **sales-extra (item 6)** — `lane/sales-extra` tip `51940810f`, 5 commits, conflict-free vs dev. **Owed: `tenancy-authz-reviewer` + `frontend-conventions-reviewer` gates**, then merge. Handback `.worktrees/sales-extra/docs/handoff/HANDBACK-sales-extra-2026-09-07.md` (route census 87 Document routes 44 gated / 43 left; 77 Treasury routes left with reasons; residuals: `documents.pdf.*`/`email.*`/`smart-payment.*` ungated, low exposure). Owner row **A11** (no facture draft on POS account charge when Sales OFF) awaits a ruling.

**Briefs ready to dispatch (not started):** q9-overpay `docs/superpowers/plans/2026-09-07-q9-over-payment-advance-gate.md`; K-1 (brief in gitignored `docs/sessions/session-K-otospex-money-2026-08-30/LANE-K1-…-BRIEF.md`, copy into the worktree); doc lanes 7 (Session H Phase 2 brief: strip the backfill arm per A6, add the demo-seeder fix task), 10 (impersonation runbook from `config/support_access.php` + SupportAccess routes; B3 ruled allow+log), 11 (P0 roll-up `PLAN-p0-fix-lanes-pre-production-2026-08-05.md` L1–L5 vs merged lanes).

**Owner rows added today:** A9 (#210, ruled (a) in chat), A10 (casts, benchmark attached, provisional (A)), A11 (facture draft with Sales OFF).

**Commits this block (local dev, unpushed):** `a1c7e271e`, `45d39347f` (merge), `c708ebc42`, `44868d82c`, plus this handback.
