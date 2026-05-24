# Task 26 Opus Adversarial Review — `TERMINAL_REGISTRY_SNAPSHOT` + reserved `COMPANY_DAY_CLOSURE_MANIFEST` + provider-wiring verification

**Reviewer:** Opus 4.7 (1M context)
**Date:** 2026-05-19
**Commit:** `31d2c94a6` on `feat/pos-fiscal-event-engine-phase1`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.fiscal-phase1/`
**Plan reference:** `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md` lines 1952-2014
**Spec reference:** v7 §1 (device-authority pattern) + §11 (company-level integrity record types) + §7.3 (projector registry seam)

---

## Verdict — APPROVE-WITH-MINOR-EDITS

15-test discriminated-union matrix passes, 16 pre-existing `FiscalEventProjectionRegistry` tests stay green (no regression in `byName()`/`activeProjectorsFor()`), full `tests/Feature/Fiscal/` suite at **216 / 216 (37 PG-only skips on SQLite — as expected)**, PHPStan level 8 clean on both `TerminalRegistrySnapshotService.php` and `FiscalEventProjectionRegistry.php`. The provider file is genuinely untouched (`git show 31d2c94a6 -- .../FiscalServiceProvider.php` is empty). The architectural reading — server-authoring is a §11 carve-out from §1's device-authority rule — is spec-correct (see Premise Audit §1 below). CI PG-merge-gate filter extended correctly. The findings below are operational hardening, not correctness BLOCKERs — none of them touches the chain truth the test matrix already locks.

---

## Premise Audit (the 6 implementer self-flags + the architectural read)

### Architectural read — §11 as a server-authoring carve-out from §1 / D1

**VERIFIED, with one caveat.** Spec §11 lines 535-542 explicitly states `TERMINAL_REGISTRY_SNAPSHOT` "can be emitted at terminal provisioning and on demand". §1 / D1 governs *the device authoring of per-terminal transactions* (`SALE_RECEIPT`, chain-recovery events on the device's own chain). §11 introduces a *company-level* record type whose semantic is "operator/company-level fact, not per-device transaction" — and the spec contemplates an operator-driven emission path. **Cross-language drift gate (Task 14 standing pattern) is satisfied:** `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:547-551` explicitly carves `TERMINAL_REGISTRY_SNAPSHOT` out of TS-side authoring with the comment "No TS authoring path in Phase 1 — only server emits." Server-only authoring matches the TS engine's own self-description.

**Caveat (P2 below):** the spec phrase "emitted on demand" is silent on *which terminal's chain* the snapshot lands on, and the implementation's chain-splicing logic (resolveChainPlacement reads the current chain head from *any* fiscal_events row on that terminal) means a snapshot emitted "on demand" on a terminal that is actively device-authoring will silently advance the server-side chain head and the device's next sync will collide on `(tenant_id, terminal_id, sequence_number)`. The method is named `emitInitialSnapshot` (initial-only contract) but the implementation accepts N>1 without guarding the contract. Flagged P2-1 below.

### Self-flag #1 — Local canonical-encoder helper vs RFC 8785 / JCS

**ACCEPTED.** The snapshot payload's value space is strings (UUIDs, alphanumeric `^POS[0-9]{2}$` codes, terminal names, lowercase 64-hex), booleans (`is_active`), integers (`current_sequence`), and `null` (`hardware_identifier`). PHP `json_encode` with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR` over an ASCII-only key set (`terminal_id`, `code`, `name`, `is_active`, `hardware_identifier`, `genesis_seed`, `current_sequence`) is byte-identical to a full JCS implementation for this payload shape — ASCII byte order equals UTF-16 code unit order, no floats, no insignificant whitespace, no control bytes. The implementer's claim holds. **One known divergence:** the snapshot service does NOT strip U+2028 / U+2029 from terminal `name` (the JCS producer-side normalization §4 spec line 234). A maliciously-crafted terminal name with a line-separator codepoint would land in `canonical_bytes` and would fail a hypothetical future `StrictCanonicalParser::parse()` of this row. Out of scope for Task 26 (no parser-on-snapshot codepath exists in Phase 1) but worth a defensive `str_replace` if a P2 hardening pass touches this file.

