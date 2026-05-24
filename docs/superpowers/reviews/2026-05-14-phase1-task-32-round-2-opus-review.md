# Phase 1 — Task 32 Round-2 Opus Re-Review

**Reviewer:** Opus 4.7 (1M context) — round-2 re-reviewer
**Scope:** Task 32 round-2 — close 2 BLOCKER + 1 P1 + 2 P2 from Codex round-1 review
**Round-2 commit:** `3995575e0` (21 files; +1871/−113)
**Round-1 commit:** `b09665a33`
**Branch:** `feat/pos-fiscal-event-engine-phase1`
**Authority:** spec v7 §12 (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md:566-568`), SoT v3 §1/D1/D8, plan Task 32 (`docs/superpowers/plans/2026-05-14-pos-phase1-fiscal-event-engine.md:2676-2734`).
**Round-1 reviews consulted:** Codex (REJECT — 2 BLOCKER + 1 P1 + 2 P2) and Opus (APPROVE; the wiring BLOCKER B2 was missed by round-1 Opus).

---

## 1. Verdict summary

Round-2 closes all 5 round-1 findings with the right shape. The implementer applied the canonical sibling pattern in every case:

- **T32-B1 (FK orphans + invalid-enum sloppy assertion)** — `setUp()` seeds real `Tenant → Company → Location → Terminal → User` rows via factories; raw-row helper now defaults `reported_by` to NULL and uses seeded UUIDs for the three required FKs; the invalid-enum test asserts SQLSTATE `23514` AND constraint name `device_loss_incidents_recovery_status_allowed`.
- **T32-B2 (dead control surface)** — refactored from "service-as-prop + internal polling" to the canonical Zustand-store + polling-hook pattern (matches `ChainBreakAlert` ↔ `useSyncStore`). New `durabilityStore`, `useFiscalDurabilityPolling`, `DurabilityGateModal`, and `buildOffDeviceDurabilityService` factory; the indicator + the gate are mounted unconditionally in `AppShell` and read from the store, so the conservation surface is now reachable end-to-end.
- **T32-P1 (cross-tenant terminal_id forgery surface)** — new migration `2026_05_20_100002_add_composite_fk_to_device_loss_incidents.php` adds `UNIQUE pos_terminals(id, tenant_id, company_id)`, drops the simple `terminal_id_fk`, and adds composite `device_loss_incidents_terminal_scope_fk` on `(terminal_id, tenant_id, company_id) → pos_terminals(id, tenant_id, company_id)`. New PG-only test asserts SQLSTATE `23503` + constraint name on cross-tenant insert.
- **T32-P2 (required thresholds without defaults/validation)** — three threshold knobs made optional; `normalizeThreshold()` applies the documented defaults (50 / 500 / 3600); validates positive finite integers and `escalated >= forced`; new `InvalidDurabilityThresholdError`; 4 new tests pin defaults / negative / non-finite / inverted-pair rejection.
- **T32-P3 (Vitest skipped on CI Node 20)** — `SqliteTestAdapter` migrated `node:sqlite → better-sqlite3`; `describe.skip` guard removed; entire fiscal-lib + db suite (342 tests / 36 files) now runs unconditionally; package.json adds `better-sqlite3` + `@types/better-sqlite3` as devDependencies.

Verification commands all pass green (see §7). The most-recent-pattern Task 23/24/25 r2-introduces-new-defects risk is the right framing — round-2 here introduces 5 new TS files + a non-trivial migration + a critical adapter swap. I scanned each new surface for the defect classes the brief listed; the findings are limited to P3 polish (a11y focus trap, grace-expiry up-to-30s lag, factory placeholder phase-2 boundary) plus one NOTE on cross-app lock-in of the `pos_terminals(id, tenant_id, company_id)` UNIQUE.

**Findings:** 0 BLOCKER · 0 P1 · 0 P2 · 4 P3 · 1 NOTE
**Verdict:** APPROVE

---

## 2. T32-B1 closure — DeviceLossIncidentTest FK orphans + SQLSTATE assertion

### 2.1 setUp() seeds real referenced rows

`DeviceLossIncidentTest.php:66-90` builds the FK referent graph via real factories:

```php
$tenant = Tenant::factory()->create();
$company = Company::factory()->create(['tenant_id' => $this->tenantId]);
$location = Location::factory()->create(['company_id' => $this->companyId]);
$terminal = Terminal::factory()->create([
    'tenant_id' => $this->tenantId,
    'company_id' => $this->companyId,
    'location_id' => $location->id,
]);
$user = User::factory()->create(['tenant_id' => $this->tenantId, ...]);
```

Each `RefreshDatabase` test gets a fresh referent graph. The 4 FKs on `device_loss_incidents` (`tenant_id`, `company_id`, `terminal_id`, `reported_by`) all have real referent rows when the `incidentRawRow()` insert fires. ✓

### 2.2 `incidentRawRow()` defaults `reported_by` to NULL

Line 392: `'reported_by' => null,` — the safer default for raw-row helpers because most PG-only tests don't care about the operator identity. The model layer's `incidentAttributes()` (line 363) still uses `$this->operatorId` for the create-path tests. ✓

### 2.3 Invalid-enum test asserts SQLSTATE 23514 + constraint name

`test_recovery_status_must_be_valid_enum_value_on_postgres()` (lines 143-167):

```php
$this->assertSame('23514', $e->getCode(), ...);
$this->assertStringContainsString(
    'device_loss_incidents_recovery_status_allowed',
    $e->getMessage(),
    ...
);
```

This is exactly what Codex demanded — the QueryException's SQLSTATE proves a CHECK violation (not an FK orphan), and the constraint name pins the specific check we care about. ✓

### 2.4 SQLite vs PG portability preserved

The 8 PG-only tests skip cleanly on SQLite (`skipUnlessPostgres()` at line 402). On the local SQLite run the test suite reports `13 / 13` (5 portable + 8 skipped). On the PG-merge-gate the 8 PG assertions run including the new T32-P1 cross-tenant test (covered in §4). ✓

**Verdict on T32-B1:** Closed correctly.

---

## 3. T32-B2 closure — operator-visible indicator + forced-archive gate mounted

This is the biggest round-2 change and the highest risk for new defects. I read the entire wiring chain end-to-end.

### 3.1 AppShell.tsx — single wiring site

Lines 41-62:

```tsx
const [durabilityDb, setDurabilityDb] = useState<Database | null>(null);
useEffect(() => {
  let cancelled = false;
  if (!companyId) return;
  void getDatabase(companyId).then((db) => {
    if (!cancelled) setDurabilityDb(db);
  });
  return () => { cancelled = true; };
}, [companyId]);
const durabilityService = useMemo(
  () => buildOffDeviceDurabilityService(durabilityDb),
  [durabilityDb],
);
useFiscalDurabilityPolling(durabilityService);
```

- `companyId` reactive — the effect re-runs when the operator switches companies. ✓
- `cancelled` flag prevents post-unmount/post-companyId-change state writes. ✓
- `buildOffDeviceDurabilityService(null)` returns `null`; polling hook treats `null` as no-op. ✓
- `useMemo` memoizes the service so the polling hook's `[service]` dep stays stable across unrelated re-renders. ✓

The indicator mounts at line 135 (between `C2MigrationBanner` and `<main>`, inside a `<div className="px-4 pt-2">` wrapper); the modal mounts at line 153 (sibling of `<main>` so it's a sibling of the routed content, allowing the modal's `fixed inset-0 z-50` overlay to cover the entire viewport). ✓

### 3.2 useFiscalDurabilityPolling.ts — the data flow

- `service === null` → effect early-returns; hook is a no-op until the DB is ready. ✓ (tested at `useFiscalDurabilityPolling.test.tsx:43-47`)
- First tick runs eagerly on mount; subsequent ticks at `intervalMs = 30_000` default (test override `intervalMs: 0` for unit tests). ✓
- `Promise.all([unsyncedRisk(), shouldForceArchive()])` — parallel reads; both queries hit the partial sync-pending index. ✓
- Errors are caught and pushed via `setPollError`, which does NOT clobber the last-good `riskLevel` (`durabilityStore.ts:85-87`) — the indicator stays visible during a transient SQL hiccup. ✓ (tested at `useFiscalDurabilityPolling.test.tsx:65-90`)
- Cleanup: both branches (`intervalMs > 0` and `intervalMs === 0`) return a cancellation function that sets `cancelled = true` and clears the interval handle. ✓ React-strict-mode-safe because the cleanup runs on every effect re-execution.
- Selector references (`setPollResult`, `setPollError`) are stable because Zustand's create-time action functions are created once. The `[service, intervalMs, setPollResult, setPollError]` dep array doesn't churn. ✓

### 3.3 durabilityStore.ts — atomic state

- `setPollResult` writes `{ riskLevel, forceArchiveRequired, lastPollError: null }` in a single `set(...)` — atomic from the React-rendering perspective. ✓
- No persistence layer (no `zustand/middleware`'s `persist`). Sensitive data (sync_status counts, force-archive flag) is in-memory only — correct, given the source of truth is the SQLite `fiscal_events` table. ✓
- `reset()` and `resetAcknowledgment()` are test-only helpers — clearly labeled. ✓
- `isDurabilityGateArmed` selector: `forceArchiveRequired && (acknowledgedUntil === null || Date.now() >= acknowledgedUntil)`. Semantically correct: gate is armed iff forced-archive is required AND no live grace covers the current moment. ✓
- `DURABILITY_ACKNOWLEDGMENT_GRACE_MS = 60 * 60 * 1000` (1 hour) — matches the §12 age-warn default. The gate stays a real conservation control because the grace expires; the polling hook re-arms it on the next tick when the unsynced backlog persists. ✓

### 3.4 DurabilityGateModal.tsx — blocking gate

- `role="dialog" aria-modal="true" aria-labelledby="durability-gate-title"` — ARIA dialog semantics declared. ✓
- `fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4` — full-viewport overlay; `z-50` puts it above any normal-flow POS content. ✓ (`Header`, `TrainingModeBanner`, `C2MigrationBanner` use lower z-indices.)
- `data-testid="durability-gate-modal"` + `data-testid="durability-gate-acknowledge"` — E2E test hooks. ✓
- `onClick={acknowledgeDurabilityGate}` writes `acknowledgedUntil = Date.now() + DURABILITY_ACKNOWLEDGMENT_GRACE_MS` — the gate dismisses for 1 hour. ✓ (tested at `DurabilityGateModal.test.tsx:75-93`)
- Re-arm behavior: when `acknowledgedUntil < Date.now()`, the selector returns `true` again and the modal re-renders. The test at lines 95-105 pins this with a pre-expired grace. ✓
- The "phase 2 note" copy is set so the operator understands they're acknowledging an action that doesn't actually transfer the archive yet. ✓

### 3.5 UnsyncedRiskIndicator.tsx — refactored to store-driven

- Reads `level = useDurabilityStore((s) => s.riskLevel)`. ✓
- Returns `null` pre-first-poll (`level === null`). ✓ (tested at `UnsyncedRiskIndicator.test.tsx:50-53`)
- Three Tailwind color classes for `normal / elevated / escalated` (green / amber / red). ✓
- `data-risk-level={level}` attribute for E2E test hooks. ✓
- The "service-as-prop + internal polling" round-1 design is removed (line 47); the component now mounts trivially without threading the service through every surface. The Codex finding was specifically that the round-1 design left the component dead — round-2's store-driven refactor fixes it. ✓

### 3.6 buildOffDeviceDurabilityService factory

`durabilityServiceFactory.ts:68-83`:

- Returns `null` when `database === null` (the "DB not ready yet" path). ✓
- Builds with a placeholder lan-peer path (kind: `lan-peer`, peerUrl: `http://durability-peer.invalid:7443`, keyCustody: `shared-secret-rotated`). The `.invalid` TLD is intentionally non-resolving — Phase 1 doesn't attempt transfer, so the placeholder is the stub-able seam. ✓ (docblock at lines 1-23 documents this Phase 2 expansion boundary.)
- Threshold knobs are ALL optional — service constructor applies the documented defaults. ✓
- Factory itself never throws; only the service constructor throws on invalid config. ✓

### 3.7 New-defect scan results

I worked through the brief's defect classes for this surface:

| Defect class | Finding |
|---|---|
| Polls indefinitely without cleanup | Cleanup correctly clears the interval handle + sets `cancelled`. ✓ |
| Error spiral on service throw | Errors caught; store records `lastPollError`; polling continues; last-good `riskLevel` preserved. ✓ |
| React strict-mode double-mount safety | Effect cleanup runs on the first mount's unmount; second mount allocates fresh `cancelled` + `setInterval`. Safe. ✓ |
| Zustand store atomic mutations | `set(...)` calls update multiple fields in one shot — atomic. ✓ |
| Persistence leaks sensitive data | No `persist` middleware used — store is in-memory only. ✓ |
| Modal focus trap / a11y | `role="dialog" aria-modal="true"` declared but no JS focus trap. See P3-A11Y. |
| Modal Escape-to-dismiss | No keydown handler; cannot dismiss without clicking acknowledge. Defensible — it's a gate. |
| Acknowledge action is meaningful | Records `acknowledgedUntil` and gates re-arm. Phase 1 stub action; Phase 2 wires the actual transfer. ✓ |
| Factory module-scope singleton | Not a singleton — service is built on demand via `useMemo`. Different from Task 30 `instance.ts` pattern; correct here because the service depends on a per-company DB handle. ✓ |
| Factory config source documented | docblock + commit body call out the Phase 2 config-loader plan. ✓ |
| AppShell layout shifts | Indicator wrapper is `<div className="px-4 pt-2">` so it occupies a small fixed-padding block when present; renders null pre-first-poll so no shift on first paint. Modal is overlay (out of normal flow). ✓ |
| Indicator without active terminal | The indicator is keyed on `durabilityStore.riskLevel`, not on a terminal. Renders only after the polling hook (which only runs when `companyId` is set) lands a first result. With no terminal set, `companyId` is still set; the indicator renders the company-DB-derived risk level. Defensible. |
| AppShell test coverage | Implicit via component-level tests (4 indicator + 4 modal + 3 hook). The AppShell mount-points are pure JSX — no logic to unit-test there. ✓ |

**Verdict on T32-B2:** Closed correctly. The store-driven pattern matches `ChainBreakAlert` ↔ `useSyncStore`, the polling hook handles all the error/cleanup concerns, the gate is real (it re-arms after grace expires), and the wiring is end-to-end reachable.

---

## 4. T32-P1 closure — composite FK rejects cross-tenant terminal_id

### 4.1 Migration `2026_05_20_100002_add_composite_fk_to_device_loss_incidents.php`

Read in full (105 lines). Three-step migration:

1. `ALTER TABLE pos_terminals ADD CONSTRAINT pos_terminals_id_tenant_company_unique UNIQUE (id, tenant_id, company_id)` — required by PG for the composite FK referent (a composite FK's referent columns must carry a UNIQUE constraint, and PG won't accept the PK alone as the referent for a 3-column FK). ✓
2. `ALTER TABLE device_loss_incidents DROP CONSTRAINT device_loss_incidents_terminal_id_fk` — the simple FK is superseded by the composite FK. ✓
3. `ALTER TABLE device_loss_incidents ADD CONSTRAINT device_loss_incidents_terminal_scope_fk FOREIGN KEY (terminal_id, tenant_id, company_id) REFERENCES pos_terminals (id, tenant_id, company_id)` — the composite FK rejects any incident whose `(tenant_id, company_id)` pair doesn't match the referenced terminal's. ✓

Migration is SQLite-skipped at line 48 (driver check). SQLite tests don't enforce FKs anyway, and the PG merge-gate runs the cross-tenant test against real PG. ✓

`down()` is symmetric — drops the composite FK, restores the simple FK, drops the UNIQUE. Reversible. ✓

### 4.2 Cross-tenant rejection test

`test_cross_tenant_terminal_id_is_rejected_by_composite_fk_on_postgres()` (lines 254-297):

- Seeds a second tenant+company+terminal triple (`$otherTenant`, `$otherCompany`, `$otherTerminal`). ✓
- Inserts an incident with `$this->tenantId / $this->companyId` (tenant A) but `terminal_id = $otherTerminal->id` (from tenant B). ✓
- Asserts SQLSTATE `23503` (FK violation) + constraint name `device_loss_incidents_terminal_scope_fk` in the error message. ✓

### 4.3 Composite FK existence test

`test_composite_terminal_scope_fk_exists_on_postgres()` (lines 299-326):

- Asserts `device_loss_incidents_terminal_scope_fk` exists in `pg_constraint`. ✓
- Asserts `device_loss_incidents_terminal_id_fk` is dropped (proves the round-1 simple FK is gone). ✓

### 4.4 Updated FK-presence test

`test_tenant_terminal_company_fks_exist_on_postgres()` (lines 232-252) now checks only the three remaining simple FKs (`tenant_id_fk`, `company_id_fk`, `reported_by_fk`); the `terminal_id_fk` row has been removed with the commentary explaining why. ✓

**Verdict on T32-P1:** Closed correctly. The composite FK is the canonical PG pattern for tenant-scoped cross-table integrity; the new UNIQUE on `pos_terminals(id, tenant_id, company_id)` is the standard prerequisite.

---

## 5. T32-P2 closure — optional thresholds + defaults + validation

### 5.1 Config interface

`OffDeviceDurabilityService.ts:137-144`:

```ts
export interface OffDeviceDurabilityConfig {
  paths: OffDeviceDurabilityPath[];
  forcedArchiveUnsyncedThreshold?: number;
  escalatedUnsyncedThreshold?: number;
  unsyncedAgeWarnThresholdSeconds?: number;
  sqlSurface: Database | SqlSurface;
}
```

All three thresholds are optional. ✓

### 5.2 Defaults documented

Lines 150-152:

```ts
export const DEFAULT_FORCED_ARCHIVE_UNSYNCED_THRESHOLD = 50;
export const DEFAULT_ESCALATED_UNSYNCED_THRESHOLD = 500;
export const DEFAULT_UNSYNCED_AGE_WARN_THRESHOLD_SECONDS = 3600;
```

Exported so callers + tests can reference them without duplicating the values. ✓

### 5.3 `normalizeThreshold()` helper

Lines 234-258:

- `undefined` → fallback.
- non-number / non-finite (NaN, ±Infinity) → throw `InvalidDurabilityThresholdError`.
- non-integer → throw.
- `<= 0` → throw.

The four guards cover the JSON-erasure attack surface the round-1 finding called out. ✓

### 5.4 `escalated >= forced` invariant

Lines 305-310: throws `InvalidDurabilityThresholdError` if the ordering is violated. ✓ (tested at line 378-391 of the test file.)

### 5.5 `InvalidDurabilityThresholdError` typed error

Lines 205-215. Named per convention; carries the offending field + reason in the message. ✓

### 5.6 New tests (4 cases)

- `applies the documented defaults when threshold knobs are omitted from config` — pins 49 unsynced → normal/false, 50 → elevated/true (default forced=50). ✓
- `rejects a negative threshold at construction` — `-1` → throw. ✓
- `rejects a non-finite threshold at construction` — `NaN` and `+Infinity` both → throw. ✓
- `rejects escalatedUnsyncedThreshold < forcedArchiveUnsyncedThreshold at construction` — inverted-pair → throw. ✓

**Verdict on T32-P2:** Closed correctly. The defense-in-depth at the boundary matches the Task 18 boot-time-invariant standing pattern.

---

## 6. T32-P3 closure — Vitest runs unconditionally on CI Node 20

### 6.1 SqliteTestAdapter migrated `node:sqlite → better-sqlite3`

`sqliteTestAdapter.ts`:

- Imports `better-sqlite3` (line 23) + its types (line 24). ✓
- Constructor uses `new BetterSqlite3(':memory:')`. ✓
- `bindParams()` (lines 38-47) — `better-sqlite3`'s `$1` named-param bind drops the leading `$`, so the helper maps to `{ '1': v1, '2': v2, … }` (vs `node:sqlite`'s `{ '$1': v1 }`). The comment at line 43-46 calls out the difference. ✓
- `execute()` correctly routes multi-statement migration SQL to `inner.exec()` and single-statement to `prepare(...).run()` so callers get accurate `rowsAffected`. ✓
- `select()` rejects non-SELECT statements (defense). ✓
- The 342-test fiscal-lib + db regression passes against the new adapter (run locally — see §7). ✓

