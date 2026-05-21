import type Database from '@tauri-apps/plugin-sql';
import { getCurrencyDecimals } from '@/lib/currency';
import { bcadd, bccomp, bcformat, bcsub } from '@/lib/decimal';
import { getFiscalEventEngine } from '@/lib/fiscal/instance';
import type { FiscalEventAppendResult } from '@/lib/fiscal/FiscalEventEngine';
import type {
  AccountPaymentAddress,
  AccountPaymentPayload,
} from '@/lib/fiscal/payloads/AccountPaymentPayload';
import type { SaleReceiptSellerInput } from '@/lib/fiscal/payloads/SaleReceiptPayload';
import { assertCustomerAliasMatches } from '@/lib/db/repositories/pendingCustomerRepository';
import { buildEscPosAccountPaymentReceiptData } from '@/lib/buildReceiptData';
import type { ReceiptData } from '@/lib/printing';
import { useSyncStore } from '@/stores/syncStore';
import type { AttachedCheckoutCustomer } from '@/stores/paymentStore';

export class AccountPaymentInputError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'AccountPaymentInputError';
  }
}

export interface AccountPaymentMethodInput {
  amount: string;
  methodCode: string;
  repositoryId?: string | null;
  instrumentType?: string | null;
  instrumentSerial?: string | null;
  foreignCurrencyCode?: string | null;
  foreignCurrencyAmount?: string | null;
}

export interface CreateAccountPaymentInput {
  tenantId: string;
  companyId: string;
  terminalId: string;
  terminalName: string;
  operatorId: string;
  operatorName: string;
  shiftId: string;
  currency: string;
  seller: SaleReceiptSellerInput;
  customer: AttachedCheckoutCustomer;
  payment: AccountPaymentMethodInput;
  businessDate?: string;
  eventTimeDevice?: Date;
  isTraining?: boolean;
  notes?: string | null;
  externalReference?: string | null;
  relatedSaleReceiptEventId?: string | null;
  customerSnapshotStale?: boolean;
  balanceSnapshotStale?: boolean;
  mirrorLastSyncedAt?: string | null;
  stalenessReason?: 'never_synced' | 'older_than_threshold' | 'server_conflict_pending' | null;
  expectedServerCustomerAliasId?: string | null;
}

export interface AccountPaymentResult {
  accountPaymentUuid: string;
  fiscalEventId: string;
  receiptNumber: string;
  total: string;
  currency: string;
  fiscalHash: string;
  sequenceNumber: number;
  canonicalBytes: string;
  payload: AccountPaymentPayload;
  printableData: ReceiptData;
}

function requireText(value: string | null | undefined, path: string): string {
  const normalized = value?.trim() ?? '';
  if (normalized === '') {
    throw new AccountPaymentInputError(`${path} is required for ACCOUNT_PAYMENT authoring.`);
  }
  return normalized;
}

