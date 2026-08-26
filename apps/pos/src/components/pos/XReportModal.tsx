import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { formatPercent } from '@/lib/format';
import { Modal } from './Modal';
import { VatDisclosureSummary } from './VatDisclosureSummary';
import { deriveVatDisclosure } from '@/lib/reports/vatDisclosure';
import { Loader2 } from 'lucide-react';
import type { PaymentMethodItem, XReportResponse } from '@/api/reportApi';

interface XReportModalProps {
  isOpen: boolean;
  onClose: () => void;
  report: XReportResponse | null;
  isLoading: boolean;
  error: string | null;
  /**
   * B-13 (ii): true while a shift is open under blind cash counting — the
   * regime in which physical-tender takings are a term of the drawer
   * expectation the counter must not see. The caller owns that decision (it
   * holds the policy and the open shift); this component owns WHICH rows the
   * regime covers, read off each row's own `is_physical`.
   *
   * Display-only: the SIGNED `X_REPORT` payload is authored in
   * `api/reportApi.ts` (an explicit three-field allow-list that does not carry
   * `is_physical`) and is byte-identical either way.
   */
  concealPhysicalTenders: boolean;
}

/** Concealed figures read as an em dash, matching `/shift` and `/reports`. */
const CONCEALED = '—';

/**
 * RESIDUAL B-13 (i), inherited here on purpose — NOT closed by this component.
 *
 * The summary cards above still show `gross_sales` / `net_sales` /
 * `refunds_amount`, and Σ(all tender rows) === gross_sales − refunds_amount by
 * construction (`api/reportApi.ts` builds both from the same receipt loop). So
 * with a single physical tender in play, its concealed amount is exactly
 * re-derivable as `gross_sales − refunds_amount − Σ(visible tenders)` — the
 * same arithmetic the `/reports` dashboard leaves open.
 *
 * Closing it means concealing the headline during trading hours, which makes
 * the report useless for the thing it is for. That is a PRODUCT call the owner
 * has not made (LEDGER B-13 residual (i)); until they do, `/reports` and this
 * modal stay deliberately consistent with each other rather than one of them
 * quietly going further.
 */

/**
 * Gate r1 (F-1 / R1-5) — fail CLOSED on an unresolvable tender.
 *
 * `is_physical` is OPTIONAL on the wire: the server X builder
 * (`XReportResource`) never emits it, and the device builder leaves it
 * undefined for a tender whose payment-method row is missing from the synced
 * table (deactivated or renamed). Reading `undefined` as "not physical" would
 * disclose cash in full in the one regime whose entire purpose is concealment,
 * so UNKNOWN conceals. An explicit `false` is the only disclosure.
 *
 * This also disposes of R1-7: the two builders do NOT share a `payment_type`
 * namespace (device = method CODE, server = method display NAME —
 * `ReceiptPaymentService.php:358`, `PosCoreReceiptProjection.php:1540-1542`),
 * so the earlier code-join mask would have silently no-op'd on every
 * server-built X report. Keying on a flag carried by the row removes the join
 * entirely.
 *
 * Consequence, accepted: on a v2/server-built X report every tender row is
 * masked while blind counting is on. That over-conceals rather than
 * under-conceals, and v3 — the tenant-#1 path — always uses the device builder
 * (`Header.handleXReport` supplies fiscal opts only for
 * `fiscal_schema_version === 3`, and `reportApi.generateXReport` then builds
 * locally), so the accurate flag is present where it matters.
 */
function isConcealed(row: PaymentMethodItem, concealPhysicalTenders: boolean): boolean {
  return concealPhysicalTenders && row.is_physical !== false;
}

export function XReportModal({
  isOpen,
  onClose,
  report,
  isLoading,
  error,
  concealPhysicalTenders,
}: XReportModalProps) {
  const { t } = useTranslation('pos');
  const { format, decimals } = useCurrency();
  const anyTenderConcealed = report !== null
    && report.payment_methods.some((row) => isConcealed(row, concealPhysicalTenders));

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
                  ? t('reports.taxAmountNetOfRefunds')
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
                {vatDisclosure?.hasRefundVat
                  ? t('reports.vatBreakdownNetOfRefunds')
                  : t('reports.vatBreakdown')}
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
                  {report.payment_methods.map((row) => {
                    const concealed = isConcealed(row, concealPhysicalTenders);
                    return (
                      <tr key={row.payment_type} className="border-b border-border-subtle">
                        <td className="py-2">{row.payment_type}</td>
                        {/* The transaction COUNT stays visible: it is not a
                            term of the drawer expectation, and hiding it would
                            cost the operator the "did my sale land?" check the
                            X report exists for. */}
                        <td className="py-2 text-right">{row.transaction_count}</td>
                        <td className="py-2 text-right">
                          {concealed ? CONCEALED : format(row.total_amount)}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
              {anyTenderConcealed && (
                <p className="mt-2 text-xs text-ink-muted">
                  {t('reports.dashboard.cashConcealed')}
                </p>
              )}
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
