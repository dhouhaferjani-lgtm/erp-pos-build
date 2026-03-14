import { useState, useEffect, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { useSettingsStore } from '@/stores/settingsStore';
import { Modal } from '@/components/pos/Modal';
import { NumPad } from '@/components/molecules/NumPad';
import { Banknote, AlertCircle } from 'lucide-react';

export interface CashTenderedModalProps {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: (tenderedAmount: number) => void;
  total: number;
  isProcessing: boolean;
  error?: string | null;
}

/** Common bill denominations — whole numbers only. */
const DENOMINATIONS = [5, 10, 20, 50];

export function CashTenderedModal({
  isOpen,
  onClose,
  onConfirm,
  total,
  isProcessing,
  error,
}: CashTenderedModalProps) {
  const { t } = useTranslation('pos');
  const { format, currency } = useCurrency();
  const touchMode = useSettingsStore((s) => s.touchMode);
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

  /** Format denomination as whole number with currency symbol (e.g. "5 DT"). */
  const formatDenom = (amount: number): string => {
    return `${amount} ${currency}`;
  };

  // Filter denominations to only show those >= total (useful shortcuts)
  const visibleDenoms = DENOMINATIONS.filter((d) => d >= total);

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={t('cashTendered.title')} size={touchMode ? 'lg' : 'md'}>
      <div className={touchMode ? 'space-y-3' : 'space-y-4'}>
        {/* Error display */}
        {error && (
          <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 p-3">
            <AlertCircle className="h-4 w-4 shrink-0 text-red-600" />
            <p className="text-sm font-medium text-red-700">{error}</p>
          </div>
        )}

        {/* Amount due + tendered input — side by side in touch mode for compactness */}
        {touchMode ? (
          <div className="flex items-center gap-3">
            <div className="flex-1 rounded-lg bg-gray-50 p-3 text-center">
              <p className="text-xs font-medium text-gray-500">
                {t('cashTendered.amountDue')}
              </p>
              <p className="text-xl font-bold text-gray-900">{format(total)}</p>
            </div>
            <div className="flex-1 rounded-lg bg-gray-50 p-3 text-center">
              <p className="text-xs font-medium text-gray-500">
                {t('cashTendered.tenderedAmount')}
              </p>
              <p className="text-xl font-bold text-gray-900">
                {tenderedStr || '0'}
              </p>
            </div>
          </div>
        ) : (
          <>
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
                inputMode="decimal"
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
          </>
        )}

        {/* Denomination quick buttons — single row */}
        <div className="flex gap-2">
          <button
            type="button"
            onClick={handleExact}
            className="min-h-[44px] flex-1 rounded-lg border border-blue-200 bg-blue-50 px-2 py-2 text-sm font-medium text-blue-700 transition-colors active:bg-blue-100"
          >
            {t('cashTendered.exactAmount')}
          </button>
          {visibleDenoms.map((amount) => (
            <button
              key={amount}
              type="button"
              onClick={() => handleDenomination(amount)}
              className="min-h-[44px] flex-1 rounded-lg border border-gray-200 bg-gray-50 px-2 py-2 text-sm font-medium text-gray-700 transition-colors active:bg-gray-100"
            >
              {formatDenom(amount)}
            </button>
          ))}
        </div>

        {/* On-screen numpad for touch mode */}
        {touchMode && (
          <NumPad
            value={tenderedStr}
            onChange={setTenderedStr}
          />
        )}

        {/* Change due */}
        {tenderedNum > total && (
          <div className="rounded-lg border border-green-200 bg-green-50 p-3 text-center">
            <p className="text-xs font-medium text-green-600">
              {t('cashTendered.changeDue')}
            </p>
            <p className="text-xl font-bold text-green-700">{format(changeDue)}</p>
          </div>
        )}

        {/* Confirm */}
        <button
          onClick={handleConfirm}
          disabled={!isValid || isProcessing}
          className="flex min-h-[52px] w-full items-center justify-center gap-2 rounded-xl bg-green-600 px-6 py-3 text-lg font-semibold text-white transition-colors hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50"
        >
          <Banknote className="h-5 w-5" />
          {isProcessing ? t('cashTendered.processing') : t('cashTendered.confirm')}
        </button>
      </div>
    </Modal>
  );
}
