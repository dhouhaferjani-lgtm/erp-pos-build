import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Search } from 'lucide-react';
import { useCurrency } from '@/lib/currency';
import { bcabs, bcadd, bcdiv, bcformat } from '@/lib/decimal';
import { getDatabase } from '@/lib/db';
import { buildEndOfDayPreview } from '@/lib/offline/endOfDayPreview';
import type { EndOfDayPreview } from '@/lib/offline/endOfDayPreview';
import {
  MIXED_METHOD,
  filterTickets,
  loadSalesHistoryTickets,
  paymentSharePercent,
  periodStartIso,
  sqliteUtcToDate,
  summarizeRefunds,
} from '@/lib/offline/salesHistory';
import type { SalesHistoryTicket, SalesPeriod } from '@/lib/offline/salesHistory';
import { useTerminalStore } from '@/stores/terminalStore';
import { useAuthStore } from '@/stores/authStore';
import { cn } from '@/lib/utils';

const BAR_FILLS = ['bg-accent', 'bg-action', 'bg-ink-muted'] as const;

/**
 * Manager sales report (`/reports`) over the device's own receipt store.
 *
 * The money AGGREGATES are read from `buildEndOfDayPreview` — the canonical
 * derivation used by the closure flow — with the selected period's start standing
 * in for the shift opening and a zero float (nothing on this screen is a drawer
 * expectation). That keeps the change-netted CASH figure, the refund signing and
 * the sale-only totals identical to the Z rather than re-derived here.
 *
 * Owner ruling O-28: the headline is the SALE-ONLY figure and is labelled as
 * excluding refunds; refunds are surfaced as their own counter-figure instead of
 * being netted silently into it.
 */
