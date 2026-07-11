# GATE 2 — Treasury Phase 2 — RC1

Reviewer: `claude-opus-4-8`

## GATE 2 — VERDICT: CHANGES-REQUIRED

I reviewed `git diff phase2-gate-1..HEAD` (Tasks 11–15) against spec Rev 2 §6/§7/§8 and plan Rev 2. One HIGH money-path defect + its masking test gap; everything else is clean.

### HIGH-1 — Deferred-customer portfolio JE still gated on `repository->gl_account_id` (spec §8/T12 deviation, unrecorded)

`PaymentController.php:966-970` (primary JE) and `:1034` (advance JE) still short-circuit on `$repository->gl_account_id`. Spec §8 explicitly required this gate be **decoupled** from `gl_account_id` for the deferred path (the debit is the resolved §3 portfolio account, not the repo account). The task did the *first* half of the T12 fix (`repository_id` now required) but not the second.

**Reachability is real:** `gl_account_id` is `nullable` on `payment_repositories` and validated nullable on create; backfill only logs a warning when no GL account exists. A "checks-in-hand"/safe custody repo — precisely the non-bank repo this flow targets — legitimately has `gl_account_id = NULL`.

**Failure:** deferred cheque payment on a null-`gl_account_id` custody repo → instrument created + invoice allocated/closed by the balance trigger, but the `:966` block is skipped so **neither** the payment JE (Dr 5312 / Cr 411) **nor** the advance JE posts, and the movement is skipped anyway. Invoice shows paid while 411 and 5312 never move — silent GL↔subledger divergence, caught only by alert-only reconcile #4.

**Fix:** for `$isDeferredCustomer`, post primary/advance JEs on `totalAllocatedForGL > 0` / `excessAmount > 0` independent of `gl_account_id` (the account is already resolved at `:551-560`).

### MED-1 — Test gap masking HIGH-1

`DeferredTenderPaymentTest.php:69-75` only uses a ledgered bank repo, so the `:968` short-circuit is never exercised. Add a TDD pin with a `gl_account_id => null` custody repo.

### LOW-1 / LOW-2 — non-blocking

Fee regex is fixed at scale-3 across currencies; advance-JE mode flipped to synchronous on the immediate path too (correct/necessary, recorded in the progress file — flagged for the record only).

### Verified clean

Lock order (instrument → documents → GL advisory, no inversion); no cash at receipt / no movement outside the port; debit-swap honored with credit lines untouched; supplier direction Phase-① verbatim + outbound registration; all four side-door 422s; refund guards before any write; no afterCommit GL; permissions + 4-tuple routes; company scoping + UUID guards + pagination; `'TND'` literal sweep complete; FE single-call with nested `instrument`.

---

Two notes:

- **Escalation:** per handoff §3 rule 3(a), this CHANGES-REQUIRED with a HIGH money-path finding should be re-run under `--model claude-fable-5` — that's the runner's step, not mine.
- The reviewer could not persist the artifact because its Write request was denied; this file preserves the completed returned review.

VERDICT: CHANGES-REQUIRED
