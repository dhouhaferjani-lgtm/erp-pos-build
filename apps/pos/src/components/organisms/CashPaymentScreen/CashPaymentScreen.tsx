import { useState, useEffect, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { getDenominations } from '@/lib/denominations';
import { NumPad } from '@/components/molecules/NumPad';
import { tokens } from '@/lib/designTokens';
import { cn } from '@/lib/utils';
import { ArrowLeft, Banknote, CheckCircle2, AlertCircle } from 'lucide-react';
import { bccomp, bcsub, bcformat } from '@/lib/decimal';

/**
 * Pure helper — no React, safe to unit-test directly.
 * Computes the change-due string and valid-tender gate from decimal strings.
 * Uses bcmath (big.js) — no IEEE-754 float drift.
 */
export function computeCashTenderState(
  tenderedStr: string,
  totalStr: string,
  decimals: number,
): { changeDue: string; isValid: boolean } {
  if (!tenderedStr) {
    return { changeDue: bcformat('0', decimals), isValid: false };
  }
  const cmp = bccomp(tenderedStr, totalStr);
  const isValid = cmp >= 0;
  const changeDue = cmp > 0
    ? bcsub(tenderedStr, totalStr, decimals)
    : bcformat('0', decimals);
  return { changeDue, isValid };
}

export interface CashPaymentScreenProps {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: (tenderedAmount: string) => void;
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
  // True after Exact / denomination button has set tenderedStr programmatically.
  // The next digit press from the numpad overwrites the preset (industry-
  // standard POS behavior: cashier doesn't have to clear before retyping).
  // Backspace, Clear, or decimal keys clear this flag and behave normally.
  const [presetSet, setPresetSet] = useState(false);

  useEffect(() => {
    if (isOpen) {
      setTenderedStr('');
      setPresetSet(false);
    }
  }, [isOpen]);

  const totalStr = bcformat(String(total), decimals);
  const { changeDue, isValid } = computeCashTenderState(tenderedStr, totalStr, decimals);

  const handleExact = useCallback(() => {
    setTenderedStr(bcformat(String(total), decimals));
    setPresetSet(true);
  }, [total, decimals]);

  const handleDenomination = useCallback((amount: number) => {
    setTenderedStr(bcformat(String(amount), decimals));
    setPresetSet(true);
  }, [decimals]);

  const handleNumPadChange = useCallback((newVal: string) => {
    if (presetSet && newVal.length > tenderedStr.length) {
      // Digit appended after a preset — overwrite the preset with just the
      // newly-typed portion so the cashier doesn't see "114.745" when they
      // tap 5 on a preset of 114.74. The visual confusion (display still
      // showing the rounded preset) was a recurring blocker in the field.
      setTenderedStr(newVal.slice(tenderedStr.length));
    } else {
      // Backspace, clear, decimal, or normal typing — pass through.
      setTenderedStr(newVal);
    }
    setPresetSet(false);
  }, [presetSet, tenderedStr]);

  const handleConfirm = useCallback(() => {
    if (isValid && !isProcessing) onConfirm(tenderedStr);
  }, [isValid, isProcessing, onConfirm, tenderedStr]);

  const denominations = getDenominations(currency, total);

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex flex-col bg-surface-canvas text-ink">
      {/* Header */}
      <div className="flex items-center justify-between border-b border-border-subtle bg-surface-raised px-4 py-3">
        <button
          onClick={onClose}
          className="flex items-center gap-2 rounded-ctl px-3 py-2 text-sm text-ink-muted hover:bg-surface-sunken hover:text-ink"
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
        <div className="mx-4 mt-3 flex items-center gap-2 rounded-tile border border-danger-subtle bg-danger-surface p-3">
          <AlertCircle className="h-4 w-4 shrink-0 text-danger" />
          <p className="text-sm text-danger-strong">{error}</p>
        </div>
      )}

      {/* Main content */}
      <div className="flex flex-1 overflow-hidden">
        {/* Left: amounts — navy summary panel (mock §5.2; --pay-navy is constant
         * across themes). The change-due box turns green once the tender covers
         * the total. */}
        <div className="flex flex-[2] flex-col items-center justify-center bg-pay-navy p-6 text-pay-navy-fg">
          <div className="text-center">
            <p className="text-xs font-medium uppercase tracking-widest text-pay-navy-fg/70">
              {t('cashPayment.amountDue')}
            </p>
            <p className="mt-2 font-mono text-5xl font-bold tabular-nums text-pay-navy-fg">{format(total)}</p>
          </div>

          {discountAmount != null && discountAmount > 0 && (
            <div className="mt-4 text-center">
              <p className="text-xs font-medium uppercase tracking-widest text-pay-navy-fg/70">
                {t('cashPayment.discount')}
              </p>
              <p className="mt-1 font-mono text-lg font-bold tabular-nums text-pay-navy-fg/90">-{format(discountAmount)}</p>
            </div>
          )}

          <div className="mt-8 text-center">
            <p className="text-xs font-medium uppercase tracking-widest text-pay-navy-fg/70">
              {t('cashPayment.tendered')}
            </p>
            <p className="mt-2 font-mono text-4xl font-bold tabular-nums text-pay-navy-fg">
              {tenderedStr ? format(tenderedStr) : format(0)}
            </p>
          </div>

          {bccomp(changeDue, '0') > 0 && (
            <div className="mt-8 w-full max-w-xs rounded-card border-2 border-success-subtle bg-success-surface p-4 text-center">
              <p className="text-xs font-medium uppercase tracking-widest text-success-strong">
                {t('cashPayment.changeDue')}
              </p>
              <p className="mt-2 font-mono text-3xl font-bold tabular-nums text-success-strong">{format(changeDue)}</p>
            </div>
          )}
        </div>

        {/* Right: numpad */}
        <div className="flex flex-[3] flex-col bg-surface-canvas p-4">
          {/* Denomination buttons */}
          <div className="mb-3 flex gap-2">
            <button
              onClick={handleExact}
              className="min-h-[56px] flex-1 rounded-ctl bg-action px-3 text-sm font-semibold text-ink-inverse active:bg-action-strong"
            >
              {t('cashPayment.exact')}
            </button>
            {denominations.map((amount) => (
              <button
                key={amount}
                onClick={() => handleDenomination(amount)}
                className="min-h-[56px] flex-1 rounded-ctl border border-border-subtle bg-surface-raised px-3 text-sm font-medium tabular-nums text-ink active:bg-surface-sunken"
              >
                {amount} {currency}
              </button>
            ))}
          </div>

          {/* Numpad — fills the available height so there is no dead gap above
           * the confirm button (keys grow from their 56px floor to fill). */}
          <div className="min-h-0 flex-1">
            <NumPad value={tenderedStr} onChange={handleNumPadChange} className="h-full auto-rows-fr" />
          </div>

          {/* Disabled reason — tell the cashier what's blocking the confirm. */}
          {!isValid && !isProcessing && (
            <p className="mt-3 text-center text-sm text-ink-faint">
              {t('cashPayment.enterAmount')}
            </p>
          )}

          {/* Confirm button */}
          <button
            onClick={handleConfirm}
            disabled={!isValid || isProcessing}
            className={cn(
              tokens.button.confirm,
              'mt-2 w-full py-4 text-lg font-bold',
              (!isValid || isProcessing) && tokens.disabledReason,
            )}
          >
            <CheckCircle2 className="h-5 w-5" />
            {isProcessing ? t('cashPayment.processing') : t('cashPayment.complete')}
          </button>
        </div>
      </div>
    </div>
  );
}
