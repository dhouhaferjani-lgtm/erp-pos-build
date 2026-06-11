REQUEST-CHANGES

## BLOCKER

None found.

## P1

### P1-1: `FiscalEventProjectionDispatcher::dispatch()` is not idempotent, despite the spec and class doc claiming `(fiscal_event_id, projector_name)` idempotency is reused

- Location: apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionDispatcher.php:60, apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionDispatcher.php:82
- Constraint: apps/api/database/migrations/tenant/2026_05_14_100003_create_fiscal_event_projections_table.php:58
- Contrast: apps/api/app/Modules/Fiscal/Infrastructure/Commands/EnqueueResolvedEventProjectionsCommand.php:241

Concrete repro:
1. Bind one active `DEPOSIT_RECEIPT` projector, as `FiscalEventProjectionDispatcherTest::test_dispatch_inserts_one_pending_row_per_active_projector_and_enqueues_a_job()` does.
2. Persist a `DEPOSIT_RECEIPT` fiscal event.
3. Call `app(FiscalEventProjectionDispatcher::class)->dispatch($event)` once. It inserts `(event_id, fake_deposit_receipt)`.
4. Call `dispatch($event)` a second time. Line 82 runs a plain bulk `insert($pendingRows)` against the unique key declared at migration lines 58-63, so the second call raises a duplicate-key `QueryException`.

That is not harmless idempotency. It makes a replay/recovery call fail at the fiscal projection boundary, and if the first call committed projection rows but failed before queue dispatch was durably handed to Horizon, a second call cannot be used to re-enqueue the existing pending rows.

Fix:
- Use the same conflict-target pattern as `EnqueueResolvedEventProjectionsCommand::insertOnConflictDoNothing()`:
  - PostgreSQL: `ON CONFLICT ON CONSTRAINT fiscal_event_projections_event_projector_unique DO NOTHING`
  - SQLite/portable: `ON CONFLICT (fiscal_event_id, projector_name) DO NOTHING`
- After insertion, query existing `pending` rows for the event and dispatch those row IDs, rather than only dispatching IDs generated in the current call. That makes both "create missing rows" and "re-enqueue pending rows" replay-safe.
- Add a test that calls `dispatch($event)` twice and asserts no exception, one projection row per projector, and sensible pending-row dispatch behavior.

### P1-2: `appendDepositReceipt()` silently truncates over-precise amounts before sealing the fiscal event

- Location: apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:217
- Helper behavior: apps/api/app/Shared/Domain/CurrencyScale.php:85, apps/api/app/Shared/Domain/CurrencyScale.php:108, apps/api/app/Shared/Domain/CurrencyScale.php:151
- Spec contract: docs/superpowers/specs/2026-06-08-customer-account-deposit-topup-phase5-design.md:152

Concrete repro:
1. Call `appendDepositReceipt(..., currencyCode: 'EUR', amount: '10.009', ...)`.
2. `CurrencyScale::for('EUR')` gives scale 2.
3. Line 218 calls `CurrencyScale::bcformat('10.009', 2)`.
4. `bcformat()` delegates to `bcadd($str, '0', $scale)`, which truncates to `10.00`.
5. The validator sees `10.00`, passes the money regex, and the event is sealed with less money than the caller supplied.

The same issue applies to any extra non-zero fractional precision: `10.999` becomes `10.99`. For a fiscal receipt, silent truncation is not acceptable. The event hash then permanently commits the truncated amount.

Fix:
- Validate the caller amount before formatting:
  - require a well-formed numeric string;
  - reject fractional precision greater than `CurrencyScale::for($currencyCode)` unless every extra digit is zero;
  - reject negative and zero before/after normalization.
- Then format to fixed scale for canonical output.
- Add tests for `EUR amount='10.009'` and `TND amount='12.9999'` rejecting rather than truncating.

## P2

### P2-1: The public dispatcher relies on a server-authored/Verified/Parsed precondition but does not enforce it

- Location: apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionDispatcher.php:60, apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionDispatcher.php:62, apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionDispatcher.php:82
- Device suppression reference: apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:918
- Server-only enum helper: apps/api/app/Modules/Fiscal/Domain/Enums/FiscalEventType.php:74

