import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AlertTriangle } from 'lucide-react';
import { getErrorMessage } from '@/lib/api';
import { useCurrency } from '@/lib/currency';
import { MoneyInput } from '@/components/atoms/MoneyInput';
import { useTerminalStore } from '@/stores/terminalStore';
import { useCartStore } from '@/stores/cartStore';
import { useRefundFlowStore } from '@/stores/refundFlowStore';
import { useRefundDraftStore } from '@/stores/refundDraftStore';
import { usePaymentStore } from '@/stores/paymentStore';
import { useSyncStore } from '@/stores/syncStore';

interface CloseShiftModalProps {
  isOpen: boolean;
  onClose: () => void;
  shift: {
    shift_number: number;
    opening_cash: string;
  };
}

export function CloseShiftModal({ isOpen, onClose, shift }: CloseShiftModalProps) {
  const { t } = useTranslation(['pos', 'common']);
  const { currency } = useCurrency();
  const closeShift = useTerminalStore((s) => s.closeShift);
  const pendingReceiptCount = useSyncStore((s) => s.pendingReceiptCount);

  const [actualCash, setActualCash] = useState('');
  const [closing, setClosing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleCloseShift() {
    setError(null);
    setClosing(true);
    try {
      await closeShift(actualCash);
      useCartStore.getState().clearCart('shift_close');
      useRefundFlowStore.getState().clearAll();
      useRefundDraftStore.getState().clearDraftState();
      usePaymentStore.getState().clearVoucherTenders();
      // T0.2 (Codex F-2): shift close ends any in-flight cart submission.
      // Drop the pending idempotency key so the next shift's first sale
      // gets a fresh allocation.
      usePaymentStore.getState().discardPendingSubmission();
      onClose();
      setActualCash('');
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setClosing(false);
    }
  }

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
      <div className="w-full max-w-sm rounded-panel bg-surface-raised p-6 shadow-xl">
        <h3 className="text-lg font-bold text-ink">{t('pos:header.closeShift')}</h3>
        <p className="mt-1 text-sm text-ink-muted">
          {t('pos:header.shiftOpening', { number: shift.shift_number, amount: shift.opening_cash })}
        </p>

        {pendingReceiptCount > 0 && (
          <div className="mt-3 flex items-start gap-2 rounded-sm bg-warning-surface border border-warning-subtle p-3">
            <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0 text-warning-strong" />
            <div className="text-sm text-warning-strong">
              <p className="font-medium">{t('pos:header.pendingSyncWarning')}</p>
              <p className="mt-0.5">
                {t('pos:sync.pendingCount', { count: pendingReceiptCount })}
              </p>
            </div>
          </div>
        )}

        <div className="mt-4">
          <label htmlFor="actualCash" className="block text-sm font-medium text-ink-muted">
            {t('pos:header.actualCash')}
          </label>
          <MoneyInput
            id="actualCash"
            currency={currency}
            min="0"
            value={actualCash}
            onChange={setActualCash}
            className="mt-1 block w-full rounded-ctl border border-border-strong px-3 py-2 text-sm focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
            autoFocus
          />
        </div>

        {error && (
          <div className="mt-3 rounded-sm bg-danger-surface p-3 text-sm text-danger-strong">{error}</div>
        )}

        <div className="mt-4 flex gap-3">
          <button
            onClick={onClose}
            className="flex min-h-[48px] items-center justify-center flex-1 rounded-ctl border border-border-strong px-4 py-2 text-sm font-medium text-ink-muted hover:bg-surface-sunken"
          >
            {t('common:cancel')}
          </button>
          <button
            onClick={() => void handleCloseShift()}
            disabled={closing || !actualCash}
            className="flex min-h-[48px] items-center justify-center flex-1 rounded-ctl bg-danger px-4 py-2 text-sm font-medium text-ink-inverse hover:bg-danger-strong disabled:opacity-50"
          >
            {closing ? t('pos:header.closing') : t('pos:header.closeShift')}
          </button>
        </div>
      </div>
    </div>
  );
}
