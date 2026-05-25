import type Database from '@tauri-apps/plugin-sql';
import { apiPost } from '@/lib/api';
import {
  getPendingCustomers,
  markPendingCustomerResolved,
  storeCustomerAlias,
  type PendingCustomerRow,
} from '@/lib/db/repositories/pendingCustomerRepository';

interface PendingCustomerAliasResponse {
  client_customer_uuid?: string;
  server_partner_id?: string;
  tenant_id?: string;
  company_id?: string;
  resolved_at?: string;
}

export class PendingCustomerSyncScopeError extends Error {
  constructor(
    readonly expectedTenantId: string,
    readonly expectedCompanyId: string,
    readonly row: Pick<PendingCustomerRow, 'client_customer_uuid'>,
    readonly response: Pick<PendingCustomerAliasResponse, 'tenant_id' | 'company_id' | 'client_customer_uuid'>,
  ) {
    super(
      `[customer] Pending customer ${row.client_customer_uuid} resolved outside active scope: ` +
        `tenant=${response.tenant_id ?? '<missing>'} company=${response.company_id ?? '<missing>'}.`,
    );
    this.name = 'PendingCustomerSyncScopeError';
  }
}

export class PendingCustomerSyncResponseError extends Error {
  constructor(message: string) {
    super(`[customer] ${message}`);
    this.name = 'PendingCustomerSyncResponseError';
  }
}

function assertResponse(
  row: PendingCustomerRow,
  response: PendingCustomerAliasResponse,
): Required<PendingCustomerAliasResponse> {
  if (response.client_customer_uuid !== row.client_customer_uuid) {
    throw new PendingCustomerSyncResponseError('Server returned a mismatched client_customer_uuid.');
  }

  if (typeof response.server_partner_id !== 'string' || response.server_partner_id.trim() === '') {
    throw new PendingCustomerSyncResponseError('Server response is missing server_partner_id.');
  }

  if (typeof response.tenant_id !== 'string' || response.tenant_id.trim() === '') {
    throw new PendingCustomerSyncResponseError('Server response is missing tenant_id.');
  }

  if (typeof response.company_id !== 'string' || response.company_id.trim() === '') {
    throw new PendingCustomerSyncResponseError('Server response is missing company_id.');
  }

  if (typeof response.resolved_at !== 'string' || response.resolved_at.trim() === '') {
    throw new PendingCustomerSyncResponseError('Server response is missing resolved_at.');
  }

  return {
    client_customer_uuid: response.client_customer_uuid,
    server_partner_id: response.server_partner_id,
    tenant_id: response.tenant_id,
    company_id: response.company_id,
    resolved_at: response.resolved_at,
  };
}

export async function pushPendingCustomers(
  db: Database,
  tenantId: string,
  companyId: string,
): Promise<number> {
  const pending = await getPendingCustomers(db, tenantId, companyId);
  let resolved = 0;

  for (const row of pending) {
    const response = assertResponse(
      row,
      await apiPost<PendingCustomerAliasResponse>('/pos/customers/pending', {
        client_customer_uuid: row.client_customer_uuid,
        name: row.name,
        phone: row.phone,
        email: row.email,
      }),
    );

    if (response.tenant_id !== tenantId || response.company_id !== companyId) {
      throw new PendingCustomerSyncScopeError(tenantId, companyId, row, response);
    }

    await storeCustomerAlias(db, {
      tenant_id: tenantId,
      company_id: companyId,
      client_customer_uuid: row.client_customer_uuid,
      server_partner_id: response.server_partner_id,
      resolved_at: response.resolved_at,
    });
    await markPendingCustomerResolved(db, tenantId, companyId, row.client_customer_uuid);
    resolved += 1;
  }

  return resolved;
}
