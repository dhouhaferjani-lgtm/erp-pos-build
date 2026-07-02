import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Search } from 'lucide-react';
import { useCurrency } from '@/lib/currency';
import { cn } from '@/lib/utils';

const REPORT_TICKETS = [
  { id: 'T-1042', time: '09:18', customer: 'Mariam Ben Ali', count: '3', payment: 'cash', total: '126.500' },
  { id: 'T-1043', time: '09:32', customer: 'Passage caisse', count: '1', payment: 'card', total: '48.900' },
  { id: 'T-1044', time: '10:05', customer: 'Ines Trabelsi', count: '5', payment: 'mixed', total: '214.000' },
  { id: 'T-1045', time: '10:41', customer: 'Selma Mansour', count: '2', payment: 'voucher', total: '72.300' },
] as const;

const BREAKDOWN = [
  { method: 'cash', amount: '3100.000', pct: '64%', widthClass: 'w-[64%]', fill: 'bg-accent' },
  { method: 'card', amount: '1200.500', pct: '25%', widthClass: 'w-[25%]', fill: 'bg-action' },
  { method: 'voucher', amount: '520.000', pct: '11%', widthClass: 'w-[11%]', fill: 'bg-ink-muted' },
] as const;

export function ReportsPage() {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();
  const [period, setPeriod] = useState('today');
  const [method, setMethod] = useState('all');
  const [search, setSearch] = useState('');
  const rows = useMemo(
    () =>
      REPORT_TICKETS.filter((ticket) => {
        const matchesMethod = method === 'all' || ticket.payment === method;
        const q = search.trim().toLowerCase();
        const matchesSearch =
          q === '' ||
          ticket.id.toLowerCase().includes(q) ||
          ticket.customer.toLowerCase().includes(q);
        return matchesMethod && matchesSearch;
      }),
    [method, search],
  );

  return (
    <div data-testid="reports-screen" className="flex h-full min-h-0 flex-col gap-3 bg-surface-canvas p-3">
      <div className="grid shrink-0 grid-cols-[repeat(3,minmax(0,1fr))_1.7fr] gap-3">
        <Kpi label={t('reports.dashboard.sales')} value={format('4820.500')} />
        <Kpi label={t('reports.dashboard.transactions')} value="37" />
        <Kpi label={t('reports.dashboard.averageBasket')} value={format('130.284')} />
        <section className="rounded-card border border-border-subtle bg-surface-raised px-4 py-3">
          <h2 className="mb-3 text-sm text-ink-muted">{t('reports.dashboard.paymentBreakdown')}</h2>
          <div className="flex flex-col gap-2">
            {BREAKDOWN.map((row) => (
              <div key={row.method} className="flex items-center gap-3">
                <span className="w-20 shrink-0 text-sm text-ink">{t(`reports.payment.${row.method}`)}</span>
                <div className="h-2 flex-1 overflow-hidden rounded-pill bg-surface-sunken">
                  <div className={cn('h-full rounded-pill', row.widthClass, row.fill)} />
                </div>
                <span className="w-28 shrink-0 text-right font-mono text-sm font-semibold tabular-nums text-ink-strong">
                  {format(row.amount)}
                </span>
                <span className="w-10 shrink-0 text-right text-xs text-ink-faint">{row.pct}</span>
              </div>
            ))}
          </div>
        </section>
      </div>

      <div className="flex shrink-0 items-center gap-3 rounded-card border border-border-subtle bg-surface-raised p-3">
        <Segment
          value={period}
          onChange={setPeriod}
          options={[
            ['today', t('reports.dashboard.today')],
            ['shift', t('reports.dashboard.currentShift')],
            ['week', t('reports.dashboard.week')],
          ]}
        />
        <div className="h-8 w-px bg-border-subtle" />
        <Segment
          value={method}
          onChange={setMethod}
          options={[
            ['all', t('reports.payment.all')],
            ['cash', t('reports.payment.cash')],
            ['card', t('reports.payment.card')],
            ['voucher', t('reports.payment.voucher')],
            ['mixed', t('reports.payment.mixed')],
          ]}
        />
        <label className="relative ml-auto w-[260px]">
          <span className="sr-only">{t('reports.dashboard.search')}</span>
          <Search className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-ink-faint" />
          <input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder={t('reports.dashboard.searchPlaceholder')}
            className="h-12 w-full rounded-ctl border border-border-subtle bg-surface-sunken pl-10 pr-3 text-sm text-ink outline-none focus:border-accent focus:ring-2 focus:ring-accent"
          />
        </label>
      </div>

      <section className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-card border border-border-subtle bg-surface-raised">
        <div className="grid shrink-0 grid-cols-[90px_150px_minmax(0,1fr)_80px_110px_120px] gap-3 border-b border-border-subtle px-5 py-3 text-xs font-bold tracking-wide text-ink-faint uppercase">
          <span>{t('reports.dashboard.ticket')}</span>
          <span>{t('reports.dashboard.date')}</span>
          <span>{t('reports.dashboard.customer')}</span>
          <span>{t('reports.dashboard.items')}</span>
          <span>{t('reports.dashboard.payment')}</span>
          <span className="text-right">{t('reports.dashboard.total')}</span>
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto">
          {rows.map((ticket) => (
            <div
              key={ticket.id}
              className="grid min-h-[52px] grid-cols-[90px_150px_minmax(0,1fr)_80px_110px_120px] items-center gap-3 border-b border-border-subtle px-5 text-sm"
            >
              <span className="font-mono font-semibold text-ink-strong">{ticket.id}</span>
              <span className="text-ink-muted">{ticket.time}</span>
              <span className="truncate font-semibold text-ink">{ticket.customer}</span>
              <span className="text-ink-muted">{ticket.count}</span>
              <span>
                <span className="rounded-pill bg-surface-sunken px-3 py-1 text-xs font-semibold text-ink">
                  {t(`reports.payment.${ticket.payment}`)}
                </span>
              </span>
              <span className="text-right font-mono font-semibold tabular-nums text-ink-strong">
                {format(ticket.total)}
              </span>
            </div>
          ))}
        </div>
      </section>
    </div>
  );
}

function Kpi({ label, value }: { label: string; value: string }) {
  return (
    <section className="rounded-card border border-border-subtle bg-surface-raised px-5 py-4">
      <div className="text-sm text-ink-muted">{label}</div>
      <div className="mt-2 font-mono text-2xl font-semibold tabular-nums text-ink-strong">{value}</div>
    </section>
  );
}

function Segment({
  value,
  onChange,
  options,
}: {
  value: string;
  onChange: (value: string) => void;
  options: [string, string][];
}) {
  return (
    <div className="flex rounded-ctl bg-surface-sunken p-1">
      {options.map(([id, label]) => (
        <button
          key={id}
          type="button"
          onClick={() => onChange(id)}
          className={cn(
            'h-10 rounded-ctl px-4 text-sm font-semibold',
            value === id ? 'bg-surface-raised text-ink-strong shadow-sm' : 'text-ink-muted',
          )}
        >
          {label}
        </button>
      ))}
    </div>
  );
}
