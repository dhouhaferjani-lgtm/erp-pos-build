import type Database from '@tauri-apps/plugin-sql';
import { getCurrencyDecimals } from '@/lib/currency';
import { bcadd, bccomp, bcformat, bcsub } from '@/lib/decimal';
import { getFiscalEventEngine } from '@/lib/fiscal/instance';
import type { FiscalEventAppendResult } from '@/lib/fiscal/FiscalEventEngine';
import type {
  AccountChargeOverrideEvidence,
  AccountChargePayload,
  AccountChargeSeller,
} from '@/lib/fiscal/payloads/AccountChargePayload';
import type { SaleReceiptSellerInput } from '@/lib/fiscal/payloads/SaleReceiptPayload';
import { useSyncStore } from '@/stores/syncStore';
import type { AttachedCheckoutCustomer } from '@/stores/paymentStore';
import {
  evaluateAccountChargeCreditDecision,
  type AccountChargeCreditDecisionInput,
} from './creditRulesEngine';
import {
  buildAccountChargePrintable,
  type AccountChargePrintable,
} from './accountChargePrintable';

export class AccountChargeInputError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'AccountChargeInputError';
  }
}

export interface AccountChargeLineInput {
  lineUuid: string;
  productId: string;
  sku: string | null;
  name: string;
  quantity: string;
  unitPrice: string;
  lineSubtotal: string;
  lineVat: string;
  lineDiscountAmount: string;
  lineDiscountReason: string | null;
  vatRate: string;
  taxCategoryCode: string | null;
  gtin: string | null;
}

export interface AccountChargeVatBreakdownInput {
  netAmount: string;
  vatAmount: string;
  grossAmount: string;
  rate: string;
  taxCategoryCode: string;
}

export interface AccountChargeOverrideApprovalInput {
  approvalId: string;
  approvalScope: AccountChargeOverrideEvidence['approval_scope'];
  cashierUserId: string;
  reasonCode: string;
  reasonText: string | null;
  requestedAtDevice: Date;
  resolvedAtDevice: Date;
  supervisorUserId: string;
  supervisorUserSnapshot: Record<string, unknown>;
}

export interface AuthorAccountChargeInput {
  tenantId: string;
  companyId: string;
  terminalId: string;
  terminalName: string;
  operatorId: string;
  operatorName: string;
  shiftId: string;
  accountChargeUuid?: string;
  currency: string;
  seller: SaleReceiptSellerInput;
  customer: AttachedCheckoutCustomer | null;
  lines: AccountChargeLineInput[];
  vatBreakdown: AccountChargeVatBreakdownInput[];
  subtotal: string;
  vatTotal: string;
  total: string;
  transactionDiscountAmount: string;
  transactionDiscountReason: string | null;
  payments?: ReadonlyArray<unknown>;
  businessDate?: string;
  eventTimeDevice?: Date;
  isTraining?: boolean;
  notes?: string | null;
  externalReference?: string | null;
  relatedSaleReceiptEventId?: string | null;
  customerSnapshotStale?: boolean;
  balanceSnapshotStale?: boolean;
  stalenessReason?: 'never_synced' | 'older_than_threshold' | 'server_conflict_pending' | null;
  aliasCandidates?: string[];
  hardStaleAfterMinutes?: number;
  overrideApproval?: AccountChargeOverrideApprovalInput | null;
  overrideEvidence?: AccountChargeOverrideEvidence | null;
}

export interface AccountChargeResult {
  accountChargeUuid: string;
  fiscalEventId: string;
  total: string;
  currency: string;
  fiscalHash: string;
  sequenceNumber: number;
  canonicalBytes: string;
  fiscalEvent: FiscalEventAppendResult;
  payload: AccountChargePayload;
  printable: AccountChargePrintable;
}

function requireText(value: string | null | undefined, path: string): string {
  const normalized = value?.trim() ?? '';
  if (normalized === '') {
    throw new AccountChargeInputError(`${path} is required for ACCOUNT_CHARGE authoring.`);
  }
  return normalized;
}

