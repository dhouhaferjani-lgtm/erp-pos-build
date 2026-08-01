/**
 * v3-refund-chain-integration spec §4.5 — payout-confirmation AND
 * reprint-recovery screens for a v4 device-authored refund.
 *
 * Two prompts, mutually exclusive, shown one at a time, oldest-first:
 *
 *   1. Payout confirmation — a `refund_intents` row has an appended fiscal
 *      event (`refund_fiscal_event_id IS NOT NULL`) but no
 *      `payout_confirmed_at` yet: "did the cash leave the drawer?"
 *        - Yes    → `confirmRefundIntentPayout()`, then print.
 *        - No / Not sure → `disputeRefundIntentPayout()` PLUS a signed
 *          `authorPayoutDisputeEvidence()` audit event (§4.5's dispute
 *          money-effect ruling: this does NOT change the fiscal event's
 *          already-signed effect and does NOT exclude the refund from the
 *          device Z's expected-cash subtraction — purely a server-side
 *          evidence signal for finance-team investigation) — still prints
 *          (dispute state does not block the AVOIR).
 *   2. Reprint recovery — `payout_confirmed_at IS NOT NULL AND printed_at
 *      IS NULL` (the confirm succeeded but the app closed/crashed before
 *      the print completed): "print it now?" Print sets `printed_at`;
 *      Skip leaves it null and re-prompts next restart (never silently
 *      dropped).
 *
 * Triggered on mount (app-start recovery, §4.5's own requirement) AND
 * whenever `useRefundReconciliationStore`'s epoch bumps (immediately after
 * a v4 settle, `refundCheckoutStore.ts`'s own trigger) — so the prompt
 * appears right away, not only on the next app restart.
 *
 * The AVOIR itself prints via the SAME local-SQLite path §4.5 names
 * explicitly: `getOfflineReceiptForPrint()` reads the refund's own
 * `offline_receipts` row by `idempotency_key = refund_intents.id` (§7.2) —
 * never a server call.
 */
import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/pos/Modal';
import { getDatabase } from '@/lib/db';
import { useAuthStore } from '@/stores/authStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { usePrinterStore } from '@/stores/printerStore';
import { useRefundReconciliationStore } from '@/stores/refundReconciliationStore';
import {
  getRefundIntentsPendingPayoutConfirmation,
  getRefundIntentsPendingReprint,
  confirmRefundIntentPayout,
  disputeRefundIntentPayout,
  confirmRefundIntentPrinted,
  type RefundIntentRow,
} from '@/lib/db/repositories/refundIntentRepository';
import { authorPayoutDisputeEvidence } from '@/lib/refundFlow/refundApprovalV3';
import { getOfflineReceiptForPrint } from '@/lib/offline/getOfflineReceiptForPrint';
import { buildEscPosReceiptData } from '@/lib/buildReceiptData';
import { getPrintSettingsFromStore, isTauriEnvironment, printReceipt } from '@/lib/printing';
import { formatCurrency } from '@/lib/currency';
import type { FullReceiptResponse } from '@/types/receipt';
import { serializeErrorForLog } from '@/lib/errorLogging';

type PendingPrompt =
  | { kind: 'confirm'; intent: RefundIntentRow; receipt: FullReceiptResponse | null }
  | { kind: 'reprint'; intent: RefundIntentRow };

/** Best-effort AVOIR print — never throws; a failure surfaces nothing more
 *  than a console log (the reconciliation flow itself must never get stuck
 *  behind a printer problem, mirroring `printRefundSettlementArtifacts`'s
 *  own never-block philosophy for the legacy path). Returns whether the
 *  print is considered to have happened (so the caller can decide whether
 *  to stamp `printed_at`). */
async function attemptPrint(intentId: string): Promise<boolean> {
  if (!isTauriEnvironment()) return false;
  const { printerConfig } = usePrinterStore.getState();
  if (printerConfig === null) return false;

  try {
    const fullReceipt = await getOfflineReceiptForPrint(intentId);
    const sellerLocation = useTerminalStore.getState().terminal?.location ?? null;
    const receiptData = buildEscPosReceiptData(fullReceipt, {
      extras: { receiptKind: 'refund', qrToken: null },
      sellerLocation,
    });
    await printReceipt(receiptData, printerConfig, getPrintSettingsFromStore());
    return true;
  } catch (error) {
    console.error('[refundReconciliation] AVOIR print failed', serializeErrorForLog(error));
    return false;
  }
}

