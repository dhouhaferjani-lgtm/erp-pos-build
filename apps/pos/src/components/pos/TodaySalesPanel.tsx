import { useState, useEffect, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { RotateCcw, Printer, Eye } from 'lucide-react';
import { PageHeader } from '@/components/PageHeader';
import { Button } from '@/components/ui';
import { toast } from 'sonner';
import { fetchShiftReceipts, type ShiftReceipt } from '@/api/reportApi';
import { useTerminalStore } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useAuthStore } from '@/stores/authStore';
import { useCashDisclosure } from '@/hooks/useCashDisclosure';
import { hasManagerAccess } from '@/lib/auth/roles';
import { useCurrency } from '@/lib/currency';
import { bcsum, bcsub, bcdiv, bccomp, bcabs, bcformat } from '@/lib/decimal';
import { formatQuantity } from '@/lib/quantity';
import { printReceiptAsPdf } from '@/lib/printing';
import { SaleDetailModal } from '@/components/pos/SaleDetailModal';

function getLineName(line: ShiftReceipt['lines'][number]): string {
  return line.product?.name ?? line.product_name ?? '—';
}

function getLineSummary(line: ShiftReceipt['lines'][number]): string {
  return `${formatQuantity(String(line.quantity), line.quantity_decimals)}× ${getLineName(line)}`;
}

/**
 * The counted population for the money tiles. Both filters must be applied HERE,
 * on BOTH receipt types, because neither source guarantees them:
 * `ShiftController::receipts` filters on neither `is_voided` nor `is_training`
 * (unlike every server-side fiscal aggregate, which carries
 * `training_flag = false`), and the offline path deliberately returns every row
 * so the list below can still show it.
 *
 * `is_training` is OPTIONAL on the wire, so the comparison direction is a
 * deliberate fail-OPEN choice. A device pointed at an older API build receives no
 * such field; `!== true` counts those receipts, where a fail-closed `=== false`
 * would drop every receipt from that server and report zero sales. Under-reporting
 * to zero is the worse failure: a training receipt is an occasional deliberate act,
 * an old API build is a whole deployment.
 */
function isCounted(receipt: ShiftReceipt): boolean {
  return !receipt.is_voided && receipt.is_training !== true;
}

function getPaymentLabel(receipt: ShiftReceipt): string {
  if (!receipt.payments || receipt.payments.length === 0) return CONCEALED;
  return receipt.payments.map((p) => p.payment_type).join(' + ');
}

/** Concealed figures read as an em dash, matching `/shift`, `/reports` and the X report. */
const CONCEALED = '—';

function getReceiptStatus(receipt: ShiftReceipt, t: (key: string) => string): { label: string; className: string } {
  if (receipt.is_voided) {
    return { label: t('voidReturn.void'), className: 'bg-danger-surface text-danger-strong' };
  }
  if (receipt.receipt_type === 'return') {
    return { label: t('reports.typeReturn'), className: 'bg-warning-surface text-warning-strong' };
  }
  return { label: t('reports.typeSale'), className: 'bg-success-surface text-success-strong' };
}

