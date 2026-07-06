import type Database from '@tauri-apps/plugin-sql';
import { execute, queryAll, queryOne } from '@/lib/db';
import type { CustomerMirrorRow, CustomerSearchInput } from '@/lib/customer/customerTypes';

const DEFAULT_SEARCH_LIMIT = 20;
const MAX_SEARCH_LIMIT = 50;

export class CustomerCompanyDriftError extends Error {
  constructor(
    readonly tenantId: string,
    readonly customerId: string,
    readonly existingCompanyId: string,
    readonly incomingCompanyId: string,
  ) {
    super(
      `[customer] Customer ${customerId} for tenant ${tenantId} already belongs to company ${existingCompanyId}; ` +
        `refusing to upsert into ${incomingCompanyId}.`,
    );
    this.name = 'CustomerCompanyDriftError';
  }
}

function assertPresent(field: string, value: string): void {
  if (value.trim() === '') {
    throw new Error(`[customer] ${field} is required`);
  }
}

function clampLimit(limit: number | undefined): number {
  if (limit === undefined) return DEFAULT_SEARCH_LIMIT;
  if (!Number.isInteger(limit) || limit < 1) {
    throw new Error('[customer] limit must be a positive integer');
  }
  return Math.min(limit, MAX_SEARCH_LIMIT);
}

function assertScope(input: { tenant_id: string; company_id: string }): void {
  assertPresent('tenant_id', input.tenant_id);
  assertPresent('company_id', input.company_id);
}

export async function upsertCustomer(db: Database, input: CustomerMirrorRow): Promise<void> {
  assertScope(input);
  assertPresent('id', input.id);
  const chargeAccountEnabled = input.charge_account_enabled === true || input.charge_account_enabled === 1 ? 1 : 0;

  const drift = await queryOne<{ company_id: string }>(
    db,
    `SELECT company_id
       FROM customers
      WHERE tenant_id = $1
        AND id = $2
        AND company_id <> $3
      LIMIT 1`,
    [input.tenant_id, input.id, input.company_id],
  );

  if (drift !== null) {
    throw new CustomerCompanyDriftError(
      input.tenant_id,
      input.id,
      drift.company_id,
      input.company_id,
    );
  }

  // Param count before Task 21: 23 ($1–$23). After adding skin_type ($24) and
  // skin_advice_note ($25) the total is 25. Column list, placeholders, params
  // array, and ON CONFLICT SET are all kept in sync.
  await execute(
    db,
    `INSERT INTO customers (
       id, tenant_id, company_id, name, phone, email, tax_number, customer_category,
       receivable_balance, credit_balance, credit_limit, payment_terms_days,
       charge_account_enabled, charge_policy_version, balance_updated_at,
       account_status, account_status_changed_at, account_status_reason, account_status_version,
       is_active, sync_version, updated_at, synced_at,
       skin_type, skin_advice_note
     )
     VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, $17, $18, $19, $20, $21, $22, $23, $24, $25)
     ON CONFLICT(tenant_id, company_id, id) DO UPDATE SET
       name = excluded.name,
       phone = excluded.phone,
       email = excluded.email,
       tax_number = excluded.tax_number,
       customer_category = excluded.customer_category,
       receivable_balance = excluded.receivable_balance,
       credit_balance = excluded.credit_balance,
       credit_limit = excluded.credit_limit,
       payment_terms_days = excluded.payment_terms_days,
       charge_account_enabled = excluded.charge_account_enabled,
       charge_policy_version = excluded.charge_policy_version,
       balance_updated_at = excluded.balance_updated_at,
       account_status = excluded.account_status,
       account_status_changed_at = excluded.account_status_changed_at,
       account_status_reason = excluded.account_status_reason,
       account_status_version = excluded.account_status_version,
       is_active = excluded.is_active,
       sync_version = excluded.sync_version,
       updated_at = excluded.updated_at,
       synced_at = excluded.synced_at,
       skin_type = excluded.skin_type,
       skin_advice_note = excluded.skin_advice_note`,
    [
      input.id,
      input.tenant_id,
      input.company_id,
      input.name,
      input.phone,
      input.email,
      input.tax_number,
      input.customer_category,
      input.receivable_balance,
      input.credit_balance,
      input.credit_limit,
      input.payment_terms_days,
      chargeAccountEnabled,
      input.charge_policy_version,
      input.balance_updated_at,
      input.account_status,
      input.account_status_changed_at,
      input.account_status_reason,
      input.account_status_version,
      input.is_active,
      input.sync_version,
      input.updated_at,
      input.synced_at,
      input.skin_type ?? null,
      input.skin_advice_note ?? null,
    ],
  );
}

