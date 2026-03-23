import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { Banknote, Wallet, X } from 'lucide-react';
import type { PaymentMethod } from '@/types/payment';

interface PaymentSummaryProps {
  subtotal: number;
  taxAmount: number;
  discountAmount: number;
  total: number;
  onPayCash: () => void;
  onAdvancedPayments?: () => void;
  onRemoveDiscount?: () => void;
  hasDiscount: boolean;
  paymentMethods?: PaymentMethod[];
  disabled?: boolean;
}

export function PaymentSummary({
  subtotal,
  taxAmount,
  discountAmount,
  total,
  onPayCash,
  onAdvancedPayments,
  onRemoveDiscount,
  hasDiscount,
  paymentMethods = [],
  disabled = false,
}: PaymentSummaryProps) {
  const { t } = useTranslation(['common', 'pos']);
  const { format } = useCurrency();

  // Show advanced payments button when there are 2+ active payment methods
  const hasMultipleMethods = paymentMethods.filter((m) => m.is_active).length >= 2;

  return (
    <div className="space-y-2 border-t border-gray-200 pt-2">
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

      {/* Discount */}
      {hasDiscount && (
        <div className="flex items-center justify-between text-sm">
          <div className="flex items-center gap-1">
            <span className="text-primary-600 font-medium">{t('common:discount')}</span>
            {onRemoveDiscount && (
              <button
                onClick={onRemoveDiscount}
                className="ml-1 rounded p-0.5 text-gray-400 hover:bg-gray-100 hover:text-red-500"
                title={t('pos:discount.remove')}
              >
                <X className="h-3.5 w-3.5" />
              </button>
            )}
          </div>
          <span className="font-medium text-primary-600">-{format(discountAmount)}</span>
        </div>
      )}

      {/* Total */}
      <div className="rounded-lg bg-gray-900 px-3 py-2 text-white">
        <div className="flex items-center justify-between">
          <span className="text-lg font-medium">{t('common:total')}</span>
          <span className="text-2xl font-bold">{format(total)}</span>
        </div>
      </div>

      {/* Payment buttons */}
      <div className="flex gap-2">
        <button
          onClick={onPayCash}
          disabled={disabled}
          className="flex flex-[3] items-center justify-center gap-2 rounded-lg bg-green-600 px-4 py-3 text-base font-semibold text-white transition-colors hover:bg-green-700 active:bg-green-800 disabled:cursor-not-allowed disabled:opacity-50"
        >
          <Banknote className="h-6 w-6" />
          {t('pos:payment.cashPayment')}
        </button>

        {hasMultipleMethods && onAdvancedPayments && (
          <button
            onClick={onAdvancedPayments}
            disabled={disabled}
            className="flex flex-1 items-center justify-center gap-2 rounded-lg bg-primary-600 px-4 py-3 text-base font-semibold text-white transition-colors hover:bg-primary-700 active:bg-primary-800 disabled:cursor-not-allowed disabled:opacity-50"
          >
            <Wallet className="h-6 w-6" />
            {t('pos:payment.advancedPayments')}
          </button>
        )}
      </div>
    </div>
  );
}