function isoSecondsUtc(date: Date): string {
  return date.toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function assertSupportedScale(scale: number, currency: string): asserts scale is 0 | 2 | 3 {
  if (scale !== 0 && scale !== 2 && scale !== 3) {
    throw new AccountChargeInputError(
      `Unsupported currency scale ${String(scale)} for ${currency}; expected 0, 2, or 3.`,
    );
  }
}

function maxZero(value: string, scale: number): string {
  return bccomp(value, '0') < 0 ? bcformat('0', scale) : bcformat(value, scale);
}

function normalizeTaxNumberForCountry(taxNumber: string, countryCode: string): string {
  if (countryCode === 'TN') {
    return taxNumber.replace(/\//g, '');
  }

  return taxNumber;
}

function buildSeller(input: SaleReceiptSellerInput): AccountChargeSeller {
  const countryCode = requireText(input.countryCode, 'seller.address.country_code').toUpperCase();
  const taxNumber = normalizeTaxNumberForCountry(
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

function buildDueDate(businessDate: string, paymentTermsDays: number | null): string | null {
  if (paymentTermsDays === null) return null;
  const date = new Date(`${businessDate}T00:00:00.000Z`);
  if (Number.isNaN(date.getTime())) {
    throw new AccountChargeInputError('businessDate must be a valid YYYY-MM-DD date.');
  }
  date.setUTCDate(date.getUTCDate() + paymentTermsDays);
  return date.toISOString().slice(0, 10);
}

function buildBalanceSnapshot(
  customer: AttachedCheckoutCustomer,
  chargeAmount: string,
  balanceUpdatedAt: string,
  scale: number,
): AccountChargePayload['local_balance_snapshot'] {
  const receivableBefore = bcformat(customer.receivable_balance, scale);
  const creditBefore = bcformat(customer.credit_balance, scale);
  const amount = bcformat(chargeAmount, scale);
  const netBefore = maxZero(bcsub(receivableBefore, creditBefore, scale), scale);
  const projectedReceivable = bcformat(bcadd(receivableBefore, amount, scale), scale);
  const projectedCredit = creditBefore;
  const projectedNet = maxZero(bcsub(projectedReceivable, projectedCredit, scale), scale);

  return {
    balance_updated_at: balanceUpdatedAt,
    charge_amount: amount,
    credit_balance_before: creditBefore,
    net_balance_before: netBefore,
    projected_credit_balance_after: projectedCredit,
    projected_net_balance_after: projectedNet,
    projected_receivable_balance_after: projectedReceivable,
    receivable_balance_before: receivableBefore,
  };
}

function assertNoPaymentLines(input: AuthorAccountChargeInput): void {
  if (input.payments !== undefined && input.payments.length > 0) {
    throw new AccountChargeInputError(
      'account_charge_payments_forbidden: ACCOUNT_CHARGE is non-collected and must not carry payment lines.',
    );
  }
}

function assertCustomerSelected(
  customer: AttachedCheckoutCustomer | null,
): asserts customer is AttachedCheckoutCustomer {
  if (customer === null) {
    throw new AccountChargeInputError(
      'customer_required: ACCOUNT_CHARGE requires a selected customer.',
    );
  }
}

function buildCreditDecision(
  input: AuthorAccountChargeInput & {
    customer: AttachedCheckoutCustomer;
    eventTimeDevice: Date;
    scale: 0 | 2 | 3;
    total: string;
  },
): AccountChargePayload['credit_decision'] {
  const decisionInput: AccountChargeCreditDecisionInput = {
    tenant_id: input.customer.tenant_id,
    company_id: input.customer.company_id,
    expected_tenant_id: input.tenantId,
    expected_company_id: input.companyId,
    customer_id: input.customer.id,
    customer_sync_status: input.customer.customer_sync_status,
    alias_candidates: input.aliasCandidates ?? [],
    is_active: input.customer.is_active,
    account_status: input.customer.account_status,
    charge_account_enabled: input.customer.charge_account_enabled,
    charge_policy_version: input.customer.charge_policy_version,
    receivable_balance: bcformat(input.customer.receivable_balance, input.scale),
    credit_balance: bcformat(input.customer.credit_balance, input.scale),
    credit_limit: input.customer.credit_limit === null
      ? null
      : bcformat(input.customer.credit_limit, input.scale),
    charge_amount: input.total,
    currency_scale: input.scale,
    balance_updated_at: input.customer.balance_updated_at,
    now: input.eventTimeDevice,
    hard_stale_after_minutes: input.hardStaleAfterMinutes ?? 240,
    override_evidence: input.overrideEvidence ?? null,
  };

  const result = evaluateAccountChargeCreditDecision(decisionInput);
  if (!result.ok) {
    throw new AccountChargeInputError(
      `account_charge_credit_rejected:${result.error.code}${result.error.field ? `:${result.error.field}` : ''}`,
    );
  }

  return result.decision;
}

function buildOverrideTarget(input: {
  customer: AttachedCheckoutCustomer;
  policyVersion: string;
  total: string;
}): Record<string, unknown> {
  return {
    account_status: input.customer.account_status,
    amount: input.total,
    customer_id: input.customer.id,
    policy_version: input.policyVersion,
  };
}

function overrideEventTypeFor(
  approvalScope: AccountChargeOverrideApprovalInput['approvalScope'],
): 'OVERRIDE_CREDIT_LIMIT' | 'OVERRIDE_ACCOUNT_STATUS' {
  return approvalScope === 'credit_limit_override'
    ? 'OVERRIDE_CREDIT_LIMIT'
    : 'OVERRIDE_ACCOUNT_STATUS';
}

function requirePolicyVersion(customer: AttachedCheckoutCustomer): string {
  const policyVersion = customer.charge_policy_version?.trim() ?? '';
  if (policyVersion === '') {
    throw new AccountChargeInputError(
      'charge_policy_missing: customer.charge_policy_version is required for account-charge override authoring.',
    );
  }

  return policyVersion;
}

function buildStaleness(
  input: AuthorAccountChargeInput & { customer: AttachedCheckoutCustomer },
): AccountChargePayload['staleness'] {
  const customerStale = input.customerSnapshotStale === true;
  const balanceStale = input.balanceSnapshotStale === true;
  let reason = input.stalenessReason ?? null;
  if ((customerStale || balanceStale) && reason === null) {
    reason = 'older_than_threshold';
  }
  if (!customerStale && !balanceStale && reason !== null) {
    throw new AccountChargeInputError(
      'staleness.staleness_reason must be null when customer and balance snapshots are fresh.',
    );
  }

  return {
    balance_snapshot_stale: balanceStale,
    customer_snapshot_stale: customerStale,
    mirror_last_synced_at: input.customer.balance_updated_at,
    staleness_reason: reason,
  };
}

export function buildAccountChargePayload(input: AuthorAccountChargeInput): AccountChargePayload {
  assertNoPaymentLines(input);
  assertCustomerSelected(input.customer);

  const eventTimeDevice = input.eventTimeDevice ?? new Date();
  const eventTimeIso = eventTimeDevice.toISOString();
  const businessDate = input.businessDate ?? eventTimeIso.slice(0, 10);
  const scale = getCurrencyDecimals(input.currency);
  assertSupportedScale(scale, input.currency);

  const total = bcformat(input.total, scale);
  const subtotal = bcformat(input.subtotal, scale);
  const vatTotal = bcformat(input.vatTotal, scale);
  const transactionDiscountAmount = bcformat(input.transactionDiscountAmount, scale);
  const seller = buildSeller(input.seller);
  const creditDecision = buildCreditDecision({
    ...input,
    customer: input.customer,
    eventTimeDevice,
    scale,
    total,
  });
  const balanceUpdatedAt = input.customer.balance_updated_at;
  if (balanceUpdatedAt === null) {
    throw new AccountChargeInputError(
      'balance_snapshot_missing: customer.balance_updated_at is required for ACCOUNT_CHARGE authoring.',
    );
  }

  return {
    account_charge_uuid: input.accountChargeUuid ?? crypto.randomUUID(),
    business_date: businessDate,
    buyer: null,
    cashier_id: requireText(input.operatorId, 'cashier_id'),
    cashier_name: requireText(input.operatorName, 'cashier_name'),
    charge_terms: {
      due_date: buildDueDate(businessDate, input.customer.payment_terms_days),
      payment_terms_days: input.customer.payment_terms_days,
      terms_label: input.customer.payment_terms_days === null
        ? null
        : `Net ${String(input.customer.payment_terms_days)}`,
    },
    credit_decision: creditDecision,
    currency_code: input.currency,
    currency_scale: scale,
    customer: {
      address: null,
      account_identifier: null,
      customer_category: input.customer.customer_category,
      customer_id: requireText(input.customer.id, 'customer.customer_id'),
      customer_sync_status: input.customer.customer_sync_status,
      email: input.customer.email,
      name: requireText(input.customer.name, 'customer.name'),
      phone: input.customer.phone,
      tax_number: input.customer.tax_number === null
        ? null
        : normalizeTaxNumberForCountry(input.customer.tax_number, seller.tax_jurisdiction_country_code),
    },
    event_time_device: eventTimeIso,
    invoice_classification: input.customer.customer_category === 'business'
      ? 'b2b_facture_draft_requested'
      : 'b2c_charge_receipt',
    line_items: input.lines.map((line) => ({
      gtin: line.gtin,
      line_discount_amount: bcformat(line.lineDiscountAmount, scale),
      line_discount_reason: line.lineDiscountReason,
      line_subtotal: bcformat(line.lineSubtotal, scale),
      line_uuid: requireText(line.lineUuid, 'line_items.line_uuid'),
      line_vat: bcformat(line.lineVat, scale),
      name: requireText(line.name, 'line_items.name'),
      non_collected_subtype: null,
      product_id: requireText(line.productId, 'line_items.product_id'),
      quantity: bcformat(line.quantity, 3),
      sku: line.sku,
      tax_category_code: line.taxCategoryCode,
      unit_price: bcformat(line.unitPrice, scale),
      vat_rate: bcformat(line.vatRate, 2),
    })),
    local_balance_snapshot: buildBalanceSnapshot(input.customer, total, balanceUpdatedAt, scale),
    notes: input.notes?.trim() ? input.notes.trim() : null,
    print_profile: 'ACCOUNT_CHARGE_RECEIPT',
    receipt_type_code: 'ACCOUNT_CHARGE',
    references: input.externalReference || input.relatedSaleReceiptEventId
      ? {
        external_reference: input.externalReference ?? null,
        related_sale_receipt_event_id: input.relatedSaleReceiptEventId ?? null,
        server_customer_alias_id: null,
      }
      : null,
    regime_extensions: null,
    seller,
    shift_id: requireText(input.shiftId, 'shift_id'),
    staleness: buildStaleness({ ...input, customer: input.customer }),
    terminal_id: requireText(input.terminalId, 'terminal_id'),
    totals: {
      amount_charged_to_account: total,
      grand_total_before_charge: total,
      subtotal,
      total,
      vat_total: vatTotal,
    },
    training_flag: input.isTraining === true,
    transaction_discount_amount: transactionDiscountAmount,
    transaction_discount_reason: input.transactionDiscountReason,
    vat_breakdown: input.vatBreakdown.map((row) => ({
      gross_amount: bcformat(row.grossAmount, scale),
      net_amount: bcformat(row.netAmount, scale),
      rate: bcformat(row.rate, 2),
      tax_category_code: row.taxCategoryCode,
      vat_amount: bcformat(row.vatAmount, scale),
    })),
  };
}

export async function authorAccountCharge(
  db: Database,
  input: AuthorAccountChargeInput,
): Promise<AccountChargeResult> {
  assertNoPaymentLines(input);
  assertCustomerSelected(input.customer);

  const accountChargeUuid = input.accountChargeUuid ?? crypto.randomUUID();
  const eventTimeDevice = input.eventTimeDevice ?? new Date();
  const businessDate = input.businessDate ?? eventTimeDevice.toISOString().slice(0, 10);
  const scale = getCurrencyDecimals(input.currency);
  assertSupportedScale(scale, input.currency);
  const total = bcformat(input.total, scale);
  const hasOverrideApproval = input.overrideApproval !== null && input.overrideApproval !== undefined;
  const prebuiltPayload = hasOverrideApproval
    ? null
    : buildAccountChargePayload({
      ...input,
      accountChargeUuid,
      eventTimeDevice,
      businessDate,
    });

  let appendResult: FiscalEventAppendResult | null = null;
  let payload: AccountChargePayload | null = null;
  await db.execute('BEGIN TRANSACTION');
  try {
    const engine = await getFiscalEventEngine(input.companyId, db);
    let overrideEvidence = input.overrideEvidence ?? null;
    if (hasOverrideApproval && input.overrideApproval !== null && input.overrideApproval !== undefined) {
      const policyVersion = requirePolicyVersion(input.customer);
      const target = buildOverrideTarget({
        customer: input.customer,
        policyVersion,
        total,
      });
      const approvalPayload = {
        approval_id: input.overrideApproval.approvalId,
        approval_scope: input.overrideApproval.approvalScope,
        cashier_user_id: input.overrideApproval.cashierUserId,
        company_id: input.companyId,
        event_time_device: eventTimeDevice.toISOString(),
        policy_version: policyVersion,
        reason_code: input.overrideApproval.reasonCode,
        reason_text: input.overrideApproval.reasonText,
        regime_extensions: null,
        requested_at_device: input.overrideApproval.requestedAtDevice.toISOString(),
        resolved_at_device: input.overrideApproval.resolvedAtDevice.toISOString(),
        supervisor_user_id: input.overrideApproval.supervisorUserId,
        supervisor_user_snapshot: input.overrideApproval.supervisorUserSnapshot,
        target,
        tenant_id: input.tenantId,
        terminal_id: input.terminalId,
        training_flag: input.isTraining === true,
      };
      const approvalEvent = await engine.append(db, {
        event_type: 'OPERATOR_APPROVAL_GRANTED',
        tenant_id: input.tenantId,
        company_id: input.companyId,
        terminal_id: input.terminalId,
        operator_id: input.overrideApproval.supervisorUserId,
        event_time_device: isoSecondsUtc(eventTimeDevice),
        business_date: businessDate,
        payload: approvalPayload,
        source_event_class: 'operator_approval',
        source_event_id: input.overrideApproval.approvalId,
      });

      const overrideEventType = overrideEventTypeFor(input.overrideApproval.approvalScope);
      const overridePayload = {
        approval_event_id: approvalEvent.id,
        approval_id: input.overrideApproval.approvalId,
        approval_scope: input.overrideApproval.approvalScope,
        company_id: input.companyId,
        event_time_device: eventTimeDevice.toISOString(),
        override_context: {
          account_charge_uuid: accountChargeUuid,
          override_event_type: overrideEventType,
        },
        policy_version: policyVersion,
        reason_code: input.overrideApproval.reasonCode,
        reason_text: input.overrideApproval.reasonText,
        supervisor_user_id: input.overrideApproval.supervisorUserId,
        target,
        tenant_id: input.tenantId,
        terminal_id: input.terminalId,
        training_flag: input.isTraining === true,
      };
      const overrideEvent = await engine.append(db, {
        event_type: overrideEventType,
        tenant_id: input.tenantId,
        company_id: input.companyId,
        terminal_id: input.terminalId,
        operator_id: input.overrideApproval.supervisorUserId,
        event_time_device: isoSecondsUtc(eventTimeDevice),
        business_date: businessDate,
        payload: overridePayload,
        reference_event_id: approvalEvent.id,
        source_event_class: 'account_charge_override',
        source_event_id: `${input.overrideApproval.approvalId}:${input.overrideApproval.approvalScope}`,
      });

      overrideEvidence = {
        approval_event_id: approvalEvent.id,
        approval_scope: input.overrideApproval.approvalScope,
        override_event_id: overrideEvent.id,
        policy_version: policyVersion,
        target_account_status: input.customer.account_status,
        target_amount: total,
        target_customer_id: input.customer.id,
      };
    }

    payload = prebuiltPayload ?? buildAccountChargePayload({
      ...input,
      accountChargeUuid,
      businessDate,
      eventTimeDevice,
      overrideEvidence,
    });
    appendResult = await engine.append(db, {
      event_type: 'ACCOUNT_CHARGE',
      tenant_id: input.tenantId,
      company_id: input.companyId,
      terminal_id: input.terminalId,
      operator_id: input.operatorId,
      event_time_device: isoSecondsUtc(eventTimeDevice),
      business_date: businessDate,
      payload,
      reference_event_id: overrideEvidence?.override_event_id,
      source_event_class: 'account_charge',
      source_event_id: accountChargeUuid,
    });
    await db.execute('COMMIT');
  } catch (error) {
    await db.execute('ROLLBACK');
    throw error;
  }

  if (appendResult === null) {
    throw new Error('Fiscal event append did not return a result.');
  }
  if (payload === null) {
    throw new Error('Account charge payload was not built.');
  }

  useSyncStore.getState().incrementPendingCount();
  void useSyncStore.getState().triggerSync();

  const printable = buildAccountChargePrintable({
    payload,
    fiscalEventId: appendResult.id,
    fiscalHash: appendResult.current_hash,
    terminalName: input.terminalName,
  });

  return {
    accountChargeUuid,
    fiscalEventId: appendResult.id,
    total: payload.totals.amount_charged_to_account,
    currency: payload.currency_code,
    fiscalHash: appendResult.current_hash,
    sequenceNumber: appendResult.sequence_number,
    canonicalBytes: appendResult.canonical_bytes,
    fiscalEvent: appendResult,
    payload,
    printable,
  };
}
