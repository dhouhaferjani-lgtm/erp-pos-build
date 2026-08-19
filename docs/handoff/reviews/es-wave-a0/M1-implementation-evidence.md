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

Canonical bytes are strictly parsed for every row, regardless of
`payload_parse_status`. When an envelope is derivable, the sealed coordinate
set compared is:
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

The manifest actor is authorized inside bound tenancy before this query runs.
The shared gate performs the tenant-qualified user lookup,
`can('fiscal.events.verify_chain')`, and Spatie team re-scope/restoration.
Unauthorized or missing actors therefore expose neither target counts nor
terminal/context coordinates and invoke zero chains. Every authorized target
is delegated to the existing
`fiscal:verify-event-chain` command with that manifest tenant, terminal,
context, and supplied actor; the same shared gate is applied again. There is no
service account, identity model, or implicit actor resolution. Zero-pair
tenants fail loudly rather than being reported as verified over zero chains.

Driver coverage: clean multi-tenant success; missing directory tenant; unknown
manifest tenant; unauthorized actor before enumeration; missing actor before
enumeration; cross-tenant actor; malformed JSON;
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
- Review-fix revert replay: in a disposable worktree at `00a604961`, reverting
  production changes from `a43a9a3e7` while retaining its current tests made
  the pending-row sealed-coordinate regression fail (1 failed, 25 passed);
  restoring the worktree returned the path to 26 passed.
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

- `VerifyEventChainCommandTest.php`: **26 passed, 95 assertions**.
- `VerifyEventChainFleetCommandTest.php`: **11 passed, 54 assertions**.
- `ParseFailureResumeTest.php`: **21 passed, 110 assertions**.

Formatting and static analysis:

- Pint `--test` on all six touched PHP files: pass.
- PHPStan level 8 on the four production paths in scope: no errors.
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

## Fix round 1 — exact command and output record

Implementation commit: `a43a9a3e7` (`Phase 0.1.3: Close M1 verification
review findings`). The completion-review follow-up found no Critical or
Important issues and returned **Ready**.

### Load-bearing disable proofs

Each temporary one-line disable and restore was performed with the
`apply_patch` tool, not a retained shell command. The exact source transitions
are recorded below. No dedicated `git diff --exit-code`/clean-diff command was
run after each individual restore, so none is claimed. After all restores, the
full working diff was inspected and `git diff --check` passed before the final
suites and commit. Post-commit, this documentation-only round also ran:

```bash
git show a43a9a3e7:apps/api/app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php | rg -n 'false &&|foreach \(\[\] as' || true
git show a43a9a3e7:apps/api/app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainFleetCommand.php | rg -n 'false &&' || true
git status --porcelain=v1
```

The raw output was empty: the committed production files contain none of the
temporary disable forms and the worktree was clean before this evidence edit.
This is a final-state check, not retroactive per-proof clean-diff evidence.

Payload semantic comparison:

- Temporary manual `apply_patch` in
  `VerifyEventChainCommand::walkChain()`: changed
  `if ($parsed->payload === null || ! is_array($row->payload) || ! $this->semanticallyEqual($row->payload, $parsed->payload))`
  to
  `if (false && ($parsed->payload === null || ! is_array($row->payload) || ! $this->semanticallyEqual($row->payload, $parsed->payload)))`,
  disabling only the incident branch.
- Restore manual `apply_patch`: removed that `false &&` prefix and restored the
  original conditional exactly before the next proof.

```bash
APP_ENV=testing APP_KEY='base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_es_wave_a0_test DB_CENTRAL_DATABASE=autoerp_es_wave_a0_test DB_USERNAME=houssamr DB_PASSWORD='' php artisan test --no-ansi -c phpunit-pgsql.xml tests/Feature/Fiscal/VerifyEventChainCommandTest.php --filter='/test_(fails_when_parsed_payload_diverges_from_canonical_bytes|passes_when_parsed_payload_semantically_matches_canonical_bytes)/'
```

```text
FAIL  Tests\Feature\Fiscal\VerifyEventChainCommandTest
⨯ fails when parsed payload diverges from canonical bytes
✓ passes when parsed payload semantically matches canonical bytes
Expected status code 1 but received 0.
Tests: 1 failed, 1 passed (3 assertions)
```

Integrity status:

- Temporary manual `apply_patch` in
  `VerifyEventChainCommand::walkChain()`: changed
  `if ($row->integrity_status !== IntegrityStatus::Verified)` to
  `if (false && $row->integrity_status !== IntegrityStatus::Verified)`.
- Restore manual `apply_patch`: removed the `false &&` prefix and restored the
  original status conditional before the next proof.

```bash
APP_ENV=testing APP_KEY='base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_es_wave_a0_test DB_CENTRAL_DATABASE=autoerp_es_wave_a0_test DB_USERNAME=houssamr DB_PASSWORD='' php artisan test --no-ansi -c phpunit-pgsql.xml tests/Feature/Fiscal/VerifyEventChainCommandTest.php --filter='/test_(fails_when_an_internally_hash_valid_row_is_not_verified|passes_on_a_valid_seeded_chain)/'
```

```text
FAIL  Tests\Feature\Fiscal\VerifyEventChainCommandTest
✓ passes on a valid seeded chain
⨯ fails when an internally hash valid row is not verified
Expected status code 1 but received 0.
Tests: 1 failed, 1 passed (4 assertions)
```

