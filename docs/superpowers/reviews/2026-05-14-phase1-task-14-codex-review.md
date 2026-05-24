# Codex review — Phase 1 Task 14 (FiscalEventPayloadRegistry + DTOs)

**Commit:** `57b8fda9`
**Branch:** `feat/pos-fiscal-event-engine-phase1`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1`
**Reviewer:** Codex (headless adversarial review gate, second opinion)
**Date:** 2026-05-16
**Verdict:** **BLOCK** — 2 BLOCKER, 0 P1, 0 P2, 0 P3.

**Note:** the Codex sandbox could not write directly to the
`fiscal-phase1` worktree due to a path-permission restriction. The
review content below was captured from the Codex turn output and
transcribed by the executing-plans loop. The findings are the
authoritative output; the transcription preserves them verbatim.

## Summary

The registry surface (PHP + TS), the implemented-set classifier, the
event-version=1 invariant, the 28-case PHP enum ↔ TS `FISCAL_EVENT_TYPES`
mirror, the registry-vs-enum consistency walk, and the
`CompanyDayClosureManifestPayload` schema-only contract are all sound.
PHPStan level 8 + Pint + TS strict all clean. PHPUnit 11/11 and Vitest
6/6 green.

However, **two defense-in-depth gaps in the PHP DTO `fromArray()`
surfaces are BLOCKERs against the spec v7 §4 canonical-payload
contract**. The DTO is a public API consumed by the future
`StrictCanonicalParser` (Task 16) — but the parser is itself the only
upstream gate. Until Task 16 lands, any caller that builds a payload
array by hand (and there will be such callers during the Phase 2 ramp
of projector wiring + chain-recovery code paths) can pass a payload
that the DTO silently accepts.

## Findings — BLOCKER / P1 / P2 / P3

### BLOCKER-1 — `fromArray()` silent key-missing data loss

**Files:** all five `apps/api/app/Modules/Fiscal/Domain/DTOs/*Payload.php` (except `CompanyDayClosureManifestPayload`, which intentionally throws).

The PHP DTO constructors cast missing required keys into safe-looking
defaults via the `(string) $data['key']` / `(int) $data['key']` cast
chain:

```php
public static function fromArray(array $data): self
{
    return new self(
        currency: (string) $data['currency'],
        currencyScale: (int) $data['currency_scale'],
        // …
    );
}
```

If `$data['currency']` is missing, PHP raises an "undefined array key"
**warning** (not error) and the cast `(string) null` resolves to the
empty string `''`. Same path for missing int keys → `0`. Same path for
missing array keys → an empty array (with a warning).

This is silent data corruption: the DTO accepts a malformed payload,
emits a warning that may be swallowed by the application error handler
in production, and constructs a "valid-looking" object with
empty/zero/empty-array fields. A downstream consumer that hashes or
inspects the DTO would see legitimate-looking-but-wrong data.

The spec v7 §4 canonical-payload contract requires the canonical bytes
to be **complete** — every required field is present and typed
correctly. The DTO must enforce that contract at its surface, not
defer it to the parser.

**Fix:** add explicit `array_key_exists($key, $data)` guards (or
`?? throw new \InvalidArgumentException(...)` pattern) for every
required field. Optionally combine with type assertions per BLOCKER-2.

### BLOCKER-2 — Float monetary field leak in `SaleReceiptPayload`

**File:** `apps/api/app/Modules/Fiscal/Domain/DTOs/SaleReceiptPayload.php`

The DTO properties are typed `string` for monetary fields — `subtotal`,
`discountTotal`, `taxTotal`, `total`. But `fromArray()` constructs
them via `(string) $data['subtotal']` — which **accepts a float and
silently casts it to a string**, with the host PHP locale-sensitive
float-to-string conversion (e.g. `(string) 10.5` → `'10.5'`).

This violates the spec v7 §4 contract: "integers only, no floats; money
as `CurrencyScale::bcformat()` decimal strings." A caller that passes
a float — accidentally or via an upstream type-erasure bug — has the
float silently converted, with the float's IEEE 754 imprecision
becoming part of the canonical payload string.

Note the same risk exists for the per-line nested sub-arrays
(`lines[i].unit_price`, `lines[i].line_total`, `vat_breakdown[i].base`,
`vat_breakdown[i].amount`, `payment_lines[i].amount`, etc.) but those
are passed through as `array<string, mixed>` and deferred to Task 16's
parser. The top-level monetary fields **on `SaleReceiptPayload`**
should not.

**Fix:** assert `is_string($data['subtotal'])` (and friends) before
cast — or remove the cast and let the explicit type-mismatch surface
as `TypeError`.

### P1 / P2 / P3

None.

## What works well

- **28-case enum ↔ TS tuple alignment.** I ran `diff` between the PHP
  enum's `case` list and the TS `FISCAL_EVENT_TYPES` tuple — empty
  diff. Every server case is mirrored client-side.
- **Registry-vs-enum drift gate.** The `test_registry_is_consistent_with_enum_phase1_helper`
  walks every enum case and asserts `registry->isImplemented($case) ===
  $case->isImplementedInPhase1()`. A future enum or registry change
  that desyncs them surfaces immediately.
- **TS reserved-sweep coverage.** The TS test exercises all 24
  non-Phase-1 cases — not a sample. The Task 13 GLOB-false-positive
  bug class is structurally impossible here.
- **`CompanyDayClosureManifestPayload` separation.** Intentional split
  between `LogicException` (DTO surface, "should never be called")
  and `FiscalEventTypeNotImplemented` (registry, "the type is
  reserved"). Defensible; the registry's exception is the canonical
  caller-facing failure. The DTO's `LogicException` is a safety net
  for any direct importer.
- **No `float` field types.** The `(string) $data[...]` cast hides the
  BLOCKER-2 issue, but no property is declared `float`. Once the cast
  is replaced with an `is_string` assertion, the contract is sealed.
- **PHPStan level 8 clean.** I ran the full `app/Modules/Fiscal/`
  scan; no errors. The Phase 1 DTO surface is PHPStan-correct given
  the `array<string, mixed>` input contract.
- **TypeScript strict clean.** `pnpm typecheck` from `apps/pos` —
  zero errors.

## Verification I ran

- `git -C /Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1 show 57b8fda9 --stat` — 10 files added, +777 lines.
- `cd apps/api && ./vendor/bin/phpunit tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php` — 11/11 (59 assertions).
- `cd apps/api && ./vendor/bin/phpstan analyse --no-progress app/Modules/Fiscal/` — clean.
- `cd apps/api && ./vendor/bin/pint --test app/Modules/Fiscal/ tests/Unit/Fiscal/FiscalEventPayloadRegistryTest.php` — pass.
- `cd apps/pos && pnpm vitest run src/lib/fiscal/__tests__/FiscalEventPayloadRegistry.test.ts` — 6/6.
- `cd apps/pos && pnpm typecheck` — clean.
- Enum vs TS tuple diff:
  ```
  diff <(grep "case " apps/api/app/Modules/Fiscal/Domain/Enums/FiscalEventType.php \
           | sed -E "s/.*case ([A-Z_]+).*/\1/" | sort) \
       <(grep "  '[A-Z_]" apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts \
           | sed -E "s/.*'([A-Z_]+)'.*/\1/" | sort -u)
  ```
  Empty diff — 28-case match.
- **BLOCKER-1 reproducer**: constructed `SaleReceiptPayload::fromArray(['currency' => 'TND'])` (missing every other key). Result: emitted PHP undefined-array-key warnings; returned a `SaleReceiptPayload` with `subtotal = ''`, `currencyScale = 0`, `lines = []`. No exception thrown. Silent data corruption confirmed.
- **BLOCKER-2 reproducer**: constructed `SaleReceiptPayload::fromArray($validShape)` where `$validShape['subtotal'] = 10.5` (a float). Result: `$dto->subtotal === '10.5'`. The `toArray()` round-trip returned `'subtotal' => '10.5'` — float silently converted.

## Recommendation

**BLOCK.** Both findings are 1-line fixes inside `fromArray()`. Replace
each `(type) $data['key']` cast with a guarded helper:

```php
private static function requireString(array $data, string $key): string
{
    if (!array_key_exists($key, $data)) {
        throw new \InvalidArgumentException("Missing required key: {$key}");
    }
    if (!is_string($data[$key])) {
        throw new \InvalidArgumentException(
            sprintf('Expected string for key %s, got %s', $key, get_debug_type($data[$key])),
        );
    }
    return $data[$key];
}
```

Add `requireInt` / `requireList` variants. Update the 4 implemented
DTOs to use the helpers. Add regression tests:

- `fromArray` with missing required keys throws `\InvalidArgumentException`.
- `fromArray` with float `subtotal` throws `\InvalidArgumentException`.
- `fromArray` with bool `currency_scale` throws `\InvalidArgumentException`.

Re-run PHPUnit + PHPStan + Pint — should remain green at the level the
DTOs achieve today.
