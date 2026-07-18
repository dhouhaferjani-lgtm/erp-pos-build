GATE VERDICT: REJECT

## Important

**1. Re-settling an expense by instrument after a cancellation dies on a unique-index violation (500), permanently blocking the exact recovery path cancel exists to enable.**

`apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:678` passes the stable settlement key as the *instrument's* `idempotency_key`:

```php
idempotencyKey: $settlementKey,   // "expense:{$expense->id}:settlement"
```

`InstrumentLifecycleService::receive()` (`apps/api/app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php:84`) **unconditionally** `create()`s a row with that key — it has no replay branch — and the column carries a partial unique index (`apps/api/database/migrations/tenant/2026_07_12_100000_add_instrument_portfolio_columns_to_payment_instruments.php:70-71`):

```
CREATE UNIQUE INDEX IF NOT EXISTS payment_instruments_idempotency_key_uniq
ON payment_instruments (idempotency_key) WHERE idempotency_key IS NOT NULL
```

`OutboundInstrumentService::cancel()` (`:511-700`) sets `status = Cancelled` but never nulls `idempotency_key`, and the Expense listener nulls `payment_instrument_id` (`SyncExpenseOnInstrumentLifecycle.php:34-41`).

Failure scenario: pay expense E by cheque → cheque voided → Treasury `cancel()` → listener unlinks, `is_paid=false` → user issues a replacement cheque via `POST /expenses/{E}/pay` → the `payment_instrument_id` guard now passes (Cancelled is not in the denylist at `ExpenseService.php:505-512`), execution reaches `receive()` with the same `expense:{E}:settlement` key → Postgres `23505` → HTTP 500. The expense can never again be settled by instrument. Cash settlement still works (the movement key is untouched), which is precisely why no test catches it — `test_cancel_event_unlinks_instrument_and_resets_payment_fields` (`ExpensePayByInstrumentTest.php:216-236`) stops at the unlink and never re-pays. The plan's own Gate 4 E2E names "cancel-from-bounced second instrument", so this case is in scope and unimplemented.

Note the tension with spec §4.5 ("Idempotency key stays `expense:{id}:settlement` for the settlement itself") — that key is the *settlement* anchor, not the instrument's row key. The instrument needs a per-issue key (or the key must be released on cancel).

**2. `maturity_date` is not required for an effet on the backend; only the UI enforces it.**

`apps/api/app/Modules/Expense/Presentation/Requests/PayExpenseRequest.php:80` — `'instrument.maturity_date' => ['nullable', 'date']` with no `Rule::requiredIf` on `instrument.kind === 'effet'`, and `settleByInstrument` (`ExpenseService.php:623-640`) validates kind/reference/method but never maturity. Spec §4.2 requires the instrument be registered "with `maturity_date` (effets)".

Failure scenario: `POST /expenses/{id}/pay {"mode":"instrument","instrument":{"kind":"effet","reference":"EFF-1"}}` (any API client, or the FE with JS validation bypassed) creates an `effets_payable` liability with `maturity_date = null`. `MaturingInstrumentsController::bucketFor()` (`:139-141`) buckets null maturity as `d0_7`, so an undated bill of exchange silently reports as due within 7 days in the new payables échéancier, and `InstrumentMaturityAlertsCommand` has no date to alert on. `test_effet_settlement_uses_effets_payable_and_preserves_maturity` only asserts the happy path.

**3. The bank fallback name entered in the pay dialog is silently discarded.**

`apps/web/src/features/expenses/components/PayExpenseDialog.tsx:65` holds `bankFallbackName`, `:275` feeds it to `BankPicker`, but `onSubmit` (`:114`) sends only `bank_id: selectedBank?.id ?? null`. There is no `bank_name` in `PayExpenseRequest` (`apps/web/src/features/expenses/types/index.ts:206-215`), none in the FormRequest, and `ExpenseService.php:660-680` never passes `bankName:` to `ReceiveInstrumentData` — so it defaults null.

Failure scenario: user's bank is not in the picker list, toggles fallback, types "Banque Franco-Tunisienne", submits. `payment_instruments` gets `bank_id = NULL` *and* `bank_name = NULL` — the instrument has no bank at all and reconciliation/matching in ⑤b has nothing to key on. The sibling flow does this correctly: `apps/web/src/features/treasury/PaymentForm.tsx:693` sends `bank_name: data.bank_name || undefined`. Either wire `bank_name` through, or don't render the fallback affordance.

