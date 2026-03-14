import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { Banknote, Wallet } from 'lucide-react';
import type { PaymentMethod } from '@/types/payment';

interface PaymentSummaryProps {
  subtotal: number;
  taxAmount: number;
  total: number;
  onPayCash: () => void;
  onAdvancedPayments?: () => void;
  paymentMethods?: PaymentMethod[];
  disabled?: boolean;
}

export function PaymentSummary({
  subtotal,
  taxAmount,
  total,
  onPayCash,
  onAdvancedPayments,
  paymentMethods = [],
  disabled = false,
}: PaymentSummaryProps) {
  const { t } = useTranslation(['common', 'pos']);
  const { format } = useCurrency();

  // Show advanced payments button when there are 2+ active payment methods
  const hasMultipleMethods = paymentMethods.filter((m) => m.is_active).length >= 2;

  return (
    <div className="space-y-3 border-t border-gray-200 pt-4">
      {/* Subtotal */}
      <div className="flex justify-between text-sm text-gray-600">
        <span>{t('common:subtotal')}</span>
        <span>{format(subtotal)}</span>
      </div>

      {/* Tax */}
      <div className="flex justify-between text-sm text-gray-600">
        <span>{t('common:tax')}</span>
        <span>{format(taxAmount)}</span>
      </div>

      {/* Total */}
      <div className="rounded-xl bg-gray-900 p-4 text-white">
        <div className="flex items-center justify-between">
          <span className="text-lg font-medium">{t('common:total')}</span>
          <span className="text-3xl font-bold">{format(total)}</span>
        </div>
      </div>

      {/* Payment buttons */}
      <div className="flex gap-3">
        <button
          onClick={onPayCash}
          disabled={disabled}
          className="flex flex-1 items-center justify-center gap-2 rounded-xl bg-green-600 px-6 py-4 text-lg font-semibold text-white transition-colors hover:bg-green-700 active:bg-green-800 disabled:cursor-not-allowed disabled:opacity-50"
        >
          <Banknote className="h-6 w-6" />
          {t('pos:payment.cashPayment')}
        </button>

        {hasMultipleMethods && onAdvancedPayments && (
          <button
            onClick={onAdvancedPayments}
            disabled={disabled}
            className="flex flex-1 items-center justify-center gap-2 rounded-xl bg-primary-600 px-6 py-4 text-lg font-semibold text-white transition-colors hover:bg-primary-700 active:bg-primary-800 disabled:cursor-not-allowed disabled:opacity-50"
          >
            <Wallet className="h-6 w-6" />
            {t('pos:payment.advancedPayments')}
          </button>
        )}
      </div>
    </div>
  );
}
