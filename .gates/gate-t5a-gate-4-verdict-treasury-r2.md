GATE VERDICT: APPROVE

All three Important findings are correctly closed, and the four Minor ones are addressed or acceptably deferred. I verified statically against code rather than re-running the provided evidence (test execution required approval I don't hold; the static chain below is what I could confirm independently).

## Verification against the seven checks

**1. Per-cycle instrument keys, settlement anchor preserved, serialized — ✅**

`ExpenseService.php:669-674` derives `expense:{id}:settlement:instrument:{n}` from a `LIKE`-scoped count, passed as the instrument row key at `:691`. The base `$settlementKey` is untouched and still the financial anchor: it remains the `RepositoryMovement` idempotency key checked at `:516-520` (different table, no namespace collision — the instrument keys are strict suffix-extensions and the movement lookup is an exact match).

Serialization holds. The metadata row is `lockForUpdate()`'d at `:472-482` *before* the guard and before `settleByInstrument`, and the whole path runs inside the outer `DB::transaction` opened at `:468`. Because the LIKE namespace is keyed on `$expense->id`, only same-expense requests can contend, and those are exactly what the metadata lock blocks. `payment_instruments_idempotency_key_uniq` (`2026_07_12_100000_…php:70-71`) is the backstop. `PaymentInstrument` (`app/Modules/Treasury/Domain/PaymentInstrument.php:70`) has no `SoftDeletes` and no global scope, so the count sees every row that could hold the index slot — no phantom-gap key reuse. The UUID in the key contains no `%`/`_`, so the unescaped LIKE cannot over-match.

**2. Cancel → retain → unlink → replace, with a single new issue JE — ✅**

`OutboundInstrumentService.php:672-676` sets `Cancelled` and keeps the row and its key; `SyncExpenseOnInstrumentLifecycle.php:33-42` nulls `payment_instrument_id` and resets payment fields. `test_cancelled_instrument_can_be_replaced_without_reusing_its_row_key` (`ExpensePayByInstrumentTest.php:275-297`) pins distinct ids, `Cancelled`/`Received`, keys `:instrument:1` / `:instrument:2`, and exactly 2 `Issued` events.

Critically — and this was not asserted in the correction but is what makes the replacement financially sound — `cancel()` posts `createOutboundInstrumentCancellationEntry` (`OutboundInstrumentService.php:623-635`), which is the exact mirror of issue: Dr payable-instrument / Cr 401 (`GeneralLedgerService.php:849-872`) against issue's Dr 401 / Cr payable-instrument (`:777-800`). The liability returns to AP before the replacement re-transfers it, so cancel-then-replace nets to one outstanding paper. No double-book.

The allowlist inversion at `:505-511` (`! in_array(…, [Cancelled, Expired])`) blocks `Received`/`Bounced`/`Cleared` as before and now fails closed on any status not explicitly named.

**3. Effet maturity rejected pre-mutation, cheque still optional — ✅**

`PayExpenseRequest.php:80-84` adds `required_if:instrument.kind,effet`. `required_if` is an implicit rule, so the sibling `nullable` does not suppress it on a null/absent value. Defense-in-depth at `ExpenseService.php:637-639` throws before any lookup or `receive()`. Cheque stays `nullable|date`. `test_effet_settlement_rejects_a_missing_maturity_date_before_mutation` (`ExpensePayByInstrumentTest.php:180-209`) asserts the 422 envelope path *and* zero `PaymentInstrument` / zero instrument `JournalEntry` rows — a real DB assertion, not a weakened one.

**4. Dead replay query removed without losing protection — ✅**

The unreachable `InstrumentEvent` lookup is gone (was `:695-704`). The surviving chain is intact and is what the new comment at `:664-668` correctly describes: outer transaction (`:468`) → metadata `lockForUpdate` (`:472-482`) → active-link allowlist (`:505-511`) → unique per-cycle instrument row created at `:675-693` **before** the GL call at `:713-721`. A sequential duplicate is refused by the link guard (status `Received`); a concurrent duplicate blocks on the metadata lock and then sees the committed link. The `instrument_events_action_key_uniq` index still exists but, as noted in round 1, keys on a fresh UUID here — the removal correctly stops pretending otherwise.

**5. Test assertions tightened, not weakened — ✅**

`assertCount(2, $issue->lines)` added at `ExpensePayByInstrumentTest.php:128`, on top of the existing `sole()`. Both new tests hit real endpoints with real models; the helper change at `:352-362` parameterizes the reference rather than relaxing anything.

**6. Fallback affordance removed only where it cannot be persisted — ✅**

`BankPicker.tsx:19,33,218-230` gates the "not listed" button behind `allowFallback`, **defaulting to `true`**. `PayExpenseDialog.tsx:275-279` is the sole opt-out (`grep` over `apps/web/src` confirms), and the fallback state variables are deleted — so no writable name can be entered and discarded. `PaymentForm.tsx:1004-1016`, which does persist `bank_name`, is untouched and keeps the affordance. Directory selection still sends `bank_id` (`PayExpenseDialog.tsx:113`). The dialog test now renders the **real** picker with only `useBanks` mocked (`PayExpenseDialog.test.tsx:39,67-78,143-145`), so `does not offer an unsaved fallback bank name` (`:214-221`) is a genuine regression test rather than a mock artifact.

## Minor

**M1. `Expired` is pre-authorized in the allowlist but is unreachable and has no defined GL semantics.** `ExpenseService.php:508` allows re-settlement of an `Expired` instrument, but `grep` finds no transition anywhere that writes that status — its only other use is the terminal-status list in `PaymentInstrument.php:249`. `Cancelled` is safe *because* `cancel()` posts a mirror reversal; nothing guarantees a future expiry transition will. Failure scenario: someone later adds `expire()` that only flips status (a plausible shape for a lapsed effet), and an expired-then-re-issued expense books Dr 401 / Cr 4035 twice with only one reversal, understating AP by the instrument amount. Narrowing to `[Cancelled]` today, or pinning the reversal requirement in a comment/test, closes it.

**M2. The inverted allowlist has no direct test.** The replacement test reaches `settleByInstrument` with `payment_instrument_id = null` (the listener already unlinked at `SyncExpenseOnInstrumentLifecycle.php:34`), so the guard at `:505-511` is never evaluated on that path. `test_retry_and_cash_settlement_are_rejected_while_linked_instrument_is_pending` covers the reject side only. A test that cancels the instrument with the listener suppressed — the exact post-commit-failure state M4 describes — would exercise the allow branch.

**M3. Cash mode still accepts an instrument-bound payment method on the backend.** The cash branch (`ExpenseService.php:609`) writes `payment_method_id` with no `instrument_kind` check, while the FE now filters it out (`PayExpenseDialog.tsx:82`). Pre-existing, not introduced here, but the correction made the divergence explicit: an API client can settle cash against a cheque method and mislabel the payment. Worth a follow-up rather than a block.

## On check 7 — the listener-after-commit concern

Correctly **non-blocking for this gate**, and the correction actually reduces its blast radius. `SyncExpenseOnInstrumentLifecycle` is still synchronous on an `afterCommit` event (`OutboundInstrumentService.php:686-694`), so a listener failure after a successful cancel leaves the expense linked to a `Cancelled` instrument. Before the inversion that state was unrecoverable-by-retry in the wrong direction; now the allowlist at `:505-511` lets a retried `/pay` proceed against a `Cancelled` link, so the user-facing recovery path self-heals. What remains is the cosmetic 500 on a succeeded clear (`:53`) and stale `is_paid=false` after a failed clear-sync. That belongs in the deploy checklist as a reconciliation note, not a merge gate.

VERDICT: spec ✅ + quality APPROVED

Before the ⑤a exit review: decide M1 (drop `Expired` from the allowlist or pin the mirror-reversal requirement), and add the listener-failure reconciliation note to `treasury-phase5` deploy checklist alongside the existing Phase ③/④ owes.
