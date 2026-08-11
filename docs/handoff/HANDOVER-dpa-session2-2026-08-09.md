# HANDOVER — DPA remediation orchestrator, session 2 (2026-08-09)

**For:** the successor dedicated orchestrator session. You inherit the document-per-action
remediation track (original mandate:
`docs/handoff/HANDOVER-document-per-action-remediation-2026-08-08.md`). Session 1
executed Waves 0–2 completely and set up Wave 3. **Read the ledger FIRST — it is the
authoritative state:**
`.superpowers/sdd/HANDOVER-document-per-action-remediation-2026-08-08/progress.md`
(same directory = the workspace: every brief, report, gate review, plan, and fact sheet).

## 1. Standing owner directives (2026-08-09, binding)
- **Codex SOL 5.6 HIGH effort is the workhorse**: dispatch implementation fixes AND
  adversarial reviews to Codex; Opus subagents implement when Codex is unavailable;
  the in-repo reviewer agents (fiscal-pos / treasury / inventory-costing /
  frontend-conventions / imports / tenancy-authz) remain the merge gates.
- Greenfield: NO live tenants; first tenant onboarding imminent. Nothing to migrate,
  but auto-deploy discipline binds (origin/dev push runs tenants:migrate).
- No hardcoded data anywhere; account numbers/names seeded per country; new tenants get
  a clean setup. Wave 3 is confirmed next.
- Program standing rules (earned this session, apply to every lane): revert-replay every
  fix commit; real PG run before any green claim (local 5432; **Docker PG 5433 is broken
  — pg_filenode.map I/O error, needs a machine check**); tests drive the mutation to the
  posted ledger; never git stash (repo-global across worktrees).

## 2. 🛑 PROMOTION HOLD
Local `dev` (~85 ahead of `origin/dev`, clean ff) must NOT be pushed until the
advance-reversal lane's cash-branch fix (task A8/A9 family) lands — V4's mixed-payment
defect (AR overstated by the advance portion) is CONFIRMED wrong money, present on local
dev only; `origin/dev` @ `6bf56d102` verified unaffected. See `plan-advance-reversal.md`
§9. When the hold lifts, promotion is the MAIN orchestrator session's authority; the
deploy checklist is in `owner-decisions-2026-08-09.md` §F.

## 3. Completed and merged to local dev (all adversarially gated)
V1, S0, V2, V3, V4(+e2e), V6, V7, V9, V10, G3(ships DISABLED), CF cancel-flow, plus the
Wave-0 research (R1/R2/R3) and lane-separation research (CONFIRM: invoices=money lane;
allow+detect, not refuse). V8 is APPROVED but PARKED unmerged behind Wave 3 (c1-bis
merge-order; F-9 must land before its CN-posting wiring). Per-lane detail: ledger +
`owner-decisions-2026-08-09.md`.

## 4. In flight at handover (check these FIRST — outputs land in the workspace)
1. **SEEDS lane** (`fix/dpa-seeder-gaps-accounting`, commits `2bee58c48..c05812444`):
   fiscal-pos gate running → `gate-seeds-review.md`. On APPROVE (+fix rounds as needed):
   merge to local dev. Covers items A/B/D/E/F of the NEW register (§6).
2. **H-3 lane: DONE + MERGED to local dev** (`7d423d162`; gate APPROVED after 1 round;
   the C-1 cash-register-preference ruling implemented with a mutation-tested pin).
   Successor owes: file the C-7 fixture ticket (ShiftCashVarianceAdjustmentTest:413
   forceFill vs pgsql trigger — G3-owned) and carry N-1 into the deploy note
   (fallback shift Bank→Cash GL purpose; greenfield ⇒ no affected tenants).
3. **Advance-reversal plan: GATE CLOSED** (revision 2 adopted all prescriptions with
   independent re-verification; adjudication precedent CF/V7). NEXT: dispatch Path A
   IMPLEMENTATION (Codex SOL 5.6 HIGH per directive; treasury code gate; A1a + A1g are
   the mandatory first red-test commits; 12 tasks A1–A12). Path B severed/deferred
   (F1–F9, expert EQ-1-gated). ⚠️ Shape Z (A1g) is RED ON DEV TODAY — a live
   pre-existing wrong-shape reversal path — which is a second independent reason the
   §2 promotion hold must not be lifted early. Plan: `plan-advance-reversal.md`.