### Self-flag #2 — No `StrictCanonicalParser` round-trip on server-authored bytes

**ACCEPTED for Phase 1.** Task 31's verifier (not yet written, but spec line 689 + the omission-guard test pin its contract) re-hashes `canonical_bytes` and asserts `current_hash` matches. It does NOT re-parse `canonical_bytes` to verify `payload`. The contract the snapshot service has to honour is `sha256(canonical_bytes) === current_hash`, which it does (line 122-160). The structural integrity of `canonical_bytes` is not currently verified end-to-end on the server.

### Self-flag #3 — No `fiscal_event_projections` rows for snapshots

**CORRECT.** Neither `PosCoreReceiptProjection` (handles `SALE_RECEIPT`) nor `TreasuryReceiptBridge` (handles `SALE_RECEIPT`) declares `handlesEventType(TERMINAL_REGISTRY_SNAPSHOT)`. `OutboxIngestor::dispatchProjections` (§7.5) would have produced zero rows for this event type anyway. The bypass of OutboxIngestor changes nothing observable about projection wiring.

### Self-flag #4 — `FiscalEventProjectionRegistry::all()` added

**BACKWARD-COMPATIBLE.** `all()` is a brand-new method returning a `Generator` over the already-materialized `$this->projectors` list (lines 221-226). It does not mutate `byName()` or `activeProjectorsFor()`. All 16 pre-existing tests pass (verified). The Generator yield is read-only — callers cannot mutate the registry's private list.

### Self-flag #5 — No `FiscalServiceProvider` edit

**VERIFIED.** `git show 31d2c94a6 -- apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php` returns empty. The two constructor parameters (`ConnectionInterface`, `FiscalIntegrityProvider`) are already bound by `FiscalServiceProvider::register()` from prior tasks (Task 1/17). The test `test_fiscal_service_provider_binds_the_seam_interfaces` exercises the cumulative wiring without prematurely asserting Task 31 — correct per the incremental-wiring convention.

### Self-flag #6 — Task 31 omission guard test

**DEFENSIVE, valuable.** `test_fiscal_service_provider_does_not_yet_wire_verify_event_chain_command` asserts `fiscal:verify-event-chain` is **not** in `Kernel::all()`. If a future task hastily wires the command in the provider without updating this test, the test fails immediately and surfaces the Task 26 / Task 31 boundary breach. Recommend extending the standing pattern (handoff §4.2) to call out the value of these "omission guards".

---

## Findings

### BLOCKER

_None._

### P1

_None._ All P1-class concerns either reduce to documented Phase 1 deferrals or are gated by the integration assumption that snapshots are emitted at provisioning only.

### P2

#### P2-1 — `emitInitialSnapshot()` method name promises initial-only; implementation silently handles N>1 with no guard

**File:** `apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php:92-196`

```php
public function emitInitialSnapshot(
    string $tenantId,
    string $companyId,
    string $terminalId,
    string $operatorId,
): FiscalEvent {
    ...
    // --- Step 4: resolve chain placement on the authoring terminal.
    [$sequenceNumber, $previousHash] = $this->resolveChainPlacement(
        tenantId: $tenantId,
        terminalId: $terminalId,
        genesisSeed: $genesisSeed,
    );
```

**Rule:** Spec §1 / D1 — *device authority is connectivity-independent*. The device's chain head is the source of truth. A server-side write that splices into a device-authored chain on a live terminal silently advances the server-side chain head — and the device's next sync's `(tenant_id, terminal_id, sequence_number)` insert collides at the unique constraint and gets quarantined as `sequence_conflict` (§8). The test `test_second_snapshot_links_to_prior_via_prior_snapshot_link_and_advances_sequence` confirms the splicing behaviour works (lines 325-356) but doesn't assert it's safe.