### 6.2 describe.skip guard removed

`OffDeviceDurabilityService.test.ts:36-41`:

```ts
// Round-2 T32-P3: the SqliteTestAdapter is backed by `better-sqlite3` now,
// which works on Node 20 (the CI version). The previous `node:sqlite` skip
// guard caused the entire suite to silently skip on CI — replaced by an
// unconditional `describe`. If `better-sqlite3` is somehow missing the
// import itself will throw at module load and the failure is loud.
const d = describe;
```

The `d = describe` indirection looks like dead code from the round-1 conditional aliasing but is functionally equivalent to `describe`. Not a blocker but worth cleaning up in a future pass — see P3-CLEAN.

### 6.3 package.json devDependencies

`apps/pos/package.json:52, 58`:

```json
"@types/better-sqlite3": "^7.6.13",
"better-sqlite3": "^12.10.0",
```

Both added. Module loads at vitest startup; if missing it would throw on import. ✓

### 6.4 Test count confirmation

Round-2 commit body claims `342 vitest tests / 36 files` for `src/lib/fiscal + src/lib/db`. Local run confirms this exactly (see §7.3). ✓

**Verdict on T32-P3:** Closed correctly. The adapter swap is API-compatible (synchronous prepare/run/all), the multi-statement path is preserved, and the broader regression is green.

