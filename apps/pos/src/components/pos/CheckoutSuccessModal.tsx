import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { Modal } from './Modal';
import { CheckCircle } from 'lucide-react';

interface CheckoutSuccessModalProps {
  isOpen: boolean;
  onClose: () => void;
  receiptNumber: string;
  total: string;
  changeDue: number;
}

export function CheckoutSuccessModal({
  isOpen,
  onClose,
  receiptNumber,
  total,
  changeDue,
}: CheckoutSuccessModalProps) {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();
  const isOnline = useConnectivityStore((s) => s.isOnline);

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={t('payment.success')} size="sm">
      <div className="space-y-6 text-center">
        {/* Success icon */}
        <div className="flex justify-center">
          <div className="flex h-20 w-20 items-center justify-center rounded-full bg-green-100">
            <CheckCircle className="h-12 w-12 text-green-600" />
          </div>
        </div>

        {/* Offline banner */}
        {!isOnline && (
          <div className="rounded-md bg-amber-50 p-3 text-sm text-amber-700">
            {t('sync.receiptQueued')}
          </div>
        )}

        {/* Receipt number */}
        <div>
          <p className="text-sm text-gray-500">{t('payment.transactionComplete')}</p>
          <p className="mt-1 text-2xl font-bold text-gray-900">
            {t('payment.receiptNumber', { number: receiptNumber })}
          </p>
        </div>

        {/* Total */}
        <div className="rounded-xl bg-gray-50 p-4">
          <p className="text-sm text-gray-500">{t('common:total')}</p>
          <p className="text-xl font-bold text-gray-900">{format(total)}</p>
        </div>

        {/* Change due */}
        {changeDue > 0 && (
          <div className="rounded-xl border border-green-200 bg-green-50 p-4">
            <p className="text-sm font-medium text-green-600">
              {t('cashTendered.changeDue')}
            </p>
            <p className="text-2xl font-bold text-green-700">{format(changeDue)}</p>
          </div>
        )}

        {/* New sale button */}
        <button
          onClick={onClose}
          className="w-full rounded-xl bg-blue-600 px-6 py-4 text-lg font-semibold text-white transition-colors hover:bg-blue-700"
        >
          {t('payment.newTransaction')}
        </button>
      </div>
    </Modal>
  );
}
