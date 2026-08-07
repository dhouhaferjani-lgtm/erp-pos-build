# Treasury merge gate — `fix/l5-negative-repository-balance` (W-5b Option B)

Reviewer: treasury-reviewer (adversarial, code-grounded)
Date: 2026-08-07
Worktree: `/Users/houssamr/Projects/syneriva/apps/erp.fix-l5-repobal`
Commits: `589e5a199` (migration+model), `9e8062cfc` (enforcement+exception), `4e07264bb` (intent sweep)
Diff base: `695f6814d`

## VERDICT

**spec ❌ — quality CHANGES-REQUESTED (REJECT for merge as-is).**

One CRITICAL: the guard is bypassable through the single most ordinary cash
operation the feature exists to protect (register → safe transfer). Proven
empirically, not inferred. Everything else in the lane is competent work.

---

## P-1 — WORKER-THROW HOLE: **NOT FOUND. The 8 "unset" sites are disproven as
worker-reachable.**

The full record()-caller set is 21 `new MovementIntent(` sites (`grep -rn "new
MovementIntent(" app/`), of which the OUT-direction ones outside the three
bridges are:

| Site | Direction | Root caller(s) — traced |
|---|---|---|
| `AcquirerFeeService.php:133/137` | Out | `Actions/AcquirerFeeHandler.php:52` ← `StatementActionRegistry` ← `StatementMatchingService` ← `Presentation/Controllers/StatementLineController.php:28` (HTTP only — grep for `StatementMatchingService` returns exactly one non-self hit) |
| `InstrumentLifecycleService.php:491` (`bounce()`) | Out | `InstrumentRemittanceController.php:187`, `PaymentInstrumentController.php:339` — the ONLY two `BounceInstrumentData` construction sites. Repository is `$remittance->bank_repository_id` (`InstrumentLifecycleService.php:355`) → a bank account → `allow_negative = true` anyway. |
| `OutboundInstrumentService.php:138` (`clear()`), `:450` (`represent()`) | Out | `Actions/OutboundClearHandler.php:46/53` (← StatementLineController) and `PaymentInstrumentController.php:40` |
| `VendorRefundService.php:199` | Out | `PaymentRefundController.php:21` only |
| `PaymentRefundService.php:530` | Out | `PaymentRefundController.php:20` and `POS/Application/Services/ReceiptReturnService.php:859` ← `ReceiptController.php:54` / `ExchangeService.php:75` — all HTTP |
| `InstrumentLifecycleService.php:273`, `OutboundInstrumentService.php:296`, `MultiPaymentService.php:542` | **In** | guard is In-exempt by construction (`TreasuryMovementService.php:118`) |

Worker surface actually enumerated (not assumed):

- The only `ShouldQueue` runner that reaches a projector is
  `Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:144`.
- The tagged projector set is `POSServiceProvider.php:55-63`,
  `TreasuryServiceProvider.php:127-135`, `DocumentServiceProvider.php:57-61`.
  Of those, only the three treasury bridges construct a `MovementIntent`;
  `TreasuryAccountChargeBridge` and `DocumentAccountChargeFactureBridge` do not
  (no `movementService` dependency — see their constructors at
  `TreasuryAccountChargeBridge.php:35-36`, `DocumentAccountChargeFactureBridge.php:21-22`).
- The two indirect bridge→service edges were checked and are movement-free:
  `Projections/Concerns/HandlesMaturityTenderLeg.php:76` calls only
  `InstrumentLifecycleService::receive()` (`:71-136` — no `record()`), and
  `TreasuryReceiptBridge.php:1513` calls `cancel()` →
  `performCancellation()` (`:758-805` — GL entry + status + audit row, no
  `record()`).
- Console reach: `ReconcileTreasuryCommand.php:1106` uses only `freeze()`;
  `GenerateRecurringExpensesCommand.php:120` calls only `ExpenseService::create()`
  (`ExpenseService.php:67`) — every ExpenseService movement lives in `post()`
  (`:360`,`:396`), `settle()` (`:651`), `reverse()` (`:833`), none reached.
