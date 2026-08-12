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

### P1 — sanctioned legacy server envelopes

The general `StrictCanonicalParser` remains strict. The verifier recognizes the
historical 14-key server envelope only after the strict parse fails with exactly
`envelope_field_missing:chain_context`, and only when all of these independently
stored path coordinates match:

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
invented as sealed data. The legacy services' timestampTz wall representation is
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

The fleet driver now refuses the empty-directory plus empty-object-manifest case
instead of reporting a successful zero-work run. A PostgreSQL-only acceptance
test provisions and migrates a physical tenant database, seeds its actor and chain
only inside that database, proves the event is absent from central, and drives the
fleet enumerator plus child command. The child succeeds only by re-binding after
the parent's enumeration binding ends.

### Commit-level replay

`71f306c86` was reverted with `--no-commit`; all three current test files were
restored from the commit before execution. On real PostgreSQL:

- the snapshot and virtual-admin clean controls failed exactly on
  `envelope_field_missing:chain_context` (2 failed, device negative control
  passed; 3 tests / 8 assertions);
- the empty fleet regression failed because production returned exit 0
  (1 test / 4 assertions).

The revert was aborted and the commit restored. This is a behavioral-commit
revert replay with current tests retained, not a test-only baseline replay.

### Fresh final serial PostgreSQL paths

The dedicated `autoerp_es_wave_a0_m1r1_test` database had zero active sessions,
was dropped/recreated, and these exact paths ran serially:

```text
VerifyEventChainCommandTest.php:                    33 passed, 136 assertions, 68.24s
VerifyEventChainFleetCommandTest.php:               12 passed,  58 assertions, 30.97s
VerifyEventChainFleetCommandDbPerTenantTest.php:      1 passed,   8 assertions,  8.72s
ParseFailureResumeTest.php:                         21 passed, 110 assertions, 37.17s
```

Pint `--test` passed on the five touched PHP paths. PHPStan level 8 passed on
the two touched production commands. The existing console-classification
architecture test still reports the same eleven unrelated classes documented
above; neither fiscal verifier appears, so this diff's delta remains zero.

Deptrac was run at M1 test baseline `03e11782e` and at `71f306c86`. Both runs
reported the identical 116 violations and identical category vector
`21/41/1/18/29/4/2` against the existing 99 baseline. The ratchet command remains
red on the repository's pre-existing 17-edge baseline drift, but this M1 range's
deptrac violation delta is exactly zero.
