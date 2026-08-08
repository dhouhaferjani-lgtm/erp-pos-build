# OWNER QUEUE — `no_goods_issued` records a falsehood when everything was already returned

**Raised by:** gate CF, fiscal half (round 2 escalation, ruled ticket-not-blocker at the
final pass). **Queue:** OWNER, not the dev backlog — this is a decision-vocabulary and UX
question adjacent to the F2 FE contract the owner already ruled on (`db47258f1`), not an
engineering cleanup.
**Status:** OPEN. **Severity:** low impact, but it writes a false statement onto a fiscal
document.

## The defect

`DeliveredQuantityResolver::hasGoodsIssued()` means **"any tuple with `remaining > 0`"** —
i.e. *delivered units still outstanding*, not *anything was ever delivered*.

So for an invoice whose goods were delivered **and fully returned**, it answers FALSE, and
both sides then adopt `no_goods_issued` — whose own enum docblock reads *"nothing was ever
delivered against this invoice"*. That is a false physical statement, recorded in
`documents.payload['return_decisions'][]`, in two load-bearing places:

- the FE branch mapping — `CancelInvoiceModal::resolveMode()`;
- the server cross-check — `RefundService::assertDecisionMatchesGoods()`.

## Premise correction (required by the gate's ruling)

An earlier note in the CF report described this predicate as *"predates this lane"*. **It
does not.** Verified with `git cat-file`:

- `DeliveredQuantityResolver.php` — **not present** at the lane base `984a020dd`; authored
  by this lane in `f022f234f` (T15).
- `ReturnDecisionMode.php` — **not present** at base; authored in `0337d660a` (T5+T6).

Both reach production for the first time in this merge. The accurate statement is that the
predicate *predates the fix rounds*, not the lane. This matters because it is the same
distinction the gate used to rule the mirror-netting residual IN scope: **the exposure is
new. The reason not to block is severity and reversibility, not age.**

## Why it was not blocked (the gate's four grounds, all code-checked)

1. **Nothing physical, monetary or chained is wrong.** The prior return note already
   restocked; the guided cancel writes no stock movement, no GL entry, no money. Only the
   narrative mode is mislabelled.
2. **The falsehood is contradicted by evidence in the same record**, not concealed — the
   confirmed return note against that invoice is *what makes* `remaining = 0`, so anyone
   tracing the invoice meets it immediately.
3. **It is correctable after the fact.** The mode lands in an appended JSONB record and is
   **not a fiscal-hash input** (the invoice's `fiscal_hash` was computed at seal, before
   the cancel). No versioned event, no chain rebuild, no rule-8 problem. This is the single
   biggest reason it is a ticket: a wrong value here is backfillable, unlike a wrong value
   inside signed bytes.
4. **The remedy is a vocabulary decision, not a defect fix.** The truthful statement —
   *"everything already came back"* — has no enum case today.

## The decision the owner is being asked for

Pick one:

- **(a) Add a `ReturnDecisionMode` case** (e.g. `already_fully_returned`) for "delivered,
  and all of it is already back". Most truthful; costs a new option→mode row in T10's
  table, new i18n copy, and a migration path for records already written.
- **(b) Split the predicate** into `everDelivered()` vs `remainingOutstanding()`, and drive
  the goods question off the first while keeping the cap on the second. The modal would
  then ask the question and offer only option 3, correctly reasoned.
- **(c) Accept the mislabel** and change `ReturnDecisionMode::NoGoodsIssued`'s docblock and
  user-facing copy to the weaker, true statement ("no goods are available to return"),
  rather than the strong "nothing was ever delivered".

Whichever is chosen moves the persisted decision vocabulary, the T10 option→mode table, the
i18n copy and the CF-D6 read model — which is why it is the owner's call and why
improvising it under gate pressure in a fix round would have been worse than shipping a
backfillable mislabel.

## Acceptance

- A cancel of an invoice whose goods were delivered and fully returned records a mode
  whose copy is TRUE of that invoice.
- Existing `payload.return_decisions[]` records carrying the old mode are either migrated
  or explicitly ruled acceptable-as-is.
- `hasGoodsIssued()`'s name matches what it computes, whichever option is taken.