## Minor

**4. The "replay" guard in `settleByInstrument` is unreachable and its comment asserts protection it does not provide.** `ExpenseService.php:695-704` queries `instrument_events` for `instrument:{id}:issue` on an instrument created two statements earlier at `:664`; the UUID is fresh, so `$existingIssue` is always null. The comment ("finding one means this orchestration is replaying an already-posted issue") describes `PaymentController.php:1025-1035`, where the instrument genuinely pre-exists. Real protection here comes from the metadata guard at `:498-513` plus the `instrument_events_action_key_uniq` index. Delete the dead branch or move the anchor check before `receive()`.

**5. A listener failure after commit strands the expense as unpaid with no retry.** `SyncExpenseOnInstrumentLifecycle` is a synchronous listener on an `afterCommit` event (`OutboundInstrumentService.php:182`). The Treasury clear (JE + movement) is already durable; if the listener's transaction fails, `is_paid` never flips and nothing retries. `:53` makes this worse by `throw`ing a `DomainException` post-commit, which surfaces as a 500 on an operation that actually succeeded. This is inherent to the mandated event bridge (brief line 66), but it deserves an explicit note in the deploy checklist / a reconciliation path rather than silence.

**6. The second-payment guard is a status denylist, not an allowlist.** `ExpenseService.php:505-512` blocks `Received|Bounced|Cleared`. It is correct today only because `custodyTransfer()`/`deposit()` reject outbound (`InstrumentLifecycleService.php:137-139, 181-183`), so `Deposited`/`Clearing`/`InTransit` are unreachable for outbound paper. Invert it — allow only `Cancelled`/`Expired` — so a future outbound transition can't open a double-payment hole.

**7. The issue-JE assertion doesn't pin the line count.** `ExpensePayByInstrumentTest.php:126-129` uses `firstWhere(...)` on two accounts; a stray third line (e.g. a bank credit) would pass. Add `assertCount(2, $issue->lines)`.

## What holds up

- Issue posts exactly one JE, Dr `401` / Cr `4035`/`403`, two lines (`GeneralLedgerService.php:778-800`), zero movement, balance unchanged — asserted with real accounts and a `sole()` (`ExpensePayByInstrumentTest.php:118-131`).
- Repository/method/bank inputs are tenant+company scoped before mutation (`ExpenseService.php:641-659`, `OutboundRepositoryValidator.php:19-45`), metadata is `lockForUpdate()`'d (`:475`), `is_paid` stays false (`:735-739`).
- Cash-while-paper-active is genuinely blocked — the guard sits above the mode branch (`:498-538`), and `test_retry_and_cash_settlement_are_rejected_while_linked_instrument_is_pending` proves one JE, zero movements, unchanged balance.
- Clear/cancel events are emitted only by the gated Treasury lifecycle, `afterCommit`, after JE + movement (`OutboundInstrumentService.php:135-182`, `:686-704`); Treasury imports no Expense model.
- Unrelated instruments can't touch an expense: the listener matches on `payment_instrument_id` and re-pins tenant+company through the document (`SyncExpenseOnInstrumentLifecycle.php:22-30`).
- Cash regression and LinkedCost rejection preserved and tested with real GL/movement evidence (`ExpensePayByInstrumentTest.php:238-266`).
- Échéancier is display-only: the endpoint returns the full unpaginated set (`MaturingInstrumentsController.php:83`), so client-side direction counts are exact, `total_in`/`total_out` stay separate with no sign inversion, no write path.
- FE payload is strings/nulls throughout, filters to `bank_account` repositories and kind-matched methods (`PayExpenseDialog.tsx:78-83`); i18n keys verified present in en/fr/ar. PHPStan L8 on all four touched Expense files: **[OK] No errors** (re-run independently).

VERDICT: spec ❌ + quality CHANGES-REQUESTED

Before the ⑤a exit review: give the expense instrument its own per-issue idempotency key (or release it on cancel) and add the re-settle-after-cancellation test; make `maturity_date` conditionally required for `kind=effet` on the backend; and either send `bank_name` or remove the fallback affordance from the pay dialog.
