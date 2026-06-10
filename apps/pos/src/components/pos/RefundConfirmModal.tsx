/**
 * RefundConfirmModal
 *
 * Orchestrates the refund confirm step:
 *   1. Show confirm button (with amount summary).
 *   2. On submit, call the provided `onSubmitRefund` function.
 *   3. On 422 error with typed code → show ManagerPinPanel inline.
 *   4. On manager-PIN success, re-submit with `authorized_by_user_id` populated.
 *   5. On any other error → show generic error message (toast-equivalent inline).
 *
 * Error-code handling:
 *   - MANAGER_OVERRIDE_REQUIRED, DAILY_REFUND_CAP_EXCEEDED, REFUND_WINDOW_CLOSED
 *     → inline ManagerPinPanel (spec §6.4).
 *   - BUSINESS_ERROR / unknown → inline error text; no PIN prompt. We cannot
 *     distinguish which case it is until the API exception handler session adds
 *     typed codes to bootstrap/app.php.
 *
 * TODO (follow-up — API exception handler session):
 *   Once typed exception codes land, BUSINESS_ERROR will no longer appear for
 *   the refund cases above. Remove the BUSINESS_ERROR fallback comment at that
 *   point and verify all code paths.
 *
 * Manager list:
 *   The parent supplies `authorizedManagers` (from fetchAuthorizedManagers()).
 *   In the future this could be pre-fetched and cached in a store.
 */

