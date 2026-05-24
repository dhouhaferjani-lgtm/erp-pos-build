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

export async function authorZSessionOpenWithOpeningFloat(
  input: AuthorZSessionOpenInput,
): Promise<AuthorZSessionOpenResult> {
  const db = await getDatabase(input.companyId);
  return lockTerminal(input.tenantId, input.terminalId, async () => {
    const engine = await getFiscalEventEngine(input.companyId, db);
    return authorZSessionOpenWithOpeningFloatOnDb(db, engine, input);
  });
}