- `Expense/Application/Listeners/CreateExpenseFromStatement.php` and
  `SettleExpenseFromStatement.php` are plain classes (no `ShouldQueue`) → they
  run synchronously inside the StatementLineController request. Their
  `post()`/`settle()` outflows are genuinely interactive.

So the `allowWhileFrozen` precedent did transfer correctly here, and the sweep's
three bridge sites are the right (and only) queued set. **P-1 clears.**

One residual in the same family — see I-4 below (`RefundCompensationService`).

## P-2 — TRANSFER BYPASS: **CONFIRMED, CRITICAL, in-lane fix required.**

`TreasuryMovementService::transfer()` (`:233-447`) is a second public writer
that never passes through `record()`. It locks both repositories
(`:266-272`), checks currency (`:280-285`), cross-GL-account JE (`:293-296`),
and checkpoint (`:308-309`) — and then writes the OUT leg straight into
`insertMovementLeg()` (`:349-367`). There is **no** `allow_negative` /
balance-sufficiency check anywhere on that path, and `insertMovementLeg()`
(`:485-541`) is the sole balance mutator (`$repository->balance = $balanceAfter;
$repository->save();` at `:537-538`), so nothing downstream catches it either.

Reachability is a permissioned interactive endpoint, not a theoretical path:
`POST /api/v1/payment-repositories/transfers` →
`routes.php:102-104` (`can:treasury.transfer`) → `RepositoryTransferController` →
`RepositoryTransferService::transfer()` (`:33-116`, which validates frozen /
active / transferable-type / cross-GL only) → `TreasuryMovementService::transfer()`.

**Empirically proven** with a throwaway probe (written, run, deleted): a
`cash_register` at `10.000` with `allow_negative = false` transferred `1000.000`
to a `safe` and landed at `-990.000` with no exception raised.