import { useState, useCallback, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/pos/Modal';
import { ManagerPinPanel } from '@/components/pos/molecules/ManagerPinPanel';
import { ApiRequestError } from '@/lib/api';
import {
  mapRefundErrorToUiAction,
  requiresManagerPin,
  type RefundConfirmUiAction,
} from '@/lib/refundFlow/refundConfirmation';

// ─── Types ────────────────────────────────────────────────────────────────────

export interface AuthorizedManager {
  id: string;
  name: string;
}

export interface RefundSubmitPayload {
  /** When populated, the backend will verify this manager authorized the refund. */
  authorized_by_user_id?: string;
}

export interface RefundConfirmModalProps {
  isOpen: boolean;
  onClose: () => void;

  /** Formatted refund amount string, e.g. "12.50 EUR". */
  refundAmount: string;

  /**
   * Already-translated label of the chosen refund destination (Task 2b wiring).
   * Rendered under the amount summary when provided.
   */
  destinationLabel?: string;

  /** Current logged-in cashier user ID — excluded from manager selector. */
  cashierUserId: string;

  /** List of managers eligible to authorize overrides. */
  authorizedManagers: AuthorizedManager[];

  /**
   * Called when the user submits the refund. May be called twice:
   * once without `authorized_by_user_id`, and again with it if the first
   * call returns a manager-override 422.
   *
   * @throws ApiRequestError on API-level failures.
   */
  onSubmitRefund: (payload: RefundSubmitPayload) => Promise<void>;

  /**
   * Called when the refund is submitted and accepted successfully (no API error).
   */
  onSuccess: () => void;

  /**
   * Verify a manager's PIN. Injected so this component stays testable without
   * live API calls.
   */
  onVerifyManagerPin: (userId: string, pin: string) => Promise<{ valid: boolean }>;
}

// ─── Component ────────────────────────────────────────────────────────────────

const INITIAL_THROTTLE = { until: null as string | null, failedAttempts: 0 };

export function RefundConfirmModal({
  isOpen,
  onClose,
  refundAmount,
  destinationLabel,
  cashierUserId,
  authorizedManagers,
  onSubmitRefund,
  onSuccess,
  onVerifyManagerPin,
}: RefundConfirmModalProps) {
  const { t } = useTranslation('pos');

  const [isSubmitting, setIsSubmitting] = useState(false);
  const [uiAction, setUiAction] = useState<RefundConfirmUiAction | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [authorizedManagerId, setAuthorizedManagerId] = useState<string | null>(null);
  const [pinThrottle, setPinThrottle] = useState(INITIAL_THROTTLE);
  /**
   * Ref (not state) so that `submitRefund` always reads the latest value even
   * when called synchronously after `handleManagerPinSuccess` sets it to true.
   * A state update would be batched and the closure would see the stale value.
   *
   * True once a manager-PIN-authorized re-submit has already been attempted.
   * Prevents the PIN panel from re-appearing if the second call also returns a
   * 422 — instead we fall through to the generic error path (I1 guard).
   */
  const hasRetriedWithManagerPinRef = useRef(false);

  const resetState = useCallback(() => {
    setIsSubmitting(false);
    setUiAction(null);
    setErrorMessage(null);
    setAuthorizedManagerId(null);
    setPinThrottle(INITIAL_THROTTLE);
    hasRetriedWithManagerPinRef.current = false;
  }, []);

  const handleClose = useCallback(() => {
    resetState();
    onClose();
  }, [resetState, onClose]);

  const submitRefund = useCallback(
    async (managerId?: string) => {
      setIsSubmitting(true);
      setErrorMessage(null);

      const payload: RefundSubmitPayload = {};
      if (managerId !== undefined) {
        payload.authorized_by_user_id = managerId;
      }

      try {
        await onSubmitRefund(payload);
        // Success — clear state then notify parent
        resetState();
        onSuccess();
      } catch (err: unknown) {
        // Map to UI action
        let action: RefundConfirmUiAction = 'generic';
        let message = t('refundFlow.confirm.errorGeneric', {
          defaultValue: 'An unexpected error occurred. Please try again.',
        });

        if (err instanceof ApiRequestError) {
          action = mapRefundErrorToUiAction({
            status: err.status,
            code: err.code,
            apiMessage: err.apiMessage,
          });
          message = err.apiMessage;
        }

        // I1 guard: if a manager-PIN re-submit already happened and the second
        // call also returns a PIN-requiring 422, do not re-show the PIN panel —
        // that would trap the cashier in an infinite loop.  Fall through to the
        // generic error path so the cashier can cancel and try again.
        // We read from the ref (not state) so we always see the latest value
        // even when called immediately after handleManagerPinSuccess sets it.
        if (hasRetriedWithManagerPinRef.current && requiresManagerPin(action)) {
          action = 'generic';
          // Keep message from the API (already set above)
        }

        setUiAction(action);
        if (!requiresManagerPin(action)) {
          // Show the error inline; no PIN prompt possible
          setErrorMessage(message);
        }
      } finally {
        setIsSubmitting(false);
      }
    },
    [onSubmitRefund, onSuccess, resetState, t],
  );

  // Signature matches ManagerPinPanel.onSuccess: (userId: string, name: string) => void
  // The managerName arg is not used here (display is handled inside ManagerPinPanel),
  // but we must accept it to stay aligned with the real component interface (I4).
  const handleManagerPinSuccess = useCallback(
    (managerUserId: string, _managerName: string) => {
      setAuthorizedManagerId(managerUserId);
      setUiAction(null);
      // Set the ref synchronously BEFORE calling submitRefund so the guard in
      // submitRefund reads the correct value even in the same render cycle.
      hasRetriedWithManagerPinRef.current = true;
      // Re-submit with manager authorization
      void submitRefund(managerUserId);
    },
    [submitRefund],
  );

  const showManagerPin = uiAction !== null && requiresManagerPin(uiAction);

  const pinSectionTitle = (): string => {
    switch (uiAction) {
      case 'daily-cap':
        return t('refundFlow.confirm.pinRequired.dailyCap', {
          defaultValue: 'Daily refund cap reached — manager authorization required',
        });
      case 'window-closed':
        return t('refundFlow.confirm.pinRequired.windowClosed', {
          defaultValue: 'Refund window closed — manager authorization required',
        });
      default:
        return t('refundFlow.confirm.pinRequired.override', {
          defaultValue: 'Manager authorization required',
        });
    }
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={handleClose}
      title={t('refundFlow.confirm.title', { defaultValue: 'Confirm refund' })}
      size="sm"
    >
      <div className="space-y-4 p-4" data-testid="refund-confirm-modal">
        {/* Refund amount summary */}
        <div className="rounded-lg bg-gray-50 p-3 text-center" data-testid="refund-amount-summary">
          <p className="text-sm text-gray-500">
            {t('refundFlow.confirm.amountLabel', { defaultValue: 'Refund amount' })}
          </p>
          <p className="text-2xl font-bold text-gray-900" data-testid="refund-amount-value">
            {refundAmount}
          </p>
          {destinationLabel !== undefined && (
            <p className="mt-1 text-sm text-gray-600" data-testid="refund-destination-summary">
              {t('refundFlow.destination.label', { defaultValue: 'Refund destination' })}
              {': '}
              {destinationLabel}
            </p>
          )}
        </div>

        {/* Manager PIN panel — shown when override is required */}
        {showManagerPin && (
          <div data-testid="manager-pin-override-section" className="space-y-2">
            <h4 className="text-sm font-semibold text-amber-700">
              {pinSectionTitle()}
            </h4>
            <ManagerPinPanel
              authorizedManagers={authorizedManagers}
              excludeUserId={cashierUserId}
              onVerify={onVerifyManagerPin}
              onSuccess={(userId, name) => handleManagerPinSuccess(userId, name)}
              throttle={pinThrottle}
              onThrottleUpdate={setPinThrottle}
            />
          </div>
        )}

        {/* Authorized manager confirmation */}
        {authorizedManagerId !== null && !showManagerPin && (
          <p
            data-testid="refund-manager-authorized"
            className="text-sm font-medium text-green-700"
          >
            {t('refundFlow.confirm.authorizedBy', {
              defaultValue: 'Authorized by manager',
            })}
          </p>
        )}

        {/* Generic error message */}
        {errorMessage !== null && !showManagerPin && (
          <p
            data-testid="refund-confirm-error"
            className="rounded-md bg-red-50 p-3 text-sm text-red-700"
          >
            {errorMessage}
          </p>
        )}

        {/* Action buttons — hidden while PIN panel is shown */}
        {!showManagerPin && (
          <div className="flex gap-2 pt-2">
            <button
              type="button"
              onClick={handleClose}
              disabled={isSubmitting}
              data-testid="refund-confirm-cancel"
              className="flex-1 rounded-md border border-gray-300 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
            >
              {t('refundFlow.confirm.cancel', { defaultValue: 'Cancel' })}
            </button>
            <button
              type="button"
              onClick={() => void submitRefund(authorizedManagerId ?? undefined)}
              disabled={isSubmitting}
              data-testid="refund-confirm-submit"
              className="flex-1 rounded-md bg-blue-600 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
            >
              {isSubmitting
                ? t('refundFlow.confirm.submitting', { defaultValue: 'Processing…' })
                : t('refundFlow.confirm.submit', { defaultValue: 'Refund {{amount}}', amount: refundAmount })}
            </button>
          </div>
        )}
      </div>
    </Modal>
  );
}
