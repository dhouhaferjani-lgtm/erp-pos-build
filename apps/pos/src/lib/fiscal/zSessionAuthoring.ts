import type Database from '@tauri-apps/plugin-sql';

import { getDatabase } from '@/lib/db';
import type {
  FiscalEventAppendRequest,
  FiscalEventAppendResult,
  SqlSurface,
} from '@/lib/fiscal/FiscalEventEngine';
import { getFiscalEventEngine } from '@/lib/fiscal/instance';
import { bcformat } from '@/lib/decimal';
import { lockTerminal } from '@/lib/offline/terminalMutex';

export interface AuthorZSessionOpenInput {
  tenantId: string;
  companyId: string;
  terminalId: string;
  terminalLabel: string;
  shiftId: string;
  sessionId: string;
  businessDate: string;
  operatorId: string;
  operatorName: string;
  currencyCode: string;
  currencyScale: 0 | 2 | 3;
  openingFloatAmount: string;
  isTraining: boolean;
  openedAtDevice?: Date;
  openingFloatMovementId?: string;
  openingCashDrawerOperationId?: string | null;
}

export interface AuthorZSessionOpenResult {
  sessionOpenEvent: FiscalEventAppendResult;
  openingFloatEvent: FiscalEventAppendResult;
  openingFloatMovementId: string;
}

export interface ZSessionReportTotals {
  sales_count: number;
  gross_sales: string;
  net_sales: string;
  tax_amount: string;
  refunds_count: number;
  refunds_amount: string;
  voided_count: number;
}

export interface AuthorXReportInput {
  tenantId: string;
  companyId: string;
  terminalId: string;
  shiftId: string;
  sessionId: string;
  businessDate: string;
  operatorId: string;
  operatorName: string;
  periodStart: string;
  periodEnd: string;
  xReportUuid: string;
  reportTotals: ZSessionReportTotals;
  vatBreakdown: ReadonlyArray<Record<string, unknown>>;
  paymentMethodTotals: ReadonlyArray<Record<string, unknown>>;
  cashDrawerTotals: Record<string, unknown>;
  operationalEventRange: Record<string, unknown>;
  isTraining: boolean;
  generatedAtDevice?: Date;
}

export interface AuthorXReportResult {
  xReportEvent: FiscalEventAppendResult;
}

export interface AuthorZSessionCloseInput {
  tenantId: string;
  companyId: string;
  terminalId: string;
  terminalLabel: string;
  shiftId: string;
  sessionId: string;
  businessDate: string;
  operatorId: string;
  operatorName: string;
  currencyCode: string;
  currencyScale: 0 | 2 | 3;
  periodStart: string;
  periodEnd: string;
  zReportUuid: string;
  zNumber: number;
  formattedZNumber: string;
  expectedCash: string;
  countedCash: string | null;
  varianceAmount: string | null;
  varianceDirection: 'over' | 'under' | 'balanced' | null;
  varianceSeverity: string | null;
  varianceReason: string | null;
  reportTotals: ZSessionReportTotals;
  vatBreakdown: ReadonlyArray<Record<string, unknown>>;
  paymentMethodTotals: ReadonlyArray<Record<string, unknown>>;
  cashCountLines: ReadonlyArray<Record<string, unknown>>;
  cashDrawerTotals: Record<string, unknown>;
  grandTotalsBefore: Record<string, unknown>;
  grandTotalsAfter: Record<string, unknown>;
  toleranceSummary: Record<string, unknown> | null;
  legacyReportReference: Record<string, unknown> | null;
  companySnapshot: Record<string, unknown>;
  seller: Record<string, unknown> | null;
  operationalEventRange: Record<string, unknown>;
  isTraining: boolean;
  closedAtDevice?: Date;
  sessionCloseUuid?: string;
}

export interface AuthorZSessionCloseResult {
  sessionCloseEvent: FiscalEventAppendResult;
  zReportEvent: FiscalEventAppendResult;
  sessionCloseUuid: string;
}

