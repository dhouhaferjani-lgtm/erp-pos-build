import { useState, useEffect, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { ArrowLeft, RotateCcw, Printer, Eye } from 'lucide-react';
import { toast } from 'sonner';
import { fetchShiftReceipts, type ShiftReceipt } from '@/api/reportApi';
import { useTerminalStore } from '@/stores/terminalStore';
import { useCurrency } from '@/lib/currency';
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
    return { label: t('voidReturn.void'), className: 'bg-red-100 text-red-700' };
  }
  if (receipt.receipt_type === 'return') {
    return { label: t('reports.typeReturn'), className: 'bg-amber-100 text-amber-700' };
  }
  return { label: t('reports.typeSale'), className: 'bg-green-100 text-green-700' };
}

export function TodaySalesPage() {
  const { t } = useTranslation('pos');
  const navigate = useNavigate();
  const { format } = useCurrency();
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
  const totalSales = saleReceipts.reduce((sum, r) => sum + Number(r.total), 0);
  const totalReturns = returnReceipts.reduce((sum, r) => sum + Number(r.total), 0);
  const avgTicket = saleReceipts.length > 0 ? totalSales / saleReceipts.length : 0;

  const receiptTime = (receipt: ShiftReceipt) => {
    const ts = receipt.posted_at ?? receipt.created_at;
    return ts ? new Date(ts).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—';
  };

  return (
    <div className="flex h-full flex-col bg-gray-50">
      {/* Sub-header with back button */}
      <div className="flex items-center gap-3 border-b border-gray-200 bg-white px-4 py-3">
        <button
          onClick={() => navigate('/')}
          className="rounded-lg p-1.5 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700"
        >
          <ArrowLeft className="h-5 w-5" />
        </button>
        <h1 className="text-xl font-bold text-gray-900">{t('reports.todaySales')}</h1>
      </div>

      {/* No-shift empty state */}
      {!shiftId && (
        <div className="flex flex-1 items-center justify-center">
          <p className="text-sm text-gray-500">{t('reports.noShiftOpen')}</p>
        </div>
      )}

      {/* Summary Cards + Receipt Table (only when shift is open) */}
      {shiftId && (
        <>
          <div className="grid grid-cols-4 gap-4 px-6 py-4">
            <div className="rounded-xl bg-blue-50 p-4">
              <p className="text-xs font-medium text-blue-600">{t('reports.totalSales')}</p>
              <p className="mt-1 text-2xl font-bold text-gray-900">{format(totalSales)}</p>
            </div>
            <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
              <p className="text-xs font-medium text-gray-500">{t('reports.receiptCount')}</p>
              <p className="mt-1 text-2xl font-bold text-gray-900">{receipts.length}</p>
            </div>
            <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-100">
              <p className="text-xs font-medium text-gray-500">{t('reports.avgTicket')}</p>
              <p className="mt-1 text-2xl font-bold text-gray-900">{format(avgTicket)}</p>
            </div>
            <div className="rounded-xl bg-red-50 p-4">
              <p className="text-xs font-medium text-red-600">{t('reports.returns')}</p>
              <p className="mt-1 text-2xl font-bold text-gray-900">
                {returnReceipts.length}
                {totalReturns > 0 && (
                  <span className="ml-2 text-sm font-normal text-red-500">
                    −{format(totalReturns)}
                  </span>
                )}
                {voidedCount > 0 && (
                  <span className="ml-2 text-sm font-normal text-gray-500">
                    ({voidedCount} {t('voidReturn.void').toLowerCase()})
                  </span>
                )}
              </p>
            </div>
          </div>

      {/* Receipt Table */}
      <div className="flex-1 overflow-y-auto px-6 pb-4">
        <div className="rounded-xl bg-white shadow-sm ring-1 ring-gray-100">
          {loading ? (
            <div className="flex items-center justify-center py-16">
              <RotateCcw className="h-6 w-6 animate-spin text-gray-400" />
            </div>
          ) : receipts.length === 0 ? (
            <p className="py-16 text-center text-sm text-gray-500">
              {t('reports.noReceipts')}
            </p>
          ) : (
            <table className="w-full">
              <thead className="border-b border-gray-100 text-xs font-medium uppercase text-gray-500">
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
              <tbody className="divide-y divide-gray-50">
                {receipts.map((receipt) => {
                  const status = getReceiptStatus(receipt, t);
                  return (
                    <tr key={receipt.id} className={receipt.is_voided ? 'bg-gray-50/50 opacity-60' : 'hover:bg-gray-50/50'}>
                      <td className="px-5 py-3 text-sm font-medium text-gray-900">
                        {receipt.receipt_number}
                      </td>
                      <td className="px-5 py-3 text-sm text-gray-600">
                        {receiptTime(receipt)}
                      </td>
                      <td className="px-5 py-3">
                        <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${status.className}`}>
                          {status.label}
                        </span>
                      </td>
                      <td className="max-w-[260px] px-5 py-3 text-sm text-gray-600">
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
                          <span className="text-gray-400">—</span>
                        )}
                      </td>
                      <td className="px-5 py-3 text-right text-sm font-semibold text-gray-900">
                        {receipt.receipt_type === 'return' && '−'}
                        {format(receipt.total)}
                      </td>
                      <td className="px-5 py-3">
                        <span className="rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">
                          {getPaymentLabel(receipt)}
                        </span>
                      </td>
                      <td className="px-5 py-3 text-right">
                        <div className="inline-flex items-center gap-2">
                          <button
                            type="button"
                            onClick={() => setDetailReceipt(receipt)}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-200"
                          >
                            <Eye className="h-3.5 w-3.5" />
                            {t('reports.view')}
                          </button>
                          <button
                            type="button"
                            onClick={() => void handleReprint(receipt.id)}
                            disabled={reprintingId === receipt.id}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-200 disabled:opacity-50"
                          >
                            <Printer className="h-3.5 w-3.5" />
                            {reprintingId === receipt.id ? '...' : t('reports.reprint')}
                          </button>
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
