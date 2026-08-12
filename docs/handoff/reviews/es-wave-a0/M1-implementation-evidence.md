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

### Pre-implementation/test-only baseline replay (not a commit revert)

This section is deliberately **not** commit-level revert/restore evidence for
round-one commit `a43a9a3e7`. No `git revert` of `a43a9a3e7` was performed.
The post-review files overlap the earlier behavioral commit `76ec7c7e7`; an
attempted `git revert --no-commit 76ec7c7e7` in a disposable worktree conflicted
and was immediately aborted without running tests, so it is not evidence.

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

Explicit deviation: the original M1 behavioral commit `76ec7c7e7` had a true
`git revert --no-commit` / restore replay, recorded earlier in this document.
Round-one commit `a43a9a3e7` did not receive its own commit-level revert replay.
Its load-bearing evidence is instead the red-first focused tests, the five
individual temporary-disable proofs above, the pre-implementation baseline
replay, the architecture base/HEAD comparison, and the final green paths. This
evidence does not claim those are equivalent to a commit-level revert/restore
operation.

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
