# POS Cash Rounding + Tolerance — Device Phase 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the IziPOS device Swedish-round the cash-due total to the country denomination inside the signed SALE_RECEIPT v3 bytes, auto-accept in-tolerance cash shortfalls without a PIN, and surface both on the receipt, EOD preview and local Z — fully offline, fail-closed.

**Architecture:** A server-resolved payment policy (`GET /api/v1/pos/payment-policy`, authored by Plan A) is pulled into a new SQLite `payment_policy_cache` table and exposed synchronously through a `paymentPolicy` Zustand slice. At tender time a single `CheckoutPolicySnapshot` is built from pure helpers in `lib/payment/cashRounding.ts` (rounding, cash-only union predicate, tolerance effective-max); that snapshot — not live state — drives the UI, the enforcement gates, the v3 canonical payload, and the `offline_receipts` mirror columns. The SALE_RECEIPT payload moves to `event_version = 3` by delegating to the byte-frozen V2 builder with the EXACT total, replacing `total` with the rounded total, and appending two signed sibling fields.

**Tech Stack:** React 19 + TypeScript strict, Zustand 5, Tauri 2 + `@tauri-apps/plugin-sql` (SQLite), big.js (`src/lib/decimal.ts`), Vitest, react-i18next (en + fr), Rust ESC/POS formatter (`src-tauri/src/printing/receipt_template.rs`).

## Global Constraints

- All money is big.js decimal STRINGS via `src/lib/decimal.ts` (`bcadd`/`bcsub`/`bcmul`/`bcdiv`/`bccomp`/`bcabs`/`bcformat`/`bcsum`) — NEVER `parseFloat`/`Number(...)` on a monetary or quantity value.
- Tests run BY PATH only: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run <path>`. Never run the whole suite.
- SQLite money columns are TEXT; any JS-supplied timestamp compared against a `datetime('now')` column MUST pass through `toSqliteUtc()` (`src/lib/db/sqliteTime.ts`) — rule 20.
- The V1 (`SaleReceiptPayload.ts`) and V2 (`SaleReceiptV2Payload.ts`) builders are BYTE-IMMUTABLE and snapshot-pinned; V3 wraps V2, it never edits it.
- The rounding gate is FAIL-CLOSED: missing/stale policy, `cash_rounding.enabled = false`, an invalid denomination, a non-cash-only tender, OR `terminal.fiscal_schema_version !== 3` ⇒ exact today-behavior (`total = exact_total`, canonical-zero adjustment, canonical-zero denomination).
- Every user-facing string goes through `t()` with matching `en` + `fr` keys in `src/locales/{en,fr}/pos.json`.
- The manager-PIN tender-tolerance override path (`paymentStore.ts:1124-1191`) stays BYTE-IDENTICAL — auto-accept is a NEW branch in front of it, never a replacement.
- No scope creep beyond the spec: no `apps/api` changes, no refund/void authoring, no Z_REPORT payload version bump, no per-company rounding UI.
- **Depends on Plan A (server Phase 1) being deployed first** — the device authors `event_version = 3` SALE_RECEIPTs, which a pre-Phase-1 server quarantines. `SALE_RECEIPT_PAYLOAD_KEYS_V3` in the PHP validator is a hard precondition for Task 8's drift gate.

**Spec of record:** `docs/superpowers/specs/2026-07-27-pos-cash-rounding-tolerance-design.md` (Rev 2.2, APPROVED FOR PLANNING). Sections in scope: §4.1 (device semantics), §4.3 (device, in full), §4.4 (device half), §6 (device tests), §7 (Phase-2 checklist).

**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.cash-rounding`, branch `feat/pos-cash-rounding`. All paths below are relative to `apps/pos/` unless absolute.

---

### Task 1: SQLite migration v63 — policy cache, receipt columns, `is_cash_tender`

**Files:**
- `apps/pos/src/lib/db/migrations.ts` — append after the v62 entry (`:1913-1923`); the array literal closes at `:1924`. `Migration` interface at `:1-6`; `isDuplicateColumnError` at `:8-15`.
- `apps/pos/src/lib/db/__tests__/migrations.v63.test.ts` (NEW) — mirror `migrations.v62.test.ts:1-77`.
- Reference for helpers: `apps/pos/src/lib/db/__tests__/helpers/migrationTestHelpers.ts:16-49` (`applyAllMigrations`, `runMigrationsUpTo`, `runMigrationVersion`).

**Interfaces:**
- Consumes: `Migration { version: number; name: string; sql: string; run?: (db: { execute(sql: string, params?: unknown[]): Promise<unknown> }) => Promise<void> }`.
- Produces: SQLite tables/columns —
  - `payment_policy_cache(company_id TEXT PRIMARY KEY, cash_rounding_enabled INTEGER NOT NULL DEFAULT 0, cash_rounding_denomination TEXT, tender_tolerance_enabled INTEGER NOT NULL DEFAULT 0, tender_tolerance_percentage TEXT NOT NULL DEFAULT '0', tender_tolerance_max_amount TEXT NOT NULL DEFAULT '0', currency_code TEXT NOT NULL DEFAULT '', currency_scale INTEGER NOT NULL DEFAULT 2, refreshed_at TEXT NOT NULL DEFAULT (datetime('now')))`
  - `offline_receipts.cash_rounding_adjustment TEXT`, `offline_receipts.cash_rounding_denomination TEXT`, `offline_receipts.tolerance_shortfall TEXT`
  - `payment_methods.is_cash_tender INTEGER NOT NULL DEFAULT 0`

**CRITICAL runner semantics:** `runMigrations` (`src/lib/db.ts:121-133`) and the test helper both do `if (migration.run) { ... } else if (migration.sql) { ... }` — when `run` is present the `sql` string is IGNORED. v63 therefore performs the `CREATE TABLE` inside `run`, with `sql: ''`.

Steps:

- [ ] Write the failing test file `apps/pos/src/lib/db/__tests__/migrations.v63.test.ts`:
```ts
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
  applyAllMigrations,
  runMigrationsUpTo,
  runMigrationVersion,
} from './helpers/migrationTestHelpers';
import { SqliteTestAdapter } from './helpers/sqliteTestAdapter';

interface ColumnInfo {
  name: string;
  type: string;
  notnull: number;
  dflt_value: string | null;
}

describe('Migration v63 — cash rounding policy cache + receipt columns + is_cash_tender', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(() => {
    adapter = new SqliteTestAdapter();
  });

  afterEach(() => {
    adapter.close();
  });

  it('creates payment_policy_cache with TEXT money columns and a datetime default', async () => {
    await runMigrationsUpTo(adapter, 62);
    await runMigrationVersion(adapter, 63);

    const columns = await adapter.select<ColumnInfo[]>('PRAGMA table_info(payment_policy_cache)');
    const byName = new Map(columns.map((c) => [c.name, c]));

    expect(byName.get('company_id')).toMatchObject({ type: 'TEXT' });
    // TEXT affinity is load-bearing: NUMERIC/REAL would strip the trailing zero
    // off '0.050' and every rounded receipt would quarantine server-side.
    expect(byName.get('cash_rounding_denomination')).toMatchObject({ type: 'TEXT' });
    expect(byName.get('tender_tolerance_percentage')).toMatchObject({ type: 'TEXT' });
    expect(byName.get('tender_tolerance_max_amount')).toMatchObject({ type: 'TEXT' });
    expect(byName.get('cash_rounding_enabled')).toMatchObject({ type: 'INTEGER', notnull: 1 });
    expect(byName.get('tender_tolerance_enabled')).toMatchObject({ type: 'INTEGER', notnull: 1 });
    expect(byName.get('refreshed_at')).toMatchObject({ type: 'TEXT', notnull: 1 });
    expect(byName.get('refreshed_at')?.dflt_value).toContain("datetime('now')");
  });

  it('round-trips a scale-3 denomination string without losing the trailing zero', async () => {
    await runMigrationsUpTo(adapter, 63);
    await adapter.execute(
      `INSERT INTO payment_policy_cache (
         company_id, cash_rounding_enabled, cash_rounding_denomination,
         tender_tolerance_enabled, tender_tolerance_percentage, tender_tolerance_max_amount,
         currency_code, currency_scale
       ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8)`,
      ['company-tnd', 1, '0.050', 1, '0.0050', '0.100', 'TND', 3],
    );

    const rows = await adapter.select<Array<{ cash_rounding_denomination: string }>>(
      'SELECT cash_rounding_denomination FROM payment_policy_cache WHERE company_id = $1',
      ['company-tnd'],
    );
    expect(rows[0]?.cash_rounding_denomination).toBe('0.050');
  });

  it('adds the three nullable TEXT columns to offline_receipts and is idempotent', async () => {
    await runMigrationsUpTo(adapter, 62);
    await runMigrationVersion(adapter, 63);

    const columns = await adapter.select<ColumnInfo[]>('PRAGMA table_info(offline_receipts)');
    const byName = new Map(columns.map((c) => [c.name, c]));
    expect(byName.get('cash_rounding_adjustment')).toMatchObject({ type: 'TEXT', notnull: 0 });
    expect(byName.get('cash_rounding_denomination')).toMatchObject({ type: 'TEXT', notnull: 0 });
    expect(byName.get('tolerance_shortfall')).toMatchObject({ type: 'TEXT', notnull: 0 });

    await expect(runMigrationVersion(adapter, 63)).resolves.toBeUndefined();
  });

  it('adds payment_methods.is_cash_tender defaulting to 0 for existing rows', async () => {
    await runMigrationsUpTo(adapter, 62);
    await adapter.execute(
      `INSERT INTO payment_methods (id, code, name, is_physical, is_active, position)
       VALUES ($1, $2, $3, $4, $5, $6)`,
      ['pm-cash', 'CASH', 'Cash', 1, 1, 1],
    );

    await runMigrationVersion(adapter, 63);

    const rows = await adapter.select<Array<{ id: string; is_cash_tender: number }>>(
      'SELECT id, is_cash_tender FROM payment_methods',
    );
    // Fail-closed: a pre-v63 row is NOT cash until the server wire refreshes it.
    expect(rows).toEqual([{ id: 'pm-cash', is_cash_tender: 0 }]);
  });

  it('fresh migrate-to-63 matches the upgrade-from-62 schema', async () => {
    await runMigrationsUpTo(adapter, 63);
    const fresh = await adapter.select<ColumnInfo[]>('PRAGMA table_info(offline_receipts)');

    const upgradeAdapter = new SqliteTestAdapter();
    try {
      await runMigrationsUpTo(upgradeAdapter, 62);
      await runMigrationVersion(upgradeAdapter, 63);
      const upgraded = await upgradeAdapter.select<ColumnInfo[]>(
        'PRAGMA table_info(offline_receipts)',
      );
      const names = (cols: ColumnInfo[]) => cols.map((c) => c.name).sort();
      expect(names(fresh)).toEqual(names(upgraded));
      expect(names(fresh)).toContain('cash_rounding_adjustment');
    } finally {
      upgradeAdapter.close();
    }
  });

  it('applyAllMigrations leaves payment_policy_cache present', async () => {
    await applyAllMigrations(adapter);
    const tables = await adapter.select<Array<{ name: string }>>(
      "SELECT name FROM sqlite_master WHERE type = 'table'",
    );
    expect(tables.map((t) => t.name)).toContain('payment_policy_cache');
  });
});
```
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/db/__tests__/migrations.v63.test.ts`
- [ ] Expected fail: `Migration v63 not found.` thrown by `runMigrationVersion`.
- [ ] Implement — append this object to the `migrations` array in `src/lib/db/migrations.ts`, immediately after the v62 entry and before the closing `];`:
```ts
  {
    // v63: cash rounding + tender tolerance device surfaces (spec 2026-07-27 §4.3).
    //
    // Three parts, all inside `run` because the runner ignores `sql` whenever
    // `run` is present (src/lib/db.ts:121-133):
    //   1. payment_policy_cache — the offline-authoritative policy snapshot.
    //      Money columns are TEXT: NUMERIC/REAL affinity strips the trailing
    //      zero off '0.050', and the denomination is SIGNED into the fiscal
    //      payload, so a mutated string means 100% server-side quarantine.
    //   2. offline_receipts + the signed adjustment/denomination mirror and the
    //      local tolerance shortfall (EOD/print/Z read these; they never ride
    //      the wire — the fiscal-event envelope carries the canonical bytes).
    //   3. payment_methods.is_cash_tender — the ONE cash-ness predicate for
    //      every device layer. Defaults to 0 so a device that upgrades before
    //      the payment-method wire refresh classifies nothing as cash
    //      (fail-closed: rounding off, quick-cash surfaces `errors.noCashMethod`).
    version: 63,
    name: 'cash_rounding_policy_cache_and_receipt_columns',
    sql: '',
    async run(db) {
      await db.execute(`
        CREATE TABLE IF NOT EXISTS payment_policy_cache (
          company_id TEXT PRIMARY KEY,
          cash_rounding_enabled INTEGER NOT NULL DEFAULT 0,
          cash_rounding_denomination TEXT,
          tender_tolerance_enabled INTEGER NOT NULL DEFAULT 0,
          tender_tolerance_percentage TEXT NOT NULL DEFAULT '0',
          tender_tolerance_max_amount TEXT NOT NULL DEFAULT '0',
          currency_code TEXT NOT NULL DEFAULT '',
          currency_scale INTEGER NOT NULL DEFAULT 2,
          refreshed_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
      `);

      for (const col of [
        'cash_rounding_adjustment',
        'cash_rounding_denomination',
        'tolerance_shortfall',
      ]) {
        try {
          await db.execute(`ALTER TABLE offline_receipts ADD COLUMN ${col} TEXT`);
        } catch (error) {
          if (!isDuplicateColumnError(error)) throw error;
        }
      }

      try {
        await db.execute(
          'ALTER TABLE payment_methods ADD COLUMN is_cash_tender INTEGER NOT NULL DEFAULT 0',
        );
      } catch (error) {
        if (!isDuplicateColumnError(error)) throw error;
      }
    },
  },
```
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/db/__tests__/migrations.v63.test.ts`
- [ ] Expected: 6 passing.
- [ ] Regression-check the existing migration suites: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/db/__tests__/migrations.integration.test.ts src/lib/db/__tests__/migrations.tauri-string-errors.test.ts`
- [ ] Commit: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding && git add apps/pos/src/lib/db/migrations.ts apps/pos/src/lib/db/__tests__/migrations.v63.test.ts && git commit -m "$(cat <<'EOF'
POS cash rounding T1: SQLite v63 — policy cache, receipt columns, is_cash_tender

Adds payment_policy_cache (TEXT money columns so the signed denomination
string survives the round trip), the three offline_receipts mirror columns,
and payment_methods.is_cash_tender defaulting to 0 (fail-closed).

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"`

---

### Task 2: `is_cash_tender` device wire — type, row, mapper, upsert, equality

**Files:**
- `apps/pos/src/types/payment.ts:1-17` — `PaymentMethod` interface.
- `apps/pos/src/lib/db/repositories/paymentRepository.ts:5-21` (`PaymentMethodRow`), `:36-54` (`rowToMethod`), `:87-109` (`upsertPaymentMethods`).
- `apps/pos/src/stores/paymentStore.ts:86-117` — `paymentMethodsShallowEqual` (must compare the new field or a server-side cash-flag flip is invisible to every 60 s SQLite refresh tick).
- `apps/pos/src/test/helpers.ts:76-95` — `makePaymentMethod` factory.
- `apps/pos/src/lib/db/repositories/__tests__/paymentRepository.isCashTender.test.ts` (NEW).

**Interfaces:**
- Produces: `PaymentMethod.is_cash_tender: boolean`; `PaymentMethodRow.is_cash_tender: number`.
- `rowToMethod(row: PaymentMethodRow): PaymentMethod` — INTEGER→boolean via `row.is_cash_tender === 1`.
- `upsertPaymentMethods(db: Database, methods: PaymentMethod[]): Promise<void>` — the explicit column list, the `VALUES ($1…$16)` placeholders, the `ON CONFLICT … DO UPDATE SET` list and the bind array must all move in LOCKSTEP; missing any one means every method classifies non-cash and cash checkout dies.
- Consumes: `getAllPaymentMethods` already does `SELECT *`, so no query change is needed.

**Contract note:** the server invariant is `is_cash_tender = true ⇒ code = 'CASH'` EXACT (case-sensitive), enforced by Plan A's `PaymentMethodController` store/update. The device NEVER re-derives cash-ness from the code — it trusts the flag.

Steps:

- [ ] Write the failing test `apps/pos/src/lib/db/repositories/__tests__/paymentRepository.isCashTender.test.ts`:
```ts
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type Database from '@tauri-apps/plugin-sql';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import {
  getAllPaymentMethods,
  upsertPaymentMethods,
} from '@/lib/db/repositories/paymentRepository';
import { makePaymentMethod } from '@/test/helpers';

describe('paymentRepository — is_cash_tender wire', () => {
  let adapter: SqliteTestAdapter;
  let db: Database;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await applyAllMigrations(adapter);
    db = adapter.asDatabase() as unknown as Database;
  });

  afterEach(() => {
    adapter.close();
  });

  it('persists is_cash_tender = true and reads it back as a boolean', async () => {
    await upsertPaymentMethods(db, [
      makePaymentMethod({ id: 'pm-cash', code: 'CASH', is_cash_tender: true }),
      makePaymentMethod({ id: 'pm-card', code: 'CARD', is_cash_tender: false, is_physical: false }),
    ]);

    const methods = await getAllPaymentMethods(db);
    const byId = new Map(methods.map((m) => [m.id, m]));
    expect(byId.get('pm-cash')?.is_cash_tender).toBe(true);
    expect(byId.get('pm-card')?.is_cash_tender).toBe(false);
  });

  it('flips the flag on re-upsert (ON CONFLICT branch carries the column)', async () => {
    await upsertPaymentMethods(db, [
      makePaymentMethod({ id: 'pm-cash', code: 'CASH', is_cash_tender: true }),
    ]);
    await upsertPaymentMethods(db, [
      makePaymentMethod({ id: 'pm-cash', code: 'CASH', is_cash_tender: false }),
    ]);

    const methods = await getAllPaymentMethods(db);
    expect(methods[0]?.is_cash_tender).toBe(false);
  });

  it('MEAL_VOUCHER is never cash even though it is physical and has no maturity', async () => {
    // The legacy quick-cash selection predicate (is_physical && !has_maturity)
    // misclassifies this seeded method; the flag is the only correct source.
    await upsertPaymentMethods(db, [
      makePaymentMethod({
        id: 'pm-meal',
        code: 'MEAL_VOUCHER',
        is_physical: true,
        has_maturity: false,
        is_cash_tender: false,
      }),
    ]);

    const methods = await getAllPaymentMethods(db);
    expect(methods[0]?.is_physical).toBe(true);
    expect(methods[0]?.has_maturity).toBe(false);
    expect(methods[0]?.is_cash_tender).toBe(false);
  });
});
```
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/db/repositories/__tests__/paymentRepository.isCashTender.test.ts`
- [ ] Expected fail: TypeScript rejects `is_cash_tender` in the `makePaymentMethod` override (not on `PaymentMethod`).
- [ ] Implement — `src/types/payment.ts`, add to `PaymentMethod` after `is_restricted: boolean;`:
```ts
  /**
   * Cash-ness — the ONE predicate every device layer uses (spec §4.1).
   * Server invariant: `is_cash_tender === true` implies `code === 'CASH'`
   * EXACT (case-sensitive), enforced by PaymentMethodController store/update.
   * NEVER re-derive cash-ness from is_physical/has_maturity: that legacy
   * predicate classifies MEAL_VOUCHER as cash.
   */
  is_cash_tender: boolean;
```
- [ ] Implement — `src/lib/db/repositories/paymentRepository.ts`, add to `PaymentMethodRow` after `is_restricted: number;`:
```ts
  is_cash_tender: number;
```
- [ ] Implement — same file, add to `rowToMethod`'s returned object after `is_restricted: row.is_restricted === 1,`:
```ts
    is_cash_tender: row.is_cash_tender === 1,
