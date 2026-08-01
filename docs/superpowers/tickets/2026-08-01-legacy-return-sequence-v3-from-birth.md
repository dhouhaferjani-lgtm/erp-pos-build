
**C-6 — legacy /return cannot allocate a chain_sequence on a v3-from-birth terminal, so
`pos:disable-v4-refund-authoring` halts refunds rather than falling back to legacy.** The v3/v4
fiscal-event ingestion path never advances `pos_terminals.current_sequence` (the §1.1 acceptance
tests assert exactly this), while `PosCoreReceiptProjection` fills `pos_receipts.chain_sequence`
from the device's own fiscal-event `sequence_number`. The legacy authoring path allocates
`chain_sequence = $terminal->current_sequence` in `ReceiptFinalizationService`, which on a
v3-from-birth terminal is `0` and permanently behind the projected chain. The first legacy return
after a rollback therefore violates the `pos_receipts_sequence` CHECK (SQLSTATE 23514, observed) or
collides on the `pos_receipts_terminal_sequence` UNIQUE (SQLSTATE 23505, observed) — §1's
PRE-EXISTING failure mode re-exposed, not introduced, by the rollback lever. **Ruled ACCEPTED as
fail-closed and NOT merge-blocking:** the attempt aborts inside its own transaction (no cash
movement, no fiscal event, no persisted return receipt — pinned by
`ReceiptReturnRefactorV3Test::test_legacy_return_on_a_v3_from_birth_terminal_after_a_rollback_fails_closed`),
and the honest operational semantic of an emergency disable on a v3-from-birth terminal is "refunds
halted until re-enable" — the pre-Lane-C interim no-refunds prohibition — not "legacy returns work".
The command now states this in its confirmation prompt and in a warning block printed before AND
after the write, so the operator consents to it rather than discovering it. A real fix changes
fiscal chain numbering (seeding `current_sequence` from the projected chain, or retiring the counter
from the legacy allocator) and gets its own gated lane post-launch.

## What shipped for the ruling

**1. Operator consent, not discovery.** `DisableV4RefundAuthoringCommand` gains
`V3_FROM_BIRTH_WARNING` and a rewritten `CONFIRMATION_QUESTION`:

> `Clear BOTH v4 refund-authoring flags, accepting that on a v3-from-birth terminal this HALTS ALL
> REFUNDS until v4 is re-enabled?`

The warning block is printed **before** the prompt and **again after** the write — an operator who
passed `--force` never saw the prompt, so the post-write repetition is the only copy they get. It
also states the contrast case: a terminal WITH legacy-era history does get a working legacy
`/return` back. The class docblock carries the ruling and its rationale for the record.

**2. Fail-closed pinned as a permanent test.**
`ReceiptReturnRefactorV3Test::test_legacy_return_on_a_v3_from_birth_terminal_after_a_rollback_fails_closed`
— a real v3-from-birth terminal (`current_sequence = 0`), a v3 sale + v4 refund authored through the
real ingestion pipeline, `pos:disable-v4-refund-authoring --force`, then a legacy return through the
real `POST /api/v1/pos/receipts/{id}/return` HTTP endpoint with genuine approval scaffolding.

Asserts the **invariant**, deliberately not the SQLSTATE: non-2xx, plus **zero** new `fiscal_events`,
**zero** new `pos_cash_drawer_operations`, and **zero** new return receipts against the original.
Baselines are snapshotted after the approval scaffolding (which legitimately writes its own
`OPERATOR_APPROVAL_GRANTED` / `OVERRIDE_VOID_OR_RETURN` events) and the return-receipt check is a
before/after delta — the legitimate v4 refund is itself a `receipt_type = 'return'` row carrying the
same `original_receipt_id`, so an absolute `== 0` would have been wrong (it was, and was caught).
A future numbering fix should turn this test green by making the return SUCCEED, not by changing
which error it emits.

Non-vacuity verified by probe: the endpoint returns **422 `RETURN_FAILED`** carrying
`SQLSTATE[23514] … violates check constraint "pos_receipts_sequence"` — i.e. the test really does
reach the C-6 sequence contract, and the transaction really did roll back.

*Minor aside, pre-existing and out of scope:* that 422 body echoes the raw PostgreSQL error text
(constraint name, and a `DETAIL:` line containing the full failing row) straight to the API client.
Worth a separate hygiene ticket; not touched here.

## Round-3 verification

| Suite | Result |
|---|---|
| `ReceiptReturnRefactorV3Test` (PG — §1.1 acceptance, both 409 companions, v2 non-regression, 3 C-5 tests, new C-6 fail-closed test) | **10 passed** |
| `DisableV4RefundAuthoringCommandTest` (PG — includes the two rewritten confirmation-prompt assertions) | **10 passed** |
| Combined PG run | **20 passed** (422 assertions) |
| PHPStan level 8 + Pint on touched files | clean |
