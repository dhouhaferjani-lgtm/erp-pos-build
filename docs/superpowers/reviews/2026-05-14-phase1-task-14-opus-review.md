# Opus review — Phase 1 Task 14 (FiscalEventPayloadRegistry + DTOs)

**Commit:** `57b8fda9`
**Branch:** `feat/pos-fiscal-event-engine-phase1`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1`
**Reviewer:** Opus (headless adversarial review gate)
**Date:** 2026-05-16
**Verdict:** **APPROVE** — 0 BLOCKER, 0 P1, 2 P2, 2 P3.

## Summary

Task 14 lands cleanly. The server-side `FiscalEventPayloadRegistry` is a final
class with constructor-injectable surface, a static `PHASE_1_MAP` of
`[FiscalEventType::value → [class-string, int]]`, and three methods —
`dtoClassFor()`, `eventVersionFor()`, `isImplemented()` — that all agree on the
same four-element implemented set: `SALE_RECEIPT`, `CHAIN_BREAK_DETECTED`,
`CHAIN_RESTART`, `TERMINAL_REGISTRY_SNAPSHOT`. The exception
`FiscalEventTypeNotImplemented` carries the offending `FiscalEventType` case as
a readonly property, giving downstream debug visibility without leaking
implementation strings into the message.

The five DTOs are `final readonly` with constructor-promoted typed properties
and a `fromArray()` / `toArray()` pair. Monetary values are typed `string`
(no `float` anywhere — spec v7 §4 compliance verified); `currency_scale` is
`int`; `last_good_sequence` is `int`. Sub-arrays (`lines`, `vat_breakdown`,
`payment_lines`, `voucher_redemptions`, `terminals`, `last_good_anchor`,
`operator_authorization_evidence`, `provenance_link`, `offending_record_reference`)
are typed `list<array<string, mixed>>` or `array<string, mixed>` with a class
docblock comment documenting the expected shape; refinement is deferred to the
StrictCanonicalParser (Task 16) and the projection callers (Tasks 21-22), per
the explicit comment block on `SaleReceiptPayload`. `toArray()` returns the
snake_case canonical keys that match `fromArray()`'s input — round-trip
asserted lossless by the four `*_roundtrip` tests using `$this->assertSame($data, $dto->toArray())`.

`CompanyDayClosureManifestPayload` is the schema-only reserved DTO. Its
constructor is `private` (can never be instantiated externally) and both
`fromArray()` / `toArray()` throw `\LogicException` with a clear message
pointing to spec v7 §11. The registry intentionally does not map this enum
case, so the production code path that asks the registry will throw
`FiscalEventTypeNotImplemented` before the DTO can be touched; the
`\LogicException` guards are belt-and-suspenders defense for anyone who
imports the class directly. P2-1 flags an exception-class consistency
nit on this.

The TS device-side `FiscalEventPayloadRegistry` is a thin mirror:
`FISCAL_EVENT_TYPES` is a 28-element `as const` tuple that I diffed against
the PHP enum line-by-line — exact match (see "Verification I ran" below).
`FiscalEventTypeValue` is the derived union; `Phase1ImplementedType` is a
narrower 4-element union driven by `as const satisfies readonly
FiscalEventTypeValue[]`, which Lock the implemented set at compile-time:
adding a typo to `PHASE_1_IMPLEMENTED` is a TypeScript error.
`FiscalEventTypeNotImplementedError extends Error` carries the offending
type value and a stable error-name.

Test coverage parity is solid: PHP runs 11 cases / 59 assertions covering
implemented-set resolution, version=1 invariant, two reserved-type spot
checks for `dtoClassFor()` (`COMPANY_DAY_CLOSURE_MANIFEST`, `SALE_VOID`),
one for `eventVersionFor()` (`Z_REPORT`), the `isImplemented()` helper, a
**full 28-case walk** asserting `registry.isImplemented($case) ===
$case->isImplementedInPhase1()` (the registry-vs-enum drift guard), and the
four `*_roundtrip` cases. TS runs 6 cases covering implemented-set
classification, a **24-case sweep** of the reserved-not-implemented set
(28 − 4), version=1 invariant, three spot-check throws, error-message
shape, and the stable `implementedTypes()` listing. The PHP enum-walk and
the TS reserved-sweep together close the cross-platform drift hole that
Task 13's `[0-9a-f]*` GLOB BLOCKER could have hidden — a typo in either
list surfaces as a test failure.

The two material risks I looked hardest at — (a) the TS tuple missing a
case (type-system unsound; the union loses an arm; reserved-sweep coverage
silently drops), and (b) a `float` slipping into a DTO (SoT §4 violation) —
are both clean. The 28-case match is exact; the only `number` in the TS
file is `eventVersionFor()`'s return.

## Findings

### BLOCKER (must fix before merge)

None.

### P1 (must fix before merge)

None.

### P2 (should fix before merge)

#### P2-1 — `CompanyDayClosureManifestPayload` throws `\LogicException` rather than the domain `FiscalEventTypeNotImplemented`

**Files:** `apps/api/app/Modules/Fiscal/Domain/DTOs/CompanyDayClosureManifestPayload.php:30-50`

The DTO's `fromArray()` and `toArray()` throw `\LogicException`. The Phase 1
contract for this enum case is "no Phase 1 payload handler" — exactly what
`FiscalEventTypeNotImplemented` is named to express. In normal flow the
registry rejects the type before the DTO is ever constructed, so these
guards are dead code; but if a future caller imports the DTO directly
(e.g. a test, a debug script, or a Phase 2 ramp-up that wires the registry
entry before fleshing out the schema), the exception class diverges from
the rest of the engine's `not-implemented` vocabulary. Two paths report
the same condition with two exception types — a callsite that does
`catch (FiscalEventTypeNotImplemented $e)` (which Task 15 / Task 16 will
inevitably write) silently leaks the `\LogicException`.

**Suggested fix:** throw `FiscalEventTypeNotImplemented($type =
FiscalEventType::COMPANY_DAY_CLOSURE_MANIFEST)` from both methods. The
exception class accepts the enum case in its constructor — no shape
change needed. Add a one-line test asserting the throw is the domain
exception, not a generic `\LogicException`.

(Marking P2 rather than P1 because the dead-code-guard nature of these
methods means no production path can reach them today; the registry
shields them. The cost of leaving it is purely a hygiene tax on Phase 2.)

#### P2-2 — DTO sub-arrays typed `list<array<string, mixed>>` defer all field-shape validation to Task 16

**Files:**
- `apps/api/app/Modules/Fiscal/Domain/DTOs/SaleReceiptPayload.php:28-32` — `$lines`, `$vatBreakdown`, `$paymentLines`, `$voucherRedemptions`
- `apps/api/app/Modules/Fiscal/Domain/DTOs/ChainBreakDetectedPayload.php:24` — `$offendingRecordReference`
- `apps/api/app/Modules/Fiscal/Domain/DTOs/ChainRestartPayload.php:29-31` — `$lastGoodAnchor`, `$operatorAuthorizationEvidence`, `$provenanceLink`
- `apps/api/app/Modules/Fiscal/Domain/DTOs/TerminalRegistrySnapshotPayload.php:22` — `$terminals`

`fromArray()` does `$data['lines']` with no validation: the caller can pass
a string, `null`, or a heterogeneous array, and PHPStan level 8 stays
happy because the property is typed `array`. Round-trip asserts equality
on `toArray()`, but never asserts the *shape* — feeding garbage in and
getting garbage out passes. This is acceptable under the explicit
"refinement deferred to Task 16" comment in `SaleReceiptPayload`, and
Task 16's `StrictCanonicalParser` is the spec-mandated gatekeeper before
canonical bytes ever land. But the DTO ships today with no
field-shape guarantee, which is a quiet contract weakness that a future
test or a future bridge could trip over.

**Suggested fix (cheap, defer-friendly):** add a per-DTO `assertShape()`
private helper that loops over the sub-array elements and checks the
expected keys are present (`product_id`, `quantity`, `unit_price`, etc.).
Call it from `fromArray()`. Throw `\InvalidArgumentException` listing the
missing key. This raises false-positive risk on the round-trip test (the
test data must satisfy the shape) but the existing round-trip fixtures
already do. **Or** defer this entirely to Task 16 and explicitly note in
the Task 14 docblock that DTO field-shape validation is StrictCanonicalParser's
job — making the deferral explicit at the DTO boundary, not implicit.

### P3 (worth fixing; not blocking)

#### P3-1 — `FiscalEventPayloadRegistry::PHASE_1_MAP` array shape uses `value-of<FiscalEventType>` keys; PHPStan happy but humans grep for the enum case names

**File:** `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php:35-42`

The map is keyed by `FiscalEventType::SALE_RECEIPT->value` (the string
`'SALE_RECEIPT'`), not by the enum case itself. PHP doesn't allow enum-case
array keys in a class constant, so this is forced — but a reader scanning
the file for "where is SALE_RECEIPT handled" greps for `SALE_RECEIPT`
and lands on the value-arrow lookup, which is fine. The minor cost is
the lookup goes through `$type->value` instead of `$type` directly, and
the docblock claims `@var array<value-of<FiscalEventType>, ...>` (which is
what PHPStan reads) but the runtime key is the string. Consider switching
to a `match (true)` expression in `dtoClassFor()` / `eventVersionFor()`
that pattern-matches on the enum case — slightly more code, but the enum
case name lives at the callsite rather than as a value-string lookup.

This is taste; not a defect.

#### P3-2 — `FiscalEventTypeNotImplemented` extends `RuntimeException`; a checked-exception-equivalent might be cleaner

**File:** `apps/api/app/Modules/Fiscal/Domain/Exceptions/FiscalEventTypeNotImplemented.php:21`

The exception extends `RuntimeException`. Caller code (Task 15 `append()`,
Task 16 strict parser) will need to catch it explicitly. Extending
`RuntimeException` is the right choice (PHP has no checked exceptions and
the convention is to extend `RuntimeException` for "programmer error"-style
exceptions), but consider also implementing a marker interface like
`FiscalEventException` (or similar) so that the engine's top-level
sync-failure handler can `catch (FiscalEventException $e)` without
enumerating concrete classes. Same idea Task 11's
`SecondaryIntegritySealMissingException` could benefit from. Not blocking;
nothing to fix today.

## What works well

- **Registry-vs-enum drift guard.** The PHP test walks all 28 enum cases
  and asserts `registry.isImplemented($case) === $case->isImplementedInPhase1()`.
  Adding an enum case without registering it (or vice versa) is a test
  failure. This is the exact pattern that would have caught the Task 13
  GLOB BLOCKER if the test had asserted the invariant rather than the
  happy path.
- **TS reserved-sweep exhaustive.** The 24-case sweep covers exactly
  `(enum cases) − (implemented set)` — I verified this by diffing
  `/tmp/php_enum.txt - /tmp/ts_implemented.txt` against
  `/tmp/ts_reserved.txt`: zero drift.
- **`as const satisfies readonly FiscalEventTypeValue[]` on `PHASE_1_IMPLEMENTED`.**
  Compile-time exhaustiveness: a typo in the implemented set is a TS
  error, not a runtime drift.
- **No `float` anywhere.** Money is `string`, `currency_scale` is `int`,
  `last_good_sequence` is `int`. Spec v7 §4 compliance held under grep.
- **PHP `final readonly class` + `class-string` annotations.** PHPStan
  level 8 clean over the whole `app/Modules/Fiscal/` tree.
- **`FiscalEventTypeNotImplemented` carries the offending case as a
  readonly property.** Debug visibility without parsing the message.
- **`SaleReceiptPayload` docblock comment explicitly names sub-array
  refinement as a Task 16 / Task 21-22 deferred concern.** Future readers
  know why the type is loose; the deferral is documented at the DTO,
  not buried in the commit message.

## Verification I ran

```bash
# PHP unit suite — green
cd apps/api && ./vendor/bin/phpunit tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php
# OK (11 tests, 59 assertions)

