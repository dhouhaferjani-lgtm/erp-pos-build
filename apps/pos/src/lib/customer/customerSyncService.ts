import type Database from '@tauri-apps/plugin-sql';
import { apiGet } from '@/lib/api';
import { upsertCustomer } from '@/lib/db/repositories/customerRepository';
import {
  getSyncMetadata,
  setSyncMetadata,
} from '@/lib/db/repositories/syncLogRepository';
import type { CustomerMirrorRow } from './customerTypes';

const CUSTOMER_CURSOR_KEY = 'customers.updated_since';
const CUSTOMER_PAGE_LIMIT = 100;

interface CustomerSyncResponse {
  customers?: CustomerMirrorRow[];
  has_more?: boolean;
  next_updated_since?: string | null;
  next_updated_since_id?: string | null;
  synced_at?: string;
}

type ParsedCustomerSyncResponse =
  | {
      customers: CustomerMirrorRow[];
      has_more: false;
      next_updated_since: null;
      next_updated_since_id: null;
      synced_at: string;
    }
  | {
      customers: CustomerMirrorRow[];
      has_more: true;
      next_updated_since: string;
      next_updated_since_id: string;
      synced_at: string;
    };

const CUSTOMER_ACCOUNT_STATUSES = new Set(['active', 'suspended', 'closed', 'disputed']);

export class CustomerSyncResponseError extends Error {
  constructor(message: string) {
    super(`[customer] ${message}`);
    this.name = 'CustomerSyncResponseError';
  }
}

export class CustomerSyncScopeError extends Error {
  constructor(
    readonly expectedTenantId: string,
    readonly expectedCompanyId: string,
    readonly row: Pick<CustomerMirrorRow, 'id' | 'tenant_id' | 'company_id'>,
  ) {
    super(
      `[customer] Server returned customer ${row.id} for tenant=${row.tenant_id} company=${row.company_id}; ` +
        `expected tenant=${expectedTenantId} company=${expectedCompanyId}.`,
    );
    this.name = 'CustomerSyncScopeError';
  }
}

function assertScope(row: CustomerMirrorRow, tenantId: string, companyId: string): void {
  if (row.tenant_id !== tenantId || row.company_id !== companyId) {
    throw new CustomerSyncScopeError(tenantId, companyId, row);
  }
}

function assertAccountStatus(row: CustomerMirrorRow): void {
  if (!CUSTOMER_ACCOUNT_STATUSES.has(row.account_status)) {
    throw new CustomerSyncResponseError(
      `Server returned customer ${row.id} with unknown account_status ${String(row.account_status)}.`,
    );
  }

  if (!Number.isInteger(row.account_status_version) || row.account_status_version < 1) {
    throw new CustomerSyncResponseError(
      `Server returned customer ${row.id} with invalid account_status_version.`,
    );
  }
}

function parseResponse(response: CustomerSyncResponse): ParsedCustomerSyncResponse {
  if (!Array.isArray(response.customers)) {
    throw new CustomerSyncResponseError('Server response is missing customers array.');
  }

  if (typeof response.has_more !== 'boolean') {
    throw new CustomerSyncResponseError('Server response is missing has_more flag.');
  }

  if (response.has_more) {
    const nextUpdatedSince = response.next_updated_since;
    const nextUpdatedSinceId = response.next_updated_since_id;

    if (
      typeof nextUpdatedSince !== 'string' ||
      nextUpdatedSince.trim() === ''
    ) {
      throw new CustomerSyncResponseError('Server response is missing next_updated_since cursor.');
    }

    if (
      typeof nextUpdatedSinceId !== 'string' ||
      nextUpdatedSinceId.trim() === ''
    ) {
      throw new CustomerSyncResponseError('Server response is missing next_updated_since_id cursor.');
    }

    if (typeof response.synced_at !== 'string' || response.synced_at.trim() === '') {
      throw new CustomerSyncResponseError('Server response is missing synced_at cursor.');
    }

    return {
      customers: response.customers,
      has_more: true,
      next_updated_since: nextUpdatedSince,
      next_updated_since_id: nextUpdatedSinceId,
      synced_at: response.synced_at,
    };
  }

  if (typeof response.synced_at !== 'string' || response.synced_at.trim() === '') {
    throw new CustomerSyncResponseError('Server response is missing synced_at cursor.');
  }

  return {
    customers: response.customers,
    has_more: false,
    next_updated_since: null,
    next_updated_since_id: null,
    synced_at: response.synced_at,
  };
}

export async function pullCustomers(
  db: Database,
  tenantId: string,
  companyId: string,
): Promise<number> {
  const lastSync = await getSyncMetadata(db, CUSTOMER_CURSOR_KEY);
  const params: Record<string, string> = {};
  if (lastSync !== null && lastSync !== '') {
    params.updated_since = lastSync;
  }

  params.limit = String(CUSTOMER_PAGE_LIMIT);
  let total = 0;

  while (true) {
    const response = parseResponse(
      await apiGet<CustomerSyncResponse>('/pos/customers/sync', { ...params }),
    );
    const customers = response.customers;

    for (const customer of customers) {
      assertScope(customer, tenantId, companyId);
      assertAccountStatus(customer);
    }

    for (const customer of customers) {
      await upsertCustomer(db, customer);
    }

    total += customers.length;

    if (!response.has_more) {
      await setSyncMetadata(
        db,
        CUSTOMER_CURSOR_KEY,
        response.synced_at,
      );

      return total;
    }

    params.updated_since = response.next_updated_since;
    params.updated_since_id = response.next_updated_since_id;
  }
}
