REQUEST-CHANGES

## Round-1 Fix Verification

P1-1: NOT fully verified. The duplicate-key fix itself is correct: `insertOnConflictDoNothing()` targets the named PostgreSQL constraint and the portable `(fiscal_event_id, projector_name)` target for SQLite, and the rollback test proves rows plus `afterCommit` jobs are suppressed when the outer transaction rolls back. Re-reading persisted row IDs also handles the normal "prior dispatch inserted rows with different UUIDs" case. However, the replay re-read is filtered to the currently active projector names, which can strand an already-created pending row if module activation changes before replay. See P1 below.

P1-2: NOT verified. The regex accepts the intended plain non-negative decimal subset, and normal inputs such as `10`, `10.5`, `0.50`, `125.50`, and large integer strings are not false-rejected. But `bccomp($raw, $formatted, $scale + 6)` only compares through six guard digits beyond the currency scale. A non-zero digit beyond that window is still silently truncated. See P1 below.

P2-1: verified. The precondition guard runs before projector resolution or any insert, uses the enum-cast comparisons correctly, and rejects both non-server-authored event types and non-Verified/non-Parsed rows without writing projection rows.

P2-2 deferral: sound and bounded for Phase 2. There is still a real crash gap if a future caller invokes `appendDepositReceipt()` without dispatching in the same outer transaction, but there is no production caller in this phase, the dispatcher docblock now states the transaction requirement, and the Phase 5 `RecordCustomerDepositService` is the right boundary for the atomic append-plus-dispatch rollback test.

P2-3 deferral: sound and bounded for Phase 2. The fiscal authoring service still accepts unknown uppercase alpha-3 codes through `CurrencyScale::DEFAULT_SCALE`, but Phase 5 is the first layer with company/payment-currency context. Adding an allowlist here would be arbitrary because common scale-2 currencies intentionally fall through the default map. Phase 5 must validate ingress currency against company settings.

Hash-chain regression check: `appendDepositReceipt()` still matches the shipped `appendAccountStatusChanged()` pattern: resolve the virtual-admin terminal, lock `pos_terminals`, compute chain placement inside that lock, canonical-encode the same server-authored envelope shape, compute `current_hash`, and persist the event as `Verified`/`Parsed`.

Verification run: `php artisan test tests/Feature/Fiscal/DepositReceiptAppendTest.php tests/Feature/Fiscal/FiscalEventProjectionDispatcherTest.php --display-warnings` passed 14 tests / 60 assertions, with the existing missing `.env` warnings.

## BLOCKER

None.

## P1

### P1-1-R2: Dispatcher replay can skip existing pending rows after module activation drift

- Location: apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionDispatcher.php:112

The fix re-reads pending rows only where `projector_name` is in the currently active projector set:

```php
->whereIn('projector_name', $projectorNames)
->where('projection_status', ProjectionStatus::Pending->value)
```

That is replay-safe only when the active set is unchanged. If an earlier successful dispatch created a pending row for a gated projector, the transaction committed, but queue handoff was lost, a later replay after that module is disabled will not enqueue the existing pending row. This conflicts with the durable-row semantics already documented in `FiscalEventProjectionRegistry::byName()`: activation gating happens when the row is created, and a module deactivated between ingest and run must still be able to resolve and process its existing projection row.

This also diverges from `EnqueueResolvedEventProjectionsCommand::dispatchPendingRows()`, which dispatches all pending rows for the event after creating any missing active rows.

Fix: after the idempotent insert, re-read all still-`pending` rows for the event, not only rows whose projector is currently active. If preserving priority order matters, add an explicit ordering strategy; do not rely on physical row order.

### P1-2-R2: `normalizeDepositAmount()` still silently truncates non-zero precision beyond the six guard digits

- Location: apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:336

The `bccomp($raw, $formatted, $scale + 6)` guard does not reject all precision loss. It only detects differences visible at `scale + 6`. Examples that pass the regex and compare equal, but are truncated before sealing:

```text
EUR scale 2: raw 10.000000001 -> formatted 10.00, bccomp(..., 8) === 0
EUR scale 2: raw 0.500000001 -> formatted 0.50, bccomp(..., 8) === 0
TND scale 3: raw 12.9990000001 -> formatted 12.999, bccomp(..., 9) === 0
JPY scale 0: raw 1.0000001 -> formatted 1, bccomp(..., 6) === 0
```

That leaves the original fiscal risk intact for sufficiently small but non-zero excess precision: the caller supplies more money than the canonical event stores.

Fix: inspect the fractional string directly. For scale `s`, split on `.`, allow any missing fractional part, and if the fractional length exceeds `s`, require every digit after position `s` to be `0`. Then call `CurrencyScale::bcformat()` for canonical fixed-scale output. That rejects all non-zero excess precision without imposing an arbitrary comparison window.

## P2

None.

## NIT

None.

---

## Resolution (Claude, 2026-06-09, round 2)

Both new P1 refinements accepted and fixed.

**P1-1-R2 — replay stranded rows after activation drift: FIXED.**
`FiscalEventProjectionDispatcher::dispatch()` no longer filters the post-insert
re-read by the currently-active projector set, and no longer early-returns when the
active set is empty. It now re-reads ALL still-`pending` rows for the event (matching
`EnqueueResolvedEventProjectionsCommand::dispatchPendingRows()` and the durable-row
semantics of `FiscalEventProjectionRegistry::byName()`), so a row created while a
module was active is still re-driven on replay after that module is disabled. Test:
`test_dispatch_re_enqueues_an_existing_pending_row_even_if_its_projector_is_no_longer_active`
(dispatch with projector active → rebind registry to empty → dispatch again →
existing pending row re-enqueued; still one row).

**P1-2-R2 — windowed bccomp missed excess precision: FIXED.**
`normalizeDepositAmount()` no longer uses `bccomp($raw, $formatted, $scale+6)`. It now
inspects the fractional digits directly: after the plain-decimal regex, if the
fractional length exceeds the currency scale, every digit beyond the scale must be
`0` (trailing-zero padding is accepted); otherwise it throws before sealing. This
rejects ALL non-zero excess precision (e.g. EUR `10.000000001`, JPY `1.0000001`) with
no arbitrary comparison window. Tests: `...rejects_tiny_excess_precision_beyond_scale`
(EUR `10.000000001` → reject, no event) and
`...accepts_trailing_zero_padding_beyond_scale` (EUR `10.5000` → sealed `10.50`).

P2-2 / P2-3 deferrals were confirmed sound and bounded by the r2 review; unchanged.

Gates after r2 fixes: 17/17 new tests green (67 assertions); Pint pass; PHPStan L8
clean; deptrac unchanged (0 new violations); full Fiscal feature suite re-run below.