---

## 7. Verification commands executed

### 7.1 PHPUnit DeviceLossIncidentTest (SQLite local)

```
./vendor/bin/phpunit tests/Feature/Fiscal/DeviceLossIncidentTest.php
```

Result: `Tests: 13, Assertions: 19, Skipped: 8.` All portable assertions green; 8 PG-only assertions skip on SQLite as designed. The 8 skipped are: 5 round-1 PG-only + 2 new round-2 PG-only (`test_composite_terminal_scope_fk_exists_on_postgres` + `test_cross_tenant_terminal_id_is_rejected_by_composite_fk_on_postgres`) + 1 (the round-1 invalid-enum test now also PG-only because the SQLSTATE assertion only matches on PG). The PG merge-gate runs these against real PG. ✓

### 7.2 PHPUnit Fiscal feature regression (SQLite local)

```
./vendor/bin/phpunit tests/Feature/Fiscal/
```

Result: `Tests: 273, Assertions: 950, Skipped: 45.` No regressions vs round-1's 271/271 baseline — the +2 comes from the new round-2 composite-FK tests. ✓

### 7.3 Vitest Task 32 round-2 surfaces

```
pnpm vitest run \
  src/lib/fiscal/__tests__/OffDeviceDurabilityService.test.ts \
  src/components/fiscal/__tests__/UnsyncedRiskIndicator.test.tsx \
  src/components/fiscal/__tests__/DurabilityGateModal.test.tsx \
  src/hooks/__tests__/useFiscalDurabilityPolling.test.tsx
```

