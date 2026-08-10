# Ticket — `tests/Unit/Accounting/AccountEntityTest` fails when run after `ChartOfAccountsServiceTest`

**Raised by:** DPA `DPA-REV2-A` fix round 1 (2026-08-10), while adding `tests/Unit/`
to the lane's regression sweep per code-gate finding I-A.
**Class:** pre-existing test-ordering artifact. **Not** a `DPA-REV2-A` defect.
**Status:** OPEN

## Symptom

```
FAILED Tests\Unit\Accounting\AccountEntityTest > account has required properties
BindingResolutionException: Unresolvable dependency resolving
  [Parameter #0 [ <required> string $storedEventRepository ]]
  in class Spatie\EventSourcing\StoredEvents\EventSubscriber
```

Only in a **mixed run**. Each suite is green on its own:

```
tests/Unit/Accounting/            -> 38 passed
tests/Feature/Accounting/ChartOfAccountsServiceTest.php -> 18 passed
both, in one invocation           -> 1 failed
```

## Proven pre-existing

Verified by substituting the BASE version of the Feature test:

```bash
git show ed6fe896f:apps/api/tests/Feature/Accounting/ChartOfAccountsServiceTest.php \
  > apps/api/tests/Feature/Accounting/ChartOfAccountsServiceTest.php
php artisan test tests/Feature/Accounting/ChartOfAccountsServiceTest.php tests/Unit/Accounting/
  -> Tests: 1 failed, 53 passed   # IDENTICAL failure at base
```

So the pairing, not this lane's two added tests, is what triggers it.

## Mechanism (hypothesis — needs confirming)

`AccountEntityTest` is a plain unit test that does not boot the full application the
way a `RefreshDatabase` feature test does. Once a feature test has run in the same
process, Spatie's event-sourcing `EventSubscriber` is resolved from a container whose
`event-sourcing` config binding is no longer in the state the unit test expects.
Ordering-dependent container state, not a product defect.

## Why it matters more than a normal flake

The lane's fix-round-1 regression sweep now includes `tests/Unit/` (the omission of
which was code-gate finding I-A — a red suite reported as green). This artifact means
the combined sweep cannot be run as ONE invocation without a spurious failure, so the
declared command set has to split it. That is a papercut that will keep costing
reviewers time until it is fixed.

## Candidate fixes

1. Make `AccountEntityTest` resolve what it needs explicitly, or extend the same base
   `TestCase` the feature tests use.
2. Bind a null/array `storedEventRepository` in the testing config so the subscriber
   always resolves.
3. Mark the unit suite `@runInSeparateProcess` (heaviest, slowest).

Option 1 or 2. Check the sibling entity tests for the same fragility:

```bash
grep -rln "EventSubscriber\|event-sourcing" apps/api/tests/Unit/
```
