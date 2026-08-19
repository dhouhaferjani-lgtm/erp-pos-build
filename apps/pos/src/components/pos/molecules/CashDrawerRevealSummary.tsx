import { useTranslation } from 'react-i18next';

interface CashDrawerRevealSummaryProps {
  openingCash: string;
  cashSalesNet: string;
  drawerMovementsNet: string;
  expectedCash: string;
  countedCash: string;
  variance: {
    amount: string;
    direction: 'over' | 'under' | 'balanced';
  };
}

export function CashDrawerRevealSummary({
  openingCash,
  cashSalesNet,
  drawerMovementsNet,
  expectedCash,
  countedCash,
  variance,
}: CashDrawerRevealSummaryProps) {
  const { t } = useTranslation('pos');
  const varianceLabel =
    variance.direction === 'over'
      ? t('cash_count.summary.over', { defaultValue: 'Over' })
      : variance.direction === 'under'
        ? t('cash_count.summary.short', { defaultValue: 'Short' })
        : t('cash_count.no_difference', { defaultValue: 'No difference' });

  return (
    <div
      className="space-y-2 rounded-ctl border border-border-subtle bg-surface-raised p-3"
      data-testid="cash-count-reveal-summary"
    >
      <p className="text-sm text-ink-muted">
        {t('cash_count.expected_includes_float', {
          defaultValue: 'Expected includes the opening float.',
        })}
      </p>
      <dl className="space-y-1 text-sm">
        <div className="flex justify-between gap-4">
          <dt>{t('cash_count.summary.opening_float', { defaultValue: 'Opening float' })}</dt>
          <dd className="tabular-nums">{openingCash}</dd>
        </div>
        <div className="flex justify-between gap-4">
          <dt>
            {t('cash_count.summary.cash_sales_net', {
              defaultValue: 'Cash sales (net of change)',
            })}
          </dt>
          <dd className="tabular-nums">{cashSalesNet}</dd>
        </div>
        <div className="flex justify-between gap-4">
          <dt>
            {t('cash_count.summary.drawer_movements', {
              defaultValue: 'Paid in / paid out',
            })}
          </dt>
          <dd className="tabular-nums">{drawerMovementsNet}</dd>
        </div>
        <div className="flex justify-between gap-4 font-medium">
          <dt>
            {t('cash_count.summary.expected_in_drawer', {
              defaultValue: 'Expected in drawer',
            })}
          </dt>
          <dd className="tabular-nums">{expectedCash}</dd>
        </div>
        <div className="flex justify-between gap-4 font-medium">
          <dt>{t('cash_count.summary.counted', { defaultValue: 'Counted' })}</dt>
          <dd className="tabular-nums">{countedCash}</dd>
        </div>
        <div className="flex justify-between gap-4 font-medium">
          <dt>{varianceLabel}</dt>
          {variance.direction !== 'balanced' && (
            <dd className="tabular-nums">{variance.amount}</dd>
          )}
        </div>
      </dl>
    </div>
  );
}
