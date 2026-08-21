import { useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { History, Lock, Wallet } from 'lucide-react';
import { useCurrency } from '@/lib/currency';
import { bcabs, bcadd, bcdiv, bcformat } from '@/lib/decimal';
import { getDatabase } from '@/lib/db';
import { buildEndOfDayPreview } from '@/lib/offline/endOfDayPreview';
import type { EndOfDayPreview } from '@/lib/offline/endOfDayPreview';
import { resolveCashDisclosure } from '@/lib/offline/cashDisclosurePolicy';
import type { CashDisclosure } from '@/lib/offline/cashDisclosurePolicy';
import { paymentSharePercent, sqliteUtcToDate } from '@/lib/offline/salesHistory';
import { useTerminalStore } from '@/stores/terminalStore';
import { useAuthStore } from '@/stores/authStore';
import { useShiftActionsStore } from '@/stores/shiftActionsStore';
import { cn } from '@/lib/utils';

/** Rendered in place of any money the blind-count policy conceals. */
const CONCEALED = '—';

const BAR_FILLS = ['bg-accent', 'bg-action', 'bg-ink-muted'] as const;

/**
 * Live reading of the open shift (`/shift`, manager-gated in AppShell).
 *
 * This screen deliberately does NOT close the shift. The real closure UX is
 * `EndOfDayPreviewModal`, mounted by `Header`: it owns the blind cash count, the
 * per-tender variance severity, the manager-PIN authorization above the hard
 * threshold, and the hash-chained Z. A second counting form here would be a
 * weaker parallel path to the same fiscal event, so the close button hands over
 * to that one flow through `shiftActionsStore`.
 *
 * Every figure comes from `buildEndOfDayPreview` — the same derivation, with the
 * same arguments, that the closure modal uses — so the two screens cannot show
 * different numbers for the same shift.
 */
export function ShiftClosurePage() {
  const { t } = useTranslation('pos');
  const { currency, decimals, format } = useCurrency();
  const navigate = useNavigate();

  const shift = useTerminalStore((s) => s.shift);
  const terminal = useTerminalStore((s) => s.terminal);
  const companyId = useAuthStore((s) => s.companyId);
  const requestEndOfDay = useShiftActionsStore((s) => s.requestEndOfDay);

  const [preview, setPreview] = useState<EndOfDayPreview | null>(null);
  // Fail closed: conceal until the policy is positively read as "not blind".
  const [disclosure, setDisclosure] = useState<CashDisclosure>('conceal');
  const [failed, setFailed] = useState(false);

  const shiftId = shift?.id ?? null;
  const openedAt = shift?.opened_at ?? null;
  const openingCash = shift?.opening_cash ?? null;
  const terminalId = terminal?.id ?? null;

  useEffect(() => {
    if (!shiftId || !openedAt || openingCash === null || !terminalId || !companyId) return;
    let cancelled = false;

    void (async () => {
      try {
        const db = await getDatabase(companyId);
        const [built, policy] = await Promise.all([
          buildEndOfDayPreview(db, terminalId, openedAt, openingCash, currency, shiftId),
          resolveCashDisclosure(db, companyId),
        ]);
        if (cancelled) return;
        setPreview(built);
        setDisclosure(policy);
        setFailed(false);
      } catch {
        if (cancelled) return;
        // Never fall back to a plausible-looking number — say the read failed.
        setPreview(null);
        setDisclosure('conceal');
        setFailed(true);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [shiftId, openedAt, openingCash, terminalId, companyId, currency]);

  const disclosed = disclosure === 'disclose';
  const money = (value: string) => (disclosed ? format(value) : CONCEALED);

  const averageBasket = useMemo(() => {
    if (!preview || preview.sales_count === 0) return bcformat('0', decimals);
    return bcdiv(preview.gross_sales, String(preview.sales_count), decimals);
  }, [preview, decimals]);

  const tenders = useMemo(() => {
    if (!preview) return [];
    const active = preview.payment_methods.filter((method) => method.transaction_count > 0);
    const totalAbs = active.reduce(
      (sum, method) => bcadd(sum, bcabs(method.total_amount, decimals), decimals),
      '0',
    );
    return active.map((method, index) => ({
      key: method.payment_method_code,
      label: method.payment_method_name,
      amount: method.total_amount,
      percent: paymentSharePercent(method.total_amount, totalAbs),
      fill: BAR_FILLS[index % BAR_FILLS.length],
    }));
  }, [preview, decimals]);

  if (!shift) {
    return (
      <div
        data-testid="shift-screen"
        className="flex h-full min-h-0 items-center justify-center bg-surface-canvas p-3"
      >
        <div className="max-w-md rounded-panel border border-border-subtle bg-surface-raised px-8 py-10 text-center shadow-sm">
          <span className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-card bg-surface-sunken text-ink-muted">
            <Wallet className="h-6 w-6" aria-hidden="true" />
          </span>
          <h1 className="font-display text-xl font-bold text-ink-strong">
            {t('shiftClosure.noOpenShift')}
          </h1>
          <p className="mt-2 text-sm text-ink-muted">{t('shiftClosure.noOpenShiftHint')}</p>
        </div>
      </div>
    );
  }

  const openedTime = sqliteUtcToDate(shift.opened_at).toLocaleTimeString([], {
    hour: '2-digit',
    minute: '2-digit',
  });

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
                {t('shiftClosure.serviceNumber', { number: shift.shift_number })}
              </h1>
              <p className="mt-1 text-sm text-ink-muted">
                {t('shiftClosure.openedMetaLive', {
                  time: openedTime,
                  terminal: terminal?.name ?? terminal?.code ?? '',
                  cashier: shift.user.name,
                })}
              </p>
            </div>
          </div>
          <span className="inline-flex min-h-10 items-center gap-2 rounded-pill border border-success-subtle bg-success-surface px-4 text-sm font-semibold text-success-strong">
            <span className="h-2 w-2 rounded-pill bg-success" />
            {t('shiftClosure.inProgress')}
          </span>
        </header>

        <div className="min-h-0 flex-1 overflow-y-auto px-6 py-5">
          {failed ? (
            <p className="rounded-card border border-danger-subtle bg-danger-surface px-4 py-3 text-sm text-danger-strong">
              {t('shiftClosure.loadError')}
            </p>
          ) : (
            <>
              <SectionTitle>{t('shiftClosure.xReportTitle')}</SectionTitle>
              <div className="grid grid-cols-3 gap-3">
                <Kpi
                  label={t('reports.dashboard.salesExclRefunds')}
                  value={preview ? money(preview.gross_sales) : CONCEALED}
                />
                <Kpi
                  label={t('reports.dashboard.transactions')}
                  value={preview ? String(preview.sales_count) : CONCEALED}
                />
                <Kpi
                  label={t('reports.dashboard.averageBasket')}
                  value={preview ? money(averageBasket) : CONCEALED}
                />
              </div>

              <SectionTitle>{t('shiftClosure.paymentTotals')}</SectionTitle>
              {tenders.length === 0 ? (
                <p className="text-sm text-ink-muted">{t('reports.noReceiptsYet')}</p>
              ) : (
                <div className="flex flex-col gap-3">
                  {tenders.map((row) => (
                    <div key={row.key} className="flex items-center gap-3">
                      <span className="w-20 shrink-0 truncate text-sm text-ink">{row.label}</span>
                      <div className="h-2.5 flex-1 overflow-hidden rounded-pill bg-surface-sunken">
                        <div
                          className={cn('h-full rounded-pill', row.fill)}
                          style={{ width: disclosed ? `${row.percent}%` : '0%' }}
                        />
                      </div>
                      <span className="w-28 shrink-0 text-right font-mono text-sm font-semibold tabular-nums text-ink-strong">
                        {money(row.amount)}
                      </span>
                      <span className="w-10 shrink-0 text-right text-xs text-ink-faint">
                        {disclosed ? `${row.percent}%` : CONCEALED}
                      </span>
                    </div>
                  ))}
                </div>
              )}
            </>
          )}
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
          <CashLine label={t('shiftClosure.openingFloat')} value={money(shift.opening_cash)} />
          <CashLine
            label={t('shiftClosure.cashSales')}
            value={preview ? money(preview.cash_sales_net) : CONCEALED}
          />
          {preview && disclosed && preview.drawer_movements_net !== '0' && (
            <CashLine
              label={t('shiftClosure.drawerMovements')}
              value={format(preview.drawer_movements_net)}
            />
          )}
          <CashLine
            label={t('shiftClosure.expectedCash')}
            value={preview ? money(preview.expected_cash) : CONCEALED}
            strong
          />

          {!disclosed && (
            <p className="mt-4 rounded-card border border-border-subtle bg-surface-sunken px-4 py-3 text-sm text-ink-muted">
              {t('shiftClosure.blindConcealedHint')}
            </p>
          )}

          <button
            type="button"
            onClick={() => navigate('/reports/z')}
            className="mt-5 inline-flex h-12 items-center gap-2 rounded-ctl border border-border-subtle bg-surface-raised px-5 text-sm font-semibold text-ink active:bg-surface-sunken"
          >
            <History className="h-5 w-5" aria-hidden="true" />
            {t('shiftClosure.zHistory')}
          </button>
        </div>

        <footer className="shrink-0 border-t border-border-subtle p-5">
          <button
            type="button"
            onClick={requestEndOfDay}
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