export interface ZSessionFiscalEventEngine {
  append(
    tx: Database | SqlSurface,
    request: FiscalEventAppendRequest,
  ): Promise<FiscalEventAppendResult>;
}

function isoSecondsUtc(date: Date): string {
  return date.toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function formatMoney(amount: string, scale: 0 | 2 | 3): string {
  return bcformat(amount, scale);
}

function zChainContext(isTraining: boolean): 'z_session' | 'training_z_session' {
  return isTraining ? 'training_z_session' : 'z_session';
}

export function buildSessionOpenPayload(input: AuthorZSessionOpenInput, openedAtDevice: Date): Record<string, unknown> {
  return {
    business_date: input.businessDate,
    currency_code: input.currencyCode,
    currency_scale: input.currencyScale,
    opened_at_device: openedAtDevice.toISOString(),
    opening_float_amount: formatMoney(input.openingFloatAmount, input.currencyScale),
    operator_id: input.operatorId,
    operator_name: input.operatorName,
    session_id: input.sessionId,
    shift_id: input.shiftId,
    terminal_id: input.terminalId,
    terminal_label: input.terminalLabel,
    training_flag: input.isTraining,
  };
}

export function buildOpeningFloatPayload(
  input: AuthorZSessionOpenInput,
  openedAtDevice: Date,
  movementId: string,
): Record<string, unknown> {
  return {
    amount: formatMoney(input.openingFloatAmount, input.currencyScale),
    approval: null,
    business_date: input.businessDate,
    cash_drawer_operation_id: input.openingCashDrawerOperationId ?? null,
    currency_code: input.currencyCode,
    currency_scale: input.currencyScale,
    event_time_device: openedAtDevice.toISOString(),
    movement_id: movementId,
    movement_type: 'OPENING_FLOAT',
    operator_id: input.operatorId,
    operator_name: input.operatorName,
    reason_code: 'opening_float',
    reason_text: null,
    session_id: input.sessionId,
    shift_id: input.shiftId,
    training_flag: input.isTraining,
  };
}

export function buildXReportPayload(
  input: AuthorXReportInput,
  generatedAtDevice: Date,
): Record<string, unknown> {
  const totals = input.reportTotals;
  return {
    business_date: input.businessDate,
    cash_drawer_totals: input.cashDrawerTotals,
    generated_at_device: generatedAtDevice.toISOString(),
    operational_event_range: input.operationalEventRange,
    operator_id: input.operatorId,
    operator_name: input.operatorName,
    payment_method_totals: input.paymentMethodTotals,
    period_end: input.periodEnd,
    period_start: input.periodStart,
    receipt_count: totals.sales_count,
    refunds_totals: {
      amount: totals.refunds_amount,
      count: totals.refunds_count,
    },
    sales_totals: {
      gross_sales: totals.gross_sales,
      net_sales: totals.net_sales,
      tax_amount: totals.tax_amount,
    },
    session_id: input.sessionId,
    shift_id: input.shiftId,
    terminal_id: input.terminalId,
    training_flag: input.isTraining,
    vat_breakdown: input.vatBreakdown,
    voids_totals: {
      count: totals.voided_count,
    },
    x_report_uuid: input.xReportUuid,
  };
}

export function buildSessionClosePayload(
  input: AuthorZSessionCloseInput,
  closedAtDevice: Date,
  sessionCloseUuid: string,
): Record<string, unknown> {
  const totals = input.reportTotals;
  return {
    business_date: input.businessDate,
    cash_count_lines: input.cashCountLines,
    cash_drawer_totals: input.cashDrawerTotals,
    closure_status: 'closed',
    counted_cash: input.countedCash,
    expected_cash: input.expectedCash,
    generated_at_device: closedAtDevice.toISOString(),
    manager_approval: null,
    operational_event_range: input.operationalEventRange,
    operator_id: input.operatorId,
    operator_name: input.operatorName,
    payment_method_totals: input.paymentMethodTotals,
    period_end: input.periodEnd,
    period_start: input.periodStart,
    receipt_count: totals.sales_count,
    refunds_totals: {
      amount: totals.refunds_amount,
      count: totals.refunds_count,
    },
    sales_totals: {
      gross_sales: totals.gross_sales,
      net_sales: totals.net_sales,
      tax_amount: totals.tax_amount,
    },
    session_close_uuid: sessionCloseUuid,
    session_id: input.sessionId,
    shift_id: input.shiftId,
    terminal_id: input.terminalId,
    training_flag: input.isTraining,
    variance_amount: input.varianceAmount,
    variance_direction: input.varianceDirection,
    variance_reason: input.varianceReason,
    variance_severity: input.varianceSeverity,
    vat_breakdown: input.vatBreakdown,
    voids_totals: {
      count: totals.voided_count,
    },
  };
}

export function buildZReportPayload(
  input: AuthorZSessionCloseInput,
  closedAtDevice: Date,
  sessionEventRange: Record<string, unknown>,
): Record<string, unknown> {
  const totals = input.reportTotals;
  return {
    business_date: input.businessDate,
    cash_count: {
      counted_cash: input.countedCash,
      expected_cash: input.expectedCash,
      lines: input.cashCountLines,
      variance_amount: input.varianceAmount,
      variance_direction: input.varianceDirection,
      variance_reason: input.varianceReason,
      variance_severity: input.varianceSeverity,
    },
    cash_drawer_totals: input.cashDrawerTotals,
    closed_at_device: closedAtDevice.toISOString(),
    company_snapshot: input.companySnapshot,
    currency_code: input.currencyCode,
    currency_scale: input.currencyScale,
    formatted_z_number: input.formattedZNumber,
    grand_totals_after: input.grandTotalsAfter,
    grand_totals_before: input.grandTotalsBefore,
    legacy_report_reference: input.legacyReportReference,
    operational_event_range: input.operationalEventRange,
    operator_id: input.operatorId,
    operator_name: input.operatorName,
    payment_method_totals: input.paymentMethodTotals,
    period_end: input.periodEnd,
    period_start: input.periodStart,
    period_type: 'DAY',
    receipt_totals: {
      count: totals.sales_count,
      gross_sales: totals.gross_sales,
      net_sales: totals.net_sales,
      tax_amount: totals.tax_amount,
    },
    refunds_totals: {
      amount: totals.refunds_amount,
      count: totals.refunds_count,
    },
    seller: input.seller,
    session_event_range: sessionEventRange,
    session_id: input.sessionId,
    shift_id: input.shiftId,
    terminal_id: input.terminalId,
    terminal_label: input.terminalLabel,
    tolerance_summary: input.toleranceSummary,
    training_flag: input.isTraining,
    vat_breakdown: input.vatBreakdown,
    voids_totals: {
      count: totals.voided_count,
    },
    z_number: input.zNumber,
    z_report_uuid: input.zReportUuid,
  };
}

export async function authorZSessionOpenWithOpeningFloatOnDb(
  db: Database | SqlSurface,
  engine: ZSessionFiscalEventEngine,
  input: AuthorZSessionOpenInput,
): Promise<AuthorZSessionOpenResult> {
  const openedAtDevice = input.openedAtDevice ?? new Date();
  const movementId = input.openingFloatMovementId ?? crypto.randomUUID();
  const chainContext = zChainContext(input.isTraining);

  await db.execute('BEGIN TRANSACTION');
  try {
    const sessionOpenEvent = await engine.append(db, {
      event_type: 'SESSION_OPEN',
      tenant_id: input.tenantId,
      company_id: input.companyId,
      terminal_id: input.terminalId,
      operator_id: input.operatorId,
      event_time_device: isoSecondsUtc(openedAtDevice),
      business_date: input.businessDate,
      chain_context: chainContext,
      payload: buildSessionOpenPayload(input, openedAtDevice),
      source_event_class: 'pos_session',
      source_event_id: input.sessionId,
    });

    const openingFloatEvent = await engine.append(db, {
      event_type: 'OPENING_FLOAT',
      tenant_id: input.tenantId,
      company_id: input.companyId,
      terminal_id: input.terminalId,
      operator_id: input.operatorId,
      event_time_device: isoSecondsUtc(openedAtDevice),
      business_date: input.businessDate,
      chain_context: chainContext,
      payload: buildOpeningFloatPayload(input, openedAtDevice, movementId),
      reference_event_id: sessionOpenEvent.id,
      source_event_class: 'z_cash_drawer_movement',
      source_event_id: movementId,
    });

    await db.execute('COMMIT');

    return {
      sessionOpenEvent,
      openingFloatEvent,
      openingFloatMovementId: movementId,
    };
  } catch (error) {
    await db.execute('ROLLBACK');
    throw error;
  }
}

export async function appendXReport(
  db: Database | SqlSurface,
  engine: ZSessionFiscalEventEngine,
  input: AuthorXReportInput,
): Promise<AuthorXReportResult> {
  const generatedAtDevice = input.generatedAtDevice ?? new Date();
  const xReportEvent = await engine.append(db, {
    event_type: 'X_REPORT',
    tenant_id: input.tenantId,
    company_id: input.companyId,
    terminal_id: input.terminalId,
    operator_id: input.operatorId,
    event_time_device: isoSecondsUtc(generatedAtDevice),
    business_date: input.businessDate,
    chain_context: zChainContext(input.isTraining),
    payload: buildXReportPayload(input, generatedAtDevice),
    source_event_class: 'x_report',
    source_event_id: input.xReportUuid,
  });

  return { xReportEvent };
}

export async function appendZSessionCloseAndZReport(
  db: Database | SqlSurface,
  engine: ZSessionFiscalEventEngine,
  input: AuthorZSessionCloseInput,
): Promise<AuthorZSessionCloseResult> {
  const closedAtDevice = input.closedAtDevice ?? new Date();
  const sessionCloseUuid = input.sessionCloseUuid ?? crypto.randomUUID();
  const chainContext = zChainContext(input.isTraining);

  const sessionCloseEvent = await engine.append(db, {
    event_type: 'SESSION_CLOSE',
    tenant_id: input.tenantId,
    company_id: input.companyId,
    terminal_id: input.terminalId,
    operator_id: input.operatorId,
    event_time_device: isoSecondsUtc(closedAtDevice),
    business_date: input.businessDate,
    chain_context: chainContext,
    payload: buildSessionClosePayload(input, closedAtDevice, sessionCloseUuid),
    source_event_class: 'pos_session_close',
    source_event_id: sessionCloseUuid,
  });

  const sessionEventRange = {
    first_sequence: 1,
    last_sequence: sessionCloseEvent.sequence_number,
    session_close_event_id: sessionCloseEvent.id,
    session_close_hash: sessionCloseEvent.current_hash,
  };

  const zReportEvent = await engine.append(db, {
    event_type: 'Z_REPORT',
    tenant_id: input.tenantId,
    company_id: input.companyId,
    terminal_id: input.terminalId,
    operator_id: input.operatorId,
    event_time_device: isoSecondsUtc(closedAtDevice),
    business_date: input.businessDate,
    chain_context: chainContext,
    payload: buildZReportPayload(input, closedAtDevice, sessionEventRange),
    reference_event_id: sessionCloseEvent.id,
    source_event_class: 'z_report',
    source_event_id: input.zReportUuid,
  });

  return {
    sessionCloseEvent,
    zReportEvent,
    sessionCloseUuid,
  };
}

export async function authorZSessionOpenWithOpeningFloat(
  input: AuthorZSessionOpenInput,
): Promise<AuthorZSessionOpenResult> {
  const db = await getDatabase(input.companyId);
  return lockTerminal(input.tenantId, input.terminalId, async () => {
    const engine = await getFiscalEventEngine(input.companyId, db);
    return authorZSessionOpenWithOpeningFloatOnDb(db, engine, input);
  });
}