# PHPStan level 8 — clean over the whole Fiscal module
cd apps/api && ./vendor/bin/phpstan analyse --no-progress app/Modules/Fiscal/
# [OK] No errors

# Pint — clean on the new files
cd apps/api && ./vendor/bin/pint --test app/Modules/Fiscal/ tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php
# {"result":"pass"}

# TypeScript strict typecheck — clean
cd apps/pos && pnpm typecheck
# (no output → clean)

# Vitest fiscal registry — green
cd apps/pos && pnpm vitest run src/lib/fiscal/__tests__/FiscalEventPayloadRegistry.test.ts
# Test Files  1 passed (1)
# Tests  6 passed (6)
```

**Cross-language enum drift sweep (executed):**

```bash
# Extract PHP enum cases
grep -E "case [A-Z_]+ = " apps/api/app/Modules/Fiscal/Domain/Enums/FiscalEventType.php \
  | sed -E "s/.*case ([A-Z_]+) = .*/\1/" | sort > /tmp/php_enum.txt

# Extract TS FISCAL_EVENT_TYPES tuple (lines 30-59 only — excludes PHASE_1_IMPLEMENTED block)
sed -n '30,59p' apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts \
  | grep -Eo "'[A-Z_]+'" | sed -E "s/'//g" | sort > /tmp/ts_enum.txt

