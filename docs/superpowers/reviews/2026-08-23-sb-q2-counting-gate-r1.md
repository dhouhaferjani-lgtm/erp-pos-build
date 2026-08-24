# Gate record — Session B lane Q-2 (counting finalize), inventory-costing-reviewer r1

Commit `1ec42fc43`, branch `fix/sb-q2-counting-finalize-lock`.

**Verdict: CHANGES-REQUESTED** (H-1 finalize lock + migration solid and red-proven; the flagged
design call ruled: policy right, implementation wrong — the residual window is NOT benign,
proven by three executed probes on the fixed code).

- **BLOCKER-1**: no terminal-status re-assert after the submitCount lock (`:754-768`) — the
  lock WAIT is the window; probe A wrote `count_1_qty = 99.0000` into a FINALIZED counting
  (variance already applied, no outgoing edge, no in-product repair). Fix: 3-line
  Finalized/Cancelled re-assert after the lock + correct the false rationale comment at
  `:759-762`. Keep the tolerant forward-phase no-op exactly as is.
- **BLOCKER-2**: `triggerThirdCount` (`:985-1009`) unlocked, stale-instance status test —
  probe B regressed `finalized → count_3_in_progress`; the subsequent re-finalize double-fires
  `InventoryCountingCompleted`, the listener's applied-markers skip every item, and the new
  unique index makes posting the corrected quantities impossible — silent loss of a stock
  correction. Fix: same lockForUpdate + re-assert.
- **IMPORTANT-3**: `cancel()` (`:1198-1221`) same shape — probe C flipped a FINALIZED counting
  to `cancelled` (stock moved, document says cancelled). Fix: same lock + re-assert.
- **MINOR-5**: double-click 422 renders `"Cannot transition ... finalized to finalized"` with a
  UUID; register a typed render handler above the generic one (bootstrap convention at
  `:907-917`) so FE can distinguish already-finalized.
- **MINOR-6**: index build takes ACCESS EXCLUSIVE (no CONCURRENTLY) + census obligation must
  land on the promotion checklist (parent action at merge).
- **MINOR-7**: commit narrative overstates H-1 — sequential double-finalize was caught by the
  listener markers; genuine double-apply needs two concurrent workers. Correct in fix-round
  commit message.
- **MINOR-8** (not lane-caused): counting regression suite cannot run on PG —
  `ReplayFinalizeTest`/`ZoneScopedCountingTest` fixtures overflow `varchar(20)`
  `counting_number`; the file covering both index-guarded paths has never executed on PG.
  → LEDGER ticket.

Gate verified: red-proofs by revert-probe; migration grain exhaustive (only two writers stamp
`inventory_counting`; batch/FEFO child rows cannot split the grain; reversals pass no
referenceType); listener 23505 rolls back whole attempt (no torn state), scoped GL buffer
cannot leak; typed 422 via existing generic renderer, bootstrap untouched; scope clean.
On faith: the lock's serialization itself (single-threaded tests; two-connection test would
close this — fix round may add it cheaply or record it).
