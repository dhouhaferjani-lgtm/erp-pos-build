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
