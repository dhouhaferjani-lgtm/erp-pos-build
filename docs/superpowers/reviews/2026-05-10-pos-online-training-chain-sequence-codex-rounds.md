# PR #104 — Online ReceiptCreationService training chain_sequence NULL fix — Codex review trail

**Branch:** `fix/online-training-chain-sequence-null`
**Base:** `dev`
**Reviewer:** Codex CLI (`codex review --base dev`)

## Round 1 — APPROVE-WITH-MINOR-EDITS-APPLIED (P2 closed)

### P2 — Training receipt numbers must advance

> When the same terminal creates a second training receipt, this path still
> uses `terminal.current_sequence` inside `generateTrainingReceiptNumber()` but
> deliberately leaves it unchanged, so both rows get the same
> `TRN-...-{sequence}` receipt number. The unique index on
> `(tenant_id, company_id, location_id, receipt_number)` then rejects the
> second training receipt; now that this patch allows the first training
> insert by storing `chain_sequence = NULL`, training mode remains unusable
> beyond one receipt unless a separate persisted training counter is advanced.

**Closure (commit `ea9880ea`):**

- `ReceiptCreationService::createReceipt`: dropped the `if (! $isTraining)` guard
  so all paths bump `current_sequence`. Comment rewritten to explain that
  training and production share the receipt-number counter (TRN- prefix only
  namespaces the visible string).
- Test `test_training_receipt_does_not_advance_terminal_sequence` (which was
  pinning the bug in place) flipped to
  `test_training_receipt_advances_terminal_sequence`.
- New regression test `test_two_sequential_training_receipts_have_unique_numbers`:
  two creates on the same terminal both persist, get unique TRN- numbers, and
  both retain `chain_sequence=null`.
- Verification: `tests/Feature/POS/ReceiptCreationServiceTrainingModeTest.php`
  5/5 + 11 assertions; full Feature/POS suite 767/767 + 2586 assertions
  (clean +1 test +5 assertions vs round-1 baseline; skipped/incomplete
  unchanged at 21/2). PHPStan/Pint clean on changed source.

## Round 2 — APPROVE

> The changes correctly avoid writing chain_sequence=0 for training receipts
> and persist sequence advancement to prevent duplicate training receipt
> numbers. I did not find any discrete regressions introduced by this patch.

No findings. PR ready for merge.

## Final shape

- 1 source change: `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php` — `chain_sequence=null` for training (was `0`); guard removed so training also advances `current_sequence`.
- 1 new test file: `apps/api/tests/Feature/POS/ReceiptCreationServiceTrainingModeTest.php` — 5 tests, 11 assertions.
- 2 commits: round-1 base fix (`636d8bea`), round-1 P2 closure (`ea9880ea`).

## Cross-references

- PR #103 round-1 surfaced the chain_sequence=0 issue on the offline-sync path; that PR closed the offline side and noted this online-side gap as an out-of-scope follow-up.
- PR #92 in-flight pre-launch work: "Online ReceiptCreationService training chain_sequence fix" — this PR closes that entry.
