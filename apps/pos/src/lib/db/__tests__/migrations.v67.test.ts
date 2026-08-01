import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { applyAllMigrations, runMigrationsUpTo, runMigrationVersion } from './helpers/migrationTestHelpers';
import { SqliteTestAdapter } from './helpers/sqliteTestAdapter';
import { getCompanyFraudSettings } from '@/lib/db/repositories/companyFraudSettingsCacheRepository';
import { getShiftRefundReceiptTotals } from '@/lib/db/repositories/offlineReceiptRepository';
import { countUnsyncedFiscalEvents } from '@/lib/db/repositories/fiscalEventRepository';
import {
  DEFAULT_OFFLINE_REFUND_COUNT_CEILING,
  DEFAULT_OFFLINE_REFUND_VALUE_CEILING,
  DEFAULT_ONLINE_REQUIRED_REFUND_THRESHOLD,
} from '@/lib/refundFlow/refundExposureDefaults';

/**
 * Lane C M2/M3 — the device half of the refund-exposure policies, against
 * REAL SQLite.
 *
 * Three things can only fail here and nowhere else:
 *   1. the migration's column DEFAULTS, which ARE the "absent setting behaves
 *      as a sane default" contract for a device whose fraud-settings row was
 *      written before this app version;
 *   2. the shift window's TEXT-timestamp comparison — CLAUDE.md rule 20's
 *      `' ' < 'T'` trap, where an ISO boundary silently excludes every
 *      same-day row and so RAISES the ceiling;
 *   3. the `receipt_kind`/`is_training` filters on the velocity aggregate.
 */

interface ColumnInfo {
  name: string;
  type: string;
  notnull: number;
  pk: number;
  dflt_value: string | null;
}

async function columnsOf(adapter: SqliteTestAdapter, table: string): Promise<Map<string, ColumnInfo>> {
  const columns = await adapter.select<ColumnInfo[]>(`PRAGMA table_info(${table})`);
  return new Map(columns.map((c) => [c.name, c]));
}

const HASH_A = 'a'.repeat(64);
const HASH_B = 'b'.repeat(64);

async function insertReceipt(
  adapter: SqliteTestAdapter,
  row: {
    id: string;
    terminalId: string;
    total: string;
    hashSequence: number;
    receiptKind: 'sale' | 'refund';
    createdAt: string;
    isTraining?: 0 | 1;
  },
): Promise<void> {
  await adapter.execute(
    `INSERT INTO offline_receipts (
       id, idempotency_key, receipt_number, terminal_id, terminal_code, operator_id,
       operator_name, lines, subtotal, tax_amount, discount_amount, total, currency,
       fiscal_hash, previous_hash, hash_sequence, payment_method_id, payment_repository_id,
       status, payments_json, receipt_kind, is_training, created_at
     ) VALUES ($1, $1, $1, $2, 'T01', 'op1', 'Alice', '[]', '0', '0', '0', $3, 'TND',
       $4, $5, $6, 'pm1', 'pr1', 'pending', '[]', $7, $8, $9)`,
    [
      row.id,
      row.terminalId,
      row.total,
      HASH_A,
      HASH_B,
      row.hashSequence,
      row.receiptKind,
      row.isTraining ?? 0,
      row.createdAt,
    ],
  );
}

describe('Migration v67 — refund-exposure policies on company_fraud_settings_cache', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(() => {
    adapter = new SqliteTestAdapter();
  });

  afterEach(() => {
    adapter.close();
  });

  it('adds the three columns NOT NULL with the SEEDED defaults', async () => {
    await runMigrationsUpTo(adapter, 66);
    await runMigrationVersion(adapter, 67);

    const byName = await columnsOf(adapter, 'company_fraud_settings_cache');

    expect(byName.get('offline_refund_count_ceiling')).toMatchObject({
      type: 'INTEGER',
      notnull: 1,
      dflt_value: String(DEFAULT_OFFLINE_REFUND_COUNT_CEILING),
    });
    expect(byName.get('offline_refund_value_ceiling')).toMatchObject({
      type: 'TEXT',
      notnull: 1,
      dflt_value: `'${DEFAULT_OFFLINE_REFUND_VALUE_CEILING}'`,
    });
    expect(byName.get('online_required_refund_threshold')).toMatchObject({
      type: 'TEXT',
      notnull: 1,
      dflt_value: `'${DEFAULT_ONLINE_REQUIRED_REFUND_THRESHOLD}'`,
    });
  });

  it('a row written BEFORE this version reads back the seeded defaults, not nulls', async () => {
    await runMigrationsUpTo(adapter, 66);
    await adapter.execute(
      `INSERT INTO company_fraud_settings_cache (
         company_id, cash_variance_over_soft, cash_variance_over_hard,
         cash_variance_under_soft, cash_variance_under_hard
       ) VALUES ('co-legacy', '1.0000', '20.0000', '1.0000', '20.0000')`,
    );

    await runMigrationVersion(adapter, 67);

    const settings = await getCompanyFraudSettings(adapter.asDatabase(), 'co-legacy');
    expect(settings?.offline_refund_count_ceiling).toBe(DEFAULT_OFFLINE_REFUND_COUNT_CEILING);
    expect(settings?.offline_refund_value_ceiling).toBe(DEFAULT_OFFLINE_REFUND_VALUE_CEILING);
    expect(settings?.online_required_refund_threshold).toBe(
      DEFAULT_ONLINE_REQUIRED_REFUND_THRESHOLD,
    );
  });

  it('is idempotent — re-running v67 does not error and preserves rows', async () => {
    await runMigrationsUpTo(adapter, 67);
    await adapter.execute(
      `INSERT INTO company_fraud_settings_cache (
         company_id, cash_variance_over_soft, cash_variance_over_hard,
         cash_variance_under_soft, cash_variance_under_hard,
         offline_refund_count_ceiling
       ) VALUES ('co-1', '1.0000', '20.0000', '1.0000', '20.0000', 2)`,
    );

    await runMigrationVersion(adapter, 67);

    const settings = await getCompanyFraudSettings(adapter.asDatabase(), 'co-1');
    expect(settings?.offline_refund_count_ceiling).toBe(2);
  });
});

