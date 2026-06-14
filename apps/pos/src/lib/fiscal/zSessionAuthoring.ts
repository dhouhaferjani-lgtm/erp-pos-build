import type Database from '@tauri-apps/plugin-sql';

import { getDatabase } from '@/lib/db';
import type {
  FiscalChainContext,
  FiscalEventAppendRequest,
  FiscalEventAppendResult,
  SqlSurface,
} from '@/lib/fiscal/FiscalEventEngine';
import { getFiscalEventEngine } from '@/lib/fiscal/instance';
import { withWriteTransaction } from '@/lib/db/writeGate';
import { bcformat } from '@/lib/decimal';
import { lockTerminal } from '@/lib/offline/terminalMutex';
import {
  nextShiftNumber,
  insertLocalShift,
  closeLocalShift,
} from '@/lib/db/repositories/localShiftRepository';
import {
  getMaxReceiptHashSequence,
  insertShiftReceiptAnchor,
} from '@/lib/db/repositories/shiftReceiptAnchorRepository';

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
  /** The device-minted, per-terminal monotone shift number assigned in-tx. */
  shiftNumber: number;
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

export type ZCashDrawerMovementType =
  | 'OPENING_FLOAT'
  | 'CASH_IN'
  | 'CASH_OUT'
  | 'SAFE_DROP'
  | 'CASH_CORRECTION';

export interface ZCashDrawerMovementApproval {
  approval_event_id: string;
  approval_id: string;
  supervisor_user_id: string;
  scope: 'cash_drawer_control';
  policy_version: string;
  target_hash: string;
}

export interface AuthorZCashDrawerMovementInput {
  tenantId: string;
  companyId: string;
  terminalId: string;
  shiftId: string;
  sessionId: string;
  businessDate: string;
  operatorId: string;
  operatorName: string;
  movementType: ZCashDrawerMovementType;
  amount: string;
  currencyCode: string;
  currencyScale: 0 | 2 | 3;
  reasonCode: string;
  reasonText: string | null;
  cashDrawerOperationId: string | null;
  approval: ZCashDrawerMovementApproval | null;
  isTraining: boolean;
  eventTimeDevice?: Date;
  movementId?: string;
}

export interface AuthorZCashDrawerMovementResult {
  movementEvent: FiscalEventAppendResult;
  movementId: string;
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

interface ZSessionAnchorEvent {
  id: string;
  current_hash: string;
  sequence_number: number;
}

export class ZSessionLifecycleError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'ZSessionLifecycleError';
  }
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

async function findSessionOpenEvent(
  db: Database | SqlSurface,
  tenantId: string,
  terminalId: string,
  chainContext: FiscalChainContext,
  sessionId: string,
): Promise<ZSessionAnchorEvent | null> {
  const rows = await db.select<ZSessionAnchorEvent[]>(
    `SELECT id, current_hash, sequence_number
       FROM fiscal_events
      WHERE tenant_id = $1
        AND terminal_id = $2
        AND chain_context = $3
        AND event_type = 'SESSION_OPEN'
        AND source_event_class = 'pos_session'
        AND source_event_id = $4
      ORDER BY sequence_number ASC
      LIMIT 1`,
    [tenantId, terminalId, chainContext, sessionId],
  );
  return rows[0] ?? null;
}

async function requireSessionOpenEvent(
  db: Database | SqlSurface,
  input: Pick<
    AuthorXReportInput | AuthorZCashDrawerMovementInput | AuthorZSessionCloseInput,
    'tenantId' | 'terminalId' | 'sessionId' | 'isTraining'
  >,
): Promise<ZSessionAnchorEvent> {
  const chainContext = zChainContext(input.isTraining);
  const sessionOpen = await findSessionOpenEvent(
    db,
    input.tenantId,
    input.terminalId,
    chainContext,
    input.sessionId,
  );
  if (sessionOpen === null) {
    throw new ZSessionLifecycleError(
      `Z-session ${input.sessionId} on terminal ${input.terminalId} cannot append to ${chainContext} before SESSION_OPEN.`,
    );
  }
  return sessionOpen;
}

function canonicalSessionId(canonicalBytes: string): string | null {
  try {
    const decoded = JSON.parse(canonicalBytes) as { payload?: unknown };
    const payload = decoded.payload;
    if (payload !== null && typeof payload === 'object' && !Array.isArray(payload)) {
      const sessionId = (payload as { session_id?: unknown }).session_id;
      return typeof sessionId === 'string' ? sessionId : null;
    }
  } catch {
    return null;
  }
  return null;
}

