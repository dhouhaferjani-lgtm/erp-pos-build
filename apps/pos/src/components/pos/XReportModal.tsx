import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { Modal } from './Modal';
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
  const { format } = useCurrency();

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={t('reports.xReportTitle')} size="xl">
      {isLoading && (
        <div className="flex flex-col items-center gap-3 py-12">
          <Loader2 className="h-8 w-8 animate-spin text-blue-500" />
          <p className="text-sm text-gray-500">{t('reports.loading')}</p>
        </div>
      )}

      {error && !isLoading && (
        <div className="rounded-lg bg-red-50 p-4 text-center">
          <p className="font-medium text-red-700">{t('reports.errorGenerating')}</p>
          <p className="mt-1 text-sm text-red-600">{error}</p>
        </div>
      )}

      {report && !isLoading && (
        <div className="space-y-5">
          {/* Generated timestamp */}
          <p className="text-right text-xs text-gray-500">
            {t('reports.generatedAt')} {new Date(report.generated_at).toLocaleString()}
          </p>

          {/* Summary cards */}
          <div className="grid grid-cols-4 gap-3">
            <SummaryCard label={t('reports.salesCount')} value={String(report.sales_count)} />
            <SummaryCard label={t('reports.grossSales')} value={format(report.gross_sales)} />
            <SummaryCard label={t('reports.netSales')} value={format(report.net_sales)} />
            <SummaryCard label={t('reports.taxAmount')} value={format(report.tax_amount)} />
          </div>

          {/* Refunds */}
          {report.refunds_count > 0 && (
            <div className="rounded-lg bg-amber-50 px-4 py-3">
              <span className="text-sm font-medium text-amber-700">
                {t('reports.refundsCount')}: {report.refunds_count}
              </span>
            </div>
          )}

          {/* VAT Breakdown */}
          {report.vat_breakdown.length > 0 && (
            <div>
              <h4 className="mb-2 text-sm font-semibold text-gray-700">{t('reports.vatBreakdown')}</h4>
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
                  {report.vat_breakdown.map((row) => (
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

          {/* Payment Methods */}
          {report.payment_methods.length > 0 && (
            <div>
              <h4 className="mb-2 text-sm font-semibold text-gray-700">{t('reports.paymentBreakdown')}</h4>
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-gray-200 text-left text-xs text-gray-500">
                    <th className="pb-2">{t('reports.paymentType')}</th>
                    <th className="pb-2 text-right">{t('reports.paymentCount')}</th>
                    <th className="pb-2 text-right">{t('reports.paymentAmount')}</th>
                  </tr>
                </thead>
                <tbody>
                  {report.payment_methods.map((row) => (
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