Result: `Test Files 4 passed (4) · Tests 27 passed (27)` — 16 service + 4 indicator + 4 modal + 3 hook. ✓

### 7.4 Vitest broader fiscal-lib + db regression

```
pnpm vitest run src/lib/fiscal src/lib/db
```

Result: `Test Files 36 passed (36) · Tests 342 passed (342)` — exactly the count the round-2 commit body claims. The better-sqlite3 swap broke nothing. ✓

### 7.5 POS typecheck

```
pnpm typecheck
```

Result: clean (no output → exit 0). ✓

### 7.6 PHPStan + Pint on round-2 PHP files

```
./vendor/bin/phpstan analyse \
  database/migrations/2026_05_20_100002_add_composite_fk_to_device_loss_incidents.php \
  tests/Feature/Fiscal/DeviceLossIncidentTest.php

./vendor/bin/pint --test database/migrations/2026_05_20_100002_*.php tests/Feature/Fiscal/DeviceLossIncidentTest.php
```

Result: `[OK] No errors` (PHPStan); `{"result":"pass"}` (Pint). ✓

### 7.7 §14.3 chokepoint gate (unchanged from round-1)

```
bash apps/api/scripts/check-saleReceipt-chokepoints.sh
```

Result: `manifest receiver_type validator: PASS — 7 entry/entries validated` + `§14.3 chokepoint gate: PASS — 9 call site(s) reconciled` ✓

