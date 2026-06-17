import bcrypt from 'bcryptjs';
import { ApiRequestError, apiPost } from '@/lib/api';
import { getDatabase } from '@/lib/db';
import { getAllOperators } from '@/lib/db/repositories/operatorPinRepository';
import { recordAuditEvent } from '@/lib/audit/recordAuditEvent';
import { FetchTimeoutError } from '@/lib/fetchWithTimeout';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { verifyOfflineApprovalPin, isApprovalCacheFresh, type ApprovalScope } from './approvalVerifier';
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

/**
 * Anti-downgrade retry policy for the online manager-PIN verification.
 *
 * A reachable-but-erroring server (5xx / 408 / 429) is RETRYABLE up to a cap,
 * then FAILS CLOSED — it must never silently downgrade to the local manager
 * PIN (a forgeable weak factor). Only a GENUINELY-unreachable server (transport
 * failure) may fall back offline.
 */
const MAX_ONLINE_RETRIES = 2; // 1 initial attempt + 2 retries = 3 attempts total.
const RETRY_BACKOFF_MS = 400;

function delay(ms: number): Promise<void> {
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}

/**
 * Positive evidence that the override failed because the terminal is GENUINELY
 * offline (server unreachable) — the only condition under which the local-PIN
 * fallback is allowed. A typed read-timeout, or the POS's authoritative offline
 * signal (the same source offlineCheckoutService uses). Anything else is an
 * unexpected error and must FAIL CLOSED (no PIN downgrade).
 */
function isGenuineOfflineFailure(error: unknown): boolean {
  if (error instanceof FetchTimeoutError) return true;
  if (!useConnectivityStore.getState().isOnline) return true;
  return false;
}

/**
 * A reachable server that could not authorize: a 5xx, or a 408 (Request
 * Timeout) / 429 (Too Many Requests). These are RETRYABLE — the server is up
 * but transiently unable to decide. NOT an authorization rejection.
 */
function isRetryableServerError(error: unknown): error is ApiRequestError {
  return (
    error instanceof ApiRequestError &&
    (error.status >= 500 || error.status === 408 || error.status === 429)
  );
}

