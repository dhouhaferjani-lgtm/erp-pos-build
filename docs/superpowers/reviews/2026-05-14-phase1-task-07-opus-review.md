# Phase 1 Task 7 — `create_fiscal_events_table` + `FiscalEvent` model — Opus review

**Date:** 2026-05-16
**Reviewer:** Opus (headless review gate)
**Scope:** Task 7 from `docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md` (server PostgreSQL `fiscal_events` ledger + Eloquent model + feature test).
**Base SHA:** `d695e9cf` (owner sign-off attestation; preflight gate)
**Head SHA:** `245f5e85` (feat(fiscal): create_fiscal_events_table migration + FiscalEvent model)
**Branch:** `feat/pos-fiscal-event-engine-phase1`
**Diff vs. base:** 3 files added, 433 lines.
- `apps/api/app/Modules/Fiscal/Domain/Models/FiscalEvent.php` (+158)
- `apps/api/database/migrations/2026_05_14_100001_create_fiscal_events_table.php` (+143)
- `apps/api/tests/Feature/Fiscal/FiscalEventsTableTest.php` (+132)

**Verdict:** **APPROVE** — 0 findings.

The migration is a faithful, column-by-column realization of spec v7 §3.2 (server PostgreSQL `fiscal_events`); the Eloquent model is a typed, minimal-surface mirror of the immutable ledger with no auto-timestamps, no cross-module imports, and casts that resolve to enums that already exist from Tasks 2–3; the test is a genuine RED→GREEN (verified by removing the migration and re-running) and the four constraint cases skip cleanly on SQLite via the project's established `markTestSkipped('… only enforced on PostgreSQL')` pattern. PHPStan level 8 is clean. Pint is clean. No file outside the Task-7 scope was touched.

---

## Verification performed

| Check | Result | Evidence |
|---|---|---|
| Three (and only three) files in the commit | ✓ | `git show --stat 245f5e85` → `FiscalEvent.php`, `2026_05_14_100001_create_fiscal_events_table.php`, `FiscalEventsTableTest.php`. No Task 1–6 files touched. |
| Test fails before migration (genuine RED) | ✓ | Temporarily moved migration aside; `phpunit FiscalEventsTableTest.php` → `1 failure, 4 skipped` with `test_table_has_all_chain_and_integrity_columns` failing at `Schema::hasTable('fiscal_events')`. Restored → `5 tests, 22 assertions, 4 skipped, 0 failures`. |
| PHPStan level 8 clean on new files | ✓ | `./vendor/bin/phpstan analyse <three files> --no-progress` → `[OK] No errors`. |
| Pint clean on new files | ✓ | `./vendor/bin/pint --test <three files>` → `{"result":"pass"}`. |
| Test driver is SQLite (skip pattern justified) | ✓ | `apps/api/phpunit.xml` → `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`. CHECK / partial-unique are PG-only, so skipping on SQLite is mandatory, not optional. |
| Project precedent for `markTestSkipped('… only enforced on PostgreSQL')` | ✓ | `apps/api/tests/Unit/Compliance/CompanyFraudSettingsCashControlsTest.php:40`, `apps/api/tests/Unit/POS/ZReportCountModelTest.php:48,69`, `apps/api/tests/Unit/Voucher/Domain/VoucherLedgerTest.php:83,98`, `apps/api/tests/Feature/PlatformIntegration/ExternalIdUniquenessTest.php:194,220,251`, `apps/api/tests/Feature/Scheduling/MigrationSchemaTest.php:127,137,153,169`. Identical idiom. |

---

## Spec §3.2 column-by-column conformance (server PostgreSQL)