Sealed coordinates, including `pending`:

- Temporary manual `apply_patch` in
  `VerifyEventChainCommand::walkChain()`: replaced
  `foreach ($this->sealedCoordinateMismatches($row, $parsed->envelope) as $mismatch)`
  with `foreach ([] as $mismatch)`, disabling only emission of sealed-coordinate
  incidents for both parsed and pending rows.
- Restore manual `apply_patch`: replaced `foreach ([] as $mismatch)` with the
  original
  `foreach ($this->sealedCoordinateMismatches($row, $parsed->envelope) as $mismatch)`
  iteration before the next proof.

```bash
APP_ENV=testing APP_KEY='base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_es_wave_a0_test DB_CENTRAL_DATABASE=autoerp_es_wave_a0_test DB_USERNAME=houssamr DB_PASSWORD='' php artisan test --no-ansi -c phpunit-pgsql.xml tests/Feature/Fiscal/VerifyEventChainCommandTest.php --filter='/test_(fails_when_a_stored_coordinate_disagrees_with_its_sealed_value|passes_when_parsed_payload_semantically_matches_canonical_bytes|fails_when_pending_row_stored_coordinate_disagrees_with_its_sealed_value|passes_when_pending_row_sealed_coordinates_match)/'
```

```text
FAIL  Tests\Feature\Fiscal\VerifyEventChainCommandTest
✓ passes when parsed payload semantically matches canonical bytes
⨯ fails when a stored coordinate disagrees with its sealed value
⨯ fails when pending row stored coordinate disagrees with its sealed value
✓ passes when pending row sealed coordinates match
Expected status code 1 but received 0. (both mismatch cases)
Tests: 2 failed, 2 passed (6 assertions)
```

Sequence contiguity:

- Temporary manual `apply_patch` in
  `VerifyEventChainCommand::walkChain()`: changed
  `if ($row->sequence_number !== $expectedSequence)` to
  `if (false && $row->sequence_number !== $expectedSequence)`.
- Restore manual `apply_patch`: removed the `false &&` prefix and restored the
  original gap conditional before the next proof.

```bash
APP_ENV=testing APP_KEY='base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_es_wave_a0_test DB_CENTRAL_DATABASE=autoerp_es_wave_a0_test DB_USERNAME=houssamr DB_PASSWORD='' php artisan test --no-ansi -c phpunit-pgsql.xml tests/Feature/Fiscal/VerifyEventChainCommandTest.php --filter='/test_(fails_when_sequence_numbers_are_not_contiguous_even_if_hash_linkage_is_valid|passes_on_a_valid_seeded_chain)/'
```

```text
FAIL  Tests\Feature\Fiscal\VerifyEventChainCommandTest
✓ passes on a valid seeded chain
⨯ fails when sequence numbers are not contiguous even if hash linkage is valid
Expected status code 1 but received 0.
Tests: 1 failed, 1 passed (4 assertions)
```

Fleet pre-enumeration authorization short-circuit:

- Temporary manual `apply_patch` in the bound-tenant closure of
  `VerifyEventChainFleetCommand::executeCommand()`: changed
  `if ($authorizationExit !== self::SUCCESS)` to
  `if (false && $authorizationExit !== self::SUCCESS)`. This allowed the
  target query to run after the shared gate returned failure, reproducing the
  count/coordinate exposure that the new tests forbid.
- Restore manual `apply_patch`: removed the `false &&` prefix and restored the
  pre-enumeration short-circuit before final verification.

```bash
APP_ENV=testing APP_KEY='base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_es_wave_a0_test DB_CENTRAL_DATABASE=autoerp_es_wave_a0_test DB_USERNAME=houssamr DB_PASSWORD='' php artisan test --no-ansi -c phpunit-pgsql.xml tests/Feature/Fiscal/VerifyEventChainFleetCommandTest.php --filter='/test_(unauthorized_actor_is_reported_by_the_existing_actor_gate|missing_actor_is_rejected_before_chain_targets_are_enumerated)/'
```

```text
FAIL  Tests\Feature\Fiscal\VerifyEventChainFleetCommandTest
⨯ unauthorized actor is reported by the existing actor gate
⨯ missing actor is rejected before chain targets are enumerated
Output does not contain "0 chain(s) invoked". (both cases)
Tests: 2 failed (14 assertions)
```

### Review-fix commit revert/restore replay

Commit `a43a9a3e7` was replayed in disposable worktree
`/tmp/es-wave-m1-replay-a43a9a3e7` at `00a604961`. Its production changes were
reverted without committing, while its current test changes were restored from
HEAD so the covering tests remained present:

```bash
git -C /tmp/es-wave-m1-replay-a43a9a3e7 revert --no-commit a43a9a3e7
git -C /tmp/es-wave-m1-replay-a43a9a3e7 restore --source=HEAD -- apps/api/tests/Feature/Fiscal/VerifyEventChainCommandTest.php apps/api/tests/Feature/Fiscal/VerifyEventChainFleetCommandTest.php
dropdb --if-exists --force autoerp_es_wave_a0_test
createdb autoerp_es_wave_a0_test
APP_ENV=testing APP_KEY='base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_es_wave_a0_test DB_CENTRAL_DATABASE=autoerp_es_wave_a0_test DB_USERNAME=houssamr DB_PASSWORD='' php artisan test --no-ansi -c phpunit-pgsql.xml tests/Feature/Fiscal/VerifyEventChainCommandTest.php
```

