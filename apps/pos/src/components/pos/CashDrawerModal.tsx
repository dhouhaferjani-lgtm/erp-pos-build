import { useState, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from './Modal';
import { cn } from '@/lib/utils';
import { depositCash, payoutCash } from '@/api/cashDrawerApi';
import { getErrorMessage } from '@/lib/api';

type TabType = 'deposit' | 'payout';

interface CashDrawerModalProps {
  isOpen: boolean;
  onClose: () => void;
  shiftId: string;
}

export function CashDrawerModal({
  isOpen,
  onClose,
  shiftId: _shiftId,
}: CashDrawerModalProps) {
  const { t } = useTranslation('pos');
  const [tab, setTab] = useState<TabType>('deposit');
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [isProcessing, setIsProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  const numericAmount = parseFloat(amount) || 0;
  const isValid = numericAmount > 0 && reason.trim().length > 0;

  const handleSubmit = useCallback(async () => {
    if (!isValid) return;

    setIsProcessing(true);
    setError(null);
    setSuccess(false);

    try {
      const data = { amount, reason: reason.trim() };
      if (tab === 'deposit') {
        await depositCash(data);
      } else {
        await payoutCash(data);
      }
      setSuccess(true);
      setAmount('');
      setReason('');
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setIsProcessing(false);
    }
  }, [isValid, amount, reason, tab]);

  const handleClose = useCallback(() => {
    setAmount('');
    setReason('');
    setError(null);
    setSuccess(false);
    onClose();
  }, [onClose]);

  return (
    <Modal isOpen={isOpen} onClose={handleClose} title={t('cashDrawer.title')} size="md">
      <div className="space-y-4">
        {/* Tabs */}
        <div className="flex rounded-lg bg-gray-100 p-1">
          <button
            onClick={() => { setTab('deposit'); setSuccess(false); }}
            className={cn(
              'flex-1 rounded-md px-3 py-2 text-sm font-medium transition-colors',
              tab === 'deposit'
                ? 'bg-white text-gray-900 shadow-sm'
                : 'text-gray-500 hover:text-gray-700',
            )}
          >
            {t('cashDrawer.deposit')}
          </button>
          <button
            onClick={() => { setTab('payout'); setSuccess(false); }}
            className={cn(
              'flex-1 rounded-md px-3 py-2 text-sm font-medium transition-colors',
              tab === 'payout'
                ? 'bg-white text-gray-900 shadow-sm'
                : 'text-gray-500 hover:text-gray-700',
            )}
          >
            {t('cashDrawer.payout')}
          </button>
        </div>

        {/* Error */}
        {error && (
          <div className="rounded-lg bg-red-50 p-3 text-sm text-red-700">{error}</div>
        )}

        {/* Success */}
        {success && (
          <div className="rounded-lg bg-green-50 p-3 text-sm text-green-700">
            {tab === 'deposit' ? t('cashDrawer.deposit') : t('cashDrawer.payout')} OK
          </div>
        )}

        {/* Amount */}
        <div>
          <label className="mb-1 block text-sm font-medium text-gray-700">
            {t('cashDrawer.amount')}
          </label>
          <input
            type="number"
            step="0.01"
            min="0"
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
            className="w-full rounded-lg border border-gray-300 px-4 py-3 text-right text-xl font-semibold focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none"
          />
        </div>

        {/* Reason */}
        <div>
          <label className="mb-1 block text-sm font-medium text-gray-700">
            {t('cashDrawer.reason')} <span className="text-red-500">*</span>
          </label>
          <input
            type="text"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none"
          />
        </div>

        {/* Submit */}
        <button
          onClick={() => void handleSubmit()}
          disabled={!isValid || isProcessing}
          className={cn(
            'flex min-h-[48px] w-full items-center justify-center rounded-xl px-6 py-3 text-base font-semibold text-white transition-colors disabled:cursor-not-allowed disabled:opacity-50',
            tab === 'deposit'
              ? 'bg-green-600 hover:bg-green-700'
              : 'bg-red-600 hover:bg-red-700',
          )}
        >
          {tab === 'deposit' ? t('cashDrawer.deposit') : t('cashDrawer.payout')}
        </button>
      </div>
    </Modal>
  );
}
