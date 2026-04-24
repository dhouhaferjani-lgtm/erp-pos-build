import { useState, useEffect, useRef, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { CheckCircle, Loader2, AlertCircle, Printer } from 'lucide-react';
import { Modal } from './Modal';
import { useCurrency } from '@/lib/currency';
import type { Shift } from '@/stores/terminalStore';
import type { EndOfDayPreview } from '@/lib/offline/endOfDayPreview';

export interface EndOfDayConfirmResult {
  formattedZNumber: string;
  wasReused: boolean;
}

export interface EndOfDayPreviewModalProps {
  isOpen: boolean;
  onClose: () => void;
  shift: Shift;
  terminalId: string;
  onConfirmAndClose: (preview: EndOfDayPreview) => Promise<EndOfDayConfirmResult>;
  onPrintReceipt?: (result: EndOfDayConfirmResult) => void;
}

type ModalPhase = 'loading' | 'preview' | 'confirming' | 'success' | 'error';

export function EndOfDayPreviewModal({
  isOpen,
  onClose,
  shift,
  terminalId,
  onConfirmAndClose,
  onPrintReceipt,
}: EndOfDayPreviewModalProps) {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();

  const [phase, setPhase] = useState<ModalPhase>('loading');
  const [preview, setPreview] = useState<EndOfDayPreview | null>(null);
  const [result, setResult] = useState<EndOfDayConfirmResult | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // Guard: once confirmation begins, block backdrop dismiss
  const isConfirmingRef = useRef(false);

  const handleClose = useCallback(() => {
    if (isConfirmingRef.current) return;
    onClose();
  }, [onClose]);

  // Load preview data when modal opens
  useEffect(() => {
    if (!isOpen) {
      // Reset state when modal closes
      setPhase('loading');
      setPreview(null);
      setResult(null);
      setErrorMessage(null);
      isConfirmingRef.current = false;
      return;
    }

    let cancelled = false;

    const loadPreview = async () => {
      setPhase('loading');
      try {
        const { getDatabase } = await import('@/lib/db');
        const { useAuthStore } = await import('@/stores/authStore');
        const companyId = useAuthStore.getState().companyId;
        if (!companyId) throw new Error('No company selected');
        const db = await getDatabase(companyId);
        const { buildEndOfDayPreview } = await import('@/lib/offline/endOfDayPreview');
        const data = await buildEndOfDayPreview(
          db,
          terminalId,
          shift.opened_at,
          shift.opening_cash,
        );
        if (!cancelled) {
          setPreview(data);
          setPhase('preview');
        }
      } catch (err) {
        if (!cancelled) {
          const msg = err instanceof Error ? err.message : String(err);
          setErrorMessage(msg);
          setPhase('error');
        }
      }
    };

    void loadPreview();
    return () => { cancelled = true; };
  }, [isOpen, terminalId, shift.opened_at, shift.opening_cash]);

  const handleConfirm = useCallback(async () => {
    if (!preview) return;
    isConfirmingRef.current = true;
    setPhase('confirming');
    try {
      const confirmResult = await onConfirmAndClose(preview);
      setResult(confirmResult);
      setPhase('success');
    } catch (err) {
      const msg = err instanceof Error ? err.message : String(err);
      setErrorMessage(msg);
      setPhase('error');
      isConfirmingRef.current = false;
    }
  }, [preview, onConfirmAndClose]);

  const title = phase === 'success'
    ? t('reports.endOfDay.successTitle')
    : t('reports.endOfDay.title');

  return (
    <Modal isOpen={isOpen} onClose={handleClose} title={title} size="xl">
      {phase === 'loading' && (
        <div className="flex flex-col items-center gap-3 py-12">
          <Loader2 className="h-8 w-8 animate-spin text-blue-500" />
          <p className="text-sm text-gray-500">{t('reports.loading')}</p>
        </div>
      )}

      {phase === 'error' && (
        <div className="flex flex-col items-center gap-3 py-8">
          <AlertCircle className="h-10 w-10 text-red-500" />
          <p className="text-center text-sm text-red-600">{errorMessage}</p>
          <button
            onClick={handleClose}
            className="mt-4 rounded-xl border border-gray-300 px-6 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50"
          >
            {t('reports.endOfDay.cancel')}
          </button>
        </div>
      )}

      {(phase === 'preview' || phase === 'confirming') && preview !== null && (
        <div className="space-y-5">
          {/* Subtitle */}
          <p className="text-sm text-gray-500">{t('reports.endOfDay.subtitle')}</p>

          {/* Shift info */}
          <div className="flex items-center justify-between text-xs text-gray-500">
            <span>{t('shift.number', { number: shift.shift_number })}</span>
            <span>
              {new Date(shift.opened_at).toLocaleString()}
            </span>
          </div>

          {/* Totals */}
          <div className="grid grid-cols-4 gap-3">
            <SummaryCard label={t('reports.endOfDay.salesCount')} value={String(preview.sales_count)} />
            <SummaryCard label={t('reports.endOfDay.grossSales')} value={format(preview.gross_sales)} />
            <SummaryCard label={t('reports.endOfDay.netSales')} value={format(preview.net_sales)} />
            <SummaryCard label={t('reports.endOfDay.taxAmount')} value={format(preview.tax_amount)} />
          </div>

          {/* Cash reconciliation */}
          <div className="rounded-xl border border-blue-200 bg-blue-50 p-5">
            <h4 className="mb-3 text-sm font-semibold text-blue-800">
              {t('reports.endOfDay.cashReconciliation')}
            </h4>
            <div className="grid grid-cols-2 gap-4 text-sm">
              <div>
                <p className="text-xs text-blue-600">{t('reports.endOfDay.openingCash')}</p>
                <p className="mt-0.5 text-lg font-bold text-blue-900">{format(preview.opening_cash)}</p>
              </div>
              <div>
                <p className="text-xs text-blue-600">{t('reports.endOfDay.expectedCash')}</p>
                <p className="mt-0.5 text-lg font-bold text-blue-900">{format(preview.expected_cash)}</p>
              </div>
            </div>
          </div>

          {/* VAT Breakdown + Payment Methods side by side */}
          <div className="grid grid-cols-2 gap-5">
            {preview.vat_breakdown.length > 0 && (
              <div>
                <h4 className="mb-2 text-sm font-semibold text-gray-700">
                  {t('reports.endOfDay.vatBreakdown')}
                </h4>
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-gray-200 text-left text-xs text-gray-500">
                      <th className="pb-2">{t('reports.vatRate')}</th>
                      <th className="pb-2 text-right">{t('reports.vatNet')}</th>
                      <th className="pb-2 text-right">{t('reports.vatVat')}</th>
                      <th className="pb-2 text-right">{t('reports.vatGross')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {preview.vat_breakdown.map((row) => (
                      <tr key={row.tax_rate} className="border-b border-gray-100">
                        <td className="py-2">{row.tax_rate}%</td>
                        <td className="py-2 text-right">{format(row.net_amount)}</td>
                        <td className="py-2 text-right">{format(row.vat_amount)}</td>
                        <td className="py-2 text-right">{format(row.gross_amount)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {preview.payment_methods.length > 0 && (
              <div>
                <h4 className="mb-2 text-sm font-semibold text-gray-700">
                  {t('reports.endOfDay.payments')}
                </h4>
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-gray-200 text-left text-xs text-gray-500">
                      <th className="pb-2">{t('reports.paymentType')}</th>
                      <th className="pb-2 text-right">{t('reports.paymentCount')}</th>
                      <th className="pb-2 text-right">{t('reports.paymentAmount')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {preview.payment_methods.map((row) => (
                      <tr key={row.payment_type} className="border-b border-gray-100">
                        <td className="py-2">{row.payment_type}</td>
                        <td className="py-2 text-right">{row.transaction_count}</td>
                        <td className="py-2 text-right">{format(row.total_amount)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>

          {/* Bottom bar */}
          <div className="flex gap-3 pt-2">
            <button
              onClick={handleClose}
              disabled={phase === 'confirming'}
              className="flex-1 rounded-xl border border-gray-300 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {t('reports.endOfDay.cancel')}
            </button>
            <button
              onClick={() => void handleConfirm()}
              disabled={phase === 'confirming'}
              aria-label={t('reports.endOfDay.confirmLabel')}
              className="flex-1 rounded-xl bg-red-600 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-75"
            >
              {phase === 'confirming' ? (
                <span className="flex items-center justify-center gap-2">
                  <Loader2 className="h-4 w-4 animate-spin" />
                  {t('reports.endOfDay.confirming')}
                </span>
              ) : (
                t('reports.endOfDay.confirmLabel')
              )}
            </button>
          </div>
        </div>
      )}

      {phase === 'success' && result !== null && (
        <div className="flex flex-col items-center gap-4 py-8">
          <CheckCircle className="h-16 w-16 text-green-500" />

          <div className="text-center">
            <p className="mt-2 text-sm text-gray-600">
              {t('reports.endOfDay.successZNumber')}: {' '}
              <span className="rounded bg-blue-100 px-2 py-0.5 font-mono font-bold text-blue-800">
                {result.formattedZNumber}
              </span>
            </p>
            {result.wasReused && (
              <p className="mt-2 text-xs text-amber-600">
                {t('reports.endOfDay.successReused')}
              </p>
            )}
          </div>

          <div className="mt-4 flex gap-3">
            {onPrintReceipt && (
              <button
                onClick={() => onPrintReceipt(result)}
                className="flex items-center gap-2 rounded-xl border border-gray-300 bg-white px-6 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50"
              >
                <Printer className="h-4 w-4" />
                {t('reports.endOfDay.printReceipt')}
              </button>
            )}
            <button
              onClick={onClose}
              className="rounded-xl bg-gray-900 px-6 py-2.5 text-sm font-semibold text-white hover:bg-gray-800"
            >
              {t('reports.endOfDay.done')}
            </button>
          </div>
        </div>
      )}
    </Modal>
  );
}

function SummaryCard({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg bg-gray-50 px-4 py-3 text-center">
      <p className="text-xs text-gray-500">{label}</p>
      <p className="mt-1 text-lg font-bold text-gray-900">{value}</p>
    </div>
  );
}
