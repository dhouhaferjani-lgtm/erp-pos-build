import { useState, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { Modal } from './Modal';
import { CreditCard } from 'lucide-react';

interface CardPaymentModalProps {
  isOpen: boolean;
  onClose: () => void;
  total: number;
  onConfirm: (data: { lastFour?: string; reference?: string }) => void;
  isProcessing: boolean;
}

export function CardPaymentModal({
  isOpen,
  onClose,
  total,
  onConfirm,
  isProcessing,
}: CardPaymentModalProps) {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();
  const [lastFour, setLastFour] = useState('');
  const [reference, setReference] = useState('');

  const handleConfirm = useCallback(() => {
    onConfirm({
      lastFour: lastFour || undefined,
      reference: reference || undefined,
    });
  }, [onConfirm, lastFour, reference]);

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={t('payment.cardPayment')} size="md">
      <div className="space-y-6">
        {/* Amount */}
        <div className="rounded-xl bg-gray-50 p-4 text-center">
          <p className="mb-1 text-sm font-medium text-gray-500">
            {t('cashTendered.amountDue')}
          </p>
          <p className="text-3xl font-bold text-gray-900">{format(total)}</p>
        </div>

        {/* Card last 4 */}
        <div>
          <label className="mb-2 block text-sm font-medium text-gray-700">
            {t('payment.cardLastFour')}
          </label>
          <input
            type="text"
            maxLength={4}
            inputMode="numeric"
            pattern="[0-9]*"
            value={lastFour}
            onChange={(e) => setLastFour(e.target.value.replace(/\D/g, '').slice(0, 4))}
            placeholder="0000"
            className="w-full rounded-lg border border-gray-300 px-4 py-3 text-center text-lg tracking-widest focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none"
          />
        </div>

        {/* Transaction reference */}
        <div>
          <label className="mb-2 block text-sm font-medium text-gray-700">
            {t('payment.transactionRef')}
          </label>
          <input
            type="text"
            value={reference}
            onChange={(e) => setReference(e.target.value)}
            className="w-full rounded-lg border border-gray-300 px-4 py-3 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none"
          />
        </div>

        {/* Confirm */}
        <button
          onClick={handleConfirm}
          disabled={isProcessing}
          className="flex min-h-[56px] w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-6 py-4 text-lg font-semibold text-white transition-colors hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
        >
          <CreditCard className="h-5 w-5" />
          {isProcessing ? t('cashTendered.processing') : t('cashTendered.confirm')}
        </button>
      </div>
    </Modal>
  );
}
