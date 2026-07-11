# ADVERSARIAL GATE REVIEW — Treasury Phase ②, GATE 1 (rc1)

**Scope:** Waves A–B, Tasks 1–10 — schema + enums + accounts, `InstrumentLifecycleService`, `InstrumentRemittanceService`, all GL posting builders. **Diff:** `origin/dev..HEAD` (11 commits, 54 files, +4761/−21).

## Verdict

The Wave A–B implementation is complete, TDD-built, and closely faithful to Rev 2 spec/plan. Every money-path invariant the gate exists to protect holds under inspection and is test-pinned:

- **No `afterCommit` GL posting** — all GL via `postEntryNow`; `afterCommit` only dispatches domain events after durable writes.
- **Every cash movement goes through the Phase-① port with a linked JE** (`InstrumentLifecycleService.php:250-267`, `:453-470`); no balance mutated outside the port.
- **Reconcile-#2 equality holds** (clear=net, dishonor=nominal+fees, bounce-fee=fee+VAT) — pinned with in-test `treasury:reconcile` runs staying unfrozen.
- **No float on money** — all monetary attributes are `decimal:N` (string) casts; bcmath at `getScale($currency)` throughout. I verified the `bounce()` `(string)`-casts (`:479,484,486,497,501,505`) against the model casts — all `decimal:N`, safe.
- **Global lock order (§6)** honored in `clear()` and `bounce()` (instrument → documents id-sorted → GL advisory → repository).
- **JE balance enforced twice** (clearing builder assert + `postEntryNow` refusal at `:2888-2890`).
- **Tolerance reversal is correctly document-scoped** (`payment_id IS NULL` + `whereColumn('tolerance_writeoff','amount')`) with contre-passation and Paid→Posted revert; the imperative `balance_due` recompute formula exactly matches the pgsql trigger.
- Immutability trigger and idempotency partial-unique index are re-runnable and correct.

## Findings (all LOW / informational — none block the gate)

1. **[LOW]** `bounce()` locks the linked payment row (`:334`) *before* the document locks (`:349-354`) — an extra lock outside the canonical §6 order. No live inversion (mitigated by the Wave-D refund guard); recommend folding it into the documented order so later flows preserve it.
2. **[LOW]** Concurrency tests validate lock *acquisition order* (single-connection query-trace) rather than a real two-connection deadlock outcome. Self-disclosed in the progress file and grounded in the repo's existing `GlChainSequenceConcurrencyTest` harness constraint. Recommend a committed-fixture harness later.
3. **[LOW/INFO]** The §5.5 reservation architecture test is intentionally permissive (grep on code-literals only); it does not yet assert that only Phase-② code *posts* to portfolio accounts. Must land before reconcile #4 ships (Gate 4).
4. **[INFO]** REALIGNMENT-LOG chart-of-accounts note deferred (parent-repo file outside the worktree; documented).
5. **[INFO]** Interactive pre-transaction 422 for a missing portfolio account is correctly a Wave-C controller responsibility, not the service's.

Carry-forward items for Wave C: preserve the payment-lock position; delete the controller-level event dispatches per plan MED-8 to avoid audit double-writes; hoist the account resolve to validation.

VERDICT: APPROVE
