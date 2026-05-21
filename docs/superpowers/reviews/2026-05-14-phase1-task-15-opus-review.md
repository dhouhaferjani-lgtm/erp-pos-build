# Opus review — Phase 1 Task 15 (FiscalEventEngine.append)

**Commit:** `9a9a94b3`
**Branch:** `feat/pos-fiscal-event-engine-phase1`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1`
**Reviewer:** Opus (headless adversarial review gate)
**Date:** 2026-05-16
**Verdict:** **APPROVE-WITH-MINOR-EDITS** — 0 BLOCKER, 0 P1, 2 P2, 3 P3.

## Summary

Task 15 lands the device-side `FiscalEventEngine.append()` — the single
authoring path for the fiscal-event chain per spec v7 §6.1 / §6.2 — with
the spec's six steps mapped one-to-one onto the implementation. The engine
runs inside the caller's SQLite transaction (no `BEGIN`/`COMMIT`/`ROLLBACK`
inside), reads the single chain head from `terminal_state`, refuses to
append against the v37 empty-string seed sentinel (closing Task 13 round-2
Codex P3), builds canonical bytes from exactly the fourteen spec §4 fields
with `signature_version` injected from `HashChainIntegrityProvider.version()`
(no hardcoded string in the engine), INSERTs the `fiscal_events` row with
`signature_status='not_required'` / `sync_status='pending'`, and advances
the chain head with `fiscal_event_last_hash` + `fiscal_event_sequence` in
the same transactional surface so atomicity is the caller's transaction's
job.

The Task 14 round-2 Codex BLOCKER pattern (`(type) row.key` cast that hides
a malformed DB read) is explicitly defended: every SELECT row goes through
`rowToResult()` → `requireString` / `requireNumber` / `requireExact` /
`nullableString` runtime narrowing, with field-named error messages. The
Task 13 round-2 GLOB pattern lives at the DB layer; the engine's hash
output is always 64 lowercase-hex via the same `HashChainIntegrityProvider`
the v3 chain uses, so the CHECK constraint defense fires only on adversarial
override, not on engine output.

Eleven tests run against the real v37 schema (`SqliteTestAdapter` +
`node:sqlite`), exercising: first-event seed linkage, second-event chain
link, caller-controlled BEGIN/ROLLBACK leaves no row + no head advance,
caller-controlled BEGIN/COMMIT atomicity, source-backed idempotent
re-emission returns the existing row + no head advance, two distinct
source-backed events advance the chain, reserved-type rejection with
state-unchanged, empty-seed sentinel rejection with state-unchanged, the
integrity provider's `verify(canonical_bytes, current_hash)` round-trips
on the persisted row (locks the server-side verifier contract), the
canonical-payload key set is exactly the spec §4 fourteen-field sorted
list, and two terminals' chains are independent (seed isolation).
`pnpm vitest run src/lib/fiscal/` is 108/108 green; v37 migration suite
is 49/49 green; `pnpm typecheck` clean; ESLint clean on the two new
files.

The two material risks I looked hardest at — (a) silent acceptance of the
asymmetric `(source_event_class, source_event_id)` pair (only one set),
which would be a Task 14-style defense-in-depth gap, and (b) the
"rejection-with-state-unchanged" invariant not being asserted on **both**
throw paths — are largely covered: (a) the DB-level CHECK constraint
catches the asymmetric pair (engine forwards values + lets CHECK reject),
and (b) both throw paths assert `rows.toHaveLength(0)` + `head.sequence === 0`.
The two P2 findings are contract-clarity issues (cross-tx race semantics
documentation; engine-level rejection of the asymmetric source pair before
relying on DB CHECK), not correctness defects.

## Findings

### BLOCKER (must fix before merge)

None.

### P1 (must fix before merge)

None.

### P2 (should fix before merge)

#### P2-1 — Asymmetric `(source_event_class, source_event_id)` request relies on the DB CHECK to reject; engine has no upfront guard

**File:** `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:170-179, 253-254`

Step 2's idempotency block fires only when **both** `source_event_class`
and `source_event_id` are non-null:

```ts
if (request.source_event_class != null && request.source_event_id != null) {
  const existing = await this.findBySource(...);
  if (existing) return existing;
}
```

If the caller sets exactly one of the two fields (e.g. supplies a
`source_event_class` from a partially-typed request DTO but forgets
`source_event_id`), the engine does **not** raise. It skips the
idempotency block, advances through Step 3-4 (reads the chain head,
builds canonical bytes, computes the hash), and then sends the
asymmetric pair into the INSERT at line 253-254 — at which point the
v37 CHECK constraint
`(source_event_class IS NULL AND source_event_id IS NULL) OR (both NOT NULL)`
rejects the row.

This is "wrong but caught," not "wrong and silently accepted" — the
chain head has not yet advanced (Step 6 is after the INSERT), so a
CHECK-failed INSERT leaves both `fiscal_events` and `terminal_state`
unchanged, mirroring the reserved-type and empty-seed cases. However:

- The error surface is a SQLite `CHECK constraint failed` message,
  not a domain exception. Callers cannot `catch (e instanceof
  AsymmetricSourceEventError)` to distinguish this from a legitimate
  unique-key collision or a real schema corruption.
- A future test that asserts "rejection leaves state unchanged" will
  catch a CHECK failure here, but the diagnostic path is more
  expensive than the engine-level throw would be.
- The Task 14 P2 pattern is exactly this: "defer field-shape
  validation to the next layer." The engine is the natural next
  layer; deferring further pushes the cost onto the projection /
  ingestion path.

**Suggested fix:** at the top of `append()`, immediately after Step 1's
registry resolution, add a guard:

```ts
const hasClass = request.source_event_class != null;
const hasId = request.source_event_id != null;
if (hasClass !== hasId) {
  throw new Error(
    'source_event_class and source_event_id must be set together or both omitted; ' +
      `got class=${hasClass}, id=${hasId}`,
  );
}
```

Or, in keeping with the project's named-exception style, introduce a
`AsymmetricSourceEventError extends Error` with named fields, mirroring
`ChainHeadNotInitializedError`. Add one Vitest case that supplies a
half-set pair and asserts the named throw + state-unchanged
post-conditions.

Marking P2 (not P1) because the CHECK catches the wrong-state outcome —
no half-mutated chain is possible. The fix is hygiene + diagnostic
quality, not correctness recovery.

#### P2-2 — Cross-transaction race semantics are implied, not documented; the docblock could mislead a Phase 2 caller

**File:** `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:10-16, 150-162`

The header docblock says "runs inside the caller's transaction" and the
single test exercising rollback uses an explicit `BEGIN`/`ROLLBACK` pair
around one engine call. That covers the **single-caller, single-tx**
contract. What it does **not** address — and what the open-items list
in the session handoff explicitly flagged — is the **cross-transaction
race**: two `append()` calls fired from two different SQLite connections
(e.g. a sync flusher reading + a checkout writing on adjacent ticks)
against the same `terminal_state` row.

In SQLite's default rollback-journal isolation:
- The first `BEGIN` upgrades to `RESERVED` on first write.
- The second `BEGIN`'s first write attempts `RESERVED`, gets
  `SQLITE_BUSY`, and either retries or fails per the connection's
  `busy_timeout` setting.
- If both reads succeed and one INSERT lands first, the second's INSERT
  hits the chain UNIQUE `(tenant_id, terminal_id, sequence_number)` and
  fails — the chain head update from the loser is rolled back because
  the surrounding transaction was implicit-rolled.

This is **fine** — the UNIQUE constraint is the cross-tx guard the spec
intends — but the engine docblock doesn't say so. A Phase 2 caller
reading the file might think "the engine handles concurrency" because
"inside the caller's transaction" sounds like atomicity is solved.
Atomicity within one tx **is** solved; cross-tx is solved **by SQLite
locking + the UNIQUE index**, not by the engine itself.

**Suggested fix:** add a short paragraph to the header docblock under
"Invariants":

> **Cross-transaction safety.** Two concurrent `append()` calls on
> different connections rely on SQLite's transaction lock + the
> `idx_fiscal_events_chain_unique` UNIQUE index for serialization:
> the loser of the lock race retries on `SQLITE_BUSY` per its connection
> `busy_timeout`; the loser of an INSERT race (if both reads see the
> same `fiscal_event_sequence`) fails with a UNIQUE violation and is
> rolled back. The engine itself does not coordinate across
> transactions.

Marking P2 (not P1) because the contract is correct; only its
documentation is implicit.

### P3 (worth fixing; not blocking)

#### P3-1 — `defaultDb` constructor parameter + `db` protected getter are unused; either wire them or remove them

**File:** `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:144, 376-378`

```ts
constructor(
  private readonly defaultDb: Database | SqlSurface,
  ...
) {}