**Fix:** Either (a) make the service's contract strictly "first emission per terminal" — `resolveChainPlacement` throws `RuntimeException` when a prior `fiscal_events` row exists for the terminal — and rename the method to `emitInitialSnapshot` *means* initial; OR (b) keep the splicing semantics but rename to `emitSnapshot` and document the live-terminal hazard prominently. Option (a) matches the current method name and the test's framing (the test seeds a fresh terminal each run); option (b) preserves "on demand" emission per §11 but requires either a coordination protocol (server tells device to pause, snapshot, then device resumes from server's chain head) or restriction to non-live terminals. **Recommend option (a) for Phase 1** — "on demand" is a Phase 2+ concern; locking to first-emission only matches the method name, prevents the footgun, and the second-snapshot test rewrites to use a second terminal (which exercises the cross-terminal `prior_snapshot_link` sub-chain correctly anyway).

#### P2-2 — No concurrency guard on snapshot emission; race produces `QueryException` instead of a graceful retry

**File:** `apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php:163-193`

```php
$event = FiscalEvent::query()->create([
    'id' => (string) Str::uuid(),
    ...
    'sequence_number' => $sequenceNumber,
    ...
]);
```

**Rule:** `OutboxIngestor::insertOnConflictDoNothingReturningId()` (§7.2 Step 2, `OutboxIngestor.php:661-700`) wraps every fiscal-event write in `INSERT ... ON CONFLICT (tenant_id, terminal_id, sequence_number) DO NOTHING RETURNING id` precisely to make the insert race-safe — two concurrent posts of the same sequence slot resolve at the DB rather than throwing. The snapshot service uses a plain Eloquent `create()` with no conflict handler. Two concurrent `emitInitialSnapshot` calls (e.g., an admin tab + an automated provisioning script) will both read sequence N from `resolveChainPlacement`, both attempt to insert N+1, and the loser bubbles up a `QueryException` to the caller.

**Fix:** Either (a) wrap the read-chain-head + insert in a serializable transaction with retry; OR (b) use the same `INSERT ... ON CONFLICT ON CONSTRAINT fiscal_events_tenant_terminal_sequence_unique DO NOTHING RETURNING id` primitive as `OutboxIngestor` and re-read the chain head on conflict. Option (b) matches the architectural pattern; option (a) is simpler. Either fix should add a test that simulates the race (two concurrent calls, expect exactly one row written + a documented conflict outcome on the other). For Phase 1, where the realistic concurrency surface is "admin clicks twice" rather than high-volume traffic, P2 — not P1 — is the correct severity.

#### P2-3 — `event_time_device` is set to server `now()` on a server-authored event; semantically misleading

**File:** `apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php:142-146`

```php
$now = Carbon::now('UTC');
$envelope = [
    'business_date' => $now->copy()->startOfDay()->toDateString(),
    'company_id' => $companyId,
    'event_time_device' => $now->format('Y-m-d\TH:i:s\Z'),
    ...
];
```

**Rule:** §3.2 + §10 — `event_time_device` is the device's untrusted timestamp at emission. For a server-authored event, the field's semantic ("device clock") doesn't apply; populating it with `server.now()` (which IS trusted) silently muddies the audit story. The field is required by the canonical envelope (14-key set), so a value is mandatory — but the value type matters.

**Fix:** Document the convention explicitly in the docblock (one sentence: "For server-authored events, `event_time_device` is the server's authoring timestamp; the device-time semantic does not apply"). No code change required — the value satisfies the envelope regex and the chain integrity contract — but the audit story benefits from the disambiguation. Optionally consider populating `last_server_time_seen` with `$now` so a downstream verifier can detect "this row has device_time == server_time, therefore server-authored" — a defensive marker rather than a contract.

### P3

#### P3-1 — Terminal `name` field is not U+2028/U+2029-stripped

**File:** `apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php:239`

```php
'name' => is_string($row->name) ? $row->name : (string) $row->name,
```

**Rule:** Spec §4 line 234 — "UTF-8 NFC strings with U+2028/U+2029 stripped at the producer". Snapshot service is a producer.

**Fix:** Apply the same `str_replace(["\u{2028}", "\u{2029}"], '', $value)` normalization that the TS-side `FiscalEventCanonicalEncoder` applies. The current `pos_terminals.name` schema (varchar(100)) doesn't validate against these codepoints, so a deliberate or accidental terminal name with one of them would land in `canonical_bytes` and break a hypothetical future structural re-parse.

#### P3-2 — `findPriorSnapshotHash` orderBy fallback is `created_at DESC, sequence_number DESC` — both can be wrong as tiebreakers

**File:** `apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php:262-269`

```php
$prior = $this->db->table('fiscal_events')
    ->where('tenant_id', $tenantId)
    ->where('company_id', $companyId)
    ->where('event_type', FiscalEventType::TERMINAL_REGISTRY_SNAPSHOT->value)
    ->where('integrity_status', IntegrityStatus::Verified->value)
    ->orderByDesc('created_at')
    ->orderByDesc('sequence_number')
    ->first(['payload']);
```

**Rule:** When snapshots span terminals (per §11, snapshots form a company-level sub-chain regardless of carrier terminal), `sequence_number` is per-terminal and not meaningful as a cross-terminal tiebreaker. `created_at` ordering is correct as the primary sort; the secondary `sequence_number` sort is at best noise and at worst incorrect (terminal A's seq 5 ordered "after" terminal B's seq 3 when their `created_at` values are equal).

**Fix:** Drop the `orderByDesc('sequence_number')` secondary clause OR replace with `orderByDesc('id')` (UUIDv4 is a stable but meaningless tiebreaker — clearly arbitrary, won't be misread as semantic). For Phase 1's realistic emission cadence (one snapshot per provisioning event), this is a P3 / docs-clarification finding, not a behavioural fix.

#### P3-3 — `payloadArray` is passed straight to the JSONB column; Laravel's `'array'` cast re-encodes with unsorted keys

**File:** `apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php:191`

```php
'payload' => $payloadArray,
```

The Eloquent `'payload' => 'array'` cast (`FiscalEvent::casts()`) runs `json_encode($payloadArray)` on write — unsorted keys, no UTF-8 unescaped flags, etc. The `payload` column ends up structurally identical-but-textually-different from the `canonical_bytes` payload subtree. **This is not a bug** — the verifier walks `canonical_bytes`, and reads of `payload` parse it back to an associative array where key order is irrelevant. But the docblock at line 36-50 implies `payload` and `canonical_bytes` carry "the same" content, which is true semantically but not textually.

**Fix:** Add one docblock line: "`payload` (JSONB column) is the parsed structural copy of the payload subtree for SQL queryability; `canonical_bytes` is the authoritative byte representation. Their JSON encodings will not be byte-equal because the JSONB column's encoding does not use sorted-key canonical encoding." Optional only.

### CLEAN

- 14-key envelope shape matches `StrictCanonicalParser::ENVELOPE_KEYS` exactly (lines 143-158 vs `StrictCanonicalParser.php:75-90`). A hypothetical future structural re-parse of `canonical_bytes` would pass envelope-shape validation.
- Payload key set (`terminals`, `snapshot_hash`, `prior_snapshot_link`) matches `FiscalPayloadConstraintValidator::PAYLOAD_KEYS['TERMINAL_REGISTRY_SNAPSHOT']` exactly (line 82-84).
- `previous_hash` chain anchor matches Task 19 T19-B3: first event uses `pos_terminals.genesis_seed`; subsequent events use `prior.current_hash`. Test `test_first_snapshot_links_previous_hash_to_terminal_genesis_seed` locks the first-event invariant; test `test_second_snapshot_links_to_prior_via_prior_snapshot_link_and_advances_sequence` locks the subsequent-event invariant.
- `signature_status = NotRequired`, `signature_version = 'hash-chain-integrity-v1'` — matches §5.1 / §5.2 Phase 1 default ("Every Phase 1 event is `signature_status = 'not_required'`").
- `integrity_status = Verified` + `payload_parse_status = Parsed` on INSERT — the immutability trigger (`2026_05_14_100002_create_fiscal_events_immutability.php`) is BEFORE UPDATE; INSERTs are not gated, so direct insertion of these terminal states is permitted by the schema.
- Unknown-terminal-id boundary fail-closed (line 105-113) — service throws `RuntimeException` rather than writing a row whose `previous_hash` cannot reconcile with a chain head. Test `test_unknown_terminal_id_throws_at_service_boundary` locks the contract.
- Soft-deleted terminals are excluded via explicit `whereNull('deleted_at')` — service is boundary-clean (no Eloquent SoftDeletes scope dependency). Test `test_soft_deleted_terminals_are_excluded_from_snapshot` locks the filter.
- Other-tenant terminals excluded — test `test_other_company_terminals_are_excluded_from_snapshot` locks tenant + company scope.
- Deterministic terminal ordering by `code` ASC — test `test_multiple_company_terminals_appear_in_snapshot_sorted_by_code` locks the canonical ordering.
- `FiscalEventProjectionRegistry::all()` Generator preserves the constructor-sorted (`priority ASC, name ASC`) order; 16 pre-existing registry tests stay green.
- Constructor-injection-only (CLAUDE.md rule 13) — `ConnectionInterface` + `FiscalIntegrityProvider` via `private readonly`. No `app()` helper, no `new` of dependencies.
- PHPStan level 8 clean on both files.
- CI PG-merge-gate filter extended correctly (`.github/workflows/ci.yml:411`) with a multi-paragraph rationale comment.
- Discriminated-union coverage (15 tests, 56 assertions): happy path, integrity columns, first-event genesis anchor, payload identity columns, hash-of-canonical-list anchor, multi-terminal sort, soft-delete exclusion, cross-tenant exclusion, second-snapshot sub-chain + sequence advance, unknown-terminal boundary, `COMPANY_DAY_CLOSURE_MANIFEST` reserved-not-implemented contract, provider seam bindings, Task 31 omission guard, both projectors named-resolvable. Strong matrix for a Phase-1-only emitter.

---

## Standing patterns sweep (handoff §4.2)

| Pattern | Status |
|---|---|
| Constructor injection only (CLAUDE.md rule 13) | CLEAN |
| Discriminated-union test matrix (Task 20+) | CLEAN — 15 tests across happy / chain-anchor / scoping / sub-chain / boundary |
| Verify the premise of every deferral (Task 19 T19-B3) | DONE — 6 self-flags + architectural read independently verified above |
| Cross-language drift gate (Task 14) | CLEAN — TS engine acknowledges TERMINAL_REGISTRY_SNAPSHOT is server-only at `FiscalEventEngine.ts:547-551` |
| CI PG-merge-gate filter extended for PG-only constraints (CHECK + enum whitelist) | CLEAN — `.github/workflows/ci.yml:411` |
| DB primitives spec-named are load-bearing (Task 19) | NOT MATCHED — see P2-2 (`INSERT ... ON CONFLICT` not used); not a BLOCKER for Phase 1 but mismatched with OutboxIngestor's documented pattern |
| Boot-time invariants in constructors (Task 18) | NOT APPLICABLE — service has no boot-time invariants beyond constructor injection |
| Atomic transaction boundaries (Task 24) | NOT APPLICABLE — no projection write to coordinate with |

---

## Summary

Task 26 ships a server-authored `TERMINAL_REGISTRY_SNAPSHOT` emitter that correctly resolves §11's "company-level integrity record type" semantic as a carve-out from §1 / D1's device-authority pattern, with the cross-language drift gate satisfied (the TS engine carves the type out of its own authoring switch). The 15-test discriminated-union matrix locks the payload shape, chain placement, scoping, and reserved-not-implemented contract. `FiscalEventProjectionRegistry::all()` is a clean read-only addition (Generator yield) with no regression in 16 pre-existing tests. `FiscalServiceProvider` is genuinely untouched. PHPStan level 8 clean. Full Fiscal suite 216 / 216 (37 PG-only skips). The three P2 findings are operational hardening — chain-head splicing on a live terminal, concurrency race producing QueryException rather than graceful conflict, and a semantic mismatch on `event_time_device` for server-authored events — none of which mutates the chain truth the test matrix already locks. Recommend addressing P2-1 (rename / first-emission guard) before any second caller of `emitInitialSnapshot` lands in a future task; P2-2 and P2-3 can ride the next architectural cluster's hardening pass.

VERDICT: APPROVE-WITH-MINOR-EDITS
