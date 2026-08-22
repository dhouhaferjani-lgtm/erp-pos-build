# DPA V8 — supplier goods-return note residuals

**Severity:** MEDIUM — none of these is reachable today (the lane has zero
callers in `app/` or `routes/`); all of them become live the moment F-3 wires the
controller.
**Owner:** Inventory costing + stock-GL.
**Opened:** 2026-08-22, from the DPA V8 round-2 gate.

One ticket for everything V8 deliberately left open, so the next lane has a
single place to look instead of four docblocks.

---

## R-1 — Ordinary lines carry a units-vs-GL residual of `q × (invoice price − WAC)`

The last remaining GL/sub-ledger gap in this lane. For an ORDINARY (paid) return
line the GL plug is priced at the INVOICE, while the units relieve inventory at
the **WAC**. When the two differ — any price variance, any later cheaper receipt
— GL and the sub-ledger move by different amounts and the difference is never
booked anywhere.

Bonus lines no longer have this problem: round 2 of the stock-GL gate (P1-1)
added the compensating `Dr Inventory / Cr PurchaseExpenses` pair at
`wac_undilution_applied`, so their halves reconcile. Ordinary lines were out of
that fix's scope.

Note this is NOT the same shape as the bonus gap — it is a purchase price
variance, and the accounting answer is probably a PPV account rather than a
compensating pair. That is the decision this ticket wants, not a patch.

## R-2 — `C` is one receipt's price, not the paid blend (the C-11 residual, owner F-9)

`unit_cost_ceiling` is the per-unit price paid on the receipt that brought the
returned units in. It stands in for the blended cost of the surviving PAID units,
and those coincide **only when every paid receipt of the product carried the same
price**. On a multi-price history it misses in both directions:

- `C` **above** the blend → the cap does not bind and the pre-fix over-credit
  survives (probe: 100 @ 1.000000 then 1 @ 500.000000 (+10 free), sell 100,
  return the 10 free against `C = 500` → WAC' = `59.459455` against a paid blend
  of `5.940594`).
