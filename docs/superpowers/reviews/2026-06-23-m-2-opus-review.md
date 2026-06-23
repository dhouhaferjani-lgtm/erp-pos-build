# M-2 — Financial Mutation Audit Policy — Opus Adversarial Review

Date: 2026-06-23
Reviewer: Opus 4.8 (1M) — adversarial cross-model pass (post-merge, Codex-only prior review)
Commit: `45b9f1720` — "Phase 0.1.19: Classify financial mutation audit events"
Item: M-2 (work-list `docs/superpowers/audits/2026-06-22-balance-conventions-audit/work-list.md:341`)

## Summary

The implementation is small, correct, and genuinely red-first verified. It subscribes two
previously-unaudited treasury domain events (`PaymentAllocated`, `ReconciliationCompleted`)
to the compliance audit subscriber and persists them as `Payment` / `BankReconciliation`
audit rows, matching the established audit-only pattern. No money/precision, sign-convention,
event-immutability, or migration issues were introduced (this commit contains no migration).
The main weakness is that the "classification" required by the acceptance criteria exists only
as a **test-local array fixture** with a **tautological assertion**, not as durable production
policy — and a production classification mechanism (`FiscalEventCoveragePolicy`) already exists
for the adjacent fiscal layer. This is a maintainability/enforcement-strength concern, not a
fiscal/data-integrity defect. Verdict: APPROVE-WITH-MINOR-EDITS.

## BLOCKER

None.

- No float cast or `number_format((float)...)` introduced. Audit payloads pass money fields
  through as strings verbatim (`PaymentAllocated.php:46-56`, `ReconciliationCompleted.php:37-46`).
- No event renamed/restructured/deleted; both events are reused as-is and explicitly marked
  immutable (`ReconciliationCompleted.php:13` "Immutable — never modify once deployed").
- No migration in this commit → no PG-CHECK / backfill / column-widen risk for M-2.
- Hash-chain integrity untouched (audit rows are a separate append-only trail; fiscal chain
  not modified).

## HIGH

None. Each load-bearing claim was independently verified:

- Both events are actually dispatched in production, so audit rows really persist:
  `PaymentAllocationService.php:355` (`event(new PaymentAllocated(...))`, after the
  `DB::transaction` closure returns) and `BankReconciliationService.php:208-218`
  (`DB::afterCommit(fn() => event(new ReconciliationCompleted(...)))`). Both are
  transactionally safe — no audit-row leak on rollback.
- Refund/reversal "already covered" claim is true: handlers + subscription entries exist at
  `DomainEventSubscriber.php:311,329,1025,1026`, and the events are dispatched at
  `PaymentRefundService.php:139,219,312,541`.
- Subscriber is registered (`ComplianceServiceProvider.php:83` `Event::subscribe(...)`), so the
  test's real `event()` dispatch genuinely exercises the handler.
- Red-first confirmed by refutation: removing the two new subscription lines and re-running
  `--filter 'financial_mutation_event_policy|payment_allocated_event_creates_audit_entry|reconciliation_completed_event_creates_audit_entry'`
  produced 3 failures ("Failed asserting that null is not null"). Restoring made them pass
  (3 passed, 20 assertions). File confirmed restored to committed state.
- Allocation payload shape matches the event contract `array{document_id, amount}`
  (`PaymentAllocationService.php:186-188,514-516,535-537`).

## MEDIUM

1. **The required "classification" is a test-local fixture, not production policy, and the
   classification assertion is tautological/dead.**
   `DomainEventSubscriberTest.php:55-61` declares a private static array mapping the four events
   to the literal string `'audit-only'`, and the enforcement test asserts:
   ```php
   $this->assertSame('audit-only', $policy);            // tautological — compares the literal to itself
   $this->assertArrayHasKey($eventClass, $subscriptions); // the only real assertion
   ```
   The `assertSame` line can never fail (it checks a hardcoded constant against the value it was
   just defined with) and adds zero enforcement value. The only durable thing the test enforces is
   "these four classes appear in `subscribe()`". The acceptance criterion is *"Each mutation is
   classified as fiscal, audit-only, ledger-only, or reserved"* — but the classification has no
   production representation; if the audit-only decision were ever wrong, no production code or
   meaningful test would catch it. Notably, a production classification mechanism already exists
   for the sibling layer: `FiscalEventCoveragePolicy` (`apps/api/app/Modules/Fiscal/Application/Services/FiscalEventCoveragePolicy.php:16-63`)
   with `PROJECTED` / `AUDIT_ONLY` / `RESERVED_UNREACHABLE` constants. M-2's treasury domain events
   are not `FiscalEventType` cases so they cannot drop into that class directly, but the asymmetry
   (fiscal vocabulary has a durable policy object; treasury financial-mutation domain events get a
   throwaway test array) means the "policy" deliverable is weaker than the work-list outcome text
   implies. Recommend either dropping the dead `assertSame` or — better — promoting the
   audit-only classification to a small production policy/registry the subscriber consults, so the
   classification is enforced by something other than a hand-maintained test array.

## LOW

1. **Inconsistent audit-accessor method naming across sibling events.** `PaymentAllocated`
   exposes `getAuditData()` (`PaymentAllocated.php:46`) while `ReconciliationCompleted` exposes
   `getAuditPayload()` (`ReconciliationCompleted.php:37`), and the two new handlers call them
   respectively (`DomainEventSubscriber.php`). Both work; the divergent naming is a minor
   maintainability nit for a freshly-wired pair.

2. **Audit rows are not idempotent on repeated event delivery.** `AuditService::record()`
   (`AuditService.php:44-57`) always inserts; a re-delivered `PaymentAllocated` /
   `ReconciliationCompleted` would create duplicate audit rows. This is the pre-existing behavior
   for *every* audit event (e.g. `PaymentRecorded`), so it is not an M-2 regression — noted only
   so it is not mistaken for new idempotency in an "audit-only policy" item.

3. **Pre-existing (out-of-scope) no-arg `getScale()` now feeds an audit payload.**
   `BankReconciliationService.php:205` computes `matchedTotal` with bare
   `$this->scaleResolver->getScale()`; per CLAUDE.md rule 19 a no-arg `getScale()` throws in
   queued/console/transition contexts. The resolver is correctly constructor-injected
   (`BankReconciliationService.php:19-20`), and this code predates M-2 (the commit only adds the
   subscriber + tests), so it is not introduced here — but M-2 now persists the resulting value,
   so if reconciliation is ever moved to a worker the no-arg call is a latent throw. Track under
   M-3 or a precision-contract follow-up, not as an M-2 blocker.

## Test Quality

- Real `event()` dispatch + `RefreshDatabase` + real `AuditEvent` query — no `Event::fake()`,
  no mocked audit service. Asserts company scope, aggregate type, and payload contents.
- Red-first independently reproduced (see HIGH). The two audit-entry tests are meaningful.
- The policy test's `assertSame` is dead (see MEDIUM-1); its `assertArrayHasKey` is the only
  real guard.

## Verdict

APPROVE-WITH-MINOR-EDITS

The code change is correct, minimal, transactionally safe, immutability-respecting, and genuinely
red-first. No fiscal/money/data-integrity defect and no migration risk. The edits worth making are
non-blocking: remove or strengthen the tautological policy assertion and consider promoting the
audit-only classification to a durable production artifact (mirroring `FiscalEventCoveragePolicy`)
so the acceptance criterion is enforced by production code rather than a hand-maintained test array.
