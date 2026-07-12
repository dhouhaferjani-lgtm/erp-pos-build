import type Database from '@tauri-apps/plugin-sql';
import { execute, queryAll, queryOne } from '@/lib/db';

export type PendingCustomerStatus = 'pending' | 'resolved' | 'failed';

export interface PendingCustomerRow {
  client_customer_uuid: string;
  tenant_id: string;
  company_id: string;
  name: string;
  phone: string | null;
  email: string | null;
  status: PendingCustomerStatus;
  sync_error: string | null;
  created_at: string;
  updated_at: string;
}

export interface PendingCustomerInput {
  client_customer_uuid: string;
  tenant_id: string;
  company_id: string;
  name: string;
  phone?: string | null;
  email?: string | null;
  now?: string;
}

export interface CustomerAliasRow {
  tenant_id: string;
  company_id: string;
  client_customer_uuid: string;
  server_partner_id: string;
  resolved_at: string;
}

export class StaleCustomerAliasConflictError extends Error {
  constructor(
    readonly tenantId: string,
    readonly companyId: string,
    readonly clientCustomerUuid: string,
    readonly existingServerPartnerId: string,
    readonly incomingServerPartnerId: string,
  ) {
    super(
      `[customer] Client customer ${clientCustomerUuid} in tenant=${tenantId} company=${companyId} ` +
        `already maps to server partner ${existingServerPartnerId}; refused conflicting alias ${incomingServerPartnerId}.`,
    );
    this.name = 'StaleCustomerAliasConflictError';
  }
}

function nowIso(): string {
  return new Date().toISOString();
}

function assertPresent(field: string, value: string): void {
  if (value.trim() === '') {
    throw new Error(`[customer] ${field} is required`);
  }
}

function assertScope(input: { tenant_id: string; company_id: string }): void {
  assertPresent('tenant_id', input.tenant_id);
  assertPresent('company_id', input.company_id);
}

export async function enqueuePendingCustomer(
  db: Database,
  input: PendingCustomerInput,
): Promise<void> {
  assertScope(input);
  assertPresent('client_customer_uuid', input.client_customer_uuid);
  assertPresent('name', input.name);

  const timestamp = input.now ?? nowIso();

  await execute(
    db,
    `INSERT INTO pending_customer_outbox (
       client_customer_uuid, tenant_id, company_id, name, phone, email,
       status, sync_error, created_at, updated_at
     )
     VALUES ($1, $2, $3, $4, $5, $6, 'pending', NULL, $7, $7)
     ON CONFLICT(tenant_id, company_id, client_customer_uuid) DO UPDATE SET
       name = excluded.name,
       phone = excluded.phone,
       email = excluded.email,
       status = 'pending',
       sync_error = NULL,
       updated_at = excluded.updated_at`,
    [
      input.client_customer_uuid,
      input.tenant_id,
      input.company_id,
      input.name,
      input.phone ?? null,
      input.email ?? null,
      timestamp,
    ],
  );
}

export async function getPendingCustomers(
  db: Database,
  tenantId: string,
  companyId: string,
): Promise<PendingCustomerRow[]> {
  assertPresent('tenant_id', tenantId);
  assertPresent('company_id', companyId);

  return queryAll<PendingCustomerRow>(
    db,
    `SELECT *
       FROM pending_customer_outbox
      WHERE tenant_id = $1
        AND company_id = $2
        AND status = 'pending'
      ORDER BY updated_at, client_customer_uuid`,
    [tenantId, companyId],
  );
}

export async function markPendingCustomerResolved(
  db: Database,
  tenantId: string,
  companyId: string,
  clientCustomerUuid: string,
  now: string = nowIso(),
): Promise<void> {
  await updatePendingStatus(db, tenantId, companyId, clientCustomerUuid, 'resolved', null, now);
}

export async function markPendingCustomerFailed(
  db: Database,
  tenantId: string,
  companyId: string,
  clientCustomerUuid: string,
  syncError: string,
  now: string = nowIso(),
): Promise<void> {
  await updatePendingStatus(db, tenantId, companyId, clientCustomerUuid, 'failed', syncError, now);
}