Failure scenario: a cashier (or a fat-fingered end-of-day "send the till to the
safe" with a mistyped amount) drives the register to a large negative. The
`record()` guard then locks the register out of every subsequent legitimate
outflow (refund, expense payment) with a 422, while the safe holds phantom cash.
This is the exact defect class the lane was opened to close.

---

## FINDINGS

### CRITICAL

**[CRITICAL] `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:349-367`
(transfer OUT leg) — the W-5b guard is enforced in `record()` only; `transfer()`
debits a repository with no `allow_negative` check.**
Why it matters: the register→safe transfer is the highest-frequency
cash-outflow operation in the product and it is fully unguarded; the feature's
core promise fails for the ordinary case, and the resulting negative balance
then bricks the register for every guarded outflow.
Fix: apply the same predicate to the `fromRepo` leg after the sorted
`lockForUpdate()` (`:266-272`) and before `insertMovementLeg()` — factor the
`record()` block at `:117-133` into a private
`assertOutflowAllowed(PaymentRepository $repo, string $amount, int $scale, bool $allowNegative)`
and call it from both writers. `TransferIntent` has no `allowNegative` today and
should not need one (there is no queued transfer writer — `TransferIntent` is
constructed only in `RepositoryTransferService.php:89`), so the transfer leg can
hard-block. Add a `TreasuryMovementServiceTransferTest` case + a
`RepositoryTransferEndpointTest` 422 case.

### IMPORTANT

**[IMPORTANT] `apps/api/app/Modules/Treasury/Domain/PaymentRepository.php:112-128`
+ `Presentation/Controllers/PaymentRepositoryController.php:141/193` — the
type-derived default is a `creating`-only hook, but `type` is mutable via
`PATCH`.**
`update()` validates `'type' => ['sometimes', 'string', Rule::in([...])]`
(`:141`) and mass-assigns it (`:193`, and the locked branch at `:193` above).
Converting a `bank_account` (allow_negative = true) to a `cash_register` keeps
`allow_negative = true` — a till on which the guard is silently dead. The
reverse (cash → bank) leaves a bank account unable to overdraft.
Fix: re-derive in an `updating` hook when `type` is dirty and `allow_negative`
is not explicitly in the same payload, or forbid type changes once movements
exist.

**[IMPORTANT] `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:65-117`
— `allow_negative` has no write path anywhere in the codebase.**
`grep -rn "allow_negative" app/` shows it is `$fillable`
(`PaymentRepository.php:138`) and readable (`:327` in `formatRepository`), but
neither `store()` nor `update()` validates or accepts it, and there is no FE
reference (`grep -rn allow_negative apps/web/src apps/pos/src packages/shared`
→ empty). The ruling is "**per-repository** `allow_negative` boolean, **type-derived
default**"; what shipped is only the type-derived default. There is no operator
lever to authorise an overdraft on a safe/virtual bucket, and no remedy for the
drift in the finding above.
Fix: accept `allow_negative` (boolean, `sometimes`) in store/update behind
`treasury.manage`, or explicitly record in the lane that the lever is
deliberately deferred and the column is internal-only.

**[IMPORTANT] `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:93-116`
— the 201 create response returns `"allow_negative": null` for every non-bank
type.**
`PaymentRepository::create()` never populates the attribute for
cash_register/safe/virtual (the hook at `PaymentRepository.php:120-127` only
*sets* it for `BankAccount`), and `store()` calls `->load([...])` (relations
only, `:112`) rather than `->refresh()`, so `formatRepository()` reads an unset
attribute. Verified: an `assertFalse($repo->allow_negative)` on a freshly
`create()`d cash register fails with *"Failed asserting that null is false"*;
`$repo->fresh()->allow_negative` is `false`. A subsequent `GET` returns `false`,
so the create and read responses disagree on type.
Fix: `$repository->refresh()` (or set the attribute to `false` explicitly in the
hook's else-branch, which also makes the model self-consistent pre-save).

**[IMPORTANT] `apps/api/app/Modules/Fiscal/Application/Services/RefundCompensationService.php:295-310`
— an OUT movement replaying an already-physical device refund, with no
`allowNegative` and no bypass.**
This is the dead-letter/quarantine remediation path for a POS refund that the
device already paid out in cash (`compensate()`, `:64`), reachable via
`RefundCompensationController.php:35`. It is *interactive*, so P-1's
worker-throw rule does not apply — but it is semantically identical to the
bridge case the ruling carved out: the cash is already gone. If the refund's own
`SALE_RECEIPT` projection also failed (the common correlated failure), the
server-side drawer balance is below the refund amount and the operator now gets
a hard 422 with **no** override, i.e. a dead-ended remediation flow.
Fix: either pass `allowNegative: true` here (it is a replay of a physical fact,
and the operator attestation is already captured at `:66-68`), or give the
endpoint an explicit attested override. Do not leave it silently blockable.

**[IMPORTANT] `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:203-223`
— the "alert" is a `Log::warning` only, and the stated justification for not
using `TreasuryAlertNotification` is factually wrong.**
`TreasuryAlertNotification` is fully wired and in active production use at six
call sites — `ReconcileTreasuryCommand.php:479,745,1144`,
`InstrumentMaturityAlertsCommand.php:161,205`,
`GenerateRecurringExpensesCommand.php:172` — with a passing recipient-resolution
test (`tests/Feature/Treasury/TreasuryAlertRecipientsTest.php:180`). The in-code
comment ("the only ACTIVE alert-emission precedent **in this port**") is
narrowly true; the claim that the notification mechanism is unwired is not.
Since this alert fires only on device-replay legs (i.e. exactly the silent,
nobody-is-watching case), a log line no human reads is a weak remedy for a
till that has gone negative.
Fix: either emit `TreasuryAlertNotification` alongside the log (inside the same
`DB::afterCommit`), or record the deliberate log-only decision with the correct
rationale.
(The mechanics of the alert itself are correct: `DB::afterCommit` at `:212` fires
only on outermost commit, and the payload carries repo/tenant/company/amount/
currency/balance_after/source — verified green by
`TreasuryMovementServiceRecordTest::test_allow_negative_intent_records_and_alerts_instead_of_throwing`.)

### MINOR

**[minor] `TreasuryDepositBridge.php:281`** — `allowNegative: ! $event->event_type->isServerOnly()`
is *always false* on this bridge: it handles only `DEPOSIT_RECEIPT`
(`:82`), which is in `FiscalEventType::isServerOnly()`'s list
(`FiscalEventType.php:76-82`). The comment calls it "defense in depth", but the
expression can never evaluate true, so it would not protect anything if the leg
direction ever changed. Either hardcode `false` with the real reason, or drop it.
(`TreasuryAccountPaymentBridge` handles `ACCOUNT_PAYMENT` (`:67`), which is not
server-only, so its flag does evaluate true — that one is genuine defense in depth.)

**[minor] `database/migrations/tenant/2026_08_06_100000_add_allow_negative_to_payment_repositories.php:33`**
— `->after('type')` is ignored by the Postgres grammar. Harmless, but misleading
in a tenant-PG-only migration.

**[minor] no coverage of the transfer path at all.** The new tests are excellent
where they exist (`TreasuryMovementServiceRecordTest` cases (g)/(h),
`RepositoryAdjustmentTest::test_out_adjustment_returns_422_...`,
`PosRefundReceiptBridgeTest::test_device_refund_leg_records_and_alerts_...` —
all real behaviour, real models, `RefreshDatabase`, `CompanyContext` cleared at
`TreasuryMovementServiceRecordTest.php:53`). The gap is structural, not
qualitative: nothing exercises the other public writer, which is exactly where
the hole is.

**[minor] operational note (not a code defect):** existing production/staging
repositories that are *already* negative (the premise of the L5 lane) will now
refuse every further outflow. The remedy exists (an `in` adjustment via
`POST /payment-repositories/{id}/adjustments`), but there is no data audit or
remediation step in this branch or the migration. Worth a deploy-note.
Similarly, `count_variance` shortage adjustments larger than the recorded
balance now 422 — intended per the ruling, but a behaviour change cashiers
will hit.

---

## STANDARD VERIFICATION — results

1. **Migration** ✅ self-guarding on both legs (`:28`, `:47`), idempotent
   backfill (`:40-42` only ever flips bank rows), safe on missing table/empty
   tenant. Type mapping uses `RepositoryType::BankAccount->value` directly —
   byte-match by construction, and the enum has exactly the four expected cases
   (`RepositoryType.php:9-12`). Column is NOT NULL DEFAULT false. Future rows:
   the model hook (`PaymentRepository.php:120-127`) fires on `create()` and the
   DB default does *not* override it — confirmed by
   `PaymentRepositorySpineColumnsTest` (bank→true, non-bank→false, explicit
   value never overridden; 16/16 green). Seeder path
   (`PaymentRepositorySeeder`) goes through the model. **Caveat: the update path,
   see IMPORTANT above.**
2. **Enforcement placement** ✅ after `lockForUpdate()` (`:64-69`) and after the
   idempotent-replay short-circuit (`:76-78`, so a replay never re-trips the
   guard — good), inside the caller's transaction; Out-only (`:118`); exact zero
   allowed (`bccomp(... ) < 0`, `:120`, test at `:210-221`); bcmath at
   `$scale = $this->scaleResolver->getScale($intent->currency)` (`:58`, explicit
   currency, no bare `getScale()`); no float anywhere. PHPStan level 8 clean on
   all four changed app files.
3. **Exception → HTTP** ✅ registered at `bootstrap/app.php:380`, before the
   generic `DomainException` handler at `:401`; envelope matches the project
   shape (`error.code` / `error.message`, cf. `:259-268`, `:401-409`) with
   `INSUFFICIENT_REPOSITORY_BALANCE` plus available/requested/resulting_balance/
   repository_id/currency; en + fr strings real (`lang/en/messages.php:71`,
   `lang/fr/messages.php:71`). End-to-end proven by
   `RepositoryAdjustmentTest::test_out_adjustment_returns_422_insufficient_repository_balance_and_writes_nothing`
   (asserts JE + movement both rolled back). Web surfaces `error.message`
   (`apps/web/src/lib/api.ts:65`).
4. **Alert** ✅ mechanically; ❌ on the justification — see IMPORTANT above.
5. **Intent flag** ✅ `MovementIntent.php:71` mirrors `allowWhileFrozen` exactly
   (public promoted bool, default false, named-arg at every call site, plain DTO
   so no queue-serialisation concern — it is constructed inside the job, never
   serialised across it). The `! $event->event_type->isServerOnly()` expression
   matches `allowWhileFrozen` on the same line at all three sites. Server-only
   events cannot carry an Out refund leg on the receipt bridge:
   `TreasuryReceiptBridge::handlesEventType` is `SALE_RECEIPT`-scoped and
   `SALE_RECEIPT` is not in the server-only list, so the flag is true for every
   event that bridge actually applies.
6. **21-site table spot-checks** ✅ all three requested claims hold — expense
   statement listeners are synchronous plain classes;
   `GenerateRecurringExpensesCommand` reaches only `ExpenseService::create()`,
   which contains no `MovementIntent`; `RepositoryAdjustmentController.php:123-140`
   goes through `record()` and is therefore guarded (and now covered by a 422 test).
7. **Collateral test fixes** ✅ legitimate, not behaviour masking. `balance` is
   genuinely absent from `PaymentRepository::$fillable` (`:132-150`), so both
   fixtures had *always* been minting a 0-balance till while claiming 5000/100;
   funding them through the port is the correct repair and matches
   `PaymentRepositorySeeder::recordOpeningBalance()`.
8. **Reruns (sqlite, the repo's configured test driver — `phpunit.xml:41`)**
   - `TreasuryMovementServiceRecordTest` — 17 tests, 39 assertions, OK (3 skips
     are pre-existing Postgres-shaped savepoint tests, unrelated to this diff).
   - `RepositoryAdjustmentTest` + `PaymentRepositorySpineColumnsTest` — 16/16 OK.
   - `PosRefundReceiptBridgeTest` — 5/5 OK.
   - `RepositoryMovementsEndpointTest` — 1 failure, *"Repository movement
     allocation aggregate must be a decimal string"* at `:352`; a sqlite
     aggregate-typing artifact on a code path this diff does not touch.
     Their "pre-existing" claim is credible.
   - Note: the branch's new guard has **no** Postgres-mode execution in this
     review (default driver is sqlite). The guard is pure PHP bcmath before the
     insert, so the risk is low, but it is not zero-verified against PG.

## WHAT TO FIX BEFORE MERGE

Close the `transfer()` OUT-leg bypass (shared `assertOutflowAllowed()` helper +
transfer service/endpoint tests); then re-derive `allow_negative` on type change,
fix the `null` in the create response, and settle the `RefundCompensationService`
override question.

---

# Fix-round re-verify — `ddad745e7` (2026-08-07)

Scope: ONLY the findings raised above. No new areas audited.

## REVISED VERDICT: **CLEAR TO MERGE** (spec ✅ / quality APPROVED)

All findings closed. One new **minor** latent trap found (non-blocking, one-line
fix, must land before the ticketed FE editor). Everything below was re-verified
by reading the code and running tests myself; two throwaway probes were written,
run, and deleted (worktree left clean apart from this record).

## CRITICAL — transfer bypass: **CLOSED**

`assertOutflowAllowed(repo, direction, amount, scale, allowNegative): bool`
(`TreasuryMovementService.php:509-533`) is now the sole predicate, called from
`record()` (`:105-111`) and `transfer()` (`:313`). Correct by inspection:

- **Placement in `transfer()`** — after the sorted `lockForUpdate()` (`:266-272`),
  after the currency guards (`:280-285`), after the cross-GL/JE-required guard
  (`:293-296`), after the idempotent-replay short-circuit (`:304-306`), after
  `checkpointDisposition()` (`:308-309` — verified read-only at `:852-874`, it
  only reads `last_reconciled_at` and either returns or throws), and **before**
  the port GUC (`:317`), the savepoint (`:334`), and `postEntryNow()` (`:345`).
  So the refusal happens before any write in this method.
- **Replayed transfer never re-trips** — the guard sits *after* the
  `whereIn('idempotency_key', [$outKey, $inKey])->exists()` short-circuit, so a
  replay returns `handleTransferIdempotentHit()` without ever reaching the
  predicate. Same ordering as `record()` (`:76-78` → `:105`). ✅
- **Draft JE untouched** — the draft is minted by
  `RepositoryTransferService.php:77-87` *inside* that service's own
  `DB::transaction` (`:64`); the exception propagates out of the closure, so the
  draft rolls back with it. Asserted directly by the new
  `test_transfer_out_leg_below_zero_is_refused_and_both_balances_and_je_are_untouched`
  (`JournalEntryStatus::Draft` still Draft, both ordinals still 0).
- **Hard-block is right** — `transfer()` passes `allowNegative: false`
  unconditionally, and `TransferIntent` is constructed at exactly one site
  (`RepositoryTransferService.php:89`, an interactive HTTP path), so the
  "record + alert" branch is genuinely unreachable there.
- Exception now carries `$repo->currency` rather than `$intent->currency`
  (`:529`); in `record()` these are provably equal (currency guard at `:82`),
  and `transfer()` guards both repos at `:280-285`. Equivalent, no drift.

**My own probe rerun (the exact scenario that failed the first gate):**
cash_register `10.000` → `transfer()` of `1000.000` to a safe → typed
`InsufficientRepositoryBalanceException` (repositoryId / available `10.000` /
requested `1000.000` / resulting `-990.000` / currency `TND`), both balances
unchanged, both `next_movement_ordinal` still 0, `repository_movements` count 0.
Green, 12 assertions.

**Suites rerun:** `TreasuryMovementServiceTransferTest` +
`RepositoryTransferEndpointTest` + `RepositoryTransferServiceTest` — 33 tests
green on sqlite (4 pre-existing PG-shaped skips).

## IMPORTANT #1 — type-change drift: **CLOSED**, with one new minor

Re-derivation lives in `PaymentRepositoryController::update()` (`:174-178`),
reading `array_key_exists()` on the validated payload before mass-assignment, and
runs *before* the `gl_account_id` branch split so both update paths carry it
(`defaultAccountIdToGlAccountId()` at `:325-343` preserves unrelated keys —
verified). Their rejection of the model `updating` hook is sound: `isDirty()`
genuinely cannot separate "explicitly re-sent the current value" from "never
sent", and `PaymentRepository::booted()` now documents that instead of
silently omitting it. `defaultAllowNegativeForType()` (`:169-172`) is the single
derivation source shared by hook and controller. ✅

**Probed all four PATCH scenarios end-to-end through the real route:**
bank→cash_register without `allow_negative` → `false` ✅; cash_register→bank →
`true` ✅; type change **with** explicit `allow_negative: true` on a
cash_register → stays `true` ✅; PATCH of an unrelated field only (`name`) →
unchanged ✅.

**[minor — NEW, latent, non-blocking] `PaymentRepositoryController.php:174`** —
the predicate is *"`type` is present in the payload"*, not *"`type` changed"*.
Probed and reproduced: a `safe` created with an explicit `allow_negative: true`,
then PATCHed with `{name: '…', type: 'safe'}` (type **unchanged**, no
`allow_negative`), silently reverts to `false`. Any client that PATCHes the whole
form object — the normal React-form pattern — will clobber a deliberate treasury
override on every unrelated save.
Not a blocker today: the only repository PATCH in the web app sends
`gl_account_id` alone (`apps/web/src/features/treasury/RepositoryDetailPage.tsx:136`),
and nothing else in the repo sends `type`. But the write path was just added so
that overrides can exist, and the FE editor is explicitly ticketed — this will
bite the moment it lands.
Fix (one line): `array_key_exists('type', $validated) && $validated['type'] !== $repository->type->value && ! array_key_exists('allow_negative', $validated)`.

## IMPORTANT #2 — write path: **CLOSED**
`allow_negative` is now `['sometimes', 'boolean']` in both `store()` (`:81`) and
`update()` (`:152`), and `store()` spreads the key only when present (`:105-108`)
so an omitted value still reaches the creating hook as genuinely unset. Explicit
value wins over derivation in both. Covered by
`test_can_create_cash_register_with_allow_negative_explicitly_honored`,
`test_update_can_flip_allow_negative`, plus the two type-change cases.

## IMPORTANT #3 — null create response: **CLOSED**
`PaymentRepository::booted()` (`:120-127`) now assigns in both branches
(`$repository->allow_negative = $typeEnum !== null && self::defaultAllowNegativeForType($typeEnum)`).
My original failing probe (`assertFalse($repo->allow_negative)` on a freshly
`create()`d cash register, which previously failed with *"null is false"*) now
**passes without `->fresh()`**. Create response and subsequent GET agree.

## IMPORTANT #4 — RefundCompensationService: **CLOSED, and worse than admitted**
`allowNegative: true` at `RefundCompensationService.php:324`, correctly justified
as the replay class per the orchestrator ruling.

`tests/Feature/Fiscal/RefundCompensationControllerTest.php` — 14/14 green.

I independently verified the regression claim by temporarily deleting the single
`allowNegative: true` line and rerunning: **7 of 14 tests fail**, all with
*"Repository … has insufficient balance (0.000 EUR) to record an outflow of
20.00 EUR"* (e.g. `:499`). Their commit body understates it as "would have
422'd" — the prior round's enforcement commit had in fact broken the majority of
the refund-compensation feature, and it went unnoticed purely because that
round's reruns were `tests/Feature/Treasury`-scoped. File restored; worktree
clean. This is the strongest single argument for the wider rerun scope they
adopted.

## IMPORTANT #5 — alert wording: **CLOSED**
`TreasuryMovementService.php:185-203` now states the accurate, narrow claim
(never emitted *in this port*), names the six live `TreasuryAlertNotification`
sites, and records `Log::warning` as a deliberate hot-path choice with a separate
ticket. Correct as written.

## minor — deposit-bridge comment: **CLOSED**
`TreasuryDepositBridge.php:274-286` now says plainly that
`! isServerOnly()` is constant-false on that bridge and that the expression is an
inert shape-matching placeholder, not defense in depth. Accurate.

## Pre-deploy SQL — sanity check
```sql
SELECT id, code, type, balance
FROM payment_repositories
WHERE balance < 0 AND allow_negative = false;
```
Read-only, correct, per-tenant-DB. Two notes for the runbook:
- It is labelled "pre-deploy" but `allow_negative` does not exist until the
  migration runs, so it is a **post-`tenants:migrate`** check. The genuinely
  pre-deploy equivalent is `WHERE balance < 0 AND type <> 'bank_account'`.
- The stated remedy (an `in` adjustment via
  `POST /payment-repositories/{id}/adjustments`) can itself 422 on a tenant whose
  chart lacks the tolerance account — cf.
  `RepositoryAdjustmentTest::test_in_adjustment_returns_422_when_chart_lacks_payment_tolerance_account`.
  Worth one line in the runbook.

## Test evidence I produced (not taken on trust)
- **Postgres mode** (`-c phpunit-pgsql.xml`, live local PG:5433) — **98 tests,
  347+129 assertions, 0 failures, 0 skips**:
  `TreasuryMovementServiceTransferTest` + `TreasuryMovementServiceRecordTest`
  (31, incl. every savepoint/GUC/lock-order case that skips on sqlite), and
  `RepositoryTransferEndpointTest` + `RepositoryTransferServiceTest` +
  `PaymentRepositoryTest` + `RepositoryAdjustmentTest` +
  `RefundCompensationControllerTest` (67).
- **sqlite** — transfer trio 33 green; `PaymentRepositoryTest` +
  `PaymentRepositorySpineColumnsTest` 30 green; `RefundCompensationControllerTest`
  14 green.
- **PHPStan level 8** — clean on all five fix-round app files.
- Two throwaway probes (transfer refusal + PATCH semantics) written, run, deleted.

## OPEN AFTER MERGE (none blocking)
1. `[minor]` tighten the `update()` re-derivation predicate to "type **changed**"
   before the FE `allow_negative` editor ships.
2. Runbook: make the negative-balance query a post-migrate step; note the
   chart-of-accounts precondition on the `in`-adjustment remedy.
3. Already ticketed by the implementer: FE surface for `allow_negative`;
   `TreasuryAlertNotification` upgrade for the negative-balance alert;
   `BankStatementAggregateSchemaTest`'s hardcoded `--step 6` window.