| Spec §3.2 line | Spec type / default / nullable | Migration line | Migration produces | Verdict |
|---|---|---|---|---|
| 145 `id` | `UUID PK` | `:25` | `uuid('id')->primary()` | ✓ |
| 146 `tenant_id` | `UUID NOT NULL` | `:28` | `uuid('tenant_id')` | ✓ |
| 147 `company_id` | `UUID NOT NULL` | `:29` | `uuid('company_id')` | ✓ |
| 148 `terminal_id` | `UUID NOT NULL` | `:30` | `uuid('terminal_id')` | ✓ |
| 149 `operator_id` | `UUID NOT NULL` | `:31` | `uuid('operator_id')` | ✓ |
| 150 `event_type` | `VARCHAR(64) NOT NULL` | `:34` | `string('event_type', 64)` | ✓ |
| 151 `event_version` | `SMALLINT NOT NULL DEFAULT 1` | `:35` | `smallInteger('event_version')->default(1)` | ✓ |
| 152 `signature_version` | `VARCHAR(64) NOT NULL` | `:36` | `string('signature_version', 64)` | ✓ |
| 153 `sequence_number` | `BIGINT NOT NULL` | `:39` | `bigInteger('sequence_number')` | ✓ |
| 154 `event_time_device` | `TIMESTAMPTZ NOT NULL` | `:40` | `timestampTz('event_time_device')` | ✓ |
| 155 `business_date` | `DATE NOT NULL` | `:41` | `date('business_date')` | ✓ |
| 156 `last_server_time_seen` | `TIMESTAMPTZ` (nullable) | `:42` | `timestampTz(...)->nullable()` | ✓ |
| 157 `server_received_at` | `TIMESTAMPTZ NOT NULL` (set by ingestor, no default) | `:46` | `timestampTz('server_received_at')` — no `default()`, no `nullable()` | ✓ — matches the spec note "set by the OutboxIngestor (§7, §10)" |
| 158 `reference_event_id` | `UUID` (nullable) | `:49` | `uuid(...)->nullable()` | ✓ |
| 159 `reference_document_id` | `UUID` (nullable) | `:50` | `uuid(...)->nullable()` | ✓ |
| 160 `source_event_class` | `VARCHAR(255)` (nullable) | `:51` | `string(..., 255)->nullable()` | ✓ |
| 161 `source_event_id` | `UUID` (nullable) | `:52` | `uuid(...)->nullable()` | ✓ |
| 162 `partner_id` | `UUID` (nullable) | `:53` | `uuid(...)->nullable()` | ✓ |
| 163 `partner_identity_snapshot` | `JSONB` (nullable) | `:54` | `jsonb(...)->nullable()` | ✓ |
| 164 `canonical_bytes` | `BYTEA NOT NULL` (verbatim device bytes) | `:57` | `binary('canonical_bytes')` — maps to `BYTEA` on PG | ✓ |
| 165 `previous_hash` | `CHAR(64) NOT NULL` | `:60` | `char('previous_hash', 64)` | ✓ |
| 166 `current_hash` | `CHAR(64) NOT NULL` | `:61` | `char('current_hash', 64)` | ✓ |
| 168 `signature_status` | `VARCHAR(16) NOT NULL DEFAULT 'not_required'` | `:64` | `string(..., 16)->default('not_required')` | ✓ |
| 169 `signature_algorithm` | `VARCHAR(64)` (nullable) | `:65` | `string(..., 64)->nullable()` | ✓ |
| 170 `signature_value` | `TEXT` (nullable) | `:66` | `text(...)->nullable()` | ✓ |
| 171 `signature_counter` | `BIGINT` (nullable) | `:67` | `bigInteger(...)->nullable()` | ✓ |
| 172 `signature_provider` | `VARCHAR(64)` (nullable) | `:68` | `string(..., 64)->nullable()` | ✓ |
| 173 `signing_device_id` | `UUID` (nullable) | `:69` | `uuid(...)->nullable()` | ✓ |
| 174 `certificate_id` | `VARCHAR(128)` (nullable) | `:70` | `string(..., 128)->nullable()` | ✓ |
| 175 `signed_payload_ref` | `TEXT` (nullable) | `:71` | `text(...)->nullable()` | ✓ |
| 176 `time_source_value` | `VARCHAR(64)` (nullable) | `:72` | `string(..., 64)->nullable()` | ✓ |
| 177 `time_format` | `VARCHAR(32)` (nullable) | `:73` | `string(..., 32)->nullable()` | ✓ |
| 178 `provider_transaction_id` | `VARCHAR(128)` (nullable) | `:74` | `string(..., 128)->nullable()` | ✓ |
| 180 `integrity_status` | `VARCHAR(24) NOT NULL DEFAULT 'verified'` | `:78` | `string(..., 24)->default('verified')` | ✓ |
| 181 `integrity_exception_class` | `VARCHAR(32)` (nullable) | `:79` | `string(..., 32)->nullable()` | ✓ |
| 182 `integrity_exception_reason` | `TEXT` (nullable) | `:80` | `text(...)->nullable()` | ✓ |
| 183 `integrity_resolved_at` | `TIMESTAMPTZ` (nullable) | `:81` | `timestampTz(...)->nullable()` | ✓ |
| 184 `integrity_resolved_by` | `UUID` (nullable) | `:82` | `uuid(...)->nullable()` | ✓ |
| 186 `payload` | `JSONB` (nullable) | `:85` | `jsonb(...)->nullable()` | ✓ |
| 187 `payload_parse_status` | `VARCHAR(16) NOT NULL DEFAULT 'pending'` | `:86` | `string(..., 16)->default('pending')` | ✓ |
| 188 `created_at` | `TIMESTAMPTZ NOT NULL DEFAULT NOW()` | `:89` | `timestampTz('created_at')->useCurrent()` | ✓ |

