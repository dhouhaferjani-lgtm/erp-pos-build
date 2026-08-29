import type Database from '@tauri-apps/plugin-sql';
import {
  enqueuePendingCustomer,
  type PendingCustomerInput,
} from '@/lib/db/repositories/pendingCustomerRepository';
import { upsertCustomer } from '@/lib/db/repositories/customerRepository';
import { toSqliteUtc } from '@/lib/db/sqliteTime';
import type { CustomerMirrorRow } from './customerTypes';

/**
 * T-0001 — offline-first "add customer" write path.
 *
 * The outbox row alone made new customers invisible: search AND list read
 * only the `customers` mirror (customerRepository.searchCustomers /
 * listCustomers), so a customer queued in `pending_customer_outbox` never
 * appeared anywhere until a server round-trip that (pre-fix) never happened.
 *
 * `createPendingCustomer` therefore writes BOTH rows:
 *   1. the outbox row (`enqueuePendingCustomer`) — drained by
 *      `pushPendingCustomers` on the next sync tick, and
 *   2. an optimistic `customers` mirror row keyed by the client uuid.
 *
 * Round-trip contract: when the push resolves, `pushPendingCustomers`
 * re-keys this optimistic row to the returned `server_partner_id`
 * (`promoteCustomerServerId`), so the same-tick delta pull upserts the
 * server's canonical row ON TOP of it instead of duplicating it. The
 * client→server mapping itself lives in `customer_aliases`, exactly as
 * before.
 */
export function optimisticCustomerMirrorRow(input: PendingCustomerInput): CustomerMirrorRow {
  // SQLite mirror columns store `YYYY-MM-DD HH:MM:SS` UTC (space separator);
  // never bind an ISO 8601 string into the mirror (see sqliteTime.ts).
  const timestamp = toSqliteUtc(input.now ?? new Date().toISOString());

  return {
    id: input.client_customer_uuid,
    tenant_id: input.tenant_id,
    company_id: input.company_id,
    name: input.name,
    phone: input.phone ?? null,
    email: input.email ?? null,
    tax_number: null,
    // Matches the optimistic AttachedCheckoutCustomer built by
    // CustomerAttachPanel.handleCreate; the pull overwrites with server truth.
    customer_category: null,
    receivable_balance: '0.000',
    credit_balance: '0.000',
    credit_limit: null,
    payment_terms_days: null,
    // Charge-to-account stays OFF until the server-configured policy arrives —
    // a device must never author account charges against an unsynced customer.
    charge_account_enabled: 0,
    charge_policy_version: null,
    account_status: 'active',
    account_status_changed_at: null,
    account_status_reason: null,
    account_status_version: 1,
    balance_updated_at: null,
    is_active: 1,
    sync_version: null,
    updated_at: timestamp,
    synced_at: timestamp,
    skin_type: null,
    skin_advice_note: null,
  };
}

/**
 * Queue a locally created customer for server sync AND make it immediately
 * visible to local search/list. Returns the optimistic mirror row (callers
 * attach it to checkout state).
 */
export async function createPendingCustomer(
  db: Database,
  input: PendingCustomerInput,
): Promise<CustomerMirrorRow> {
  await enqueuePendingCustomer(db, input);

  const row = optimisticCustomerMirrorRow(input);
  await upsertCustomer(db, row);

  return row;
}