```
- [ ] Implement — same file, replace the whole `upsertPaymentMethods` body statement with the 16-column lockstep version:
```ts
export async function upsertPaymentMethods(db: Database, methods: PaymentMethod[]): Promise<void> {
  for (const m of methods) {
    await execute(
      db,
      `INSERT INTO payment_methods (id, code, name, is_physical, has_maturity, requires_third_party, is_push, has_deducted_fees, is_restricted, fee_type, fee_fixed, fee_percent, restriction_type, is_active, position, is_cash_tender, synced_at)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, datetime('now'))
       ON CONFLICT(id) DO UPDATE SET
         code = excluded.code, name = excluded.name, is_physical = excluded.is_physical,
         has_maturity = excluded.has_maturity, requires_third_party = excluded.requires_third_party,
         is_push = excluded.is_push, has_deducted_fees = excluded.has_deducted_fees,
         is_restricted = excluded.is_restricted, fee_type = excluded.fee_type,
         fee_fixed = excluded.fee_fixed, fee_percent = excluded.fee_percent,
         restriction_type = excluded.restriction_type, is_active = excluded.is_active,
         position = excluded.position, is_cash_tender = excluded.is_cash_tender,
         synced_at = datetime('now')`,
      [
        m.id, m.code, m.name, m.is_physical ? 1 : 0, m.has_maturity ? 1 : 0,
        m.requires_third_party ? 1 : 0, m.is_push ? 1 : 0, m.has_deducted_fees ? 1 : 0,
        m.is_restricted ? 1 : 0, m.fee_type, m.fee_fixed, m.fee_percent,
        m.restriction_type, m.is_active ? 1 : 0, m.position, m.is_cash_tender ? 1 : 0,
      ]
    );
  }
}
```
- [ ] Implement — `src/stores/paymentStore.ts`, in `paymentMethodsShallowEqual` add to the `if (` comparison chain, after `ax.is_restricted !== bx.is_restricted ||`:
```ts
      ax.is_cash_tender !== bx.is_cash_tender ||
```
- [ ] Implement — `src/test/helpers.ts`, add to the `makePaymentMethod` defaults after `is_restricted: false,`:
```ts
    is_cash_tender: true,
```
  (The factory defaults to `code: 'CASH'`, so `is_cash_tender: true` keeps the default fixture self-consistent with the server invariant; non-cash fixtures pass the override explicitly.)
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/db/repositories/__tests__/paymentRepository.isCashTender.test.ts`
- [ ] Expected: 3 passing.
- [ ] Fix the fallout in every fixture that builds a `PaymentMethod` literal without the factory: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm typecheck` — add `is_cash_tender: <true for CASH, false otherwise>` to each reported literal.
- [ ] Run the payment-store regression set: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/stores/__tests__/paymentStore.refreshFromSQLite.test.ts src/stores/__tests__/paymentStore.test.ts`
- [ ] Commit: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding && git add apps/pos/src/types/payment.ts apps/pos/src/lib/db/repositories/paymentRepository.ts apps/pos/src/stores/paymentStore.ts apps/pos/src/test/helpers.ts apps/pos/src/lib/db/repositories/__tests__/paymentRepository.isCashTender.test.ts && git commit -m "$(cat <<'EOF'
POS cash rounding T2: is_cash_tender device wire (type, row, mapper, upsert)

PaymentMethod.is_cash_tender flows API -> SQLite -> store in lockstep across
the interface, the row type, rowToMethod, the INSERT/ON CONFLICT/bind triple,
and the refreshFromSQLite shallow-equality guard.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"`

---

### Task 3: Payment-policy pull, SQLite cache, store slice, synchronous accessor

**Files:**
- `apps/pos/src/lib/db/repositories/paymentPolicyCacheRepository.ts` (NEW) — mirror `companyFraudSettingsCacheRepository.ts:23-80` exactly (upsert + get, INTEGER→boolean at the boundary).
- `apps/pos/src/api/paymentPolicyApi.ts` (NEW) — mirror `src/api/fraudSettingsApi.ts:15-34`.
- `apps/pos/src/stores/paymentPolicyStore.ts` (NEW) — Zustand slice + `getActivePaymentPolicy()` non-hook accessor.
- `apps/pos/src/lib/sync/syncService.ts` — new `pullPaymentPolicy` beside `pullPaymentConfig` (`:1098-1122`); call it in `runFullSync` right after `const paymentConfigPulled = await pullPaymentConfig(db);` (`:2026`).
- `apps/pos/src/stores/terminalStore.ts:490-507` — activation pre-warm block (the `usePaymentStore.getState().fetchPaymentConfig()` fire-and-forget); add the policy refresh next to `refreshFraudSettingsCache` (`:524-529`).
- `apps/pos/src/stores/__tests__/paymentPolicyStore.test.ts` (NEW).

**Interfaces:**
- Produces (device shape, the ONLY shape the rest of the device consumes):
```ts
export interface PaymentPolicy {
  readonly cashRoundingEnabled: boolean;
  readonly cashRoundingDenomination: string | null;
  readonly tenderToleranceEnabled: boolean;
  readonly tenderTolerancePercentage: string;
  readonly tenderToleranceMaxAmount: string;
  readonly currencyCode: string;
  readonly currencyScale: number;
  readonly refreshedAt: string | null;
}
```
- Produces: `getActivePaymentPolicy(): PaymentPolicy | null` (synchronous, reads `usePaymentPolicyStore.getState().policy`).
- Produces: `hydratePaymentPolicyFromCache(db: Database, companyId: string): Promise<void>`, `refreshPaymentPolicy(db: Database, companyId: string): Promise<void>`.
- Produces: `upsertPaymentPolicy(db: Database, row: PaymentPolicyCacheRow): Promise<void>`, `getPaymentPolicy(db: Database, companyId: string): Promise<PaymentPolicyCacheRow | null>`.
- Produces: `pullPaymentPolicy(db: Database): Promise<boolean>` in syncService (same swallow-and-log contract as `pullPaymentConfig`: returns false on failure, never throws).
- Consumes: `apiGet<T>(path: string): Promise<T>` from `@/lib/api`; `execute`/`queryAll` from `@/lib/db`.

**Wire-shape note:** Plan A authors the `GET /api/v1/pos/payment-policy` DTO. The camelCase names below follow the fraud-settings precedent (`fraudSettingsApi.ts:5-13`). If Plan A's serialized property names differ, ONLY `paymentPolicyApi.ts`'s `toPaymentPolicy` mapper changes — everything downstream consumes the device `PaymentPolicy` shape. Verify the response shape against `apps/api` before writing the mapper.

Steps:

- [ ] Verify the server endpoint exists (Plan A precondition): `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding && grep -rn "payment-policy" apps/api/app/Modules/Pos apps/api/routes 2>/dev/null | head`. If nothing is found, Plan A has not landed — the device code can still be written and unit-tested (the pull is failure-tolerant), but note it in the task log.
- [ ] Write the failing test `apps/pos/src/stores/__tests__/paymentPolicyStore.test.ts`:
```ts
import { beforeEach, describe, expect, it, vi } from 'vitest';

const apiGet = vi.fn();
vi.mock('@/lib/api', () => ({ apiGet: (...args: unknown[]) => apiGet(...args) }));

const upsertPaymentPolicy = vi.fn();
const getPaymentPolicy = vi.fn();
vi.mock('@/lib/db/repositories/paymentPolicyCacheRepository', () => ({
  upsertPaymentPolicy: (...args: unknown[]) => upsertPaymentPolicy(...args),
  getPaymentPolicy: (...args: unknown[]) => getPaymentPolicy(...args),
}));

import {
  getActivePaymentPolicy,
  hydratePaymentPolicyFromCache,
  refreshPaymentPolicy,
  usePaymentPolicyStore,
} from '@/stores/paymentPolicyStore';

const db = {} as never;

describe('paymentPolicyStore', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    usePaymentPolicyStore.getState().reset();
  });

  it('starts fail-closed: no policy means both mechanisms are off', () => {
    expect(getActivePaymentPolicy()).toBeNull();
  });

  it('hydrates from SQLite without touching the network', async () => {
    getPaymentPolicy.mockResolvedValue({
      company_id: 'company-tnd',
      cash_rounding_enabled: true,
      cash_rounding_denomination: '0.050',
      tender_tolerance_enabled: true,
      tender_tolerance_percentage: '0.0050',
      tender_tolerance_max_amount: '0.100',
      currency_code: 'TND',
      currency_scale: 3,
      refreshed_at: '2026-07-27 08:00:00',
    });

    await hydratePaymentPolicyFromCache(db, 'company-tnd');

    expect(apiGet).not.toHaveBeenCalled();
    expect(getActivePaymentPolicy()).toEqual({
      cashRoundingEnabled: true,
      cashRoundingDenomination: '0.050',
      tenderToleranceEnabled: true,
      tenderTolerancePercentage: '0.0050',
      tenderToleranceMaxAmount: '0.100',
      currencyCode: 'TND',
      currencyScale: 3,
      refreshedAt: '2026-07-27 08:00:00',
    });
  });

  it('an empty cache leaves the policy null (fail-closed, never a fabricated default)', async () => {
    getPaymentPolicy.mockResolvedValue(null);
    await hydratePaymentPolicyFromCache(db, 'company-tnd');
    expect(getActivePaymentPolicy()).toBeNull();
  });

  it('refresh writes the API response through to SQLite and to the slice', async () => {
    apiGet.mockResolvedValue({
      cashRoundingEnabled: true,
      cashRoundingDenomination: '0.050',
      tenderToleranceEnabled: false,
      tenderTolerancePercentage: '0.0050',
      tenderToleranceMaxAmount: '0.100',
      currencyCode: 'TND',
      currencyScale: 3,
    });

    await refreshPaymentPolicy(db, 'company-tnd');

    expect(apiGet).toHaveBeenCalledWith('/pos/payment-policy');
    expect(upsertPaymentPolicy).toHaveBeenCalledWith(db, expect.objectContaining({
      company_id: 'company-tnd',
      cash_rounding_denomination: '0.050',
      tender_tolerance_enabled: false,
    }));
    expect(getActivePaymentPolicy()?.cashRoundingDenomination).toBe('0.050');
  });

  it('a failed refresh keeps the previously hydrated policy (offline tolerance)', async () => {
    getPaymentPolicy.mockResolvedValue({
      company_id: 'company-tnd',
      cash_rounding_enabled: true,
      cash_rounding_denomination: '0.050',
      tender_tolerance_enabled: true,
      tender_tolerance_percentage: '0.0050',
      tender_tolerance_max_amount: '0.100',
      currency_code: 'TND',
      currency_scale: 3,
      refreshed_at: '2026-07-27 08:00:00',
    });
    await hydratePaymentPolicyFromCache(db, 'company-tnd');

    apiGet.mockRejectedValue(new Error('offline'));
    await expect(refreshPaymentPolicy(db, 'company-tnd')).rejects.toThrow('offline');

    expect(getActivePaymentPolicy()?.cashRoundingEnabled).toBe(true);
  });
});
```
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/stores/__tests__/paymentPolicyStore.test.ts`
- [ ] Expected fail: `Failed to resolve import "@/stores/paymentPolicyStore"`.
- [ ] Implement — `apps/pos/src/lib/db/repositories/paymentPolicyCacheRepository.ts`:
```ts
import type Database from '@tauri-apps/plugin-sql';
import { queryAll, execute } from '@/lib/db';

/**
 * Offline-authoritative POS payment policy (spec 2026-07-27 §4.2/§4.3).
 *
 * Money columns are TEXT and are carried as decimal STRINGS end-to-end: the
 * denomination is signed into the SALE_RECEIPT canonical bytes, so any hop
 * that turns '0.050' into 0.05 makes every rounded receipt quarantine.
 */
export interface PaymentPolicyCacheRow {
  company_id: string;
  cash_rounding_enabled: boolean;
  cash_rounding_denomination: string | null;
  tender_tolerance_enabled: boolean;
  tender_tolerance_percentage: string;
  tender_tolerance_max_amount: string;
  currency_code: string;
  currency_scale: number;
  refreshed_at?: string;
}

interface RawRow extends Omit<
  PaymentPolicyCacheRow,
  'cash_rounding_enabled' | 'tender_tolerance_enabled'
> {
  cash_rounding_enabled: number;
  tender_tolerance_enabled: number;
  refreshed_at: string;
}

export async function upsertPaymentPolicy(
  db: Database,
  row: PaymentPolicyCacheRow,
): Promise<void> {
  await execute(
    db,
    `INSERT INTO payment_policy_cache (
       company_id,
       cash_rounding_enabled, cash_rounding_denomination,
       tender_tolerance_enabled, tender_tolerance_percentage, tender_tolerance_max_amount,
       currency_code, currency_scale, refreshed_at
     ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, datetime('now'))
     ON CONFLICT(company_id) DO UPDATE SET
       cash_rounding_enabled        = excluded.cash_rounding_enabled,
       cash_rounding_denomination   = excluded.cash_rounding_denomination,
       tender_tolerance_enabled     = excluded.tender_tolerance_enabled,
       tender_tolerance_percentage  = excluded.tender_tolerance_percentage,
       tender_tolerance_max_amount  = excluded.tender_tolerance_max_amount,
       currency_code                = excluded.currency_code,
       currency_scale               = excluded.currency_scale,
       refreshed_at                 = datetime('now')`,
    [
      row.company_id,
      row.cash_rounding_enabled ? 1 : 0,
      row.cash_rounding_denomination,
      row.tender_tolerance_enabled ? 1 : 0,
      row.tender_tolerance_percentage,
      row.tender_tolerance_max_amount,
      row.currency_code,
      row.currency_scale,
    ],
  );
}

export async function getPaymentPolicy(
  db: Database,
  companyId: string,
): Promise<PaymentPolicyCacheRow | null> {
  const rows = await queryAll<RawRow>(
    db,
    `SELECT company_id,
            cash_rounding_enabled, cash_rounding_denomination,
            tender_tolerance_enabled, tender_tolerance_percentage, tender_tolerance_max_amount,
            currency_code, currency_scale, refreshed_at
     FROM payment_policy_cache
     WHERE company_id = $1`,
    [companyId],
  );
  if (rows.length === 0) return null;
  const r = rows[0]!;
  return {
    ...r,
    cash_rounding_enabled: r.cash_rounding_enabled === 1,
    tender_tolerance_enabled: r.tender_tolerance_enabled === 1,
  };
}
```
- [ ] Implement — `apps/pos/src/api/paymentPolicyApi.ts`:
```ts
import { apiGet } from '@/lib/api';

/**
 * Wire shape of GET /api/v1/pos/payment-policy (server DTO, Plan A §4.2).
 * Money fields are STRINGS — the denomination arrives already normalized to
 * the company currency scale and must reach the signed payload unmutated.
 */
export interface PaymentPolicyResponse {
  cashRoundingEnabled: boolean;
  cashRoundingDenomination: string | null;
  tenderToleranceEnabled: boolean;
  tenderTolerancePercentage: string;
  tenderToleranceMaxAmount: string;
  currencyCode: string;
  currencyScale: number;
}

export async function fetchPaymentPolicy(): Promise<PaymentPolicyResponse> {
  return apiGet<PaymentPolicyResponse>('/pos/payment-policy');
}
```
- [ ] Implement — `apps/pos/src/stores/paymentPolicyStore.ts`:
```ts
import { create } from 'zustand';
import type Database from '@tauri-apps/plugin-sql';
import { fetchPaymentPolicy } from '@/api/paymentPolicyApi';
import {
  getPaymentPolicy,
  upsertPaymentPolicy,
} from '@/lib/db/repositories/paymentPolicyCacheRepository';

/**
 * Device-side POS payment policy (spec 2026-07-27 §4.3).
 *
 * SQLite-FIRST: `hydratePaymentPolicyFromCache` runs at activation and gives
 * the cashier an offline-authoritative policy before any network call;
 * `refreshPaymentPolicy` then writes the fresh server value through to both
 * SQLite and this slice. A missing policy is NEVER replaced with a fabricated
 * default — `null` means "both mechanisms disabled", i.e. exact today-behavior.
 */
export interface PaymentPolicy {
  readonly cashRoundingEnabled: boolean;
  readonly cashRoundingDenomination: string | null;
  readonly tenderToleranceEnabled: boolean;
  readonly tenderTolerancePercentage: string;
  readonly tenderToleranceMaxAmount: string;
  readonly currencyCode: string;
  readonly currencyScale: number;
  readonly refreshedAt: string | null;
}

interface PaymentPolicyState {
  policy: PaymentPolicy | null;
}

interface PaymentPolicyActions {
  setPolicy: (policy: PaymentPolicy | null) => void;
  reset: () => void;
}

export const usePaymentPolicyStore = create<PaymentPolicyState & PaymentPolicyActions>()((set) => ({
  policy: null,
  setPolicy: (policy) => set({ policy }),
  reset: () => set({ policy: null }),
}));

/**
 * Synchronous, non-hook accessor. Safe from stores / library code (the
 * checkout snapshot builder calls it at tender time). Returns null when no
 * policy has been hydrated — callers MUST treat null as "disabled".
 */
export function getActivePaymentPolicy(): PaymentPolicy | null {
  return usePaymentPolicyStore.getState().policy;
}

export async function hydratePaymentPolicyFromCache(
  db: Database,
  companyId: string,
): Promise<void> {
  const row = await getPaymentPolicy(db, companyId);
  if (row === null) {
    return;
  }
  usePaymentPolicyStore.getState().setPolicy({
    cashRoundingEnabled: row.cash_rounding_enabled,
    cashRoundingDenomination: row.cash_rounding_denomination,
    tenderToleranceEnabled: row.tender_tolerance_enabled,
    tenderTolerancePercentage: row.tender_tolerance_percentage,
    tenderToleranceMaxAmount: row.tender_tolerance_max_amount,
    currencyCode: row.currency_code,
    currencyScale: row.currency_scale,
    refreshedAt: row.refreshed_at ?? null,
  });
}

export async function refreshPaymentPolicy(db: Database, companyId: string): Promise<void> {
  const response = await fetchPaymentPolicy();
  await upsertPaymentPolicy(db, {
    company_id: companyId,
    cash_rounding_enabled: response.cashRoundingEnabled,
    cash_rounding_denomination: response.cashRoundingDenomination,
    tender_tolerance_enabled: response.tenderToleranceEnabled,
    tender_tolerance_percentage: response.tenderTolerancePercentage,
    tender_tolerance_max_amount: response.tenderToleranceMaxAmount,
    currency_code: response.currencyCode,
    currency_scale: response.currencyScale,
  });
  usePaymentPolicyStore.getState().setPolicy({
    cashRoundingEnabled: response.cashRoundingEnabled,
    cashRoundingDenomination: response.cashRoundingDenomination,
    tenderToleranceEnabled: response.tenderToleranceEnabled,
    tenderTolerancePercentage: response.tenderTolerancePercentage,
    tenderToleranceMaxAmount: response.tenderToleranceMaxAmount,
    currencyCode: response.currencyCode,
    currencyScale: response.currencyScale,
    refreshedAt: new Date().toISOString(),
  });
}
```
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/stores/__tests__/paymentPolicyStore.test.ts`
- [ ] Expected: 5 passing.
- [ ] Implement — `src/lib/sync/syncService.ts`, add after `pullPaymentConfig` (ends at `:1122`):
```ts
/**
 * Pull the POS payment policy (cash rounding + tender tolerance) for the
 * active company. Swallow-and-log like every other pull: a failure leaves the
 * previously cached policy in place, which is exactly the offline contract —
 * a device that never reaches the server keeps signing against the policy it
 * last saw, and the projection's policy-reconciliation alert flags the drift.
 */
export async function pullPaymentPolicy(db: Database): Promise<boolean> {
  try {
    const { useAuthStore } = await import('@/stores/authStore');
    const companyId = useAuthStore.getState().companyId;
    if (!companyId) {
      await logSyncOperation(db, 'pull', 'payment_policy', null, 'error', 'no company selected');
      return false;
    }
    const { refreshPaymentPolicy } = await import('@/stores/paymentPolicyStore');
    await refreshPaymentPolicy(db, companyId);
    await setSyncMetadata(db, 'payment_policy_last_sync', new Date().toISOString());
    await logSyncOperation(db, 'pull', 'payment_policy', null, 'success');
    return true;
  } catch (error) {
    const message = coerceSyncError(error);
    await logSyncOperation(db, 'pull', 'payment_policy', null, 'error', message);
    return false;
  }
}
```
- [ ] Implement — same file, in `runFullSync` add immediately after `const paymentConfigPulled = await pullPaymentConfig(db);`:
```ts
  await pullPaymentPolicy(db);
```
- [ ] Implement — `src/stores/terminalStore.ts`, add after the `refreshFraudSettingsCache` fire-and-forget block (ends `:529`):
```ts
    // Cash rounding / tender tolerance (spec 2026-07-27 §4.3): hydrate the
    // cached policy FIRST so an offline activation has an authoritative policy
    // immediately, then refresh from the server. Both are fire-and-forget: a
    // failure leaves the policy null/stale, which is fail-closed (exact
    // behavior, no rounding, no auto-accept).
    void hydratePaymentPolicyFromCache(db, companyId)
      .then(() => refreshPaymentPolicy(db, companyId))
      .catch((err: unknown) => {
        console.error(
          '[POS][terminalStore][preWarm] payment-policy refresh failed',
          serializeErrorForLog(err),
        );
      });
```
  …and add the import next to the existing `refreshFraudSettingsCache` import (`:7`):
```ts
import {
  hydratePaymentPolicyFromCache,
  refreshPaymentPolicy,
} from '@/stores/paymentPolicyStore';
```
- [ ] Run the sync + activation regressions: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/sync/__tests__/syncService.test.ts src/stores/__tests__/terminalStore.preWarm.test.ts`
- [ ] Expected: green (both suites mock the pull surface; add `pullPaymentPolicy` to any `vi.mock('@/lib/sync/syncService')` factory the typecheck flags).
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm typecheck`
- [ ] Commit: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding && git add apps/pos/src/lib/db/repositories/paymentPolicyCacheRepository.ts apps/pos/src/api/paymentPolicyApi.ts apps/pos/src/stores/paymentPolicyStore.ts apps/pos/src/lib/sync/syncService.ts apps/pos/src/stores/terminalStore.ts apps/pos/src/stores/__tests__/paymentPolicyStore.test.ts && git commit -m "$(cat <<'EOF'
POS cash rounding T3: payment-policy pull, SQLite cache, slice, accessor

SQLite-first policy hydration at activation plus a per-tick pull, exposed
through getActivePaymentPolicy(). A missing or unreachable policy stays null,
which every consumer treats as "both mechanisms disabled".

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"`

---

### Task 4: `lib/payment/cashRounding.ts` — the pure rounding/tolerance module

**Files:**
- `apps/pos/src/lib/decimal.ts:22-66` — add `bcmod` beside `bcdiv`; `Big.RM = 1` (half-up, away from zero) is set at `:14` and MUST NOT change.
- `apps/pos/src/lib/payment/cashRounding.ts` (NEW).
- `apps/pos/src/lib/payment/__tests__/cashRounding.test.ts` (NEW).
- Sibling for style: `apps/pos/src/lib/payment/paymentMethodKind.ts`.

**Interfaces:**
- Consumes: `bccomp`, `bcmul`, `bcdiv`, `bcsub`, `bcadd`, `bcabs`, `bcformat` from `@/lib/decimal`.
- Produces:
```ts
export interface TenderLeg { readonly methodCode: string; readonly amount: string }
export function isValidDenomination(denomination: string | null, scale: number): boolean
export function roundCashTotal(exactTotal: string, denomination: string, scale: number): string
export function computeRoundingAdjustment(exactTotal: string, roundedTotal: string, scale: number): string
export function isCashOnlyTender(legs: readonly TenderLeg[], isCashMethodCode: (code: string) => boolean): boolean
export function toleranceEffectiveMax(input: ToleranceMaxInput): string
export const DENOMINATION_CAP_BY_SCALE: Readonly<Record<number, string>>
export const TOLERANCE_AUTO_ACCEPT_LIMIT_PER_SHIFT: 10
```
- Produces in `decimal.ts`: `export function bcmod(a: string, b: string, scale?: number): string` — throws `Error('Modulo by zero')` on a zero divisor (callers MUST assert `denomination > 0` first; the assert order is normative).

**Semantics pinned by the spec:** `rounded = round_half_up(exact / D) × D`; `adj = rounded − exact` (signed, `|adj| ≤ D/2`, ties UP). Tolerance `effective_max = min(pct × total, max_amount)`, floored to `D` when rounding is active AND `exact_total > 0`. The pct cap is TRUNCATED at currency scale (never rounded up) so the cap can only ever be conservative — the denomination floor is what makes a full-`D` shortfall acceptable, exactly as the spec's worked example requires.

Steps:

- [ ] Write the failing test `apps/pos/src/lib/payment/__tests__/cashRounding.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import {
  computeRoundingAdjustment,
  isCashOnlyTender,
  isValidDenomination,
  roundCashTotal,
  toleranceEffectiveMax,
} from '@/lib/payment/cashRounding';

const TND = 3;
const EUR = 2;
const JPY = 0;

describe('roundCashTotal — Swedish rounding to the nearest denomination', () => {
  it.each([
    ['9.997', '10.000'],
    ['9.973', '9.950'],
    ['9.975', '10.000'], // exact tie → UP
    ['0.025', '0.050'],  // smallest tie → UP
    ['0.024', '0.000'],
    ['0.026', '0.050'],
    ['9.950', '9.950'],  // exact multiple → unchanged
    ['0.000', '0.000'],
  ])('TND 0.050: %s rounds to %s', (exact, expected) => {
    expect(roundCashTotal(exact, '0.050', TND)).toBe(expected);
  });

  it('|adjustment| never exceeds D/2 across a full denomination sweep', () => {
    for (let millimes = 0; millimes <= 200; millimes += 1) {
      const exact = (millimes / 1000).toFixed(3);
      const rounded = roundCashTotal(exact, '0.050', TND);
      const adj = computeRoundingAdjustment(exact, rounded, TND);
      const magnitude = adj.startsWith('-') ? adj.slice(1) : adj;
      // D/2 = 0.025 at scale 3; the bound is inclusive (the tie case hits it).
      expect(Number(magnitude) <= 0.025 + 1e-9).toBe(true);
      expect(Number(rounded) % 0.05).toBeCloseTo(0, 6);
    }
  });

  it('returns a signed adjustment string at currency scale', () => {
    expect(computeRoundingAdjustment('9.997', '10.000', TND)).toBe('0.003');
    expect(computeRoundingAdjustment('9.973', '9.950', TND)).toBe('-0.023');
    expect(computeRoundingAdjustment('9.950', '9.950', TND)).toBe('0.000');
  });

  it('EUR scale 2 with a 0.05 denomination rounds at the cent', () => {
    expect(roundCashTotal('9.97', '0.05', EUR)).toBe('9.95');
    expect(roundCashTotal('9.98', '0.05', EUR)).toBe('10.00');
  });
});

describe('isValidDenomination — fail-closed validity', () => {
  it('accepts a scale-representable positive denomination', () => {
    expect(isValidDenomination('0.050', TND)).toBe(true);
    expect(isValidDenomination('0.05', EUR)).toBe(true);
    expect(isValidDenomination('10', JPY)).toBe(true);
  });

  it('rejects null, empty, zero, negative and non-numeric values', () => {
    expect(isValidDenomination(null, TND)).toBe(false);
    expect(isValidDenomination('', TND)).toBe(false);
    expect(isValidDenomination('0.000', TND)).toBe(false);
    expect(isValidDenomination('-0.050', TND)).toBe(false);
    expect(isValidDenomination('abc', TND)).toBe(false);
  });

  it('rejects a denomination that is not representable at the currency scale', () => {
    // 0.0025 would truncate/round to 0.003 — the signed value would no longer
    // divide the signed total, so the payload must never carry it.
    expect(isValidDenomination('0.0025', TND)).toBe(false);
  });

  it('rejects a denomination beyond the static history-stable cap', () => {
    expect(isValidDenomination('1.000', TND)).toBe(true);
    expect(isValidDenomination('5.000', TND)).toBe(false);
    expect(isValidDenomination('1.00', EUR)).toBe(true);
    expect(isValidDenomination('2.00', EUR)).toBe(false);
    expect(isValidDenomination('10', JPY)).toBe(true);
    expect(isValidDenomination('50', JPY)).toBe(false);
  });
});

describe('isCashOnlyTender — union over payment legs and voucher tenders', () => {
  const isCash = (code: string) => code === 'CASH';

  it('is true when every leg is a cash method', () => {
    expect(isCashOnlyTender([{ methodCode: 'CASH', amount: '10.000' }], isCash)).toBe(true);
    expect(isCashOnlyTender(
      [{ methodCode: 'CASH', amount: '5.000' }, { methodCode: 'CASH', amount: '5.000' }],
      isCash,
    )).toBe(true);
  });

  it('is false when a MEAL_VOUCHER leg is present (physical, no maturity, NOT cash)', () => {
    expect(isCashOnlyTender(
      [{ methodCode: 'CASH', amount: '5.000' }, { methodCode: 'MEAL_VOUCHER', amount: '5.000' }],
      isCash,
    )).toBe(false);
  });

  it('is false for a voucher-partial tender (voucher legs ARE payment legs)', () => {
    expect(isCashOnlyTender(
      [{ methodCode: 'CASH', amount: '5.000' }, { methodCode: 'store_voucher', amount: '5.000' }],
      isCash,
    )).toBe(false);
  });

  it('is false for card-only and for an empty leg set', () => {
    expect(isCashOnlyTender([{ methodCode: 'CARD', amount: '10.000' }], isCash)).toBe(false);
    expect(isCashOnlyTender([], isCash)).toBe(false);
  });
});

describe('toleranceEffectiveMax — min(pct, max) with the denomination floor', () => {
  const base = {
    percentage: '0.0050',
    maxAmount: '0.100',
    denomination: '0.050',
    scale: TND,
  };

  it('takes the percentage cap when it is the smaller of the two', () => {
    // 9.973 * 0.005 = 0.049865 -> truncated at scale 3 = 0.049; floor lifts it to D.
    expect(toleranceEffectiveMax({ ...base, exactTotal: '9.973', roundingActive: false }))
      .toBe('0.049');
  });

  it('takes max_amount when the percentage cap exceeds it', () => {
    // 100.000 * 0.005 = 0.500 > 0.100
    expect(toleranceEffectiveMax({ ...base, exactTotal: '100.000', roundingActive: false }))
      .toBe('0.100');
  });

  it('applies the denomination floor when rounding is active', () => {
    expect(toleranceEffectiveMax({ ...base, exactTotal: '9.973', roundingActive: true }))
      .toBe('0.050');
  });

  it('never floors a zero-total sale', () => {
    expect(toleranceEffectiveMax({ ...base, exactTotal: '0.000', roundingActive: true }))
      .toBe('0.000');
  });

  it('ignores the floor when the denomination is invalid', () => {
    expect(toleranceEffectiveMax({
      ...base,
      denomination: '0.0025',
      exactTotal: '9.973',
      roundingActive: true,
    })).toBe('0.049');
  });
});
```
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/payment/__tests__/cashRounding.test.ts`
- [ ] Expected fail: `Failed to resolve import "@/lib/payment/cashRounding"`.
- [ ] Implement — `src/lib/decimal.ts`, add after `bcdiv` (`:34-40`):
```ts
/**
 * Remainder of `a / b` at `scale`. Used by the v3 aggregate bind
 * (`total mod denomination == 0`). Callers MUST assert `b > 0` BEFORE calling
 * — the assert ORDER is normative (spec §4.1): reaching a zero divisor must be
 * impossible, not merely caught.
 */
export function bcmod(a: string, b: string, scale: number = 3): string {
  const divisor = safeBig(b);
  if (divisor.eq(0)) {
    throw new Error('Modulo by zero');
  }
  return safeBig(a).mod(divisor).toFixed(scale);
}
```
- [ ] Implement — `apps/pos/src/lib/payment/cashRounding.ts`:
```ts
/**
 * Cash rounding + tender tolerance — pure device helpers (spec 2026-07-27 §4.1).
 *
 * Every function here is total, side-effect-free and string-in/string-out so
 * the checkout snapshot builder can be unit-pinned without a DB, a store or a
 * network. NOTHING in this module reads global state.
 *
 * Rounding contract:
 *   rounded = round_half_up(exact / D) * D      (ties away from zero; totals
 *                                               are non-negative so that is
 *                                               plain half-up)
 *   adj     = rounded - exact                   (signed, |adj| <= D/2)
 *
 * `Big.RM = 1` is set once in `@/lib/decimal` and is the half-up rounding mode
 * every bc* helper inherits; do not change it.
 */

import { bcabs, bcadd, bccomp, bcdiv, bcformat, bcmul, bcsub } from '@/lib/decimal';

/** A single tender leg — a payment line OR a voucher tender (they are the same thing). */
export interface TenderLeg {
  readonly methodCode: string;
  readonly amount: string;
}

/**
 * Static, history-stable ceilings on the signed denomination, mirrored from
 * the server bind (spec §4.1). A suppression payload claiming a huge
 * denomination fails here on the device and in the validator server-side.
 * JPY 10-yen rounding is real, hence the scale-0 entry.
 */
export const DENOMINATION_CAP_BY_SCALE: Readonly<Record<number, string>> = {
  0: '10',
  2: '1.00',
  3: '1.000',
};

/** Per-shift auto-accept ceiling before tolerance escalates to the PIN path (spec §8.1). */
export const TOLERANCE_AUTO_ACCEPT_LIMIT_PER_SHIFT = 10 as const;

const NON_NEGATIVE_DECIMAL = /^\d+(\.\d+)?$/;

/**
 * Truncate (never round up) a non-negative decimal string at `scale`.
 * Used for the tolerance percentage cap so the accepted ceiling can only ever
 * be conservative — the denomination floor is the mechanism that admits a
 * full-D shortfall, not an inflated percentage.
 */
function truncateAtScale(value: string, scale: number): string {
  const trimmed = value.trim();
  const negative = trimmed.startsWith('-');
  const magnitude = negative ? trimmed.slice(1) : trimmed;
  const [intPart = '0', fracPart = ''] = magnitude.split('.');
  const padded = `${fracPart}${'0'.repeat(scale)}`.slice(0, scale);
  const out = scale === 0 ? intPart : `${intPart}.${padded}`;
  return negative ? `-${out}` : out;
}

/**
 * True when `denomination` is a usable rounding step at `scale`:
 * numeric, strictly positive, EXACTLY representable at `scale` (round-trip
 * equality — '0.0025' on a scale-3 currency is rejected, never silently
 * re-scaled), and within the static cap. Anything else disables rounding.
 */
export function isValidDenomination(denomination: string | null, scale: number): boolean {
  if (denomination === null) return false;
  const trimmed = denomination.trim();
  if (!NON_NEGATIVE_DECIMAL.test(trimmed)) return false;

  let normalized: string;
  try {
    normalized = bcformat(trimmed, scale);
  } catch {
    return false;
  }
  if (bccomp(normalized, '0') <= 0) return false;
  if (bccomp(normalized, trimmed) !== 0) return false;

  const cap = DENOMINATION_CAP_BY_SCALE[scale];
  if (cap === undefined) return false;
  return bccomp(normalized, cap) <= 0;
}

/**
 * Swedish-round `exactTotal` to the nearest multiple of `denomination`.
 * Caller MUST have validated the denomination first (`isValidDenomination`).
 */
export function roundCashTotal(exactTotal: string, denomination: string, scale: number): string {
  const units = bcdiv(bcformat(exactTotal, scale), denomination, 0);
  return bcformat(bcmul(units, denomination, scale), scale);
}

/** Signed `rounded - exact` at currency scale. Canonical zero when they agree. */
export function computeRoundingAdjustment(
  exactTotal: string,
  roundedTotal: string,
  scale: number,
): string {
  return bcformat(bcsub(roundedTotal, exactTotal, scale), scale);
}

/**
 * True when EVERY leg of the union (payment lines + voucher tenders) is a cash
 * method. An empty tender is never cash-only. Cash-ness comes from
 * `payment_methods.is_cash_tender` via the injected predicate — never from
 * is_physical/has_maturity (that misclassifies MEAL_VOUCHER) and never from a
 * hardcoded 'CASH' string comparison in this module.
 */
export function isCashOnlyTender(
  legs: readonly TenderLeg[],
  isCashMethodCode: (code: string) => boolean,
): boolean {
  if (legs.length === 0) return false;
  return legs.every((leg) => isCashMethodCode(leg.methodCode));
}

export interface ToleranceMaxInput {
  readonly exactTotal: string;
  /** Fraction, not a percent literal: 0.5% arrives as '0.0050'. */
  readonly percentage: string;
  readonly maxAmount: string;
  readonly denomination: string | null;
  readonly roundingActive: boolean;
  readonly scale: number;
}

/**
 * `min(pct * total, max_amount)`, floored to the denomination when rounding is
 * active on a positive-total sale (owner decision §8.1). Returns a
 * currency-scale string; a zero result means "no auto-accept headroom".
 */
export function toleranceEffectiveMax(input: ToleranceMaxInput): string {
  const { exactTotal, percentage, maxAmount, denomination, roundingActive, scale } = input;
  const zero = bcformat('0', scale);

  if (bccomp(bcformat(exactTotal, scale), '0') <= 0) {
    return zero;
  }

  const pctCapRaw = bcmul(bcformat(exactTotal, scale), percentage, scale + 4);
  const pctCap = truncateAtScale(pctCapRaw, scale);
  const maxCap = bcformat(maxAmount, scale);
  let effective = bccomp(pctCap, maxCap) < 0 ? pctCap : maxCap;

  if (
    roundingActive
    && isValidDenomination(denomination, scale)
    && bccomp(bcformat(denomination as string, scale), effective) > 0
  ) {
    effective = bcformat(denomination as string, scale);
  }

  return bccomp(effective, '0') < 0 ? zero : effective;
}

/** Positive shortfall `total - tendered`, clamped at zero. */
export function computeShortfall(total: string, tendered: string, scale: number): string {
  const raw = bcsub(bcformat(total, scale), bcformat(tendered, scale), scale);
  return bccomp(raw, '0') > 0 ? raw : bcformat('0', scale);
}

/** Positive change `tendered - total`, clamped at zero. */
export function computeChange(total: string, tendered: string, scale: number): string {
  const raw = bcsub(bcformat(tendered, scale), bcformat(total, scale), scale);
  return bccomp(raw, '0') > 0 ? raw : bcformat('0', scale);
}

/** Sum of the legs whose method is cash, at currency scale. */
export function sumCashLegs(
  legs: readonly TenderLeg[],
  isCashMethodCode: (code: string) => boolean,
  scale: number,
): string {
  let total = bcformat('0', scale);
  for (const leg of legs) {
    if (isCashMethodCode(leg.methodCode)) {
      total = bcadd(total, leg.amount, scale);
    }
  }
  return total;
}

/** Absolute magnitude helper used by the aggregate binds. */
export function magnitude(value: string, scale: number): string {
  return bcabs(value, scale);
}
```
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/payment/__tests__/cashRounding.test.ts`
- [ ] Expected: all describe blocks green (rounding table incl. both ties, the D/2 sweep, denomination validity incl. `0.0025` and the caps, the cash-only union incl. MEAL_VOUCHER and voucher-partial, the tolerance floor cases).
- [ ] Run the decimal regression: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/__tests__/decimal.test.ts`
- [ ] Commit: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding && git add apps/pos/src/lib/decimal.ts apps/pos/src/lib/payment/cashRounding.ts apps/pos/src/lib/payment/__tests__/cashRounding.test.ts && git commit -m "$(cat <<'EOF'
POS cash rounding T4: pure cashRounding module + bcmod

roundCashTotal/computeRoundingAdjustment (ties up, |adj| <= D/2),
isValidDenomination (round-trip representability + static caps),
isCashOnlyTender over the leg union, and toleranceEffectiveMax with the
denomination floor. All string-in/string-out, no global state.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"`

---

### Task 5: Single-sourced cart total — `computeExactCartTotal`, `parseFloat` removal, string props

**Files:**
- `apps/pos/src/lib/payment/cartTotals.ts` (NEW) — the single exported total function.
- `apps/pos/src/stores/paymentStore.ts:426-456` — `estimateCartTotal` becomes a thin delegate (it stays exported: `paymentStore.tenderSufficiency.test.ts:26` imports it).
- `apps/pos/src/lib/offline/receiptService.ts:270-284` (inline discount math at DEFAULT scale 3 — the divergence), `:252` and `:575` (`parseFloat` on money), `:96-106` (`OfflineReceiptResult.changeDue: number`).
- `apps/pos/src/components/organisms/CashPaymentScreen/CashPaymentScreen.tsx:32-40` (props `total: number`, `discountAmount?: number`), `:67`, `:70-78`, `:98`, `:138`, `:146`.
- `apps/pos/src/pages/HomePage.tsx:1620-1621` — pass `totalString()` / `discountAmountString()` (already on `cartStore`: `:665-676`, `:691-704`, `:711-716`).
- `apps/pos/src/stores/paymentStore.ts:195` (`changeDue: number`), `:365`, `:952`, `:1206`; `apps/pos/src/components/pos/CheckoutSuccessModal.tsx:22,140,145`.
- `apps/pos/src/lib/payment/__tests__/cartTotals.test.ts` (NEW).

**Interfaces:**
- Produces: `computeExactCartTotal(cartItems: CartItem[], transactionDiscount: CartTransactionDiscount | undefined, currency: string): string` — subtotal via `bcsum(line_total, decimals)`, percentage discount computed at `decimals` (NOT the default scale 3), discount clamped to subtotal, total clamped at zero. Returns a currency-scale string.
- Changes: `estimateCartTotal(cartItems, transactionDiscount?, currency = 'EUR'): string` → `return computeExactCartTotal(cartItems, transactionDiscount, currency)` (signature unchanged).
- Changes: `OfflineReceiptResult.changeDue: string` (was `number`); `PaymentState.changeDue: string` (initial `'0'`); `CheckoutSuccessModalProps.changeDue: string`.
- Changes: `CashPaymentScreenProps.total: string`, `CashPaymentScreenProps.discountAmount?: string`.
- Consumes: `getCurrencyDecimals` from `@/lib/currency`; `bccomp/bcsub/bcmul/bcdiv/bcsum/bcformat` from `@/lib/decimal`.

**Why unification, not detection:** `estimateCartTotal` computes the percentage discount at `decimals`; `receiptService.ts:272-283` computed it at the DEFAULT scale 3. On a scale-2 currency the two disagree by a sub-cent, which is exactly the input to a rounding tie — the gate and the signed total could pick different sides. One function, both callers.

Steps:

- [ ] Write the failing test `apps/pos/src/lib/payment/__tests__/cartTotals.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { computeExactCartTotal } from '@/lib/payment/cartTotals';
import { estimateCartTotal } from '@/stores/paymentStore';
import { makeCartItem } from '@/test/helpers';

describe('computeExactCartTotal', () => {
  it('EUR percentage discount agrees byte-for-byte with the store estimator', () => {
    // 3 x 3.33 EUR = 9.99; 7% = 0.6993.
    //   at scale 2 (correct): 0.70  -> total 9.29
    //   at scale 3 (the old receiptService path): 0.699 -> total 9.291
    // The disagreement is a rounding-tie input, so both callers MUST agree.
    const items = [
      makeCartItem({ id: 'i1', line_total: '3.33' }),
      makeCartItem({ id: 'i2', line_total: '3.33' }),
      makeCartItem({ id: 'i3', line_total: '3.33' }),
    ];
    const discount = { type: 'percentage' as const, value: '7' };

    expect(computeExactCartTotal(items, discount, 'EUR')).toBe('9.29');
    expect(estimateCartTotal(items, discount, 'EUR')).toBe(
      computeExactCartTotal(items, discount, 'EUR'),
    );
  });

  it('TND scale 3 keeps millime precision', () => {
    const items = [makeCartItem({ id: 'i1', line_total: '9.997' })];
    expect(computeExactCartTotal(items, undefined, 'TND')).toBe('9.997');
  });

  it('clamps an over-large discount to the subtotal and never returns a negative total', () => {
    const items = [makeCartItem({ id: 'i1', line_total: '5.000' })];
    expect(computeExactCartTotal(items, { type: 'fixed', value: '9.000' }, 'TND')).toBe('0.000');
  });

  it('ignores a zero, negative or non-numeric discount value', () => {
    const items = [makeCartItem({ id: 'i1', line_total: '7.500' })];
    expect(computeExactCartTotal(items, { type: 'fixed', value: '0' }, 'TND')).toBe('7.500');
    expect(computeExactCartTotal(items, { type: 'fixed', value: 'abc' }, 'TND')).toBe('7.500');
  });
});
```
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/payment/__tests__/cartTotals.test.ts`
- [ ] Expected fail: `Failed to resolve import "@/lib/payment/cartTotals"`.
- [ ] Implement — `apps/pos/src/lib/payment/cartTotals.ts`:
```ts
import { getCurrencyDecimals } from '@/lib/currency';
import { bccomp, bcdiv, bcformat, bcmul, bcsub, bcsum } from '@/lib/decimal';
import type { CartItem } from '@/types/cart';
import type { CartTransactionDiscount } from '@/stores/cartStore';

/**
 * THE cart total (spec 2026-07-27 §4.3, r2 F4). Both the checkout gate
 * (paymentStore.estimateCartTotal) and the fiscal authoring path
 * (offline/receiptService) call this ONE function, so a scale-2 percentage
 * discount can no longer produce two different "exact totals" — a divergence
 * that lands on either side of a rounding tie.
 *
 * All arithmetic at the CURRENCY scale (never the bc* default of 3).
 */
export function computeExactCartTotal(
  cartItems: CartItem[],
  transactionDiscount: CartTransactionDiscount | undefined,
  currency: string,
): string {
  const decimals = getCurrencyDecimals(currency);
  const subtotal = bcsum(cartItems.map((item) => item.line_total), decimals);
  if (transactionDiscount === undefined) {
    return subtotal;
  }

  // Stay in the decimal domain: safeBig maps '' to 0, the catch covers a
  // non-numeric value (treated as no discount).
  let discountIsPositive = false;
  try {
    discountIsPositive = bccomp(transactionDiscount.value, '0') > 0;
  } catch {
    discountIsPositive = false;
  }
  if (!discountIsPositive) {
    return subtotal;
  }

  const rawDiscount = transactionDiscount.type === 'percentage'
    ? bcdiv(bcmul(subtotal, transactionDiscount.value, decimals), '100', decimals)
    : transactionDiscount.value;
  const discount = bccomp(rawDiscount, subtotal) > 0 ? subtotal : rawDiscount;
  const total = bcsub(subtotal, discount, decimals);
  return bccomp(total, '0') < 0 ? bcformat('0', decimals) : total;
}

/**
 * The transaction-discount amount actually applied by {@link computeExactCartTotal},
 * at currency scale. Kept beside the total so the receipt row, the signed
 * payload and the gate can never disagree on which discount was used.
 */
export function computeExactDiscountAmount(
  cartItems: CartItem[],
  transactionDiscount: CartTransactionDiscount | undefined,
  currency: string,
): string {
  const decimals = getCurrencyDecimals(currency);
  const subtotal = bcsum(cartItems.map((item) => item.line_total), decimals);
  if (transactionDiscount === undefined) {
    return bcformat('0', decimals);
  }
  let discountIsPositive = false;
  try {
    discountIsPositive = bccomp(transactionDiscount.value, '0') > 0;
  } catch {
    discountIsPositive = false;
  }
  if (!discountIsPositive) {
    return bcformat('0', decimals);
  }
  const rawDiscount = transactionDiscount.type === 'percentage'
    ? bcdiv(bcmul(subtotal, transactionDiscount.value, decimals), '100', decimals)
    : transactionDiscount.value;
  return bccomp(rawDiscount, subtotal) > 0 ? subtotal : bcformat(rawDiscount, decimals);
}
```
- [ ] Implement — `src/stores/paymentStore.ts`, replace the whole body of `estimateCartTotal` (`:426-456`) with the delegate, keeping the export and signature:
```ts
/** @internal Exported for unit testing. Delegates to the single-sourced total. */
export function estimateCartTotal(
  cartItems: CartItem[],
  transactionDiscount?: CartTransactionDiscount,
  currency: string = 'EUR',
): string {
  return computeExactCartTotal(cartItems, transactionDiscount, currency);
}
```
  …and add the import beside the other `@/lib/payment` imports:
```ts
import { computeExactCartTotal } from '@/lib/payment/cartTotals';
```
- [ ] Implement — `src/lib/offline/receiptService.ts`, replace `:270-284` (the `transactionDiscountAmount` / `rawTotal` / `total` block) with:
```ts
  // Single-sourced (spec §4.3 r2 F4): the discount + total come from the SAME
  // function the checkout gate used, at the CURRENCY scale.
  const transactionDiscountAmount = computeExactDiscountAmount(
    input.cartItems,
    input.transactionDiscount,
    input.currency,
  );
  const total = computeExactCartTotal(
    input.cartItems,
    input.transactionDiscount,
    input.currency,
  );
```
  …and add the import:
```ts
import { computeExactCartTotal, computeExactDiscountAmount } from '@/lib/payment/cartTotals';
```
- [ ] Implement — same file, `:252`: replace `changeDue: parseFloat(existing.change_due ?? '0'),` with:
```ts
        changeDue: existing.change_due ?? bcformat('0', getCurrencyDecimals(existing.currency)),
```
- [ ] Implement — same file, `:575`: replace `changeDue: parseFloat(changeDueFormatted),` with:
```ts
    changeDue: changeDueFormatted,
```
- [ ] Implement — same file, `:102`: `changeDue: number;` → `changeDue: string;` in `OfflineReceiptResult`.
- [ ] Implement — `src/stores/paymentStore.ts`: `:195` `changeDue: number;` → `changeDue: string;`; `:365` `changeDue: 0,` → `changeDue: '0',`; `:1046`, `:1270`, `:1342`, `:1396` `changeDue: 0` → `changeDue: '0'`. Lines `:952` / `:1206` already assign `result.changeDue` and need no edit.
- [ ] Implement — `src/components/pos/CheckoutSuccessModal.tsx`: `:22` `changeDue: number;` → `changeDue: string;`; `:140` `{changeDue > 0 && (` → `{bccomp(changeDue, '0') > 0 && (` and add `import { bccomp } from '@/lib/decimal';`.
- [ ] Implement — `src/components/organisms/CashPaymentScreen/CashPaymentScreen.tsx`: props to strings and drop every `String(...)` bridge:
```ts
export interface CashPaymentScreenProps {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: (tenderedAmount: string) => void;
  /** Currency-scale decimal string — NEVER a JS number (money must not cross a float boundary). */
  total: string;
  /** Currency-scale decimal string. */
  discountAmount?: string;
  isProcessing: boolean;
  error?: string | null;
}
```
  `:67` → `const totalStr = bcformat(total, decimals);`
  `:70-73` → ``const handleExact = useCallback(() => { setTenderedStr(bcformat(total, decimals)); setPresetSet(true); }, [total, decimals]);``
  `:75-78` → `const handleDenomination = useCallback((amount: number) => { setTenderedStr(bcformat(String(amount), decimals)); setPresetSet(true); }, [decimals]);` (unchanged — `amount` is a UI denomination button value, not receipt money)
  `:98` → `const denominations = getDenominations(currency, Number(total));` (the denomination-button helper takes a number; the value never re-enters money math)
  `:138` → `{format(totalStr)}`
  `:141` → `{discountAmount != null && bccomp(discountAmount, '0') > 0 && (`
  `:146` → `-{format(discountAmount)}`
- [ ] Implement — `src/pages/HomePage.tsx:1620-1621`: `total={total()}` → `total={totalString()}`, `discountAmount={discountAmount()}` → `discountAmount={discountAmountString()}`; add the two selectors beside the existing `discountAmount` selector (`:132`):
```ts
  const totalString = useCartStore((s) => s.totalString);
  const discountAmountString = useCartStore((s) => s.discountAmountString);
```
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/payment/__tests__/cartTotals.test.ts`
- [ ] Expected: 4 passing.
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm typecheck` — fix every reported `changeDue` / `total` prop site (tests included) by passing decimal strings.
- [ ] Run the affected suites: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/offline/__tests__/receiptService.test.ts src/stores/__tests__/paymentStore.tenderSufficiency.test.ts src/components/organisms/CashPaymentScreen/__tests__/CashPaymentScreen.test.tsx`
- [ ] Commit: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding && git add apps/pos/src/lib/payment/cartTotals.ts apps/pos/src/lib/payment/__tests__/cartTotals.test.ts apps/pos/src/stores/paymentStore.ts apps/pos/src/lib/offline/receiptService.ts apps/pos/src/components/organisms/CashPaymentScreen/CashPaymentScreen.tsx apps/pos/src/components/pos/CheckoutSuccessModal.tsx apps/pos/src/pages/HomePage.tsx && git commit -m "$(cat <<'EOF'
POS cash rounding T5: single-sourced cart total, no parseFloat on money

computeExactCartTotal/computeExactDiscountAmount are now the only cart-total
math; estimateCartTotal delegates and receiptService stops computing the
percentage discount at the default scale. changeDue becomes a decimal string
end-to-end and CashPaymentScreen takes string money props.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"`

---

### Task 6: `CheckoutPolicySnapshot` + quick-cash path (selection, gate, floor, UI)

**Files:**
- `apps/pos/src/lib/payment/checkoutPolicySnapshot.ts` (NEW).
- `apps/pos/src/stores/paymentStore.ts:855-858` (quick-cash method selection), `:874-879` (the hard tender gate), `:935-950` (`createReceiptLocalFirst` call), `:189-238` (`PaymentState` — add the per-shift auto-accept counter), `:1230-1232` (account-payment cash-method selection).
- `apps/pos/src/components/organisms/CashPaymentScreen/CashPaymentScreen.tsx:16-30` (`computeCashTenderState`) and the amount panel.
- `apps/pos/src/pages/HomePage.tsx:1616-1623` — pass the snapshot-derived rounded due into the cash screen.
- `apps/pos/src/lib/payment/__tests__/checkoutPolicySnapshot.test.ts` (NEW), `apps/pos/src/stores/__tests__/paymentStore.cashRounding.test.ts` (NEW).

**Interfaces:**
- Produces:
```ts
export type ToleranceReason =
  | 'not_applicable' | 'disabled' | 'accepted' | 'exceeds_max' | 'shift_limit_reached';

export interface ToleranceDecision {
  readonly applied: boolean;
  readonly shortfall: string;
  readonly effectiveMax: string;
  readonly reason: ToleranceReason;
}

export interface CheckoutPolicySnapshot {
  readonly currency: string;
  readonly scale: number;
  readonly exactTotal: string;
  readonly roundedTotal: string;
  readonly adjustment: string;
  readonly denomination: string;
  readonly roundingApplied: boolean;
  readonly cashOnly: boolean;
  readonly toleranceDecision: ToleranceDecision;
  readonly fiscalSchemaVersion: number | null;
  readonly policyRefreshedAt: string | null;
}

export function buildCheckoutPolicySnapshot(input: {
  readonly exactTotal: string;
  readonly currency: string;
  readonly legs: readonly TenderLeg[];
  readonly tenderedAmount: string;
  readonly isCashMethodCode: (code: string) => boolean;
  readonly policy: PaymentPolicy | null;
  readonly fiscalSchemaVersion: number | null;
  readonly isTraining: boolean;
  readonly autoAcceptCountThisShift: number;
}): CheckoutPolicySnapshot
```
- Produces in `paymentStore`: `makeIsCashMethodCode(methods: readonly PaymentMethod[]): (code: string) => boolean`; state `toleranceAutoAcceptShiftId: string | null`, `toleranceAutoAcceptCount: number`; action `recordToleranceAutoAccept(shiftId: string): void`.
- Changes: `computeCashTenderState(tenderedStr, totalStr, decimals)` keeps its exact signature — the CALLER now passes the ROUNDED due plus an optional in-tolerance floor:
```ts
export function computeCashTenderState(
  tenderedStr: string,
  totalStr: string,
  decimals: number,
  minimumAcceptable?: string,
): { changeDue: string; isValid: boolean }
```
- Consumes: `roundCashTotal`, `computeRoundingAdjustment`, `isValidDenomination`, `isCashOnlyTender`, `toleranceEffectiveMax`, `computeShortfall`, `TOLERANCE_AUTO_ACCEPT_LIMIT_PER_SHIFT` from `@/lib/payment/cashRounding`; `getActivePaymentPolicy` from `@/stores/paymentPolicyStore`; `useTerminalStore.getState().terminal.fiscal_schema_version` (`terminalStore.ts:43`).

**Gate (normative, spec §4.1):** rounding applies IFF `policy !== null` AND `policy.cashRoundingEnabled` AND `isValidDenomination(policy.cashRoundingDenomination, scale)` AND `cashOnly` AND invoice type ∈ {SALE, TRAINING} AND `fiscalSchemaVersion === 3`. Anything else ⇒ `roundedTotal = exactTotal`, `adjustment = bcformat('0', scale)`, `denomination = bcformat('0', scale)`, `roundingApplied = false`.

Steps:

- [ ] Write the failing test `apps/pos/src/lib/payment/__tests__/checkoutPolicySnapshot.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { buildCheckoutPolicySnapshot } from '@/lib/payment/checkoutPolicySnapshot';
import type { PaymentPolicy } from '@/stores/paymentPolicyStore';

const isCash = (code: string) => code === 'CASH';

const policy: PaymentPolicy = {
  cashRoundingEnabled: true,
  cashRoundingDenomination: '0.050',
  tenderToleranceEnabled: true,
  tenderTolerancePercentage: '0.0050',
  tenderToleranceMaxAmount: '0.100',
  currencyCode: 'TND',
  currencyScale: 3,
  refreshedAt: '2026-07-27 08:00:00',
};

function build(overrides: Partial<Parameters<typeof buildCheckoutPolicySnapshot>[0]> = {}) {
  return buildCheckoutPolicySnapshot({
    exactTotal: '9.997',
    currency: 'TND',
    legs: [{ methodCode: 'CASH', amount: '10.000' }],
    tenderedAmount: '10.000',
    isCashMethodCode: isCash,
    policy,
    fiscalSchemaVersion: 3,
    isTraining: false,
    autoAcceptCountThisShift: 0,
    ...overrides,
  });
}

describe('buildCheckoutPolicySnapshot — rounding gate', () => {
  it('rounds a cash-only sale on a fiscal_schema_version 3 terminal', () => {
    const s = build();
    expect(s.roundingApplied).toBe(true);
    expect(s.roundedTotal).toBe('10.000');
    expect(s.adjustment).toBe('0.003');
    expect(s.denomination).toBe('0.050');
  });

  it('does NOT round on a fiscal_schema_version 2 terminal (the cutover gate)', () => {
    const s = build({ fiscalSchemaVersion: 2 });
    expect(s.roundingApplied).toBe(false);
    expect(s.roundedTotal).toBe('9.997');
    expect(s.adjustment).toBe('0.000');
    expect(s.denomination).toBe('0.000');
  });

  it('does NOT round when the terminal version is unknown (fail-closed)', () => {
    expect(build({ fiscalSchemaVersion: null }).roundingApplied).toBe(false);
  });

  it('does NOT round without a policy, with rounding disabled, or with a bad denomination', () => {
    expect(build({ policy: null }).roundingApplied).toBe(false);
    expect(build({ policy: { ...policy, cashRoundingEnabled: false } }).roundingApplied).toBe(false);
    expect(build({ policy: { ...policy, cashRoundingDenomination: '0.0025' } }).roundingApplied)
      .toBe(false);
    expect(build({ policy: { ...policy, cashRoundingDenomination: null } }).roundingApplied)
      .toBe(false);
  });

  it('does NOT round a mixed tender (voucher leg present)', () => {
    const s = build({
      legs: [
        { methodCode: 'CASH', amount: '5.000' },
        { methodCode: 'store_voucher', amount: '5.000' },
      ],
    });
    expect(s.cashOnly).toBe(false);
    expect(s.roundingApplied).toBe(false);
    expect(s.roundedTotal).toBe('9.997');
  });

  it('rounds a TRAINING sale (invoice_type_code TRAINING is in scope)', () => {
    expect(build({ isTraining: true }).roundingApplied).toBe(true);
  });
});

describe('buildCheckoutPolicySnapshot — tolerance decision', () => {
  it('auto-accepts a shortfall inside the denomination floor', () => {
    // exact 9.973 -> rounded 9.950; tendered 9.900 -> shortfall 0.050.
    // pct cap 0.049, max 0.100 -> min 0.049; floor D=0.050 lifts it to 0.050.
    const s = build({ exactTotal: '9.973', tenderedAmount: '9.900',
      legs: [{ methodCode: 'CASH', amount: '9.900' }] });
    expect(s.roundedTotal).toBe('9.950');
    expect(s.toleranceDecision).toEqual({
      applied: true,
      shortfall: '0.050',
      effectiveMax: '0.050',
      reason: 'accepted',
    });
  });

  it('refuses a shortfall beyond the effective max (PIN path stays the escape hatch)', () => {
    const s = build({ exactTotal: '9.973', tenderedAmount: '9.800',
      legs: [{ methodCode: 'CASH', amount: '9.800' }] });
    expect(s.toleranceDecision.applied).toBe(false);
    expect(s.toleranceDecision.reason).toBe('exceeds_max');
  });

  it('escalates to the PIN path once the per-shift auto-accept limit is reached', () => {
    const s = build({ exactTotal: '9.973', tenderedAmount: '9.900',
      legs: [{ methodCode: 'CASH', amount: '9.900' }], autoAcceptCountThisShift: 10 });
    expect(s.toleranceDecision.applied).toBe(false);
    expect(s.toleranceDecision.reason).toBe('shift_limit_reached');
  });

  it('never auto-accepts a non-cash-only tender', () => {
    const s = build({
      exactTotal: '10.000',
      tenderedAmount: '9.960',
      legs: [{ methodCode: 'CARD', amount: '9.960' }],
    });
    expect(s.toleranceDecision.applied).toBe(false);
    expect(s.toleranceDecision.reason).toBe('not_applicable');
  });

  it('never auto-accepts when pos tolerance is disabled', () => {
    const s = build({
      policy: { ...policy, tenderToleranceEnabled: false },
      exactTotal: '9.973',
      tenderedAmount: '9.900',
      legs: [{ methodCode: 'CASH', amount: '9.900' }],
    });
    expect(s.toleranceDecision.applied).toBe(false);
    expect(s.toleranceDecision.reason).toBe('disabled');
  });

  it('reports no shortfall when the tender covers the rounded total', () => {
    const s = build();
    expect(s.toleranceDecision).toEqual({
      applied: false,
      shortfall: '0.000',
      effectiveMax: '0.050',
      reason: 'not_applicable',
    });
  });
});
```
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/payment/__tests__/checkoutPolicySnapshot.test.ts`
- [ ] Expected fail: `Failed to resolve import "@/lib/payment/checkoutPolicySnapshot"`.
- [ ] Implement — `apps/pos/src/lib/payment/checkoutPolicySnapshot.ts`:
```ts
import { getCurrencyDecimals } from '@/lib/currency';
import { bccomp, bcformat } from '@/lib/decimal';
import {
  computeRoundingAdjustment,
  computeShortfall,
  isCashOnlyTender,
  isValidDenomination,
  roundCashTotal,
  toleranceEffectiveMax,
  TOLERANCE_AUTO_ACCEPT_LIMIT_PER_SHIFT,
  type TenderLeg,
} from '@/lib/payment/cashRounding';
import type { PaymentPolicy } from '@/stores/paymentPolicyStore';

export type ToleranceReason =
  | 'not_applicable'
  | 'disabled'
  | 'accepted'
  | 'exceeds_max'
  | 'shift_limit_reached';

export interface ToleranceDecision {
  readonly applied: boolean;
  readonly shortfall: string;
  readonly effectiveMax: string;
  readonly reason: ToleranceReason;
}

/**
 * The sealed decision for ONE checkout attempt (spec §4.3).
 *
 * Built ONCE at tender time and threaded through
 * `createReceiptLocalFirst -> createOfflineReceipt -> buildSaleReceiptV3Payload`.
 * What signs is the snapshot: a policy tick mid-sale can never move the total
 * between the gate and the signature.
 */
export interface CheckoutPolicySnapshot {
  readonly currency: string;
  readonly scale: number;
  readonly exactTotal: string;
  readonly roundedTotal: string;
  readonly adjustment: string;
  readonly denomination: string;
  readonly roundingApplied: boolean;
  readonly cashOnly: boolean;
  readonly toleranceDecision: ToleranceDecision;
  readonly fiscalSchemaVersion: number | null;
  readonly policyRefreshedAt: string | null;
}

export interface BuildCheckoutPolicySnapshotInput {
  readonly exactTotal: string;
  readonly currency: string;
  readonly legs: readonly TenderLeg[];
  readonly tenderedAmount: string;
  readonly isCashMethodCode: (code: string) => boolean;
  readonly policy: PaymentPolicy | null;
  /** `terminal.fiscal_schema_version` as cached by terminalStore; null when unknown. */
  readonly fiscalSchemaVersion: number | null;
  readonly isTraining: boolean;
  readonly autoAcceptCountThisShift: number;
}

export function buildCheckoutPolicySnapshot(
  input: BuildCheckoutPolicySnapshotInput,
): CheckoutPolicySnapshot {
  const scale = getCurrencyDecimals(input.currency);
  const zero = bcformat('0', scale);
  const exactTotal = bcformat(input.exactTotal, scale);
  const cashOnly = isCashOnlyTender(input.legs, input.isCashMethodCode);

  // ── Rounding gate (spec §4.1, fail-closed on every unknown) ──────────────
  // invoice_type_code is SALE or TRAINING for every path that reaches here
  // (refund/void authoring is out of scope), so `isTraining` only selects
  // between the two in-scope codes and never disables rounding.
  const denominationCandidate = input.policy?.cashRoundingDenomination ?? null;
  const roundingApplied =
    input.policy !== null
    && input.policy.cashRoundingEnabled
    && isValidDenomination(denominationCandidate, scale)
    && cashOnly
    && input.fiscalSchemaVersion === 3;

  const denomination = roundingApplied
    ? bcformat(denominationCandidate as string, scale)
    : zero;
  const roundedTotal = roundingApplied
    ? roundCashTotal(exactTotal, denomination, scale)
    : exactTotal;
  const adjustment = roundingApplied
    ? computeRoundingAdjustment(exactTotal, roundedTotal, scale)
    : zero;

  // ── Tolerance decision (evaluated against the ROUNDED due) ───────────────
  const shortfall = computeShortfall(roundedTotal, input.tenderedAmount, scale);
  const effectiveMax = toleranceEffectiveMax({
    exactTotal,
    percentage: input.policy?.tenderTolerancePercentage ?? '0',
    maxAmount: input.policy?.tenderToleranceMaxAmount ?? '0',
    denomination: denominationCandidate,
    roundingActive: roundingApplied,
    scale,
  });

  const toleranceDecision = decideTolerance({
    cashOnly,
    enabled: input.policy?.tenderToleranceEnabled === true,
    shortfall,
    effectiveMax,
    autoAcceptCountThisShift: input.autoAcceptCountThisShift,
  });

  return {
    currency: input.currency,
    scale,
    exactTotal,
    roundedTotal,
    adjustment,
    denomination,
    roundingApplied,
    cashOnly,
    toleranceDecision,
    fiscalSchemaVersion: input.fiscalSchemaVersion,
    policyRefreshedAt: input.policy?.refreshedAt ?? null,
  };
}

function decideTolerance(input: {
  cashOnly: boolean;
  enabled: boolean;
  shortfall: string;
  effectiveMax: string;
  autoAcceptCountThisShift: number;
}): ToleranceDecision {
  const base = { shortfall: input.shortfall, effectiveMax: input.effectiveMax };
  if (bccomp(input.shortfall, '0') <= 0) {
    return { ...base, applied: false, reason: 'not_applicable' };
  }
  if (!input.cashOnly) {
    return { ...base, applied: false, reason: 'not_applicable' };
  }
  if (!input.enabled) {
    return { ...base, applied: false, reason: 'disabled' };
  }
  if (bccomp(input.shortfall, input.effectiveMax) > 0) {
    return { ...base, applied: false, reason: 'exceeds_max' };
  }
  if (input.autoAcceptCountThisShift >= TOLERANCE_AUTO_ACCEPT_LIMIT_PER_SHIFT) {
    return { ...base, applied: false, reason: 'shift_limit_reached' };
  }
  return { ...base, applied: true, reason: 'accepted' };
}
```
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/payment/__tests__/checkoutPolicySnapshot.test.ts`
- [ ] Expected: 13 passing.
- [ ] Write the failing store test `apps/pos/src/stores/__tests__/paymentStore.cashRounding.test.ts` — copy the module mocks and `setTndAuthState()` helper verbatim from `paymentStore.tenderSufficiency.test.ts:33-112`, then:
```ts
describe('processCashCheckout — is_cash_tender selection + rounded gate', () => {
  it('selects the cash method by is_cash_tender, not by is_physical/has_maturity', async () => {
    usePaymentStore.setState({
      paymentMethods: [
        makePaymentMethod({
          id: 'pm-meal', code: 'MEAL_VOUCHER',
          is_physical: true, has_maturity: false, is_cash_tender: false, position: 0,
        }),
        makePaymentMethod({ id: 'pm-cash', code: 'CASH', is_cash_tender: true, position: 1 }),
      ],
      paymentRepositories: [makePaymentRepository({ id: 'repo-cash', type: 'cash_register' })],
    });

    await usePaymentStore.getState().processCashCheckout(
      'term-1', [makeCartItem({ id: 'i1', line_total: '10.000' })], '10.000',
    );

    const { createOfflineReceipt } = await import('@/lib/offline/receiptService');
    expect(vi.mocked(createOfflineReceipt).mock.calls[0]?.[1].payments[0]?.methodCode)
      .toBe('CASH');
  });

  it('throws errors.noCashMethod when no method carries is_cash_tender', async () => {
    usePaymentStore.setState({
      paymentMethods: [makePaymentMethod({ id: 'pm-meal', code: 'MEAL_VOUCHER', is_cash_tender: false })],
      paymentRepositories: [makePaymentRepository({ id: 'repo-cash', type: 'cash_register' })],
    });

    await expect(
      usePaymentStore.getState().processCashCheckout(
        'term-1', [makeCartItem({ id: 'i1', line_total: '10.000' })], '10.000',
      ),
    ).rejects.toThrow();
    expect(usePaymentStore.getState().error).toContain('cash payment method');
  });

  it('accepts a tender that covers the ROUNDED total but not the exact total', async () => {
    // exact 9.997 -> rounded 10.000 on a v3 terminal; but 9.950 < 9.997 and
    // 9.950 < 10.000, so this asserts the reverse direction: exact 9.973 ->
    // rounded 9.950, tender 9.950 is now sufficient though it is 0.023 short
    // of the exact total.
    usePaymentPolicyStore.getState().setPolicy(tndRoundingPolicy);
    usePaymentStore.setState({
      paymentMethods: [makePaymentMethod({ id: 'pm-cash', code: 'CASH', is_cash_tender: true })],
      paymentRepositories: [makePaymentRepository({ id: 'repo-cash', type: 'cash_register' })],
    });

    await expect(
      usePaymentStore.getState().processCashCheckout(
        'term-1', [makeCartItem({ id: 'i1', line_total: '9.973' })], '9.950',
      ),
    ).resolves.toBeUndefined();
  });

  it('auto-accepts an in-tolerance shortfall and increments the per-shift counter', async () => {
    usePaymentPolicyStore.getState().setPolicy(tndRoundingPolicy);
    usePaymentStore.setState({
      paymentMethods: [makePaymentMethod({ id: 'pm-cash', code: 'CASH', is_cash_tender: true })],
      paymentRepositories: [makePaymentRepository({ id: 'repo-cash', type: 'cash_register' })],
    });

    await usePaymentStore.getState().processCashCheckout(
      'term-1', [makeCartItem({ id: 'i1', line_total: '9.973' })], '9.900',
    );

    expect(usePaymentStore.getState().toleranceAutoAcceptCount).toBe(1);
  });

  it('still refuses a beyond-tolerance shortfall from quick cash', async () => {
    usePaymentPolicyStore.getState().setPolicy(tndRoundingPolicy);
    usePaymentStore.setState({
      paymentMethods: [makePaymentMethod({ id: 'pm-cash', code: 'CASH', is_cash_tender: true })],
      paymentRepositories: [makePaymentRepository({ id: 'repo-cash', type: 'cash_register' })],
    });

    await expect(
      usePaymentStore.getState().processCashCheckout(
        'term-1', [makeCartItem({ id: 'i1', line_total: '9.973' })], '9.500',
      ),
    ).rejects.toThrow();
  });
});
```
  …where `tndRoundingPolicy` is the `PaymentPolicy` literal from the snapshot test and the terminal fixture in `beforeEach` carries `fiscal_schema_version: 3`.
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/stores/__tests__/paymentStore.cashRounding.test.ts`
- [ ] Expected fail: the method-selection test picks `MEAL_VOUCHER` (legacy predicate) and `toleranceAutoAcceptCount` does not exist.
- [ ] Implement — `src/stores/paymentStore.ts`, add to `PaymentState` after `selectedCustomer`:
```ts
  /**
   * Per-shift tender-tolerance auto-accept guard (spec §8.1). The counter is
   * scoped to a shift id so closing and reopening a shift resets the budget;
   * beyond TOLERANCE_AUTO_ACCEPT_LIMIT_PER_SHIFT the cashier is escalated to
   * the unchanged manager-PIN path. Surfaced on the EOD preview.
   */
  toleranceAutoAcceptShiftId: string | null;
  toleranceAutoAcceptCount: number;
```
  …to `initialState`:
```ts
  toleranceAutoAcceptShiftId: null,
  toleranceAutoAcceptCount: 0,
```
  …and the action (declared in `PaymentActions`, implemented in the store body):
```ts
  recordToleranceAutoAccept: (shiftId: string) => {
    set((state) =>
      state.toleranceAutoAcceptShiftId === shiftId
        ? { toleranceAutoAcceptCount: state.toleranceAutoAcceptCount + 1 }
        : { toleranceAutoAcceptShiftId: shiftId, toleranceAutoAcceptCount: 1 },
    );
  },
```
- [ ] Implement — same file, the cash-ness resolver (module scope, exported for tests):
```ts
/**
 * Cash-ness resolver over the cached payment methods. `is_cash_tender` is the
 * ONE predicate (spec §4.1); the legacy `is_physical && !has_maturity` shape
 * classified MEAL_VOUCHER as cash.
 */
export function makeIsCashMethodCode(
  methods: readonly PaymentMethod[],
): (code: string) => boolean {
  const cashCodes = new Set(
    methods.filter((m) => m.is_cash_tender && m.is_active).map((m) => m.code),
  );
  return (code: string) => cashCodes.has(code);
}
```
- [ ] Implement — same file, replace the quick-cash method selection (`:856-858`) with:
```ts
    const cashMethod = paymentMethods.find((m) => m.is_cash_tender && m.is_active);
```
  …and make the same replacement in `processAccountPayment` (`:1230-1232`). Both already throw `errors.noCashMethod` when the find misses — that behavior is unchanged.
- [ ] Implement — same file, replace the hard tender gate (`:874-879`) with the snapshot build + rounded/tolerance gate:
```ts
    const currency = getActiveCurrency();
    const scale = getCurrencyDecimals(currency);
    const terminalSnapshot = useTerminalStore.getState();
    const snapshot = buildCheckoutPolicySnapshot({
      exactTotal: computeExactCartTotal(cartItems, transactionDiscount, currency),
      currency,
      legs: [{ methodCode: cashMethod.code, amount: bcformat(tenderedAmount, scale) }],
      tenderedAmount: bcformat(tenderedAmount, scale),
      isCashMethodCode: makeIsCashMethodCode(paymentMethods),
      policy: getActivePaymentPolicy(),
      fiscalSchemaVersion: terminalSnapshot.terminal?.fiscal_schema_version ?? null,
      isTraining: terminalSnapshot.terminal?.is_training_mode === true,
      autoAcceptCountThisShift:
        get().toleranceAutoAcceptShiftId === (terminalSnapshot.shift?.id ?? null)
          ? get().toleranceAutoAcceptCount
          : 0,
    });

    if (
      bccomp(bcformat(tenderedAmount, scale), snapshot.roundedTotal) < 0
      && !snapshot.toleranceDecision.applied
    ) {
      const msg = i18n.t('payment.tenderBelowDue', { ns: 'pos' });
      set({ error: msg });
      throw new Error(msg);
    }
```
- [ ] Implement — same file, thread the snapshot into the receipt call (`:935-950`) by adding a `policySnapshot` argument to `createReceiptLocalFirst` (new last parameter, forwarded into `createOfflineReceipt` in Task 9) and record the auto-accept after a successful create:
```ts
      if (snapshot.toleranceDecision.applied && terminalSnapshot.shift) {
        get().recordToleranceAutoAccept(terminalSnapshot.shift.id);
      }
      set({ changeDue: result.changeDue, isProcessing: false });
```
- [ ] Implement — `CashPaymentScreen.tsx`, widen the pure helper so an in-tolerance tender enables Confirm:
```ts
export function computeCashTenderState(
  tenderedStr: string,
  totalStr: string,
  decimals: number,
  minimumAcceptable?: string,
): { changeDue: string; isValid: boolean } {
  if (!tenderedStr) {
    return { changeDue: bcformat('0', decimals), isValid: false };
  }
  const floor = minimumAcceptable ?? totalStr;
  const isValid = bccomp(tenderedStr, floor) >= 0;
  const cmp = bccomp(tenderedStr, totalStr);
  const changeDue = cmp > 0
    ? bcsub(tenderedStr, totalStr, decimals)
    : bcformat('0', decimals);
  return { changeDue, isValid };
}
```
- [ ] Implement — `CashPaymentScreen.tsx`, add the rounding line to the navy amount panel, immediately after the discount block (`:141-148`):
```tsx
          {roundingAdjustment != null && bccomp(roundingAdjustment, '0') !== 0 && (
            <div className="mt-4 text-center">
              <p className="text-xs font-medium uppercase tracking-widest text-pay-navy-fg/70">
                {t('cashPayment.rounding')}
              </p>
              <p className="mt-1 font-mono text-lg font-bold tabular-nums text-pay-navy-fg/90">
                {format(roundingAdjustment)}
              </p>
            </div>
          )}
```
  …with the two new optional props (`roundingAdjustment?: string`, `minimumAcceptable?: string`) added to `CashPaymentScreenProps` and `minimumAcceptable` passed into `computeCashTenderState` at `:68`. `total` is now the ROUNDED due supplied by the caller.
- [ ] Implement — `src/pages/HomePage.tsx`, compute the display snapshot for the open cash screen and pass it down:
```tsx
      <CashPaymentScreen
        isOpen={showCashModal}
        onClose={() => setShowCashModal(false)}
        onConfirm={(amount) => void handleCashConfirm(amount)}
        total={cashScreenSnapshot.roundedTotal}
        discountAmount={discountAmountString()}
        roundingAdjustment={cashScreenSnapshot.adjustment}
        minimumAcceptable={cashScreenSnapshot.minimumAcceptable}
        isProcessing={isProcessing}
        error={paymentError}
      />
```
  …where `cashScreenSnapshot` is a `useMemo` over `buildCheckoutPolicySnapshot` with a single synthetic CASH leg for the full rounded due, `minimumAcceptable = bcsub(roundedTotal, toleranceDecision.applied ? toleranceDecision.effectiveMax : '0', scale)` clamped at zero. The authoritative snapshot is still the one paymentStore builds at confirm time — this one only drives the display.
- [ ] Implement — i18n `cashPayment.rounding` (`en`: `"Rounding"`, `fr`: `"Arrondi"`) and `payment.tenderBelowDue` (`en`: `"The amount tendered is below the amount due."`, `fr`: `"Le montant remis est inférieur au montant dû."`) in `src/locales/{en,fr}/pos.json`.
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/stores/__tests__/paymentStore.cashRounding.test.ts src/components/organisms/CashPaymentScreen/__tests__/CashPaymentScreen.test.tsx`
- [ ] Expected: green, including the existing CashPaymentScreen suite (the 4th `computeCashTenderState` argument is optional, so its current call sites keep their meaning).
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/stores/__tests__/paymentStore.tenderSufficiency.test.ts src/stores/__tests__/paymentStore.cashTenderedAmount.test.ts`
- [ ] Commit: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding && git add apps/pos/src/lib/payment/checkoutPolicySnapshot.ts apps/pos/src/lib/payment/__tests__/checkoutPolicySnapshot.test.ts apps/pos/src/stores/paymentStore.ts apps/pos/src/stores/__tests__/paymentStore.cashRounding.test.ts apps/pos/src/components/organisms/CashPaymentScreen/CashPaymentScreen.tsx apps/pos/src/pages/HomePage.tsx apps/pos/src/locales/en/pos.json apps/pos/src/locales/fr/pos.json && git commit -m "$(cat <<'EOF'
POS cash rounding T6: CheckoutPolicySnapshot + quick-cash path

Rounding gate is fail-closed on policy, denomination validity, cash-only union
and terminal.fiscal_schema_version === 3. Quick cash selects its method by
is_cash_tender, gates on the rounded due, auto-accepts an in-tolerance
shortfall under a per-shift counter, and shows the rounding line.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"`

---

### Task 7: Advanced checkout — string total, rounded due, change eligibility, tolerance

**Files:**
- `apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx:75-120` (props), `:128-151` (`computeTenderState`), `:203-208` (`chargeEligible` uses `total > 0`), `:265-273` (`useMemo` + `canComplete`), `:709` (`bcformat(String(total), decimals)`), `:767` (`{format(total)}`), `:862-873` (balance rows).
- `apps/pos/src/pages/HomePage.tsx:1637-1650` — `total={total()}` → `total={totalString()}`.
- `apps/pos/src/stores/paymentStore.ts:1063-1220` — `processAdvancedCheckout`: snapshot build over the enriched legs + voucher tenders, rounded due, change-eligibility refusal, in-tolerance auto-accept BEFORE the unchanged PIN branch.
- `apps/pos/src/components/organisms/AdvancedPaymentsModal/__tests__/AdvancedPaymentsModal.test.tsx` — existing suite; add the new cases.
- `apps/pos/src/stores/__tests__/paymentStore.changeEligibility.test.ts` (NEW).

**Interfaces:**
- Changes: `AdvancedPaymentsModalProps.total: string` (was `number`); new optional `roundingAdjustment?: string`.
- Changes: `computeTenderState(paymentLines: readonly { amount: string }[], voucherTenders: readonly { amount: string }[], total: string, decimals: number)` — same return shape `{ totalPaid, remaining, overpayment, isFullyPaid }`; the internal `String(total)` bridge disappears.
- Consumes in `paymentStore`: `buildCheckoutPolicySnapshot`, `makeIsCashMethodCode`, `sumCashLegs`, `computeChange` from Tasks 4/6.
- Produces: error keys `pos:payment.changeExceedsCashLegs`, `pos:payment.tenderBelowDue` (added in Task 6).

**Union rule (spec §4.1):** the cash-only predicate runs over the UNION of `enriched` (the payment lines) and `voucherTenders`. Voucher legs ARE payment legs (`SaleReceiptPayload.ts:120` derives `vouchers_redeemed` from `payments[]`), and they are never cash — so a voucher-partial sale is exact, never rounded.

**PIN path is untouched:** the auto-accept branch runs FIRST; when it declines (`exceeds_max`, `shift_limit_reached`, `disabled`, non-cash) control falls into the existing `paymentStore.ts:1124-1191` block byte-for-byte.

Steps:

- [ ] Write the failing test `apps/pos/src/stores/__tests__/paymentStore.changeEligibility.test.ts` (reuse the mock preamble + `setTndAuthState()` from `paymentStore.tenderSufficiency.test.ts:33-112`, terminal fixture with `fiscal_schema_version: 3`):
```ts
describe('processAdvancedCheckout — change eligibility', () => {
  beforeEach(() => {
    usePaymentStore.setState({
      paymentMethods: [
        makePaymentMethod({ id: 'pm-cash', code: 'CASH', is_cash_tender: true }),
        makePaymentMethod({
          id: 'pm-card', code: 'CARD',
          is_physical: false, requires_third_party: true, is_cash_tender: false,
        }),
      ],
      paymentRepositories: [
        makePaymentRepository({ id: 'repo-cash', type: 'cash_register' }),
        makePaymentRepository({ id: 'repo-bank', type: 'bank_account' }),
      ],
    });
  });

  it('refuses completion when the change owed exceeds the cash legs (card-only over-tender)', async () => {
    await expect(
      usePaymentStore.getState().processAdvancedCheckout(
        'term-1',
        [makeCartItem({ id: 'i1', line_total: '10.000' })],
        [{ payment_method_id: 'pm-card', amount: '12.000', repository_id: 'repo-bank' }],
      ),
    ).rejects.toThrow();
    expect(usePaymentStore.getState().error).toContain('change');
  });

  it('allows an over-tender whose change is fully covered by cash legs', async () => {
    await expect(
      usePaymentStore.getState().processAdvancedCheckout(
        'term-1',
        [makeCartItem({ id: 'i1', line_total: '10.000' })],
        [{ payment_method_id: 'pm-cash', amount: '12.000', repository_id: 'repo-cash' }],
      ),
    ).resolves.toBeUndefined();
  });

  it('does not round a voucher-partial tender (union rule)', async () => {
    usePaymentPolicyStore.getState().setPolicy(tndRoundingPolicy);
    usePaymentStore.getState().addVoucherPayment('V-1', '5.000');

    await usePaymentStore.getState().processAdvancedCheckout(
      'term-1',
      [makeCartItem({ id: 'i1', line_total: '9.973' })],
      [
        { payment_method_id: 'pm-cash', amount: '4.973', repository_id: 'repo-cash' },
        {
          payment_method_id: 'pm-voucher', amount: '5.000', repository_id: 'repo-virtual',
          instrument_type: 'store_voucher', instrument_serial: 'V-1',
        },
      ],
    );

    const { createOfflineReceipt } = await import('@/lib/offline/receiptService');
    const snapshot = vi.mocked(createOfflineReceipt).mock.calls[0]?.[1].policySnapshot;
    expect(snapshot?.roundingApplied).toBe(false);
    expect(snapshot?.roundedTotal).toBe('9.973');
  });

  it('auto-accepts an in-tolerance all-cash shortfall without a PIN', async () => {
    usePaymentPolicyStore.getState().setPolicy(tndRoundingPolicy);

    await expect(
      usePaymentStore.getState().processAdvancedCheckout(
        'term-1',
        [makeCartItem({ id: 'i1', line_total: '9.973' })],
        [{ payment_method_id: 'pm-cash', amount: '9.900', repository_id: 'repo-cash' }],
      ),
    ).resolves.toBeUndefined();

    const { verifyScopedManagerPin } = await import('@/lib/operatorApproval/scopedManagerPin');
    expect(vi.mocked(verifyScopedManagerPin)).not.toHaveBeenCalled();
    expect(usePaymentStore.getState().toleranceAutoAcceptCount).toBe(1);
  });

  it('still demands a PIN for a beyond-tolerance shortfall', async () => {
    usePaymentPolicyStore.getState().setPolicy(tndRoundingPolicy);

    await expect(
      usePaymentStore.getState().processAdvancedCheckout(
        'term-1',
        [makeCartItem({ id: 'i1', line_total: '9.973' })],
        [{ payment_method_id: 'pm-cash', amount: '9.000', repository_id: 'repo-cash' }],
      ),
    ).rejects.toThrow('Tender tolerance requires a manager PIN.');
  });
});
```
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/stores/__tests__/paymentStore.changeEligibility.test.ts`
- [ ] Expected fail: the card-only over-tender resolves (today `totalPaid >= total` is signable) and `policySnapshot` is not on the `createOfflineReceipt` input.
- [ ] Implement — `src/stores/paymentStore.ts`, inside `processAdvancedCheckout` after `const totalEstimate = estimateCartTotal(...)` (`:1121`), replace the shortfall branch's PREAMBLE with the snapshot build (the PIN block itself is untouched):
```ts
      const voucherLegs = get().voucherTenders.map((v) => ({
        methodCode: 'store_voucher',
        amount: bcformat(v.amount, decimals),
      }));
      const unionLegs = [
        ...enriched.map((e) => ({ methodCode: e.methodCode, amount: e.amount })),
        ...voucherLegs,
      ];
      const terminalSnapshot = useTerminalStore.getState();
      const isCashMethodCode = makeIsCashMethodCode(get().paymentMethods);
      const snapshot = buildCheckoutPolicySnapshot({
        exactTotal: totalEstimate,
        currency,
        legs: unionLegs,
        tenderedAmount: tenderedAmountStr,
        isCashMethodCode,
        policy: getActivePaymentPolicy(),
        fiscalSchemaVersion: terminalSnapshot.terminal?.fiscal_schema_version ?? null,
        isTraining: terminalSnapshot.terminal?.is_training_mode === true,
        autoAcceptCountThisShift:
          get().toleranceAutoAcceptShiftId === (terminalSnapshot.shift?.id ?? null)
            ? get().toleranceAutoAcceptCount
            : 0,
      });

      // Change eligibility (spec §4.1): change can only ever come out of the
      // drawer. A card-only over-tender would otherwise sign a receipt whose
      // change exceeds the cash actually collected.
      const changeOwed = computeChange(snapshot.roundedTotal, tenderedAmountStr, decimals);
      const cashLegTotal = sumCashLegs(unionLegs, isCashMethodCode, decimals);
      if (bccomp(changeOwed, cashLegTotal) > 0) {
        const msg = i18n.t('payment.changeExceedsCashLegs', { ns: 'pos' });
        set({ error: msg });
        throw new Error(msg);
      }
```
  …then change the shortfall condition from `if (bccomp(tenderedAmountStr, totalEstimate) < 0) {` to:
```ts
      if (
        bccomp(tenderedAmountStr, snapshot.roundedTotal) < 0
        && !snapshot.toleranceDecision.applied
      ) {
```
  …and, after the successful `createReceiptLocalFirst` call, record the auto-accept exactly as in the quick-cash path:
```ts
      if (snapshot.toleranceDecision.applied && terminalSnapshot.shift) {
        get().recordToleranceAutoAccept(terminalSnapshot.shift.id);
      }
```
  Note: inside the PIN block, `totalEstimateStr` and the `shortfall` computation must now use `snapshot.roundedTotal` so the authored override evidence matches the amount actually due; the rest of that block (target shape, `verifyScopedManagerPin`, `authorPosOverride`) stays byte-identical.
- [ ] Implement — `AdvancedPaymentsModal.tsx`, `computeTenderState` takes a string total:
```ts
export function computeTenderState(
  paymentLines: readonly { amount: string }[],
  voucherTenders: readonly { amount: string }[],
  total: string,
  decimals: number,
): { totalPaid: string; remaining: string; overpayment: string; isFullyPaid: boolean } {
  const totalStr = bcformat(total, decimals);
  const voucherTotal = bcsum(voucherTenders.map((v) => v.amount), decimals);
  const totalPaid = bcadd(
    bcsum(paymentLines.map((l) => l.amount), decimals),
    voucherTotal,
    decimals,
  );
  const remaining =
    bccomp(totalPaid, totalStr) < 0
      ? bcsub(totalStr, totalPaid, decimals)
      : bcformat('0', decimals);
  const overpayment =
    bccomp(totalPaid, totalStr) > 0
      ? bcsub(totalPaid, totalStr, decimals)
      : bcformat('0', decimals);
  const isFullyPaid = bccomp(totalPaid, totalStr) >= 0;
  return { totalPaid, remaining, overpayment, isFullyPaid };
}
```
- [ ] Implement — same file: `total: number;` → `/** Currency-scale decimal string. */ total: string;` in `AdvancedPaymentsModalProps`, plus `roundingAdjustment?: string;`. Then `:208` `&& total > 0;` → `&& bccomp(total, '0') > 0;`; `:709` `total={bcformat(String(total), decimals)}` → `total={bcformat(total, decimals)}`; `:767` `{format(total)}` unchanged (accepts a string). Add the rounding row inside the footer balance block, after the `overpayment` row (`:868-873`):
```tsx
              {roundingAdjustment != null && bccomp(roundingAdjustment, '0') !== 0 && (
                <div className="flex justify-between text-ink-muted">
                  <span>{t('advancedPayments.rounding')}</span>
                  <span className="font-medium">{format(roundingAdjustment)}</span>
                </div>
              )}
```
- [ ] Implement — `src/pages/HomePage.tsx:1640`: `total={total()}` → `total={totalString()}` on `<AdvancedPaymentsModal>`.
- [ ] Implement — i18n keys `payment.changeExceedsCashLegs` (`en`: `"Change cannot exceed the cash tendered. Add a cash tender or lower the non-cash amount."`, `fr`: `"La monnaie ne peut pas dépasser les espèces remises. Ajoutez un règlement espèces ou réduisez le montant non-espèces."`) and `advancedPayments.rounding` (`en`: `"Rounding"`, `fr`: `"Arrondi"`).
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/stores/__tests__/paymentStore.changeEligibility.test.ts src/components/organisms/AdvancedPaymentsModal/__tests__/AdvancedPaymentsModal.test.tsx`
- [ ] Expected: green; update the existing modal tests that pass `total={30}` to `total="30.00"`.
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm typecheck`
- [ ] Commit: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding && git add apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx apps/pos/src/stores/paymentStore.ts apps/pos/src/pages/HomePage.tsx apps/pos/src/stores/__tests__/paymentStore.changeEligibility.test.ts apps/pos/src/locales/en/pos.json apps/pos/src/locales/fr/pos.json && git commit -m "$(cat <<'EOF'
POS cash rounding T7: advanced checkout — rounded due, change eligibility, tolerance

computeTenderState takes a decimal-string total; the advanced path builds the
policy snapshot over the payment-line + voucher union, refuses a completion
whose change exceeds the cash legs, and auto-accepts an in-tolerance all-cash
shortfall in front of the byte-identical manager-PIN path.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"`

---

### Task 8: SALE_RECEIPT v3 — builder, registry, key sets, validator, drift gate

**Files:**
- `apps/pos/src/lib/fiscal/payloads/SaleReceiptV3Payload.ts` (NEW).
- `apps/pos/src/lib/fiscal/payloads/SaleReceiptV2Payload.ts` — READ ONLY, never edited (its docblock `:1-16` pins "the V1 builder is NEVER mutated"; the same rule now covers V2).
- `apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts:162-174` — `eventVersionFor` returns 3 for SALE_RECEIPT.
- `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:1032-1061` (`SALE_RECEIPT_PAYLOAD_KEYS`, 28 keys), `:1360-1365` (`moneyRegex`), `:1387-1396` (`validateSaleReceiptPayload` + the `assertExactKeySet` call), `:1450-1452` (the top-level money loop), `:391-431` (`SaleReceiptPayloadInput`).
- `apps/pos/src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts:1-121` — full rewrite of the SALE_RECEIPT case.
- `apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts:109-190` (`validSaleReceiptPayload` fixture) and `apps/pos/src/lib/fiscal/__tests__/ChainRecoveryService.test.ts` (its sibling fixture, ends `:188`) — both gain the two new keys.
- `apps/pos/src/lib/fiscal/payloads/__tests__/SaleReceiptV3Payload.test.ts` (NEW).
- `apps/pos/src/lib/fiscal/payloads/__tests__/SaleReceiptV1V2ByteStability.test.ts` (NEW).

**Interfaces:**
- Produces:
```ts
export interface SaleReceiptV3PayloadInput extends SaleReceiptV2PayloadInput {
  readonly cash_rounding_adjustment: string;
  readonly cash_rounding_denomination: string;
}

export interface SaleReceiptV3RoundingInput {
  readonly exactTotal: string;
  readonly roundedTotal: string;
  readonly adjustment: string;
  readonly denomination: string;
}

export function buildSaleReceiptV3Payload(
  input: BuildSaleReceiptPayloadInput,
  rounding: SaleReceiptV3RoundingInput,
): SaleReceiptV3PayloadInput

export function assertSaleReceiptAggregatesV3(
  payload: SaleReceiptV3PayloadInput,
  scale: number,
): void
```
- Produces in `FiscalEventEngine.ts`: `export const SALE_RECEIPT_PAYLOAD_KEYS_V3` (30 entries, lexicographically sorted) and `function signedMoneyRegex(scale: number): RegExp`.
- Changes: `FiscalEventPayloadRegistry.eventVersionFor('SALE_RECEIPT')` returns `3`.
- Consumes: `bcadd/bcsub/bccomp/bcabs/bcdiv/bcformat/bcmod` from `@/lib/decimal`; `SaleReceiptAggregateInvariantError` from `@/lib/fiscal/payloads/SaleReceiptPayload`.

**NORMATIVE build order (spec §4.4 r2 F3) — do not reorder:**
1. delegate to `buildSaleReceiptV2Payload` with **`rounding.exactTotal`** as `total` (V1's aggregate assert runs untouched and passes);
2. **replace** the `total` key with `rounding.roundedTotal`;
3. add `cash_rounding_adjustment` + `cash_rounding_denomination`;
4. run `assertSaleReceiptAggregatesV3`.

**Append-time const swap (spec §4.4 r2 F9):** `assertValidSaleReceiptPayload` points at `SALE_RECEIPT_PAYLOAD_KEYS_V3` — NO version threading through `validateRequestPayload`. After the swap the device CANNOT author a v2 SALE_RECEIPT at all; that is precisely why the ROUNDING gate lives on `fiscal_schema_version` (Task 6) and not on the payload version.

**Precondition:** Plan A must have landed `public const SALE_RECEIPT_PAYLOAD_KEYS_V3` in `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php`. Verify before starting: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding && grep -n "SALE_RECEIPT_PAYLOAD_KEYS_V3" apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php`. If absent, STOP — the drift gate cannot go green.

Steps:

- [ ] Verify the PHP precondition with the grep above; expect one `public const SALE_RECEIPT_PAYLOAD_KEYS_V3 = [` hit.
- [ ] Write the failing test `apps/pos/src/lib/fiscal/payloads/__tests__/SaleReceiptV3Payload.test.ts` (reuse `makeSeller`/`makeCartItem`/`makeInput` verbatim from `SaleReceiptV2Payload.test.ts:19-67`, switching the currency to TND with scale-3 amounts):
```ts
import { describe, expect, it } from 'vitest';
import { SaleReceiptAggregateInvariantError } from '@/lib/fiscal/payloads/SaleReceiptPayload';
import {
  assertSaleReceiptAggregatesV3,
  buildSaleReceiptV3Payload,
  type SaleReceiptV3PayloadInput,
} from '@/lib/fiscal/payloads/SaleReceiptV3Payload';

const NO_ROUNDING = {
  exactTotal: '10.000',
  roundedTotal: '10.000',
  adjustment: '0.000',
  denomination: '0.000',
};

describe('buildSaleReceiptV3Payload — normative build order', () => {
  it('signs the ROUNDED total and both sibling fields', () => {
    const payload = buildSaleReceiptV3Payload(makeInput([makeCartItem()]), {
      exactTotal: '9.997',
      roundedTotal: '10.000',
      adjustment: '0.003',
      denomination: '0.050',
    });

    expect(payload.total).toBe('10.000');
    expect(payload.cash_rounding_adjustment).toBe('0.003');
    expect(payload.cash_rounding_denomination).toBe('0.050');
    // subtotal + vat == (total - adj) + discount
    expect(payload.subtotal).toBe('9.997');
  });

  it('carries canonical zeros when rounding did not apply', () => {
    const payload = buildSaleReceiptV3Payload(makeInput([makeCartItem()]), NO_ROUNDING);
    expect(payload.total).toBe('10.000');
    expect(payload.cash_rounding_adjustment).toBe('0.000');
    expect(payload.cash_rounding_denomination).toBe('0.000');
  });

  it('keeps every V2 key and shape (strict superset)', () => {
    const payload = buildSaleReceiptV3Payload(makeInput([makeCartItem()]), NO_ROUNDING);
    expect(payload.line_items[0]).toHaveProperty('variant_id');
    expect(Object.keys(payload).sort()).toEqual([...SALE_RECEIPT_PAYLOAD_KEYS_V3].sort());
  });
});

describe('assertSaleReceiptAggregatesV3 — binds in normative order', () => {
  function payloadWith(overrides: Partial<SaleReceiptV3PayloadInput>): SaleReceiptV3PayloadInput {
    return {
      ...buildSaleReceiptV3Payload(makeInput([makeCartItem()]), NO_ROUNDING),
      ...overrides,
    } as SaleReceiptV3PayloadInput;
  }

  it('rejects a payload whose total was NOT replaced (build-order regression)', () => {
    // adj is non-zero but total is still the exact total: identity (1) breaks.
    expect(() => assertSaleReceiptAggregatesV3(
      payloadWith({ total: '9.997', subtotal: '9.997', vat_total: '0.000',
        cash_rounding_adjustment: '0.003', cash_rounding_denomination: '0.050' }),
      3,
    )).toThrow(SaleReceiptAggregateInvariantError);
  });

  it('rejects adj != 0 with a zero denomination WITHOUT a division error', () => {
    let thrown: unknown;
    try {
      assertSaleReceiptAggregatesV3(
        payloadWith({ total: '10.000', subtotal: '9.997', vat_total: '0.000',
          cash_rounding_adjustment: '0.003', cash_rounding_denomination: '0.000' }),
        3,
      );
    } catch (error) {
      thrown = error;
    }
    expect(thrown).toBeInstanceOf(SaleReceiptAggregateInvariantError);
    expect(String(thrown)).not.toContain('Modulo by zero');
  });

  it('rejects |adj| > denomination / 2', () => {
    expect(() => assertSaleReceiptAggregatesV3(
      payloadWith({ total: '10.000', subtotal: '9.970', vat_total: '0.000',
        cash_rounding_adjustment: '0.030', cash_rounding_denomination: '0.050' }),
      3,
    )).toThrow(/denomination/);
  });

  it('accepts |adj| exactly equal to denomination / 2 (the tie)', () => {
    expect(() => assertSaleReceiptAggregatesV3(
      payloadWith({ total: '10.000', subtotal: '9.975', vat_total: '0.000',
        cash_rounding_adjustment: '0.025', cash_rounding_denomination: '0.050' }),
      3,
    )).not.toThrow();
  });

  it('rejects a total that is not a multiple of the denomination', () => {
    expect(() => assertSaleReceiptAggregatesV3(
      payloadWith({ total: '10.010', subtotal: '10.000', vat_total: '0.000',
        cash_rounding_adjustment: '0.010', cash_rounding_denomination: '0.050' }),
      3,
    )).toThrow();
  });

  it('rejects a suppression payload (total 5.000, adj -95.000)', () => {
    expect(() => assertSaleReceiptAggregatesV3(
      payloadWith({ total: '5.000', subtotal: '100.000', vat_total: '0.000',
        cash_rounding_adjustment: '-95.000', cash_rounding_denomination: '0.050' }),
      3,
    )).toThrow(SaleReceiptAggregateInvariantError);
  });

  it('rejects a denomination beyond the static cap', () => {
    expect(() => assertSaleReceiptAggregatesV3(
      payloadWith({ total: '10.000', subtotal: '8.000', vat_total: '0.000',
        cash_rounding_adjustment: '2.000', cash_rounding_denomination: '5.000' }),
      3,
    )).toThrow();
  });
});
```
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/fiscal/payloads/__tests__/SaleReceiptV3Payload.test.ts`
- [ ] Expected fail: `Failed to resolve import ".../SaleReceiptV3Payload"`.
- [ ] Implement — `apps/pos/src/lib/fiscal/payloads/SaleReceiptV3Payload.ts`:
```ts
/**
 * SaleReceiptV3 — `event_version = 3` canonical SALE_RECEIPT payload
 * (cash rounding, spec 2026-07-27 §4.4).
 *
 * V3 is a strict superset of V2: `total` becomes the ROUNDED total and two
 * signed siblings are appended — `cash_rounding_adjustment` (signed) and
 * `cash_rounding_denomination` (non-negative, normalized at currency scale).
 * Every receipt is therefore verifiable against its OWN authoring policy,
 * offline, forever.
 *
 * Build order is NORMATIVE and must not be reordered:
 *   1. delegate to buildSaleReceiptV2Payload with the EXACT total, so V1's
 *      aggregate assert runs unchanged and passes;
 *   2. replace `total` with the rounded total;
 *   3. add the two rounding fields;
 *   4. run assertSaleReceiptAggregatesV3.
 *
 * The V1 and V2 builders are NEVER mutated — Events are Immutable Forever.
 */

import { getCurrencyDecimals } from '@/lib/currency';
import { bcabs, bcadd, bccomp, bcdiv, bcformat, bcmod, bcsub } from '@/lib/decimal';
import {
  SaleReceiptAggregateInvariantError,
  type BuildSaleReceiptPayloadInput,
} from '@/lib/fiscal/payloads/SaleReceiptPayload';
import {
  buildSaleReceiptV2Payload,
  type SaleReceiptV2PayloadInput,
} from '@/lib/fiscal/payloads/SaleReceiptV2Payload';

export interface SaleReceiptV3PayloadInput extends SaleReceiptV2PayloadInput {
  /** Signed `rounded_total - exact_total` at currency scale; canonical zero when unrounded. */
  readonly cash_rounding_adjustment: string;
  /** Non-negative rounding step at currency scale; canonical zero when unrounded. */
  readonly cash_rounding_denomination: string;
}

export interface SaleReceiptV3RoundingInput {
  readonly exactTotal: string;
  readonly roundedTotal: string;
  readonly adjustment: string;
  readonly denomination: string;
}

/**
 * Static, history-stable ceilings on the signed denomination (spec §4.1).
 * Mirrors DENOMINATION_CAP_BY_SCALE in lib/payment/cashRounding.ts and the
 * server-side bind; duplicated here so the payload assert has no dependency
 * on the checkout layer.
 */
const DENOMINATION_CAP_BY_SCALE: Readonly<Record<number, string>> = {
  0: '10',
  2: '1.00',
  3: '1.000',
};

export function buildSaleReceiptV3Payload(
  input: BuildSaleReceiptPayloadInput,
  rounding: SaleReceiptV3RoundingInput,
): SaleReceiptV3PayloadInput {
  const scale = getCurrencyDecimals(input.currency);

  // (1) V2 with the EXACT total — V1's aggregate assert must see the total it
  //     can reconcile against subtotal/vat/discount.
  const v2 = buildSaleReceiptV2Payload({
    ...input,
    total: bcformat(rounding.exactTotal, scale),
  });

  // (2) + (3)
  const payload: SaleReceiptV3PayloadInput = {
    ...v2,
    total: bcformat(rounding.roundedTotal, scale),
    cash_rounding_adjustment: bcformat(rounding.adjustment, scale),
    cash_rounding_denomination: bcformat(rounding.denomination, scale),
  };

  // (4)
  assertSaleReceiptAggregatesV3(payload, scale);

  return payload;
}

/**
 * V3 aggregate identity + rounding binds. Evaluation ORDER is normative
 * (spec §4.1): the denomination-positivity check runs BEFORE any modulo so a
 * zero divisor is unreachable — a raw division error would escape the
 * quarantine path server-side and kill the worker.
 */
export function assertSaleReceiptAggregatesV3(
  payload: SaleReceiptV3PayloadInput,
  scale: number,
): void {
  const adjustment = payload.cash_rounding_adjustment;
  const denomination = payload.cash_rounding_denomination;

  // 1. subtotal + vat_total == (total - adjustment) + transaction_discount_amount
  const lhs = bcformat(bcadd(payload.subtotal, payload.vat_total, scale), scale);
  const rhs = bcformat(
    bcadd(
      bcsub(payload.total, adjustment, scale),
      payload.transaction_discount_amount,
      scale,
    ),
    scale,
  );
  if (bccomp(lhs, rhs) !== 0) {
    throw new SaleReceiptAggregateInvariantError(
      `V3 aggregate invariant violated: subtotal (${payload.subtotal}) + vat_total `
      + `(${payload.vat_total}) = ${lhs} != (total (${payload.total}) - `
      + `cash_rounding_adjustment (${adjustment})) + transaction_discount_amount `
      + `(${payload.transaction_discount_amount}) = ${rhs}.`,
    );
  }

  if (bccomp(adjustment, bcformat('0', scale)) === 0) {
    return;
  }

  // 2a. denomination strictly positive — BEFORE any modulo.
  if (bccomp(denomination, '0') <= 0) {
    throw new SaleReceiptAggregateInvariantError(
      `V3 rounding bind violated: cash_rounding_adjustment ${adjustment} is non-zero `
      + `while cash_rounding_denomination is ${denomination}.`,
    );
  }

  // 2b. |adjustment| <= denomination / 2, compared at scale + 1 (truncation-safe).
  const half = bcdiv(denomination, '2', scale + 1);
  if (bccomp(bcabs(adjustment, scale + 1), half) > 0) {
    throw new SaleReceiptAggregateInvariantError(
      `V3 rounding bind violated: |cash_rounding_adjustment| (${adjustment}) exceeds `
      + `half the cash_rounding_denomination (${denomination} / 2 = ${half}).`,
    );
  }

  // 2c. total is an exact multiple of denomination.
  const remainder = bcmod(payload.total, denomination, scale);
  if (bccomp(remainder, bcformat('0', scale)) !== 0) {
    throw new SaleReceiptAggregateInvariantError(
      `V3 rounding bind violated: total ${payload.total} is not a multiple of `
      + `cash_rounding_denomination ${denomination} (remainder ${remainder}).`,
    );
  }

  // 3. Static history-stable cap on the denomination.
  const cap = DENOMINATION_CAP_BY_SCALE[scale];
  if (cap === undefined || bccomp(denomination, cap) > 0) {
    throw new SaleReceiptAggregateInvariantError(
      `V3 rounding bind violated: cash_rounding_denomination ${denomination} exceeds `
      + `the scale-${String(scale)} cap ${cap ?? '(unsupported scale)'}.`,
    );
  }
}
```
- [ ] Implement — `FiscalEventEngine.ts`, add the v3 key set immediately after the existing `SALE_RECEIPT_PAYLOAD_KEYS` block (which stays exported, unchanged, as the v1/v2 record):
```ts
/**
 * SALE_RECEIPT v3 top-level key set (cash rounding, spec §4.4). 30 keys =
 * the 28 v1/v2 keys plus `cash_rounding_adjustment` +
 * `cash_rounding_denomination`, which sort between `buyer` and `cashier_id`.
 *
 * The PHP authority is
 * `FiscalPayloadConstraintValidator::SALE_RECEIPT_PAYLOAD_KEYS_V3`; the
 * FiscalPayloadKeyDrift gate pins the two lists (and this list's
 * sortedness) against each other.
 */
export const SALE_RECEIPT_PAYLOAD_KEYS_V3 = [
  'approval_references',
  'business_date',
  'buyer',
  'cash_rounding_adjustment',
  'cash_rounding_denomination',
  'cashier_id',
  'cashier_name',
  'consumption_mode',
  'currency_code',
  'currency_scale',
  'event_time_device',
  'invoice_type_code',
  'line_items',
  'lottery_code',
  'notes',
  'original_receipt_reference',
  'payments',
  'receipt_uuid',
  'seller',
  'shift_id',
  'subtotal',
  'table_id',
  'terminal_id',
  'total',
  'training_flag',
  'transaction_discount_amount',
  'transaction_discount_reason',
  'vat_breakdown',
  'vat_total',
  'vouchers_redeemed',
] as const;
```
- [ ] Implement — `FiscalEventEngine.ts`, the signed-money regex beside `moneyRegex` (`:1360-1365`):
```ts
/**
 * Signed money at `scale` — the non-negative `moneyRegex` shape plus an
 * optional leading '-'. `-0` is rejected: canonical zero is unsigned.
 */
function signedMoneyRegex(scale: number): RegExp {
  if (scale === 0) {
    return /^-?(0|[1-9]\d*)$/;
  }
  return new RegExp(`^-?(0|[1-9]\\d*)\\.\\d{${scale}}$`);
}
```
- [ ] Implement — `FiscalEventEngine.ts:1396`, swap the const: `assertExactKeySet(p, SALE_RECEIPT_PAYLOAD_KEYS_V3, 'SALE_RECEIPT');`
- [ ] Implement — `FiscalEventEngine.ts`, after the existing top-level money loop (`:1450-1452`), add the two v3 field checks:
```ts
  // -- 4b. v3 cash-rounding fields (spec §4.4) --
  const signedMoney = signedMoneyRegex(scale);
  const adjustment = p['cash_rounding_adjustment'];
  if (typeof adjustment !== 'string' || !signedMoney.test(adjustment) || adjustment === `-${bcformat('0', scale)}`) {
    throw new FiscalEventPayloadValidationError(
      `payload_field_invalid:cash_rounding_adjustment must be signed money at scale ${scale}; got ${jsonOrType(adjustment)}`,
    );
  }
  assertMoneyString(p, 'cash_rounding_denomination', money, scale);
```
  (`bcformat` is already imported by this module; if not, add `bcformat` to the `@/lib/decimal` import list.)
- [ ] Implement — `FiscalEventEngine.ts:391-431`, add to `SaleReceiptPayloadInput` after `total`:
```ts
  /** v3: signed `rounded_total - exact_total` at `currency_scale`. */
  readonly cash_rounding_adjustment?: string;
  /** v3: non-negative rounding step at `currency_scale`. */
  readonly cash_rounding_denomination?: string;
```
- [ ] Implement — `FiscalEventPayloadRegistry.ts:170-172`, change the SALE_RECEIPT branch:
```ts
    // SaleReceiptV3 (cash rounding, 2026-07-27): SALE_RECEIPT carries the
    // signed rounding adjustment + denomination since event_version 3. The
    // server accepts {1, 2, 3} for parse; the device AUTHORS only 3.
    if (type === 'SALE_RECEIPT') {
      return 3;
    }
```
- [ ] Implement — update both existing SALE_RECEIPT fixtures so the append-time key set is satisfied: in `FiscalEventEngine.test.ts:109-190` and the sibling in `ChainRecoveryService.test.ts`, add to the returned object (alphabetical position is irrelevant — the canonical encoder sorts):
```ts
    cash_rounding_adjustment: '0.000',
    cash_rounding_denomination: '0.000',
```
- [ ] Write the byte-stability pin `apps/pos/src/lib/fiscal/payloads/__tests__/SaleReceiptV1V2ByteStability.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { buildSaleReceiptPayload } from '@/lib/fiscal/payloads/SaleReceiptPayload';
import { buildSaleReceiptV2Payload } from '@/lib/fiscal/payloads/SaleReceiptV2Payload';
// makeSeller / makeCartItem / makeInput copied verbatim from
// SaleReceiptV2Payload.test.ts:19-67 — the fixture is the pin.

describe('V1/V2 byte stability under the v3 rollout', () => {
  it('V1 output is unchanged (snapshot pin)', () => {
    expect(JSON.stringify(buildSaleReceiptPayload(makeInput([makeCartItem()])))).toMatchSnapshot();
  });

  it('V2 output is unchanged (snapshot pin)', () => {
    expect(JSON.stringify(buildSaleReceiptV2Payload(makeInput([makeCartItem()])))).toMatchSnapshot();
  });

  it('neither builder emits a cash-rounding key', () => {
    const v1 = buildSaleReceiptPayload(makeInput([makeCartItem()]));
    const v2 = buildSaleReceiptV2Payload(makeInput([makeCartItem()]));
    expect(Object.keys(v1)).not.toContain('cash_rounding_adjustment');
    expect(Object.keys(v2)).not.toContain('cash_rounding_denomination');
  });
});
```
- [ ] Rewrite `apps/pos/src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts` — replace the SALE_RECEIPT case (`:8-14`) and add a named-const reader + a sortedness assertion. The old `readPhpValidatorPayloadKeys` regex cannot parse a named const or a nested shape, so SALE_RECEIPT moves onto a dedicated reader while ACCOUNT_PAYMENT keeps the map-entry reader:
```ts
  it('SALE_RECEIPT_PAYLOAD_KEYS_V3 byte-mirrors the PHP named const', () => {
    const phpKeys = readPhpNamedConst('SALE_RECEIPT_PAYLOAD_KEYS_V3');
    const tsKeys = [...SALE_RECEIPT_PAYLOAD_KEYS_V3];

    expect([...tsKeys].sort()).toEqual([...phpKeys].sort());
    expect(tsKeys).toHaveLength(30);
  });

  it('SALE_RECEIPT_PAYLOAD_KEYS_V3 is declared lexicographically sorted', () => {
    // The canonical encoder sorts by code unit; a declaration that already
    // matches makes every future insertion reviewable at a glance.
    const tsKeys = [...SALE_RECEIPT_PAYLOAD_KEYS_V3];
    expect(tsKeys).toEqual([...tsKeys].sort());
    expect(tsKeys.indexOf('cash_rounding_adjustment')).toBe(tsKeys.indexOf('buyer') + 1);
    expect(tsKeys.indexOf('cashier_id')).toBe(tsKeys.indexOf('cash_rounding_denomination') + 1);
  });

  it('the v1/v2 SALE_RECEIPT key set is frozen at 28 keys', () => {
    expect([...SALE_RECEIPT_PAYLOAD_KEYS]).toHaveLength(28);
    expect([...SALE_RECEIPT_PAYLOAD_KEYS]).not.toContain('cash_rounding_adjustment');
  });
```
  …with the reader (same candidate-path + fs pattern as `readPhpSaleReceiptLineItemKeysV2:69-95`):
```ts
function readPhpNamedConst(constName: string): string[] {
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const fs = require('node:fs') as typeof import('node:fs');
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const path = require('node:path') as typeof import('node:path');
  const candidates = [
    path.resolve(__dirname, '../../../../../../apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php'),
    path.resolve(__dirname, '../../../../../api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php'),
  ];
  const phpPath = candidates.find((p) => fs.existsSync(p));
  if (!phpPath) {
    throw new Error(`FiscalPayloadConstraintValidator.php not found at: ${candidates.join(', ')}`);
  }
  const src = fs.readFileSync(phpPath, 'utf8');
  const match = src.match(new RegExp(`public const ${constName} = \\[([\\s\\S]*?)\\];`));
  if (!match) {
    throw new Error(`Could not locate ${constName} in ${phpPath}`);
  }
  const keys = Array.from((match[1] ?? '').matchAll(/'([a-z_][a-z0-9_]*)'/g)).map((m) => m[1] as string);
  if (keys.length === 0) {
    throw new Error(`No keys extracted from ${constName} in ${phpPath}`);
  }
  return keys;
}
```
  …and fix the stale comment at `FiscalEventEngine.ts:1378-1379` ("28-key canonical Candidate C-v3") to say 30 keys at v3.
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/fiscal/payloads/__tests__/SaleReceiptV3Payload.test.ts src/lib/fiscal/payloads/__tests__/SaleReceiptV1V2ByteStability.test.ts src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts`
- [ ] Expected: green (V3 build order + all seven bind rejections, V1/V2 snapshots written, 30 sorted keys mirroring PHP).
- [ ] Run the engine + registry + parity regressions: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/fiscal/__tests__/FiscalEventEngine.test.ts src/lib/fiscal/__tests__/FiscalEventPayloadRegistry.test.ts src/lib/fiscal/__tests__/ChainRecoveryService.test.ts src/lib/fiscal/__tests__/saleReceiptV2CanonicalParity.test.ts`
- [ ] Expected: green after the two fixture updates; `FiscalEventPayloadRegistry.test.ts`'s SALE_RECEIPT version assertion moves from 2 to 3.
- [ ] Commit: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding && git add apps/pos/src/lib/fiscal/payloads/SaleReceiptV3Payload.ts apps/pos/src/lib/fiscal/FiscalEventEngine.ts apps/pos/src/lib/fiscal/FiscalEventPayloadRegistry.ts apps/pos/src/lib/fiscal/__tests__ apps/pos/src/lib/fiscal/payloads/__tests__ && git commit -m "$(cat <<'EOF'
POS cash rounding T8: SALE_RECEIPT v3 payload, key set, validator, drift gate

buildSaleReceiptV3Payload follows the normative order (V2 with the exact
total, replace total, add the two signed fields, assert). Registry authors
version 3, the append-time key set swaps to the 30-key sorted v3 const, the
validator gains a signed-money check, and the drift gate reads the PHP named
const and pins sortedness. V1/V2 byte-pinned by snapshot.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"`

---

### Task 9: `receiptService` v3 integration — snapshot threading, persistence, integrity assert

**Files:**
- `apps/pos/src/lib/offline/receiptService.ts:12` (import), `:33-94` (`OfflineReceiptInput`), `:96-106` (`OfflineReceiptResult`), `:294-325` (the canonical payload build), `:335-336` (change-due), `:436-491` (the `offline_receipts` row), `:569-579` (the return).
- `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:29-78` (`OfflineReceipt`), `:80-113` (`insertOfflineReceipt` — column list, placeholders and bind array move in LOCKSTEP).
- `apps/pos/src/stores/paymentStore.ts:503-626` (`createReceiptLocalFirst` — forward `policySnapshot`).
- `apps/pos/src/lib/offline/__tests__/receiptService.cashRounding.test.ts` (NEW).

**Interfaces:**
- Changes: `OfflineReceiptInput` gains `readonly policySnapshot: CheckoutPolicySnapshot;` (REQUIRED — every caller already builds one; a missing snapshot must be a type error, not a silent unrounded receipt).
- Changes: `OfflineReceipt` gains `cash_rounding_adjustment: string | null; cash_rounding_denomination: string | null; tolerance_shortfall: string | null;`.
- Changes: `insertOfflineReceipt` INSERT goes from 29 to 32 placeholders.
- Consumes: `buildSaleReceiptV3Payload` (replacing `buildSaleReceiptV2Payload` at `:299`).
- Produces: error key `pos:payment.totalIntegrityError`.

**Integrity assert (defense-in-depth, spec §4.3):** after computing the line-derived total, `bccomp(lineDerivedTotal, snapshot.exactTotal) === 0` must hold. Task 5 made this unreachable by construction (one function, both callers); the assert is the belt. On throw NOTHING is signed — the checkout is blocked and the cashier retries.

**Persistence:** the three columns are written INSIDE the same write-gate transaction as the fiscal event, from the snapshot — never re-derived. They are LOCAL only: the sync wire is the fiscal-event envelope (`syncService.pushOfflineReceipts:294-298` posts `fiscalEventToWireEnvelope`), so the canonical bytes already carry the signed values and the server projects from them.

Steps:

- [ ] Write the failing test `apps/pos/src/lib/offline/__tests__/receiptService.cashRounding.test.ts` — model the harness on the existing `receiptService.test.ts` (same db/engine mocks), then:
```ts
describe('createOfflineReceipt — v3 rounding', () => {
  it('signs the rounded total and persists the three mirror columns', async () => {
    const result = await createOfflineReceipt(db, makeInput({
      cartItems: [makeCartItem({ id: 'i1', line_total: '9.997' })],
      currency: 'TND',
      tenderedAmount: '10.000',
      payments: [{ methodCode: 'CASH', amount: '10.000' }],
      policySnapshot: roundedSnapshot, // exact 9.997 -> rounded 10.000, adj 0.003, D 0.050
    }));

    expect(result.total).toBe('10.000');
    const appended = appendSpy.mock.calls[0]![1];
    expect(appended.payload.total).toBe('10.000');
    expect(appended.payload.cash_rounding_adjustment).toBe('0.003');
    expect(appended.payload.cash_rounding_denomination).toBe('0.050');

    const row = insertSpy.mock.calls[0]![1];
    expect(row.cash_rounding_adjustment).toBe('0.003');
    expect(row.cash_rounding_denomination).toBe('0.050');
    expect(row.tolerance_shortfall).toBeNull();
  });

  it('signs canonical zeros and the EXACT total when the gate is closed', async () => {
    const result = await createOfflineReceipt(db, makeInput({
      cartItems: [makeCartItem({ id: 'i1', line_total: '9.997' })],
      currency: 'TND',
      tenderedAmount: '10.000',
      payments: [{ methodCode: 'CASH', amount: '10.000' }],
      policySnapshot: unroundedSnapshot, // fiscal_schema_version 2 => no rounding
    }));

    expect(result.total).toBe('9.997');
    const appended = appendSpy.mock.calls[0]![1];
    expect(appended.payload.cash_rounding_adjustment).toBe('0.000');
    expect(appended.payload.cash_rounding_denomination).toBe('0.000');
  });

  it('persists the tolerance shortfall when the sale auto-accepted', async () => {
    await createOfflineReceipt(db, makeInput({
      cartItems: [makeCartItem({ id: 'i1', line_total: '9.973' })],
      currency: 'TND',
      tenderedAmount: '9.900',
      payments: [{ methodCode: 'CASH', amount: '9.900' }],
      policySnapshot: toleranceSnapshot, // rounded 9.950, shortfall 0.050, applied
    }));

    expect(insertSpy.mock.calls[0]![1].tolerance_shortfall).toBe('0.050');
  });

  it('blocks checkout when the line-derived total disagrees with the snapshot', async () => {
    await expect(createOfflineReceipt(db, makeInput({
      cartItems: [makeCartItem({ id: 'i1', line_total: '9.997' })],
      currency: 'TND',
      tenderedAmount: '10.000',
      payments: [{ methodCode: 'CASH', amount: '10.000' }],
      policySnapshot: { ...roundedSnapshot, exactTotal: '8.000' },
    }))).rejects.toThrow(/totalIntegrityError|integrity/i);
    expect(appendSpy).not.toHaveBeenCalled();
  });
});
```
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/offline/__tests__/receiptService.cashRounding.test.ts`
- [ ] Expected fail: `policySnapshot` is not a known input property.
- [ ] Implement — `receiptService.ts`, add to `OfflineReceiptInput` after `isTraining?: boolean;`:
```ts
  /**
   * Sealed checkout decision (spec §4.3). Built ONCE at tender time; what
   * signs is THIS snapshot, so a policy tick between the gate and the
   * signature can never move the total. Required: an absent snapshot must be
   * a compile error, never a silently unrounded receipt.
   */
  policySnapshot: CheckoutPolicySnapshot;
```
  …and the import:
```ts
import type { CheckoutPolicySnapshot } from '@/lib/payment/checkoutPolicySnapshot';
import { buildSaleReceiptV3Payload } from '@/lib/fiscal/payloads/SaleReceiptV3Payload';
```
- [ ] Implement — `receiptService.ts`, right after the `total` computation added in Task 5, add the integrity assert:
```ts
  // Defense-in-depth (spec §4.3 r2 F4): Task 5 made this unreachable by making
  // one function the only cart-total math. If it EVER fires, nothing is signed.
  if (bccomp(total, input.policySnapshot.exactTotal) !== 0) {
    throw new Error(
      `pos:payment.totalIntegrityError — line-derived total ${total} disagrees with the `
      + `checkout snapshot exact total ${input.policySnapshot.exactTotal}.`,
    );
  }
```
- [ ] Implement — `receiptService.ts`, the change-due now nets against the ROUNDED total (`:335-336`):
```ts
  const changeDueRaw = bcsub(input.tenderedAmount, input.policySnapshot.roundedTotal, decimals);
```
  …and `totalFormatted` (`:294`) becomes the rounded total:
```ts
  const totalFormatted = bcformat(input.policySnapshot.roundedTotal, decimals);
```
- [ ] Implement — `receiptService.ts:299-325`, swap the builder call, passing the EXACT total to the V2 delegate and the rounding block alongside:
```ts
  const canonicalPayload = buildSaleReceiptV3Payload(
    {
      receiptId,
      terminalId: input.terminalId,
      operatorId: input.operatorId,
      operatorName: input.operatorName,
      shiftId: input.shiftId,
      currency: input.currency,
      eventTimeDevice: postedAtDate,
      businessDate,
      cartItems: input.cartItems,
      subtotalGross: subtotal,
      taxAmount,
      total: bcformat(input.policySnapshot.exactTotal, decimals),
      transactionDiscountAmount,
      transactionDiscountReason: input.transactionDiscount?.reason ?? null,
      payments: input.payments.map((payment) => ({
        methodCode: payment.methodCode,
        amount: payment.amount,
        instrumentType: payment.instrumentType ?? null,
        instrumentSerial: payment.instrumentSerial ?? null,
      })),
      consumptionMode: input.consumptionMode ?? null,
      tableId: input.tableId ?? null,
      isTraining,
      seller: input.seller,
      approvalReferences,
    },
    {
      exactTotal: bcformat(input.policySnapshot.exactTotal, decimals),
      roundedTotal: bcformat(input.policySnapshot.roundedTotal, decimals),
      adjustment: bcformat(input.policySnapshot.adjustment, decimals),
      denomination: bcformat(input.policySnapshot.denomination, decimals),
    },
  );
```
- [ ] Implement — `receiptService.ts:436-491`, add the three columns to the `offlineReceipt` object (after `table_id`):
```ts
        cash_rounding_adjustment: input.policySnapshot.roundingApplied
          ? bcformat(input.policySnapshot.adjustment, decimals)
          : null,
        cash_rounding_denomination: input.policySnapshot.roundingApplied
          ? bcformat(input.policySnapshot.denomination, decimals)
          : null,
        tolerance_shortfall: input.policySnapshot.toleranceDecision.applied
          ? bcformat(input.policySnapshot.toleranceDecision.shortfall, decimals)
          : null,
```
- [ ] Implement — `offlineReceiptRepository.ts`, add the three fields to `OfflineReceipt` (after `table_id: string | null;`):
```ts
  /** v3 signed rounding mirror (spec §4.3). Null on unrounded receipts. */
  cash_rounding_adjustment: string | null;
  cash_rounding_denomination: string | null;
  /** Local auto-accepted tender shortfall. Null when no tolerance was applied. */
  tolerance_shortfall: string | null;
```
  …and the lockstep INSERT:
```ts
    `INSERT INTO offline_receipts (
      id, idempotency_key, receipt_number, terminal_id, terminal_code,
      operator_id, operator_name, lines, subtotal, tax_amount, discount_amount,
      total, currency, fiscal_hash, previous_hash, hash_sequence,
      transaction_discount_amount, transaction_discount_reason,
      tendered_amount, change_due, payment_method_id, payment_repository_id, status,
      payments_json, consumption_mode, table_id, fiscal_schema_version, is_training, canonical_bytes,
      cash_rounding_adjustment, cash_rounding_denomination, tolerance_shortfall
    ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, $17, $18, $19, $20, $21, $22, $23, $24, $25, $26, $27, $28, $29, $30, $31, $32)`,
```
  …with the bind array gaining, after `receipt.canonical_bytes ?? null,`:
```ts
      receipt.cash_rounding_adjustment ?? null,
      receipt.cash_rounding_denomination ?? null,
      receipt.tolerance_shortfall ?? null,
```
- [ ] Implement — `paymentStore.ts`, `createReceiptLocalFirst` takes `policySnapshot: CheckoutPolicySnapshot` as its final parameter and forwards it into the `createOfflineReceipt({ … })` object; both call sites (quick cash `:935`, advanced `:1193`) pass the snapshot built in Tasks 6/7.
- [ ] Implement — i18n `payment.totalIntegrityError` (`en`: `"The sale total could not be verified. Nothing was recorded — please retry the sale."`, `fr`: `"Le total de la vente n'a pas pu être vérifié. Rien n'a été enregistré — veuillez recommencer la vente."`).
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/offline/__tests__/receiptService.cashRounding.test.ts src/lib/offline/__tests__/receiptService.test.ts`
- [ ] Expected: green; the pre-existing suite gains `policySnapshot` on its input fixture (an unrounded snapshot keeps every existing assertion valid).
- [ ] Run the end-to-end offline flow: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/__tests__/integration/offlineFirstFlow.test.ts src/lib/db/__tests__/concurrentCheckout.integration.test.ts`
- [ ] Commit: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding && git add apps/pos/src/lib/offline/receiptService.ts apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts apps/pos/src/stores/paymentStore.ts apps/pos/src/lib/offline/__tests__/receiptService.cashRounding.test.ts apps/pos/src/locales/en/pos.json apps/pos/src/locales/fr/pos.json && git commit -m "$(cat <<'EOF'
POS cash rounding T9: receiptService authors v3 from the sealed snapshot

The checkout snapshot threads into createOfflineReceipt, drives the v3
builder and the change-due netting, and its rounding/tolerance values persist
on offline_receipts inside the same write-gate transaction. A line-derived
total that disagrees with the snapshot blocks checkout before anything signs.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"`

---

### Task 10: Device Z, hash mirror, EOD preview, print paths, i18n

**Files:**
- `apps/pos/src/lib/offline/zReportService.ts:140-176` (the shift receipt query — `SELECT *`, so the new columns arrive already), `:319-337` (the `schema_version = 2` stamp + the HARDCODED `tolerance_summary` zero-shape with the TODO naming this feature), `:407-419` (`zReport.tolerance_summary`), `:730-806` (`aggregateReportData`, incl. the `method_code === 'CASH'` cash-line netting at `:778`).
- `apps/pos/src/lib/offline/types.ts:93-112` (`ZReportData`), `:158-161` (`LocalZReport.tolerance_summary`).
- `apps/pos/src/lib/fiscal/zReportHashService.ts:31-107` (`normalizeForHash`; `tolerance_summary` block at `:85-95`).
- `apps/pos/src/lib/fiscal/zSessionAuthoring.ts:496` — `tolerance_summary` already flows into the canonical Z payload from `zReport.tolerance_summary`; NO change needed beyond feeding it real values.
- `apps/pos/src/lib/offline/endOfDayPreview.ts:26-40` (`OfflineReceiptRow`), `:42-50` (`PaymentJsonRow.tolerance_writeoff` — never written by the writer; replaced by the receipt-level column), `:78-93` (`EndOfDayPreview`), `:131-138` (the query), `:169-253` (the aggregation loop), `:324-342` (the return).
- `apps/pos/src/lib/offline/getOfflineReceiptForPrint.ts:116-149` (the `FullReceiptResponse` build; `tolerance_writeoff: null` at `:126`).
- `apps/pos/src/lib/buildReceiptData.ts:143-147`, `:214-217` (tolerance mapping), `:484-533` (`buildReceiptLabels`).
- `apps/pos/src/lib/printing.ts:65-105` (`ReceiptLabels`, `rounding` at `:80`), `:141-181` (`ReceiptData`, `tolerance_writeoff`/`has_tolerance` at `:156-163`).
- `apps/pos/src-tauri/src/printing/receipt_template.rs:39-49` (the tolerance fields), `:128` (`rounding` label), `:652-664` (the print block), `:985-986`/`:1053` (test fixtures).
- `apps/pos/src/locales/{en,fr}/pos.json`.
- NEW tests: `apps/pos/src/lib/offline/__tests__/zReportService.cashRounding.test.ts`, `apps/pos/src/lib/fiscal/__tests__/zReportHashService.legacyStability.test.ts`.

**Interfaces:**
- Produces on `ZReportData`:
```ts
  /** Local-only observability (spec §4.3): the server derives its own from
   *  projected pos_receipts.cash_rounding_adjustment. NOT a signed Z key. */
  cash_rounding_summary?: { total_adjustment: string; receipt_count: number } | null;
```
- Changes: `zReportService` computes `tolerance_summary` from `Σ offline_receipts.tolerance_shortfall` over the shift's receipts (replacing the hardcoded zero-shape); `schema_version` STAYS `2`.
- Changes: `EndOfDayPreview` gains `cash_rounding_summary: { totalAdjustment: string; receiptCount: number } | null;` and `tolerance_auto_accept_count: number;`.
- Changes: `printing.ts` `ReceiptData` gains `cash_rounding_adjustment?: string | null; has_cash_rounding?: boolean;`; `ReceiptLabels` gains `tolerance?: string;`.
- Changes: `FullReceiptResponse`-shaped local print payload sets `tolerance_writeoff` from `receipt.tolerance_shortfall` and adds `cash_rounding_adjustment`.

**Hash-parity rules (spec §4.3 r2 F1/F-6 + r3):**
1. `report_data.schema_version` STAYS `2`. Bumping it re-normalizes `refunds_amount` (`zReportHashService.ts:46-54` vs the schema≥3 key list) and breaks parity.
2. The additive `cash_rounding_summary` normalization must land in BOTH `ZReportHashService.php` (Plan A) and the TS mirror here, or a legacy-path Z hashes differently on the two sides.
3. A `report_data` WITHOUT `cash_rounding_summary` must hash byte-identically to today — pinned by a regression test.
4. The device's cash-line netting (`zReportService.ts:778`) keeps its literal `method_code === 'CASH'` comparison: the server invariant `is_cash_tender ⇒ code = 'CASH'` EXACT makes all three predicates coincide. Normalizing it further is an explicit non-goal (🎫).

**Print decision:** the existing Rust "Rounding" line prints the TOLERANCE write-off with a hardcoded `-` prefix (`receipt_template.rs:657-664`). The cash-rounding adjustment is SIGNED, so it gets its OWN line using the `rounding` label printed verbatim (no forced sign), and the tolerance line moves to a new `tolerance` label. Two identically-labelled lines on one ticket would be unreadable.

Steps:

- [ ] Write the failing hash-parity pin `apps/pos/src/lib/fiscal/__tests__/zReportHashService.legacyStability.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { computeZReportHash, normalizeForHash } from '@/lib/fiscal/zReportHashService';

const legacyReportData = {
  schema_version: 2,
  sales_count: 3,
  gross_sales: '100.5',
  net_sales: '90.5',
  tax_amount: '10.0',
  refunds_count: 0,
  refunds_amount: '0.0',
  voided_count: 0,
  vat_breakdown: [],
  payment_methods: [{ payment_type: 'CASH', total_amount: '100.5', transaction_count: 3 }],
  opening_cash: '50.0',
  expected_cash: '150.5',
  variance: null,
  tolerance_summary: { totalAmount: '0.0', currencyCode: 'TND', writeoffCount: 0 },
};

describe('zReportHashService — additive normalization is legacy-safe', () => {
  it('a report_data WITHOUT cash_rounding_summary hashes to the frozen value', async () => {
    const hash = await computeZReportHash({
      previousHash: 'GENESIS',
      zNumber: 1,
      terminalId: '11111111-1111-1111-1111-111111111111',
      generatedAt: '2026-07-27T18:00:00+00:00',
      reportData: legacyReportData as never,
    });
    // Frozen on the pre-change implementation; ANY drift here means a
    // legacy-shape Z would re-hash differently after this change.
    expect(hash).toMatchSnapshot();
  });

  it('normalizes cash_rounding_summary.total_adjustment to scale 3 when present', () => {
    const out = normalizeForHash({
      ...legacyReportData,
      cash_rounding_summary: { total_adjustment: '-0.02', receipt_count: 4 },
    });
    expect((out['cash_rounding_summary'] as Record<string, unknown>)['total_adjustment'])
      .toBe('-0.020');
  });

  it('leaves a report_data without the key untouched', () => {
    const out = normalizeForHash({ ...legacyReportData });
    expect(out).not.toHaveProperty('cash_rounding_summary');
  });
});
```
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/fiscal/__tests__/zReportHashService.legacyStability.test.ts` — this WRITES the frozen snapshot against the current implementation. Commit the `.snap` before making any change to `normalizeForHash`; test 2 fails (no normalization yet).
- [ ] Implement — `zReportHashService.ts`, add after the `tolerance_summary` block (`:85-95`):
```ts
  // Additive (spec §4.3): mirrored EXACTLY in ZReportHashService.php. Per-key
  // isset-style normalization keeps a legacy report_data (no such key)
  // byte-identical, so v2-shape Zs re-hash unchanged forever.
  const cashRoundingSummary = result['cash_rounding_summary'];
  if (
    cashRoundingSummary !== null &&
    typeof cashRoundingSummary === 'object' &&
    !Array.isArray(cashRoundingSummary)
  ) {
    const crs = cashRoundingSummary as Record<string, unknown>;
    if (typeof crs['total_adjustment'] === 'string') {
      crs['total_adjustment'] = bcformat(crs['total_adjustment'], 3);
    }
  }
```
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/fiscal/__tests__/zReportHashService.legacyStability.test.ts src/lib/fiscal/__tests__/zReportHashService.test.ts`
- [ ] Expected: 3 + existing passing, snapshot UNCHANGED (that is the parity proof).
- [ ] Write the failing Z test `apps/pos/src/lib/offline/__tests__/zReportService.cashRounding.test.ts` (model the harness on the existing zReport tests; seed `offline_receipts` rows through the real migrations):
```ts
describe('generateZReport — real tolerance + local cash rounding summary', () => {
  it('aggregates tolerance_shortfall into tolerance_summary instead of the zero-shape', async () => {
    await seedReceipt({ total: '9.950', tolerance_shortfall: '0.050' });
    await seedReceipt({ total: '10.000', tolerance_shortfall: null });

    const z = await generateZReport(db, terminalId, shiftId, shiftOpenedAt, '0.000', {
      cashCounts: [{ payment_method_id: 'pm-cash', currency_code: 'TND', actual_amount: '19.950' }],
      ...fiscalCloseContext,
    });

    expect(z.report_data.tolerance_summary).toEqual({
      totalAmount: '0.050',
      currencyCode: 'TND',
      writeoffCount: 1,
    });
    expect(z.tolerance_summary).toEqual(z.report_data.tolerance_summary);
  });

  it('emits cash_rounding_summary into LOCAL report_data only', async () => {
    await seedReceipt({ total: '10.000', cash_rounding_adjustment: '0.003' });
    await seedReceipt({ total: '9.950', cash_rounding_adjustment: '-0.023' });

    const z = await generateZReport(db, terminalId, shiftId, shiftOpenedAt, '0.000', {
      cashCounts: [{ payment_method_id: 'pm-cash', currency_code: 'TND', actual_amount: '19.950' }],
      ...fiscalCloseContext,
    });

    expect(z.report_data.cash_rounding_summary).toEqual({
      total_adjustment: '-0.020',
      receipt_count: 2,
    });
  });

  it('keeps report_data.schema_version at 2 (a bump breaks Z hash parity)', async () => {
    await seedReceipt({ total: '10.000', cash_rounding_adjustment: '0.003' });
    const z = await generateZReport(db, terminalId, shiftId, shiftOpenedAt, '0.000', {
      cashCounts: [{ payment_method_id: 'pm-cash', currency_code: 'TND', actual_amount: '10.000' }],
      ...fiscalCloseContext,
    });
    expect(z.report_data.schema_version).toBe(2);
  });

  it('gross_sales sums the ROUNDED totals (what was collected)', async () => {
    await seedReceipt({ total: '10.000', cash_rounding_adjustment: '0.003' });
    const z = await generateZReport(db, terminalId, shiftId, shiftOpenedAt, '0.000', fiscalCloseContext);
    expect(z.report_data.gross_sales).toBe('10.000');
  });
});
```
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/offline/__tests__/zReportService.cashRounding.test.ts`
- [ ] Expected fail: `tolerance_summary` is the hardcoded zero-shape and `cash_rounding_summary` is undefined.
- [ ] Implement — `types.ts`, add to `ZReportData` after `tolerance_summary`:
```ts
  /**
   * Local-only rounding observability (spec §4.3). The SIGNED authority for
   * rounding is each SALE_RECEIPT; server-side this line is DERIVED in
   * ZReportProjection::legacyReportData() from projected
   * pos_receipts.cash_rounding_adjustment. Never a signed Z key in v1.
   */
  cash_rounding_summary?: { total_adjustment: string; receipt_count: number } | null;
```
- [ ] Implement — `zReportService.ts`, compute both summaries from the shift receipts BEFORE the cash-count block (the `receipts` array is already in scope from `:159-176`):
```ts
  // Real tolerance + rounding aggregation (spec §4.3; closes the
  // TODO(payment-tolerance-v3) zero-shape below). Both read the receipt-level
  // columns written by receiptService inside the fiscal transaction.
  let toleranceTotal = bcformat('0', decimals);
  let toleranceCount = 0;
  let roundingTotal = bcformat('0', decimals);
  let roundingCount = 0;
  for (const receipt of receipts) {
    const shortfall = receipt.tolerance_shortfall;
    if (shortfall !== null && shortfall !== undefined && bccomp(shortfall, '0') !== 0) {
      toleranceTotal = bcadd(toleranceTotal, shortfall, decimals);
      toleranceCount += 1;
    }
    const adjustment = receipt.cash_rounding_adjustment;
    if (adjustment !== null && adjustment !== undefined && bccomp(adjustment, '0') !== 0) {
      roundingTotal = bcadd(roundingTotal, adjustment, decimals);
      roundingCount += 1;
    }
  }
  const toleranceSummary = {
    totalAmount: bcformat(toleranceTotal, decimals),
    currencyCode: companyCurrency,
    writeoffCount: toleranceCount,
  };
  const cashRoundingSummary = roundingCount > 0
    ? { total_adjustment: bcformat(roundingTotal, decimals), receipt_count: roundingCount }
    : null;
```
  …then replace the hardcoded zero-shape at `:322-337` with:
```ts
    reportData.schema_version = 2;
    reportData.cash_counts = countEntries;
    reportData.tolerance_summary = toleranceSummary;
```
  …add the rounding summary unconditionally after the cash-count block (so a rounding-only shift without cash counts still records it), guarded so a shift with no rounded receipt emits NOTHING and keeps the legacy hash shape:
```ts
  if (cashRoundingSummary !== null) {
    reportData.cash_rounding_summary = cashRoundingSummary;
  }
```
  …and change `zReport.tolerance_summary` (`:415-417`) from the conditional zero-shape to:
```ts
    tolerance_summary: cashCountEntries !== null ? toleranceSummary : undefined,
```
  Note: `companyCurrency` is declared at `:317` today — move that declaration ABOVE the aggregation block. `bcadd`/`bccomp`/`bcformat` are already imported (`:14`).
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/offline/__tests__/zReportService.cashRounding.test.ts`
- [ ] Expected: 4 passing.
- [ ] Run the Z regression set: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/fiscal/__tests__/zSessionAuthoring.test.ts` plus every existing `zReportService*` suite under `src/lib/offline/__tests__/`.
- [ ] Implement — `endOfDayPreview.ts`: add `cash_rounding_adjustment: string | null; tolerance_shortfall: string | null;` to `OfflineReceiptRow` and to the `SELECT` list at `:133-136`; drop the dead `PaymentJsonRow.tolerance_writeoff` read (`:215-219`) in favour of a receipt-level accumulation inside the same loop:
```ts
    const shortfall = receipt.tolerance_shortfall;
    if (shortfall !== null && bccomp(shortfall, '0') !== 0) {
      toleranceTotal = bcadd(toleranceTotal, shortfall);
      toleranceCount += 1;
    }
    const adjustment = receipt.cash_rounding_adjustment;
    if (adjustment !== null && bccomp(adjustment, '0') !== 0) {
      roundingTotal = bcadd(roundingTotal, adjustment);
      roundingCount += 1;
    }
```
  …extend `EndOfDayPreview` and the return:
```ts
  cash_rounding_summary: { totalAdjustment: string; receiptCount: number } | null;
  /** Per-shift tender-tolerance auto-accepts so far (spec §8.1 guard). */
  tolerance_auto_accept_count: number;
```
```ts
    cash_rounding_summary:
      roundingCount > 0
        ? { totalAdjustment: bcformat(roundingTotal, scale), receiptCount: roundingCount }
        : null,
    tolerance_auto_accept_count: toleranceCount,
```
- [ ] Implement — `getOfflineReceiptForPrint.ts:126`, replace `tolerance_writeoff: null,` with:
```ts
    tolerance_writeoff: receipt.tolerance_shortfall,
    cash_rounding_adjustment: receipt.cash_rounding_adjustment,
```
  …adding `cash_rounding_adjustment?: string | null;` to `FullReceiptResponse` in `src/types/receipt.ts`.
- [ ] Implement — `printing.ts`, add to `ReceiptData` after `has_tolerance`:
```ts
  /** Signed cash-rounding adjustment in customer-facing currency. Null when unrounded. */
  cash_rounding_adjustment?: string | null;
  /**
   * Precomputed flag for the Rust formatter: true when cash_rounding_adjustment
   * is a non-zero amount. Computed on the TS boundary with arbitrary-precision
   * decimal so Rust never parses a monetary string.
   */
  has_cash_rounding?: boolean;
```
  …and to `ReceiptLabels` after `rounding?: string;`:
```ts
  /** Label for the tolerance write-off line (distinct from the rounding line). */
  tolerance?: string;
```
- [ ] Implement — `buildReceiptData.ts:143-147, 214-217`, mirror the tolerance pattern for the adjustment:
```ts
  const cashRoundingAdjustment = receipt.cash_rounding_adjustment ?? null;
  const hasCashRounding =
    cashRoundingAdjustment !== null
    && cashRoundingAdjustment !== ''
    && bccomp(cashRoundingAdjustment, '0') !== 0;
```
```ts
    cash_rounding_adjustment: hasCashRounding ? bcformat(cashRoundingAdjustment as string, scale) : null,
    has_cash_rounding: hasCashRounding,
```
  …set `cash_rounding_adjustment: null, has_cash_rounding: false` at the three non-sale builders (`:316-317`, `:375-376`, `:456-457`), and add to `buildReceiptLabels`:
```ts
    tolerance: t('tolerance'),
```
- [ ] Implement — `src-tauri/src/printing/receipt_template.rs`: add the two fields beside the tolerance pair (`:39-49`), add `pub tolerance: Option<String>` to the label struct beside `rounding` (`:128`), and replace the print block (`:652-664`) with:
```rust
            // ── Cash rounding (signed) ──
            // Printed verbatim: the adjustment can be positive or negative and
            // the TS boundary already formatted it at currency scale.
            if data.has_cash_rounding.unwrap_or(false) {
                if let Some(ref adjustment) = data.cash_rounding_adjustment {
                    b.two_column(
                        &data.label(|l| &l.rounding, "Rounding"),
                        &format!("{}{}", data.currency_symbol, adjustment),
                    );
                }
            }

            // ── Tolerance write-off ──
            if data.has_tolerance {
                if let Some(ref tolerance) = data.tolerance_writeoff {
                    b.two_column(
                        &data.label(|l| &l.tolerance, "Tolerance"),
                        &format!("-{}{}", data.currency_symbol, tolerance),
                    );
                }
            }
```
  …and add `cash_rounding_adjustment: None, has_cash_rounding: None` to the Rust test fixtures at `:985-986` and `:1053`.
- [ ] Implement — i18n, add to `receiptLabel` in both locales: `"tolerance"` (`en`: `"Tolerance"`, `fr`: `"Écart accepté"`). `receiptLabel.rounding` already exists (`en:834`, `fr:834`) and keeps its value.
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm vitest run src/lib/offline/__tests__/endOfDayPreview.test.ts src/lib/offline/__tests__/getOfflineReceiptForPrint.test.ts src/lib/__tests__/buildReceiptData.test.ts src/lib/__tests__/printing.test.ts`
- [ ] Run the Rust formatter tests: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos/src-tauri && cargo test printing::receipt_template`
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm typecheck && pnpm lint`
- [ ] Commit: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding && git add apps/pos/src/lib/offline apps/pos/src/lib/fiscal/zReportHashService.ts apps/pos/src/lib/fiscal/__tests__/zReportHashService.legacyStability.test.ts apps/pos/src/lib/buildReceiptData.ts apps/pos/src/lib/printing.ts apps/pos/src/types/receipt.ts apps/pos/src-tauri/src/printing/receipt_template.rs apps/pos/src/locales/en/pos.json apps/pos/src/locales/fr/pos.json && git commit -m "$(cat <<'EOF'
POS cash rounding T10: device Z, hash mirror, EOD preview, print, i18n

tolerance_summary carries real values, cash_rounding_summary lands in LOCAL
report_data only, schema_version stays 2, and the additive normalization is
mirrored in the device zReportHashService with a frozen legacy-shape hash
snapshot. EOD surfaces both summaries; the ticket prints a signed rounding
line and a separately-labelled tolerance line.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"`

---

### Task 11: Phase-2 deploy checklist + device smoke notes

**Files:**
- `docs/handoff/cash-rounding-phase2-deploy-checklist.md` (NEW) — style-match `docs/handoff/treasury-phase3-deploy-checklist.md`.
- No code changes.

**Content contract (spec §7 Phase 2):**
1. **Terminal cutover verification BEFORE `--enable`** — every terminal in a rounding-enabled tenant must already be at `fiscal_schema_version = 3` (`FiscalSchemaCutoverService`). The device-side rounding gate is what forecloses `GrandtotalService`/server-Z poisoning; a terminal left at 2 simply never rounds, but a tenant enabled with mixed versions gives cashiers inconsistent totals across lanes.
2. **Order:** Plan A (server) fully deployed → `accounting:backfill-tolerance-purposes` applied → `pos:configure-cash-rounding --verify` clean (row state + ≥1 `is_cash_tender` method per company) → terminal cutover verified → `pos:configure-cash-rounding --country=TN --enable` → POS build v63 rollout.
3. **Kill-switch semantics:** `cash_rounding_enabled` and `pos_tolerance_enabled` are INDEPENDENT and NEITHER touches the B2B `payment_tolerance_enabled`. `--disable` takes effect at the next device sync tick; offline devices keep signing verifiable receipts against the policy they last saw (the signed denomination makes them verifiable forever, and the projection's `pos.rounding.policy_mismatch` alert flags the drift).
4. **Rollback:** rolling the device build back to v62 restores v2 authoring immediately; no data migration is required because the three SQLite columns are additive and nullable, and every v3 receipt already synced stays valid forever.

Steps:

- [ ] Write `docs/handoff/cash-rounding-phase2-deploy-checklist.md` with these sections:
```markdown
# POS cash rounding — Phase 2 (enable + device) checklist

Date: 2026-07-27
Scope: enabling cash rounding / POS tender tolerance for a tenant and rolling
out the POS build that authors SALE_RECEIPT v3.
Spec: docs/superpowers/specs/2026-07-27-pos-cash-rounding-tolerance-design.md §7.

## Hard preconditions (do not start otherwise)

- [ ] Phase 1 (server) is deployed to this environment and `tenants:migrate` completed for every tenant.
- [ ] `accounting:backfill-tolerance-purposes` has been run (dry-run reviewed, then applied); every company resolves `6580` and `7580`.
- [ ] `pos:configure-cash-rounding --verify` reports, per tenant: the `country_payment_settings` row exists with the intended values, AND every company has at least one `is_cash_tender` payment method. A company failing the second assertion loses cash checkout the moment the device build ships.
- [ ] Horizon / queue workers and the API processes have been restarted onto the Phase-1 release.

## Terminal cutover verification (BEFORE enabling)

- [ ] List every terminal for the target tenant and confirm `fiscal_schema_version = 3`. Terminals still at 2 will NOT round (the device gate is fail-closed) — that is safe, but it means two lanes charge different totals for the same basket.
- [ ] Cut over any stragglers via `FiscalSchemaCutoverService` and re-verify before continuing.

## Enable

- [ ] `pos:configure-cash-rounding --country=TN --denomination=0.050 --enable --dry-run`, review the diff.
- [ ] Re-run without `--dry-run`.
- [ ] Confirm `GET /api/v1/pos/payment-policy` returns `cashRoundingEnabled: true` and `cashRoundingDenomination: "0.050"` — as a STRING with the trailing zero. A response of `0.05` means a float crept into the DTO; STOP and fix before any device rounds.

## Device rollout

- [ ] Ship the POS build carrying SQLite schema v63 and v3 authoring.
- [ ] On first launch confirm migration 63 applied (`SELECT version FROM _migrations ORDER BY version DESC LIMIT 1` = 63).
- [ ] Confirm the device pulled the policy: `SELECT * FROM payment_policy_cache` shows the tenant row with `cash_rounding_denomination = '0.050'`.
- [ ] Confirm `SELECT code, is_cash_tender FROM payment_methods` marks exactly the `CASH` method.

## Post-enable verification

- [ ] Ring a cash sale totalling 9.997 TND: the cash screen shows 10.000 due and a `Rounding +0.003` line; the printed ticket shows the same.
- [ ] Ring a cash sale totalling 9.973 TND tendered at 9.900: completes with NO manager PIN; the ticket shows the rounding line and the tolerance line.
- [ ] Attempt a card-only over-tender: refused with the change-eligibility message.
- [ ] Ring a voucher-partial sale: the total is EXACT (no rounding line).
- [ ] Close the shift: the Z shows a non-zero `tolerance_summary` and a `cash_rounding_summary`; the Z pushes without a chain error.
- [ ] Server side: the receipts project with `cash_rounding_adjustment` populated, the GL carries the `pos_cash_rounding` and `pos_tolerance_bridge` entries, and NO `pos.rounding.policy_mismatch` / `pos.change.exceeds_cash_legs` audit events were emitted.

## Kill switches

- [ ] `pos:configure-cash-rounding --disable` turns rounding off; `--disable` on the tolerance sub-flag turns POS auto-accept off. They are independent, and NEITHER affects the B2B `payment_tolerance_enabled` used by invoice write-offs.
- [ ] A disable reaches each device on its next sync tick. Offline devices keep rounding against the last policy they saw; those receipts remain verifiable because the denomination is inside the signed bytes.

## Rollback

- [ ] Roll the POS build back to the previous version: the device returns to v2 authoring immediately. The v63 SQLite columns are additive and nullable, so no data migration is needed and already-synced v3 receipts stay valid.
```
- [ ] Add a short "device smoke" appendix listing the exact vitest paths a reviewer should re-run for this track:
```markdown
## Device smoke (test paths)

cd apps/pos && pnpm vitest run \
  src/lib/db/__tests__/migrations.v63.test.ts \
  src/lib/db/repositories/__tests__/paymentRepository.isCashTender.test.ts \
  src/stores/__tests__/paymentPolicyStore.test.ts \
  src/lib/payment/__tests__/cashRounding.test.ts \
  src/lib/payment/__tests__/cartTotals.test.ts \
  src/lib/payment/__tests__/checkoutPolicySnapshot.test.ts \
  src/stores/__tests__/paymentStore.cashRounding.test.ts \
  src/stores/__tests__/paymentStore.changeEligibility.test.ts \
  src/lib/fiscal/payloads/__tests__/SaleReceiptV3Payload.test.ts \
  src/lib/fiscal/payloads/__tests__/SaleReceiptV1V2ByteStability.test.ts \
  src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts \
  src/lib/fiscal/__tests__/zReportHashService.legacyStability.test.ts \
  src/lib/offline/__tests__/receiptService.cashRounding.test.ts \
  src/lib/offline/__tests__/zReportService.cashRounding.test.ts
```
- [ ] Run the full device smoke list above and record the result in the checklist.
- [ ] Run: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding/apps/pos && pnpm typecheck && pnpm lint`
- [ ] Commit: `cd /Users/houssamr/Projects/syneriva/apps/erp.cash-rounding && git add docs/handoff/cash-rounding-phase2-deploy-checklist.md && git commit -m "$(cat <<'EOF'
POS cash rounding T11: Phase-2 deploy checklist + device smoke paths

Terminal cutover verification before --enable, the enable ordering behind
Plan A, independent kill-switch semantics that never touch B2B tolerance,
build-rollback safety, and the exact vitest paths for the device smoke.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
EOF
)"`

---

## Done criteria

- [ ] All 11 tasks committed on `feat/pos-cash-rounding`.
- [ ] `cd apps/pos && pnpm typecheck && pnpm lint` clean.
- [ ] The device smoke list in Task 11 is green.
- [ ] `pnpm vitest run src/lib/fiscal/__tests__/FiscalPayloadKeyDrift.test.ts` proves the TS v3 key set byte-mirrors the PHP named const at 30 sorted keys.
- [ ] The `zReportHashService.legacyStability` snapshot is UNCHANGED from the value frozen before `normalizeForHash` was touched.
- [ ] `git log --oneline` shows no `apps/api` file in any commit of this track.
- [ ] Adversarial review dispatched (`fiscal-pos-reviewer` for Tasks 4/6/8/9/10, `frontend-conventions-reviewer` for Tasks 5/6/7) before the branch is merged into local `dev`.
