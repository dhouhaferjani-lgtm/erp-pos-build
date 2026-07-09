import { useState, useEffect, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';

interface QuantityNumpadProps {
  isOpen: boolean;
  onClose: () => void;
  currentQuantity: number;
  onConfirm: (qty: number) => void;
}

export function QuantityNumpad({
  isOpen,
  onClose,
  currentQuantity,
  onConfirm,
}: QuantityNumpadProps) {
  const { t } = useTranslation('pos');
  const [value, setValue] = useState('');

  useEffect(() => {
    if (isOpen) {
      setValue(String(currentQuantity));
    }
  }, [isOpen, currentQuantity]);

  const handleDigit = useCallback((digit: string) => {
    setValue((prev) => {
      if (prev === '0') return digit;
      return prev + digit;
    });
  }, []);

  const handleClear = useCallback(() => {
    setValue('');
  }, []);

  const handleConfirm = useCallback(() => {
    const qty = parseInt(value, 10);
    if (qty > 0) {
      onConfirm(qty);
      onClose();
    }
  }, [value, onConfirm, onClose]);

  if (!isOpen) return null;

  const numericValue = parseInt(value, 10) || 0;
  const isValid = numericValue > 0;

  const buttons = [
    '1', '2', '3',
    '4', '5', '6',
    '7', '8', '9',
    'C', '0', 'OK',
  ];

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
      <div
        className="absolute inset-0"
        onClick={onClose}
      />
      <div className="relative z-10 w-72 rounded-panel bg-surface-raised p-6 shadow-2xl">
        <h3 className="mb-4 text-center text-lg font-bold text-ink">
          {t('quantity.title')}
        </h3>

        {/* Display */}
        <div className="mb-4 rounded-card bg-surface-sunken px-4 py-3 text-center text-3xl font-bold text-ink">
          {value || '0'}
        </div>

        {/* Numpad grid */}
        <div className="grid grid-cols-3 gap-2">
          {buttons.map((btn) => {
            if (btn === 'C') {
              return (
                <button
                  key={btn}
                  onClick={handleClear}
                  className="flex h-16 w-16 items-center justify-center rounded-ctl bg-danger-surface text-lg font-semibold text-danger-strong transition-colors hover:bg-danger-surface active:bg-danger-surface mx-auto"
                >
                  {t('quantity.clear')}
                </button>
              );
            }
            if (btn === 'OK') {
              return (
                <button
                  key={btn}
                  onClick={handleConfirm}
                  disabled={!isValid}
                  className={cn(
                    'flex h-16 w-16 items-center justify-center rounded-ctl text-lg font-semibold transition-colors mx-auto',
                    isValid
                      ? 'bg-success text-ink-inverse hover:bg-success-hover active:bg-success-hover'
                      : 'bg-surface-sunken text-ink-faint cursor-not-allowed',
                  )}
                >
                  <Check className="h-6 w-6" />
                </button>
              );
            }
            return (
              <button
                key={btn}
                onClick={() => handleDigit(btn)}
                className="flex h-16 w-16 items-center justify-center rounded-ctl bg-surface-sunken text-xl font-semibold text-ink transition-colors hover:bg-surface-raised active:bg-surface-raised mx-auto"
              >
                {btn}
              </button>
            );
          })}
        </div>
      </div>
    </div>
  );
}
