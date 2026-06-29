import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { CurrencyNumpad } from '../atoms/CurrencyNumpad';

type VarianceStatus = 'info' | 'warning' | 'critical' | 'balanced';

interface Tender {
  payment_method_id: string;
  payment_method_code: string;
  payment_method_name: string;
  is_physical: boolean;
  expected_amount: string;
  transaction_count: number;
  currency_code: string;
}

interface VarianceCell {
  amount: string;
  direction: 'over' | 'under' | 'balanced';
  status: VarianceStatus;
}

interface CashCountTableProps {
  tenders: Tender[];
  actuals: Record<string, string>;
  onActualChange: (methodId: string, value: string) => void;
  blindMode: boolean;
  committed: boolean;
  variances: Record<string, VarianceCell>;
}

function varianceColor(status: VarianceStatus | undefined): string {
  switch (status) {
    case 'balanced':
      return 'text-success-strong';
    case 'info':
      return 'text-ink-muted';
    case 'warning':
      return 'text-warning-strong';
    case 'critical':
      return 'text-danger-strong';
    default:
      return 'text-ink-muted';
  }
}

function formatSigned(amount: string, direction: 'over' | 'under' | 'balanced'): string {
  if (direction === 'balanced') return amount;
  if (direction === 'under' && !amount.startsWith('-')) return '-' + amount;
  return amount;
}

export function CashCountTable({
  tenders,
  actuals,
  onActualChange,
  blindMode,
  committed,
  variances,
}: CashCountTableProps) {
  const { t } = useTranslation('pos');
  const [activeMethodId, setActiveMethodId] = useState<string | null>(null);

  const showExpected = !blindMode || committed;
  const showVariance = !blindMode || committed;

  return (
    <div className="space-y-2" data-testid="cash-count-table">
      <table className="w-full text-sm">
        <thead>
          <tr className="text-left text-ink-muted">
            <th className="px-2 py-1">
              {t('cash_count.tender', { defaultValue: 'Tender' })}
            </th>
            <th className="px-2 py-1 text-right">
              {t('cash_count.transactions', { defaultValue: 'Txns' })}
            </th>
            {showExpected && (
              <th className="px-2 py-1 text-right">
                {t('cash_count.expected', { defaultValue: 'Expected' })}
              </th>
            )}
            <th className="px-2 py-1 text-right">
              {t('cash_count.actual', { defaultValue: 'Actual' })}
            </th>
            {showVariance && (
              <th className="px-2 py-1 text-right">
                {t('cash_count.variance', { defaultValue: 'Variance' })}
              </th>
            )}
          </tr>
        </thead>
        <tbody>
          {tenders.map((tender) => {
            const variance = variances[tender.payment_method_id];
            const actual = actuals[tender.payment_method_id] ?? '';

            return (
              <tr
                key={tender.payment_method_id}
                data-testid={`tender-row-${tender.payment_method_code}`}
              >
                <td className="px-2 py-2 font-medium">
                  {tender.payment_method_name}
                  {!tender.is_physical && (
                    <span
                      className="ml-2 text-xs text-ink-muted"
                      data-testid={`tender-electronic-${tender.payment_method_code}`}
                    >
                      {t('cash_count.electronic_marker', { defaultValue: '✓ elec.' })}
                    </span>
                  )}
                </td>
                <td className="px-2 py-2 text-right tabular-nums">
                  {tender.transaction_count}
                </td>
                {showExpected && (
                  <td className="px-2 py-2 text-right tabular-nums">
                    {tender.expected_amount}
                  </td>
                )}
                <td className="px-2 py-2 text-right tabular-nums">
                  {tender.is_physical ? (
                    <button
                      type="button"
                      disabled={blindMode && committed}
                      onClick={() =>
                        setActiveMethodId(
                          activeMethodId === tender.payment_method_id
                            ? null
                            : tender.payment_method_id,
                        )
                      }
                      data-testid={`tender-actual-input-${tender.payment_method_code}`}
                      className="rounded border border-border-strong px-2 py-1 disabled:cursor-not-allowed disabled:bg-surface-sunken"
                    >
                      {actual === '' ? '—' : actual}
                    </button>
                  ) : (
                    <span className="text-ink-muted">{tender.expected_amount}</span>
                  )}
                </td>
                {showVariance && (
                  <td
                    className={`px-2 py-2 text-right tabular-nums ${varianceColor(variance?.status)}`}
                    data-testid={`tender-variance-${tender.payment_method_code}`}
                  >
                    {variance ? formatSigned(variance.amount, variance.direction) : '—'}
                  </td>
                )}
              </tr>
            );
          })}
        </tbody>
      </table>

      {activeMethodId !== null && !(blindMode && committed) && (
        <div
          className="rounded-md border border-border-strong p-3"
          data-testid="cash-count-numpad-panel"
        >
          <p className="mb-2 text-sm font-medium">
            {t('cash_count.entering_actual_for', {
              defaultValue: 'Entering: {{name}}',
              name: tenders.find((ten) => ten.payment_method_id === activeMethodId)
                ?.payment_method_name,
            })}
          </p>
          <CurrencyNumpad
            value={actuals[activeMethodId] ?? ''}
            onChange={(next) => onActualChange(activeMethodId, next)}
            currencyCode={
              tenders.find((ten) => ten.payment_method_id === activeMethodId)
                ?.currency_code ?? 'EUR'
            }
            aria-label={t('cash_count.numpad_label', { defaultValue: 'Cash count keypad' })}
          />
        </div>
      )}
    </div>
  );
}