---

## 8. New-defect scan summary

The brief flagged the Task 23/24/25 round-2 pattern (round-2 introduces new architecture; that new architecture has its own defects). I worked through each new surface deliberately:

| Surface | New defects found |
|---|---|
| `durabilityStore.ts` | None — store is atomic, in-memory only, action functions stable, `isDurabilityGateArmed` semantics correct. |
| `useFiscalDurabilityPolling.ts` | None — cleanup is correct, errors don't clobber `riskLevel`, deps are stable, strict-mode safe. |
| `DurabilityGateModal.tsx` | P3-A11Y (no JS focus trap; aria-modal alone doesn't enforce focus). |
| `UnsyncedRiskIndicator.tsx` | None — clean store-driven refactor. |
| `durabilityServiceFactory.ts` | NOTE-F (placeholder LAN-peer path is intentional Phase 2 expansion seam). |
| `AppShell.tsx` | None — companyId-keyed effect with `cancelled` flag is standard React pattern; the small race between old-DB-close and new-service-build is harmless because polling tolerates SQL errors. |
| `sqliteTestAdapter.ts` (swap) | P3-CLEAN (`const d = describe` indirection now redundant). |
| Migration `2026_05_20_100002` | NOTE-U (the new `pos_terminals_id_tenant_company_unique` UNIQUE will be shared by any future cross-table tenant-scoped FK against `pos_terminals` — a downstream cluster benefit, not a defect). |
| `DeviceLossIncidentTest.php` | None — factory seeding + SQLSTATE assertions are canonical sibling pattern. |
| `OffDeviceDurabilityService.ts` (threshold validation) | None — `normalizeThreshold` is defensive and covers the JSON-erasure attack surface. |

---

## 9. Findings

### BLOCKER

None.

### P1

None.

### P2

None.

### P3

**P3-A11Y — `DurabilityGateModal` declares `aria-modal="true"` but does not implement a JS focus trap.**

`DurabilityGateModal.tsx:40-73`: the modal declares `role="dialog" aria-modal="true" aria-labelledby="durability-gate-title"`, but no focus-trapping behavior is wired. A keyboard user pressing `Tab` after the acknowledge button will move focus to whatever follows the modal in the DOM tree (currently nothing because the modal is rendered last, but as soon as another floating UI element is added, focus could escape). There's also no Escape-key handler — that's a deliberate design choice (the gate must be acknowledged, not dismissed), but it should be documented. Recommend adding either a small focus-trap helper (e.g. `react-aria-focusscope` or a 5-line `useFocusTrap` hook that intercepts Tab + Shift+Tab cycle inside the modal) and an explicit `onKeyDown` that swallows Escape. Phase 1 is functional without this — the gate IS reachable and blocks pointer-clicks via the `fixed inset-0 z-50 bg-black/60` overlay — but the a11y contract is incomplete.

**P3-GRACE-LAG — Up-to-30-second delay before the gate re-appears after acknowledgment grace expires.**

`isDurabilityGateArmed` is a pure selector that reads `Date.now()`. Zustand only re-runs selectors when state mutates. After the operator acknowledges (records `acknowledgedUntil = now + 1h`), the modal hides. When the 1-hour grace silently expires, no store mutation fires — the modal stays hidden until the next polling tick (default 30s) calls `setPollResult(...)` and triggers a re-render. Worst-case lag: 30s after the 1-hour grace.

For a 1-hour grace window this is operationally fine. If the polling interval were ever raised much higher, the lag could become noticeable. Recommend documenting the polling-interval coupling in the store docblock, or (cleaner) wire an explicit `setTimeout` in the `acknowledgeDurabilityGate` action that fires a `set({})` no-op at the grace expiry moment to force re-render. Not a blocker because the 30s polling tick is short enough to be invisible to operators.

**P3-CLEAN — `const d = describe` indirection now redundant.**

`OffDeviceDurabilityService.test.ts:41`: `const d = describe;` was a placeholder for round-1's conditional `describe.skip` aliasing. With round-2 removing the skip guard, the alias is dead — the file should just use `describe(...)` directly at line 151. Cosmetic. No behavioral impact.

**P3-FACTORY-PLACEHOLDER — Phase 1 placeholder path is hardcoded; no operator settings UI yet.**

`durabilityServiceFactory.ts:40-46`: the factory ships with a single LAN-peer path with `peerUrl: 'http://durability-peer.invalid:7443'` and `keyCustody: 'shared-secret-rotated'`. Phase 1 doesn't attempt transfer so the URL is intentionally non-resolving, but the operator UI surface (settings page where the operator picks their off-device destination) is still absent. The docblock acknowledges this Phase 2 work explicitly. The Phase 1 gate per spec §12 is the *control surface* not the *transport*, so this is correct — but the next sub-agent on §12-Phase-2 transfer mechanics will need to replace this factory with a config-loader. Not a defect for Task 32.

### NOTE

**N-1 — `pos_terminals_id_tenant_company_unique` is a cross-app-shared UNIQUE.**

The new migration adds `UNIQUE pos_terminals(id, tenant_id, company_id)` on a table owned by the POS module, not Fiscal. Any future cross-table composite FK against `pos_terminals` (cash drawer events, terminal-scoped audit rows, etc.) can reuse this UNIQUE without re-adding it. Conversely, removing the UNIQUE would require dropping every dependent composite FK first. This is a Treasury/pos-stab-shared piece of schema scaffolding — worth flagging in the handoff §4 so the next architectural cluster knows it exists. No code change required.

---

## 10. Verdict reasoning

Round-2 closes all 5 round-1 Codex findings (2 BLOCKER + 1 P1 + 2 P2) with the canonical sibling pattern in every case. The biggest change — the T32-B2 dead-control-surface fix — refactors from "service-as-prop + internal polling" to "Zustand store + polling hook + factory + AppShell single wire-up", which matches the `ChainBreakAlert` ↔ `useSyncStore` pattern already in the codebase. The factory-build-from-DB pattern is correct (per-company DB handles can't be module-scope singletons). The migration is reversible and the cross-tenant test asserts the specific SQLSTATE + constraint name Codex demanded. The better-sqlite3 swap is API-compatible and the broader 342-test regression confirms it.

I deliberately scanned for the Task 23/24/25 r2 "new architecture brings new defects" pattern. The only new findings are P3 polish (focus trap, grace-expiry lag, dead-code alias, phase-2 boundary) — nothing that gates merge.

**Findings:** 0 BLOCKER · 0 P1 · 0 P2 · 4 P3 · 1 NOTE

VERDICT: APPROVE