```text
FAIL  Tests\Feature\Fiscal\VerifyEventChainCommandTest
Expected status code 1 but received 0.
at tests/Feature/Fiscal/VerifyEventChainCommandTest.php:619
Tests: 1 failed, 25 passed (95 assertions)
Duration: 118.26s
```

The failure is the review-fix regression: without `a43a9a3e7`, a pending row
whose stored company coordinate disagrees with its sealed canonical coordinate
is accepted. The production and test files were then restored to HEAD and the
same path rerun against a freshly recreated disposable database:

```bash
git restore --staged --worktree .
dropdb --if-exists --force autoerp_es_wave_a0_test
createdb autoerp_es_wave_a0_test
APP_ENV=testing APP_KEY='base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_es_wave_a0_test DB_CENTRAL_DATABASE=autoerp_es_wave_a0_test DB_USERNAME=houssamr DB_PASSWORD='' php artisan test --no-ansi -c phpunit-pgsql.xml tests/Feature/Fiscal/VerifyEventChainCommandTest.php
```

```text
PASS  Tests\Feature\Fiscal\VerifyEventChainCommandTest
Tests: 26 passed (95 assertions)
Duration: 44.95s
```

An earlier attempt accidentally overlapped two invocations of this same path
and deadlocked during `RefreshDatabase`; it is discarded as infrastructure
noise and not used as behavioral evidence. Before the serial replay above,
only the dedicated disposable database was force-dropped and recreated.

### Pre-implementation/test-only baseline replay (supplementary)

The retained evidence below is supplementary pre-implementation baseline
evidence. It is not used as a substitute for the commit-level replay above.

The retained round-one evidence below is a pre-implementation baseline replay
at the exact test-only commit `03e11782e` in a disposable detached worktree:

```bash
git checkout --detach 03e11782e
APP_ENV=testing APP_KEY='base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_es_wave_a0_test DB_CENTRAL_DATABASE=autoerp_es_wave_a0_test DB_USERNAME=houssamr DB_PASSWORD='' php artisan test --no-ansi -c phpunit-pgsql.xml tests/Feature/Fiscal/VerifyEventChainCommandTest.php --filter='/test_(fails_when_parsed_payload_diverges_from_canonical_bytes|passes_when_parsed_payload_semantically_matches_canonical_bytes|fails_when_an_internally_hash_valid_row_is_not_verified|fails_when_a_stored_coordinate_disagrees_with_its_sealed_value|fails_when_sequence_numbers_are_not_contiguous_even_if_hash_linkage_is_valid|passes_on_a_valid_seeded_chain)/'
```

```text
FAIL  Tests\Feature\Fiscal\VerifyEventChainCommandTest
! passes on a valid seeded chain (warning only)
⨯ fails when parsed payload diverges from canonical bytes
! passes when parsed payload semantically matches canonical bytes (warning only)
⨯ fails when an internally hash valid row is not verified
⨯ fails when a stored coordinate disagrees with its sealed value
⨯ fails when sequence numbers are not contiguous even if hash linkage is valid
Expected status code 1 but received 0. (all four tamper cases)
Tests: 4 failed, 2 warnings (11 assertions)
```

Both behavioral commits now have true revert/restore replays: `76ec7c7e7` as
recorded earlier, and `a43a9a3e7` in the disposable-worktree replay above.

### Final dedicated PostgreSQL paths

After a repeated `RefreshDatabase` cycle reproduced `SQLSTATE[53200]` at test
25, `pg_stat_activity` showed no sessions for the disposable database. Only
`autoerp_es_wave_a0_test` was force-dropped/recreated, then the paths were run
once serially:

```bash
APP_ENV=testing APP_KEY='base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_es_wave_a0_test DB_CENTRAL_DATABASE=autoerp_es_wave_a0_test DB_USERNAME=houssamr DB_PASSWORD='' php artisan test --no-ansi -c phpunit-pgsql.xml tests/Feature/Fiscal/VerifyEventChainCommandTest.php
```

```text
PASS  Tests\Feature\Fiscal\VerifyEventChainCommandTest
Tests: 26 passed (95 assertions)
Duration: 131.26s
```

```bash
APP_ENV=testing APP_KEY='base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_es_wave_a0_test DB_CENTRAL_DATABASE=autoerp_es_wave_a0_test DB_USERNAME=houssamr DB_PASSWORD='' php artisan test --no-ansi -c phpunit-pgsql.xml tests/Feature/Fiscal/VerifyEventChainFleetCommandTest.php
```

```text
PASS  Tests\Feature\Fiscal\VerifyEventChainFleetCommandTest
Tests: 11 passed (54 assertions)
Duration: 82.14s
```

```bash
APP_ENV=testing APP_KEY='base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_es_wave_a0_test DB_CENTRAL_DATABASE=autoerp_es_wave_a0_test DB_USERNAME=houssamr DB_PASSWORD='' php artisan test --no-ansi -c phpunit-pgsql.xml tests/Feature/Fiscal/ParseFailureResumeTest.php
```

