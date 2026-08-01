import { getDatabase } from '@/lib/db';
import { getFiscalEventEngine } from '@/lib/fiscal/instance';
import { withWriteTransaction } from '@/lib/db/writeGate';
import type { FiscalEventAppendResult } from '@/lib/fiscal/FiscalEventEngine';
import type { ApprovalScope } from './approvalVerifier';

export type PosOverrideApprovalScope =
  | 'discount_limit_override'
  | 'tender_tolerance_override'
  | 'void_or_return_override'
  /**
   * v3-refund-chain-integration spec §4.5 errata T6 — the payout-dispute
   * audit event's own `approval_scope`. Reuses `OPERATOR_APPROVAL_GRANTED`'s
   * existing shape rather than authoring a sixth `OVERRIDE_*` event type
   * (fold item 7's explicit requirement) — there is NO paired override
   * event for this scope. `authorPosOverride()` (the PAIRED
   * approval+override authoring function below) explicitly REJECTS this
   * scope rather than silently falling through `overrideEventTypeFor()`'s
   * dispatch to a bogus `OVERRIDE_VOID_OR_RETURN` event — a dedicated
   * solo-authoring function for this scope is a separate, not-yet-built
   * caller (belongs with the payout-reconciliation UI work).
   */
  | 'payout_dispute_evidence';

export interface PosOverrideContext {
  tenantId: string;
  companyId: string;
  terminalId: string;
  cashierUserId: string;
  businessDate: string;
  isTraining: boolean;
}

export interface PosOverrideSupervisor {
  id: string;
  name: string;
  roles?: string[];
}

/**
 * The return shape of `authorPosOverride()` -- a genuinely PAIRED
 * approval+override evidence record. `approval_scope` is narrower than
 * the full `PosOverrideApprovalScope` domain: `assertApprovalScope()`
 * rejects `'payout_dispute_evidence'` before any event is appended (it
 * has no paired override event, spec §4.5 errata T6), so a real
 * `PosOverrideEvidence` can never carry that scope.
 */
export interface PosOverrideEvidence {
  approval_id: string;
  approval_event_id: string;
  approval_scope: Exclude<PosOverrideApprovalScope, 'payout_dispute_evidence'>;
  override_event_id: string;
  policy_version: string;
  supervisor_user_id: string;
  target_reference_id: string;
}

export interface AuthorPosOverrideInput {
  context: PosOverrideContext;
  supervisor: PosOverrideSupervisor;
  approvalScope: PosOverrideApprovalScope;
  targetEventType: string;
  targetReferenceId: string;
  target: Record<string, unknown>;
  policyVersion: string;
  reasonCode: string;
  reasonText: string | null;
  eventTimeDevice?: Date;
  /**
   * v3-refund-chain-integration spec §4.2 — when provided, both events use
   * these caller-supplied UUIDs as `source_event_id` instead of generating
   * one internally. Existing callers (discount/tender-tolerance overrides)
   * omit this and keep today's exact behavior -- byte-identical, zero
   * regression risk. The refund flow's caller
   * (`authorRefundReturnApprovalV3()`) pre-generates both UUIDs at
   * `refund_intents` row creation (before the manager PIN is even
   * entered) and stores them as `refund_intents.approval_source_event_id`/
   * `override_source_event_id` -- durable, known in advance, unaffected
   * by restart.
   */
  sourceEventIds?: { approval: string; override: string };
}

