# Night handover — parapharmacy remediation plan gates (2026-09-05 → 2026-09-06)

Orchestrator: Claude Fable 5.1. All artifacts committed on local `dev` (docs only, unpushed). Codex CLI runs used `gpt-5.6-sol` (`astra` is rejected on a ChatGPT-account Codex), read-only sandbox, high reasoning, detached with pid files; waits via the Monitor tool (Bash background waiters get killed under memory pressure).

## Where the program stands

| Artifact | State | Commit |
|---|---|---|
| Spec v4 | **ACCEPT-FOR-OWNER-REVIEW** (gate r4, 2026-09-05) | `4a6af4912` |
| Owner rulings D1–D9, RD2–RD4, Q1–Q9 | **All ruled** | `d1f628b91`, `e3ca1ba67` |
| Owner questions Q10–Q13 (recall hold lifecycle, shared drawer, typed cash reasons, historical alignment) | **OPEN — benchmarked, awaiting owner** | `c36cc97ca` |
| W-LOT brief → execution plan | rev 3 stalled (12 open) → split | `3f32ffdd8` |
| W-LOT-A (server lot core) | rev 5 filed; gate r2 **regressed** (23 closed / 39 not closed, 6 blockers incl. Q10 encoded again, push 2 not additive-compatible with live writers) → **LOOP STOPPED** | `6bb1519ec`, `98f2747fa` |
| W-LOT-B (POS display/capture/consumption) | rev 4 filed; gate r1 = 13 closed / 34 open / 9 blockers → **PAUSED** | `b3623823d`, `9a069216b` |
| W-CASH (float/drops/custody) | rev 5 filed; gate r6 **regressed** (4 closed / 10 not closed / 5 new blockers) → **LOOP STOPPED** | `0e3e63f12`, `1aa394379` |

Gate ladder per plan, verdict at each round: W-CASH r1 (brief) → r2 (6/5 blockers) → r3 (4) → r4 (4, 28 closed) → r5 (4, 48 closed) → r6 (5, regression). W-LOT r1 → r2 (6) → r3 (9) → r4 (5, 12 open) → split. All reviews under `docs/superpowers/reviews/2026-09-06-w-{lot,lot-a,lot-b,cash}-*-codex-gate-r*.md`.

## What the night showed

1. **The spec-level loop converged; the plan-level loop does not.** Four spec rounds reached ACCEPT. Six plan rounds on 100–130 KB execution plans oscillate: each Codex revision closes a majority of rows and regresses others, and each gate finds fresh blockers in the unchanged parts. The recurring classes are (a) an owner-row (Q10/Q12) being encoded before the ruling, (b) the five-push staging manifest never being executable against the real topology (`tenants:migrate-rolling --force`, env forwarding through compose and entrypoint, captured IDs, web deploy + fingerprint, backups surviving the deploy), (c) mechanical dispatch completeness (columns, CLI signatures, test file/case/assertion/lane).
2. **Real design catches did surface and are captured in the plans**: multi-lot obligation cardinality, recall request needs a stable operation id, evidence must be inside the receipt's SQLite transaction, transfer = one document + two legs, v2 shifts emit no events, v3 has no cash-count producer, custody-interval schema must be Q11-neutral, reversal state machine, claim state persistence.

## Method change for the morning (orchestrator decision, not yet applied)

- Stop revising whole-package plans. Carve **≤6-task slice plans** that a single Codex pass can make dispatch-complete, gated individually and accepted individually:
  - **W-LOT-A slice 1**: L1 permissions on body-less routes + web gates + `general_manager` role + policy-neutral hold (`requested → recalled` only; release/reject withheld behind Q10).
  - **W-LOT-A slice 2**: L3 float retirement (all surfaces, scale-3 storage migration, generated types).
  - **W-LOT-A slice 3**: L2 freeze/correction + L9 identification/split.
  - **W-LOT-A slice 4**: L4 lot-grain counts + L5 provenance + L6 scheduled drift census.
  - **W-LOT-B slice 1**: L7a display only (device cache, session-open refresh, NearExpirySlot, drawer tab gating). Capture and consumption slices after Q10 and after A slices land.
  - **W-CASH slice 1**: C2 per-location custody configuration + T4 transfer document (one document + two legs) — no booking yet.
  - **W-CASH slice 2**: shift cash booking service + v3 adapter (float, drops), policy-neutral on Q11/Q12.
  - Later: v2 adapter/catch-up, W7 store + cash-count producer, variance flag, alignment (Q13).
- Each slice plan reuses its package plan's verified sections (census, benchmark rows, schema contracts) by reference plus the slice's own complete dispatch packets.
- Staging manifest is written **once** as a shared appendix (`docs/superpowers/plans/2026-09-xx-parapharmacy-staging-push-manifest.md`), verified against `docker-compose.staging.yml`, `apps/api/docker/entrypoint.sh`, `RollingTenantMigrationCommand`, and reused by every slice.

## Owner input that unblocks the most

Q10–Q13 rulings (benchmarked defaults in `OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md` §Q10–Q13). Accepting all four defaults is a valid ruling and removes the owner-row leak class from every future gate.

## Environment notes

Swap stayed 9–10 GB of 10 GB with two Codex processes plus the testing session's Docker stack; the harness killed Bash waiters twice. Local `dev` is ~165 commits ahead of `origin/dev`, all docs since `b9a5565aa` from this session, unpushed by design.