```text
PASS  Tests\Feature\Fiscal\ParseFailureResumeTest
Tests: 21 passed (110 assertions)
Duration: 145.75s
```

### Formatting, static analysis, and architecture delta

```bash
./vendor/bin/pint --test app/Modules/Fiscal/Infrastructure/Commands/AuthorizedFiscalChainCommand.php app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainFleetCommand.php tests/Feature/Fiscal/VerifyEventChainCommandTest.php tests/Feature/Fiscal/VerifyEventChainFleetCommandTest.php tests/Feature/Fiscal/ParseFailureResumeTest.php
```

```text
{"result":"pass"}
```

```bash
./vendor/bin/phpstan analyse --level=8 --no-progress app/Modules/Fiscal/Infrastructure/Commands/AuthorizedFiscalChainCommand.php app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainFleetCommand.php app/Modules/Fiscal/Providers/FiscalServiceProvider.php
```

```text
Note: Using configuration file .../apps/api/phpstan.neon.
[OK] No errors
```

The exact architecture command was run at both `03e11782e` and `a43a9a3e7`:

```bash
APP_ENV=testing APP_KEY='base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_es_wave_a0_test DB_CENTRAL_DATABASE=autoerp_es_wave_a0_test DB_USERNAME=houssamr DB_PASSWORD='' php artisan test --no-ansi -c phpunit-pgsql.xml tests/Architecture/ConsoleCommandTenantContextTest.php
```

Both emitted the identical raw eleven-class list:

```text
App\Console\Commands\ScanPercentScaleDrift
App\Console\Commands\ExportFrontendPermissionsMap
App\Console\Commands\ConfigureMethodRepositoryRoutingCommand
App\Console\Commands\BackfillPayableInstrumentAccountsCommand
App\Console\Commands\BackfillTaxDetailsCommand
App\Modules\Product\Presentation\Console\RunEnrichmentCommand
App\Modules\Treasury\Presentation\Console\BackfillLocationAttributionCommand
App\Modules\Company\Presentation\Console\BackfillMembershipsCommand
App\Modules\SupportAccess\Presentation\Console\VerifyImpersonationAuditCommand
App\Modules\SupportAccess\Presentation\Console\ExpireSupportAccessCommand
App\Modules\SupportAccess\Presentation\Console\ReconcileImpersonationAuditCommand
Tests: 1 failed (1 assertions)
```

Neither fiscal verifier appears. Architecture delta: **zero**.

## Round-1 review closure — 2026-08-12

Behavioral commit: `71f306c86` (`Phase 0.1.9: Close M1 verifier review
findings`). No M4 chain-head logic, projection, migration, event class, or
controller-owned progress-YAML review field changed. Version/projection impact:
V1 `—`; V2 `—`; V3 `—`.

### P1 — sanctioned server-authored envelopes (ongoing compatibility)

The general `StrictCanonicalParser` remains strict. The verifier recognizes the
currently emitted 14-key server envelope only after the strict parse fails with
exactly `envelope_field_missing:chain_context`, and only when all of these
independently stored path coordinates match:

- event type is exactly `TERMINAL_REGISTRY_SNAPSHOT`,
  `ACCOUNT_STATUS_CHANGED`, or `DEPOSIT_RECEIPT`;
- event version 1, operational row context, `not_required` signature,
  `verified` integrity, parsed payload;
- null reference and source-event coordinates; and
- the decoded object has exactly the legacy key set (current envelope set minus
  `chain_context`), with no other missing or extra field.

The current parser is then run on a synthetic operational context so its DTO,
version, key-set, shape, and constraint checks still apply. `DEPOSIT_RECEIPT` is
server-only and intentionally absent from the device parser's operational type
allowlist, so that one type is validated through `DepositReceiptPayload` plus the
same shared key-set and per-event constraint validator instead of weakening the
device gate. The returned envelope remains the original legacy object: every
coordinate that was actually sealed is compared, while the absent context is not
invented as sealed data. The ongoing services' timestampTz wall representation is
compared only on this exact path; current/device envelopes continue to compare UTC
instants.

Production-path tests author real snapshot, account-status, and deposit-receipt
events and now verify them cleanly. Negative controls prove a device-authored
missing context still fails, a sanctioned type with an extra envelope field still
fails, and a sanctioned legacy envelope with a divergent sealed `company_id` still
reports the coordinate mismatch.

### P1/P2 — honest v3 controls and isolated T-b

The v3 two-context fixture no longer inserts placeholder JSON. Its operational
row is a parseable v1 `SALE_RECEIPT` using the golden payload, and its z-session
row is a parseable `SESSION_OPEN`; both use matching stored payload/status and
sealed coordinates. Separate clean verifier controls pass for `operational` and
`z_session`.

T-b now adds one internally hash-valid, parseable operational row whose only
divergence is that `previous_hash` comes from the z-session head. Its assertion
pins exactly one `CHAIN BREAK` line, the linkage message, and summary
`1 chain incidents, 0 quarantine incidents`; it rejects parser, payload, and
current-hash contamination.

### P2/P3 — operability scope and fleet binding

Durable follow-ups live in
`docs/superpowers/tickets/2026-08-12-fiscal-event-chain-verifier-operability-followups.md`:

