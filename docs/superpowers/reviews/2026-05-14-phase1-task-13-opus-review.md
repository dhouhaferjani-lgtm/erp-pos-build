# Opus review — Phase 1 Task 13 (device SQLite fiscal_events table)

**Commit:** `442ea109`
**Branch:** `feat/pos-fiscal-event-engine-phase1`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1`
**Reviewer:** Opus (headless adversarial review gate)
**Date:** 2026-05-16
**Verdict:** **REQUEST-CHANGES** — 1 BLOCKER, 0 P1, 5 P2, 1 P3.

## Summary

Schema shape, immutability triggers, terminal-state chain head, and `offline_receipts.canonical_bytes` all match spec v7 §3.1 / §3.3 / §16 line item 6. The `BEFORE UPDATE OF <enumerated chain columns>` trigger correctly enumerates **every** non-sync column (33 columns — audited line-by-line against the table DDL) and correctly allows `sync_status` / `sync_error` / `synced_at` writes; the `BEFORE DELETE` trigger is unconditional. The UNIQUE chain key, the partial unique on `(source_event_class, source_event_id) WHERE source_event_id IS NOT NULL`, the sync-pending partial index, and the two reference back-lookup partial indexes are all present and correctly shaped. Legacy `last_hash` / `hash_sequence` / `genesis_seed` on `terminal_state` are preserved as mirrors. Migration is idempotent (re-run no-ops via `CREATE … IF NOT EXISTS` + ALTER `duplicate column` swallow).

**However** — the hash-format CHECK constraint on `current_hash` / `previous_hash` is **materially broken**. The pattern `GLOB '[0-9a-f]*'` only validates the **first character**; positions 2–64 can be ANY character. Verified empirically against `node:sqlite`: `'a' + 'X'.repeat(63)` (64 chars, leading `a`, no underscores) is **accepted** by the CHECK as shipped. The defense-in-depth guard does not guard. Worse, the test only exercises `current_hash: 'NOT_HEX'` (length 7, caught by the length predicate) — so the test passes despite the broken pattern, a false-positive that mirrors exactly the bug class the Codex reviewers caught on Tasks 7 and 11.

The remaining findings are coverage gaps: `sequence_number > 0`, the sync_status / signature_status enum CHECKs, and the source-event NULL-or-both pair CHECK are not exercised; the trigger's column-list completeness is sampled (3 of 33) not enumerated; and the "existing rows backfill cleanly" test inserts a fresh row after the migration rather than proving the ALTER TABLE backfill against a row that pre-dates the migration. None of these are correctness defects in the shipping schema, but a future regression in any of them would not surface as a test failure.

## Findings

### BLOCKER (must fix before merge)

#### BLOCKER-1 — Hash-format CHECK constraint accepts non-hex hashes (proven by reproducer)

**File:** `apps/pos/src/lib/db/migrations.ts:966-967`

```sql
CHECK (length(current_hash)  = 64 AND current_hash  GLOB '[0-9a-f]*' AND length(replace(current_hash,  '_', '')) = 64),
CHECK (length(previous_hash) = 64 AND previous_hash GLOB '[0-9a-f]*' AND length(replace(previous_hash, '_', '')) = 64),
```

In SQLite GLOB, `[0-9a-f]` matches a single character against the character class, and `*` matches **any sequence of any characters** (including non-hex). So `'[0-9a-f]*'` is equivalent to "first character is lowercase hex; the rest can be anything." The two-clause AND also fails to close the hole — `length(replace(h, '_', '')) = 64` only catches underscores specifically (which wouldn't have been hex anyway), not arbitrary non-hex characters.

**Empirical reproducer** (run from `/tmp/v37_blocker_proof.cjs`, repeated here):

```sql
CREATE TABLE fiscal_events (
  id TEXT PRIMARY KEY,
  current_hash TEXT NOT NULL,
  previous_hash TEXT NOT NULL,
  CHECK (length(current_hash)  = 64 AND current_hash  GLOB '[0-9a-f]*' AND length(replace(current_hash,  '_', '')) = 64),
  CHECK (length(previous_hash) = 64 AND previous_hash GLOB '[0-9a-f]*' AND length(replace(previous_hash, '_', '')) = 64)
);
-- 'a' followed by 63 'X's: 64 chars, first char lowercase hex, no underscores
INSERT INTO fiscal_events VALUES ('e1', 'aXXX…XXX', 'aXXX…XXX');   -- ACCEPTED
```

Output: `SHIPPED CHECK: BOGUS HASH ACCEPTED — guard is materially broken.`

The server-side commit `245f5e85` correctly used PG regex `~ '^[0-9a-f]{64}$'` — the canonical anchored full-match pattern. The device-side SQLite equivalent must do the same. SQLite's `GLOB` does not support `^`/`$` anchors (the whole string is always matched), so the two canonical fixes are:

**Fix A (preferred — single GLOB, atomic):**
```sql
CHECK (length(current_hash)  = 64 AND current_hash  NOT GLOB '*[^0-9a-f]*'),
CHECK (length(previous_hash) = 64 AND previous_hash NOT GLOB '*[^0-9a-f]*'),
```
`NOT GLOB '*[^0-9a-f]*'` asserts "there is no character anywhere in the string that is not lowercase hex." Verified against the same reproducer: rejects `'aXXX…'`, rejects `'A'×64` (uppercase), rejects underscores, accepts `'a'×64`. This is the canonical SQLite idiom for "every character matches a class."

**Fix B (explicit but verbose — 64-char literal pattern):**
```sql
CHECK (current_hash GLOB '[0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f]…')
```
64 repetitions of `[0-9a-f]`. Length is implicit. Visually noisy; equivalent in behavior to Fix A.

**Why BLOCKER, not P1:** the CHECK is positioned as "defense-in-depth alongside the producer-side encoder" (line 925-926). It does not perform that role today. Any chain-content corruption that produces a 64-char string whose first character happens to be lowercase hex slips past the device DB's last-line-of-defense, lands in the event row, and rides the sync to the server — where (assuming the server's correct regex CHECK fires) it gets rejected at OutboxIngestor as `canonical_hash_mismatch`. So the **damage is recoverable at the server boundary**, which is why this is a BLOCKER and not a "data integrity emergency." But: (a) the constraint is documented in the migration PHPDoc as a defense-in-depth guard and reviewers will rely on it being so; (b) the test passes despite the bug, normalizing a false-positive RED→GREEN signal that future maintainers should not inherit; (c) the fix is one-line.

**Suggested commit message for the fix:** `fix(fiscal): close v37 hash-format CHECK — GLOB '[0-9a-f]*' only validated first char`.

The accompanying test update (the test currently only exercises a length-failing string) should add at least one case that is length-64 but contains non-hex characters mid-string, e.g.:

```ts
it('rejects 64-char strings with non-hex characters after the first position', async () => {
  await expect(
    insertFiscalEvent(adapter, {
      id: 'bad-midstring',
      sequence_number: 1,
      current_hash: 'a' + 'X'.repeat(63),
    }),
  ).rejects.toThrow();
});
```

That test currently **passes on RED** (i.e. would pass against the broken migration); after the fix it would correctly RED→GREEN.

### P1

None.

### P2 (should fix in this PR; defer is acceptable)

#### P2-1 — `sequence_number > 0` CHECK not exercised

**File:** `apps/pos/src/lib/db/__tests__/migrations.v37.test.ts`

The migration declares `CHECK (sequence_number > 0)` (line 965) — sequence 0 is reserved for the genesis seed on `terminal_state`, not a chain row. The test never inserts a fiscal_event with `sequence_number = 0` or negative; a future "fix" PR that drops this CHECK or changes the operator would not surface as a test failure. Adds one ~6-line case:

```ts
it('rejects sequence_number = 0 and negative values', async () => {
  await expect(
    insertFiscalEvent(adapter, { id: 'zero', sequence_number: 0 }),
  ).rejects.toThrow();
  await expect(
    insertFiscalEvent(adapter, { id: 'neg', sequence_number: -1 }),
  ).rejects.toThrow();
});
```

#### P2-2 — Enum CHECKs (`sync_status`, `signature_status`) not exercised

**File:** `apps/pos/src/lib/db/__tests__/migrations.v37.test.ts`

Migration lines 968-969 enforce `sync_status IN ('pending','syncing','synced','failed')` and `signature_status IN ('not_required','pending','signed','failed')`. Neither is tested. The test asserts allowed UPDATE values for `sync_status` (case `'allows UPDATE of sync_status'`) but does not assert rejection of out-of-set values like `'bogus'`. Add one case each:

```ts
it('rejects out-of-set sync_status / signature_status values', async () => {
  await insertFiscalEvent(adapter, { id: 'e1', sequence_number: 1 });
  await expect(
    adapter.execute(`UPDATE fiscal_events SET sync_status = 'bogus' WHERE id = 'e1'`),
  ).rejects.toThrow();
  // signature_status: tested via INSERT since UPDATE is blocked by the trigger
  await expect(
    insertFiscalEvent(adapter, {
      id: 'e2',
      sequence_number: 2,
      // ... requires extending insertFiscalEvent to accept signature_status override
    }),
  ).rejects.toThrow();
});
```

Note the second assertion requires extending `insertFiscalEvent` to override `signature_status` (it currently hardcodes the default). Trivial.

#### P2-3 — Source-event NULL-or-both CHECK not exercised

**File:** `apps/pos/src/lib/db/__tests__/migrations.v37.test.ts`

Migration lines 970-973 enforce `(source_event_class IS NULL AND source_event_id IS NULL) OR (both not null)`. The test covers the two valid cases (both NULL, both present) — case `'enforces source-event idempotency via partial unique index'` — but never inserts an asymmetric pair (one NULL, one not) to confirm the CHECK fires. Add:

```ts
it('rejects asymmetric source_event_class / source_event_id', async () => {
  await expect(
    insertFiscalEvent(adapter, {
      id: 'e1',
      sequence_number: 1,
      source_event_class: 'Offline\\Receipt',
      source_event_id: null,
    }),
  ).rejects.toThrow();

  await expect(
    insertFiscalEvent(adapter, {
      id: 'e2',
      sequence_number: 2,
      source_event_class: null,
      source_event_id: 'src-uuid-1',
    }),
  ).rejects.toThrow();
});
```

#### P2-4 — Trigger column-list completeness not enumerated in tests (3 of 33 sampled)

**File:** `apps/pos/src/lib/db/__tests__/migrations.v37.test.ts:220-234`

The `BEFORE UPDATE OF <…>` trigger enumerates 33 non-sync columns; the test exercises 3 of them (`current_hash`, `sequence_number`, `canonical_bytes`). A future PR that "tidies up" the trigger by dropping, e.g., `partner_id` from the column list would silently lift the immutability guard for that column and not surface as a test failure.

**Suggested fix:** loop over all 33 columns (or a representative ~10 covering each "category" — identity, chain, timing, signature, source, reference, partner). A data-driven test of the form:

```ts
const nonSyncColumns = [
  'id', 'tenant_id', 'company_id', 'terminal_id', 'operator_id',
  'event_type', 'event_version', 'signature_version', 'sequence_number',
  'event_time_device', 'business_date', 'last_server_time_seen',
  'reference_event_id', 'reference_document_id',
  'source_event_class', 'source_event_id',
  'partner_id', 'partner_identity_snapshot',
  'canonical_bytes', 'previous_hash', 'current_hash',
  'signature_status', 'signature_algorithm', 'signature_value',
  'signature_counter', 'signature_provider', 'signing_device_id',
  'certificate_id', 'signed_payload_ref',
  'time_source_value', 'time_format', 'provider_transaction_id',
  'created_at',
];

