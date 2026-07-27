GATE VERDICT: APPROVE

I read every file in the Wave 2 diff plus the dependencies the claims rest on (movement port, balance-due triggers, `payment_allocations` schema history, `PaymentController` issue path, `journal_entries` source-uniqueness indexes) and verified the risky claims firsthand rather than trusting the implementer's summary.

## Verification of the gate focus items

**1. Four GL builders — CONFIRMED.** All four route through one private helper, `GeneralLedgerService.php:874-940`: exactly two lines, `debit = $amount`/`credit = '0'` and `debit = '0'`/`credit = $amount` (`:912-930`) — balanced by construction; `source_type = 'instrument'` and `journal_code = JournalCode::Effets` (`:906-908`), and `JournalCode.php:22` confirms `Effets = 'EF'`. Legs match §4.3 exactly:

| Builder | Dr | Cr | Partner tag |
|---|---|---|---|
| Issue `:778-802` | SupplierPayable (401) | payable-instrument | Dr only ✅ |
| Clearing `:805-825` | payable-instrument | `repository.gl_account_id` | none ✅ |
| Dishonor `:828-848` | `repository.gl_account_id` | payable-instrument | none ✅ |
| Cancellation `:851-873` | payable-instrument | SupplierPayable (401) | Cr only ✅ |

Partner tags are asserted per-builder at `OutboundInstrumentServiceTest.php:191-201`, including the negative assertions that the payable-instrument leg carries no `partner_id` (`:195`, `:197`). The `LogicException` at `:887-889` refuses to draft outside an enclosing transaction. I confirmed no partial unique index on `journal_entries (source_type, source_id)` covers `'instrument'` (only `treasury_transfer` and the procurement pair), so the multi-JE lifecycle is not blocked.

**2. Validator — CONFIRMED.** `OutboundRepositoryValidator.php:19-45` scopes on both `tenant_id` and `company_id` (`:22-23`) and rejects not-found, non-`BankAccount`, inactive, null `gl_account_id`, currency mismatch, bank mismatch. All call sites pass tenant+company through and never widen it: `OutboundInstrumentService.php:107-113`, `:258-264`, `:415-421`, `:598-604`. Each re-checks `gl_account_id !== null` after validation (`:120`, `:266`, `:423`).

**3. Lock → replay → validation ordering — CONFIRMED, the item I checked hardest.** All four open `DB::transaction`, take a tenant+company-scoped `lockForUpdate()` on the instrument (`:62-67`, `:215-220`, `:370-375`, `:522-527`), then do the `action_key` lookup and digest comparison, and only *after* that check direction and transition: clear replay `:71-95` → direction `:97` → status `:100`; bounce `:224-246`/`:248`/`:251`; represent `:381-403`/`:405`/`:408`; cancel `:530-552`/`:554`/`:557`. `assertSemanticDigest` (`InstrumentEvent.php:54-59`) uses `hash_equals` and throws on a null stored digest too. Exact replay returns the stored IDs and never reaches GL — proven at `OutboundInstrumentServiceTest.php:118-143` (1 event, 1 movement, 1 JE despite status already `Cleared`), `:204-223`, `:414-443`, `OutboundCancelReopenTest.php:206-231`. Unique partial index `instrument_events_action_key_uniq` (migration `2026_07_18_100100:39`) is the durable backstop.

**4. Movement shapes and cycles — CONFIRMED.** Clear `out`/`clear:{cycle}` (`:138-155`), represent `out`/`clear:{targetCycle}` (`:447-464`), bounce `in` with `reverses_movement_id` from the cycle-matched clear event (`:269-275`, `:296-313`). Asserted at `ServiceTest:103`, `:303-305`, `:357-358`; cycle-1 artifacts asserted still present after cycle 2 (`:344-348`) — cycles append, never mutate.

**5. Rollback atomicity — CONFIRMED.** All effects sit in one `DB::transaction`, and `TreasuryMovementService::record()` (`:51-53`) hard-refuses to run outside one. Injection proofs: `ServiceTest:238-279` (closed period ⇒ status `Received`, JE count unchanged, 0 movements/events), `:374-412` (cycle increment rolled back), `CancelReopenTest:173-204` (failure after the cancellation JE ⇒ nothing survives).