function isoSecondsUtc(date: Date): string {
  return date.toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function overrideEventTypeFor(scope: PosOverrideApprovalScope): FiscalEventAppendResult['event_type'] {
  if (scope === 'discount_limit_override') return 'OVERRIDE_DISCOUNT_LIMIT';
  if (scope === 'tender_tolerance_override') return 'OVERRIDE_TENDER_TOLERANCE';
  return 'OVERRIDE_VOID_OR_RETURN';
}

function assertApprovalScope(scope: PosOverrideApprovalScope): asserts scope is Extract<ApprovalScope, PosOverrideApprovalScope> {
  if (
    scope !== 'discount_limit_override' &&
    scope !== 'tender_tolerance_override' &&
    scope !== 'void_or_return_override'
  ) {
    throw new Error(`Unsupported approval scope: ${scope}`);
  }
}

export async function authorPosOverride(input: AuthorPosOverrideInput): Promise<PosOverrideEvidence> {
  assertApprovalScope(input.approvalScope);
  // Narrowing from the assertion above does not persist across the async
  // closure boundary below (TS cannot prove `input` is unmutated by the
  // time that closure runs) -- capture the narrowed value in a local
  // const instead, matching `PosOverrideEvidence.approval_scope`'s own
  // (deliberately narrower than `PosOverrideApprovalScope`) type.
  const approvalScope = input.approvalScope;

  const eventTimeDevice = input.eventTimeDevice ?? new Date();
  const approvalId = crypto.randomUUID();
  const db = await getDatabase(input.context.companyId);
  const engine = await getFiscalEventEngine(input.context.companyId, db);

  // Single-writer architecture: both appends run as ONE exclusive write-gate
  // transaction on the single connection (fiscal lane) — see writeGate.ts.
  return withWriteTransaction('fiscal', async (tx) => {
    const approvalEvent = await engine.append(tx, {
      event_type: 'OPERATOR_APPROVAL_GRANTED',
      tenant_id: input.context.tenantId,
      company_id: input.context.companyId,
      terminal_id: input.context.terminalId,
      operator_id: input.supervisor.id,
      event_time_device: isoSecondsUtc(eventTimeDevice),
      business_date: input.context.businessDate,
      payload: {
        approval_id: approvalId,
        approval_scope: approvalScope,
        cashier_user_id: input.context.cashierUserId,
        company_id: input.context.companyId,
        event_time_device: eventTimeDevice.toISOString(),
        policy_version: input.policyVersion,
        reason_code: input.reasonCode,
        reason_text: input.reasonText,
        regime_extensions: null,
        requested_at_device: eventTimeDevice.toISOString(),
        resolved_at_device: eventTimeDevice.toISOString(),
        supervisor_user_id: input.supervisor.id,
        supervisor_user_snapshot: {
          name: input.supervisor.name,
          roles: input.supervisor.roles ?? [],
        },
        target: input.target,
        tenant_id: input.context.tenantId,
        terminal_id: input.context.terminalId,
        training_flag: input.context.isTraining,
      },
      source_event_class: 'operator_approval',
      // v3-refund-chain-integration spec §4.2 — a caller-supplied,
      // pre-generated UUID (known BEFORE this call, e.g. at refund_intents
      // row creation) makes the append itself recoverable by a stable,
      // pre-known identifier after a crash. Existing callers (discount/
      // tender-tolerance overrides) omit `sourceEventIds` and keep
      // today's exact behavior -- byte-identical, zero regression risk.
      source_event_id: input.sourceEventIds?.approval ?? approvalId,
    });

    const overrideEventType = overrideEventTypeFor(approvalScope);
    const overrideEvent = await engine.append(tx, {
      event_type: overrideEventType,
      tenant_id: input.context.tenantId,
      company_id: input.context.companyId,
      terminal_id: input.context.terminalId,
      operator_id: input.supervisor.id,
      event_time_device: isoSecondsUtc(eventTimeDevice),
      business_date: input.context.businessDate,
      payload: {
        approval_event_id: approvalEvent.id,
        approval_id: approvalId,
        approval_scope: approvalScope,
        company_id: input.context.companyId,
        event_time_device: eventTimeDevice.toISOString(),
        override_context: {
          target_event_type: input.targetEventType,
          target_reference_id: input.targetReferenceId,
        },
        policy_version: input.policyVersion,
        reason_code: input.reasonCode,
        reason_text: input.reasonText,
        supervisor_user_id: input.supervisor.id,
        target: input.target,
        tenant_id: input.context.tenantId,
        terminal_id: input.context.terminalId,
        training_flag: input.context.isTraining,
      },
      reference_event_id: approvalEvent.id,
      source_event_class: 'pos_override',
      source_event_id: input.sourceEventIds?.override ?? `${input.targetReferenceId}:${approvalScope}`,
    });

    return {
      approval_id: approvalId,
      approval_event_id: approvalEvent.id,
      approval_scope: approvalScope,
      override_event_id: overrideEvent.id,
      policy_version: input.policyVersion,
      supervisor_user_id: input.supervisor.id,
      target_reference_id: input.targetReferenceId,
    };
  });
}
