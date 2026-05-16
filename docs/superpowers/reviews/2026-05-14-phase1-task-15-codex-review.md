# Codex review — Phase 1 Task 15 (FiscalEventEngine.append)

**Commit:** `9a9a94b3`
**Branch:** `feat/pos-fiscal-event-engine-phase1`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1`
**Reviewer:** Codex (headless adversarial review gate, second opinion)
**Date:** 2026-05-16
**Verdict:** **BLOCK** — 1 BLOCKER, 2 P1, 2 P2, 1 P3.

**Note:** the Codex sandbox could not write directly to the
fiscal-phase1 worktree (same path-permission restriction that occurred
on Task 14). The review content below was returned inline by the Codex
turn and transcribed verbatim by the executing-plans loop.

## Summary

The engine's structural integration with the v37 schema, the
canonical-encoder, the integrity provider, and the payload registry is
sound. The six spec §6.1 steps execute in order, reserved-event-type
+ empty-seed-sentinel throws fire BEFORE any state mutation, the
spec §4 fourteen-field canonical object is constructed exactly (with
`reference_*` as `null` not omitted), `canonical_bytes` round-trip
verification works server-side, and `signature_version` is injected
from the integrity provider (not hardcoded). 11/11 tests green;
typecheck + lint clean.

**However** — `append()` accepts `payload: unknown` and passes it
opaquely into the canonical object without payload-shape validation.
The `FiscalEventCanonicalEncoder` rejects floats (Task 5 contract),
but it does NOT reject integers in monetary-named fields. Spec v7
§4 says money MUST be a `CurrencyScale::bcformat()` decimal string —
e.g. `"10.000"` for TND, not the integer `10`. A caller that builds
the receipt payload as `{ total: 10, currency: "TND" }` (an honest
mistake — `10` looks fine to a TS strict caller because the field
is `unknown`) produces canonical bytes that hash and chain
successfully on the device, but cannot be repaired server-side: the
chain advances, the canonical_bytes are immutable, and the server's
strict parser (Task 16) rejects the row with
`canonical_parse_failure` — putting that terminal into chain-incident
state.

The damage is recoverable via the `CHAIN_BREAK_DETECTED` +
`CHAIN_RESTART` flow per spec §9, but the operational cost is
non-trivial. The BLOCKER framing is: the device-side foot-gun is
preventable in the engine itself with a thin typed-input layer +
top-level monetary-field validator, and we should prevent it rather
than rely on the server-side quarantine.

## Findings — BLOCKER / P1 / P2 / P3

### BLOCKER — Opaque payload lets non-string money into immutable canonical bytes

**File:** `apps/pos/src/lib/fiscal/FiscalEventEngine.ts` (`append()` step 4
— `buildCanonicalBytes`).

`append()` takes `payload: unknown` directly from
`FiscalEventAppendRequest`. The encoder rejects only non-integer
numbers — floats throw `CanonicalEncodingError`. Integer numbers
(e.g. `10`, `1000`) are encoded as JSON numbers (`10`, `1000`).
Decimal strings (e.g. `"10.000"`) are encoded as JSON strings.
Spec v7 §4 says monetary fields MUST be decimal strings.

A caller building a `SALE_RECEIPT` payload like:
```ts
engine.append(tx, {
  event_type: 'SALE_RECEIPT',
  // ...
  payload: { currency: 'TND', total: 10, subtotal: 10, lines: [] },
});
```
produces canonical bytes containing `"total":10` instead of
`"total":"10.000"`. The SHA-256 of those bytes is the device-side
`current_hash`. The chain advances. The row syncs to the server. The
server's strict parser (Task 16) rejects the payload —
`canonical_parse_failure` integrity exception, terminal-chain
incident state, requires `CHAIN_BREAK_DETECTED` + `CHAIN_RESTART`
to recover.

The architecture has the recovery seam (§9), but the foot-gun is
preventable at the device boundary with two thin defenses:

1. **Typed-input interfaces** (TS compile-time defense). Define
   `SaleReceiptPayloadInput`, `ChainBreakDetectedPayloadInput`,
   `ChainRestartPayloadInput`, `TerminalRegistrySnapshotPayloadInput`
   in `apps/pos/src/lib/fiscal/`. Each mirrors the server's
   `App\Modules\Fiscal\Domain\DTOs\*Payload` PHP DTO fields, but
   **at the device side every monetary field is typed `string`** —
   never `number`. TS strict callers passing `total: 10` get a
   compile error.

2. **Runtime payload sanity-check** in `append()`, scoped to the
   event_type. For `SALE_RECEIPT`: assert `currency: string`,
   `currency_scale: int`, and the four top-level monetary fields
   (`subtotal`, `discount_total`, `tax_total`, `total`) are
   strings. Reject with a domain-specific
   `FiscalEventPayloadValidationError` BEFORE encoding so no
   canonical_bytes are produced and no chain advance occurs. Per-line
   monetary fields and sub-array shape validation are intentionally
   left to Task 16's `StrictCanonicalParser` (matches the deferral
   pattern from Task 14 Opus P2-2).

The defense is light — ~50 lines for the validator, ~80 lines for
the four typed-input interfaces. Cost is negligible vs. the
operational cost of a chain incident.

### P1-1 — `event_time_device` / `business_date` format not enforced

**File:** `apps/pos/src/lib/fiscal/FiscalEventEngine.ts` (`append()` step 4).

Comments in the engine + spec §4 mandate "UTC ISO-8601 second-precision
timestamps" and `YYYY-MM-DD` business dates. The engine copies
caller strings verbatim into the canonical object. A caller that
supplies `event_time_device = '2026-05-16T10:00:00.000Z'` (with
milliseconds) or `'2026-05-16T10:00:00+02:00'` (non-UTC) produces
canonical bytes that violate spec §4. Same risk class as the BLOCKER
— device-side foot-gun, server-side quarantine.

Fix: regex-validate `event_time_device` against
`^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$` and `business_date` against
`^\d{4}-\d{2}-\d{2}$` at the top of `append()`. Throw
`FiscalEventPayloadValidationError` on mismatch.

### P1-2 — Transaction not enforced by type or runtime

**File:** `apps/pos/src/lib/fiscal/FiscalEventEngine.ts` (`append()` entry).

Spec §5.0 / §6.1 mandates `append()` runs INSIDE the caller's
transaction. The contract is documented in JSDoc + the test verifies
the BEGIN/ROLLBACK case. But the engine does NOT verify the adapter
is currently in a transaction. Most existing tests in the suite
demonstrate this: any `append()` call NOT wrapped in `BEGIN`/`COMMIT`
writes immediately in SQLite autocommit mode, the row persists, and
the test asserts that as success — but in production this means a
caller who forgets `BEGIN` produces a fiscal row OUTSIDE the
receipt-atomicity boundary (violates §5.0).

Two paths to close this:
- **Runtime check:** read `PRAGMA … transaction_status` or similar
  before INSERT; throw if not in a transaction. SQLite makes this
  awkward.
- **Type / API change:** restrict the `tx` parameter to a `Tx`
  branded type that only `runInTransaction(adapter, fn)` produces.
  Heavier change; needs an `appendInTransaction(...)` helper.

For Phase 1, the lighter fix is a strong JSDoc + a comment in
each test that `BEGIN`/`COMMIT` is used in production. Document
clearly that the engine relies on the caller's discipline.

### P2-1 — Cross-terminal source-event idempotency pollution

**File:** `apps/pos/src/lib/fiscal/FiscalEventEngine.ts` (`findBySource()`).

The device-side idempotency lookup queries `fiscal_events` by
`source_event_class` + `source_event_id` only. The underlying v37
partial UNIQUE index also doesn't include `terminal_id`. So a source
event id of `'receipt-r-1'` on terminal A "matches" a source event id
of `'receipt-r-1'` on terminal B — the second append returns the
first terminal's event row. This is incorrect for any source-event
domain where IDs are scoped per-terminal (e.g. per-terminal sequential
ids from a local id generator).

Fix: include `tenant_id` + `terminal_id` in the source-event lookup
WHERE clause. Whether to widen the partial UNIQUE itself is a v37
migration change (revisit if needed); the engine's lookup is the
first-defense scope.

### P2-2 — Parallel same-terminal appends surface raw SQLite UNIQUE violation

**File:** `apps/pos/src/lib/fiscal/FiscalEventEngine.ts`.

Inside one transaction `append()` is atomic. Across two parallel
transactions on the same terminal, the chain UNIQUE on
`(tenant_id, terminal_id, sequence_number)` catches the second
insert as a raw `SQLITE_CONSTRAINT` exception — not a typed
domain error. The caller must inspect the SQLite error message to
distinguish "chain race" from other UNIQUE failures.

Fix: catch the SQLITE_CONSTRAINT_UNIQUE error specifically when
inserting `fiscal_events` + raise a typed
`ConcurrentChainAdvanceError`. The caller can then retry, or
escalate to a CHAIN_BREAK_DETECTED if the race is unexpected.

### P3 — Genesis sentinel check only rejects `''`

**File:** `apps/pos/src/lib/fiscal/FiscalEventEngine.ts`
(`readChainHead()`).

The empty-string sentinel `''` is the v37 default, but a buggy
terminal-init flow could populate the seed with `'GENESIS'`
(legacy receipt-V3 sentinel) or `'\0'.repeat(64)` or `'0'.repeat(64)`.
None of those are valid seeds; the check should reject obviously-
uninitialized values, not just the empty string.

Fix: assert `fiscal_event_genesis_seed` matches
`^[0-9a-f]{64}$` (the same lowercase-hex 64-char invariant the
v37 CHECK applies to `previous_hash` / `current_hash`).

## What works well

- **Spec §6.1 steps executed in order with no inversion.** Step 1
  (registry) precedes step 2 (idempotency) precedes step 3 (chain
  head read) precedes step 4 (canonical_bytes + hash) precedes
  step 5 (INSERT) precedes step 6 (chain head advance).
- **Reserved-type + empty-seed throws fire BEFORE any state
  mutation.** Tests assert state-unchanged after either throw.
- **Canonical-object field set matches spec §4 exactly.** Fourteen
  fields, `reference_*` present as `null` (not omitted), sorted-key
  JCS encoding (the encoder sorts; the engine doesn't pre-sort).
- **`signature_version` is injected from
  `IntegrityProvider.version()`.** Not hardcoded.
- **`canonical_bytes` persisted verbatim.** Server-side verifier
  re-hashes the device's exact bytes per D2.
- **`event_version` from registry, not hardcoded `1`.** Verified by
  reading the `eventVersionFor(type)` call site.
- **Constructor injection only.** No `new` inside methods.
- **DB row reads narrowed via `requireString` / `requireNumber` /
  `nullableString` helpers.** Task 14 round-2 Codex lesson applied.
  No `(type) row.key` cast pattern.
- **Genesis-seed sentinel rejection.** Closes Task 13 round-2 Codex
  P3 forward-looking input.
- **UUID v4 via `crypto.randomUUID()` with `getRandomValues()`
  fallback.** No `Math.random()` for a fiscal id.

## Verification I ran

- `pwd` and `git rev-parse HEAD` to confirm worktree.
- `cd apps/pos && pnpm vitest run src/lib/fiscal/__tests__/FiscalEventEngine.test.ts` — 11/11.
- `cd apps/pos && pnpm typecheck` — clean.
- `cd apps/pos && pnpm lint src/lib/fiscal/FiscalEventEngine.ts src/lib/fiscal/__tests__/FiscalEventEngine.test.ts` — 0 errors.
- Cross-checked the canonical-field-set against spec §4 — exact match.
- **BLOCKER reproducer**: constructed
  ```ts
  engine.append(tx, {
    event_type: 'SALE_RECEIPT', /* …minimal required fields… */
    payload: { currency: 'TND', total: 10, subtotal: 10, lines: [] },
  });
  ```
  Result: the row inserts. `canonical_bytes` contains `"total":10`
  (integer). Hash is computed and the chain advances. No domain
  exception. The server-side strict parser would reject this row
  with `canonical_parse_failure` — recoverable but operationally
  expensive.
- **P2-1 reproducer**: created two `terminal_state` rows (terminals
  `t1` and `t2`, both seeded), called `engine.append()` for `t1`
  with `source_event_id = 'r-1'`, then `engine.append()` for `t2`
  with the same `source_event_id`. Second call returned `t1`'s row
  id — cross-terminal pollution.

## Recommendation

**BLOCK** until the BLOCKER + both P1s are closed. P2s can be
deferred to a follow-up if scope is constrained, but P2-1
(cross-terminal idempotency pollution) is a thin fix (single
WHERE-clause extension + a test) and worth folding into the
same round-2 commit.
