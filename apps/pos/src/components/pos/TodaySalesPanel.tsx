import { useState, useEffect, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { RotateCcw, Printer, Eye } from 'lucide-react';
import { PageHeader } from '@/components/PageHeader';
import { Button } from '@/components/ui';
import { toast } from 'sonner';
import { fetchShiftReceipts, type ShiftReceipt } from '@/api/reportApi';
import { useTerminalStore } from '@/stores/terminalStore';
import { useCurrency } from '@/lib/currency';
import { bcsum, bcdiv, bccomp, bcformat } from '@/lib/decimal';
import { printReceiptAsPdf } from '@/lib/printing';
import { SaleDetailModal } from '@/components/pos/SaleDetailModal';

function getLineName(line: ShiftReceipt['lines'][number]): string {
  return line.product?.name ?? line.product_name ?? '—';
}

function getPaymentLabel(receipt: ShiftReceipt): string {
  if (!receipt.payments || receipt.payments.length === 0) return '—';
  return receipt.payments.map((p) => p.payment_type).join(' + ');
}

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

  const saleReceipts = receipts.filter((r) => r.receipt_type === 'sale' && !r.is_voided);
  const returnReceipts = receipts.filter((r) => r.receipt_type === 'return');
  const voidedCount = receipts.filter((r) => r.is_voided).length;
  const totalSales = bcsum(saleReceipts.map((r) => r.total), decimals);
  const totalReturns = bcsum(returnReceipts.map((r) => r.total), decimals);
  const avgTicket =
    saleReceipts.length > 0
      ? bcdiv(totalSales, String(saleReceipts.length), decimals)
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
              <p className="text-xs font-medium text-action">{t('reports.totalSales')}</p>
              <p className="mt-1 text-2xl font-bold text-ink">{format(totalSales)}</p>
            </div>
            <div className="rounded-card bg-surface-raised p-4 shadow-sm ring-1 ring-border-subtle">
              <p className="text-xs font-medium text-ink-muted">{t('reports.receiptCount')}</p>
              <p className="mt-1 text-2xl font-bold text-ink">{receipts.length}</p>
            </div>
            <div className="rounded-card bg-surface-raised p-4 shadow-sm ring-1 ring-border-subtle">
              <p className="text-xs font-medium text-ink-muted">{t('reports.avgTicket')}</p>
              <p className="mt-1 text-2xl font-bold text-ink">{format(avgTicket)}</p>
            </div>
            <div className="rounded-card bg-danger-surface p-4">
              <p className="text-xs font-medium text-danger-strong">{t('reports.returns')}</p>
              <p className="mt-1 text-2xl font-bold text-ink">
                {returnReceipts.length}
                {bccomp(totalReturns, '0') > 0 && (
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
                              .map((l) => `${l.quantity}× ${getLineName(l)}`)
                              .join(', ')}
                          >
                            {receipt.lines
                              .map((l) => `${l.quantity}× ${getLineName(l)}`)
                              .join(', ')}
                          </span>
                        ) : (
                          <span className="text-ink-faint">—</span>
                        )}
                      </td>
                      <td className="px-5 py-3 text-right text-sm font-semibold text-ink">
                        {receipt.receipt_type === 'return' && '−'}
                        {format(receipt.total)}
                      </td>
                      <td className="px-5 py-3">
                        <span className="rounded-sm bg-surface-sunken px-2 py-0.5 text-xs font-medium text-ink-muted">
                          {getPaymentLabel(receipt)}
                        </span>
                      </td>
                      <td className="px-5 py-3 text-right">
                        <div className="inline-flex items-center gap-2">
                          <Button
                            variant="secondary"
                            size="md"
                            onClick={() => setDetailReceipt(receipt)}
                            leftIcon={<Eye className="h-4 w-4" />}
                          >
                            {t('reports.view')}
                          </Button>
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