describe('M2 shift-window aggregates — real SQLite', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await applyAllMigrations(adapter);
  });

  afterEach(() => {
    adapter.close();
  });

  it('anchor window selects ONLY this terminal\'s non-training refunds above the opening sequence', async () => {
    await insertReceipt(adapter, {
      id: 'before-anchor', terminalId: 't1', total: '-9.000',
      hashSequence: 10, receiptKind: 'refund', createdAt: '2026-07-31 07:00:00',
    });
    await insertReceipt(adapter, {
      id: 'in-window-refund', terminalId: 't1', total: '-5.000',
      hashSequence: 11, receiptKind: 'refund', createdAt: '2026-07-31 09:00:00',
    });
    await insertReceipt(adapter, {
      id: 'in-window-sale', terminalId: 't1', total: '30.000',
      hashSequence: 12, receiptKind: 'sale', createdAt: '2026-07-31 09:05:00',
    });
    await insertReceipt(adapter, {
      id: 'training-refund', terminalId: 't1', total: '-99.000',
      hashSequence: 13, receiptKind: 'refund', createdAt: '2026-07-31 09:10:00', isTraining: 1,
    });
    await insertReceipt(adapter, {
      id: 'other-terminal', terminalId: 't2', total: '-77.000',
      hashSequence: 14, receiptKind: 'refund', createdAt: '2026-07-31 09:15:00',
    });

    const totals = await getShiftRefundReceiptTotals(adapter.asDatabase(), 't1', {
      kind: 'anchor',
      openingHashSequence: 10,
    });

    expect(totals).toEqual(['-5.000']);
  });

  it('openedAt window includes SAME-DAY rows (rule 20: SQLite TEXT timestamps use a SPACE separator)', async () => {
    await insertReceipt(adapter, {
      id: 'previous-shift', terminalId: 't1', total: '-4.000',
      hashSequence: 1, receiptKind: 'refund', createdAt: '2026-07-31 07:59:59',
    });
    await insertReceipt(adapter, {
      id: 'this-shift', terminalId: 't1', total: '-6.000',
      hashSequence: 2, receiptKind: 'refund', createdAt: '2026-07-31 08:00:01',
    });

    // The SAME normalization `beginV4()` applies: an ISO 'T' boundary here
    // would compare lexicographically ABOVE every same-day row and return
    // an empty set — silently disabling the ceiling.
    const totals = await getShiftRefundReceiptTotals(adapter.asDatabase(), 't1', {
      kind: 'openedAt',
      openedAtSqliteUtc: '2026-07-31 08:00:00',
    });

    expect(totals).toEqual(['-6.000']);
  });

  it('counts every non-synced fiscal event for the terminal, including stranded syncing rows', async () => {
    let sequence = 0;
    const insertEvent = async (id: string, terminalId: string, status: string): Promise<void> => {
      sequence += 1;
      await adapter.execute(
        `INSERT INTO fiscal_events (
           id, tenant_id, company_id, terminal_id, operator_id, event_type, event_version,
           signature_version, sequence_number, event_time_device, business_date,
           chain_context, canonical_bytes, previous_hash, current_hash, sync_status,
           created_at
         ) VALUES ($1, 'tn1', 'co1', $2, 'op1', 'SALE_RECEIPT', 3, 1, $6,
           '2026-07-31T09:00:00Z', '2026-07-31', 'operational', '{}', $3, $4, $5,
           '2026-07-31 09:00:00')`,
        [id, terminalId, HASH_A, HASH_B, status, sequence],
      );
    };

    await insertEvent('e-pending', 't1', 'pending');
    await insertEvent('e-syncing', 't1', 'syncing');
    await insertEvent('e-failed', 't1', 'failed');
    await insertEvent('e-synced', 't1', 'synced');
    await insertEvent('e-other-terminal', 't2', 'pending');

    expect(await countUnsyncedFiscalEvents(adapter.asDatabase(), 't1')).toBe(3);
    expect(await countUnsyncedFiscalEvents(adapter.asDatabase(), 't2')).toBe(1);
  });
});