export async function storeCustomerAlias(db: Database, input: CustomerAliasRow): Promise<void> {
  assertScope(input);
  assertPresent('client_customer_uuid', input.client_customer_uuid);
  assertPresent('server_partner_id', input.server_partner_id);
  assertPresent('resolved_at', input.resolved_at);

  const existing = await getCustomerAlias(
    db,
    input.tenant_id,
    input.company_id,
    input.client_customer_uuid,
  );

  if (existing !== null && existing.server_partner_id !== input.server_partner_id) {
    throw new StaleCustomerAliasConflictError(
      input.tenant_id,
      input.company_id,
      input.client_customer_uuid,
      existing.server_partner_id,
      input.server_partner_id,
    );
  }

  await execute(
    db,
    `INSERT INTO customer_aliases (
       tenant_id, company_id, client_customer_uuid, server_partner_id, resolved_at
     )
     VALUES ($1, $2, $3, $4, $5)
     ON CONFLICT(tenant_id, company_id, client_customer_uuid) DO UPDATE SET
       server_partner_id = excluded.server_partner_id,
       resolved_at = excluded.resolved_at`,
    [
      input.tenant_id,
      input.company_id,
      input.client_customer_uuid,
      input.server_partner_id,
      input.resolved_at,
    ],
  );
}

export async function getCustomerAlias(
  db: Database,
  tenantId: string,
  companyId: string,
  clientCustomerUuid: string,
): Promise<CustomerAliasRow | null> {
  assertPresent('tenant_id', tenantId);
  assertPresent('company_id', companyId);
  assertPresent('client_customer_uuid', clientCustomerUuid);

  return queryOne<CustomerAliasRow>(
    db,
    `SELECT *
       FROM customer_aliases
      WHERE tenant_id = $1
        AND company_id = $2
        AND client_customer_uuid = $3`,
    [tenantId, companyId, clientCustomerUuid],
  );
}

export async function assertCustomerAliasMatches(
  db: Database,
  tenantId: string,
  companyId: string,
  clientCustomerUuid: string,
  serverPartnerId: string,
): Promise<void> {
  assertPresent('server_partner_id', serverPartnerId);
  const existing = await getCustomerAlias(db, tenantId, companyId, clientCustomerUuid);

  if (existing !== null && existing.server_partner_id !== serverPartnerId) {
    throw new StaleCustomerAliasConflictError(
      tenantId,
      companyId,
      clientCustomerUuid,
      existing.server_partner_id,
      serverPartnerId,
    );
  }
}

/**
 * GB-3 — retention sweep for the pending-customer outbox.
 *
 * Resolved rows are inert once the alias is promoted, and failed rows are only
 * useful while a human might still inspect the sync error; without a sweep both
 * accumulate forever on the device. This deletes, within the given
 * tenant/company scope:
 *   - status='resolved' whose `updated_at` is older than 30 days
 *   - status='failed'   whose `updated_at` is older than 90 days (kept longer
 *     so sync errors remain inspectable before purge)
 * 'pending' rows are NEVER deleted.
 *
 * Rule 20: `created_at`/`updated_at` are JS-authored ISO 8601 strings
 * (`YYYY-MM-DDTHH:MM:SS.sssZ`), NOT SQLite `datetime('now')` values. The age
 * cutoffs are therefore computed in JS as ISO strings and passed as bound
 * params so the comparison is ISO-vs-ISO. Binding SQLite `datetime('now')`
 * here would compare a space-separated value against 'T'-separated rows and
 * silently mis-purge (the ' ' < 'T' lexicographic trap).
 */
const RESOLVED_RETENTION_DAYS = 30;
const FAILED_RETENTION_DAYS = 90;

export async function purgeOutboxRows(
  db: Database,
  tenantId: string,
  companyId: string,
  nowIso: string = new Date().toISOString(),
): Promise<void> {
  assertPresent('tenant_id', tenantId);
  assertPresent('company_id', companyId);

  const nowMs = new Date(nowIso).getTime();
  const resolvedCutoff = new Date(nowMs - RESOLVED_RETENTION_DAYS * 864e5).toISOString();
  const failedCutoff = new Date(nowMs - FAILED_RETENTION_DAYS * 864e5).toISOString();

  await execute(
    db,
    `DELETE FROM pending_customer_outbox
      WHERE tenant_id = $1
        AND company_id = $2
        AND (
          (status = 'resolved' AND updated_at < $3)
          OR (status = 'failed' AND updated_at < $4)
        )`,
    [tenantId, companyId, resolvedCutoff, failedCutoff],
  );
}

async function updatePendingStatus(
  db: Database,
  tenantId: string,
  companyId: string,
  clientCustomerUuid: string,
  status: PendingCustomerStatus,
  syncError: string | null,
  now: string,
): Promise<void> {
  assertPresent('tenant_id', tenantId);
  assertPresent('company_id', companyId);
  assertPresent('client_customer_uuid', clientCustomerUuid);

  await execute(
    db,
    `UPDATE pending_customer_outbox
        SET status = $4,
            sync_error = $5,
            updated_at = $6
      WHERE tenant_id = $1
        AND company_id = $2
        AND client_customer_uuid = $3`,
    [tenantId, companyId, clientCustomerUuid, status, syncError, now],
  );
}
