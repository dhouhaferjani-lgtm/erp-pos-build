import type Database from '@tauri-apps/plugin-sql';
import { execute, queryAll } from '@/lib/db';

export type ReplenishmentOutboxStatus = 'pending' | 'resolved' | 'failed';

export interface ReplenishmentOutboxRow {
  client_request_uuid: string;
  tenant_id: string;
  company_id: string;
  terminal_id: string;
  product_id: string;
  variant_id: string | null;
  requested_qty: string | null;
  note: string | null;
  status: ReplenishmentOutboxStatus;
  sync_error: string | null;
  created_at: string;
  updated_at: string;
}

export interface ReplenishmentOutboxInput {
  client_request_uuid: string;
  tenant_id: string;
  company_id: string;
  terminal_id: string;
  product_id: string;
  variant_id?: string | null;
  requested_qty?: string | null;
  note?: string | null;
  now?: string;
}

function nowIso(): string {
  return new Date().toISOString();
}

function assertPresent(field: string, value: string): void {
  if (value.trim() === '') {
    throw new Error(`[replenishment] ${field} is required`);
  }
}

function assertScope(input: { tenant_id: string; company_id: string }): void {
  assertPresent('tenant_id', input.tenant_id);
  assertPresent('company_id', input.company_id);
}

export async function enqueueReplenishmentRequest(
  db: Database,
  input: ReplenishmentOutboxInput,
): Promise<void> {
  assertScope(input);
  assertPresent('client_request_uuid', input.client_request_uuid);
  assertPresent('terminal_id', input.terminal_id);
  assertPresent('product_id', input.product_id);
  const timestamp = input.now ?? nowIso();

  await execute(
    db,
    `INSERT INTO replenishment_outbox (
       client_request_uuid, tenant_id, company_id, terminal_id, product_id,
       variant_id, requested_qty, note, status, sync_error, created_at, updated_at
     )
     VALUES ($1, $2, $3, $4, $5, $6, $7, $8, 'pending', NULL, $9, $9)
     ON CONFLICT(tenant_id, company_id, client_request_uuid) DO UPDATE SET
       terminal_id = excluded.terminal_id,
       product_id = excluded.product_id,
       variant_id = excluded.variant_id,
       requested_qty = excluded.requested_qty,
       note = excluded.note,
       status = 'pending',
       sync_error = NULL,
       updated_at = excluded.updated_at`,
    [
      input.client_request_uuid,
      input.tenant_id,
      input.company_id,
      input.terminal_id,
      input.product_id,
      input.variant_id ?? null,
      input.requested_qty ?? null,
      input.note ?? null,
      timestamp,
    ],
  );
}

export async function getPendingReplenishmentRequests(
  db: Database,
  tenantId: string,
  companyId: string,
): Promise<ReplenishmentOutboxRow[]> {
  assertPresent('tenant_id', tenantId);
  assertPresent('company_id', companyId);

  return queryAll<ReplenishmentOutboxRow>(
    db,
    `SELECT *
       FROM replenishment_outbox
      WHERE tenant_id = $1
        AND company_id = $2
        AND status = 'pending'
      ORDER BY updated_at, client_request_uuid`,
    [tenantId, companyId],
  );
}

export async function markReplenishmentResolved(
  db: Database,
  tenantId: string,
  companyId: string,
  clientRequestUuid: string,
  now: string = nowIso(),
): Promise<void> {
  await updateStatus(db, tenantId, companyId, clientRequestUuid, 'resolved', null, now);
}

export async function markReplenishmentFailed(
  db: Database,
  tenantId: string,
  companyId: string,
  clientRequestUuid: string,
  syncError: string,
  now: string = nowIso(),
): Promise<void> {
  await updateStatus(db, tenantId, companyId, clientRequestUuid, 'failed', syncError, now);
}

async function updateStatus(
  db: Database,
  tenantId: string,
  companyId: string,
  clientRequestUuid: string,
  status: ReplenishmentOutboxStatus,
  syncError: string | null,
  now: string,
): Promise<void> {
  assertPresent('tenant_id', tenantId);
  assertPresent('company_id', companyId);
  assertPresent('client_request_uuid', clientRequestUuid);

  await execute(
    db,
    `UPDATE replenishment_outbox
        SET status = $4,
            sync_error = $5,
            updated_at = $6
      WHERE tenant_id = $1
        AND company_id = $2
        AND client_request_uuid = $3`,
    [tenantId, companyId, clientRequestUuid, status, syncError, now],
  );
}