**38 spec columns, 38 migration columns, zero drift.** Nothing extra; nothing missing; nothing renamed.

The spec is explicit that `fiscal_events` carries **no** projection-state columns (spec §3.2 line 192: "projection state lives in the mutable `fiscal_event_projections` table"). The migration honors this — no `projection_status`, no `last_projection_attempt_at`, etc. Task 9 will lay that down separately.

The spec is also explicit that `updated_at` does not exist (the table is immutable chain truth with a narrow allowlisted mutable surface guarded by the Task 8 trigger). The migration honors this — no `timestamps()`, just an explicit `created_at` and no `updated_at`. The model's `public $timestamps = false` (`FiscalEvent.php:65`) matches.

---

## Constraints and indexes conformance (spec §3.2 lines 194–207)

| Spec invariant | Migration evidence | Verdict |
|---|---|---|
| `UNIQUE (tenant_id, terminal_id, sequence_number)` | `:93–96` `$table->unique([...], 'fiscal_events_tenant_terminal_sequence_unique')` — created **inline** so it works on both SQLite and PG (and the Task-7 test relies on it firing under PG) | ✓ |
| `UNIQUE (source_event_class, source_event_id) WHERE source_event_id IS NOT NULL` | `:100–104` raw `CREATE UNIQUE INDEX … WHERE source_event_id IS NOT NULL`, PG-gated | ✓ |
| `INDEX (reference_event_id) WHERE reference_event_id IS NOT NULL` | `:107–111` raw `CREATE INDEX … WHERE …`, PG-gated | ✓ |
| `INDEX (reference_document_id) WHERE reference_document_id IS NOT NULL` | `:112–116` raw `CREATE INDEX … WHERE …`, PG-gated | ✓ |
| `INDEX (integrity_status) WHERE integrity_status <> 'verified'` | `:117–121` raw `CREATE INDEX … WHERE integrity_status <> 'verified'`, PG-gated | ✓ |
| `CHECK (sequence_number > 0)` | `:124` `ALTER TABLE … CHECK (sequence_number > 0)`, PG-gated, named `fiscal_events_sequence_positive` | ✓ |
| `CHECK (current_hash ~ '^[0-9a-f]{64}$')` | `:125` `ALTER TABLE … CHECK (current_hash ~ '^[0-9a-f]{64}$')`, PG-gated, named `fiscal_events_current_hash_format`; backslash-escaped `$` so PHP heredoc-like double-quotes pass the literal regex to PG | ✓ |
| `CHECK (previous_hash ~ '^[0-9a-f]{64}$')` | `:126` matching constraint for `previous_hash`, named `fiscal_events_previous_hash_format` | ✓ |
| `CHECK ((source_event_class IS NULL AND source_event_id IS NULL) OR (… NOT NULL …))` | `:127–132` raw `CHECK` with both arms, PG-gated, named `fiscal_events_source_event_paired_null` | ✓ |
| `CHECK (event_type IN (…Appendix A reserved values…))` | `:135–136` `FiscalEventType::checkConstraintList()` interpolated into the `CHECK`, named `fiscal_events_event_type_allowed` — this means the DB-level whitelist is **always derived from the PHP enum** and cannot drift | ✓ — and worth highlighting as a strength |

