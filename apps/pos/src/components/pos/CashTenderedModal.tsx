import { useState, useEffect, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { Modal } from './Modal';
import { MoneyInput } from '@/components/atoms/MoneyInput';
import { Banknote } from 'lucide-react';
import { bccomp, bcsub, bcformat } from '@/lib/decimal';

interface CashTenderedModalProps {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: (tenderedAmount: string) => void;
  total: number;
  isProcessing: boolean;
  error?: string | null;
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
  const { format, decimals, currency } = useCurrency();
  const [tenderedStr, setTenderedStr] = useState('');

  useEffect(() => {
    if (isOpen) {
      setTenderedStr(bcformat(String(total), decimals));
    }
  }, [isOpen, total, decimals]);

  const totalStr = bcformat(String(total), decimals);
  const cmp = tenderedStr ? bccomp(tenderedStr, totalStr) : -1;
  const isValid = cmp >= 0 && tenderedStr !== '';
  const changeDue = cmp > 0
    ? bcsub(tenderedStr, totalStr, decimals)
    : bcformat('0', decimals);

  const handleDenomination = useCallback((amount: number) => {
    setTenderedStr(bcformat(String(amount), decimals));
  }, [decimals]);

  const handleExact = useCallback(() => {
    setTenderedStr(bcformat(String(total), decimals));
  }, [total, decimals]);

  const handleConfirm = useCallback(() => {
    if (isValid) {
      onConfirm(tenderedStr);
    }
  }, [isValid, onConfirm, tenderedStr]);

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={t('cashTendered.title')} size="md">
      <div className="space-y-6">
        {/* Amount due */}
        <div className="rounded-card bg-surface-sunken p-4 text-center">
          <p className="mb-1 text-sm font-medium text-ink-muted">
            {t('cashTendered.amountDue')}
          </p>
          <p className="text-3xl font-bold text-ink">{format(total)}</p>
        </div>

        {/* Tendered input */}
        <div>
          <label className="mb-2 block text-sm font-medium text-ink-muted">
            {t('cashTendered.tenderedAmount')}
          </label>
          <MoneyInput
            currency={currency}
            min={0}
            value={tenderedStr}
            onChange={setTenderedStr}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && isValid && !isProcessing) {
                handleConfirm();
              }
            }}
            className="w-full rounded-ctl border border-border-strong px-4 py-3 text-right text-2xl font-semibold focus:border-accent focus:ring-2 focus:ring-accent focus:outline-none"
            autoFocus
          />
        </div>

        {/* Denomination buttons */}
        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            onClick={handleExact}
            className="min-h-[48px] min-w-[80px] flex-1 rounded-ctl border border-action-subtle bg-action-subtle px-3 py-2.5 text-sm font-medium text-action transition-colors hover:bg-action-subtle"
          >
            {t('cashTendered.exactAmount')}
          </button>
          {DENOMINATIONS.map((amount) => (
            <button
              key={amount}
              type="button"
              onClick={() => handleDenomination(amount)}
              className="min-h-[48px] min-w-[60px] flex-1 rounded-ctl border border-border-subtle bg-surface-sunken px-3 py-2.5 text-sm font-medium text-ink-muted transition-colors hover:bg-surface-raised"
            >
              {format(amount)}
            </button>
          ))}
        </div>

        {/* Change due */}
        {bccomp(changeDue, '0') > 0 && (
          <div className="rounded-card border border-success-subtle bg-success-surface p-4 text-center">
            <p className="mb-1 text-sm font-medium text-success-strong">
              {t('cashTendered.changeDue')}
            </p>
            <p className="text-2xl font-bold text-success-strong">{format(changeDue)}</p>
          </div>
        )}

        {/* Confirm */}
        <button
          onClick={handleConfirm}
          disabled={!isValid || isProcessing}
          className="flex min-h-[56px] w-full items-center justify-center gap-2 rounded-ctl bg-success px-6 py-4 text-lg font-semibold text-ink-inverse transition-colors hover:bg-success-hover disabled:cursor-not-allowed disabled:opacity-50"
        >
          <Banknote className="h-5 w-5" />
          {isProcessing ? t('cashTendered.processing') : t('cashTendered.confirm')}
        </button>
      </div>
    </Modal>
  );
}