it.each(nonSyncColumns)('blocks UPDATE OF %s', async (col) => {
  await insertFiscalEvent(adapter, { id: 'e-' + col, sequence_number: nextSeq() });
  await expect(
    adapter.execute(`UPDATE fiscal_events SET ${col} = 'x' WHERE id = 'e-${col}'`),
  ).rejects.toThrow();
});
```

(Each case needs a unique sequence_number — easy via a closure counter.)

**Why P2, not BLOCKER:** the column list as shipped is correct (I audited it: every non-sync column in the table DDL appears in the trigger column list — see "Verification I ran"). The risk is forward-looking regression, not current correctness.

#### P2-5 — "Existing rows backfill cleanly" test does not actually exercise the backfill path

**File:** `apps/pos/src/lib/db/__tests__/migrations.v37.test.ts:285-303`

The test name says "existing rows backfill cleanly" but the test body inserts a fresh row **after** the migration has already added the three columns:

```ts
beforeEach(async () => {
  adapter = new SqliteTestAdapter();
  await runMigrationsUpTo(adapter, 37);     // ← v37 runs here
});
// ...
await adapter.execute(
  `INSERT INTO terminal_state (terminal_id, terminal_code, genesis_seed, last_hash)
   VALUES ('t-new', 'T01', 'seed', 'GENESIS')`,
);                                          // ← fresh row inserted AFTER v37
```

What this actually proves is "fresh INSERTs with omitted columns use the column DEFAULTs." It does not prove the genuine `ALTER TABLE ADD COLUMN NOT NULL DEFAULT 'x'` backfill semantics on a row that pre-dates the migration. To exercise the backfill, the test would need to run migrations up to **v36**, insert a row, **then** run v37, and check the existing row's new columns.

The behavior under test (modern SQLite's ALTER TABLE backfill) is well-defined and not at risk — this is a test-clarity / test-intent gap, not a correctness gap. But the test name promises something the test does not deliver, and a future reader will be misled.

**Suggested fix:** either rename to "fresh INSERTs adopt chain-head DEFAULTs" (zero-code change) or rewrite to actually exercise the backfill:

```ts
it('backfills the chain-head columns onto rows that pre-date v37', async () => {
  const freshAdapter = new SqliteTestAdapter();
  try {
    await runMigrationsUpTo(freshAdapter, 36);
    await freshAdapter.execute(
      `INSERT INTO terminal_state (terminal_id, terminal_code, genesis_seed, last_hash)
       VALUES ('t-pre-v37', 'T01', 'seed', 'GENESIS')`,
    );
    // Now run v37
    const v37 = migrations.find((m) => m.version === 37);
    if (!v37?.run) throw new Error('v37 not found');
    await v37.run(freshAdapter);

    const rows = await freshAdapter.select<…>(`SELECT …`);
    expect(rows[0]?.fiscal_event_genesis_seed).toBe('');
    // …
  } finally {
    freshAdapter.close();
  }
});
```

### P3 (nice-to-have)

#### P3-1 — `nodeSqliteAvailable` guard duplicated across v37 / v32 / v31 / v30 / v28 tests

**File:** `apps/pos/src/lib/db/__tests__/migrations.v37.test.ts:5-14` (and identical blocks in 4 sibling tests)

The 10-line `nodeSqliteAvailable` IIFE + `const d = nodeSqliteAvailable ? describe : describe.skip` idiom is repeated verbatim across five test files. Extracting it to `helpers/sqliteTestAdapter.ts` (or a sibling `helpers/nodeSqliteGuard.ts`) would reduce ~50 lines of cross-file duplication and make a single point of truth for the gate. Not load-bearing for this task; deferrable.

## What works well

- **Trigger column-list completeness.** I audited the trigger's `UPDATE OF` enumeration (lines 1023-1034) against the table DDL (lines 928-974) column-by-column. All 33 non-sync columns appear in the trigger list; the 3 sync columns (`sync_status`, `sync_error`, `synced_at`) are correctly excluded. No omissions, no extras. The "BEFORE UPDATE OF" pattern correctly captures any attempt to mutate chain content while leaving the sync flusher's lifecycle writes intact — matches spec v7 §3.3 (device) verbatim.

- **BEFORE DELETE trigger is unconditional.** No WHEN clause; no row-pattern guard. Bulk `DELETE FROM fiscal_events` is atomically rejected (verified empirically — 0 rows mutate; the trigger's RAISE(ABORT) aborts the transaction wholesale before any DELETE applies).

- **UNIQUE chain key + partial unique on source-event pair.** `(tenant_id, terminal_id, sequence_number)` UNIQUE matches spec §3.2 (server) and the device contract — the test exercises both directions (different terminal at same sequence OK; same terminal + sequence rejected). The source-event partial UNIQUE `WHERE source_event_id IS NOT NULL` correctly excludes NULL pairs from the uniqueness check (two events with NULL source can coexist, two events with the same non-NULL `(class, id)` cannot).

- **Legacy mirror columns preserved.** `terminal_state.last_hash`, `hash_sequence`, `genesis_seed` are untouched by the migration. The test affirmatively checks all three still exist. The receipt-V3 chain head reads continue to work, consistent with spec §14 line 589 ("`offline_receipts` becomes a projection row mirroring the `fiscal_events` chain values, gains `canonical_bytes`").

- **`offline_receipts.canonical_bytes` is nullable.** Matches spec §16 line 717 and Task 15's deferred wiring contract — projection-only callers continue to function until the assembler path lands. PHPDoc on the migration explicitly cites Task 15. ✓

- **No-default `created_at`.** Migration declares `created_at TEXT NOT NULL` with no `DEFAULT (datetime('now'))` — forces the producer to stamp, matching spec §3.1 line 136 (the spec deliberately omits a default on `created_at` for the device row; only `sync_status` and `signature_status` carry defaults). ✓

- **Idempotency.** Re-running the migration is a no-op. `CREATE TABLE IF NOT EXISTS` / `CREATE … INDEX IF NOT EXISTS` / `CREATE TRIGGER IF NOT EXISTS` for the new objects; ALTER TABLE statements wrapped in `duplicate column` swallow. The test exercises this directly (`it('is idempotent when applied a second time …')`).

- **`isMultiStatement` heuristic compatibility.** The trigger CREATE statements contain a `;` inside the trigger body (between `RAISE(…)` and `END;`), which would trip naive single-statement parsers. The `SqliteTestAdapter.isMultiStatement` heuristic correctly routes these through `inner.exec()` (verified empirically). The production Tauri runner uses sqlx, which handles trigger CREATE as a single statement at the parser level — no compatibility concern.

- **CI inheritance.** The commit message notes that `apps/pos pnpm test` already runs in the existing pos-test job (`.github/workflows/ci.yml:461`) — no filter changes needed. Unlike Tasks 7-12 (which required a PG merge-gate filter update), Task 13's device-side test path is inherited from the existing CI matrix. ✓

## Verification I ran

- **`git show 442ea109 --stat`** — confirmed 2 files changed (`migrations.ts` +185, `migrations.v37.test.ts` +363). No other files touched. Cross-task surface clean.

- **Trigger column-list audit (manual diff)** — enumerated all 36 columns in the table DDL (`migrations.ts:929-964`) and checked each against the trigger's `UPDATE OF` list (`:1023-1034`). Result: 33 non-sync columns in the trigger list; 3 sync-lifecycle columns (`sync_status`, `sync_error`, `synced_at`) correctly excluded. No omissions.

- **`cd apps/pos && pnpm test -- --run migrations.v37`** — all 12 tests pass (266 ms). `cd apps/pos && pnpm test -- --run migrations.integration migration22.integration` — 21 tests pass (no regression in sibling integration tests).

- **BLOCKER-1 empirical reproduction** — ran `/tmp/v37_blocker_proof.cjs` (CommonJS, `node:sqlite`):
  - Created a table with the v37 CHECK constraints verbatim.
  - Inserted `current_hash = 'a' + 'X'.repeat(63)` (64 chars, leading lowercase hex, no underscores).
  - **Result: INSERT succeeded.** The CHECK accepted the bogus hash. Output: `SHIPPED CHECK: BOGUS HASH ACCEPTED — guard is materially broken.`
  - For comparison, ran `/tmp/glob_test2.cjs` with the corrected pattern `NOT GLOB '*[^0-9a-f]*'`:
    - Same bogus hash rejected.
    - All-`A` uppercase rejected.
    - Underscore rejected.
    - 64 lowercase `a` accepted.
  - The corrected pattern is what the migration should have shipped.

- **`BEFORE UPDATE OF` trigger semantics check** — ran `/tmp/v37_trigger_semantics.cjs`:
  - `UPDATE t SET sync = 'synced'` (only sync column) — allowed.
  - `UPDATE t SET a = 'xx'` (column in trigger list) — blocked.
  - `UPDATE t SET a = 'xx', sync = 'synced'` (mixed) — blocked (a is in the trigger list).
  - Confirms the trigger fires correctly on column-level SET-list membership.

- **BEFORE DELETE atomicity** — ran `/tmp/v37_delete_test.cjs`:
  - Inserted 2 rows, ran `DELETE FROM fiscal_events`, trigger raised, 0 rows mutated.
  - Confirms atomic rejection of bulk DELETE.

- **`isMultiStatement` heuristic check** — ran `/tmp/v37_multistmt.cjs` against the trigger CREATE SQL:
  - Returns `true` → trigger SQL routes through `inner.exec()` correctly.

- **Spec conformance** — read `docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md` §3.1 (device column set, lines 96-139), §3.3 (immutability triggers, lines 209-225), §16 line item 6 (migration plan, line 716). Every required device column appears in the migration; the spec's `signature_*` column set matches; `sync_status`/`signature_status` defaults match (`'pending'` / `'not_required'` respectively); no extras.

- **Cross-task regression** — confirmed v37 does not modify any file related to Tasks 1-12. `git show 442ea109 --stat` lists 2 files; neither is a Task 1-12 surface.

- **Comparison with server-side Task 7** — `git show 245f5e85` (server PG `create_fiscal_events_table`): the PG migration uses `~ '^[0-9a-f]{64}$'` (anchored regex) for hash format. That is the canonical pattern. The device migration's SQLite-translation of "this regex" should have been `NOT GLOB '*[^0-9a-f]*'` (or 64-char literal), not `GLOB '[0-9a-f]*'`. The implementer chose the loose pattern, which is **not equivalent** to the server's anchored regex.

## Cross-task regression check

| Prior task | Files touched in `442ea109`? | Verdict |
|---|---|---|
| Tasks 1-12 (server-side schema, enums, encoders, FiscalEvent model) | No | ✓ no regression |
| `apps/pos/src/lib/db/migrations.ts` v1-36 | Untouched — v37 is appended | ✓ no regression |
| `apps/pos/src/lib/db.ts` (migration runner) | Untouched — picks v37 up automatically | ✓ — production path verified to call `migration.run(db)` |
| `offline_receipts` schema (existing v3 columns + later ALTERs) | Only `canonical_bytes` added (nullable) | ✓ no regression |
| `terminal_state` schema (existing v5 columns + v9, v13, v22, v28 ALTERs) | Three new columns added (NOT NULL DEFAULT) | ✓ no regression — existing rows backfill via SQLite ALTER semantics |
| Sibling test suites (`migrations.v28/v30/v31/v32/integration`) | Untouched | ✓ — `pnpm test` for these files stays green |

## Recommendation

**REQUEST-CHANGES.** Fix the hash-format CHECK constraint (BLOCKER-1) — replace `GLOB '[0-9a-f]*'` with `NOT GLOB '*[^0-9a-f]*'` and remove the now-redundant `length(replace(h, '_', ''))` clause. Add one regression test that exercises a 64-char string with non-hex characters mid-string. Then `pnpm test -- --run migrations.v37` should remain at 12 → 13 passing tests.

P2-1 through P2-5 are coverage / clarity gaps and may be folded into the BLOCKER fix commit or deferred to a follow-up. None of them are correctness defects in the shipping schema.

After the BLOCKER fix:
1. Re-run `pnpm test -- --run migrations.v37` → all tests (incl. the new non-hex-midstring case) green.
2. Re-run `pnpm test -- --run migrations.integration` → all 13 sibling tests green.
3. Spot-check by re-running the `/tmp/v37_blocker_proof.cjs` reproducer with the corrected CHECK substituted in — should now REJECT the bogus hash.

The architecture-locked constraints (device authority, chain UNIQUE shape matching server §3.2, immutability triggers matching `offline_receipts` discipline, legacy mirror columns preserved, `canonical_bytes` nullable on device per Task 15 deferral) are all correctly realized. The single substantive bug is the SQLite GLOB pattern — recoverable with a one-line fix.