Concrete repro:
1. Persist a device-authored `SALE_RECEIPT` with `integrity_status=quarantined`, `integrity_exception_class=canonical_parse_failure`, `payload=null`, `payload_parse_status=failed`.
2. Call the public `FiscalEventProjectionDispatcher::dispatch($event)`.
3. If an active projector handles that event type, the dispatcher seeds projection rows because it never checks `event_type->isServerOnly()`, `integrity_status`, or `payload_parse_status`.

That bypasses the exact canonical-parse-failure suppression that `OutboxIngestor::dispatchProjections()` applies at lines 918-920. The design note says suppression can be dropped because server-authored events are always `Verified`/`Parsed`; the method needs to make that precondition true at the boundary.

Fix:
- Fail fast unless `$event->event_type->isServerOnly()` is true.
- Fail fast unless `integrity_status === IntegrityStatus::Verified` and `payload_parse_status === PayloadParseStatus::Parsed`.
- Add a misuse test proving quarantined/failed device events are rejected and do not create projection rows.

### P2-2: `appendDepositReceipt()` can commit a fiscal event without projection rows unless every caller wraps append + dispatch in an outer transaction

- Location: apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:181, apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:269, apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:301
- Separate dispatcher entry point: apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionDispatcher.php:60
- Intended Phase 5 flow: docs/superpowers/specs/2026-06-08-customer-account-deposit-topup-phase5-design.md:116, docs/superpowers/specs/2026-06-08-customer-account-deposit-topup-phase5-design.md:124, docs/superpowers/specs/2026-06-08-customer-account-deposit-topup-phase5-design.md:232

Concrete repro:
1. A caller invokes `appendDepositReceipt()` and gets back a persisted `FiscalEvent`; the method owns and commits its own transaction.
2. The process crashes, throws, or returns before calling `FiscalEventProjectionDispatcher::dispatch($event)`.
3. The fiscal chain row exists, but there are no `fiscal_event_projections` rows and therefore no printable receipt / treasury bridge execution.

The spec says the future `RecordCustomerDepositService` should call append then dispatch, and the dispatcher doc says callers should wrap both in one transaction. Phase 2 does not enforce that shape. This is survivable if Phase 5 pins it with tests, but this split is a real crash gap in the public API as written.

Fix:
- In Phase 5, wrap `appendDepositReceipt()` + `dispatch()` in one outer `DB::transaction()` and add a rollback test proving neither fiscal row nor projection rows commit when the orchestrator fails.
- Prefer exposing a single `recordDepositReceiptFiscalEventAndDispatch()` style application operation for this use case, or make the transaction requirement impossible to miss through type/API boundaries.

### P2-3: Unknown three-letter currency codes are accepted and sealed with default scale 2

- Location: apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:205, apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:217
- Scale helper: apps/api/app/Shared/Domain/CurrencyScale.php:62
- Validator: apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:458

Concrete repro:
1. Call `appendDepositReceipt(..., currencyCode: 'ZZZ', amount: '10', ...)`.
2. `CurrencyScale::for('ZZZ')` falls through to `DEFAULT_SCALE=2`.
3. The payload becomes `currency_code='ZZZ'`, `currency_scale=2`, `payment.amount='10.00'`.
4. The validator only checks `/^[A-Z]{3}$/`, so the event is accepted and sealed.

That lets a non-currency identifier into the fiscal chain. If the Phase 5 controller/orchestrator validates currency against company settings, this is mitigated at the caller, but the fiscal authoring service itself currently does not defend its canonical contract.

Fix:
- Validate `$currencyCode` against the app's supported currency set or company currency/payment currency configuration before calling `CurrencyScale::for()`.
- Add a test that bogus uppercase alpha-3 input is rejected at the authoring boundary.

## NIT

None.

## False-positive checks I ran