/**
 * T-0001 — re-key an optimistic pending-customer mirror row (keyed by its
 * client uuid, written by `createPendingCustomer`) to the server partner id
 * returned by `POST /pos/customers/pending`.
 *
 * Two shapes, both ending with exactly one row under the server id:
 *   - normal: only the optimistic row exists → `UPDATE OR IGNORE` re-keys it
 *     in place, the follow-up DELETE matches nothing;
 *   - pull-first race: a delta pull already delivered the server row → the
 *     UPDATE is skipped by OR IGNORE (PK conflict) and the DELETE drops the
 *     stale optimistic row, leaving the server row untouched.
 *
 * The durable client→server mapping stays in `customer_aliases`
 * (`storeCustomerAlias`); this only keeps the search/list mirror
 * duplicate-free across the push→pull round-trip.
 */
export async function promoteCustomerServerId(
  db: Database,
  tenantId: string,
  companyId: string,
  clientCustomerUuid: string,
  serverPartnerId: string,
): Promise<void> {
  assertPresent('tenant_id', tenantId);
  assertPresent('company_id', companyId);
  assertPresent('client_customer_uuid', clientCustomerUuid);
  assertPresent('server_partner_id', serverPartnerId);

  if (clientCustomerUuid === serverPartnerId) return;

  await execute(
    db,
    `UPDATE OR IGNORE customers
        SET id = $4
      WHERE tenant_id = $1
        AND company_id = $2
        AND id = $3`,
    [tenantId, companyId, clientCustomerUuid, serverPartnerId],
  );
  await execute(
    db,
    `DELETE FROM customers
      WHERE tenant_id = $1
        AND company_id = $2
        AND id = $3`,
    [tenantId, companyId, clientCustomerUuid],
  );
}

export async function getCustomerById(
  db: Database,
  tenantId: string,
  companyId: string,
  id: string,
): Promise<CustomerMirrorRow | null> {
  assertPresent('tenant_id', tenantId);
  assertPresent('company_id', companyId);
  assertPresent('id', id);

  return queryOne<CustomerMirrorRow>(
    db,
    `SELECT *
       FROM customers
      WHERE tenant_id = $1
        AND company_id = $2
        AND id = $3`,
    [tenantId, companyId, id],
  );
}

export async function searchCustomers(
  db: Database,
  input: CustomerSearchInput,
): Promise<CustomerMirrorRow[]> {
  assertScope(input);
  const query = input.query.trim();
  if (query === '') return [];

  const limit = clampLimit(input.limit);
  const pattern = `%${query}%`;

  return queryAll<CustomerMirrorRow>(
    db,
    `SELECT *
       FROM customers
      WHERE tenant_id = $1
        AND company_id = $2
        AND is_active = 1
        AND (
          LOWER(name) LIKE LOWER($3)
          OR phone LIKE $3
          OR LOWER(COALESCE(tax_number, '')) LIKE LOWER($3)
        )
      ORDER BY name, id
      LIMIT $4`,
    [input.tenant_id, input.company_id, pattern, limit],
  );
}

const DEFAULT_LIST_LIMIT = 50;

export async function listCustomers(
  db: Database,
  tenantId: string,
  companyId: string,
  limit: number = DEFAULT_LIST_LIMIT,
): Promise<CustomerMirrorRow[]> {
  assertPresent('tenant_id', tenantId);
  assertPresent('company_id', companyId);

  return queryAll<CustomerMirrorRow>(
    db,
    `SELECT *
       FROM customers
      WHERE tenant_id = $1
        AND company_id = $2
        AND is_active = 1
      ORDER BY name, id
      LIMIT $3`,
    [tenantId, companyId, limit],
  );
}

export async function updateCustomerSkinProfile(
  db: Database,
  tenantId: string,
  companyId: string,
  id: string,
  skinType: string | null,
  skinAdviceNote: string | null,
): Promise<void> {
  assertPresent('tenant_id', tenantId);
  assertPresent('company_id', companyId);
  assertPresent('id', id);

  await execute(
    db,
    `UPDATE customers
        SET skin_type = $4,
            skin_advice_note = $5
      WHERE tenant_id = $1
        AND company_id = $2
        AND id = $3`,
    [tenantId, companyId, id, skinType ?? null, skinAdviceNote ?? null],
  );
}

export function isBalanceStale(
  row: CustomerMirrorRow,
  now: Date,
  thresholdMinutes: number,
): boolean {
  if (row.balance_updated_at === null) return true;
  if (!Number.isFinite(thresholdMinutes) || thresholdMinutes < 0) {
    throw new Error('[customer] thresholdMinutes must be a non-negative number');
  }

  const updatedAt = new Date(row.balance_updated_at);
  const updatedAtMs = updatedAt.getTime();
  if (Number.isNaN(updatedAtMs)) return true;

  return now.getTime() - updatedAtMs > thresholdMinutes * 60 * 1000;
}
