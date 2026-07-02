import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Lock, Printer, Wallet } from 'lucide-react';
import { useCurrency } from '@/lib/currency';
import { bcsub, bccomp } from '@/lib/decimal';
import { cn } from '@/lib/utils';

const SHIFT_BREAKDOWN = [
  { method: 'cash', amount: '1840.000', pct: '56%', widthClass: 'w-[56%]', fill: 'bg-accent' },
  { method: 'card', amount: '1105.500', pct: '34%', widthClass: 'w-[34%]', fill: 'bg-action' },
  { method: 'voucher', amount: '328.500', pct: '10%', widthClass: 'w-[10%]', fill: 'bg-ink-muted' },
] as const;

export function ShiftClosurePage() {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();
  const openingCash = '200.000';
  const cashSales = '1840.000';
  const expectedCash = '2040.000';
  const [countedCash, setCountedCash] = useState('2040.000');
  const variance = useMemo(() => bcsub(countedCash || '0', expectedCash), [countedCash]);
  const varianceTone = bccomp(variance, '0') === 0
    ? 'text-success-strong'
    : 'text-danger-strong';

  return (
    <div data-testid="shift-screen" className="flex h-full min-h-0 gap-3 bg-surface-canvas p-3">
      <section className="flex min-w-0 flex-[1.5] flex-col overflow-hidden rounded-panel border border-border-subtle bg-surface-raised shadow-sm">
        <header className="flex shrink-0 items-center justify-between border-b border-border-subtle px-6 py-5">
          <div className="flex items-center gap-3">
            <span className="flex h-12 w-12 items-center justify-center rounded-card bg-accent-tint text-accent-strong">
              <Wallet className="h-6 w-6" aria-hidden="true" />
            </span>
            <div>
              <h1 className="font-display text-xl font-bold text-ink-strong">
                {t('shiftClosure.serviceNumber', { number: 42 })}
              </h1>
              <p className="mt-1 text-sm text-ink-muted">
                {t('shiftClosure.openedMeta')}
              </p>
            </div>
          </div>
          <span className="inline-flex min-h-10 items-center gap-2 rounded-pill border border-success-subtle bg-success-surface px-4 text-sm font-semibold text-success-strong">
            <span className="h-2 w-2 rounded-pill bg-success" />
            {t('shiftClosure.inProgress')}
          </span>
        </header>

        <div className="min-h-0 flex-1 overflow-y-auto px-6 py-5">
          <SectionTitle>{t('shiftClosure.xReportTitle')}</SectionTitle>
          <div className="grid grid-cols-3 gap-3">
            <Kpi label={t('reports.dashboard.sales')} value={format('3274.000')} />
            <Kpi label={t('reports.dashboard.transactions')} value="24" />
            <Kpi label={t('reports.dashboard.averageBasket')} value={format('136.417')} />
          </div>

          <SectionTitle>{t('shiftClosure.paymentTotals')}</SectionTitle>
          <div className="flex flex-col gap-3">
            {SHIFT_BREAKDOWN.map((row) => (
              <div key={row.method} className="flex items-center gap-3">
                <span className="w-20 shrink-0 text-sm text-ink">{t(`reports.payment.${row.method}`)}</span>
                <div className="h-2.5 flex-1 overflow-hidden rounded-pill bg-surface-sunken">
                  <div className={cn('h-full rounded-pill', row.widthClass, row.fill)} />
                </div>
                <span className="w-28 shrink-0 text-right font-mono text-sm font-semibold tabular-nums text-ink-strong">
                  {format(row.amount)}
                </span>
                <span className="w-10 shrink-0 text-right text-xs text-ink-faint">{row.pct}</span>
              </div>
            ))}
          </div>

          <button
            type="button"
            className="mt-6 inline-flex h-12 items-center gap-2 rounded-ctl border border-border-subtle bg-surface-raised px-5 text-sm font-semibold text-ink active:bg-surface-sunken"
          >
            <Printer className="h-5 w-5" aria-hidden="true" />
            {t('shiftClosure.printX')}
          </button>
        </div>
      </section>

      <section className="flex w-[430px] shrink-0 flex-col overflow-hidden rounded-panel border border-border-subtle bg-surface-raised shadow-sm">
        <header className="shrink-0 border-b border-border-subtle px-6 py-5">
          <h2 className="font-display text-xl font-bold text-ink-strong">
            {t('shiftClosure.zCloseTitle')}
          </h2>
          <p className="mt-1 text-sm text-ink-muted">{t('shiftClosure.zCloseDesc')}</p>
        </header>

        <div className="min-h-0 flex-1 overflow-y-auto px-6 py-5">
          <label htmlFor="counted-cash" className="mb-2 block text-sm font-semibold text-ink-muted">
            {t('shiftClosure.countedCash')}
          </label>
          <div className="relative mb-5">
            <input
              id="counted-cash"
              inputMode="decimal"
              value={countedCash}
              onChange={(event) => setCountedCash(event.target.value)}
              className="h-[58px] w-full rounded-card border border-border-subtle bg-surface-sunken px-4 pr-14 font-mono text-2xl font-semibold tabular-nums text-ink-strong outline-none focus:border-accent focus:ring-2 focus:ring-accent"
            />
            <span className="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-ink-faint">DT</span>
          </div>

          <CashLine label={t('shiftClosure.openingFloat')} value={format(openingCash)} />
          <CashLine label={t('shiftClosure.cashSales')} value={format(cashSales)} />
          <CashLine label={t('shiftClosure.expectedCash')} value={format(expectedCash)} strong />

          <div className="mt-4 flex min-h-[58px] items-center justify-between rounded-card border border-border-subtle bg-surface-sunken px-4">
            <span className={cn('text-sm font-semibold', varianceTone)}>
              {bccomp(variance, '0') === 0 ? t('shiftClosure.varianceOk') : t('shiftClosure.variance')}
            </span>
            <span className={cn('font-mono text-xl font-semibold tabular-nums', varianceTone)}>
              {format(variance)}
            </span>
          </div>
        </div>

        <footer className="shrink-0 border-t border-border-subtle p-5">
          <button
            type="button"
            className="flex h-[54px] w-full items-center justify-center gap-2 rounded-ctl bg-accent text-base font-bold text-ink-inverse shadow-sm active:bg-accent-strong"
          >
            <Lock className="h-5 w-5" aria-hidden="true" />
            {t('shiftClosure.closeShift')}
          </button>
        </footer>
      </section>
    </div>
  );
}

function SectionTitle({ children }: { children: ReactNode }) {
  return (
    <h2 className="mt-1 mb-3 text-xs font-bold tracking-wide text-ink-faint uppercase">
      {children}
    </h2>
  );
}

function Kpi({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-card border border-border-subtle bg-surface-sunken p-4">
      <div className="text-sm text-ink-muted">{label}</div>
      <div className="mt-1 font-mono text-xl font-semibold tabular-nums text-ink-strong">{value}</div>
    </div>
  );
}

function CashLine({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className="flex items-center justify-between border-b border-dashed border-border-subtle px-1 py-3 text-sm">
      <span className="text-ink-muted">{label}</span>
      <span className={cn('font-mono tabular-nums', strong ? 'font-semibold text-ink-strong' : 'text-ink')}>
        {value}
      </span>
    </div>
  );
}
