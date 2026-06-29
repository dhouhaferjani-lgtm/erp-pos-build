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
import { AlertTriangle, Loader2, X } from 'lucide-react';
import { Modal } from '@/components/pos/Modal';
import { RefundConfirmModal } from '@/components/pos/RefundConfirmModal';
import { RefundDestinationPickerStateful } from '@/components/pos/RefundDestinationPicker';
import {
  useRefundCheckoutStore,
  type RefundCheckoutError,
} from '@/stores/refundCheckoutStore';
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
  const approvalCached = useRefundCheckoutStore((s) => s.approval !== null);

  // Total being refunded = abs sum of the return-line totals SNAPSHOTTED at
  // begin() — the displayed amount is frozen to the lines that were actually
  // prepared/mapped, never the live cart (which can drift behind the modals;
  // the store fail-closes on drift before submitting).
  const refundItemsSnapshot = useRefundCheckoutStore((s) => s.refundItemsSnapshot);
  const refundAmount = useMemo(() => {
    const returnTotals = (refundItemsSnapshot ?? []).map((item) =>
      bcabs(item.line_total, decimals),
    );
    return format(bcsum(returnTotals, decimals));
  }, [refundItemsSnapshot, decimals, format]);

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
      {/* Blocking busy overlay while the (retried) server prepare runs —
          without it the preparing step rendered NOTHING and the terminal
          looked frozen. Also covers submitting as a belt-and-braces layer on
          top of the approval modal's own disabled controls. */}
      {(step === 'preparing' || isSubmitting) && (
        <div
          data-testid="refund-checkout-busy-overlay"
          className="fixed inset-0 z-[60] flex items-center justify-center bg-black/30"
          role="status"
        >
          <div className="flex items-center gap-3 rounded-xl bg-surface-raised px-6 py-4 shadow-2xl">
            <Loader2 className="h-6 w-6 animate-spin text-action" aria-hidden="true" />
            <span className="text-sm font-medium text-ink-muted">
              {step === 'preparing'
                ? t('refundFlow.checkout.preparing', { defaultValue: 'Checking the original receipt…' })
                : t('refundFlow.approval.submitting', { defaultValue: 'Processing…' })}
            </span>
          </div>
        </div>
      )}

      {/* Prepare-failure banner (idle state) — translated, dismissible. */}
      {step === 'idle' && error !== null && (
        <div
          data-testid="refund-checkout-error-banner"
          className="fixed left-1/2 top-2 z-50 flex max-w-xl -translate-x-1/2 items-center gap-2 rounded-lg bg-danger px-4 py-2 text-sm font-medium text-ink-inverse shadow-lg"
        >
          <AlertTriangle className="h-4 w-4 shrink-0" aria-hidden="true" />
          <span>{errorText(error)}</span>
          <button
            type="button"
            onClick={clearError}
            aria-label={t('refundFlow.confirm.cancel', { defaultValue: 'Cancel' })}
            className="ml-1 rounded p-0.5 hover:bg-danger-strong"
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
        closable={!isSubmitting}
        title={t('refundFlow.approval.title', { defaultValue: 'Manager authorization' })}
        size="sm"
      >
        <RefundApprovalStep
          isSubmitting={isSubmitting}
          approvalCached={approvalCached}
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
  /**
   * True once approval evidence was authored and cached for this settlement.
   * The PIN/reason inputs FREEZE then: the authored fiscal events bind the
   * original reason, and a retry reuses the cached evidence — editing either
   * field would desync the submit payload from the evidence (server 422),
   * and the retry must not demand a second PIN entry.
   */
  approvalCached: boolean;
  error: RefundCheckoutError | null;
  /** False when the approval context / terminal is unavailable — authorize disabled. */
  contextReady: boolean;
  onCancel: () => void;
  onAuthorize: (managerPin: string, reason: string) => void;
}

function RefundApprovalStep({
  isSubmitting,
  approvalCached,
  error,
  contextReady,
  onCancel,
  onAuthorize,
}: RefundApprovalStepProps) {
  const { t } = useTranslation('pos');
  const errorText = useCheckoutErrorText();
  const [managerPin, setManagerPin] = useState('');
  const [reason, setReason] = useState('');

  const inputsFrozen = isSubmitting || approvalCached;
  const authorizeDisabled =
    isSubmitting || !contextReady || (!approvalCached && managerPin.length < 4);

  return (
    <div className="space-y-4 p-4" data-testid="refund-approval-modal">
      <p className="text-sm text-ink-muted">
        {t('refundFlow.approval.subtitle', {
          defaultValue: 'A manager must authorize this refund.',
        })}
      </p>

      {/* Fixed-height error slot — space is RESERVED whether or not an error
          is showing, so the modal never resizes when a failure arrives
          (project rule: modals keep fixed dimensions on interaction). */}
      <div className="min-h-16" aria-live="polite">
        {error !== null && (
          <p
            data-testid="refund-approval-error"
            className="rounded-md bg-danger-surface p-3 text-sm text-danger-strong"
          >
            {errorText(error)}
          </p>
        )}
      </div>

      <div>
        <label
          htmlFor="refund-approval-reason"
          className="mb-1 block text-sm font-medium text-ink-muted"
        >
          {t('voidReturn.reason')}
        </label>
        <input
          id="refund-approval-reason"
          type="text"
          value={reason}
          disabled={inputsFrozen}
          onChange={(e) => setReason(e.target.value)}
          className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-accent focus:ring-2 focus:ring-accent focus:outline-none disabled:opacity-50"
        />
      </div>

      <div>
        <label
          htmlFor="refund-approval-pin"
          className="mb-1 block text-sm font-medium text-ink-muted"
        >
          {t('voidReturn.managerPin')} <span className="text-danger">*</span>
        </label>
        <input
          id="refund-approval-pin"
          type="password"
          inputMode="numeric"
          autoComplete="off"
          value={managerPin}
          disabled={inputsFrozen}
          onChange={(e) => setManagerPin(e.target.value)}
          className="w-full rounded-lg border border-border-strong px-3 py-2 text-sm focus:border-accent focus:ring-2 focus:ring-accent focus:outline-none disabled:opacity-50"
        />
      </div>

      <div className="flex gap-2 pt-2">
        <button
          type="button"
          onClick={onCancel}
          disabled={isSubmitting}
          data-testid="refund-approval-cancel"
          className="flex min-h-[48px] items-center justify-center flex-1 rounded-md border border-border-strong py-2 text-sm font-medium text-ink-muted hover:bg-surface-sunken disabled:opacity-50"
        >
          {t('refundFlow.confirm.cancel', { defaultValue: 'Cancel' })}
        </button>
        <button
          type="button"
          onClick={() => onAuthorize(managerPin, reason)}
          disabled={authorizeDisabled}
          data-testid="refund-approval-authorize"
          className="flex min-h-[48px] items-center justify-center flex-1 rounded-md bg-action py-2 text-sm font-semibold text-ink-inverse hover:bg-action-hover disabled:cursor-not-allowed disabled:opacity-50"
        >
          {isSubmitting
            ? t('refundFlow.approval.submitting', { defaultValue: 'Processing…' })
            : t('refundFlow.approval.confirm', { defaultValue: 'Authorize refund' })}
        </button>
      </div>
    </div>
  );
}