diff /tmp/php_enum.txt /tmp/ts_enum.txt && echo "MATCH"
# MATCH
wc -l /tmp/php_enum.txt /tmp/ts_enum.txt
# 28 /tmp/php_enum.txt
# 28 /tmp/ts_enum.txt
```

Both registries have all 28 Appendix A vocabulary cases. No drift.

**TS reserved-sweep completeness check:**

```bash
# Extract reserved sweep from the TS test
grep -E "^      '[A-Z_]+'," apps/pos/src/lib/fiscal/__tests__/FiscalEventPayloadRegistry.test.ts \
  | sed -E "s/.*'([A-Z_]+)'.*/\1/" | sort > /tmp/ts_reserved.txt

# Expected = enum − implemented
comm -23 /tmp/php_enum.txt <(printf 'SALE_RECEIPT\nCHAIN_BREAK_DETECTED\nCHAIN_RESTART\nTERMINAL_REGISTRY_SNAPSHOT\n' | sort) > /tmp/expected_reserved.txt

diff /tmp/ts_reserved.txt /tmp/expected_reserved.txt && echo "RESERVED MATCH"
# RESERVED MATCH
wc -l /tmp/ts_reserved.txt
# 24 /tmp/ts_reserved.txt
```

All 24 reserved-not-implemented cases swept.

## Cross-task regression check

- **Task 1 (`FiscalEventType` enum):** unchanged; the registry consumes
  the enum's `isImplementedInPhase1()` helper as its drift gate.
- **Task 8 (`fiscal_events` table + integrity_exception_class):** no
  interaction; the registry sits one layer above the table.
- **Task 11 (`SecondaryIntegritySealMissingException` + signature columns):**
  no interaction; signature is a separate provider track per spec v7 §5.2.
- **Task 13 (device SQLite `fiscal_events` table):** the TS registry will
  be consumed by `FiscalEventEngine.append()` (Task 15) which writes to
  the device table; the registry's `eventVersionFor()` will populate the
  `event_version` column (Task 13 ships it as `INTEGER NOT NULL`). The
  TS registry returns `1` for all implemented types — matches the Task 13
  CHECK constraint `event_version > 0`. No drift.
- **Task 13 lesson (GLOB false-positive):** explicitly considered here.
  The risk pattern was "test passes but invariant is broken." Task 14's
  invariants are (a) registry-vs-enum agreement and (b) the implemented
  set exactly equals 4. Both are covered by exhaustive sweeps, not spot
  checks — the GLOB-style false-positive cannot hide.

## Verdict reasoning

The two P2 findings are *contract clarity* issues, not correctness
defects. P2-1 (`\LogicException` vs `FiscalEventTypeNotImplemented`) only
matters if a future caller imports `CompanyDayClosureManifestPayload`
directly, which Phase 1 doesn't. P2-2 (loose sub-array typing) is
explicitly deferred to Task 16's `StrictCanonicalParser` by the
implementation's own docblock. Neither is a wrong-behavior defect; both
are tax notes for Phase 2.

The cross-language drift gate — the registry-vs-enum walk plus the
TS reserved sweep — closes the exact failure class that Task 7, Task 11,
and Task 13 reviews surfaced. The implemented set is locked at both ends
with compile-time + runtime guards. PHPStan level 8 clean, TypeScript
strict clean, all tests green.

**Verdict: APPROVE.** Suggest the author considers P2-1 (one-line fix)
as a same-PR pickup or files it as a Task 15/16 sweep item. P2-2 should
land an explicit "deferred to Task 16" docblock on every DTO that has
`list<array<string, mixed>>` sub-arrays, not just `SaleReceiptPayload`.