- `FEV-OPS-01` assigns the Fiscal domain owner the policy decision and acceptance
  contract for quarantine classes with no current remediation path.
- `FEV-OPS-02` assigns the Fiscal platform/operations owner measurement,
  bounded-walk, checkpoint, resume, and incomplete-coverage semantics for large
  fleets.
- `FEV-OPS-03` discloses and assigns the quarantine-only terminal/context
  coverage hole created by the brief-pinned `fiscal_events` enumeration source.
- `FEV-OPS-04` assigns the fleet boundary's collapsed transient exit semantics.
- `FEV-OPS-05` records that all three server builders still omit
  `chain_context` for new rows and owns the forward-only builder migration.
- `FEV-OPS-06` assigns the existing-context/zero-event success semantics.
- `FEV-OPS-07` assigns abnormal-termination cleanup for the physical-DB test.

The fleet driver now refuses the empty-directory plus empty-object-manifest case
instead of reporting a successful zero-work run. A PostgreSQL-only acceptance
test provisions and migrates a physical tenant database, seeds its actor and chain
only inside that database, proves the event is absent from central, and drives the
fleet enumerator plus child command. The child succeeds only by re-binding after
the parent's enumeration binding ends.

### Commit-level replay

`71f306c86` was reverted with `--no-commit`; all three current test files were
restored from the commit before execution. These are the exact preparation
commands:

```bash
git revert --no-commit 71f306c86
git restore --source=HEAD -- apps/api/tests/Feature/Fiscal/VerifyEventChainCommandTest.php apps/api/tests/Feature/Fiscal/VerifyEventChainFleetCommandTest.php apps/api/tests/Feature/Fiscal/VerifyEventChainFleetCommandDbPerTenantTest.php
git status --short
```

The status output showed reverted production/docs staged while the three current
test paths remained present (`MM` for the two pre-existing files and staged-delete
plus untracked-current-copy for the new DB-per-tenant file). The exact PostgreSQL
replay command for the server-envelope behavior was:

```bash
APP_ENV=testing APP_KEY='base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_es_wave_a0_m1r1_test DB_CENTRAL_DATABASE=autoerp_es_wave_a0_m1r1_test DB_USERNAME=houssamr DB_PASSWORD='' ./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/Fiscal/VerifyEventChainCommandTest.php --filter='/test_(verifies_the_existing_server_authored_snapshot_envelope|verifies_the_existing_virtual_admin_server_authored_envelopes|missing_chain_context_remains_a_failure_for_device_authored_events)/' > /tmp/es-wave-m1r1-replay-command.txt 2>&1; replay_status=$?; sed -n '1,220p' /tmp/es-wave-m1r1-replay-command.txt; echo "REPLAY_EXIT=$replay_status"; exit $replay_status
```

Retained raw output (`/tmp/es-wave-m1r1-replay-command.txt`):

```text
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.15
Configuration: /Users/houssamr/Projects/syneriva/apps/erp/.worktrees/es-wave-a0/apps/api/phpunit-pgsql.xml

FF.                                                                 3 / 3 (100%)

Time: 00:13.574, Memory: 151.00 MB

There were 2 failures:

1) Tests\Feature\Fiscal\VerifyEventChainCommandTest::test_verifies_the_existing_server_authored_snapshot_envelope
CHAIN BREAK at sequence_number 1 (id 25afd1fe-1381-4b8e-ba33-98ad22196177): sealed coordinates could not be derived from canonical_bytes (envelope_field_missing:chain_context)
CHAIN BREAK at sequence_number 1 (id 25afd1fe-1381-4b8e-ba33-98ad22196177): payload does not semantically match canonical_bytes — canonical payload could not be derived (envelope_field_missing:chain_context)
chain NOT verified — terminal 019ff5a9-2ee5-71b2-8fb9-e87e005c354a, tenant eb2737ee-b81d-4b85-a90f-9b542392f3c5, context operational: 2 chain incidents, 0 quarantine incidents.

Failed asserting that 1 is identical to 0.

/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/es-wave-a0/apps/api/tests/Feature/Fiscal/VerifyEventChainCommandTest.php:176

2) Tests\Feature\Fiscal\VerifyEventChainCommandTest::test_verifies_the_existing_virtual_admin_server_authored_envelopes
CHAIN BREAK at sequence_number 1 (id 0e6210fa-c3de-425d-a2d3-93f64bb9c4e2): sealed coordinates could not be derived from canonical_bytes (envelope_field_missing:chain_context)
CHAIN BREAK at sequence_number 1 (id 0e6210fa-c3de-425d-a2d3-93f64bb9c4e2): payload does not semantically match canonical_bytes — canonical payload could not be derived (envelope_field_missing:chain_context)
CHAIN BREAK at sequence_number 2 (id 189dc582-bb12-4f18-b126-c544e846fed2): sealed coordinates could not be derived from canonical_bytes (envelope_field_missing:chain_context)
CHAIN BREAK at sequence_number 2 (id 189dc582-bb12-4f18-b126-c544e846fed2): payload does not semantically match canonical_bytes — canonical payload could not be derived (envelope_field_missing:chain_context)
chain NOT verified — terminal 019ff5a9-3772-7324-a37f-00b7ba1d5a7b, tenant 602a13fd-b60e-47b0-a8d8-48dd8be79424, context operational: 4 chain incidents, 0 quarantine incidents.

Failed asserting that 1 is identical to 0.

/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/es-wave-a0/apps/api/tests/Feature/Fiscal/VerifyEventChainCommandTest.php:219

FAILURES!
Tests: 3, Assertions: 8, Failures: 2.
REPLAY_EXIT=1
```

