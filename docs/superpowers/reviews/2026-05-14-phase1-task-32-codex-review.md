# Codex Adversarial Review — Task 32 Off-Device Durability Controls + Device-Loss Incident Register

Reviewed commit: `b09665a33 feat(fiscal): off-device durability controls + device-loss incident register (§12)`

Authority checked: spec v7 §12 (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:566-568`), SoT v3 §1/D1/D8 (`docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md:12-25,254-261`), plan Task 32 (`docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:2676-2734`), and codebase reality audit crypto note (`docs/superpowers/research/2026-05-14-pos-fiscal-codebase-reality.md:108-114`).

## Findings

### Class: BLOCKER
ID: T32-B1
Path: `apps/api/tests/Feature/Fiscal/DeviceLossIncidentTest.php:103`

Description: The PG-only `test_recovery_status_check_allows_every_enum_value_on_postgres()` cannot pass against the migration it is meant to validate. The test inserts raw `device_loss_incidents` rows for every valid enum value (`DeviceLossIncidentTest.php:103-114`), but `incidentRawRow()` fills `tenant_id`, `company_id`, `terminal_id`, and `reported_by` with random UUIDs (`DeviceLossIncidentTest.php:227-242`). The migration adds real PostgreSQL FKs for all four columns (`2026_05_20_100001_create_device_loss_incidents_table.php:96-115`). With `DeviceLossIncidentTest` included in the PG merge-gate filter (`.github/workflows/ci.yml:460-480`), this valid-enum test will fail on FK violations before proving the CHECK allowlist. The invalid-enum test at `DeviceLossIncidentTest.php:93-100` is also unsound because any `QueryException` could be caused by those same orphan FKs rather than `device_loss_incidents_recovery_status_allowed`.

Resolution: Build valid referenced rows in the PG tests before inserting incidents, or set nullable `reported_by` to null where user identity is irrelevant and seed real tenant/company/terminal records for the required FKs. For the invalid enum test, assert the SQLSTATE/constraint name so it proves the CHECK, not an unrelated FK.

### Class: BLOCKER
ID: T32-B2
Path: `apps/pos/src/components/fiscal/UnsyncedRiskIndicator.tsx:45`

Description: Spec §12 requires an operator-visible unsynced-risk indicator and a forced archive/export threshold (`spec-v7.md:568`). This commit creates an indicator component (`UnsyncedRiskIndicator.tsx:45-98`) and exposes `shouldForceArchive()` (`OffDeviceDurabilityService.ts:264-267`), but neither is wired into the POS UI or authoring flow. Repository grep for `UnsyncedRiskIndicator`, `OffDeviceDurabilityService`, `unsyncedRisk()`, and `shouldForceArchive()` shows references only in the new files/tests, with no caller mounting the component or enforcing the threshold. That means the delivered behavior is not operator-visible and does not force any archive/export at threshold; it is a dead control surface.

Resolution: Mount the indicator in an always-visible POS shell surface, instantiate the service from real terminal durability config, and wire `shouldForceArchive()` into the operator workflow that gates further fiscal authoring until an off-device archive/export succeeds. If transfer mechanics remain Phase 2, Task 32 still needs a Phase 1 gate/action path that is actually reachable.

### Class: P1
ID: T32-P1
Path: `apps/api/database/migrations/2026_05_20_100001_create_device_loss_incidents_table.php:96`

Description: The incident register stores `tenant_id`, `company_id`, and `terminal_id` as the forensic scope of a lost terminal (`2026_05_20_100001_create_device_loss_incidents_table.php:43-48`), but the migration only adds independent FKs to `tenants(id)`, `companies(id)`, and `pos_terminals(id)` (`2026_05_20_100001_create_device_loss_incidents_table.php:96-110`). `pos_terminals` itself carries `tenant_id` and `company_id` (`2026_01_08_190429_create_pos_terminals_table.php:24-30`). With independent FKs, PostgreSQL will accept an incident whose `tenant_id` and `company_id` belong to tenant A while `terminal_id` points to a terminal from tenant B, as long as each UUID exists. The test suite only checks FK names (`DeviceLossIncidentTest.php:166-181`), not cross-tenant rejection.

Resolution: Add a tenant/company-scoped terminal integrity constraint: for example, create a unique key on `pos_terminals(id, tenant_id, company_id)` and use a composite FK from `device_loss_incidents(terminal_id, tenant_id, company_id)`, or add an equivalent DB-level trigger/check that rejects mismatched terminal scope. Add a PG test that proves cross-tenant/company terminal combinations are rejected.

### Class: P2
ID: T32-P2
Path: `apps/pos/src/lib/fiscal/OffDeviceDurabilityService.ts:129`

Description: The service comments claim sensible defaults for `forcedArchiveUnsyncedThreshold`, `escalatedUnsyncedThreshold`, and `unsyncedAgeWarnThresholdSeconds` (`OffDeviceDurabilityService.ts:122-135`), and the plan examples use default 50/500 behavior (`2026-05-14-pos-phase1-fiscal-event-engine.md:2695-2704`). The implementation makes all three fields required (`OffDeviceDurabilityService.ts:129-135`) and the constructor never supplies defaults or validates values (`OffDeviceDurabilityService.ts:191-205`). If config comes from JSON/DB, the same erased-type path that motivated the runtime key-custody guard can omit or corrupt thresholds; comparisons against `undefined` or nonsensical negative thresholds will silently weaken or over-trigger the control (`OffDeviceDurabilityService.ts:242-267`).

Resolution: Make thresholds optional at the boundary and normalize them in the constructor with defaults of 50, 500, and 3600 seconds. Validate they are positive finite integers and that `escalatedUnsyncedThreshold >= forcedArchiveUnsyncedThreshold`; throw a typed config error otherwise.

### Class: P2
ID: T32-P3
Path: `apps/pos/src/lib/fiscal/__tests__/OffDeviceDurabilityService.test.ts:35`

Description: The new POS tests are real-SQLite tests, but they skip the entire suite when `node:sqlite` is unavailable (`OffDeviceDurabilityService.test.ts:35-44`). The shared adapter explicitly requires Node 22.5+ (`sqliteTestAdapter.ts:1-15`), while CI pins `NODE_VERSION: '20'` (`.github/workflows/ci.yml:14-17`) and the `pos-test` job uses that version (`.github/workflows/ci.yml:565-592`). On the configured CI runtime, the Task 32 Vitest coverage is skipped, so the SQL predicate, threshold behavior, and key-custody guard are not actually merge-gated.

Resolution: Either run the POS test job on a Node version that provides `node:sqlite` or replace the skip-based adapter with a test SQLite dependency available on Node 20. At minimum, add a CI assertion that this specific suite executes non-zero tests.

## CLEAN

- Spec §12 was read directly. The implementation documents the on-device AES-GCM `.izipos_key` path as crash-recovery only, not a conservation control (`OffDeviceDurabilityService.ts:7-14,30-41,157-170`), matching the reality audit (`2026-05-14-pos-fiscal-codebase-reality.md:108-114`).
- Off-device path enumeration is present for the four spec examples: encrypted removable archive, LAN peer, NAS, and cloud sync (`OffDeviceDurabilityService.ts:100-120`). Empty path config is rejected at construction (`OffDeviceDurabilityService.ts:191-194`).
- Key custody outside terminal disk is defended at construction for erased JSON/DB config via `OnDeviceKeyCustodyForbiddenError` (`OffDeviceDurabilityService.ts:69-88,157-173,195-204`).
- `unsyncedRisk()` queries the actual Task 13 SQLite `fiscal_events` schema. `sync_status` exists with allowed values `pending|syncing|synced|failed` and the partial pending index uses the same predicate (`migrations.ts:961-975,1001-1003`); the service counts `pending`, `syncing`, and `failed` (`OffDeviceDurabilityService.ts:279-284`) and computes age from `created_at` (`OffDeviceDurabilityService.ts:301-314`), which the append path writes at local authoring time (`FiscalEventEngine.ts:396-443`). The SQL uses no interpolated user input.
- The service does not silently transfer archives, does not define `forceArchive()` transfer mechanics, and does not include any `restoreFromArchive()` or write path back into `fiscal_events` (`OffDeviceDurabilityService.ts:30-35,279-314`). This preserves the single fiscal pattern and does not conflate durability archives with the canonical chain.
- PHP enum values and the migration CHECK constraint match byte-for-byte: `reported`, `recovering`, `resolved`, `unrecoverable` (`DeviceLossIncidentStatus.php:20-26`; migration `2026_05_20_100001...php:84-88`).
- `recovery_status` has a DB default of `reported` (`2026_05_20_100001...php:66-72`) and is intentionally excluded from `$fillable` (`DeviceLossIncident.php:62-83`), matching the Task 9/10 lifecycle-column pattern.
- `reported_by` is nullable in the migration (`2026_05_20_100001...php:50-57`) and covered by a test (`DeviceLossIncidentTest.php:77-82`).
- The requested admin and recovery indexes exist on PostgreSQL: `(tenant_id, terminal_id)` and partial `(recovery_status, reported_at)` for open incidents (`2026_05_20_100001...php:117-133`).
- Migration timestamp `2026_05_20_100001` is unique in `apps/api/database/migrations`.
- POS Tailwind-direct styling is consistent with nearby POS fiscal atoms; `ChainBreakAlert` and `TerminalNotReadyBanner` also use direct Tailwind classes rather than a `designTokens` module (`ChainBreakAlert.tsx:22,42,55`; `TerminalNotReadyBanner.tsx:20,28`).
- i18n registration includes import, resources, and namespace array entries for `fiscal` (`apps/pos/src/lib/i18n.ts:4-30`). English and French files contain every key used by `UnsyncedRiskIndicator` (`apps/pos/src/locales/en/fiscal.json:1-14`; `apps/pos/src/locales/fr/fiscal.json:1-14`; component key usage at `UnsyncedRiskIndicator.tsx:94-95`).
- `DeviceLossIncidentTest` is included in the PG merge-gate filter (`.github/workflows/ci.yml:460-480`).
- No `app()` helper, `App::`, or `resolve()` usage appears in the new PHP enum/model/migration/test files.
- Concurrency/race risk in `unsyncedRisk()` is limited to read-only polling over sync lifecycle state. It can oscillate between levels while sync progresses, but it does not mutate fiscal truth; once mounted, UI debouncing could polish display behavior without changing the conservation control.

VERDICT: REJECT