All four named CHECK constraints, the inline composite UNIQUE, the partial UNIQUE, and the three partial indexes are present. Every PG-specific DDL is correctly fenced inside `DB::connection()->getDriverName() === 'pgsql'`, which is the project's standard idiom (`apps/api/tests/Feature/Tenant/ResetTenantCommandTest.php:48`, etc.).

---

## `FiscalEvent` model conformance

| Aspect | Plan / spec requirement | Code evidence | Verdict |
|---|---|---|---|
| Namespace | `App\Modules\Fiscal\Domain\Models` per the module's `Domain/Models/` convention | `FiscalEvent.php:5` | ✓ |
| Table name | `'fiscal_events'` | `:54` `protected $table = 'fiscal_events';` | ✓ |
| Primary key type | UUID (string, non-incrementing) | `:57` `$keyType = 'string';`, `:60` `$incrementing = false;` | ✓ |
| Auto-timestamps | Disabled — table is immutable; only `created_at` exists and is set by `DEFAULT NOW()` | `:65` `$timestamps = false;` (with explanatory PHPDoc above) | ✓ |
| `event_type` cast | `FiscalEventType::class` (plan §625) | `:117` | ✓ — enum exists at `app/Modules/Fiscal/Domain/Enums/FiscalEventType.php` and all 29 cases match the `VARCHAR(64)` whitelist |
| `integrity_status` cast | `IntegrityStatus::class` | `:124` | ✓ — enum has `Verified = 'verified'`, `Quarantined = 'quarantined'` aligned to the `VARCHAR(24)` default `'verified'` |
| `signature_status` cast | `SignatureStatus::class` | `:122` | ✓ — enum has `NotRequired = 'not_required'` aligned to the column default |
| `payload_parse_status` cast | `PayloadParseStatus::class` | `:128` | ✓ — enum has `Pending = 'pending'` aligned to the column default |
| `payload` cast | `'array'` | `:127` | ✓ — JSONB column, array cast |
| `partner_identity_snapshot` cast | `'array'` | `:121` | ✓ — JSONB column, array cast |
| Date casts | `event_time_device`, `business_date`, `last_server_time_seen`, `server_received_at`, `integrity_resolved_at`, `created_at` | `:118–120`, `:125`, `:129` | ✓ — all six datetime/date columns cast (`business_date` as `'date'`, others as `'datetime'`) |
| Integer casts | `event_version`, `sequence_number`, `signature_counter` | `:115`, `:116`, `:123` | ✓ |
| `$fillable` is complete | Every column the ingestor / resolver / parser writes is fillable | `:73–110` lists all 37 writable columns (everything except `created_at`, which is server-set) | ✓ |
| No cross-module imports | Only `App\Modules\Fiscal\Domain\Enums\…` | `:7–10` | ✓ |
| No `app()` helper | Required by CLAUDE.md rule 13 | (none in file) | ✓ |
| No `mixed` parameters/returns | Allowed in PHPDoc `array<string, mixed>` shape annotations for JSONB casts only | `:41,61` — shape annotations only; signatures use concrete types | ✓ |
| Strict types declared | `declare(strict_types=1);` | `:3` | ✓ |
| Final class | Convention for module models | `:51` `final class FiscalEvent extends Model` | ✓ |
| `@property` annotations | Comprehensive for IDE / PHPStan inference | `:32–69` covers all 38 columns plus the projection-status absence note | ✓ |

The model is intentionally minimal — it carries no relations, no scopes, no business methods. That matches the spec's framing (`fiscal_events` is chain truth, not a behavior surface) and the plan's framing (Task 7 lays the storage; Tasks 8/9/19 add the trigger / projections / ingestor).

---

## Test discipline (plan §577–631)

