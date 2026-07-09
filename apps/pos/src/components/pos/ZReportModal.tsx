import { useState, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { formatPercent } from '@/lib/format';
import { Modal } from './Modal';
import { Loader2, Info, AlertTriangle } from 'lucide-react';
import type { ZReportResponse } from '@/api/reportApi';

interface ZReportModalProps {
  isOpen: boolean;
  onClose: () => void;
  onConfirmGenerate: () => Promise<void>;
  report: ZReportResponse | null;
  isLoading: boolean;
  error: string | null;
}

export function ZReportModal({
  isOpen,
  onClose,
  onConfirmGenerate,
  report,
  isLoading,
  error,
}: ZReportModalProps) {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();
  const [confirmed, setConfirmed] = useState(false);

  const handleClose = useCallback(() => {
    setConfirmed(false);
    onClose();
  }, [onClose]);

  const handleConfirm = useCallback(async () => {
    setConfirmed(true);
    await onConfirmGenerate();
  }, [onConfirmGenerate]);

  // Show confirmation screen when not yet confirmed and no report loaded
  const showConfirmation = !confirmed && !report;

  return (
    <Modal isOpen={isOpen} onClose={handleClose} title={t('reports.zReportTitle')} size="xl">
      {showConfirmation && !isLoading && (
        <div className="flex h-full flex-col items-center justify-center px-4">
          <div className="mx-auto max-w-md text-center">
            {/* Warning icon */}
            <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-warning-surface">
              <AlertTriangle className="h-8 w-8 text-warning-strong" />
            </div>

            <h3 className="mb-2 text-lg font-bold text-ink">
              {t('reports.zReportWarningTitle')}
            </h3>
            <p className="mb-5 text-sm text-ink-muted">
              {t('reports.zReportWarningDesc')}
            </p>

            {/* Bullet points */}
            <ul className="mb-6 space-y-2 text-left text-sm text-ink-muted">
              <li className="flex items-start gap-2">
                <span className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-warning-strong" />
                {t('reports.zReportWarningBullet1')}
              </li>
              <li className="flex items-start gap-2">
                <span className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-warning-strong" />
                {t('reports.zReportWarningBullet2')}
              </li>
              <li className="flex items-start gap-2">
                <span className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-warning-strong" />
                {t('reports.zReportWarningBullet3')}
              </li>
            </ul>

            {/* Action buttons */}
            <div className="flex gap-3">
              <button
                onClick={handleClose}
                className="flex-1 rounded-xl border border-border-strong bg-surface-raised px-4 py-3 text-sm font-semibold text-ink-muted transition-colors hover:bg-surface-sunken"
              >
                {t('reports.zReportCancel')}
              </button>
              <button
                onClick={() => void handleConfirm()}
                className="flex-1 rounded-xl bg-warning px-4 py-3 text-sm font-semibold text-ink-inverse transition-colors hover:bg-warning-strong"
              >
                {t('reports.zReportConfirm')}
              </button>
            </div>
          </div>
        </div>
      )}

      {isLoading && (
        <div className="flex flex-col items-center gap-3 py-12">
          <Loader2 className="h-8 w-8 animate-spin text-action" />
          <p className="text-sm text-ink-muted">{t('reports.loading')}</p>
        </div>
      )}

      {error && !isLoading && confirmed && (
        <div className="rounded-lg bg-danger-surface p-4 text-center">
          <p className="font-medium text-danger-strong">{t('reports.errorGenerating')}</p>
          <p className="mt-1 text-sm text-danger">{error}</p>
        </div>
      )}

      {report && !isLoading && (
        <div className="space-y-5">
          {/* Header: Z number + fiscal hash + timestamp */}
          <div className="flex items-start justify-between">
            <div className="flex items-center gap-3">
              <span className="rounded-lg bg-action-subtle px-3 py-1.5 text-sm font-bold text-action">
                {report.formatted_z_number}
              </span>
              <p className="font-mono text-xs text-ink-muted" title={report.fiscal_hash}>
                {t('reports.fiscalHash')}: {report.fiscal_hash.slice(0, 16)}…
              </p>
            </div>
            <p className="text-xs text-ink-muted">
              {t('reports.generatedAt')} {new Date(report.generated_at).toLocaleString()}
            </p>
          </div>

          {/* Cash Reconciliation */}
          <div className="rounded-xl border border-action-subtle bg-action-subtle p-5">
            <h4 className="mb-3 text-sm font-semibold text-action">{t('reports.cashReconciliation')}</h4>
            <div className="grid grid-cols-3 gap-4 text-sm">
              <div>
                <p className="text-xs text-action">{t('reports.openingCash')}</p>
                <p className="mt-0.5 text-lg font-bold text-ink">{format(report.opening_cash)}</p>
              </div>
              <div>
                <p className="text-xs text-action">{t('reports.expectedCash')}</p>
                <p className="mt-0.5 text-lg font-bold text-ink">{format(report.expected_cash)}</p>
              </div>
              <div>
                <p className="text-xs text-action">{t('reports.variance')}</p>
                {report.has_variance ? (
                  <p className="mt-0.5 text-lg font-bold text-danger-strong">{format(report.variance)}</p>
                ) : (
                  <p className="mt-0.5 text-lg font-bold text-success-strong">{t('reports.noVariance')}</p>
                )}
              </div>
            </div>
            {!report.has_variance && (
              <div className="mt-3 flex items-start gap-2 rounded-lg bg-action-subtle/50 px-3 py-2">
                <Info className="mt-0.5 h-3.5 w-3.5 shrink-0 text-action" />
                <p className="text-xs text-action">{t('reports.varianceNote')}</p>
              </div>
            )}
          </div>

          {/* Sales Summary */}
          <div className="grid grid-cols-4 gap-3">
            <SummaryCard label={t('reports.salesCount')} value={String(report.report_data.sales_count)} />
            <SummaryCard label={t('reports.grossSales')} value={format(report.report_data.gross_sales)} />
            <SummaryCard label={t('reports.netSales')} value={format(report.report_data.net_sales)} />
            <SummaryCard label={t('reports.taxAmount')} value={format(report.report_data.tax_amount)} />
          </div>

          {/* Refunds */}
          {report.report_data.refunds_count > 0 && (
            <div className="rounded-lg bg-warning-surface px-4 py-3">
              <div className="flex justify-between text-sm font-medium text-warning-strong">
                <span>{t('reports.refundsCount')}: {report.report_data.refunds_count}</span>
                <span>{format(report.report_data.refunds_amount)}</span>
              </div>
            </div>
          )}

          {/* VAT Breakdown + Payment Methods side by side */}
          <div className="grid grid-cols-2 gap-5">
            {/* VAT Breakdown */}
            {report.report_data.vat_breakdown.length > 0 && (
              <div>
                <h4 className="mb-2 text-sm font-semibold text-ink-muted">{t('reports.vatBreakdown')}</h4>
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-border-subtle text-left text-xs text-ink-muted">
                      <th className="pb-2">{t('reports.vatRate')}</th>
                      <th className="pb-2 text-right">{t('reports.vatNet')}</th>
                      <th className="pb-2 text-right">{t('reports.vatVat')}</th>
                      <th className="pb-2 text-right">{t('reports.vatGross')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {report.report_data.vat_breakdown.map((row) => (
                      <tr key={row.tax_rate} className="border-b border-border-subtle">
                        <td className="py-2">{formatPercent(row.tax_rate)}</td>
                        <td className="py-2 text-right">{format(row.net_amount)}</td>
                        <td className="py-2 text-right">{format(row.vat_amount)}</td>
                        <td className="py-2 text-right">{format(row.gross_amount)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {/* Payment Methods */}
            {report.report_data.payment_methods.length > 0 && (
              <div>
                <h4 className="mb-2 text-sm font-semibold text-ink-muted">{t('reports.paymentBreakdown')}</h4>
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-border-subtle text-left text-xs text-ink-muted">
                      <th className="pb-2">{t('reports.paymentType')}</th>
                      <th className="pb-2 text-right">{t('reports.paymentCount')}</th>
                      <th className="pb-2 text-right">{t('reports.paymentAmount')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {report.report_data.payment_methods.map((row) => (
                      <tr key={row.payment_type} className="border-b border-border-subtle">
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
        </div>
      )}
    </Modal>
  );
}

function SummaryCard({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg bg-surface-sunken px-4 py-3 text-center">
      <p className="text-xs text-ink-muted">{label}</p>
      <p className="mt-1 text-lg font-bold text-ink">{value}</p>
    </div>
  );
}
