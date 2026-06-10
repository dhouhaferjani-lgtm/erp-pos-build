/**
 * RefundCheckoutFlow (Task 2b — Task 53 settlement wiring, UI half).
 *
 * Renders the modal sequence driven by `refundCheckoutStore` after Pay is
 * pressed on an all-return cart:
 *
 *   destination  → RefundDestinationPickerStateful (original / cash / voucher)
 *   confirm      → RefundConfirmModal (amount summary + chosen destination)
 *   approval     → manager-PIN modal (shared refundApproval handshake runs
 *                  inside the store action; PIN failure keeps the cashier
 *                  here, cancel aborts cleanly back to the untouched cart)
 *   (idle+error) → dismissible error banner for prepare failures
 *
 * State lives in `useRefundCheckoutStore`; this component is purely a view +
 * dispatcher. Success side effects (cart already cleared by the store; draft
 * cleanup, success toast, Phase-3 printing) flow through the parent's
 * `onRefundSettled` seam.
 */
import { useCallback, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AlertTriangle, X } from 'lucide-react';
import { Modal } from '@/components/pos/Modal';
import { RefundConfirmModal } from '@/components/pos/RefundConfirmModal';
import { RefundDestinationPickerStateful } from '@/components/pos/RefundDestinationPicker';
import {
  useRefundCheckoutStore,
  type RefundCheckoutError,
} from '@/stores/refundCheckoutStore';
import { useCartStore } from '@/stores/cartStore';
import { useCurrency } from '@/lib/currency';
import { bcabs, bcsum } from '@/lib/decimal';
import type { PosOverrideContext } from '@/lib/operatorApproval/posOverrideAuthoring';
import type { ReturnSettlementResponse } from '@/lib/refundFlow/refundSettlementService';

export interface RefundCheckoutFlowProps {
  approvalContext: PosOverrideContext | undefined;
  terminalId: string | null;
  cashierUserId: string;
  /**
   * Settled seam — receives the FULL /return response (incl. qr_token and
   * issued_voucher). Phase 3 wires AVOIR printing here; today the parent
   * records it, cleans the draft, and shows the success toast.
   */
  onRefundSettled: (response: ReturnSettlementResponse) => void;
}

/** Translate a typed checkout error (validation wraps the server message). */
function useCheckoutErrorText(): (error: RefundCheckoutError) => string {
  const { t } = useTranslation('pos');
  return useCallback(
    (error: RefundCheckoutError) =>
      error.key === 'refundFlow.checkout.errorValidation'
        ? t(error.key, { message: error.serverMessage ?? '' })
        : t(error.key),
    [t],
  );
}

export function RefundCheckoutFlow({
  approvalContext,
  terminalId,
  cashierUserId,
  onRefundSettled,
}: RefundCheckoutFlowProps) {
  const { t } = useTranslation('pos');
  const { format, decimals } = useCurrency();
  const errorText = useCheckoutErrorText();

  const step = useRefundCheckoutStore((s) => s.step);
  const error = useRefundCheckoutStore((s) => s.error);
  const destination = useRefundCheckoutStore((s) => s.destination);
  const selectDestination = useRefundCheckoutStore((s) => s.selectDestination);
  const confirmAccepted = useRefundCheckoutStore((s) => s.confirmAccepted);
  const approveAndSubmit = useRefundCheckoutStore((s) => s.approveAndSubmit);
  const cancel = useRefundCheckoutStore((s) => s.cancel);
  const clearError = useRefundCheckoutStore((s) => s.clearError);

  // Total being refunded = abs sum of the cart's return-line totals (the
  // return lines stay in the cart until the settlement succeeds).
  const cartItems = useCartStore((s) => s.items);
  const refundAmount = useMemo(() => {
    const returnTotals = cartItems
      .filter((item) => (item.kind ?? 'sale') === 'return')
      .map((item) => bcabs(item.line_total, decimals));
    return format(bcsum(returnTotals, decimals));
  }, [cartItems, decimals, format]);

  const approvalOpen = step === 'approval' || step === 'submitting';
  const isSubmitting = step === 'submitting';

  const handleAuthorize = useCallback(
    (managerPin: string, reason: string) => {
      if (approvalContext === undefined || terminalId === null) return;
      void approveAndSubmit({
        approvalContext,
        managerPin,
        reason,
        terminalId,
        onSettled: onRefundSettled,
      });
    },
    [approvalContext, terminalId, approveAndSubmit, onRefundSettled],
  );

  return (
    <>
      {/* Prepare-failure banner (idle state) — translated, dismissible. */}
      {step === 'idle' && error !== null && (
        <div
          data-testid="refund-checkout-error-banner"
          className="fixed left-1/2 top-2 z-50 flex max-w-xl -translate-x-1/2 items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-lg"
        >
          <AlertTriangle className="h-4 w-4 shrink-0" aria-hidden="true" />
          <span>{errorText(error)}</span>
          <button
            type="button"
            onClick={clearError}
            aria-label={t('refundFlow.confirm.cancel', { defaultValue: 'Cancel' })}
            className="ml-1 rounded p-0.5 hover:bg-red-700"
            data-testid="refund-checkout-error-dismiss"
          >
            <X className="h-4 w-4" />
          </button>
        </div>
      )}

      {/* Step 1 — refund destination */}
      <Modal
        isOpen={step === 'destination'}
        onClose={cancel}
        title={t('refundFlow.destination.label', { defaultValue: 'Refund destination' })}
        size="sm"
      >
        <div className="p-4">
          <RefundDestinationPickerStateful onConfirm={selectDestination} />
        </div>
      </Modal>

      {/* Step 2 — refund summary + chosen destination */}
      <RefundConfirmModal
        isOpen={step === 'confirm'}
        onClose={cancel}
        refundAmount={refundAmount}
        destinationLabel={
          destination !== null
            ? t(`refundFlow.destination.${destination}`)
            : undefined
        }
        cashierUserId={cashierUserId}
        authorizedManagers={[]}
        // Confirm advances to the manager-PIN approval step; the actual
        // submit (idempotency key + /return POST) happens there, AFTER the
        // approval evidence exists. This resolves immediately by design.
        onSubmitRefund={() => {
          confirmAccepted();
          return Promise.resolve();
        }}
        onSuccess={() => {}}
        // Unreachable in this flow (the inline PIN panel only mounts on
        // typed 422s thrown by onSubmitRefund, which never throws here) —
        // the manager PIN is collected in the dedicated approval step.
        onVerifyManagerPin={() => Promise.resolve({ valid: false })}
      />

      {/* Step 3 — manager-PIN approval + submit. The Modal renders null when
          closed, so RefundApprovalStep unmounts on cancel/settle and its
          PIN/reason state is discarded — no zombie credentials. */}
      <Modal
        isOpen={approvalOpen}
        onClose={isSubmitting ? () => {} : cancel}
        title={t('refundFlow.approval.title', { defaultValue: 'Manager authorization' })}
        size="sm"
      >
        <RefundApprovalStep
          isSubmitting={isSubmitting}
          error={error}
          contextReady={approvalContext !== undefined && terminalId !== null}
          onCancel={cancel}
          onAuthorize={handleAuthorize}
        />
      </Modal>
    </>
  );
}