protected get db(): SqlSurface {
  return asSql(this.defaultDb);
}
```

Neither `defaultDb` nor `db` is referenced anywhere — in production code,
test code, or sibling fiscal files. The accessor is `protected`, so a
subclass might use it, but `FiscalEventEngine` has no subclasses today.

The constructor signature still requires callers to pass a DB handle that
the engine doesn't use, which is misleading. A reader will infer the
engine has a "default DB" fallback for non-transactional callers — but
there's no such code path; every call must supply `tx`.

**Suggested fix:** either (a) remove `defaultDb` from the constructor and
the `db` getter (the engine becomes a pure-policy class with no DB handle
of its own), or (b) keep it and add a one-line docblock explicitly
noting "reserved for future non-tx convenience read APIs (e.g. chain-head
peeks for diagnostics); not used in Phase 1." Today's code reads as if
the engine has DB authority it doesn't actually exercise.

Marking P3 (not P2) because nothing breaks — it's reader-confusion tax.

#### P3-2 — `Database | SqlSurface` union widens the `tx` parameter; a single structural type would tighten the contract

**File:** `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:130-140, 159-163`

The engine accepts `Database | SqlSurface` (the latter is the structural
subset with `execute` + `select`), and then `asSql()` casts via
`as unknown as SqlSurface`. The cast is sound — `Database` exposes a
superset surface — but the type-system signal is weaker than it could
be. A future change to `@tauri-apps/plugin-sql` that renames or
re-signatures `execute` would compile clean against `SqlSurface` and
fail at runtime against `Database`.

**Suggested fix:** widen `SqlSurface` to be the single accepted type
and have callers (`SqliteTestAdapter` already does this; production
`Database` consumers can wrap with a thin adapter or rely on structural
typing) supply that surface. Or narrow further with a branded type
(`type FiscalTx = SqlSurface & { readonly __fiscalTx: unique symbol }`)
that requires callers to opt in via a constructor — preventing
accidental passing of the wrong handle. The branded-type version is
overkill for Phase 1 but would close the "caller threads the wrong DB"
hole the question implies.

Marking P3 because production callers will be controlled (the receipt
assembler is the single Phase 1 caller per spec §5.0); the breach surface
is small.

#### P3-3 — `findBySource` and `readChainHead` queries are not scoped by `tenant_id`; correct on the device, but the comment could be tighter

**File:** `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:306-327, 329-373`

`findBySource` queries `WHERE source_event_class = $1 AND source_event_id
= $2` — no tenant or terminal filter. `readChainHead` queries
`WHERE terminal_id = $1` — no tenant filter. Both are correct on the
device (one terminal per SQLite file, tenant fixed at provisioning), and
`readChainHead` has a one-line comment explaining tenant scope is the
caller's job. `findBySource` does not.

**Suggested fix:** add a parallel comment to `findBySource`:

> Tenant scope is enforced upstream: device SQLite stores rows for one
> tenant only (per terminal). The partial UNIQUE on
> `(source_event_class, source_event_id)` mirrors this single-tenant
> assumption; cross-tenant disambiguation is the server-side
> `OutboxIngestor`'s job (§7.2).

Marking P3 because the code is correct; the comment is a navigability
nicety.

## What works well

- **Spec §6.1 step-by-step fidelity.** Each of the six steps is a
  numbered comment block in the implementation, and the order matches
  the pseudocode exactly: registry resolve → idempotency check → chain
  head read + sentinel guard → canonical bytes + hash → INSERT → chain
  head update. No step is omitted, reordered, or short-circuited.
- **Reserved-type defense fires before any state mutation.**
  `eventVersionFor()` is the first call in `append()`. Its throw
  surfaces before the idempotency SELECT, before the chain-head read,
  before the canonical-bytes build, before the INSERT, and before the
  chain-head UPDATE. The reserved-type test asserts `rows.toHaveLength(0)`
  and `head.fiscal_event_sequence === 0` — proves the no-partial-mutation
  invariant (`[Spec §5.0, SoT D8]`).
- **Empty-seed sentinel guard is the **exact** Task 13 round-2 Codex P3
  forward-looking input.** The condition is `seq === 0 && seed === ''`
  (both, per the explicit P3 note), not just `seed === ''` (which would
  false-positive on a freshly-seeded terminal whose seed has not yet
  been written), nor just `seq === 0` (which would false-positive on a
  seeded-but-not-appended terminal). The exact composite condition is
  what Task 13 asked for.
- **`signature_version` is injected from the integrity provider.**
  `this.integrityProvider.version()` is read once per append and used
  in both the canonical payload and the INSERT. No hardcoded
  `'hash-chain-integrity-v1'` string in the engine. Phase 2's new
  signature provider plugs into the same constructor seat without
  engine changes.
- **Canonical payload has exactly fourteen sorted keys.** The
  `Object.keys(parsed).sort()` test asserts the exact list:
  `business_date, company_id, event_time_device, event_type,
  event_version, operator_id, payload, previous_hash,
  reference_document_id, reference_event_id, sequence_number,
  signature_version, tenant_id, terminal_id`. `reference_*` fields are
  present as `null` (not omitted) when the request doesn't supply
  them — preserving the canonical key set across all `SALE_RECEIPT`
  events so the hash domain is stable regardless of reference
  presence.
- **`canonical_bytes` is persisted verbatim.** The test asserts
  `stored.canonical_bytes === event.canonical_bytes` and then
  `integrityProvider.verify(stored.canonical_bytes, stored.current_hash) === true`.
  This locks the server-side D2 contract (server re-hashes without
  re-serializing) at the device boundary.
- **Defense-in-depth row narrowing.** Every SELECT row goes through
  `requireString` / `requireNumber` / `requireExact` / `nullableString`
  with field-named error messages. The Task 14 round-2 Codex BLOCKER
  pattern (`(type) row.key` cast that silently accepts a `null` or
  `number` where a `string` was expected) cannot occur on this code
  path.
- **UUID v4 path is integrity-safe.** Prefers `crypto.randomUUID()`,
  falls back to `crypto.getRandomValues()` with the RFC 4122 §4.4
  bit-pattern stamp, and **deliberately refuses** to fall further back
  to `Math.random()` with a thrown error. The explicit comment that
  `Math.random()` is unacceptable for a fiscal id is the kind of
  forward-defense documentation that the audit trail will benefit
  from.
- **ISO-8601 second precision via regex truncation.** `isoSecondsUtc`
  uses `new Date().toISOString().replace(/\.\d{3}Z$/, 'Z')` —
  produces `'2026-05-16T13:26:18Z'`, not `'2026-05-16T13:26:18.000Z'`.
  Matches spec §4 "second-precision timestamps" exactly. `event_time_device`
  is trusted as-is from the request (caller responsibility per
  spec §4).
- **Caller-controlled tx test covers both directions.** Two separate
  tests (`caller rollback...`, `caller commit...`) prove the engine
  doesn't commit on its own AND that an explicit COMMIT lands the row
  + chain head atomically. No single "happy path commit" — the rollback
  test is the stronger invariant.

## Verification I ran

```bash
# Commit shape
git show 9a9a94b3 --stat
# 2 files changed, 924 insertions(+)
#   apps/pos/src/lib/fiscal/FiscalEventEngine.ts                 | 498 +++++
#   apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts  | 426 +++++

