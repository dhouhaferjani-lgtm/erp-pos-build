import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
  runMigrationsUpTo,
  runMigrationVersion,
} from './helpers/migrationTestHelpers';
import { SqliteTestAdapter } from './helpers/sqliteTestAdapter';

interface ColumnInfo {
  name: string;
  type: string;
  notnull: number;
}

describe('Migration v61 — add suggested quantity to replenishment cache', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(() => {
    adapter = new SqliteTestAdapter();
  });

  afterEach(() => {
    adapter.close();
  });

  it('adds a nullable TEXT column without losing existing cache rows and is idempotent', async () => {
    await runMigrationsUpTo(adapter, 60);
    await adapter.execute(
      `INSERT INTO open_replenishment_cache (
         tenant_id, company_id, request_id, product_id, variant_id, status,
         requested_qty, request_count, last_requested_at, fetched_at
       ) VALUES ($1, $2, $3, $4, '', 'pending', NULL, 1, $5, $5)`,
      [
        'tenant-1',
        'company-1',
        'request-1',
        'product-1',
        '2026-07-19T08:00:00.000Z',
      ],
    );

    await runMigrationVersion(adapter, 61);

    const columns = await adapter.select<ColumnInfo[]>(
      'PRAGMA table_info(open_replenishment_cache)',
    );
    const suggestedQuantity = columns.find((column) => column.name === 'suggested_qty');
    expect(suggestedQuantity).toMatchObject({ type: 'TEXT', notnull: 0 });
    await expect(
      adapter.select<Array<{ request_id: string; suggested_qty: string | null }>>(
        'SELECT request_id, suggested_qty FROM open_replenishment_cache',
      ),
    ).resolves.toEqual([{ request_id: 'request-1', suggested_qty: null }]);

    await expect(runMigrationVersion(adapter, 61)).resolves.toBeUndefined();
  });
});