// ─── Approval step (owns the PIN/reason inputs) ──────────────────────────────

interface RefundApprovalStepProps {
  isSubmitting: boolean;
  error: RefundCheckoutError | null;
  /** False when the approval context / terminal is unavailable — authorize disabled. */
  contextReady: boolean;
  onCancel: () => void;
  onAuthorize: (managerPin: string, reason: string) => void;
}

function RefundApprovalStep({
  isSubmitting,
  error,
  contextReady,
  onCancel,
  onAuthorize,
}: RefundApprovalStepProps) {
  const { t } = useTranslation('pos');
  const errorText = useCheckoutErrorText();
  const [managerPin, setManagerPin] = useState('');
  const [reason, setReason] = useState('');

  const authorizeDisabled = isSubmitting || managerPin.length < 4 || !contextReady;

  return (
    <div className="space-y-4 p-4" data-testid="refund-approval-modal">
      <p className="text-sm text-gray-600">
        {t('refundFlow.approval.subtitle', {
          defaultValue: 'A manager must authorize this refund.',
        })}
      </p>

      {error !== null && (
        <p
          data-testid="refund-approval-error"
          className="rounded-md bg-red-50 p-3 text-sm text-red-700"
        >
          {errorText(error)}
        </p>
      )}

      <div>
        <label
          htmlFor="refund-approval-reason"
          className="mb-1 block text-sm font-medium text-gray-700"
        >
          {t('voidReturn.reason')}
        </label>
        <input
          id="refund-approval-reason"
          type="text"
          value={reason}
          disabled={isSubmitting}
          onChange={(e) => setReason(e.target.value)}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none disabled:opacity-50"
        />
      </div>

      <div>
        <label
          htmlFor="refund-approval-pin"
          className="mb-1 block text-sm font-medium text-gray-700"
        >
          {t('voidReturn.managerPin')} <span className="text-red-500">*</span>
        </label>
        <input
          id="refund-approval-pin"
          type="password"
          inputMode="numeric"
          autoComplete="off"
          value={managerPin}
          disabled={isSubmitting}
          onChange={(e) => setManagerPin(e.target.value)}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none disabled:opacity-50"
        />
      </div>

      <div className="flex gap-2 pt-2">
        <button
          type="button"
          onClick={onCancel}
          disabled={isSubmitting}
          data-testid="refund-approval-cancel"
          className="flex-1 rounded-md border border-gray-300 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
        >
          {t('refundFlow.confirm.cancel', { defaultValue: 'Cancel' })}
        </button>
        <button
          type="button"
          onClick={() => onAuthorize(managerPin, reason)}
          disabled={authorizeDisabled}
          data-testid="refund-approval-authorize"
          className="flex-1 rounded-md bg-blue-600 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
        >
          {isSubmitting
            ? t('refundFlow.approval.submitting', { defaultValue: 'Processing…' })
            : t('refundFlow.approval.confirm', { defaultValue: 'Authorize refund' })}
        </button>
      </div>
    </div>
  );
}
