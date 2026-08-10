# Ticket — `DeferredTenderGuardsTest::payment_api_exposes_dishonored_at` is timezone-coupled

**Raised by:** DPA `DPA-REV2-A` (customer-advance reversal, Path A), 2026-08-10
**Class:** pre-existing, environment-coupled test defect. **Not a product defect.**
**Ruling:** the plan's byte-identical preserve list (§12.6) wins — the test was **NOT**
touched by the lane that found it. Precedent: MTP-TRE-06.
**Status:** OPEN — needs its own ruling before anyone edits the file.

## Symptom

```
FAILED Tests\Feature\Treasury\DeferredTenderGuardsTest > payment api exposes dishonored at
Failed asserting that two strings are identical.
-'2026-08-09T23:48:09+00:00'
+'2026-08-09T23:48:09+01:00'
at tests/Feature/Treasury/DeferredTenderGuardsTest.php:689
```

The instant is identical; only the **UTC offset rendering** differs.

## Mechanism

`DeferredTenderGuardsTest.php:687-689` compares an API-serialised timestamp against
`$payment->dishonored_at?->toIso8601String()`:

```php
$this->actingAs($this->user)->getJson("/api/v1/payments/{$payment->id}")
    ->assertOk()
    ->assertJsonPath('data.dishonored_at', $payment->dishonored_at?->toIso8601String());
```

The two sides resolve their offset differently, so the assertion is coupled to the host
machine's timezone rather than to any product behaviour. It passes on a UTC host and
fails on a `+01:00` host.

## Evidence it is pre-existing

Found while running the regression sweep for `DPA-REV2-A` task A4. The branch at that
point (`ed6fe896f..7887c3093`) contained **no timezone or serialization code**:
`git diff ed6fe896f..HEAD -- apps/api/app` touches only
`GeneralLedgerService.php`, and only by ADDING one new method
(`reverseCustomerAdvanceJournalEntry()`). Nothing in the diff can reach payment
serialization.

Sweep result: `25 passed, 1 failed` across `PaymentGlPostingTest` +
`DeferredTenderPaymentTest` + `DeferredTenderGuardsTest`.

## Why it was not fixed in place

`DeferredTenderGuardsTest.php` is on the `DPA-REV2-A` plan's **byte-identical preserve
list** (§12.6, alongside `ReversalIdempotencyIndexTest`,
`InstrumentReversalCancellerHardeningTest`, `PaymentReversalNetLineageTest`,
`InstrumentClearTest`, `InstrumentBounceTest`,
`InstrumentEventsImmutabilityTest`). Editing it inside a lane that is also changing the
reversal money path is exactly the coupling the preserve list exists to prevent.

## Candidate fixes (for whoever picks this up)

1. Compare instants rather than rendered strings —
   `Carbon::parse($response->json('data.dishonored_at'))->equalTo($payment->dishonored_at)`.
2. Normalise both sides to UTC before comparing (`->utc()->toIso8601String()`).
3. Pin the suite timezone in `phpunit.xml` (broadest blast radius — would affect every
   time-sensitive test, so it needs its own review).

Option 1 is preferred: it tests the contract that actually matters (the instant the API
reports) and is immune to host configuration.

## Blast radius to check first

Grep for the same pattern before fixing — if other tests compare
`toIso8601String()` against a JSON path, they carry the same latent coupling and should
be swept together:

```bash
grep -rn "toIso8601String()" apps/api/tests/ | grep -i "assertJsonPath\|assertJson"
```
