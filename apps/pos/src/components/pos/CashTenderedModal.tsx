import { useState, useEffect, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { Modal } from './Modal';
import { Banknote } from 'lucide-react';

interface CashTenderedModalProps {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: (tenderedAmount: number) => void;
  total: number;
  isProcessing: boolean;
}

const DENOMINATIONS = [5, 10, 20, 50, 100];

export function CashTenderedModal({
  isOpen,
  onClose,
  onConfirm,
  total,
  isProcessing,
}: CashTenderedModalProps) {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();
  const [tenderedStr, setTenderedStr] = useState('');

  useEffect(() => {
    if (isOpen) {
      setTenderedStr(total.toFixed(2));
    }
  }, [isOpen, total]);

  const tenderedNum = parseFloat(tenderedStr) || 0;
  const changeDue = Math.max(0, tenderedNum - total);
  const isValid = tenderedNum >= total && tenderedStr !== '';

  const handleDenomination = useCallback((amount: number) => {
    setTenderedStr(amount.toFixed(2));
  }, []);

  const handleExact = useCallback(() => {
    setTenderedStr(total.toFixed(2));
  }, [total]);

  const handleConfirm = useCallback(() => {
    if (isValid) {
      onConfirm(tenderedNum);
    }
  }, [isValid, onConfirm, tenderedNum]);

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={t('cashTendered.title')} size="md">
      <div className="space-y-6">
        {/* Amount due */}
        <div className="rounded-xl bg-gray-50 p-4 text-center">
          <p className="mb-1 text-sm font-medium text-gray-500">
            {t('cashTendered.amountDue')}
          </p>
          <p className="text-3xl font-bold text-gray-900">{format(total)}</p>
        </div>

        {/* Tendered input */}
        <div>
          <label className="mb-2 block text-sm font-medium text-gray-700">
            {t('cashTendered.tenderedAmount')}
          </label>
          <input
            type="number"
            step="0.01"
            min={0}
            value={tenderedStr}
            onChange={(e) => setTenderedStr(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && isValid && !isProcessing) {
                handleConfirm();
              }
            }}
            className="w-full rounded-lg border border-gray-300 px-4 py-3 text-right text-2xl font-semibold focus:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:outline-none"
            autoFocus
          />
        </div>

        {/* Denomination buttons */}
        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            onClick={handleExact}
            className="min-h-[48px] min-w-[80px] flex-1 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2.5 text-sm font-medium text-blue-700 transition-colors hover:bg-blue-100"
          >
            {t('cashTendered.exactAmount')}
          </button>
          {DENOMINATIONS.map((amount) => (
            <button
              key={amount}
              type="button"
              onClick={() => handleDenomination(amount)}
              className="min-h-[48px] min-w-[60px] flex-1 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100"
            >
              {format(amount)}
            </button>
          ))}
        </div>

        {/* Change due */}
        {tenderedNum > total && (
          <div className="rounded-xl border border-green-200 bg-green-50 p-4 text-center">
            <p className="mb-1 text-sm font-medium text-green-600">
              {t('cashTendered.changeDue')}
            </p>
            <p className="text-2xl font-bold text-green-700">{format(changeDue)}</p>
          </div>
        )}

        {/* Confirm */}
        <button
          onClick={handleConfirm}
          disabled={!isValid || isProcessing}
          className="flex min-h-[56px] w-full items-center justify-center gap-2 rounded-xl bg-green-600 px-6 py-4 text-lg font-semibold text-white transition-colors hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50"
        >
          <Banknote className="h-5 w-5" />
          {isProcessing ? t('cashTendered.processing') : t('cashTendered.confirm')}
        </button>
      </div>
    </Modal>
  );
}