4. **Wave-3 plan is WRITTEN** (`plan-wave3.md`, 33 tasks, 7 sub-waves 3A–3G) but its
   dual plan gate (inventory-costing + fiscal-pos) is NOT yet dispatched — that is your
   first new dispatch. Its return highlighted: D-6 per-movement JE granularity (T16a
   volume probe before gate ruling), D-13 single-commit cutover, D-8 posting-date
   convention, and three re-verification findings (WAC scale() bare getScale in workers;
   two lock orders; DN→invoice-converted invoices bypass both delivery gates today —
   detector D-c surfaces them).
   If any in-flight agent above died with session 1, everything needed to re-dispatch is
   in the workspace files; do NOT re-run completed work (check reports/gates first).

## 5. Ready for the OWNER to dispatch to Codex (or you dispatch it)
`docs/handoff/CODEX-DISPATCH-accounting-gaps-CGHI-2026-08-09.md` — items C, G, H, I of
the live-accounting-gaps register
(`HANDOVER-live-accounting-gaps-country-defaults-review-2026-08-09.md`), with A/B/D/E/F
explicitly marked as landed-in-SEEDS and J owned by Wave-3 T24. Quality gates defined
inside. This unblocks the other session's accountant super-admin panel work.

## 6. Owner decision state
`owner-decisions-2026-08-09.md` + ledger §OWNER RULINGS: rulings received for A1 (whole-
drawer counting → G3 pre-enable basis work defined), B1 (allow+detect — research
CONFIRMED), B3 (per-line GR-IR, proceeding), #5 (Wave 3 next), #6 (seed accounts now),
#7 (build right reversal paths), #8 (clean setup). Open owner items: B2 posting-date
explicit confirm (Wave-3 plan defaults it, OQ-13), Wave-3 OQ-3 (JE granularity after
T16a probe), OQ-2 (shrinkage/gain codes), OQ-10 (is 3G in-wave), CF policy set Q-A..Q-I
(Q-H auto-closed-periods is the sharp one), G3 count-semantics riders, H-3's C-1 ruling
(pending treasury gate). **EXPERT RULINGS RECEIVED 2026-08-10** (record:
`docs/superpowers/tickets/2026-08-10-expert-rulings-deferred-revenue-vat-issuance.md`):
deferred-revenue-on-invoice = YES (NCT 03; pre-delivery invoice = liability 472/419) and
TN VAT-on-issuance = YES (Code TVA Art. 18) → **B1 flips from ALLOW+DETECT to
GUIDED-REQUIRE + DETECT**: Wave-3 sub-wave 3E (T23–T25) must be revised AT ITS PLAN GATE
— definitive goods invoices may not precede delivery; "create & confirm DN now" becomes
the required path; prepayments via the advance machinery; proforma outside the fiscal
chain. **Owner rider: the policy is a per-country SEEDED SETTING (never hardcoded) —
TN seeds require_delivery_first; an `allow` seed value is refused until 472
deferred-revenue machinery exists (precondition recorded in the rulings ticket).** Remaining EXPERT queue: EQ-1(+Q5b/Q6) cleared-instrument reversal (SEND NOW —
long turnaround, gates only Path B), FR analog of the two new rulings (PCG 487 /
délivrance-vs-débits — before FR launch), and the SEEDS gate's 8-item account-
confirmation list (gate-seeds-review.md §expert: 603-vs-6037 + liasse-607, 628-vs-65x,
7097, TN 606/625x codes surviving H-1 renumbering, 409/419, 418, FR 666/766, FR
supplier-invoice fallback treatment).

## 7. Routed to the MAIN session (do not absorb)
H-1 (TN chart is French — 6654 nowhere in repo), H-2 (TN rounding dust → 4375) — its
CN-stamp/timbre lanes; each owes deletion of a named exemption in SEEDS' parity test.
T13 `fiscal:verify-chains` false-tamper P1 → launch fiscal-verifier gate. The 42-entry
hardcoded-defaults register (`audit-hardcoded-defaults-register.md`) → super-admin
template-library program.

## 8. Worktree map (all off local dev; vendor COPIED not symlinked)
Active: `../erp.dpa-seeds`, `../erp.dpa-h3` (lanes in gate), `../erp.dpa-v8` (parked).
Mergeable-when-ruled: none other. Stale/deletable after confirming merged: `../erp.dpa-v1,
-s0, -v2, -v3, -v4, -v6, -v7, -v9, -v10, -cf, -g3` (all merged; keep until promotion in
case a gate reopens). Wave-3 lanes: create fresh off dev when dispatching.

## 9. Known environment issues
Docker PG 5433 broken (use local 5432); central DB `iziposcentral` was found wiped once
and re-provisioned (tenant `019fe276-…`); e2e MTP-TRE-06 fails on re-seeded envs
(hardcoded unit UUID, ticketed); infra stream-stalls recur — instruct implementers to
commit early/write incrementally, and resume dead agents with a short "continue" note.
