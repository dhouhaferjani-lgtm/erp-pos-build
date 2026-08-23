import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { formatPercent } from '@/lib/format';
import { Modal } from './Modal';
import { VatDisclosureSummary } from './VatDisclosureSummary';
import { deriveVatDisclosure } from '@/lib/reports/vatDisclosure';
import { Loader2 } from 'lucide-react';
import type { XReportResponse } from '@/api/reportApi';

interface XReportModalProps {
  isOpen: boolean;
  onClose: () => void;
  report: XReportResponse | null;
  isLoading: boolean;
  error: string | null;
}

export function XReportModal({ isOpen, onClose, report, isLoading, error }: XReportModalProps) {
  const { t } = useTranslation('pos');
  const { format, decimals } = useCurrency();

  // B-6(ii): derived from the report's own signed/stored fields. The X payload
  // is byte-identical to before — nothing here reaches `appendXReport`.
  const vatDisclosure = report ? deriveVatDisclosure(report, decimals) : null;

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={t('reports.xReportTitle')} size="xl">
      {isLoading && (
        <div className="flex flex-col items-center gap-3 py-12">
          <Loader2 className="h-8 w-8 animate-spin text-action" />
          <p className="text-sm text-ink-muted">{t('reports.loading')}</p>
        </div>
      )}

      {error && !isLoading && (
        <div className="rounded-tile bg-danger-surface p-4 text-center">
          <p className="font-medium text-danger-strong">{t('reports.errorGenerating')}</p>
          <p className="mt-1 text-sm text-danger-strong">{error}</p>
        </div>
      )}

      {report && !isLoading && (
        <div className="space-y-5">
          {/* Generated timestamp */}
          <p className="text-right text-xs text-ink-muted">
            {t('reports.generatedAt')} {new Date(report.generated_at).toLocaleString()}
          </p>

          {/* Summary cards */}
          <div className="grid grid-cols-4 gap-3">
            <SummaryCard label={t('reports.salesCount')} value={String(report.sales_count)} />
            <SummaryCard label={t('reports.grossSales')} value={format(report.gross_sales)} />
            <SummaryCard label={t('reports.netSales')} value={format(report.net_sales)} />
            {/* B-6(ii)/A1: on a refund-bearing shift this card shows the NET
                figure — the same number the per-rate table below totals to and
                the one the VAT declaration reads — instead of the sale-only
                `tax_amount`, which used to sit here disagreeing with that table
                by exactly the refund VAT. The full three-line bridge follows. */}
            <SummaryCard
              label={
                vatDisclosure?.hasRefundVat
                  ? `${t('reports.taxAmount')} (${t('reports.vatNetOfRefunds')})`
                  : t('reports.taxAmount')
              }
              value={format(vatDisclosure?.hasRefundVat ? vatDisclosure.netVat : report.tax_amount)}
            />
          </div>

          {vatDisclosure !== null && (
            <VatDisclosureSummary disclosure={vatDisclosure} format={format} keyPrefix="reports" />
          )}

          {/* Refunds — `refunds_amount` is sent by both the local and server X
              builders and was silently dropped here until B-6(ii). */}
          {report.refunds_count > 0 && (
            <div className="rounded-tile bg-warning-surface px-4 py-3">
              <div className="flex justify-between text-sm font-medium text-warning-strong">
                <span>
                  {t('reports.refundsCount')}: {report.refunds_count}
                </span>
                <span className="tabular-nums">{format(report.refunds_amount)}</span>
              </div>
            </div>
          )}

          {/* VAT Breakdown */}
          {report.vat_breakdown.length > 0 && (
            <div>
              <h4 className="mb-2 text-sm font-semibold text-ink-muted">
                {t('reports.vatBreakdown')}
                {vatDisclosure?.hasRefundVat ? ` — ${t('reports.vatNetOfRefunds')}` : ''}
              </h4>
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
                  {report.vat_breakdown.map((row) => (
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
          {report.payment_methods.length > 0 && (
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
                  {report.payment_methods.map((row) => (
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
      )}
    </Modal>
  );
}

function SummaryCard({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-tile bg-surface-sunken px-4 py-3 text-center">
      <p className="text-xs text-ink-muted">{label}</p>
      <p className="mt-1 text-lg font-bold text-ink">{value}</p>
    </div>
  );
}