- Hash-chain placement: `appendDepositReceipt()` follows the shipped `appendAccountStatusChanged()` pattern: resolve virtual-admin terminal, lock `pos_terminals` with `lockForUpdate()`, resolve prior chain placement inside the lock, canonical-encode the envelope, compute `current_hash`, and persist `Verified`/`Parsed`. I did not find drift in sequence/previous-hash handling.
- Concurrency: because both server-authored append paths lock the virtual-admin terminal row before reading the prior fiscal event, parallel appends on the same terminal should serialize at the terminal lock. The DB unique key on `(tenant_id, company_id, terminal_id, chain_context, sequence_number)` remains a final guard.
- `event_time_device` precision: payload milliseconds (`Y-m-dTH:i:s.vZ`) and envelope seconds (`Y-m-dTH:i:sZ`) match the shipped account-status path and the validator/parser split.
- Canonical payload shape: the 16-key no-`seller` contract is consistent with `DepositReceiptPayload::PAYLOAD_KEYS`; the validator enforces exact nested `customer`/`payment` key sets, `customer.customer_id == partner_id`, `training_flag=false` via the authoring service, and fixed `"FIFO"`.
- I did not flag missing `chain_context` in server-authored canonical bytes. `appendAccountStatusChanged()` and `TerminalRegistrySnapshotService` use the same 14-key server-authored envelope shape; the strict parser's `chain_context` requirement is for device/wire canonical parsing.
- Targeted tests run: `php artisan test tests/Feature/Fiscal/DepositReceiptAppendTest.php tests/Feature/Fiscal/FiscalEventProjectionDispatcherTest.php --display-warnings` passed 47 assertions with 8 warnings caused by missing `apps/api/.env` (`file_get_contents(.../.env): Failed to open stream`).

---

## Resolution (Claude, 2026-06-09, round 1)

**P1-1 — dispatcher idempotency: FIXED.**
`FiscalEventProjectionDispatcher::insertOnConflictDoNothing()` now inserts pending
rows with `ON CONFLICT (fiscal_event_id, projector_name) DO NOTHING` (PG: named
constraint `fiscal_event_projections_event_projector_unique`; SQLite/portable:
column target), mirroring `EnqueueResolvedEventProjectionsCommand`. After insert it
re-reads the still-`pending` rows for the event and enqueues those row ids on
`afterCommit`, so both "create missing rows" and "re-drive pending rows" are
replay-safe. Test: `test_dispatch_is_idempotent_and_re_enqueues_pending_rows_on_replay`
(dispatch twice → no exception, 1 row/projector, 2 pushes for the same row id).

**P1-2 — silent amount truncation: FIXED.**
`VirtualAdminFiscalEventService::normalizeDepositAmount()` now validates the caller
amount BEFORE formatting: it must match a plain non-negative decimal regex, and
`bccomp($raw, bcformat($raw,$scale), $scale+6) === 0` must hold (rejecting any value
that loses precision at the currency scale). Over-precise amounts throw instead of
sealing a truncated value into the chain. Tests: EUR `10.009`, TND `12.9999`, and a
non-numeric `10,00` all reject; no fiscal event is created.

**P2-1 — dispatcher precondition not enforced: FIXED.**
`dispatch()` now fails fast (`InvalidArgumentException`) unless
`event_type->isServerOnly()` AND `integrity_status === Verified` AND
`payload_parse_status === Parsed` — making the "always Verified/Parsed" assumption
that justifies dropping device-path suppression a real, enforced boundary. Tests:
non-server-authored (`SALE_RECEIPT`) and quarantined/`Failed` events are rejected
with no projection rows.

**P2-2 — append/dispatch crash gap: DEFERRED to Phase 5 (by design).**
The Phase 2 deliverables are intentionally two separate components (spec §3 + flow
§4.1); the single-`DB::transaction()` orchestration of append-then-dispatch is the
job of Phase 5 `RecordCustomerDepositService` (spec lines 116/124/232). Merging them
into one method now would pre-empt that planned boundary. The dispatcher docblock now
states the transaction requirement explicitly; Phase 5 will add the rollback test
proving neither the fiscal row nor projection rows commit when the orchestrator fails.

**P2-3 — unknown currency → default scale 2: DEFERRED to Phase 5 (right layer).**
The authoritative defense is "validate the currency against company settings" — spec
§6 makes currency default to the company currency, and only the Phase 5
controller/orchestrator has that context. `CurrencyScale` has no usable
"known-currency" allowlist (EUR/USD/GBP aren't in its `SCALE_MAP`; they rely on the
default scale), so inventing one in the authoring service would be arbitrary and
risk rejecting valid currencies. The validator already pins `currency_scale ∈
{0,2,3}`. Phase 5 validates the currency code at ingress.

Gates after fixes: 14/14 new tests green (60 assertions); Pint pass; PHPStan L8 clean;
deptrac unchanged (0 new violations).
