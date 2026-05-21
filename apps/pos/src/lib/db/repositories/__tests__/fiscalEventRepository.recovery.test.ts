import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { migrations } from '@/lib/db/migrations';
import { recoverStrandedSyncingFiscalEvents } from '../fiscalEventRepository';

const nodeSqliteAvailable = (() => {
  try {
    return Boolean(require('node:sqlite').DatabaseSync);
  } catch {
    return false;
  }
})();

const d = nodeSqliteAvailable ? describe : describe.skip;

async function runAllMigrations(adapter: SqliteTestAdapter): Promise<void> {
  for (const m of migrations) {
    if (m.run) {
      await m.run(adapter);
    } else if (m.sql) {
      await adapter.execute(m.sql);
    }
  }
}

async function insertFiscalEvent(
  adapter: SqliteTestAdapter,
  id: string,
  status: 'pending' | 'syncing' | 'synced' | 'failed',
  sequenceNumber: number,
): Promise<void> {
  await adapter.execute(
    `INSERT INTO fiscal_events (
      id, tenant_id, company_id, terminal_id, operator_id,
      event_type, event_version, signature_version, sequence_number,
      event_time_device, business_date, canonical_bytes,
      previous_hash, current_hash, sync_status, created_at
    ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16)`,
    [
      id,
      'tenant-1',
      'company-1',
      'terminal-1',
      'operator-1',
      'SALE_RECEIPT',
      1,
      'v1',
      sequenceNumber,
      '2026-05-20T12:00:00Z',
      '2026-05-20',
      '{"event_type":"SALE_RECEIPT"}',
      '0'.repeat(64),
      String(sequenceNumber).repeat(64).slice(0, 64).padEnd(64, '1'),
      status,
      '2026-05-20T12:00:00Z',
    ],
  );
}

d('recoverStrandedSyncingFiscalEvents — boot-time crash recovery', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await runAllMigrations(adapter);
  });

  afterEach(() => {
    adapter.close();
  });

  it('demotes stranded syncing fiscal events to pending', async () => {
    await insertFiscalEvent(adapter, 'fe-syncing', 'syncing', 1);

    const recovered = await recoverStrandedSyncingFiscalEvents(adapter.asDatabase());

    expect(recovered).toBe(1);

    const rows = await adapter.select<{ id: string; sync_status: string; sync_error: string | null }[]>(
      'SELECT id, sync_status, sync_error FROM fiscal_events WHERE id = $1',
      ['fe-syncing'],
    );
    expect(rows).toEqual([{ id: 'fe-syncing', sync_status: 'pending', sync_error: null }]);
  });

  it('is idempotent and leaves non-syncing lifecycle states alone', async () => {
    await insertFiscalEvent(adapter, 'fe-pending', 'pending', 1);
    await insertFiscalEvent(adapter, 'fe-failed', 'failed', 2);
    await insertFiscalEvent(adapter, 'fe-synced', 'synced', 3);
    await insertFiscalEvent(adapter, 'fe-syncing', 'syncing', 4);

    const first = await recoverStrandedSyncingFiscalEvents(adapter.asDatabase());
    const second = await recoverStrandedSyncingFiscalEvents(adapter.asDatabase());

    expect(first).toBe(1);
    expect(second).toBe(0);

    const rows = await adapter.select<{ id: string; sync_status: string }[]>(
      'SELECT id, sync_status FROM fiscal_events ORDER BY id',
    );
    expect(rows).toEqual([
      { id: 'fe-failed', sync_status: 'failed' },
      { id: 'fe-pending', sync_status: 'pending' },
      { id: 'fe-synced', sync_status: 'synced' },
      { id: 'fe-syncing', sync_status: 'pending' },
    ]);
  });
});