export function TodaySalesPage() {
  const { t } = useTranslation('pos');
  const navigate = useNavigate();
  const { format, decimals } = useCurrency();
  const shift = useTerminalStore((s) => s.shift);
  const shiftId = shift?.id ?? null;

  /**
   * B-13 gate r1 (F-2) — `/sales` is the most direct blind-count bypass on the
   * device, and until this round it was ungated.
   *
   * The page lists, per receipt of the CURRENTLY OPEN shift, the total next to
   * the tender label. A cashier counting the drawer only had to add up the
   * CASH-labelled rows to obtain the exact takings that `/shift`, `/reports`
   * and the X report all conceal — no arithmetic derivation needed, just the
   * raw data for the shift being counted. It is server-reachable too: cashier
   * holds `pos.view_receipts` and `pos.manage_shifts`, so the bypass survived
   * even on a correctly cashier-provisioned terminal.
   *
   * Orchestrator ruling (2026-08-26): conceal the MONEY for non-managers while
   * a shift is open under blind counting; keep the receipt list, times, item
   * summaries and receipt count, so the panel still answers "did my sale
   * land?" — the reason a cashier opens it.
   *
   * Fails closed like every other leg: `useCashDisclosure` starts at 'conceal'
   * and only a positive `require_blind_cash_count === false` discloses, online
   * or from the offline cache.
   */
  const operator = useOperatorStore((s) => s.operator);
  const companyId = useAuthStore((s) => s.companyId);
  const cashDisclosure = useCashDisclosure(companyId);
  const concealTakings =
    cashDisclosure === 'conceal' && !hasManagerAccess(operator) && shiftId !== null;

  const [receipts, setReceipts] = useState<ShiftReceipt[]>([]);
  const [loading, setLoading] = useState(false);
  const [reprintingId, setReprintingId] = useState<string | null>(null);
  const [detailReceipt, setDetailReceipt] = useState<ShiftReceipt | null>(null);

  const loadReceipts = useCallback(async () => {
    if (!shiftId) return;
    setLoading(true);
    try {
      const data = await fetchShiftReceipts(shiftId);
      setReceipts(data);
    } catch (err) {
      // Log and surface once. fetchShiftReceipts' offline fallback already returns [] on
      // network failure; a rejection here means something more unusual (auth, schema).
      console.error('[TodaySales] fetchShiftReceipts failed', err);
      toast.error(t('reports.fetchError'));
      setReceipts([]);
    } finally {
      setLoading(false);
    }
  }, [shiftId, t]);

  useEffect(() => {
    if (shiftId) {
      loadReceipts();
    }
  }, [shiftId, loadReceipts]);

  const handleReprint = async (receiptId: string) => {
    setReprintingId(receiptId);
    try {
      await printReceiptAsPdf(receiptId);
    } catch {
      // printing error — user sees the dialog fail
    } finally {
      setReprintingId(null);
    }
  };

  const saleReceipts = receipts.filter((r) => r.receipt_type === 'sale' && isCounted(r));
  const returnReceipts = receipts.filter((r) => r.receipt_type === 'return' && isCounted(r));
  const voidedCount = receipts.filter((r) => r.is_voided).length;

  // Precision contract rule 19: intermediates at scale + 1, rounded ONCE at the
  // presentation boundary below.
  const intermediateScale = decimals + 1;
  const grossSales = bcsum(saleReceipts.map((r) => r.total), intermediateScale);
  // Per-row magnitude, never a raw sum of the stored totals. Legacy returns stored
  // a NEGATIVE total and v4 refund authoring stores a POSITIVE one (v3-refund-chain
  // spec §7.7), so summing raw totals lets a mixed-era shift CANCEL one era against
  // the other and deduct nothing. Same reasoning as the backend `-ABS(col)` CASE
  // (ticket 2026-08-01-positive-refund-total-consumers).
  const totalReturns = bcsum(
    returnReceipts.map((r) => bcabs(r.total, intermediateScale)),
    intermediateScale,
  );
  // O-28 (owner ruling 2026-08-21): the headline figure is NET, EXCLUDING REFUNDS.
  // Gross stays available for the per-sale average below, where a return is not a
  // member of the population being averaged.
  const netSales = bcformat(bcsub(grossSales, totalReturns, intermediateScale), decimals);
  const avgTicket =
    saleReceipts.length > 0
      ? bcdiv(grossSales, String(saleReceipts.length), decimals)
      : bcformat('0', decimals);

  const receiptTime = (receipt: ShiftReceipt) => {
    const ts = receipt.posted_at ?? receipt.created_at;
    return ts ? new Date(ts).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—';
  };

  return (
    <div className="flex h-full flex-col bg-surface-canvas">
      <PageHeader
        title={t('reports.todaySales')}
        onBack={() => navigate('/')}
        backLabel={t('common:back')}
      />

      {/* No-shift empty state */}
      {!shiftId && (
        <div className="flex flex-1 items-center justify-center">
          <p className="text-sm text-ink-muted">{t('reports.noShiftOpen')}</p>
        </div>
      )}

      {/* Summary Cards + Receipt Table (only when shift is open) */}
      {shiftId && (
        <>
          <div className="grid grid-cols-4 gap-4 px-6 py-4">
            <div className="rounded-card bg-action-subtle p-4">
              <p className="text-xs font-medium text-action">{t('reports.netSalesExclRefunds')}</p>
              <p className="mt-1 text-2xl font-bold text-ink">
                {concealTakings ? CONCEALED : format(netSales)}
              </p>
            </div>
            <div className="rounded-card bg-surface-raised p-4 shadow-sm ring-1 ring-border-subtle">
              <p className="text-xs font-medium text-ink-muted">{t('reports.receiptCount')}</p>
              <p className="mt-1 text-2xl font-bold text-ink">{receipts.length}</p>
            </div>
            <div className="rounded-card bg-surface-raised p-4 shadow-sm ring-1 ring-border-subtle">
              <p className="text-xs font-medium text-ink-muted">{t('reports.avgSaleTicket')}</p>
              <p className="mt-1 text-2xl font-bold text-ink">
                {concealTakings ? CONCEALED : format(avgTicket)}
              </p>
            </div>
            <div className="rounded-card bg-danger-surface p-4">
              <p className="text-xs font-medium text-danger-strong">{t('reports.returns')}</p>
              <p className="mt-1 text-2xl font-bold text-ink">
                {returnReceipts.length}
                {!concealTakings && bccomp(totalReturns, '0') > 0 && (
                  <span className="ml-2 text-sm font-normal text-danger">
                    −{format(totalReturns)}
                  </span>
                )}
                {voidedCount > 0 && (
                  <span className="ml-2 text-sm font-normal text-ink-muted">
                    ({voidedCount} {t('voidReturn.void').toLowerCase()})
                  </span>
                )}
              </p>
            </div>
          </div>

      {/* Receipt Table */}
      <div className="flex-1 overflow-y-auto px-6 pb-4">
        <div className="rounded-card bg-surface-raised shadow-sm ring-1 ring-border-subtle">
          {loading ? (
            <div className="flex items-center justify-center py-16">
              <RotateCcw className="h-6 w-6 animate-spin text-ink-faint" />
            </div>
          ) : receipts.length === 0 ? (
            <p className="py-16 text-center text-sm text-ink-muted">
              {t('reports.noReceipts')}
            </p>
          ) : (
            <table className="w-full">
              <thead className="border-b border-border-subtle text-xs font-medium uppercase text-ink-muted">
                <tr>
                  <th className="px-5 py-3 text-left">{t('reports.receiptNo')}</th>
                  <th className="px-5 py-3 text-left">{t('reports.time')}</th>
                  <th className="px-5 py-3 text-left">{t('reports.status')}</th>
                  <th className="px-5 py-3 text-left">{t('reports.items')}</th>
                  <th className="px-5 py-3 text-right">{t('reports.total')}</th>
                  <th className="px-5 py-3 text-left">{t('reports.paymentMethod')}</th>
                  <th className="px-5 py-3 text-right">{t('reports.actions')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border-subtle">
                {receipts.map((receipt) => {
                  const status = getReceiptStatus(receipt, t);
                  return (
                    <tr key={receipt.id} className={receipt.is_voided ? 'bg-surface-sunken/50 opacity-60' : 'hover:bg-surface-sunken/50'}>
                      <td className="px-5 py-3 text-sm font-medium text-ink">
                        {receipt.receipt_number}
                      </td>
                      <td className="px-5 py-3 text-sm text-ink-muted">
                        {receiptTime(receipt)}
                      </td>
                      <td className="px-5 py-3">
                        <span className={`inline-block rounded-pill px-2 py-0.5 text-xs font-medium ${status.className}`}>
                          {status.label}
                        </span>
                      </td>
                      <td className="max-w-[260px] px-5 py-3 text-sm text-ink-muted">
                        {receipt.lines.length > 0 ? (
                          <span
                            className="block truncate"
                            title={receipt.lines
                              .map(getLineSummary)
                              .join(', ')}
                          >
                            {receipt.lines
                              .map(getLineSummary)
                              .join(', ')}
                          </span>
                        ) : (
                          <span className="text-ink-faint">—</span>
                        )}
                      </td>
                      <td className="px-5 py-3 text-right text-sm font-semibold text-ink">
                        {concealTakings ? CONCEALED : (
                          <>
                            {receipt.receipt_type === 'return' && '−'}
                            {format(receipt.total)}
                          </>
                        )}
                      </td>
                      <td className="px-5 py-3">
                        <span className="rounded-sm bg-surface-sunken px-2 py-0.5 text-xs font-medium text-ink-muted">
                          {concealTakings ? CONCEALED : getPaymentLabel(receipt)}
                        </span>
                      </td>
                      <td className="px-5 py-3 text-right">
                        <div className="inline-flex items-center gap-2">
                          {/* `SaleDetailModal` is unit prices, line totals,
                              subtotal, tax, total and per-tender amounts — a
                              pure money surface with nothing left once masked,
                              so it is withdrawn rather than emptied. Reprint
                              stays: handing a customer a duplicate of their own
                              receipt is a core till function, and it is named
                              in the report as the acknowledged limit of this
                              control. */}
                          {!concealTakings && (
                            <Button
                              variant="secondary"
                              size="md"
                              onClick={() => setDetailReceipt(receipt)}
                              leftIcon={<Eye className="h-4 w-4" />}
                            >
                              {t('reports.view')}
                            </Button>
                          )}
                          <Button
                            variant="secondary"
                            size="md"
                            onClick={() => void handleReprint(receipt.id)}
                            disabled={reprintingId === receipt.id}
                            leftIcon={<Printer className="h-4 w-4" />}
                          >
                            {reprintingId === receipt.id ? '…' : t('reports.reprint')}
                          </Button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
        </div>
        {concealTakings && (
          <p className="mt-2 text-xs text-ink-muted">{t('reports.dashboard.cashConcealed')}</p>
        )}
      </div>
        </>
      )}

      <SaleDetailModal
        receipt={detailReceipt}
        isOpen={detailReceipt !== null}
        onClose={() => setDetailReceipt(null)}
        onReprint={(id) => void handleReprint(id)}
        reprinting={detailReceipt !== null && reprintingId === detailReceipt.id}
      />
    </div>
  );
}