- `C` **below** the blend → headroom is negative and the un-dilution no-ops
  (probe: 10 @ 10.000000 then 10 @ 2.000000 (+1 free), return the free unit
  against `C = 2` → WAC' stays `5.714285` against a paid blend of `6.000000`).

The real fix is to derive `C` from the paid-cost basis, which needs a basis the
perpetual ledger does not keep — the F-9 periodic-vs-perpetual research lane's
subject.

### The two pins exist to make this fail loudly — do not "fix" them by editing the expectation

`SupplierGoodsReturnNoteTest::test_a_ceiling_above_the_paid_blend_does_not_bind_the_undilution`
and `::test_a_ceiling_below_the_paid_blend_disables_the_undilution` assert the
figures above — i.e. **the current, economically wrong behaviour**, on purpose.
They are designed to go RED the day F-9 lands a real cost basis. When that
happens the correct response is to update them to the new, correct figures as
part of the F-9 change, not to relax them.

Both are named in the `backend-pgsql` job's `--filter` allowlist so they actually
execute while `feature-lane-inventory` is parked. If that allowlist entry is
removed before the lane's gate is flipped, the tripwire is disarmed and this
ticket loses its enforcement.

Related: `wac_undilution_forgone` is measured against `C`, so on a multi-price
history it UNDER-REPORTS (the first probe records `forgone 0.000000` while the
cost at rest is 10× wrong). No consumer may read a zero `forgone` as proof the
correction was economically complete until R-2 is closed.

## R-3 — `ownedQuantityAfterExit` is a tighter basis than `recordCostAdjustment`'s divisor

`ownedQuantityAfterExit()` sums **on-hand only**;
`WeightedAverageCostService::companyOwnedQuantity()` sums on-hand **plus
in-transit**. They agree only when nothing is in transit for the product.

Consequence: with in-transit stock, `applied` is spread over a larger divisor
than the headroom was computed against, so the WAC lands strictly **below** the
ceiling instead of exactly on it.

Deliberately left as-is — the mismatch is one-directional and can only ever
under-restore, never push the cost at rest above the price paid. Widening the
basis would make the bound depend on stock the un-dilution cannot reach and would
be the first way this code could inflate a WAC. Recorded so the asymmetry is a
known choice rather than a latent surprise.

## R-6 — GL books the stamped `applied`; the sub-ledger realises a truncated WAC

The compensating pair (P1-1) is booked at `wac_undilution_applied` exactly as
stamped. The sub-ledger realises that value by raising the per-unit WAC, which is
stored at **scale 6**. So what the sub-ledger actually gains is
`round6(newWAC) × totalOwned`, and that can differ from the booked `applied` by up
to:

```
totalOwned × 1e-6
```

≈ **0.02 at 20 000 units** — i.e. it only reaches GL scale (3 dp) in the tens of
thousands of units. Below that it rounds away entirely.

Same family as R-3 (both are "the un-dilution's arithmetic and its realisation
disagree in the last place"), but a different mechanism: R-3 is a **divisor**
mismatch, this is a **rounding** one. Unlike R-3 it is **not** one-directional —
truncation can leave GL either side of the sub-ledger.

Not fixed here because the honest fix is to book what was realised rather than
what was intended, i.e. have `recordCostAdjustment` return its realised delta and
book that. That is a change to the costing seam's contract and belongs with
whoever owns the seam, not to a returns lane.

The reconciliation test
(`SupplierCreditNoteGlTest::test_the_bonus_return_gl_inventory_movement_reconciles_to_the_sub_ledger`)
asserts EXACT equality and is correct to: its fixture holds 20 units, so the bound
is 0.00002 and vanishes at scale 3. Its docblock states the tolerance explicitly
so the exactness is not read as a general guarantee. A large-quantity arm would
demonstrate the bound empirically and is the natural first step if this is picked
up.

## R-4 — Nullable actor on the note seam

`createDraft(actorId: ?string)` / `confirm(note, ?string $actorId)` accept null,
and `confirmed_by` is nullable, because the only production caller
(`SupplierCreditNotePostingService::post()`) is itself reachable from contexts
with no authenticated user — queued/system posting. So a confirmed note can carry
no actor.

That is acceptable while the lane is system-driven and unreachable from HTTP. It
stops being acceptable when F-3 wires the controller: a user-initiated goods
return with a null `confirmed_by` is an audit gap. F-3 should require a non-null
actor on the HTTP path specifically, rather than tightening the seam's signature
(which would break the system path).

## R-5 — Batch-tracked products are refused, not supported

Refused via `BatchTrackedReturnUnsupportedException`, on **two** arms: batch stock
rows existing (the data arm — the load-bearing one, since
`products.requires_batch_tracking` is a mutable setting while lots on hand are a
fact), and the flag itself as a belt for the configured-but-empty product.

Supporting them needs lot selection on the return, which needs a UI decision
about who picks the lot. Out of scope for V8 by design.

---

## Not in this ticket

- **c1-bis is ANSWERED and closed.** Several V8 docblocks used to defer the bonus
  GL question to it. Round 2 landed that GL half, and the pointers were corrected
  to name F-9 (for the cost basis) or this ticket (for R-1). If you find another
  "c1-bis will handle it" comment, it is stale — repoint it, do not act on it.
- **The D-e detector exclusion.** Round 2 asserted this was "not a residual"
  because the carve-out was conditional on a backing credit note. That
  foreclosure was wrong and is withdrawn: testing `supplier_credit_note_id IS
  NOT NULL` proved only that the COLUMN was populated, and the column has no
  foreign key, so a confirmed note linked to a never-posted credit note was
  silently excluded while its units had no GL anywhere.

  CLOSED in round 3, not deferred: the exclusion now requires the backing
  `supplier_credit_note` journal entry to exist. Stand-alone notes and
  linked-but-unposted notes both fire the detector, and the exclusion starts
  applying by itself if the credit note posts later. Four arms pinned in
  `CheckCogsCoverageCommandTest::test_de_excludes_only_goods_return_notes_whose_credit_note_actually_posted`.
