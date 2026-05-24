import { getDatabase } from '@/lib/db';
import { FiscalEventCanonicalEncoder } from '@/lib/fiscal/FiscalEventCanonicalEncoder';
import { getFiscalEventEngine } from '@/lib/fiscal/instance';
import { lockTerminal } from '@/lib/offline/terminalMutex';
import type { CashDrawerApprovalEvidence } from '@/api/cashDrawerApi';
import type { PosOverrideContext, PosOverrideSupervisor } from './posOverrideAuthoring';

export type CashDrawerApprovalOperation = 'deposit' | 'payout';

export interface CashDrawerApprovalTarget {
  operation_type: 'DEPOSIT' | 'PAYOUT';
  amount: string;
  reason: string;
  shift_id: string;
  target_reference_id: string;
}

export interface AuthorCashDrawerApprovalInput {
  context: PosOverrideContext;
  supervisor: PosOverrideSupervisor;
  operation: CashDrawerApprovalOperation;
  amount: string;
  reason: string;
  shiftId: string;
  targetReferenceId: string;
  eventTimeDevice?: Date;
}

const encoder = new FiscalEventCanonicalEncoder();

function isoSecondsUtc(date: Date): string {
  return date.toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function formatCashAmount(amount: string): string {
  return Number.parseFloat(amount).toFixed(3);
}

export function buildCashDrawerApprovalTarget(input: {
  operation: CashDrawerApprovalOperation;
  amount: string;
  reason: string;
  shiftId: string;
  targetReferenceId: string;
}): CashDrawerApprovalTarget {
  return {
    operation_type: input.operation === 'deposit' ? 'DEPOSIT' : 'PAYOUT',
    amount: input.amount,
    reason: input.reason,
    shift_id: input.shiftId,
    target_reference_id: input.targetReferenceId,
  };
}

export function hashCashDrawerApprovalTarget(target: CashDrawerApprovalTarget): string {
  return encoder.sha256Hex(encoder.encode(target));
}

export async function authorCashDrawerApproval(
  input: AuthorCashDrawerApprovalInput,
): Promise<CashDrawerApprovalEvidence> {
  const eventTimeDevice = input.eventTimeDevice ?? new Date();
  const approvalId = crypto.randomUUID();
  const target = buildCashDrawerApprovalTarget({
    operation: input.operation,
    amount: input.amount,
    reason: input.reason,
    shiftId: input.shiftId,
    targetReferenceId: input.targetReferenceId,
  });
  const targetHash = hashCashDrawerApprovalTarget(target);

  const db = await getDatabase(input.context.companyId);
  return lockTerminal(input.context.tenantId, input.context.terminalId, async () => {
    const engine = await getFiscalEventEngine(input.context.companyId, db);

    await db.execute('BEGIN TRANSACTION');
    try {
      const approvalEvent = await engine.append(db, {
        event_type: 'OPERATOR_APPROVAL_GRANTED',
        tenant_id: input.context.tenantId,
        company_id: input.context.companyId,
        terminal_id: input.context.terminalId,
        operator_id: input.supervisor.id,
        event_time_device: isoSecondsUtc(eventTimeDevice),
        business_date: input.context.businessDate,
        payload: {
          approval_id: approvalId,
          approval_scope: 'cash_drawer_control',
          cashier_user_id: input.context.cashierUserId,
          company_id: input.context.companyId,
          event_time_device: eventTimeDevice.toISOString(),
          policy_version: 'pos-cash-drawer-policy-v1',
          reason_code: 'cash_drawer_control',
          reason_text: input.reason,
          regime_extensions: null,
          requested_at_device: eventTimeDevice.toISOString(),
          resolved_at_device: eventTimeDevice.toISOString(),
          supervisor_user_id: input.supervisor.id,
          supervisor_user_snapshot: {
            name: input.supervisor.name,
            roles: input.supervisor.roles ?? [],
          },
          target: {
            ...target,
            target_hash: targetHash,
          },
          tenant_id: input.context.tenantId,
          terminal_id: input.context.terminalId,
          training_flag: input.context.isTraining,
        },
        source_event_class: 'operator_approval',
        source_event_id: approvalId,
      });

      await engine.append(db, {
        event_type: input.operation === 'deposit' ? 'SAFE_DROP' : 'CASH_OUT',
        tenant_id: input.context.tenantId,
        company_id: input.context.companyId,
        terminal_id: input.context.terminalId,
        operator_id: input.context.cashierUserId,
        event_time_device: isoSecondsUtc(eventTimeDevice),
        business_date: input.context.businessDate,
        payload: {
          approval_event_id: approvalEvent.id,
          approval_id: approvalId,
          approval_scope: 'cash_drawer_control',
          amount: formatCashAmount(input.amount),
          company_id: input.context.companyId,
          event_time_device: eventTimeDevice.toISOString(),
          operation_type: input.operation === 'deposit' ? 'DEPOSIT' : 'PAYOUT',
          reason: input.reason,
          shift_id: input.shiftId,
          supervisor_user_id: input.supervisor.id,
          target_reference_id: input.targetReferenceId,
          tenant_id: input.context.tenantId,
          terminal_id: input.context.terminalId,
          training_flag: input.context.isTraining,
        },
        reference_event_id: approvalEvent.id,
        source_event_class: 'cash_drawer_operation',
        source_event_id: input.targetReferenceId,
      });

      await db.execute('COMMIT');

      return {
        approval_id: approvalId,
        approval_fiscal_event_id: approvalEvent.id,
        approval_scope: 'cash_drawer_control',
        approval_supervisor_user_id: input.supervisor.id,
        approval_target_hash: targetHash,
      };
    } catch (error) {
      await db.execute('ROLLBACK');
      throw error;
    }
  });
}