export interface ScopedManagerPinApprovalInput {
  pin: string;
  context: PosOverrideContext;
  approvalScope: ApprovalScope;
  targetEventType: string;
  targetReferenceId: string;
  reason: string;
  /**
   * When set, only the operator with this id is considered for the local match
   * (the EOD above-hard-variance close selects a SPECIFIC manager, unlike the
   * override flows where any scope-holder may approve). Omitted → any operator
   * holding the scope may match.
   */
  targetOperatorId?: string;
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
    if (input.targetOperatorId !== undefined && operator.id !== input.targetOperatorId) {
      continue;
    }
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
        denied_kind: 'no_local_match',
      },
    }).catch(() => {});
    throw new Error('manager_pin_scope_mismatch');
  }

  // The local manager PIN matched. Now confirm with the server. The local match
  // is offline-capable, but a manager PIN is a forgeable weak factor, so a
  // REACHABLE server's decision is authoritative and must NOT be silently
  // downgraded to PIN-only. Branching (anti-downgrade):
  //
  //   1. valid:true & user matches → server-confirmed approval. Emit nothing.
  //   2. valid:false / user mismatch → throw 403 → explicit denial (case 3).
  //   3. Explicit authorization denial (ApiRequestError, status < 500 and NOT
  //      408/429, e.g. 403/422) → emit denied{server_rejected} + rethrow.
  //      NO retry, NO local fallback.
  //   4. Reachable-but-erroring (status >= 500 OR 408/429) → RETRYABLE up to a
  //      cap. Exhausted → FAIL CLOSED: emit denied{service_unavailable} + throw
  //      503. NO local fallback.
  //   5. Genuine transport failure (non-ApiRequestError) → offline-first local
  //      fallback (NO retry): emit offline_approved + return local approval.
  for (let attempt = 0; ; attempt++) {
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
        // Case 2 → throw an explicit denial; caught and handled as case 3 below.
        throw new ApiRequestError(
          403,
          online.failure_code ?? 'manager_pin_rejected',
          online.failure_code ?? 'MANAGER_PIN_REJECTED',
        );
      }

      // Case 1: server-confirmed approval.
      return {
        id: online.user_id,
        name: online.user_name ?? matched.name,
        roles: matched.roles,
      };
    } catch (error) {
      // Case 4: reachable-but-erroring server → retry up to the cap.
      if (isRetryableServerError(error)) {
        if (attempt < MAX_ONLINE_RETRIES) {
          await delay(RETRY_BACKOFF_MS);
          continue;
        }
        // Retries exhausted → FAIL CLOSED. The server is reachable (it kept
        // erroring) so we do NOT downgrade to the local PIN. Emit a denial
        // (service_unavailable = possible downgrade-attempt / outage signal)
        // and surface a service-unavailable error. NO pin/hash.
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
            denied_kind: 'service_unavailable',
          },
        }).catch(() => {});
        throw new ApiRequestError(
          503,
          'manager_override_service_unavailable',
          'MANAGER_OVERRIDE_SERVICE_UNAVAILABLE',
        );
      }

      // Case 3: explicit authorization denial (ApiRequestError, status < 500
      // and not the retryable 408/429). The server is up and said no → DENY.
      // Emit before rethrowing. NO retry, NO local fallback. NO pin/hash.
      if (error instanceof ApiRequestError) {
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
            denied_kind: 'server_rejected',
          },
        }).catch(() => {});
        throw error;
      }

      // Case 5: non-ApiRequestError — classify before deciding to fall back.
      // ONLY positive evidence of genuine offline (FetchTimeoutError or the
      // POS's authoritative isOnline === false signal) permits the local-PIN
      // fallback. Everything else is an unexpected error and must FAIL CLOSED —
      // a parser error, a wrapped module error, or a broken instanceof check
      // must NEVER silently downgrade to PIN-only approval. NO pin/hash.
      if (isGenuineOfflineFailure(error)) {
        // FU-1b — bound stale offline authority. The offline fallback bypasses
        // the server's revocation/caps checks; refuse it if the matched
        // operator's cached approval metadata is older than the TTL. FU-1 prunes
        // a suspended operator on the next successful pull, but a device that
        // never re-syncs would otherwise retain approval authority forever. Fail
        // closed (the terminal must come back online to re-establish trust) and
        // audit the refusal so the bypass attempt stays visible to fraud.
        if (!isApprovalCacheFresh(matched.approval_scope_permissions_fetched_at, Date.now())) {
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
              denied_kind: 'stale_offline_cache',
            },
          }).catch(() => {});
          throw new ApiRequestError(
            503,
            'manager_override_stale_offline_cache',
            'MANAGER_OVERRIDE_STALE_OFFLINE_CACHE',
          );
        }

        // Genuine offline-first local fallback (server unreachable). Audit the
        // bypass — this bypassed the server's caps/revocation checks and must
        // be VISIBLE to fraud. Emit pos.manager_override_offline_approved (NOT
        // a denial). NO pin/hash.
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
          },
        }).catch(() => {});
        return {
          id: matched.id,
          name: matched.name,
          roles: matched.roles,
        };
      }

      // Unexpected error while NOT known-offline → do NOT downgrade. Fail
      // closed. The server may be reachable; an unknown failure must never
      // open the PIN-downgrade hole. Emit a denial (service_unavailable) to
      // ensure the bypass attempt is visible to fraud, then surface a 503.
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
          denied_kind: 'service_unavailable',
        },
      }).catch(() => {});
      throw new ApiRequestError(
        503,
        'manager_override_service_unavailable',
        'MANAGER_OVERRIDE_SERVICE_UNAVAILABLE',
      );
    }
  }
}
