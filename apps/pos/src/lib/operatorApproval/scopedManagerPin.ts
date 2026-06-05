import bcrypt from 'bcryptjs';
import { ApiRequestError, apiPost } from '@/lib/api';
import { getDatabase } from '@/lib/db';
import { getAllOperators } from '@/lib/db/repositories/operatorPinRepository';
import { recordAuditEvent } from '@/lib/audit/recordAuditEvent';
import { verifyOfflineApprovalPin, type ApprovalScope } from './approvalVerifier';
import type { PosOverrideContext } from './posOverrideAuthoring';

/**
 * Taxonomy scope for the `pos.manager_override_denied` audit event. Maps the
 * internal {@link ApprovalScope} onto the canonical fraud-taxonomy scope value
 * for the well-known override scopes; other approval scopes (shift-variance,
 * cash-drawer, credit/account) pass through verbatim rather than being
 * mislabelled as one of the four canonical values.
 */
function toTaxonomyScope(scope: ApprovalScope): string {
  switch (scope) {
    case 'discount_limit_override':
      return 'discount_limit';
    case 'tender_tolerance_override':
      return 'tender_tolerance';
    case 'void_or_return_override':
      return 'void';
    default:
      return scope;
  }
}

export interface ScopedManagerPinApprovalInput {
  pin: string;
  context: PosOverrideContext;
  approvalScope: ApprovalScope;
  targetEventType: string;
  targetReferenceId: string;
  reason: string;
}

export interface ScopedManagerPinApproval {
  id: string;
  name: string;
  roles: string[];
}

interface VerifyManagerPinResponse {
  valid: boolean;
  user_id?: string;
  user_name?: string;
  failure_code?: string;
}

export async function verifyScopedManagerPin(
  input: ScopedManagerPinApprovalInput,
): Promise<ScopedManagerPinApproval> {
  const db = await getDatabase(input.context.companyId);
  const operators = await getAllOperators(db);
  let matched = null as (typeof operators)[number] | null;

  for (const operator of operators) {
    const result = await verifyOfflineApprovalPin({
      operator,
      pin: input.pin,
      tenantId: input.context.tenantId,
      companyId: input.context.companyId,
      terminalId: input.context.terminalId,
      approvalScope: input.approvalScope,
      bcryptCheck: (pin, hash) => bcrypt.compareSync(pin, hash),
    });
    if (result.ok) {
      matched = operator;
      break;
    }
  }

  if (matched === null) {
    // Task 11 (audit): pos.manager_override_denied — no operator's PIN matched
    // for the requested scope (failed override). NO pin/hash in the payload.
    // requested_amount / cart_total are not carried by this seam → null.
    void recordAuditEvent({
      type: 'pos.manager_override_denied',
      aggregateType: 'Override',
      aggregateId: input.context.terminalId,
      tenantId: input.context.tenantId,
      companyId: input.context.companyId,
      payload: {
        scope: toTaxonomyScope(input.approvalScope),
        requested_amount: null,
        cart_total: null,
        reason: input.reason,
      },
    }).catch(() => {});
    throw new Error('manager_pin_scope_mismatch');
  }

  try {
    const online = await apiPost<VerifyManagerPinResponse>('/pos/verify-manager-pin', {
      user_id: matched.id,
      pin: input.pin,
      company_id: input.context.companyId,
      terminal_id: input.context.terminalId,
      approval_scope: input.approvalScope,
      target_event_type: input.targetEventType,
      target_reference_id: input.targetReferenceId,
      reason: input.reason,
    });

    if (!online.valid || online.user_id !== matched.id) {
      throw new ApiRequestError(
        403,
        online.failure_code ?? 'manager_pin_rejected',
        online.failure_code ?? 'MANAGER_PIN_REJECTED',
      );
    }

    return {
      id: online.user_id,
      name: online.user_name ?? matched.name,
      roles: matched.roles,
    };
  } catch (error) {
    // 4xx codes that mean "server busy / couldn't decide" (NOT an authorization
    // rejection) → treat like a 5xx and fall back to the local approval.
    const isServerBusy4xx =
      error instanceof ApiRequestError && (error.status === 408 || error.status === 429);

    if (error instanceof ApiRequestError && error.status < 500 && !isServerBusy4xx) {
      // The server gave an EXPLICIT non-approval (4xx: valid:false → 403, bad
      // request 422, forbidden, …). The server is up and said no → DENY.
      // Denied override → emit before rethrowing. NO pin/hash.
      void recordAuditEvent({
        type: 'pos.manager_override_denied',
        aggregateType: 'Override',
        aggregateId: input.context.terminalId,
        tenantId: input.context.tenantId,
        companyId: input.context.companyId,
        operatorId: matched.id,
        payload: {
          scope: toTaxonomyScope(input.approvalScope),
          requested_amount: null,
          cart_total: null,
          reason: input.reason,
        },
      }).catch(() => {});
      throw error;
    }

    // OFFLINE-IS-REALLY-FIRST: the server could NOT give an authorization
    // decision — either a 5xx (reachable but erroring, ApiRequestError >= 500)
    // or a genuine transport failure (non-ApiRequestError: network down /
    // timeout). Keep the terminal working: fall back to the already-validated
    // LOCAL manager PIN. But this approval bypassed the server's checks (caps,
    // revocation), so it must be VISIBLE to fraud — emit
    // pos.manager_override_offline_approved (NOT a denial). `degraded_kind`
    // distinguishes a server error from true offline. NO pin/hash.
    const degradedKind = error instanceof ApiRequestError ? 'server_error' : 'transport';
    void recordAuditEvent({
      type: 'pos.manager_override_offline_approved',
      aggregateType: 'Override',
      aggregateId: input.context.terminalId,
      tenantId: input.context.tenantId,
      companyId: input.context.companyId,
      operatorId: matched.id,
      payload: {
        scope: toTaxonomyScope(input.approvalScope),
        reason: input.reason,
        degraded_kind: degradedKind,
      },
    }).catch(() => {});
    // fall through to the local-bcrypt approval below
  }

  return {
    id: matched.id,
    name: matched.name,
    roles: matched.roles,
  };
}
