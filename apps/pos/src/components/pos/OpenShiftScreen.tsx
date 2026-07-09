import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { CurrencyNumpad } from '@/components/pos/atoms/CurrencyNumpad';
import { useCurrency } from '@/lib/currency';

interface OpenShiftScreenProps {
  terminalName: string | null;
  isLoading: boolean;
  error: string | null;
  onOpenShift: (openingCash: string) => void;
}

/**
 * Shift-open screen (extracted from HomePage, 2026-06-13).
 *
 * Touch-first: the opening float is entered on the always-visible
 * CurrencyNumpad (same pattern as the close-shift CashCountTable) — no
 * native number input, no OS keyboard. The default display is the
 * currency-scaled zero ('0.000' for TND, '0.00' for EUR), and the value
 * submitted upstream is always a canonical decimal string.
 */
export function OpenShiftScreen({ terminalName, isLoading, error, onOpenShift }: OpenShiftScreenProps) {
  const { t } = useTranslation();
  const { currency, decimals } = useCurrency();

  // '' = untouched; the display falls back to the currency-scaled zero.
  const [openingCash, setOpeningCash] = useState('');

  const zero = (0).toFixed(decimals);
  const displayValue = openingCash === '' ? zero : openingCash;

  const handleSubmit = () => {
    if (isLoading) return;
    const trimmed = openingCash.replace(/\.$/, '');
    onOpenShift(trimmed === '' ? zero : trimmed);
  };

  return (
    <div className="flex flex-1 items-center justify-center">
      <div className="w-full max-w-sm text-center">
        <h2 className="text-xl font-bold text-ink">{t('shift.openTitle')}</h2>
        <p className="mt-1 text-sm text-ink-muted">
          {t('shift.terminal')} {terminalName ?? t('shift.unknown')}
        </p>

        <div className="mt-6">
          <span className="block text-sm font-medium text-ink-muted">
            {t('shift.openingCash')}
          </span>
          <div
            data-testid="opening-cash-display"
            className="mt-1 block w-full rounded-ctl border border-border-strong px-3 py-2 text-center text-2xl font-semibold tabular-nums"
          >
            {displayValue}
          </div>
          <CurrencyNumpad
            className="mt-3"
            value={openingCash}
            onChange={setOpeningCash}
            currencyCode={currency}
            disabled={isLoading}
            aria-label={t('shift.openingCash')}
          />
        </div>

        {error && (
          <div className="mt-4 rounded-sm bg-danger-surface p-3 text-sm text-danger-strong">
            {error}
          </div>
        )}

        <button
          data-testid="open-shift-submit"
          onClick={handleSubmit}
          disabled={isLoading}
          className="flex min-h-[48px] items-center justify-center mt-4 w-full rounded-ctl bg-action px-4 py-2 text-sm font-medium text-ink-inverse hover:bg-action-hover disabled:opacity-50"
        >
          {isLoading ? t('shift.openingLoading') : t('shift.openingButton')}
        </button>
      </div>
    </div>
  );
}
