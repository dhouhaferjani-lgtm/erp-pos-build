import { ApiRequestError } from '@/lib/api';
import { getDatabase, queryAll } from '@/lib/db';
import { pushOfflineReceipts } from '@/lib/sync/syncService';

interface FiscalSyncRow {
  id: string;
  sync_status: 'pending' | 'syncing' | 'synced' | 'failed';
  sync_error: string | null;
}

function hasOnlyTransientFailures(rows: FiscalSyncRow[]): boolean {
  return rows.length > 0 && rows.every((row) => {
    const message = (row.sync_error ?? '').toLowerCase();
    return (
      message.includes('failed to fetch') ||
      message.includes('network') ||
      message.includes('timeout') ||
      message.includes('timed out') ||
      message.includes('server url')
    );
  });
}

export async function ensureApprovalFiscalEventsSynced(
  companyId: string,
  eventIds: string[],
): Promise<void> {
  if (eventIds.length === 0) return;

  const uniqueIds = [...new Set(eventIds)];
  const db = await getDatabase(companyId);
  await pushOfflineReceipts(db);

  const placeholders = uniqueIds.map((_, index) => `$${String(index + 1)}`).join(', ');
  const rows = await queryAll<FiscalSyncRow>(
    db,
    `SELECT id, sync_status, sync_error FROM fiscal_events WHERE id IN (${placeholders})`,
    uniqueIds,
  );

  const found = new Set(rows.map((row) => row.id));
  if (uniqueIds.some((id) => !found.has(id))) {
    throw new ApiRequestError(
      409,
      'Approval fiscal event is missing from the local ledger.',
      'APPROVAL_FISCAL_EVENT_MISSING',
    );
  }

  const failed = rows.filter((row) => row.sync_status === 'failed');
  if (failed.length > 0) {
    if (hasOnlyTransientFailures(failed)) {
      throw new Error('Approval fiscal event could not be synced yet.');
    }

    throw new ApiRequestError(
      409,
      'Approval fiscal event was rejected by fiscal sync.',
      'APPROVAL_FISCAL_SYNC_REJECTED',
      { event_ids: failed.map((row) => row.id) },
    );
  }

  const notSynced = rows.filter((row) => row.sync_status !== 'synced');
  if (notSynced.length > 0) {
    throw new Error('Approval fiscal event is not synced yet.');
  }
}
