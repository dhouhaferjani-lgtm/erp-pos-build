import type Database from '@tauri-apps/plugin-sql';
import {
  fetchOpenReplenishment,
  pushReplenishmentRequest,
  type ServerReplenishmentRow,
} from '@/api/replenishmentApi';
import { ApiRequestError } from '@/lib/api';
import { replaceOpenRequests } from '@/lib/db/repositories/openReplenishmentRepository';
import {
  getPendingReplenishmentRequests,
  markReplenishmentFailed,
  markReplenishmentResolved,
  type ReplenishmentOutboxRow,
} from '@/lib/db/repositories/replenishmentOutboxRepository';

export class ReplenishmentSyncScopeError extends Error {
  constructor(
    readonly expectedTenantId: string,
    readonly expectedCompanyId: string,
    readonly row: Pick<
      ReplenishmentOutboxRow,
      'client_request_uuid' | 'tenant_id' | 'company_id'
    >,
  ) {
    super(
      `[replenishment] Request ${row.client_request_uuid} is outside active scope: ` +
        `tenant=${row.tenant_id} company=${row.company_id}.`,
    );
    this.name = 'ReplenishmentSyncScopeError';
  }
}

export class ReplenishmentSyncResponseError extends Error {
  constructor(message: string) {
    super(`[replenishment] ${message}`);
    this.name = 'ReplenishmentSyncResponseError';
  }
}

export async function pushReplenishmentRequests(
  db: Database,
  tenantId: string,
  companyId: string,
): Promise<number> {
  const pending = await getPendingReplenishmentRequests(db, tenantId, companyId);
  let resolved = 0;

  for (const row of pending) {
    try {
      if (row.tenant_id !== tenantId || row.company_id !== companyId) {
        throw new ReplenishmentSyncScopeError(tenantId, companyId, row);
      }

      const response = await pushReplenishmentRequest({
        client_request_uuid: row.client_request_uuid,
        terminal_id: row.terminal_id,
        product_id: row.product_id,
        variant_id: row.variant_id,
        requested_qty: row.requested_qty,
        note: row.note,
      });
      assertPushResponse(row, response);
    } catch (error) {
      if (
        error instanceof ReplenishmentSyncResponseError ||
        error instanceof ReplenishmentSyncScopeError
      ) {
        await markReplenishmentFailed(
          db,
          tenantId,
          companyId,
          row.client_request_uuid,
          error.message,
        );
        continue;
      }
      if (error instanceof ApiRequestError && error.status !== 401 && error.status < 500) {
        await markReplenishmentFailed(
          db,
          tenantId,
          companyId,
          row.client_request_uuid,
          `${String(error.status)}: ${error.apiMessage}`,
        );
        continue;
      }
      throw error;
    }

    await markReplenishmentResolved(db, tenantId, companyId, row.client_request_uuid);
    resolved += 1;
  }

  return resolved;
}

export async function pullOpenReplenishment(
  db: Database,
  tenantId: string,
  companyId: string,
  terminalId: string,
): Promise<number> {
  const response = await fetchOpenReplenishment(terminalId);
  if (
    !Array.isArray(response.data) ||
    typeof response.as_of !== 'string' ||
    typeof response.truncated !== 'boolean'
  ) {
    throw new ReplenishmentSyncResponseError('Server returned an invalid pull-feed envelope.');
  }
  for (const row of response.data) {
    assertServerRow(row);
  }

  await replaceOpenRequests(
    db,
    tenantId,
    companyId,
    response.data,
    response.as_of,
    !response.truncated,
  );
  return response.data.length;
}

function assertPushResponse(
  request: ReplenishmentOutboxRow,
  response: ServerReplenishmentRow,
): void {
  assertServerRow(response);
  if (response.product_id !== request.product_id || response.variant_id !== request.variant_id) {
    throw new ReplenishmentSyncResponseError(
      `Server returned a mismatched product or variant for ${request.client_request_uuid}.`,
    );
  }
}

function assertServerRow(row: ServerReplenishmentRow): void {
  if (typeof row !== 'object' || row === null) {
    throw new ReplenishmentSyncResponseError('Server returned a non-object replenishment row.');
  }
  if (typeof row.id !== 'string' || row.id.trim() === '') {
    throw new ReplenishmentSyncResponseError('Server response is missing request id.');
  }
  if (typeof row.product_id !== 'string' || row.product_id.trim() === '') {
    throw new ReplenishmentSyncResponseError('Server response is missing product_id.');
  }
  if (row.variant_id !== null && typeof row.variant_id !== 'string') {
    throw new ReplenishmentSyncResponseError('Server response has an invalid variant_id.');
  }
  if (typeof row.status !== 'string' || row.status.trim() === '') {
    throw new ReplenishmentSyncResponseError('Server response is missing status.');
  }
  if (row.requested_qty !== null && typeof row.requested_qty !== 'string') {
    throw new ReplenishmentSyncResponseError('Server response has an invalid requested_qty.');
  }
  if (row.suggested_qty !== null && typeof row.suggested_qty !== 'string') {
    throw new ReplenishmentSyncResponseError('Server response has an invalid suggested_qty.');
  }
  if (!Number.isInteger(row.request_count) || row.request_count < 1) {
    throw new ReplenishmentSyncResponseError('Server response has an invalid request_count.');
  }
  if (typeof row.last_requested_at !== 'string' || row.last_requested_at.trim() === '') {
    throw new ReplenishmentSyncResponseError('Server response is missing last_requested_at.');
  }
}