export function RefundPayoutReconciliationModal() {
  const { t } = useTranslation('pos');
  const epoch = useRefundReconciliationStore((s) => s.epoch);
  const [prompt, setPrompt] = useState<PendingPrompt | null>(null);
  const [busy, setBusy] = useState(false);

  const refresh = useCallback(async () => {
    // Best-effort, non-blocking by design (matches this feature's own
    // established printing/dispute-evidence philosophy): a failure here
    // (missing company context, a transient DB read failure, an
    // unavailable Tauri connection at app-start) must never surface as an
    // unhandled rejection or crash HomePage — it just means this refresh
    // cycle doesn't show the prompt; the next trigger (app restart or the
    // next v4 settle) tries again.
    try {
      const companyId = useAuthStore.getState().companyId;
      if (!companyId) {
        setPrompt(null);
        return;
      }
      const db = await getDatabase(companyId);

      const pendingConfirmation = await getRefundIntentsPendingPayoutConfirmation(db);
      if (pendingConfirmation.length > 0) {
        const intent = pendingConfirmation[0]!;
        let receipt: FullReceiptResponse | null = null;
        try {
          receipt = await getOfflineReceiptForPrint(intent.id);
        } catch (error) {
          console.error(
            '[refundReconciliation] failed to load the refund receipt for amount display',
            serializeErrorForLog(error),
          );
        }
        setPrompt({ kind: 'confirm', intent, receipt });
        return;
      }

      const pendingReprint = await getRefundIntentsPendingReprint(db);
      if (pendingReprint.length > 0) {
        setPrompt({ kind: 'reprint', intent: pendingReprint[0]! });
        return;
      }

      setPrompt(null);
    } catch (error) {
      console.error('[refundReconciliation] refresh failed (non-fatal)', serializeErrorForLog(error));
    }
  }, []);

  // App-start recovery (§4.5) — runs once on mount.
  useEffect(() => {
    void refresh();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Immediate post-settle trigger — refundCheckoutStore bumps this epoch
  // right after a v4 settlement.
  useEffect(() => {
    if (epoch === 0) return;
    void refresh();
  }, [epoch, refresh]);

  const handleConfirmPayout = useCallback(async () => {
    if (prompt === null || prompt.kind !== 'confirm' || busy) return;
    setBusy(true);
    try {
      const companyId = useAuthStore.getState().companyId;
      if (!companyId) return;
      const db = await getDatabase(companyId);
      await confirmRefundIntentPayout(db, prompt.intent.id);
      const printed = await attemptPrint(prompt.intent.id);
      if (printed) {
        await confirmRefundIntentPrinted(db, prompt.intent.id);
      }
      await refresh();
    } finally {
      setBusy(false);
    }
  }, [prompt, busy, refresh]);

  const handleDisputePayout = useCallback(async () => {
    if (prompt === null || prompt.kind !== 'confirm' || busy) return;
    setBusy(true);
    try {
      const companyId = useAuthStore.getState().companyId;
      const terminal = useTerminalStore.getState().terminal;
      const operator = useOperatorStore.getState().operator;
      if (!companyId || terminal === null || operator === null) return;
      const db = await getDatabase(companyId);
      await disputeRefundIntentPayout(db, prompt.intent.id);

      // §4.5 — a signed, server-observable evidence record. Best-effort:
      // a failure here must never block the reconciliation flow (the
      // fiscal event's own effect and the device Z are ALREADY correct
      // regardless of whether this audit event lands).
      try {
        await authorPayoutDisputeEvidence({
          context: {
            tenantId: useAuthStore.getState().user?.tenantId ?? '',
            companyId,
            terminalId: terminal.id,
            cashierUserId: operator.id,
            businessDate: new Date().toISOString().slice(0, 10),
            isTraining: terminal.is_training_mode === true,
          },
          operator: { id: operator.id, name: operator.name, roles: operator.roles },
          refundFiscalEventId: prompt.intent.refund_fiscal_event_id ?? '',
          refundIntentId: prompt.intent.id,
          reason: '',
        });
      } catch (error) {
        console.error(
          '[refundReconciliation] authorPayoutDisputeEvidence failed (non-fatal)',
          serializeErrorForLog(error),
        );
      }

      // Dispute does not block printing — the AVOIR is still the
      // customer's proof regardless of local reconciliation uncertainty.
      const printed = await attemptPrint(prompt.intent.id);
      if (printed) {
        await confirmRefundIntentPrinted(db, prompt.intent.id);
      }
      await refresh();
    } finally {
      setBusy(false);
    }
  }, [prompt, busy, refresh]);

  const handleReprint = useCallback(async () => {
    if (prompt === null || prompt.kind !== 'reprint' || busy) return;
    setBusy(true);
    try {
      const companyId = useAuthStore.getState().companyId;
      if (!companyId) return;
      const db = await getDatabase(companyId);
      const printed = await attemptPrint(prompt.intent.id);
      if (printed) {
        await confirmRefundIntentPrinted(db, prompt.intent.id);
      }
      await refresh();
    } finally {
      setBusy(false);
    }
  }, [prompt, busy, refresh]);

  const handleSkipReprint = useCallback(() => {
    // Deliberately a no-op besides closing this render: printed_at stays
    // null, so the SAME row re-prompts on the next app start (§4.5 —
    // "never silently dropped").
    setPrompt(null);
  }, []);

  if (prompt === null) return null;

  const amount = prompt.kind === 'confirm' && prompt.receipt !== null
    ? formatCurrency(prompt.receipt.total, prompt.receipt.currency)
    : null;

  return (
    <Modal
      isOpen
      onClose={() => {}}
      closable={false}
      title={
        prompt.kind === 'confirm'
          ? t('refundFlow.reconciliation.confirmTitle', { defaultValue: 'Confirm refund payout' })
          : t('refundFlow.reconciliation.reprintTitle', { defaultValue: 'Reprint refund receipt' })
      }
      size="sm"
    >
      <div className="space-y-4 p-4" data-testid="refund-reconciliation-modal">
        {prompt.kind === 'confirm' ? (
          <>
            <p className="text-sm text-ink-muted" data-testid="refund-reconciliation-confirm-body">
              {amount !== null
                ? t('refundFlow.reconciliation.confirmBody', {
                    defaultValue: 'Did {{amount}} in cash leave the drawer for this refund?',
                    amount,
                  })
                : t('refundFlow.reconciliation.confirmBodyNoAmount', {
                    defaultValue: 'Did the cash leave the drawer for this refund?',
                  })}
            </p>
            <div className="flex gap-2 pt-2">
              <button
                type="button"
                onClick={() => void handleDisputePayout()}
                disabled={busy}
                data-testid="refund-reconciliation-dispute"
                className="flex min-h-[48px] flex-1 items-center justify-center rounded-ctl border border-border-strong py-2 text-sm font-medium text-ink-muted hover:bg-surface-sunken disabled:opacity-50"
              >
                {t('refundFlow.reconciliation.notSure', { defaultValue: 'No / Not sure' })}
              </button>
              <button
                type="button"
                onClick={() => void handleConfirmPayout()}
                disabled={busy}
                data-testid="refund-reconciliation-confirm"
                className="flex min-h-[48px] flex-1 items-center justify-center rounded-ctl bg-action py-2 text-sm font-semibold text-ink-inverse hover:bg-action-hover disabled:cursor-not-allowed disabled:opacity-50"
              >
                {t('refundFlow.reconciliation.yes', { defaultValue: 'Yes' })}
              </button>
            </div>
          </>
        ) : (
          <>
            <p className="text-sm text-ink-muted" data-testid="refund-reconciliation-reprint-body">
              {t('refundFlow.reconciliation.reprintBody', {
                defaultValue: 'This refund was confirmed but the receipt was never printed — print it now?',
              })}
            </p>
            <div className="flex gap-2 pt-2">
              <button
                type="button"
                onClick={handleSkipReprint}
                disabled={busy}
                data-testid="refund-reconciliation-skip"
                className="flex min-h-[48px] flex-1 items-center justify-center rounded-ctl border border-border-strong py-2 text-sm font-medium text-ink-muted hover:bg-surface-sunken disabled:opacity-50"
              >
                {t('refundFlow.reconciliation.skip', { defaultValue: 'Skip' })}
              </button>
              <button
                type="button"
                onClick={() => void handleReprint()}
                disabled={busy}
                data-testid="refund-reconciliation-print"
                className="flex min-h-[48px] flex-1 items-center justify-center rounded-ctl bg-action py-2 text-sm font-semibold text-ink-inverse hover:bg-action-hover disabled:cursor-not-allowed disabled:opacity-50"
              >
                {t('refundFlow.reconciliation.print', { defaultValue: 'Print' })}
              </button>
            </div>
          </>
        )}
      </div>
    </Modal>
  );
}
