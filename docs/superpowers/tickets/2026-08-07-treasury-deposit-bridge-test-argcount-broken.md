# Ticket: `TreasuryDepositBridgeTest` is broken on `dev` (ArgumentCountError)

**Filed:** 2026-08-07, by the R2-K-prev deposit-preflight lane (out-of-lane finding).
**Severity:** MEDIUM — no production impact, but it silently disables the regression
guard for a fiscal-integrity invariant that R2-K-prev depends on.
**Status:** OPEN.

## Symptom

`tests/Feature/Fiscal/TreasuryDepositBridgeTest.php` fails wholesale on `dev`
@ `264e6c483` — **5 errors, 4 failures** — with:

```
ArgumentCountError: Too few arguments to function
App\Modules\Treasury\Application\Projections\TreasuryDepositBridge::__construct(),
3 passed in tests/Feature/Fiscal/TreasuryDepositBridgeTest.php on line 300
and exactly 4 expected
```

## Cause

The test's local bridge factory at `TreasuryDepositBridgeTest.php:300` constructs the
bridge with three collaborators. `TreasuryDepositBridge::__construct()`
(`TreasuryDepositBridge.php:68-73`) takes **four** since the maturity-tender work added
`HandlesMaturityTenderLeg`:

```php
public function __construct(
    private readonly CanonicalPayloadReader $canonicalReader,
    private readonly PaymentAllocationService $allocationService,
    private readonly TreasuryMovementServiceInterface $movementService,
    private readonly HandlesMaturityTenderLeg $maturityLegHandler,   // <- not passed
) {}
```

The test hand-rolls the bridge instead of resolving it from the container, so the
container's autowiring never gets a chance to supply the new dependency.

## Why this matters beyond a red test

The file contains `test_bridge_fails_loud_when_actor_lacks_company_membership` — the
**post-seal counterpart of R2-K-prev's V2 pre-flight**. It currently asserts nothing: it
expects a `RuntimeException` and receives an `ArgumentCountError` before the bridge is
ever exercised. The same applies to `test_bridge_fails_loud_when_repository_missing`.

So the invariants the deposit pre-flight mirrors are, at the bridge end, unguarded by
tests right now. Any change to `resolveActorUserId()` / `resolveRepository()` would go
undetected — and a divergence between the bridge and
`DepositReferenceResolutionService` is exactly the failure mode that service exists to
prevent.

## Verification

```bash
cd apps/api
git stash && ./vendor/bin/phpunit tests/Feature/Fiscal/TreasuryDepositBridgeTest.php
```

Reproduced independently on `dev` and on the R2-K-prev branch, byte-identical
(5 errors / 4 failures both times), which is how the lane established it as
pre-existing rather than a regression.

## Suggested fix

Resolve the bridge from the container (`$this->app->make(TreasuryDepositBridge::class)`)
the way `PosSiblingBridgesMaturityTest` already does, rather than `new`-ing it — that
makes the test immune to future constructor changes. Then confirm the two
`fails_loud_*` tests actually go red when their guards are removed, since they have not
been proven to fail for the right reason since the constructor changed.

## Related

- `docs/superpowers/tickets/2026-08-05-deposit-residual-seal-before-resolve-vectors.md`
  (R2-K-prev; the pre-flight whose bridge-side counterpart this test covers)
