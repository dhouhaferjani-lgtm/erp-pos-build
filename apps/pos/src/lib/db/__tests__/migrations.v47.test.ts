/**
 * Migration v47 — `local_refund_records` (Phase 4, fiscal audit B2).
 *
 * Record-at-settle: when the device settles a refund via
 * POST /pos/receipts/{id}/return, the server return receipt is mirrored into
 * this table so the device Z-report can fold REAL refund totals into the
 * signed Z instead of hardcoded zeros.
 *
 * Exercises the real migration against a real SQLite engine, then round-trips
 * a record through the repository.
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { migrations } from '@/lib/db/migrations';
import { SqliteTestAdapter } from './helpers/sqliteTestAdapter';
import {
  insertLocalRefundRecord,
  getRefundRecordsForShift,
  type LocalRefundRecord,
} from '@/lib/db/repositories/localRefundRecordRepository';

async function runMigrationsUpTo(adapter: SqliteTestAdapter, maxVersion: number): Promise<void> {
  for (const migration of migrations) {
    if (migration.version > maxVersion) continue;
    if (migration.run) {
      await migration.run(adapter.asDatabase());
    } else if (migration.sql) {
      await adapter.execute(migration.sql);
    }
  }
}

interface ColumnInfo {
  name: string;
  type: string;
  notnull: number;
  pk: number;
}

function makeRecord(overrides: Partial<LocalRefundRecord> = {}): LocalRefundRecord {
  return {
    id: 'return-receipt-uuid-1',
    receipt_number: 'L01-T01-00043',
    original_receipt_number: 'L01-T01-00042',
    shift_id: 'shift-1',
    terminal_id: 'term-1',
    destination: 'cash',
    total: '-23.80',
    cash_impact: '23.80',
    currency: 'EUR',
    settled_at: '2026-06-10T09:00:00Z',
    ...overrides,
  };
}

describe('migration v47 — local_refund_records', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await runMigrationsUpTo(adapter, 47);
  });

  afterEach(() => {
    adapter.close();
  });

  it('creates the local_refund_records table with the expected columns', async () => {
    const columns = await adapter.select<ColumnInfo[]>(
      "PRAGMA table_info('local_refund_records')",
    );
    const byName = new Map(columns.map((c) => [c.name, c]));

    expect(byName.get('id')?.pk).toBe(1);
    for (const required of [
      'receipt_number',
      'original_receipt_number',
      'shift_id',
      'terminal_id',
      'destination',
      'total',
      'cash_impact',
      'currency',
      'settled_at',
    ]) {
      expect(byName.get(required)?.notnull, `${required} NOT NULL`).toBe(1);
    }
  });

  it('indexes shift_id (the Z aggregation lookup key)', async () => {
    const indexes = await adapter.select<Array<{ name: string }>>(
      "PRAGMA index_list('local_refund_records')",
    );
    expect(indexes.map((i) => i.name)).toContain('idx_local_refund_records_shift');
  });

  it('is re-runnable (CREATE TABLE IF NOT EXISTS)', async () => {
    const v47 = migrations.find((m) => m.version === 47);
    expect(v47).toBeDefined();
    await expect(adapter.execute(v47!.sql)).resolves.toBeDefined();
  });

  describe('repository round-trip', () => {
    it('inserts a record and reads it back by shift', async () => {
      const db = adapter.asDatabase();
      await insertLocalRefundRecord(db, makeRecord());

      const rows = await getRefundRecordsForShift(db, 'shift-1');
      expect(rows).toHaveLength(1);
      expect(rows[0]).toMatchObject({
        id: 'return-receipt-uuid-1',
        receipt_number: 'L01-T01-00043',
        original_receipt_number: 'L01-T01-00042',
        shift_id: 'shift-1',
        terminal_id: 'term-1',
        destination: 'cash',
        total: '-23.80',
        cash_impact: '23.80',
        currency: 'EUR',
        settled_at: '2026-06-10T09:00:00Z',
      });
    });

    it('is idempotent on the server return receipt id (settle retries cannot double-count)', async () => {
      const db = adapter.asDatabase();
      await insertLocalRefundRecord(db, makeRecord());
      await insertLocalRefundRecord(db, makeRecord());

      const rows = await getRefundRecordsForShift(db, 'shift-1');
      expect(rows).toHaveLength(1);
    });

    it('scopes reads to the requested shift_id', async () => {
      const db = adapter.asDatabase();
      await insertLocalRefundRecord(db, makeRecord());
      await insertLocalRefundRecord(
        db,
        makeRecord({ id: 'return-receipt-uuid-2', shift_id: 'shift-OTHER' }),
      );

      const rows = await getRefundRecordsForShift(db, 'shift-1');
      expect(rows).toHaveLength(1);
      expect(rows[0]!.id).toBe('return-receipt-uuid-1');
    });

    it('rejects a destination outside the server RefundDestination enum', async () => {
      const db = adapter.asDatabase();
      await expect(
        insertLocalRefundRecord(
          db,
          makeRecord({ destination: 'bank_transfer' as LocalRefundRecord['destination'] }),
        ),
      ).rejects.toThrow();
    });
  });
});