| Aspect | Required | Actual | Verdict |
|---|---|---|---|
| Test exists at spec'd path | `apps/api/tests/Feature/Fiscal/FiscalEventsTableTest.php` | Yes | ✓ |
| Uses `RefreshDatabase` | Plan §583 | `:14` | ✓ |
| Column-existence test (non-skipped on SQLite) | Plan §591–601 | `:23–40` — same column list as the plan, plus `'created_at'` per the plan | ✓ — runs RED→GREEN on SQLite |
| `test_unique_sequence_key_blocks_duplicate_slot` | Plan §603–608 | `:42–54` | ✓ — `skipUnlessPostgres()` then inserts two rows with the same `(tenant, terminal, sequence)` slot and `expectException(QueryException::class)` |
| `test_hash_format_check_constraint` | Plan §610–614 | `:56–63` | ✓ — `skipUnlessPostgres()` then inserts `current_hash = 'NOT-HEX'` and asserts violation |
| Additional `test_event_type_check_rejects_unknown_value` | Implementer-added per plan §625 (event-type CHECK is explicitly required, so a guarding test is sound) | `:65–72` | ✓ — pinning the spec's event-type whitelist behavior is a strict improvement |
| Additional `test_source_event_paired_null_check` | Implementer-added per plan §625 (paired-null CHECK is explicitly required) | `:74–84` | ✓ — pinning the spec's paired-null invariant is a strict improvement |
| Genuine RED before migration | Reviewer must verify, not the implementer | Verified by moving `2026_05_14_100001_create_fiscal_events_table.php` out of `database/migrations/` and re-running: `test_table_has_all_chain_and_integrity_columns` failed at `Schema::hasTable`. Restoring the file → all green. | ✓ |
| GREEN after migration | Implementer claim | Verified — `5 tests, 22 assertions, 4 skipped`. The 4 skips are the four PG-only constraint cases; the column-existence case is the non-skipped RED→GREEN that the plan's Step 4 promises. | ✓ |
| Skip-on-SQLite pattern matches project precedent | Plan §625 (implicit — CHECK constraints only enforced on PG) | `skipUnlessPostgres()` (`:126–131`) emits `markTestSkipped('CHECK / partial-unique constraints only enforced on PostgreSQL')`. Same idiom as `ZReportCountModelTest:48,69`, `VoucherLedgerTest:83,98`, `CompanyFraudSettingsCashControlsTest:40`, `MigrationSchemaTest:127,137,153,169`. | ✓ |
| `insertEvent()` builds a valid default row | The test's helper must be schema-true so violations don't come from incidental NOT NULL gaps | `:87–124` — every NOT NULL column is populated (`id`, `tenant_id`, `company_id`, `terminal_id`, `operator_id`, `event_type`, `event_version`, `signature_version`, `sequence_number`, `event_time_device`, `business_date`, `server_received_at`, `canonical_bytes`, `previous_hash`, `current_hash`). Defaults handle `signature_status`, `integrity_status`, `payload_parse_status`, `created_at`. | ✓ |

---

## Implementer deviations checked

The task brief flagged four declared deviations. Reviewed each:

1. **SQLite-skip pattern for PG-only constraint tests (`skipUnlessPostgres()`).** Verdict: **sound, matches project precedent.** The `apps/api/phpunit.xml` configuration uses `DB_CONNECTION=sqlite` / `:memory:`. CHECK constraints, partial UNIQUE indexes, and the `~` regex operator are PG-only. The project has eleven existing call sites of the identical `markTestSkipped('… only enforced on PostgreSQL')` idiom (full list in the verification table above). The implementer chose a small `skipUnlessPostgres()` helper rather than inlining the check four times; that's a readability improvement, not a deviation in substance. The non-skipped column-existence test ensures new contributors get RED on SQLite if they break the schema, satisfying the plan's discipline.

2. **Two extra tests beyond the plan's three (`test_event_type_check_rejects_unknown_value`, `test_source_event_paired_null_check`).** Verdict: **sound, strictly additive.** Plan §625 explicitly requires both the event-type whitelist CHECK and the source-event paired-null CHECK; the plan's Step 1 stub only happened to cover sequence-uniqueness and hash-format. Adding tests that pin the two remaining required constraints is exactly what a thorough implementer should do, and is consistent with the project's "constraints/invariants get a guarding test" pattern (`ExternalIdUniquenessTest`, `MigrationSchemaTest`, `ZReportCountModelTest`).