**6. Cancellation — CONFIRMED.** `:557-559` admits only `Received`/`Bounced`; `Cleared → cancel` throws (`CancelReopenTest:160-170`). Lock order is exactly the contract: instrument `:522` → payment `:564-569` → positive allocations ordered by `document_id`,`id` `:574-582` → documents ordered by `id` `:584-593` → then GL `:610-623`. Negative allocations via `bcsub` at resolved scale (`:629`); recompute (`:633-656`) uses the same `total − Σpayment_allocations − Σcredit_note_allocations` formula as the PG trigger `update_document_balance_due()` (migration `2026_01_08_214145:31-49`) — I checked, they agree, so trigger and PHP converge rather than fight. `Paid → Posted` only on a strictly positive balance (`:652-654`). Payment `Reversed` (`:658`), no movement (`:675`, `:694`, asserted `CancelReopenTest:99`). Multi-document/partial covered at `:118-142`.

**7. Concurrency proof — CONFIRMED as real two-process PostgreSQL.** `OutboundInstrumentConcurrencyTest.php:41-44` commits fixtures on pgsql; the child forks, `DB::disconnect()`s for its own PDO (`:66`), and blocks on a socket (`:67`). Identical bounce asserts same `journalEntryId`/`movementId` and exactly one `replayed === true` (`:110-115`); cancel-vs-clear asserts exactly one winner (`:195-198`) and exactly one keyed event (`:202-208`). Skips off pgsql/pcntl are explicit (`:48-53`, `:128-133`), not vacuous passes.

**8. Inbound untouched — CONFIRMED.** `git diff --stat t5a-gate-1..HEAD` over the *whole* range shows 11 files — 6 outbound sources, 4 test files, 1 plan. `InstrumentLifecycleService.php` and `PaymentController.php` are absent from the diff.

## Findings (all Minor — none blocks the gate)

**M1 — `cancel()` silently degrades to a no-op subledger reopen if the linked payment can't be resolved.** `OutboundInstrumentService.php:564-572`: with `payment_id` non-null but the lookup returning null, `$payment` is null, `$allocations` is empty (`:574-582`), and cancellation proceeds — posting Dr payable / Cr 401 while leaving the payment `Completed` and its documents `Paid`. Silent GL↔subledger divergence. The analogous document case throws (`:594-596`), so the asymmetry reads as unintentional. Today the null branch is reachable only for a genuinely payment-less instrument (Wave 4 expense path), so I could not construct a real-data failure — Minor, not Important. One-liner: `if ($instrument->payment_id !== null && ! $payment instanceof Payment) { throw new DomainException(...); }`.

**M2 — `represent()` on a never-bounced `Cleared` instrument raises the wrong exception.** `:377-403`: `$targetCycle` is derived from status *before* the transition check, so on `Cleared` it collides with the executed `clear:{cycle}` key. The digest's `action` field (`:700-716`) saves it — fails closed with `InstrumentActionConflictException` — but §4.3 requires the canonical `InvalidInstrumentTransitionException` for unlisted transitions. Fails safe; untested.

**M3 — document totals scaled with the *instrument's* currency scale.** `:608` resolves `$scale` from `$instrument->currency` and `:634-652` applies it to `$document->total` and the whole recompute. Latent only — nothing in the current allocation path allows a currency mismatch.

**M4 — no defence-in-depth on the amount invariant.** GL posts `$instrument->amount` (`:609-618`) while all of the payment's allocations are unwound (`:625-632`). Equal only because `PaymentController.php:726`/`:767` build the instrument with `$paymentAmount`, and `payment_instruments.payment_id` has no unique index. I verified the invariant holds today; a `bccomp` guard would make cancel self-defending.

**M5 — two test-matrix gaps.** `OutboundRepositoryValidatorTest.php:104-111` covers cross-*company* but not cross-*tenant*, though the validator filters both. And rollback injection covers GL failure only — movement-port failure rests on the single-transaction structure, not a red test. Both are absences, not weakening.

## Note on evidence

Bash execution of the suites was denied in this session (and my attempt to write `.gates/gate-t5a-gate-2-verdict.md` was also denied — the file is still empty; paste this in if you want it recorded). So I did not independently re-run the 42/45-test evidence. The verdict rests on reading every line of the diff and its dependencies plus assertion-by-assertion inspection of the tests — the load-bearing check, since a green suite asserting the wrong thing is the failure mode this gate exists to catch. I found no weakened assertions, no vacuous skips, and no test reshaped to fit the implementation.

VERDICT: spec ✅ + quality APPROVED — nothing must be fixed before Wave 3; fold M1 (throw when a non-null `payment_id` fails to resolve) and M4 (assert `payment.amount == instrument.amount`) into Task 6, the task that establishes the issue-time invariant both depend on.
