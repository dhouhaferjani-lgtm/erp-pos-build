# Ticket — `InstrumentEventsImmutabilityTest::event_and_typed_payload_round_trip` is key-order coupled

**Raised by:** DPA `DPA-REV2-A` (customer-advance reversal, Path A), 2026-08-10
**Class:** pre-existing, environment-coupled test defect. **Not a product defect.**
**Ruling applied:** same as P-1 — the plan's byte-identical preserve list (§12.6) wins;
the test was **NOT** touched by the lane that found it.
**Status:** OPEN — needs its own ruling before anyone edits the file.

## Symptom

```
FAILED Tests\Feature\Treasury\InstrumentEventsImmutabilityTest > event and typed payload round trip
Failed asserting that two arrays are identical.
 Array &0 [
     'details_diff' => Array &1 [
         'reference' => Array &2 [
 +            'new' => 'CHK-001',
             'old' => null,
 -            'new' => 'CHK-001',
         ],
     ],
```

The array **contents** are identical. Only the **key order** of the nested
`details_diff.reference` map differs (`old, new` vs `new, old`).

## Mechanism

`InstrumentEventsImmutabilityTest.php:54`:

```php
$this->assertSame($payload->toArray(), InstrumentEventPayload::fromArray($event->payload)->toArray());
```

`assertSame()` on arrays compares **order as well as contents**. The payload makes a
round trip through a JSONB column, and the key order that comes back is not guaranteed
to match the order that went in. The assertion is therefore coupled to
serialization/driver behaviour rather than to the immutability property it is named for.

## Evidence it is pre-existing

Verified directly rather than inferred. With the lane's two A9 production files reverted
to `HEAD` (`InstrumentLifecycleService.php`, `GeneralLedgerService.php`) the test fails
**identically**:

```
=== at HEAD (pre-A9) ===
  Tests:    1 failed (4 assertions)
```

Neither file touches `InstrumentEventPayload`, `details_diff`, or any JSON
serialization path.

## Why it was not fixed in place

`InstrumentEventsImmutabilityTest.php` is on the `DPA-REV2-A` plan's **byte-identical
preserve list** (§12.6). Same reasoning as ticket
`2026-08-10-deferredtenderguards-tz-coupling.md`.

## Candidate fixes (for whoever picks this up)

1. `assertEquals()` instead of `assertSame()` — order-insensitive for arrays, still
   strict about contents and nesting. Smallest change, and it tests the property the
   test is actually named for.
2. `ksort()` both sides recursively before comparing — explicit about intent, more code.
3. Give `InstrumentEventPayload::toArray()` a canonical key order and assert that
   ordering is stable. Strongest, but it turns key order into a contract, which may not
   be wanted.

Option 1 is preferred: the test's stated subject is payload **immutability / round-trip
fidelity**, not JSON key ordering.

## Blast radius to check first

```bash
grep -rn "assertSame(\$payload->toArray()" apps/api/tests/
grep -rn "assertSame(.*toArray(), .*fromArray" apps/api/tests/
```