# New Vitest suite — 11/11 green
cd apps/pos && pnpm vitest run src/lib/fiscal/__tests__/FiscalEventEngine.test.ts
# Test Files  1 passed (1)
# Tests  11 passed (11)

# Sibling fiscal regression — 108/108 green (no regression)
cd apps/pos && pnpm vitest run src/lib/fiscal/
# Test Files  11 passed (11)
# Tests  108 passed (108)

# v37 migration regression — 49/49 green
cd apps/pos && pnpm vitest run src/lib/db/__tests__/migrations.v37.test.ts
# Test Files  1 passed (1)
# Tests  49 passed (49)

# TypeScript strict — clean
cd apps/pos && pnpm typecheck
# (no output)

# ESLint on the two new files — clean
cd apps/pos && pnpm lint src/lib/fiscal/FiscalEventEngine.ts \
  src/lib/fiscal/__tests__/FiscalEventEngine.test.ts
# (warnings shown are all from unrelated files; new files clean)
```

**Spec §4 fourteen-field cross-check (executed):**

The test at `FiscalEventEngine.test.ts:370-385` asserts
`Object.keys(parsed).sort()` equals the fourteen-element array. I
diff'd this against the spec verbatim:

```
business_date         ✓
company_id            ✓
event_time_device     ✓
event_type            ✓
event_version         ✓
operator_id           ✓
payload               ✓
previous_hash         ✓
reference_document_id ✓
reference_event_id    ✓
sequence_number       ✓
signature_version     ✓
tenant_id             ✓
terminal_id           ✓
```

No extras, no omissions. `reference_*` present as `null` (not omitted).

**Spec §6.1 step ordering cross-check (executed):**

```
Spec step                              FiscalEventEngine.ts line
1. resolve event_version + reserved    167
2. device idempotency on (class, id)   170-179
3. read chain head + sentinel guard    184-187
4. build canonical_bytes + hash        196-214
5. INSERT fiscal_events                221-260
6. UPDATE terminal_state chain head    264-270
```

Reserved-type throw is in `eventVersionFor()` at line 167 — before
the SELECT, before the INSERT, before the UPDATE. State-unchanged
invariant is structurally guaranteed.

## Cross-task regression check

- **Task 1 (`FiscalEventType` enum):** the engine consumes
  `FiscalEventTypeValue` (the TS mirror) via the registry. No drift —
  Task 14's drift gate test (registry-vs-enum 28-case walk) keeps the
  enum + registry locked.
- **Task 8 (`fiscal_events` server-side table + integrity_exception_class):**
  no interaction; the engine writes only to the device table. The
  server-side `integrity_status` is set at ingestion-time by Task 17's
  `OutboxIngestor`, not here.
- **Task 11 (`SecondaryIntegritySealMissingException` + signature columns):**
  the engine writes `signature_status='not_required'` per spec v7 §5.2
  Phase 1 invariant. Signature provider is a separate track; the
  designed-for nullable columns are untouched here.
- **Task 13 (device SQLite `fiscal_events` v37 table):** the engine's
  INSERT and UPDATE shapes match the v37 column set exactly; the test
  suite runs against the real v37 schema (not a mock). 49/49 v37
  migration tests stay green — no schema regression from the engine's
  INSERT/UPDATE.
- **Task 13 round-2 GLOB BLOCKER lesson:** the engine produces
  `current_hash` via the same `HashChainIntegrityProvider` that the
  v3 receipt chain uses; output is always 64 lowercase-hex. The
  `previous_hash` CHECK constraint fires only on adversarial override —
  not on engine output. The empty-seed sentinel guard is the
  engine-layer mirror of the same defense.
- **Task 13 round-2 Codex P3 (empty-seed sentinel):** explicitly closed
  here — the engine throws `ChainHeadNotInitializedError` when
  `seq === 0 && seed === ''`. Test asserts the throw + state-unchanged
  post-condition.
- **Task 14 (FiscalEventPayloadRegistry + DTOs):** the TS registry is
  injected via constructor; reserved-type rejection is the registry's
  throw surfaced verbatim. Test imports `FiscalEventTypeNotImplementedError`
  from the registry module — the same exception type the registry test
  walks. No drift.
- **Task 14 round-2 Codex BLOCKER (`(type) row.key` cast):** explicitly
  defended here — the engine never uses the cast pattern. All SELECT
  rows go through `rowToResult()` → `requireString` / `requireNumber` /
  `requireExact` / `nullableString` runtime narrowing. A malformed DB
  row produces a field-named throw, not a silent type lie.

## Verdict reasoning

The implementation is a faithful spec §6.1 mapping. All six steps are
present in order; the reserved-type and empty-seed throws fire before
any state mutation; the canonical payload is exactly the fourteen
spec §4 fields; `canonical_bytes` storage is verbatim; the integrity
provider's version is injected (not hardcoded); the receipt-path
docblock makes the "inside caller's transaction" contract explicit;
the rollback test proves the no-side-effect invariant; the
integrity-verify test locks the server-side D2 contract; the
multi-terminal test proves seed isolation; the Task 13 round-2 P3
forward-looking input is closed; the Task 14 round-2 Codex BLOCKER
defense pattern is applied throughout the SELECT-row narrowing.

The two P2 findings are contract-clarity issues, not correctness
defects:
- P2-1 (asymmetric source pair caught by DB CHECK rather than engine
  guard) is "wrong but caught" — the chain head doesn't advance, no
  partial mutation is possible. The fix is hygiene + diagnostic
  quality.
- P2-2 (cross-tx race semantics implicit in docblock) is documentation
  tightening — the cross-tx contract is correct (SQLite locking +
  UNIQUE index serialize it); only its statement is implicit.

The three P3 findings are reader-navigability + dead-code-removal
nits. None of them touch the chain integrity or the spec contract.

**Verdict: APPROVE-WITH-MINOR-EDITS.** Suggest the author lands P2-1
(engine-level asymmetric-pair guard with a named exception, one test
case) and P2-2 (docblock paragraph on cross-tx semantics) as same-PR
edits — both are five-minute fixes that close two contract-clarity
gaps and would have surfaced on a Phase 2 caller's first review pass.
P3-1 (`defaultDb` dead code) is the only cosmetic that's worth a
"remove or document" decision today, not later. P3-2 / P3-3 can ride
along or defer.