async function assertNoZReportForSession(
  db: Database | SqlSurface,
  input: Pick<AuthorZSessionCloseInput, 'tenantId' | 'terminalId' | 'sessionId' | 'isTraining'>,
): Promise<void> {
  const chainContext = zChainContext(input.isTraining);
  const rows = await db.select<Array<{ id: string; canonical_bytes: string }>>(
    `SELECT id, canonical_bytes
       FROM fiscal_events
      WHERE tenant_id = $1
        AND terminal_id = $2
        AND chain_context = $3
        AND event_type = 'Z_REPORT'
      ORDER BY sequence_number ASC`,
    [input.tenantId, input.terminalId, chainContext],
  );
  const existing = rows.find((row) => canonicalSessionId(row.canonical_bytes) === input.sessionId);
  if (existing !== undefined) {
    throw new ZSessionLifecycleError(
      `Z-session ${input.sessionId} on terminal ${input.terminalId} already has Z_REPORT ${existing.id}.`,
    );
  }
}

export function buildSessionOpenPayload(
  input: AuthorZSessionOpenInput,
  openedAtDevice: Date,
  shiftNumber: number,
): Record<string, unknown> {
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
    shift_number: shiftNumber,
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

export function buildZCashDrawerMovementPayload(
  input: AuthorZCashDrawerMovementInput,
  eventTimeDevice: Date,
  movementId: string,
): Record<string, unknown> {
  return {
    amount: formatMoney(input.amount, input.currencyScale),
    approval: input.approval,
    business_date: input.businessDate,
    cash_drawer_operation_id: input.cashDrawerOperationId,
    currency_code: input.currencyCode,
    currency_scale: input.currencyScale,
    event_time_device: eventTimeDevice.toISOString(),
    movement_id: movementId,
    movement_type: input.movementType,
    operator_id: input.operatorId,
    operator_name: input.operatorName,
    reason_code: input.reasonCode,
    reason_text: input.reasonText,
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
  _db: Database | SqlSurface,
  engine: ZSessionFiscalEventEngine,
  input: AuthorZSessionOpenInput,
): Promise<AuthorZSessionOpenResult> {
  const openedAtDevice = input.openedAtDevice ?? new Date();
  const movementId = input.openingFloatMovementId ?? crypto.randomUUID();
  const chainContext = zChainContext(input.isTraining);

  // Single-writer architecture: SESSION_OPEN + OPENING_FLOAT, the receipt
  // anchor, and the `local_shifts` row are authored as ONE exclusive
  // write-gate transaction on the single connection (fiscal lane), so the
  // device shift is created all-or-nothing. The `db` parameter remains for
  // sibling read helpers; writes must not issue BEGIN through the pooled
  // plugin (see writeGate.ts). Repo helpers are typed for the pooled
  // Database; the gate's `tx` exposes the same execute/select surface.
  const result = await withWriteTransaction('fiscal', async (tx) => {
    const txDb = tx as unknown as Database;

    // Mint the per-terminal monotone shift number inside the tx. The
    // local_shifts one-open partial unique index is the correctness
    // backstop if two opens ever race (the second insert fails loud).
    const shiftNumber = await nextShiftNumber(txDb, input.terminalId);

    const sessionOpenEvent = await engine.append(tx, {
      event_type: 'SESSION_OPEN',
      tenant_id: input.tenantId,
      company_id: input.companyId,
      terminal_id: input.terminalId,
      operator_id: input.operatorId,
      event_time_device: isoSecondsUtc(openedAtDevice),
      business_date: input.businessDate,
      chain_context: chainContext,
      payload: buildSessionOpenPayload(input, openedAtDevice, shiftNumber),
      source_event_class: 'pos_session',
      source_event_id: input.sessionId,
    });

    const openingFloatEvent = await engine.append(tx, {
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

    // M3: anchor the Z window to the terminal's receipt hash_sequence at open
    // time (clock-rollback-immune). Inside the tx so a failure unwinds the
    // whole open.
    const openingHashSequence = await getMaxReceiptHashSequence(txDb, input.terminalId);
    await insertShiftReceiptAnchor(txDb, {
      shift_id: input.shiftId,
      opening_hash_sequence: openingHashSequence,
    });

    // The device-authoritative shift row — the local source of truth for
    // "the current open shift", readable with no network. One UUID per shift
    // (id == fiscal_shift_id == pos_shifts.id).
    await insertLocalShift(txDb, {
      id: input.shiftId,
      terminal_id: input.terminalId,
      session_id: input.sessionId,
      shift_number: shiftNumber,
      opening_cash: formatMoney(input.openingFloatAmount, input.currencyScale),
      opened_at: openedAtDevice.toISOString(),
      cashier_id: input.operatorId,
      cashier_name: input.operatorName,
    });

    return { sessionOpenEvent, openingFloatEvent, shiftNumber };
  });

  return {
    sessionOpenEvent: result.sessionOpenEvent,
    openingFloatEvent: result.openingFloatEvent,
    openingFloatMovementId: movementId,
    shiftNumber: result.shiftNumber,
  };
}

export async function appendXReport(
  _db: Database | SqlSurface,
  engine: ZSessionFiscalEventEngine,
  input: AuthorXReportInput,
): Promise<AuthorXReportResult> {
  const generatedAtDevice = input.generatedAtDevice ?? new Date();
  // Single-writer (M1): the SESSION_OPEN/Z guard reads and the X_REPORT append
  // run as ONE exclusive fiscal write-gate transaction on the single writer
  // connection — never via the pooled handle (see writeGate.ts).
  return withWriteTransaction('fiscal', async (tx) => {
    const sessionOpen = await requireSessionOpenEvent(tx, input);
    await assertNoZReportForSession(tx, input);
    const xReportEvent = await engine.append(tx, {
      event_type: 'X_REPORT',
      tenant_id: input.tenantId,
      company_id: input.companyId,
      terminal_id: input.terminalId,
      operator_id: input.operatorId,
      event_time_device: isoSecondsUtc(generatedAtDevice),
      business_date: input.businessDate,
      chain_context: zChainContext(input.isTraining),
      payload: buildXReportPayload(input, generatedAtDevice),
      reference_event_id: sessionOpen.id,
      source_event_class: 'x_report',
      source_event_id: input.xReportUuid,
    });

    return { xReportEvent };
  });
}

export async function appendZCashDrawerMovement(
  _db: Database | SqlSurface,
  engine: ZSessionFiscalEventEngine,
  input: AuthorZCashDrawerMovementInput,
): Promise<AuthorZCashDrawerMovementResult> {
  const eventTimeDevice = input.eventTimeDevice ?? new Date();
  const movementId = input.movementId ?? crypto.randomUUID();
  // Single-writer (M1): the guard reads and the movement append run as ONE
  // exclusive fiscal write-gate transaction on the single writer connection.
  return withWriteTransaction('fiscal', async (tx) => {
    await requireSessionOpenEvent(tx, input);
    await assertNoZReportForSession(tx, input);
    const movementEvent = await engine.append(tx, {
      event_type: input.movementType,
      tenant_id: input.tenantId,
      company_id: input.companyId,
      terminal_id: input.terminalId,
      operator_id: input.operatorId,
      event_time_device: isoSecondsUtc(eventTimeDevice),
      business_date: input.businessDate,
      chain_context: zChainContext(input.isTraining),
      payload: buildZCashDrawerMovementPayload(input, eventTimeDevice, movementId),
      reference_event_id: input.approval?.approval_event_id,
      source_event_class: 'z_cash_drawer_movement',
      source_event_id: movementId,
    });

    return { movementEvent, movementId };
  });
}

export async function appendZSessionCloseAndZReport(
  db: Database | SqlSurface,
  engine: ZSessionFiscalEventEngine,
  input: AuthorZSessionCloseInput,
): Promise<AuthorZSessionCloseResult> {
  const closedAtDevice = input.closedAtDevice ?? new Date();
  const sessionCloseUuid = input.sessionCloseUuid ?? crypto.randomUUID();
  const chainContext = zChainContext(input.isTraining);
  const sessionOpen = await requireSessionOpenEvent(db, input);
  await assertNoZReportForSession(db, input);

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
    first_sequence: sessionOpen.sequence_number,
    last_sequence: sessionCloseEvent.sequence_number,
    session_open_event_id: sessionOpen.id,
    session_open_hash: sessionOpen.current_hash,
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

  // Close the device-authoritative shift row so the terminal can open a new
  // shift (the one-open partial unique index blocks a reopen otherwise). A
  // no-op UPDATE for grandfathered shifts that pre-date local_shifts.
  await closeLocalShift(db as unknown as Database, input.shiftId, isoSecondsUtc(closedAtDevice));

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

export async function authorZCashDrawerMovement(
  input: AuthorZCashDrawerMovementInput,
): Promise<AuthorZCashDrawerMovementResult> {
  const db = await getDatabase(input.companyId);
  return lockTerminal(input.tenantId, input.terminalId, async () => {
    const engine = await getFiscalEventEngine(input.companyId, db);
    return appendZCashDrawerMovement(db, engine, input);
  });
}