3. **`insertEvent()` reuses `static $stickyTenant` / `$stickyTerminal` across calls within the same test.** Verdict: **sound for current usage but worth a comment for future readers.** The static keeps tenant/terminal pinned so the duplicate-slot test triggers the UNIQUE on the second insert (rather than the first generating a fresh UUID per call and accidentally inserting two non-colliding rows). PHP `static` in a method persists for the *process*, not per-test, but `RefreshDatabase` drops/recreates the schema between tests so the rows from a prior test never linger — and no test in this file does two inserts where the second needs a *different* tenant from the first. The pattern is correct for the four cases written. If a future test wants two inserts where the second has a fresh tenant, it would need to reset the static or pass an override; not a blocker, just a behavioral note. No finding raised.

4. **Named CHECK / INDEX constraints (e.g. `fiscal_events_sequence_positive`, `fiscal_events_event_type_allowed`).** Verdict: **sound, matches project precedent.** The spec doesn't name constraints; the implementer chose `fiscal_events_<purpose>` names, which is the same convention `ExternalIdUniquenessTest:194` and `MigrationSchemaTest:153` introspect. Named constraints make Task 8's immutability-trigger work and Task 31's `fiscal:verify-event-chain` introspection easier than anonymous `pg_constraint` rows. Strict improvement.

---

## Findings

**None.** No BLOCKER, no P1, no P2, no P3.

The only thing I would *consider* raising — and explicitly chose not to — is the `static $stickyTenant` subtlety in deviation 3. It is a correct pattern for the four cases written and the project's `RefreshDatabase` discipline; flagging it would be teaching-style rather than risk-style. If the implementer wants to add a one-line comment "// Static persists for the process; RefreshDatabase keeps rows scoped per-test" that would be a nicety, but it isn't required.

---

## Cross-task regression check

| Prior task | Files touched in `245f5e85`? | Verdict |
|---|---|---|
| Task 1 (Fiscal module skeleton + RoadmapItem) | No | ✓ no regression |
| Task 2 (`FiscalEventType` enum) | No (only **read** by the migration via `FiscalEventType::checkConstraintList()`) | ✓ no regression |
| Task 3 (`IntegrityStatus` / `PayloadParseStatus` / `SignatureStatus` enums) | No (only **read** by the model casts) | ✓ no regression |
| Task 4 (canonical-golden-vectors fixture + PHP hash-only test) | No | ✓ no regression |
| Task 5 (`FiscalEventCanonicalEncoder` TS) | No | ✓ no regression |
| Task 6 (`FiscalIntegrityProvider` / `HashChainIntegrityProvider` + `SignatureProviderInterface`) | No | ✓ no regression |

The commit's three-file scope is exactly what Task 7 promises.

---

## Recommendation

**APPROVE — proceed to Task 8** (`create_fiscal_events_immutability` triggers — the BEFORE UPDATE / DELETE / TRUNCATE trigger trio that the spec §3.3 layers on top of this table, with the `pos_receipts` immutability trigger as the existing model).

Two forward-looking notes for the reviewer of Task 8, since they will be touching the same table:

- The migration's named CHECK constraints (`fiscal_events_sequence_positive`, etc.) give Task 8's allowed-column-list trigger something to refer to in its `pg_constraint` introspection if it wants to enumerate columns that are CHECK-guarded versus columns that are mutability-guarded.
- The model's `$fillable` deliberately includes the mutable surface (`payload`, `payload_parse_status`, integrity columns) so the StrictCanonicalParser (Task 16) and the quarantine resolver can update through Eloquent. Task 8's trigger must permit exactly that allowlist (`payload`, `payload_parse_status`, `integrity_status`, `integrity_exception_class`, `integrity_exception_reason`, `integrity_resolved_at`, `integrity_resolved_by`) per spec §3.3 line 214 — and the model's `$fillable` is the source of truth that any drift between the two would surface as.

No follow-ups required before Task 8 begins.
