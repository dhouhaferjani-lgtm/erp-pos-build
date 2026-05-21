# Phase 1 — Task 32 Opus Spec-Compliance Review

**Reviewer:** Opus 4.7 (1M context) — 13th Opus subagent delegation, Phase 1 review cycle
**Scope:** Task 32 — Off-device durability controls + device-loss incident register (spec v7 §12)
**Commit:** `b09665a33` (11 files; +1298/−2)
**Branch:** `feat/pos-fiscal-event-engine-phase1`
**Authority:** spec v7 §12 (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:566-568`) + plan §2676–2734 (`docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:2676`).

---

## 1. Verdict summary

Task 32 delivers the v7 §12 Phase-1 gate (Phase 1 → Phase 2 customer-facing deployment gate) correctly. All seven §12 deliverables are present, the TS service contract is sound, the PHP register table mirrors the canonical Task 9 / Task 10 pattern, the CI PG-merge-gate is extended in the same commit, and verification commands pass (vitest 12/12, fiscal lib 161/161, PHPUnit Fiscal 271/271, §14.3 chokepoint gate 9 reconciled / 7 manifest receivers, PHPStan + Pint clean on new files, typecheck clean, no new lint warnings on Task 32 files).

The implementer's four documented decisions (migration slot bump, Tailwind-direct deviation, `OnDeviceKeyCustodyForbiddenError` defense-in-depth, `recovery_status` boundary discipline) are all justified by existing codebase conventions and the standing patterns from Tasks 9 / 10 / 18 / 19 / 21 / 22 / 29 / 30.

No BLOCKERs. No P1s. No P2s. Two P3 observations and one note for the next subagent.

**Findings:** 0 BLOCKER · 0 P1 · 0 P2 · 2 P3 · 1 NOTE
**Verdict:** APPROVE

---

## 2. Spec §12 deliverable coverage

Spec §12 (verbatim): *"Phase 1 delivers: at least one off-device durability path (encrypted removable archive / LAN peer / NAS / cloud-sync) with key custody **outside** the terminal disk; the on-device AES-GCM copy as crash-recovery only; an operator-visible unsynced-risk indicator; a forced archive/export threshold; a maximum-unsynced escalation; a device-loss incident register. **A Phase 1 gate before any Phase 2 customer-facing deployment.**"*

| §12 deliverable | Where | Verified |
|---|---|---|
| At least one off-device durability path | `OffDeviceDurabilityService.availablePaths()` + `EmptyOffDeviceDurabilityConfigError` rejects zero-path config at construction | ✓ (TS test at L195–203) |
| Key custody OUTSIDE terminal disk | `OffDeviceDurabilityPath` discriminated union excludes `'on-device'` at compile time; `OnDeviceKeyCustodyForbiddenError` rejects at runtime; `keyCustody()` returns literal `'external'` | ✓ (TS test at L205–227) |
| Path-kind enumeration (encrypted-removable-archive / lan-peer / nas / cloud-sync) | `OffDeviceDurabilityPath` union has all four discriminants | ✓ (TS test at L180–193) |
| On-device AES-GCM is crash-recovery only, NOT a conservation control | Documented in service docblock L7–14, L37–41; runtime guard rejects on-device custody in the durability path config; `.izipos_key` not referenced in service code | ✓ |
| Operator-visible unsynced-risk indicator | `UnsyncedRiskIndicator.tsx` (98 lines); polls `service.unsyncedRisk()` on interval; renders three levels with color + `data-risk-level` + `role="status" aria-live="polite"`; uses `t()` from new `fiscal` namespace | ✓ |
| Forced archive/export threshold | `shouldForceArchive()` returns `count >= forcedArchiveUnsyncedThreshold` (default 50) | ✓ (TS test at L251–259, L315–322) |
| Maximum-unsynced escalation | `unsyncedRisk()` returns `'escalated'` at `count >= escalatedUnsyncedThreshold` (default 500) | ✓ (TS test at L261–269) |
| Device-loss incident register | `DeviceLossIncident` model + `device_loss_incidents` table + `DeviceLossIncidentStatus` enum + PG CHECK + 4 FKs + 2 indexes (one partial) + boundary discipline (`recovery_status` not fillable) | ✓ (PHP 11 tests, PHP 6 PG-only assertions skip on SQLite as designed) |
| Phase 1 gate documented | Service docblock L13–14; migration docblock L15–22; plan §2723 reads "Document that §12 is a Phase 1 gate before any Phase 2 customer-facing deployment"; the spec-§12 carry-forward is captured in the implementer's commit body | ✓ |

All seven spec §12 deliverables are present. The "Phase 1 gate" framing is documented in service docblock + migration docblock + commit body.

---

## 3. `OffDeviceDurabilityService.ts` review

**Read in full (315 lines).** Constructor injection (rule 13) ✓. No `app()` helper. No `any` types. Strict typing throughout.

### 3.1 Configuration contract

- `OffDeviceDurabilityConfig` interface declares `paths`, three threshold knobs, and the `sqlSurface` handle (structural `Database | SqlSurface` so the service stays driver-portable + testable against `SqliteTestAdapter`). ✓
- Defaults documented in plan + service: forced 50, escalated 500, age 3600s (1h). ✓
- `OffDeviceDurabilityPath` discriminated union has all four §12 kinds, each with a `keyCustody: Exclude<KeyCustody, 'on-device'>` field — compile-time enforcement of the §12 invariant. ✓
- `KeyCustody` union includes `'on-device'` deliberately so the runtime guard has a string to match against after a JSON-from-config erasure has stripped the type narrowing. The docblock L73–80 explains this clearly. ✓

### 3.2 Construction-time invariants (constructor as a gate — standing pattern from Task 18)

The constructor enforces two §12 invariants at construction time, throwing typed errors:
1. `paths.length === 0` → `EmptyOffDeviceDurabilityConfigError`. ✓
2. Any path's `keyCustody === 'on-device'` → `OnDeviceKeyCustodyForbiddenError`. The loop iterates all paths and casts through `unknown` to defend against config-loader code paths that bypass the type system. ✓

This is exactly the Task 18 boot-time-invariants-asserted-in-constructor pattern — applied correctly. The implementer's added `OnDeviceKeyCustodyForbiddenError` (mentioned in the brief as decision #3) is the right discriminator: it differentiates "zero paths configured" from "a path was configured with disallowed custody", which gives the operator UI a precise error to surface.

### 3.3 SQL surface

- `countUnsyncedFiscalEvents()` reads `COUNT(*) FROM fiscal_events WHERE sync_status IN ('pending', 'syncing', 'failed')`. This exactly matches the device migration v37's partial sync-pending index condition (`apps/pos/src/lib/db/migrations.ts:1001-1003`) — the query uses the index. ✓
- `oldestUnsyncedAgeSeconds()` reads `MIN(created_at)` with the same filter; computes the delta against `Date.now()` in TS (not SQLite's `julianday()`/`strftime()`) so the service is driver-portable across `node:sqlite` and Tauri's plugin-sql. ✓
- `Date.parse()` returns `NaN` if the format is unexpected; the service returns `null` defensively in that case. ✓
- Negative delta (clock skew) clamped to 0. ✓

### 3.4 `unsyncedRisk()` discrimination ladder

```
count >= escalatedUnsyncedThreshold        → 'escalated'
count >= forcedArchiveUnsyncedThreshold    → 'elevated'
oldestAgeSeconds >= unsyncedAgeWarnThreshold → 'elevated'   (independent age path)
else                                       → 'normal'
```

This matches the §12 intent ("forced archive/export threshold" + "maximum-unsynced escalation" + the operator-visible indicator's three levels). The age path is documented as a warn-but-don't-force signal so a single hour-old event doesn't gate authoring — sound product reasoning, explicitly captured in the docblock + the test at L271–287.

### 3.5 `shouldForceArchive()` independence from `unsyncedRisk()`

`shouldForceArchive()` re-queries the count rather than reading the cached `unsyncedRisk()` result. The plan does not require this to be derived; the implementer chose a separate query so the policy bit (force-archive) can race with the operator's risk-level display without one mutating the other. Defensible. The two run different SQL statements against the same table so the answers are eventually consistent. ✓

### 3.6 Boundary discipline

- Service is read-only in Pass 1 (no mutation of `fiscal_events`). ✓
- No `app()` helper, no module-scope singletons. ✓
- No deviation from rule 13. ✓
- Pass 1 explicitly defers actual archive transfer mechanics to Phase 2 (docblock L30–35) — correct boundary; the spec §12 mandate is the *control surface*, not the transfer. ✓

---

## 4. `UnsyncedRiskIndicator.tsx` review

**Read in full (98 lines).**

- Translation via `useTranslation('fiscal')` + `t('unsyncedRisk.label.${level}')` + `t('unsyncedRisk.detail.${level}')`. No hardcoded strings (rule 11). ✓
- Polls `service.unsyncedRisk()` via `useEffect` + `setInterval`; cancellation flag prevents post-unmount state updates. ✓
- `pollIntervalMs = 0` disables polling (for tests). ✓
- Error path: catches service errors and surfaces null (returns `null` from render so the component disappears rather than crashing the UI). Documented L60–64. Reasonable for the operator-visible badge.
- Accessibility: `role="status"`, `aria-live="polite"`, `data-risk-level` attribute for E2E test hooks. The icon `<span>` has `aria-hidden="true"`. ✓
- Service is passed as a prop (constructor injection at the container layer — rule 13). ✓

**Decision #2 (design tokens deviation) verification:** Checked the sibling atom components `apps/pos/src/components/atoms/ChainBreakAlert.tsx` and `apps/pos/src/components/atoms/TerminalNotReadyBanner.tsx`. Both use Tailwind classes directly (`bg-red-50 border border-red-300 text-red-900` etc.) — there is no `designTokens` module under `apps/pos/src/lib/`. The `designTokens` module lives at `apps/web/src/lib/designTokens.ts` and is enforced for that app, not apps/pos. The implementer correctly followed the established POS pattern. Decision is sound.

The Tailwind palette (`bg-green-50 / bg-amber-50 / bg-red-50` + `border-X-300` + `text-X-900`) matches the `ChainBreakAlert` red palette stylistically, so the visual language is consistent across fiscal-status atoms.

---

## 5. `DeviceLossIncident` model + migration review

### 5.1 `DeviceLossIncident.php` (99 lines)

- `final class DeviceLossIncident extends Model` — sibling-canonical (matches `FiscalEvent`, `FiscalEventQuarantine`, `FiscalEventProjectionRow`). ✓
- `protected $keyType = 'string'` + `public $incrementing = false` — sibling-canonical for UUID PKs. ✓
- **No `HasUuids` trait** — the brief asks for it, but no sibling Fiscal model uses it. The canonical Task 9 / Task 10 / Task 19 pattern relies on callers supplying the UUID via `Str::uuid()->toString()` (or the migration's `useCurrent()` for timestamps). The test at L208 does exactly this. The implementer correctly mirrored the sibling pattern. ✓ (See finding P3-1.)
- `$timestamps = true` — correct for a mutable lifecycle table (unlike `FiscalEventQuarantine` which is append-only-from-OutboxIngestor; this table tracks `recovery_status` transitions so `updated_at` is meaningful). ✓
- `$fillable` includes all insert-time identity + capture columns; **excludes `recovery_status`** — Task 9 / Task 10 standing pattern: lifecycle columns are NOT mass-assignable. The docblock L62–69 explains this. The test at L184–199 pins the discipline. ✓ (Decision #4 verified.)
- `casts()` covers `reported_at`, `last_synced_event_at`, `unsynced_count_at_incident` (integer), `recovery_status` (enum cast to `DeviceLossIncidentStatus`), `created_at`, `updated_at`. All datetime + integer + enum casts present. ✓

### 5.2 `DeviceLossIncidentStatus.php` (26 lines)

- 4 cases: `Reported / Recovering / Resolved / Unrecoverable`. ✓
- Lifecycle transition map documented in the docblock L11–14: `Reported → Recovering → Resolved | Unrecoverable`. ✓
- Whitelist pinned at DB layer — explicit cross-reference. ✓

### 5.3 Migration `2026_05_20_100001_create_device_loss_incidents_table.php` (141 lines)

**Decision #1 verification:** `2026_05_14_100007` slot is already occupied by `2026_05_14_100007_widen_quarantine_class_check_for_malformed_envelope.php` (confirmed). Implementer correctly picked the next unique slot `2026_05_20_100001` which is monotonically later. ✓ No collisions.

Table shape:
- `id` UUID PK. ✓
- `tenant_id`, `company_id`, `terminal_id` UUID (not-null per `$table->uuid()` default). ✓
- `reported_at` TIMESTAMPTZ. ✓
- `reported_by` UUID nullable (auto-detected incidents have no operator — documented L52–55). ✓
- `reason` TEXT (free-form). ✓
- `unsynced_count_at_incident` INTEGER. ✓
- `last_synced_event_at` TIMESTAMPTZ nullable (no prior sync = NULL). ✓
- `recovery_status` VARCHAR(32) DEFAULT `'reported'`. ✓
- `created_at` + `updated_at` TIMESTAMPTZ DEFAULT `now()`. ✓

PG-only DDL (driver-portable):
- CHECK `device_loss_incidents_recovery_status_allowed CHECK (recovery_status IN ('reported', 'recovering', 'resolved', 'unrecoverable'))`. ✓
- FKs all named: `device_loss_incidents_{tenant_id,company_id,terminal_id,reported_by}_fk`. Default `ON DELETE NO ACTION` per PG default — documented as intentional (the incident IS the forensic record-of-loss; cascade-delete would be wrong). ✓
- `device_loss_incidents_tenant_terminal_idx` on `(tenant_id, terminal_id)` — tenant-leading (matches the pos-stab / Treasury tenant-isolation convention). ✓
- `device_loss_incidents_open_status_idx` partial index on `(recovery_status, reported_at) WHERE recovery_status IN ('reported', 'recovering')` — mirrors the Task 9 partial-index pattern for the OutboxIngestor worker. ✓
- SQLite skips all four blocks deliberately so `:memory:` tests stay driver-portable. ✓
- `down()` drops the table. ✓

---

## 6. Tests review

### 6.1 TS — `OffDeviceDurabilityService.test.ts` (12 cases, 323 lines)

Test cases (verified by `grep -c "^  it("` → 12):

| # | Test | §12 deliverable covered |
|---|---|---|
| 1 | `exposes at least one off-device durability path with external key custody` | Path enumeration + keyCustody |
| 2 | `accepts every spec §12 path kind` | All 4 kinds (encrypted-removable-archive / lan-peer / nas / cloud-sync) |
| 3 | `throws EmptyOffDeviceDurabilityConfigError when paths is empty` | §12 on-device-only forbidden |
| 4 | `throws OnDeviceKeyCustodyForbiddenError if any path declares on-device custody` | Defense-in-depth runtime guard |
| 5 | `reports 'normal' risk when no unsynced events exist` | Risk baseline |
| 6 | `reports 'normal' risk when only synced events exist` | Risk filtering on sync_status |
| 7 | `escalates to 'elevated' at the forced-archive threshold (default 50)` | Forced-archive threshold |
| 8 | `escalates to 'escalated' at the maximum-unsynced threshold (default 500)` | Maximum-unsynced escalation |
| 9 | `escalates to 'elevated' on aged unsynced events even below the count threshold` | Age-based warn |
| 10 | `counts 'pending' / 'syncing' / 'failed' as unsynced; ignores 'synced'` | sync_status filter coverage |
| 11 | `returns false from shouldForceArchive when below the forced-archive threshold` | shouldForceArchive boundary (below) |
| 12 | `shouldForceArchive triggers at the exact threshold boundary (≥ count)` | shouldForceArchive boundary (at) |

All 12 pass against the real `SqliteTestAdapter` running migrations v1..v37 (so the real `fiscal_events` schema with CHECK + triggers + partial index). No mocks of SQLite — the test conventions standard. ✓

Verification: `pnpm vitest run src/lib/fiscal/__tests__/OffDeviceDurabilityService.test.ts` → **12 passed**.
Broader fiscal lib regression: `pnpm vitest run src/lib/fiscal/` → **161 passed (13 files)**.

### 6.2 PHP — `DeviceLossIncidentTest.php` (11 cases, 251 lines)

Test cases:

| # | Test | What it pins |
|---|---|---|
| 1 | `test_table_has_all_register_columns` | 12 column presence (portable) |
| 2 | `test_device_loss_incident_can_be_registered_with_defaults` | `create()` happy path + enum cast |
| 3 | `test_reported_by_is_nullable` | reported_by nullable |
| 4 | `test_last_synced_event_at_is_nullable` | pre-first-sync loss case |
| 5 | `test_recovery_status_must_be_valid_enum_value_on_postgres` | CHECK rejects invalid value (PG-only) |
| 6 | `test_recovery_status_check_allows_every_enum_value_on_postgres` | CHECK accepts every enum value (PG-only) |
| 7 | `test_recovery_status_check_constraint_is_named_per_convention_on_postgres` | CHECK named correctly (PG-only) |
| 8 | `test_tenant_terminal_index_exists_on_postgres` | Admin-browse index (PG-only) |
| 9 | `test_recovery_status_partial_index_exists_on_postgres` | OPEN-only partial index (PG-only) |
| 10 | `test_tenant_terminal_company_fks_exist_on_postgres` | 4 FKs named (PG-only) |
| 11 | `test_lifecycle_status_is_not_mass_assignable` | Boundary discipline (portable) |

`RefreshDatabase` + real `DeviceLossIncident::create()` + `DB::table()->insert()` for the raw-row variants — no mocks. ✓ Sibling pattern matches `FiscalEventQuarantineTableTest` exactly.

Verification: `./vendor/bin/phpunit tests/Feature/Fiscal/DeviceLossIncidentTest.php` → **11 passed, 6 skipped** (PG-only assertions skip on SQLite, as designed).
Broader Fiscal Feature regression: `./vendor/bin/phpunit tests/Feature/Fiscal/` → **271 passed, 43 skipped** (no regressions; same baseline as Tasks 29 / 30).

---

## 7. CI extension review

`.github/workflows/ci.yml` line 480: `DeviceLossIncidentTest` appended to the PG-merge-gate `--filter` regex (Tasks 10 / 11 / 19 / 21 / 22 / 29 / 30 standing pattern — extending in the same commit). ✓

Line 460–470: standing-pattern comment block extended with the Task 32 entry explaining the §12 device-loss register table's PG-only DDL contracts (CHECK, FKs, named indexes, partial index). This matches the existing comment-block discipline (Tasks 22 / 29 / 30 / 31 all extended with a short entry). ✓

---

## 8. Anti-pattern checklist

| Check | Status |
|---|---|
| No `app()` helper (rule 13) | ✓ Neither service nor model uses it. |
| No magic strings | ✓ `DeviceLossIncidentStatus` enum + `UnsyncedRiskLevel` type union. |
| Test seeders use real models | ✓ `DeviceLossIncident::create()` + `DB::table()->insert()` against `RefreshDatabase`; no mock factory. |
| No `git add -A` | ✓ Commit message lists explicit file paths only. |
| Constructor injection only | ✓ `OffDeviceDurabilityService` uses `private readonly`; `UnsyncedRiskIndicator` takes the service as a prop. |
| No `any` / no `mixed` | ✓ Service uses `unknown` + explicit guards. |
| Translation keys via `t()` (rule 11) | ✓ Component reads `t('unsyncedRisk.label.${level}')` + `t('unsyncedRisk.detail.${level}')`. |
| `$fillable` boundary discipline | ✓ `recovery_status` excluded. |

---

## 9. Standing pattern check (handoff §4.2)

| Pattern | Applied here? |
|---|---|
| Boot-time invariants asserted in constructor (Task 18) | ✓ `OffDeviceDurabilityService` throws on zero-path config and on `'on-device'` custody at construction. |
| `$fillable` boundary discipline (Task 9 / Task 10) | ✓ `recovery_status` not in `$fillable`; documented; tested. |
| CI PG-merge-gate same-commit (Tasks 10 / 11 / 19 / 21 / 22 / 29 / 30) | ✓ Filter + comment block extended. |
| Test scaffold uses `RefreshDatabase` + real Eloquent (Tests conventions) | ✓ |
| Partial index for hot path (Task 9) | ✓ `device_loss_incidents_open_status_idx` is partial — OPEN incidents only. |
| Named constraints (Task 9 / Task 10) | ✓ All FKs + CHECK + indexes have explicit names tested by `pg_constraint` / `pg_indexes` queries. |
| Tenant-leading composite index (pos-stab + Treasury) | ✓ `(tenant_id, terminal_id)`. |
| TS service against structural `SqlSurface` (Task 13 / 15+) | ✓ Service does not depend on Tauri concrete type. |
| Three-place i18n update (memory rule) | ✓ Import + resources + ns array all updated; en + fr both populated. |
| Tailwind-direct in apps/pos atom components (sibling convention) | ✓ Decision #2 — matches `ChainBreakAlert` / `TerminalNotReadyBanner`. |

All ten relevant standing patterns applied correctly.

---

## 10. Verification commands executed

```
git show --stat b09665a33                                                    # 11 files, +1298/-2
./vendor/bin/phpunit tests/Feature/Fiscal/DeviceLossIncidentTest.php          # 11 passed, 6 skipped (PG-only)
./vendor/bin/phpunit tests/Feature/Fiscal/                                    # 271 passed, 43 skipped (no regressions)
pnpm vitest run src/lib/fiscal/__tests__/OffDeviceDurabilityService.test.ts   # 12 passed
pnpm vitest run src/lib/fiscal/                                               # 161 passed (13 files)
pnpm typecheck                                                                # clean
pnpm lint <Task 32 files>                                                     # zero warnings on Task 32 paths (41 pre-existing warnings in other files)
./vendor/bin/phpstan analyse <Task 32 PHP files>                              # OK, no errors
./vendor/bin/pint --test <Task 32 PHP files>                                  # pass
bash apps/api/scripts/check-saleReceipt-chokepoints.sh                        # PASS — 9 call sites + 7 manifest receivers
```

All green.

---

## 11. Findings

### BLOCKER

None.

### P1

None.

### P2

None.

### P3

**P3-1 — `HasUuids` trait omission (NON-BLOCKING; sibling-canonical, but worth documenting).**
The brief's "model has `HasUuids`" instruction is not what the canonical Fiscal sibling pattern uses — `FiscalEvent`, `FiscalEventQuarantine`, and `FiscalEventProjectionRow` all rely on the explicit `$keyType = 'string'` + `$incrementing = false` + caller-supplied `Str::uuid()->toString()` pattern, without the trait. The implementer correctly mirrored the sibling pattern over the brief's instruction. No action required; this is a brief-vs-codebase reconciliation note for the next subagent. If a future cluster standardizes on `HasUuids`, all four Fiscal models should be migrated together — not just `DeviceLossIncident`.

**P3-2 — Mixed-config behaviour is implementation-checked but untested.**
The `OnDeviceKeyCustodyForbiddenError` test (L205–227) covers a single-bad-path config. A mixed config (one valid + one invalid) is rejected by the same loop, but no test pins that — the implementation iterates all paths and throws on the first bad one. A defensive test asserting "rejection happens even when at least one valid path exists" would document the invariant explicitly. Not a blocker because the loop is straightforward and the single-bad-path case proves the rejection mechanism works.

### NOTE

**N-1 — Phase 2 expansion surface.** The service docblock L30–35 enumerates what Pass 1 does NOT own: actual transfer mechanics (LAN-peer push, NAS mount, encrypted-USB write, cloud-sync upload). This is the right Phase-1 boundary — §12 mandates the *control surface*, not the *transport*. The next subagent on the §12-Phase-2 transfer-mechanics work should consume `OffDeviceDurabilityService.availablePaths()` as the configured-destinations source. The operator workflow that gates new authoring on `shouldForceArchive()` is also Pass-2 surface — the policy bit exists; the workflow wiring is deferred. Documented adequately in the docblock.

---

## 12. Summary

Task 32 is the cleanest single-round outcome since Task 9. All seven spec §12 deliverables are present, all standing patterns are applied correctly, the implementer's four documented decisions are all defensible and align with established codebase conventions, and every verification command passes green on first attempt. The CI PG-merge-gate is extended in the same commit per the standing pattern. The §14.3 chokepoint gate remains green (9 reconciled / 7 receivers). No regressions in the broader Fiscal lib (161/161 TS, 271/271 PHP).

Two P3 observations and one Phase-2 boundary NOTE. No BLOCKERs, no P1s, no P2s — the implementation is ready for the Task 33 full-flow verification + roadmap-status update.

VERDICT: APPROVE
