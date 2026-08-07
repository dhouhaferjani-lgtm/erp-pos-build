# Ticket: credit-note re-gate carry-overs (N1 GL-vs-subledger stamp question, N2 FE surfacing, N3-N5, C2 display, probe hardening)

From the credit-note money-lane re-gate (2026-08-03, APPROVE-WITH-FIXES promotable —
docs/superpowers/reviews/2026-08-03-credit-note-money-lane-gate.md §"Re-gate ba7be2ce2").

## N1 — ✅ RULING RECEIVED 2026-08-07 (fix lane dispatched): 411 ex-stamp only, stamp = charge fiscale

Expert-comptable answer (verbatim + full consequence list in
`2026-08-06-expert-comptable-rulings-q2-q3.md` §Q1): the sub-ledger's ex-stamp clamp was
correct; `createFromCreditNote()` must credit 411 ex-stamp and book the avoir's timbre as a
separate charge-fiscale/stamp-payable pair. Original finding below for context.

## N1 (original finding) — P2, BLOCKS credit-note GL certification sign-off: clamp desynchronises the ledgers

`GeneralLedgerService::createFromCreditNote()` (:252-260) credits AR (411) by the FULL unclamped
CN total while `allocateCreditNote()` clamps the subledger allocation at remaining balance_due.
Live: CN total 1.600, allocated 0.400 → subledger says 0.000 outstanding, AR control says −1.200.
Requires an ACCOUNTING RULING (expert-comptable question): does the CN's own stamp duty reduce
what the customer owes (GL's current answer) or not (headroom+clamp's answer)? Then align BOTH
ledgers to the ruling. Only manifests with ≥2 CNs against one invoice or a stamp-inclusive total
exceeding remaining. Must be resolved before credit-note GL certification sign-off (feeds
project_accounting_gl_roadmap A1).

## N2 — P2: requested-vs-credited never surfaced to the operator

RULING A's response fields (`requested_amount`/`credited_amount`) have zero apps/web references —
an operator typing 20.085 sees no notice that 20.084 was credited. Small FE change on the CN
create flows: when the two differ, show a non-blocking notice (i18n, en+fr).

## N3 — P3: deviation ceiling is line-inclusive, not always 1 millime

On a duty-bearing invoice a full-total request (120.000) floors at the line-inclusive ceiling
(119.000) — correct per RULING A and surfaced, but a 1.000 TND deviation the repo's probe never
exercises. Add a probe case; document the ceiling in the service docblock.

## N4/N5 — P3: maxQty per-CN not cumulative; remainingCreditHeadroom N+1 on create

Cumulative-quantity ceiling across multiple CNs on one invoice; batch the headroom query.

## C2 residual — P2 (reclassified from P3 by the re-gate): fabricated fractional quantities on whole-unit products

A `0.4244` quantity on a `decimal_places=0` Piece unit renders as `0 pc × 99.000 = 42.017` — an
auditor cannot recompute the printed line from the printed document. Money math is correct
(exactness ruling), so this GATES THE CREDIT-NOTE PDF/PRINT SURFACE, not the service: the
document render must show the credited value per line in an auditor-recomputable form (e.g.
amount-based lines render as value lines, not qty×price). Coordinate with the print/PDF template
owner.

## Probe-test hardening (in-repo test debt)

CreditNoteAllocationExhaustiveProbeTest: fixture lines lack product_id (maxQty ceiling branch
never exercised) and tolerance is 0.010 (10× looser than the 0.001 achieved) — tighten both.