function isoSecondsUtc(date: Date): string {
  return date.toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function assertSupportedScale(scale: number, currency: string): asserts scale is 0 | 2 | 3 {
  if (scale !== 0 && scale !== 2 && scale !== 3) {
    throw new AccountPaymentInputError(
      `Unsupported currency scale ${String(scale)} for ${currency}; expected 0, 2, or 3.`,
    );
  }
}

function minMoney(a: string, b: string): string {
  return bccomp(a, b) <= 0 ? a : b;
}

function maxZero(value: string, scale: number): string {
  return bccomp(value, '0') < 0 ? bcformat('0', scale) : bcformat(value, scale);
}

function buildSeller(input: SaleReceiptSellerInput): AccountPaymentPayload['seller'] {
  const countryCode = requireText(input.countryCode, 'seller.address.country_code').toUpperCase();
  const taxNumber = normalizeSellerTaxNumber(
    requireText(input.taxNumber, 'seller.tax_number'),
    countryCode,
  );

  return {
    address: {
      city: requireText(input.city, 'seller.address.city'),
      country_code: countryCode,
      postal_code: requireText(input.postalCode, 'seller.address.postal_code'),
      street: requireText(input.street, 'seller.address.street'),
    },
    name: requireText(input.name, 'seller.name'),
    tax_jurisdiction_country_code: countryCode,
    tax_number: taxNumber,
  };
}

function normalizeSellerTaxNumber(taxNumber: string, countryCode: string): string {
  if (countryCode === 'TN') {
    return taxNumber.replace(/\//g, '');
  }

  return taxNumber;
}

function buildCustomer(input: AttachedCheckoutCustomer): AccountPaymentPayload['customer'] {
  if (input.customer_sync_status !== 'synced' && input.customer_sync_status !== 'pending_create') {
    throw new AccountPaymentInputError(
      `customer.customer_sync_status must be synced or pending_create; got ${String(input.customer_sync_status)}.`,
    );
  }

  return {
    address: null as AccountPaymentAddress | null,
    customer_category: input.customer_category,
    customer_id: requireText(input.id, 'customer.customer_id'),
    customer_sync_status: input.customer_sync_status,
    email: input.email,
    name: requireText(input.name, 'customer.name'),
    phone: input.phone,
    tax_number: input.tax_number,
  };
}

function computeBalanceSnapshot(
  customer: AttachedCheckoutCustomer,
  amount: string,
  balanceUpdatedAt: string,
  scale: number,
): AccountPaymentPayload['local_balance_snapshot'] {
  const receivableBefore = bcformat(customer.receivable_balance, scale);
  const creditBefore = bcformat(customer.credit_balance, scale);
  const paymentAmount = bcformat(amount, scale);
  const netBefore = maxZero(bcsub(receivableBefore, creditBefore, scale), scale);
  const receivableReduction = minMoney(paymentAmount, receivableBefore);
  const projectedReceivable = maxZero(bcsub(receivableBefore, receivableReduction, scale), scale);
  const overpayment = maxZero(bcsub(paymentAmount, receivableBefore, scale), scale);
  const projectedCredit = bcformat(bcadd(creditBefore, overpayment, scale), scale);
  const projectedNet = maxZero(bcsub(projectedReceivable, projectedCredit, scale), scale);

  return {
    balance_updated_at: balanceUpdatedAt,
    credit_balance_before: creditBefore,
    net_balance_before: netBefore,
    payment_amount: paymentAmount,
    projected_credit_balance_after: projectedCredit,
    projected_net_balance_after: projectedNet,
    projected_receivable_balance_after: projectedReceivable,
    receivable_balance_before: receivableBefore,
  };
}

function resolveStaleness(input: CreateAccountPaymentInput): AccountPaymentPayload['staleness'] {
  const customerStale = input.customerSnapshotStale === true;
  const balanceStale = input.balanceSnapshotStale === true || input.customer.balance_updated_at === null;
  let reason = input.stalenessReason ?? null;
  if ((customerStale || balanceStale) && reason === null) {
    reason = input.customer.balance_updated_at === null ? 'never_synced' : 'older_than_threshold';
  }
  if (!customerStale && !balanceStale && reason !== null) {
    throw new AccountPaymentInputError(
      'staleness.staleness_reason must be null when customer and balance snapshots are fresh.',
    );
  }

  return {
    balance_snapshot_stale: balanceStale,
    customer_snapshot_stale: customerStale,
    mirror_last_synced_at: input.mirrorLastSyncedAt ?? input.customer.balance_updated_at,
    staleness_reason: reason,
  };
}

export function buildAccountPaymentPayload(
  input: CreateAccountPaymentInput & { accountPaymentUuid: string; eventTimeDevice: Date },
): AccountPaymentPayload {
  if (input.customer.tenant_id !== input.tenantId || input.customer.company_id !== input.companyId) {
    throw new AccountPaymentInputError(
      'Customer belongs to a different tenant or company.',
    );
  }

  const scale = getCurrencyDecimals(input.currency);
  assertSupportedScale(scale, input.currency);
  const amount = bcformat(input.payment.amount, scale);
  const isTraining = input.isTraining === true;
  if (!isTraining && bccomp(amount, '0') === 0) {
    throw new AccountPaymentInputError(
      'payment.amount must be greater than zero unless training_flag=true.',
    );
  }

  const eventTimeIso = input.eventTimeDevice.toISOString();
  const businessDate = input.businessDate ?? eventTimeIso.slice(0, 10);
  const balanceUpdatedAt = input.customer.balance_updated_at ?? eventTimeIso;

  return {
    account_payment_uuid: input.accountPaymentUuid,
    business_date: businessDate,
    cashier_id: requireText(input.operatorId, 'cashier_id'),
    cashier_name: requireText(input.operatorName, 'cashier_name'),
    currency_code: input.currency,
    currency_scale: scale,
    customer: buildCustomer(input.customer),
    event_time_device: eventTimeIso,
    local_balance_snapshot: computeBalanceSnapshot(input.customer, amount, balanceUpdatedAt, scale),
    notes: input.notes?.trim() ? input.notes.trim() : null,
    payment: {
      amount,
      foreign_currency_amount: input.payment.foreignCurrencyAmount ?? null,
      foreign_currency_code: input.payment.foreignCurrencyCode ?? null,
      instrument_serial: input.payment.instrumentSerial ?? null,
      instrument_type: input.payment.instrumentType ?? null,
      method_code: requireText(input.payment.methodCode, 'payment.method_code'),
      repository_id: input.payment.repositoryId ?? null,
    },
    receipt_type_code: 'ACCOUNT_PAYMENT',
    references: input.externalReference || input.relatedSaleReceiptEventId
      ? {
        external_reference: input.externalReference ?? null,
        related_sale_receipt_event_id: input.relatedSaleReceiptEventId ?? null,
        server_customer_alias_id: null,
      }
      : null,
    regime_extensions: null,
    seller: buildSeller(input.seller),
    shift_id: requireText(input.shiftId, 'shift_id'),
    staleness: resolveStaleness(input),
    terminal_id: requireText(input.terminalId, 'terminal_id'),
    training_flag: isTraining,
    treasury_allocation_policy: 'FIFO',
  };
}

export async function createAccountPayment(
  db: Database,
  input: CreateAccountPaymentInput,
): Promise<AccountPaymentResult> {
  if (input.customer.customer_sync_status === 'pending_create' && input.expectedServerCustomerAliasId) {
    await assertCustomerAliasMatches(
      db,
      input.tenantId,
      input.companyId,
      input.customer.id,
      input.expectedServerCustomerAliasId,
    );
  }

  const accountPaymentUuid = crypto.randomUUID();
  const eventTimeDevice = input.eventTimeDevice ?? new Date();
  const businessDate = input.businessDate ?? eventTimeDevice.toISOString().slice(0, 10);
  const payload = buildAccountPaymentPayload({
    ...input,
    accountPaymentUuid,
    eventTimeDevice,
    businessDate,
  });

  let appendResult: FiscalEventAppendResult | null = null;
  await db.execute('BEGIN TRANSACTION');
  try {
    const engine = await getFiscalEventEngine(input.companyId, db);
    appendResult = await engine.append(db, {
      event_type: 'ACCOUNT_PAYMENT',
      tenant_id: input.tenantId,
      company_id: input.companyId,
      terminal_id: input.terminalId,
      operator_id: input.operatorId,
      event_time_device: isoSecondsUtc(eventTimeDevice),
      business_date: businessDate,
      payload,
      source_event_class: 'account_payments',
      source_event_id: accountPaymentUuid,
    });
    await db.execute('COMMIT');
  } catch (error) {
    await db.execute('ROLLBACK');
    throw error;
  }

  if (appendResult === null) {
    throw new Error('Fiscal event append did not return a result.');
  }

  useSyncStore.getState().incrementPendingCount();
  void useSyncStore.getState().triggerSync();

  const printableData = buildEscPosAccountPaymentReceiptData({
    payload,
    fiscalEventId: appendResult.id,
    fiscalHash: appendResult.current_hash,
    terminalName: input.terminalName,
  });

  return {
    accountPaymentUuid,
    fiscalEventId: appendResult.id,
    receiptNumber: accountPaymentUuid,
    total: payload.payment.amount,
    currency: payload.currency_code,
    fiscalHash: appendResult.current_hash,
    sequenceNumber: appendResult.sequence_number,
    canonicalBytes: appendResult.canonical_bytes,
    payload,
    printableData,
  };
}
