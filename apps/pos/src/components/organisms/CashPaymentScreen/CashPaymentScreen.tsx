import { useState, useEffect, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { getDenominations } from '@/lib/denominations';
import { NumPad } from '@/components/molecules/NumPad';
import { ArrowLeft, Banknote, CheckCircle2, AlertCircle } from 'lucide-react';

export interface CashPaymentScreenProps {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: (tenderedAmount: number) => void;
  total: number;
  discountAmount?: number;
  isProcessing: boolean;
  error?: string | null;
}

export function CashPaymentScreen({
  isOpen,
  onClose,
  onConfirm,
  total,
  discountAmount,
  isProcessing,
  error,
}: CashPaymentScreenProps) {
  const { t } = useTranslation('pos');
  const { format, currency, decimals } = useCurrency();
  const [tenderedStr, setTenderedStr] = useState('');

  useEffect(() => {
    if (isOpen) setTenderedStr('');
  }, [isOpen]);

  const tenderedNum = parseFloat(tenderedStr) || 0;
  const changeDue = Math.max(0, tenderedNum - total);
  const isValid = tenderedNum >= total && tenderedStr !== '';

  const handleExact = useCallback(() => {
    setTenderedStr(total.toFixed(decimals));
  }, [total, decimals]);

  const handleDenomination = useCallback((amount: number) => {
    setTenderedStr(amount.toFixed(decimals));
  }, [decimals]);

  const handleConfirm = useCallback(() => {
    if (isValid && !isProcessing) onConfirm(tenderedNum);
  }, [isValid, isProcessing, onConfirm, tenderedNum]);

  const denominations = getDenominations(currency, total);

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex flex-col bg-gray-50 text-gray-900">
      {/* Header */}
      <div className="flex items-center justify-between border-b border-gray-200 bg-white px-4 py-3">
        <button
          onClick={onClose}
          className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-gray-500 hover:bg-gray-100 hover:text-gray-900"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('cashPayment.back')}
        </button>
        <div className="flex items-center gap-2 text-lg font-bold">
          <Banknote className="h-5 w-5" />
          {t('cashPayment.title')}
        </div>
        <div className="w-20" />
      </div>

      {/* Error */}
      {error && (
        <div className="mx-4 mt-3 flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 p-3">
          <AlertCircle className="h-4 w-4 shrink-0 text-red-500" />
          <p className="text-sm text-red-700">{error}</p>
        </div>
      )}

      {/* Main content */}
      <div className="flex flex-1 overflow-hidden">
        {/* Left: amounts */}
        <div className="flex flex-[2] flex-col items-center justify-center border-r border-gray-200 bg-white p-6">
          <div className="text-center">
            <p className="text-xs font-medium uppercase tracking-widest text-gray-500">
              {t('cashPayment.amountDue')}
            </p>
            <p className="mt-2 text-4xl font-bold">{format(total)}</p>
          </div>

          {discountAmount != null && discountAmount > 0 && (
            <div className="mt-4 text-center">
              <p className="text-xs font-medium uppercase tracking-widest text-primary-600">
                {t('cashPayment.discount')}
              </p>
              <p className="mt-1 text-lg font-bold text-primary-600">-{format(discountAmount)}</p>
            </div>
          )}

          <div className="mt-8 text-center">
            <p className="text-xs font-medium uppercase tracking-widest text-gray-500">
              {t('cashPayment.tendered')}
            </p>
            <p className="mt-2 text-3xl font-bold text-primary-600">
              {tenderedStr ? format(tenderedNum) : format(0)}
            </p>
          </div>

          <div className="mt-8 w-full max-w-xs rounded-xl border-2 border-green-200 bg-green-50 p-4 text-center">
            <p className="text-xs font-medium uppercase tracking-widest text-green-700">
              {t('cashPayment.changeDue')}
            </p>
            <p className="mt-2 text-3xl font-bold text-green-700">{format(changeDue)}</p>
          </div>
        </div>

        {/* Right: numpad */}
        <div className="flex flex-[3] flex-col bg-gray-50 p-4">
          {/* Denomination buttons */}
          <div className="mb-3 flex gap-2">
            <button
              onClick={handleExact}
              className="flex-1 rounded-lg bg-primary-600 px-3 py-3 text-sm font-semibold text-white active:bg-primary-700"
            >
              {t('cashPayment.exact')}
            </button>
            {denominations.map((amount) => (
              <button
                key={amount}
                onClick={() => handleDenomination(amount)}
                className="flex-1 rounded-lg border border-gray-200 bg-white px-3 py-3 text-sm font-medium text-gray-900 active:bg-gray-100"
              >
                {amount} {currency}
              </button>
            ))}
          </div>

          {/* Numpad */}
          <div className="flex-1">
            <NumPad value={tenderedStr} onChange={setTenderedStr} />
          </div>

          {/* Confirm button */}
          <button
            onClick={handleConfirm}
            disabled={!isValid || isProcessing}
            className="mt-3 flex w-full items-center justify-center gap-2 rounded-xl bg-green-600 py-4 text-lg font-bold text-white transition-colors hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50"
          >
            <CheckCircle2 className="h-5 w-5" />
            {isProcessing ? t('cashPayment.processing') : t('cashPayment.complete')}
          </button>
        </div>
      </div>
    </div>
  );
}
