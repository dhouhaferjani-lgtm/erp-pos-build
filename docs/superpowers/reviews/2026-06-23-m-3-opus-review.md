# M-3 — Fiscal Event Coverage Policy — Opus Adversarial Review

Date: 2026-06-23
Reviewer: Opus 4.8 (cross-model adversarial pass, fills the `opus-review: PENDING` gate)
Commit: `5835bed9d` — "Phase 0.1.20: Add fiscal event coverage policy"
Item: M-3 (work-list.md), MEDIUM
Claim under review: "`FiscalEventCoveragePolicy` classifies every `FiscalEventType`; tests cross-check payload registry/validator/projectors/reader. No event contracts renamed."

## Summary

The change is read-only and low-risk: one new final policy class
(`FiscalEventCoveragePolicy`) holding a hand-maintained classification map
(`projected` / `audit-only` / `reserved-unreachable`) over the 35
`FiscalEventType` cases, plus a 4-test unit suite that cross-checks the map
against the payload registry, the per-event validator, the registered
projectors, and the canonical reader. No production behavior, money math,
migration, GL posting, hash-chain, or event contract is touched. The diff is
purely additive (2 source/test files + docs).

I independently verified the classification against the actual code, not just
the diff:
- The 23 non-reserved (`projected` ∪ `audit-only`) cases are exactly the 23
  entries in `FiscalEventPayloadRegistry::PHASE_1_MAP` and exactly the 23 match
  arms in `FiscalPayloadConstraintValidator::validatePerEventConstraints()`.
- The 13 `projected` cases map exactly onto the union of event types handled by
  the registered projectors (`PosCoreReceiptProjection`,
  `AccountPaymentReceiptProjection`, `AccountChargeReceiptProjection`,
  `DepositReceiptProjection`, `ZReportProjection`, `ZSessionLifecycleProjection`,
  and the Treasury/Document bridges). The `audit-only` cases have no projector.
- The 4 canonical reader methods listed
  (`forSaleReceipt`/`forAccountPayment`/`forAccountCharge`/`forDepositReceipt`)
  all exist on `CanonicalPayloadReader`.
- The 12 `reserved-unreachable` cases are disjoint from the registry and the
  registry throws `FiscalEventTypeNotImplemented` for them.

I re-ran the new test (`4 passed, 122 assertions`), PHPStan L8 on the new class
(`No errors`), and Pint (`pass`). The "no event contracts renamed" claim is
confirmed by the diff — no enum case, DTO, or event class is renamed,
restructured, or deleted.

The core claim holds. The weakness is in one of the four tests, which is closer
to a tautology than to a genuine guard, plus a couple of drift gaps the "single
matrix" framing implies it covers but does not. None rise to blocking.

## BLOCKER

None. No fiscal/money/data-integrity logic changed; no migration; no event
mutation. Money/precision, sign-convention, GL-correctness, and
migration-safety lenses are all not-applicable to this diff (it contains no
arithmetic, no casts, no SQL).

## HIGH

None.

## MEDIUM

### M-3.1 — The validator cross-check is near-tautological (false confidence)
`tests/Unit/Fiscal/FiscalEventCoveragePolicyTest.php:46-58`:

```php
try {
    $validator->validatePerEventConstraints($type, [], eventVersion: $eventVersion);
} catch (LogicException $exception) {
    $this->assertStringNotContainsString('missing per-event clause', $exception->getMessage(), ...);
} catch (\Throwable) {
    // Empty payloads should fail field validation for implemented events.
}
```

For every implemented type the empty payload `[]` falls into a real `match`
arm, which throws a `RuntimeException` (field validation) caught by the
broad `\Throwable` arm — so **no assertion executes** on the normal path. The
`LogicException` arm only fires from the validator's `default => throw`, which
is unreachable for any type already present in `PHASE_1_MAP` (the same set the
test's preceding `assertTrue($registry->isImplemented($type))` requires). The
intended invariant ("an implemented event cannot silently lack a validator
clause") is therefore not actually exercised by this catch block — it would
still pass if the coupling were broken, because the registry assertion and the
validator share the same membership set by construction. The genuine guard here
is the registry `assertTrue`/`assertFalse` pair, which does hold. Recommend
asserting positively that `validatePerEventConstraints` does NOT throw a
`missing per-event clause` `LogicException` for every implemented type (e.g. by
catching only `LogicException` and failing on that substring, with the
`RuntimeException` path explicitly allowed), rather than swallowing all
`Throwable`.

### M-3.2 — Matrix does not reconcile against the enum's own `isImplemented()`
`FiscalEventType.php:45-72` carries a SECOND hand-maintained "implemented" list
(`isImplemented()`), and `FiscalEventPayloadRegistry::PHASE_1_MAP` is a THIRD.
The new policy cross-checks the registry but not the enum method. Today all
three agree (I verified the enum method and the registry both yield the same 23
cases), but the item's framing is "a single matrix proving enum, registry,
validator, projector, and reader policy" — the enum-method leg is unproven. A
future edit that adds a case to the registry/policy but forgets
`FiscalEventType::isImplemented()` (or vice-versa) would not be caught by this
suite. Add an assertion that `policy->isReservedUnreachable($type) ===
!$type->isImplemented()` to close the third source of truth.

### M-3.3 — Canonical-reader test only guards one direction
`FiscalEventCoveragePolicyTest.php:79-99` asserts every reader method the policy
*names* exists on `CanonicalPayloadReader`. It does not assert the reverse: a
new `for*` reader method added to `CanonicalPayloadReader` without a matching
`CANONICAL_READER_METHODS` entry would go unnoticed, so "drift" is only detected
in one direction. Low blast radius (readers are not on the fiscal write/chain
path), but it undercuts the "matrix proves reader policy" claim.

### M-3.4 — "Opus review" was a Codex fallback, not cross-model
`docs/superpowers/reviews/2026-06-22-m3-...-opus-fallback-review.md` is authored
by "Codex, second independent pass" and the progress log records
`opus-review: PENDING`. The merge proceeded on Codex-only review. This Opus pass
fills that gate and finds the implementation substantively correct; flagging for
process visibility, not as a code defect.

## LOW

### L-1 — `policyFor()` has no missing-key guard
`FiscalEventCoveragePolicy.php:75-78`: `return self::POLICIES[$type->value];`
relies on total coverage. If a new enum case were added, this would emit an
undefined-array-key error rather than a typed failure — but the `all()` test
(`test_every_fiscal_event_type_has_explicit_policy`) catches the missing case
first, and PHPStan L8 passes on the declared `array<value-of<...>, ...>` shape,
so this is defensive nit only.

### L-2 — String consts instead of an enum for the classification
`PROJECTED` / `AUDIT_ONLY` / `RESERVED_UNREACHABLE` are class string constants.
CLAUDE.md rule 9 ("Enums for all status/type") would suggest a backed enum for
this 3-state classification. The PHPStan literal-union typing mitigates the
risk; cosmetic.

## Verdict

APPROVE-WITH-MINOR-EDITS.

The classification is internally consistent and independently verified against
the live registry, validator, projector set, and reader; the central
cross-check (reserved vs. implemented against the payload registry) is a genuine
guard; no event contract is renamed; no money/migration/GL surface is touched;
PHPStan L8, Pint, and the new test all pass on re-run. The MEDIUM items are
test-strength and drift-coverage improvements (especially M-3.1 and M-3.2) that
should be folded in to make the matrix live up to its "single proof" framing,
but none of them indicate the shipped classification is wrong or unsafe. Safe to
keep on `dev`; address M-3.1/M-3.2 as a fast follow.