export function ReportsPage() {
  const { t } = useTranslation('pos');
  const { currency, decimals, format } = useCurrency();

  const shift = useTerminalStore((s) => s.shift);
  const terminal = useTerminalStore((s) => s.terminal);
  const companyId = useAuthStore((s) => s.companyId);

  const [period, setPeriod] = useState<SalesPeriod>('today');
  const [method, setMethod] = useState('all');
  const [search, setSearch] = useState('');

  const [preview, setPreview] = useState<EndOfDayPreview | null>(null);
  const [tickets, setTickets] = useState<SalesHistoryTicket[]>([]);
  const [failed, setFailed] = useState(false);
  const [loaded, setLoaded] = useState(false);

  const terminalId = terminal?.id ?? null;
  const shiftOpenedAt = shift?.opened_at ?? null;

  // If the shift closes while its period is selected, fall back to today rather
  // than freezing on the last window's numbers.
  const effectivePeriod: SalesPeriod = period === 'shift' && !shiftOpenedAt ? 'today' : period;

  const since = useMemo(
    () => periodStartIso(effectivePeriod, new Date(), shiftOpenedAt),
    [effectivePeriod, shiftOpenedAt],
  );

  useEffect(() => {
    if (!terminalId || !companyId || since === null) return;
    let cancelled = false;

    void (async () => {
      try {
        const db = await getDatabase(companyId);
        const [built, rows] = await Promise.all([
          // No shift id and a zero float: this is a sales report, not a drawer
          // reconciliation — `expected_cash` is deliberately not consumed.
          buildEndOfDayPreview(db, terminalId, since, '0', currency),
          loadSalesHistoryTickets(db, terminalId, since),
        ]);
        if (cancelled) return;
        setPreview(built);
        setTickets(rows);
        setFailed(false);
        setLoaded(true);
      } catch {
        if (cancelled) return;
        setPreview(null);
        setTickets([]);
        setFailed(true);
        setLoaded(true);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [terminalId, companyId, since, currency]);

  const refunds = useMemo(() => summarizeRefunds(tickets, decimals), [tickets, decimals]);

  const averageBasket = useMemo(() => {
    if (!preview || preview.sales_count === 0) return bcformat('0', decimals);
    return bcdiv(preview.gross_sales, String(preview.sales_count), decimals);
  }, [preview, decimals]);

  const tenders = useMemo(() => {
    if (!preview) return [];
    const active = preview.payment_methods.filter((m) => m.transaction_count > 0);
    const totalAbs = active.reduce(
      (sum, m) => bcadd(sum, bcabs(m.total_amount, decimals), decimals),
      '0',
    );
    return active.map((m, index) => ({
      key: m.payment_method_code,
      label: m.payment_method_name,
      amount: m.total_amount,
      percent: paymentSharePercent(m.total_amount, totalAbs),
      fill: BAR_FILLS[index % BAR_FILLS.length],
    }));
  }, [preview, decimals]);

  /** Tender filter options come from the tenders actually present in the period. */
  const methodOptions = useMemo(() => {
    const options: [string, string][] = [['all', t('reports.payment.all')]];
    const seen = new Set<string>();
    for (const ticket of tickets) {
      if (ticket.methodCodes.length !== 1) continue;
      const code = ticket.methodCodes[0];
      if (code === undefined || seen.has(code)) continue;
      seen.add(code);
      options.push([code, ticket.methodLabel ?? code]);
    }
    if (tickets.some((ticket) => ticket.methodCodes.length > 1)) {
      options.push([MIXED_METHOD, t('reports.payment.mixed')]);
    }
    return options;
  }, [tickets, t]);

  const rows = useMemo(() => filterTickets(tickets, method, search), [tickets, method, search]);

  const periodOptions: [SalesPeriod, string][] = [
    ['today', t('reports.dashboard.today')],
    ...(shiftOpenedAt
      ? ([['shift', t('reports.dashboard.currentShift')]] as [SalesPeriod, string][])
      : []),
    ['week', t('reports.dashboard.week')],
  ];

  return (
    <div data-testid="reports-screen" className="flex h-full min-h-0 flex-col gap-3 bg-surface-canvas p-3">
      <div className="grid shrink-0 grid-cols-[repeat(3,minmax(0,1fr))_1.7fr] gap-3">
        <Kpi
          label={t('reports.dashboard.salesExclRefunds')}
          value={format(preview?.gross_sales ?? bcformat('0', decimals))}
          footer={
            refunds.count > 0 ? (
              <span className="mt-1 flex items-center gap-2 text-xs text-ink-muted">
                <span>{t('reports.dashboard.refunds')}</span>
                <span className="font-mono tabular-nums">{refunds.count}</span>
                <span className="font-mono tabular-nums text-danger-strong">
                  {`−${format(refunds.amount)}`}
                </span>
              </span>
            ) : null
          }
        />
        <Kpi label={t('reports.dashboard.transactions')} value={String(preview?.sales_count ?? 0)} />
        <Kpi label={t('reports.dashboard.averageBasket')} value={format(averageBasket)} />
        <section className="rounded-card border border-border-subtle bg-surface-raised px-4 py-3">
          <h2 className="mb-3 text-sm text-ink-muted">{t('reports.dashboard.paymentBreakdown')}</h2>
          <div className="flex flex-col gap-2">
            {tenders.map((row) => (
              <div key={row.key} className="flex items-center gap-3">
                <span className="w-20 shrink-0 truncate text-sm text-ink">{row.label}</span>
                <div className="h-2 flex-1 overflow-hidden rounded-pill bg-surface-sunken">
                  <div
                    className={cn('h-full rounded-pill', row.fill)}
                    style={{ width: `${row.percent}%` }}
                  />
                </div>
                <span className="w-28 shrink-0 text-right font-mono text-sm font-semibold tabular-nums text-ink-strong">
                  {format(row.amount)}
                </span>
                <span className="w-10 shrink-0 text-right text-xs text-ink-faint">
                  {`${row.percent}%`}
                </span>
              </div>
            ))}
          </div>
        </section>
      </div>

      <div className="flex shrink-0 items-center gap-3 rounded-card border border-border-subtle bg-surface-raised p-3">
        <Segment
          value={effectivePeriod}
          onChange={(v) => setPeriod(v as SalesPeriod)}
          options={periodOptions}
        />
        <div className="h-8 w-px bg-border-subtle" />
        <Segment value={method} onChange={setMethod} options={methodOptions} />
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
        <div className="grid shrink-0 grid-cols-[110px_90px_minmax(0,1fr)_70px_130px_130px] gap-3 border-b border-border-subtle px-5 py-3 text-xs font-bold tracking-wide text-ink-faint uppercase">
          <span>{t('reports.dashboard.ticket')}</span>
          <span>{t('reports.time')}</span>
          <span>{t('reports.dashboard.cashier')}</span>
          <span>{t('reports.dashboard.items')}</span>
          <span>{t('reports.dashboard.payment')}</span>
          <span className="text-right">{t('reports.dashboard.total')}</span>
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto">
          {failed && (
            <p className="px-5 py-4 text-sm text-danger-strong">{t('reports.dashboard.error')}</p>
          )}
          {!failed && loaded && rows.length === 0 && (
            <p className="px-5 py-4 text-sm text-ink-muted">{t('reports.dashboard.empty')}</p>
          )}
          {rows.map((ticket) => (
            <div
              key={ticket.id}
              className="grid min-h-[52px] grid-cols-[110px_90px_minmax(0,1fr)_70px_130px_130px] items-center gap-3 border-b border-border-subtle px-5 text-sm"
            >
              <span className="truncate font-mono font-semibold text-ink-strong">
                {ticket.receiptNumber}
              </span>
              <span className="text-ink-muted">
                {sqliteUtcToDate(ticket.createdAt).toLocaleTimeString([], {
                  hour: '2-digit',
                  minute: '2-digit',
                })}
              </span>
              <span className="truncate font-semibold text-ink">{ticket.operatorName}</span>
              <span className="text-ink-muted">{ticket.itemCount}</span>
              <span className="flex min-w-0 items-center gap-2">
                <span className="truncate rounded-pill bg-surface-sunken px-3 py-1 text-xs font-semibold text-ink">
                  {ticket.methodLabel ?? t('reports.payment.mixed')}
                </span>
                {ticket.isRefund && (
                  <span className="shrink-0 rounded-pill bg-warning-surface px-2 py-1 text-xs font-semibold text-warning-strong">
                    {t('reports.typeReturn')}
                  </span>
                )}
              </span>
              {/* Refund rows are already negative on the row — never prefix a
                  second minus sign (that renders "−-12.500"). */}
              <span
                className={cn(
                  'text-right font-mono font-semibold tabular-nums',
                  ticket.isRefund ? 'text-danger-strong' : 'text-ink-strong',
                )}
              >
                {format(ticket.total)}
              </span>
            </div>
          ))}
        </div>
      </section>
    </div>
  );
}

function Kpi({ label, value, footer }: { label: string; value: string; footer?: React.ReactNode }) {
  return (
    <section className="rounded-card border border-border-subtle bg-surface-raised px-5 py-4">
      <div className="text-sm text-ink-muted">{label}</div>
      <div className="mt-2 font-mono text-2xl font-semibold tabular-nums text-ink-strong">{value}</div>
      {footer}
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
