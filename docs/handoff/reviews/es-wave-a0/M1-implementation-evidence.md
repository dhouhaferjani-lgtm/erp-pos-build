# M1 implementation evidence — ES-08 honest fiscal-event verification

## Result

`fiscal:verify-event-chain` now detects all four ES-08 incident classes without
changing its 0/1/2 single-chain contract:

| Check | Isolated fixture | Clean control | Disable proof |
|---|---|---|---|
| `payload` ↔ `canonical_bytes` semantics | parsed payload reason differs from the strictly parsed frozen envelope | same payload with object-key-order-insensitive comparison | disabling only the semantic comparison made the divergence fixture exit 0 while the clean control remained green |
| `integrity_status` | internally hash-valid `quarantined` row | existing valid seeded chain | disabling only the status check made the quarantined fixture exit 0 while the valid chain remained green |
| sealed coordinate set | frozen `company_id` differs from stored row | matching parsed envelope fixture | disabling only coordinate comparison made that fixture exit 0 while the matching parsed fixture remained green |
| `sequence_number` contiguity | sequence 1 links directly to sequence 3 with valid hashes/linkage | existing valid seeded chain | disabling only the gap check made the gap fixture exit 0 while the valid chain remained green |

The sealed coordinate set compared for every successfully parsed row is:
`business_date`, `chain_context`, `company_id`, `event_time_device`,
`event_type`, `event_version`, `operator_id`, `previous_hash`,
`reference_document_id`, `reference_event_id`, `sequence_number`,
`signature_version`, `tenant_id`, and `terminal_id`.

T-a is exercised through the real `ParseFailureResolutionService::resolve()`
path in `ParseFailureResumeTest`; the verifier now rejects its corrected
payload/frozen-bytes divergence. T-b remains honestly attributed to the
pre-existing context-scoped previous-hash linkage check; its M1 regression
asserts that existing message and is not claimed as a new-check red.

## Fleet driver

`fiscal:verify-event-chain-fleet --manifest=<json-file>` accepts exactly a JSON
object mapping tenant UUID to actor UUID. It compares manifest coverage with
the central tenant directory, reports missing and unknown tenants, and then
binds each known tenant before enumeration. The exact enumeration source is:

```sql
SELECT DISTINCT terminal_id, chain_context
FROM fiscal_events
WHERE tenant_id = :bound_tenant
ORDER BY terminal_id, chain_context
```

Every enumerated target is delegated to the existing
`fiscal:verify-event-chain` command with that manifest tenant, terminal,
context, and supplied actor. Therefore the actor lookup inside the bound
tenant, `can('fiscal.events.verify_chain')`, Spatie team re-scope/restoration,
and tenant binding remain owned by the existing command unchanged. There is no
service account, identity model, or implicit actor resolution. Zero-pair
tenants fail loudly rather than being reported as verified over zero chains.

Driver coverage: clean multi-tenant success; missing directory tenant; unknown
manifest tenant; unauthorized actor; cross-tenant actor; malformed JSON;
malformed entry; one broken tenant with continued clean-tenant execution and
aggregate non-zero; per-tenant output; zero-data refusal; and distinct
terminal/context enumeration.

## Red, green, and replay evidence

- Initial verifier RED: 4 failed, 20 passed (92 assertions). Each of the four
  isolated fixtures received exit 0 before implementation.
- Real T-a RED: 1 failed, 20 passed (110 assertions), because the real
  parse-resolution divergence received exit 0.
- Fleet RED: 9 failed because `fiscal:verify-event-chain-fleet` did not exist.
- Behavioral-commit revert replay: reverting `76ec7c7e7` without committing
  produced the same four verifier failures (4 failed, 20 passed); restoring the
  commit returned the touched paths to green.
- Per-check load-bearing disable proofs are summarized in the table above;
  each run included its clean control and only the named tamper failed its
  expected-exit assertion.

## Fresh dedicated PostgreSQL verification

The first combined replay run exhausted PostgreSQL shared lock slots while
`RefreshDatabase` repeatedly dropped the full 270-table schema:
`SQLSTATE[53200] out of shared memory; increase max_locks_per_transaction`.
No stale test process/session remained. The controller-authorized disposable
database `autoerp_es_wave_a0_test` was dropped with `FORCE`, recreated, and the
exact paths were rerun serially:

- `VerifyEventChainCommandTest.php`: **24 passed, 92 assertions**.
- `VerifyEventChainFleetCommandTest.php`: **10 passed, 46 assertions**.
- `ParseFailureResumeTest.php`: **21 passed, 110 assertions**.

Formatting and static analysis:

- Pint `--test` on all six touched PHP files: pass.
- PHPStan level 8 on all three touched production files: no errors.
- `ConsoleCommandTenantContextTest.php`: known baseline red. At test-only
  commit `03e11782e` and implementation HEAD it reports the identical eleven
  pre-existing unclassified commands. `VerifyEventChainFleetCommand` adds no
  delta because it extends `TenantScopedCommand`.

## Non-production validation fields

V1 `—`

V2 `—`

V3 `—`

No event class, migration, signature assertion, receipt mirror, POS Z arm,
parse-resolution workflow, or fiscal emission was changed in M1.
