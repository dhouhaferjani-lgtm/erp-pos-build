# GATE 2 — Treasury Phase 2 — RC1 (Fable-5 escalation review)

Reviewer: `claude-fable-5` (mandatory escalation under handoff §3 rule 3(a)). Scope: `phase2-gate-1..53dd5b816`. The reviewer could not write in its sandbox; this artifact preserves the returned review. The standard Opus review remains in `GATE-2-rc1.md`.

## Verdict

CHANGES-REQUIRED. Fable re-adjudicated the Opus HIGH finding: the claimed silent divergence was unreachable because the pre-transaction customer repository guard returned 422. The underlying binding-spec deviation was confirmed: an unledgered custody repository was incorrectly rejected and both portfolio JE gates remained coupled to its nullable `gl_account_id`.

## Findings

1. **HIGH-1 — §8/T12 portfolio-JE decoupling missing.** `PaymentController` required every customer repository to be ledgered and gated primary/advance JEs on `repository.gl_account_id`, although deferred tenders debit the resolved portfolio account. Fix: exempt deferred-customer custody from that guard and gate on the resolved posting account; retain the movement skip.
2. **MED-1 — masking test gap.** `DeferredTenderPaymentTest` used only a ledgered bank repository. Add an unledgered safe-custody test that pins primary+advance portfolio debits, zero movement, unchanged custody balance, and Paid document.
3. **MED-2 — self-referential Finance gate assertions.** The RC1 stabilization derived expectations from the same production formatter and searched the whole body. Restore formatter-independent hard-coded display expectations, normalize Unicode whitespace only, and scope each assertion to the relevant table row/cell.
4. **MED-3 — receipt-proration refund writer lacked the settle-first guard.** `refundReceiptPayments()` could become a live side door once Wave E links POS payments to instruments. Apply `assertInstrumentSettledForCashUndo()` to every linked original payment before writes and pin it.
5. **MED-4 — refund status coverage incomplete.** Pin all three blocked statuses (`Received`, `Deposited`, `Bounced`) across full/partial/reverse and one allowed `Cancelled` case.
6. **MED-5 — supplier byte-shape pin incomplete.** Assert the Dr-401 and Cr-bank pair, two-line shape, exactly one supplier-payment JE, and no instrument/remittance JE.

## Low findings / disposition requested for RC2

- Add deterministic id tiebreakers to instrument/remittance pagination.
- Manual outbound registration should not pre-resolve inbound portfolio accounts.
- Manual web registration does not yet consume an idempotency key; this is outside Task 11's contract and requires a semantic replay design rather than merely passing a header into a unique-index insert.
- An at-sight cheque should clear `needs_details` without requiring maturity; effet still requires maturity.
- Pin side-door error codes, add backend `other` to the FE method type, and add an effet-required-maturity Vitest.
- `parseFloat` in PaymentForm is pre-existing at the Gate-1 base; replace when that validation is next owned.
- The fixed scale-3 backend amount regex was re-adjudicated as not a finding because it matches the persisted decimal column contract.
- The synchronous immediate advance JE and company-currency default are planned/recorded changes, not defects.

## Verified clean

Fable independently verified the global lock order; no movement outside the port; no cash at deferred receipt; debit swaps with untouched credit legs; no receipt-side GL in `receive()`; supplier Phase-1 cash/GL behavior; all four side-door guards; the three spec-named refund guards before writes; no after-commit GL; string/bcmath monetary handling; payment and movement idempotency; middleware/permission/company/UUID/pagination HTTP controls; and the Task-15 single-call nested-instrument frontend contract.

## Process note

Fable observed the HIGH fix draft in the dirty working tree while reviewing the committed RC1 diff, judged that draft correct, and required it to be committed, fully verified, tagged as RC2, and reviewed again. Because the correction records a money-path deviation/finding, RC2 also requires Fable escalation under §3 rule 3(c).

VERDICT: CHANGES-REQUIRED