The device-authored missing-context negative control is the `.` in `FF.` and
therefore remained green while both sanctioned production paths went red.

The exact zero-work fleet replay command was:

```bash
APP_ENV=testing APP_KEY='base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_es_wave_a0_m1r1_test DB_CENTRAL_DATABASE=autoerp_es_wave_a0_m1r1_test DB_USERNAME=houssamr DB_PASSWORD='' ./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/Fiscal/VerifyEventChainFleetCommandTest.php --filter=test_empty_directory_and_empty_manifest_fail_instead_of_reporting_a_zero_work_success > /tmp/es-wave-m1r1-replay-fleet.txt 2>&1; replay_status=$?; sed -n '1,180p' /tmp/es-wave-m1r1-replay-fleet.txt; echo "REPLAY_EXIT=$replay_status"; exit $replay_status
```

Retained raw output (`/tmp/es-wave-m1r1-replay-fleet.txt`):

```text
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.15
Configuration: /Users/houssamr/Projects/syneriva/apps/erp/.worktrees/es-wave-a0/apps/api/phpunit-pgsql.xml

F                                                                   1 / 1 (100%)

Time: 00:12.435, Memory: 137.00 MB

There was 1 failure:

1) Tests\Feature\Fiscal\VerifyEventChainFleetCommandTest::test_empty_directory_and_empty_manifest_fail_instead_of_reporting_a_zero_work_success
Expected status code 1 but received 0.
Failed asserting that 0 matches expected 1.

/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/es-wave-a0/apps/api/vendor/laravel/framework/src/Illuminate/Testing/PendingCommand.php:463
/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/es-wave-a0/apps/api/vendor/laravel/framework/src/Illuminate/Testing/PendingCommand.php:675
/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/es-wave-a0/apps/api/tests/Feature/Fiscal/VerifyEventChainFleetCommandTest.php:72

FAILURES!
Tests: 1, Assertions: 4, Failures: 1.
REPLAY_EXIT=1
```

An initial `git revert --abort` refused because the deliberately retained tests
differed from the revert index. The exact successful restore sequence was:

```bash
git restore --source=HEAD --staged --worktree -- apps/api/app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php apps/api/app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainFleetCommand.php apps/api/tests/Feature/Fiscal/VerifyEventChainCommandTest.php apps/api/tests/Feature/Fiscal/VerifyEventChainFleetCommandTest.php apps/api/tests/Feature/Fiscal/VerifyEventChainFleetCommandDbPerTenantTest.php docs/superpowers/tickets/2026-08-12-fiscal-event-chain-verifier-operability-followups.md
git revert --abort
git status --short
git rev-parse --short=9 HEAD
```

Raw restore output was `71f306c86`; `git status --short` emitted no lines. This
is a behavioral-commit revert replay with current tests retained, not a
test-only baseline replay.

### Fresh final serial PostgreSQL paths

The dedicated `autoerp_es_wave_a0_m1r1_test` database had zero active sessions,
was dropped/recreated, and these exact paths ran serially:

```bash
psql -h 127.0.0.1 -p 5432 -U houssamr -d postgres -Atc "select count(*) from pg_stat_activity where datname = 'autoerp_es_wave_a0_m1r1_test';"
dropdb -h 127.0.0.1 -p 5432 -U houssamr autoerp_es_wave_a0_m1r1_test && createdb -h 127.0.0.1 -p 5432 -U houssamr autoerp_es_wave_a0_m1r1_test
```

The session query returned `0`; drop/create emitted no output and exited 0.

Exact restored-green command path:

```bash
APP_ENV=testing APP_KEY='base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_es_wave_a0_m1r1_test DB_CENTRAL_DATABASE=autoerp_es_wave_a0_m1r1_test DB_USERNAME=houssamr DB_PASSWORD='' php artisan test --no-ansi -c phpunit-pgsql.xml tests/Feature/Fiscal/VerifyEventChainCommandTest.php > /tmp/es-wave-m1r1-final-command.txt 2>&1; test_status=$?; sed -n '1,260p' /tmp/es-wave-m1r1-final-command.txt; echo "FINAL_EXIT=$test_status"; exit $test_status
```

Retained raw result:

```text
   PASS  Tests\Feature\Fiscal\VerifyEventChainCommandTest
  ✓ passes on a valid seeded chain                                      12.66s
  ✓ verifies the existing server authored snapshot envelope              1.83s
  ✓ verifies the existing virtual admin server authored envelopes        1.00s
  ✓ missing chain context remains a failure for device authored events   1.25s
  ✓ legacy server compatibility rejects an extra envelope field          1.63s
  ✓ legacy server compatibility still checks every sealed coordinate     1.22s
  ✓ fails with break point on a tampered fixture                         1.01s
  ✓ fails as incident on a seeded sequence conflict                      1.35s
  ✓ command is permission gated                                          1.40s
  ✓ fails when first event previous hash does not match genesis seed     1.19s
  ✓ fails when break is at last sequence                                 3.45s
  ✓ quarantine incident is reported alongside valid chain                3.95s
  ✓ from sequence skips earlier break                                    1.16s
  ✓ empty chain is a clean pass                                          2.02s
  ✓ permission check is scoped to actor tenant not request team          2.13s
  ✓ unknown actor id is rejected                                         2.40s
  ✓ missing tenant or terminal option is rejected                        3.33s
  ✓ an unknown tenant fails loudly instead of reporting a verified chai… 1.63s
  ✓ a chain is not verified from another tenants binding                 1.85s
  ✓ an actor from another tenant cannot authorise a run against this te… 2.01s
  ✓ seeded v3 fixture has two contexts and a hash mirrored projected re… 2.39s
  ✓ seeded v3 operational context is a clean verifier control            1.27s
  ✓ seeded v3 z session context is a clean verifier control              0.93s
  ✓ wrong context previous hash tamper is self asserting                 1.16s
  ✓ receipt mirror tamper is self asserting                              1.18s
  ✓ fails when parsed payload diverges from canonical bytes              1.41s
  ✓ passes when parsed payload semantically matches canonical bytes      0.96s
  ✓ fails when an internally hash valid row is not verified              1.12s
  ✓ fails when a stored coordinate disagrees with its sealed value       1.24s
  ✓ fails when pending row stored coordinate disagrees with its sealed…  1.42s
  ✓ passes when pending row sealed coordinates match                     3.62s
  ✓ fails when sequence numbers are not contiguous even if hash linkage… 1.57s
  ✓ wrong context previous hash tamper is reported by the existing cont… 1.41s

  Tests:    33 passed (136 assertions)
  Duration: 68.24s
```

```bash
APP_ENV=testing APP_KEY='base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_es_wave_a0_m1r1_test DB_CENTRAL_DATABASE=autoerp_es_wave_a0_m1r1_test DB_USERNAME=houssamr DB_PASSWORD='' php artisan test --no-ansi -c phpunit-pgsql.xml tests/Feature/Fiscal/VerifyEventChainFleetCommandTest.php > /tmp/es-wave-m1r1-final-fleet.txt 2>&1; test_status=$?; sed -n '1,240p' /tmp/es-wave-m1r1-final-fleet.txt; echo "FINAL_EXIT=$test_status"; exit $test_status
```

Retained raw result:

```text
   PASS  Tests\Feature\Fiscal\VerifyEventChainFleetCommandTest
  ✓ clean multi tenant manifest verifies every tenant with per tenant…  16.18s
  ✓ empty directory and empty manifest fail instead of reporting a zero… 1.10s
  ✓ directory tenant missing from manifest is reported and fails aggreg… 1.39s
  ✓ unknown manifest tenant is reported and fails aggregate              2.50s
  ✓ manifest tenant with no fiscal event pairs fails loudly instead of…  1.27s
  ✓ unauthorized actor is reported by the existing actor gate            0.93s
  ✓ missing actor is rejected before chain targets are enumerated        0.86s
  ✓ actor from another tenant cannot authorize manifest entry            1.17s
  ✓ malformed json manifest is rejected before any chain is verified     0.96s
  ✓ malformed manifest entry is rejected before any chain is verified    0.89s
  ✓ one broken chain fails aggregate while other tenants still run       2.50s
  ✓ enumerates distinct terminal and context pairs present in fiscal ev… 1.03s

  Tests:    12 passed (58 assertions)
  Duration: 30.97s
```

```bash
APP_ENV=testing APP_KEY='base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_es_wave_a0_m1r1_test DB_CENTRAL_DATABASE=autoerp_es_wave_a0_m1r1_test DB_USERNAME=houssamr DB_PASSWORD='' php artisan test --no-ansi -c phpunit-pgsql.xml tests/Feature/Fiscal/VerifyEventChainFleetCommandDbPerTenantTest.php > /tmp/es-wave-m1r1-final-dbper.txt 2>&1; test_status=$?; sed -n '1,180p' /tmp/es-wave-m1r1-final-dbper.txt; echo "FINAL_EXIT=$test_status"; exit $test_status
```

Retained raw output:

```text
   PASS  Tests\Feature\Fiscal\VerifyEventChainFleetCommandDbPerTenantTest
  ✓ fleet enumeration and child verification rebind the physical tenant… 8.66s

  Tests:    1 passed (8 assertions)
  Duration: 8.72s
FINAL_EXIT=0
```

```bash
APP_ENV=testing APP_KEY='base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_es_wave_a0_m1r1_test DB_CENTRAL_DATABASE=autoerp_es_wave_a0_m1r1_test DB_USERNAME=houssamr DB_PASSWORD='' php artisan test --no-ansi -c phpunit-pgsql.xml tests/Feature/Fiscal/ParseFailureResumeTest.php > /tmp/es-wave-m1r1-final-parse.txt 2>&1; test_status=$?; sed -n '1,260p' /tmp/es-wave-m1r1-final-parse.txt; echo "FINAL_EXIT=$test_status"; exit $test_status
```

Retained raw result:

```text
   PASS  Tests\Feature\Fiscal\ParseFailureResumeTest
  ✓ resolution writes payload flips status and creates projection rows… 11.43s
  ✓ payload rewrite tamper is self asserting                             1.28s
  ✓ event chain verifier rejects the real parse resolution payload dive… 1.09s
  ✓ crash between commit and enqueue is recoverable without rewriting p… 1.14s
  ✓ command is idempotent and safe to rerun                              1.18s
  ✓ command is permission gated                                          1.06s
  ✓ resolution rejects non quarantined row with typed throw              1.24s
  ✓ resolution rejects invalid corrected payload and row stays parse fa… 1.78s
  ✓ command is noop on still parse failed row                            1.93s
  ✓ command does not redispatch running applied or dead lettered rows    1.53s
  ✓ resolver rejects payload with extra top level key via strict parser  1.29s
  ✓ resolver accepts a corrected v3 payload carrying the cash rounding…  1.17s
  ✓ resolver rejects the same v3 payload on a version two event          1.21s
  ✓ resolver rejects a v3 event whose correction omits the rounding key… 1.26s
  ✓ resolver rejects money field with wrong currency scale               1.21s
  ✓ resolver rejects payload line with associative array shape           1.04s
  ✓ command permission check is scoped to actor tenant not request team  1.10s
  ✓ command returns exit code 2 on per row resolver failure              1.74s
  ✓ command filters by tenant when tenant option provided                1.52s
  ✓ command requires the tenant option                                   0.99s
  ✓ command fails loudly for a tenant absent from the directory          0.90s

  Tests:    21 passed (110 assertions)
  Duration: 37.17s
```

The `/tmp` transcripts retain each individual test line as well as the raw
summaries above; no suite was rerun for this evidence-only correction.

Pint `--test` passed on the five touched PHP paths. PHPStan level 8 passed on
the two touched production commands. The existing console-classification
architecture test still reports the same eleven unrelated classes documented
above; neither fiscal verifier appears, so this diff's delta remains zero.

Deptrac was run at M1 test baseline `03e11782e` and at `71f306c86`. Both runs
reported the identical 116 violations and identical category vector
`21/41/1/18/29/4/2` against the existing 99 baseline. The ratchet command remains
red on the repository's pre-existing 17-edge baseline drift, but this M1 range's
deptrac violation delta is exactly zero.

## Adversarial review round 4 — final docs-only disclosure closure

This round changes documentation only. No production or test file changed and no
suite was rerun. The controller-owned progress YAML was not edited; its M1
`commit:` remains `71f306c86`, the last behavioral commit.

### P2 — quarantine-only fleet targets are not enumerated

The fleet target query remains exactly the brief-pinned source:

```sql
SELECT DISTINCT terminal_id, chain_context
FROM fiscal_events
WHERE tenant_id = :bound_tenant
```

That source is not complete for unresolved incidents. A malformed envelope can
land only in `fiscal_event_quarantine`, with no corresponding event row. Exact
false-green scenario: the first `z_session` envelope for terminal T is malformed,
creating one unresolved quarantine row at `(T, z_session)` and no event row for
that pair; `(T, operational)` does have events. The fleet enumerates only the
operational pair, prints `TENANT X: VERIFIED 1 chain(s)` plus
`fleet chain verification completed: 1 tenant(s), 1 chain(s), no failures`, and
exits 0. Direct single-chain verification of `T/z_session` would report the
quarantine, so the defect is specifically fleet target coverage.

This limitation is now durably disclosed as `FEV-OPS-03`, owned by the Fiscal
platform owner with Fiscal domain sign-off. Its acceptance contract requires a
deduplicated union (or equivalently complete target source), non-zero aggregate
for quarantine-only pairs, an explicit resolved-row policy, preservation of the
actor/tenant/child-delegation boundaries, and a regression for the exact first
malformed `z_session` scenario. M1 does not implement that union because the
brief explicitly pins enumeration to `fiscal_events`.

### P3 — bounded follow-ups

- **Fleet transient exit collapse:** `FEV-OPS-04` records that a child exit 2 is
  currently collapsed to fleet exit 1 and requires a ruled mixed-result
  precedence plus automation-visible retry semantics.
- **Ongoing server envelope omission:** `FEV-OPS-05` corrects the historical-only
  framing. The compatibility gate is shape-based, and all three current builders
  continue to omit `chain_context` for every new snapshot, account-status, and
  deposit-receipt row. The ticket owns a forward-only builder migration while
  preserving already sealed 14-key rows and coordinating with M4 rather than
  changing chain-head behavior in M1. The inaccurate historical wording in PHP
  comments is explicitly identified; PHP was not changed in this docs-only round.
- **Empty existing context:** `FEV-OPS-06` records the pre-existing, honest but
  potentially checklist-ambiguous exit 0 message over `0 events walked`, while
  preserving the fact that unresolved quarantine incidents still fail.
- **Physical-DB test crash leak:** `FEV-OPS-07` records the plausible,
  unreproduced SIGKILL/OOM/fatal cleanup gap and requires run-scoped discovery,
  safe stale-resource reclamation, visible cleanup failure, and an induced-abort
  or equivalent harness check.

Reviewer round-4 P3 finding 3 (the progress-YAML behavioral SHA) is controller
owned and already reads `71f306c86`; this implementation made no YAML edit.
Version/projection impact remains V1 `—`; V2 `—`; V3 `—`.
