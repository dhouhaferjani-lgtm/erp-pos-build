import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { AlertCircle } from 'lucide-react'
import { cn } from '@/lib/utils'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { fetchTaxBreakdown, type TaxBreakdown } from '../api/taxApi'
import { bccomp } from '@/lib/decimal'
import { formatCurrency, formatPercent } from '@/lib/format'
import { colorClasses, semanticColorTokens } from '@/lib/designTokens'
import type { ProformaPresentation } from '@/types/document'

export interface DocumentTotalsProps {
  documentId: string
  documentType: 'invoice' | 'quote' | 'sales_order' | 'credit_note'
  currency: string
  showBalanceDue?: boolean
  balanceDue?: number
  className?: string
  /**
   * C-F0w / SPEC §2.4 — render the VAT-FREE box (`Document.is_proforma`).
   *
   * This is the SERVER's predicate. It gates the whole tax rendering on its own:
   * even with no `proformaTotals` to draw, nothing below prints a rate, a net
   * subtotal or a tax amount, and the breakdown request is never sent.
   */
  isProforma?: boolean
  /** The rows a proforma's box prints, from `Document.proforma`. */
  proformaTotals?: ProformaPresentation | null
}

/**
 * Inline document totals component displaying:
 * - Subtotal
 * - Individual tax lines with rates (e.g., "TVA 19%: 190.000")
 * - Stamp duty (if applicable)
 * - Total
 * - Balance due (optional, for posted invoices)
 *
 * Supports multi-currency formatting:
 * - TND: 3 decimal places
 * - EUR, USD, etc: 2 decimal places
 */
export function DocumentTotals({
  documentId,
  documentType: _documentType,
  currency,
  showBalanceDue = false,
  balanceDue,
  className,
  isProforma = false,
  proformaTotals,
}: DocumentTotalsProps) {
  const { t } = useTranslation('sales')
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const { data: taxBreakdown, isLoading, error } = useQuery<TaxBreakdown>({
    queryKey: tenantScopedKey(['tax-breakdown', documentId]),
    queryFn: () => fetchTaxBreakdown(documentId),
    // A proforma never asks for a tax breakdown. Not merely "does not render it":
    // the VAT figures never enter the browser at all, so no later refactor can
    // surface them from cache.
    enabled: tenantId !== null && companyId !== null && !!documentId && !isProforma,
  })

  /**
   * Format against the `currency` this panel is GIVEN — it drives both the scale
   * and the locale.
   *
   * W-7 F-7: this used to call `formatNumber(amount, decimals)`, whose locale was
   * pinned to `en-US`, so the totals panel rendered `1,234.567` directly beneath
   * line cells rendered `1 234,567` by the currency-locale formatter. To a French
   * or Tunisian reader that is a 1 000x misread of the invoice total. The
   * currency code is appended by the markup below, so it is suppressed here.
   *
   * NOTE — that currency is today the COMPANY's, not the document's: all four
   * detail pages pass `currency={currentCompany?.currency ?? 'EUR'}`
   * (`InvoiceDetailPage:475`, `QuoteDetailPage:350`, `SalesOrderDetailPage:446`,
   * `CreditNoteDetailPage:313`), even though `Document.currency` exists. A
   * cross-currency document therefore renders in the company's scale with the
   * company's code appended. Pre-existing and unchanged here — and NOT fixed by
   * switching these four props alone, because the outstanding callout, the
   * payment history and the payment summary on the same pages read the company
   * currency too, so a partial switch would put two currencies in one viewport
   * (D6's own failure mode). Tracked page-wide in
   * docs/superpowers/tickets/2026-08-05-l4-web-followups.md.
   */
  const formatAmount = (amount: string | number): string => {
    return formatCurrency(amount, { currency, includeCurrency: false })
  }

  /**
   * C-F0w / SPEC §2.4 (F-95) — the PROFORMA box, mirroring
   * `documents/components/proforma_totals_rows.blade.php` row for row: an optional
   * stamp-duty row, an optional signed residual, and the estimated total. No net
   * subtotal, no per-rate rows, no tax row, no settlement rows.
   *
   * Printing a net subtotal beside a gross total is a VAT breakdown written as a
   * subtraction, and a proforma is not a statement of account.
   *
   * It returns BEFORE the breakdown query's loading and error arms on purpose:
   * the query is disabled here, so it stays pending forever, and nothing below
   * this line may ever render for an unsealed document.
   */
  if (isProforma) {
    // `!= null` throughout, not `!== null` (gate r1 W-6): the generated type says
    // these are non-optional, but a payload that OMITS a key would otherwise walk
    // straight into `formatAmount(undefined)`.
    return (
      <div className={cn('space-y-2', className)}>
        {proformaTotals != null && (
          <>
            {proformaTotals.stamp_duty != null && (
              <div className="flex items-center justify-between py-1">
                <span className={`text-sm ${semanticColorTokens.text.muted}`}>
                  {t('documents.proforma.stampDuty')}
                </span>
                <span
                  className={`text-sm font-medium ${semanticColorTokens.text.primary} font-mono`}
                >
                  {formatAmount(proformaTotals.stamp_duty)} {currency}
                </span>
              </div>
            )}

            {proformaTotals.discount != null ? (
              <div className="flex items-center justify-between py-1">
                <span className={`text-sm ${semanticColorTokens.text.muted}`}>
                  {t('documents.proforma.discount')}
                </span>
                <span
                  className={`text-sm font-medium ${semanticColorTokens.text.primary} font-mono`}
                >
                  -{formatAmount(proformaTotals.discount)} {currency}
                </span>
              </div>
            ) : proformaTotals.adjustment != null ? (
              /* An increase is never called a discount. */
              <div className="flex items-center justify-between py-1">
                <span className={`text-sm ${semanticColorTokens.text.muted}`}>
                  {t('documents.proforma.adjustment')}
                </span>
                <span
                  className={`text-sm font-medium ${semanticColorTokens.text.primary} font-mono`}
                >
                  {formatAmount(proformaTotals.adjustment)} {currency}
                </span>
              </div>
            ) : null}

            <div className={`border-t ${semanticColorTokens.border.default} my-2`}></div>

            <div className="flex items-center justify-between py-1">
              <span className={`text-base font-semibold ${semanticColorTokens.text.primary}`}>
                {t('documents.proforma.estimatedTotal')}
              </span>
              <span
                className={`text-base font-bold ${semanticColorTokens.text.primary} font-mono`}
              >
                {formatAmount(proformaTotals.estimated_total)} {currency}
              </span>
            </div>
          </>
        )}
      </div>
    )
  }

  if (isLoading) {
    return (
      <div className={cn('space-y-2', className)}>
        <div className={`h-4 ${colorClasses.bgGray200} rounded animate-pulse w-3/4 ms-auto`}></div>
        <div className={`h-4 ${colorClasses.bgGray200} rounded animate-pulse w-3/4 ms-auto`}></div>
        <div className={`h-4 ${colorClasses.bgGray200} rounded animate-pulse w-3/4 ms-auto`}></div>
      </div>
    )
  }

  if (error || !taxBreakdown) {
    return (
      <div className={cn(`rounded-lg border ${colorClasses.borderRed200} ${colorClasses.bgRed50} p-4`, className)}>
        <div className={`flex items-center gap-2 ${colorClasses.textRed700}`}>
          <AlertCircle className="h-4 w-4" />
          <p className="text-sm">
            {t('tax.breakdown.error')}
          </p>
        </div>
      </div>
    )
  }

  const hasStampDuty = bccomp(taxBreakdown.stamp_duty_amount, '0') > 0
  const hasTaxes = bccomp(taxBreakdown.total_tax_amount, '0') > 0
  const hasBalanceDue = showBalanceDue && balanceDue !== undefined && balanceDue > 0

  return (
    <div className={cn('space-y-2', className)}>
      {/* Subtotal */}
      <div className="flex items-center justify-between py-1">
        <span className={`text-sm ${colorClasses.textGray600}`}>
          {t('documents.subtotal')}
        </span>
        <span className={`text-sm font-medium ${colorClasses.textGray900} font-mono`}>
          {formatAmount(taxBreakdown.subtotal)} {currency}
        </span>
      </div>

      {/* Individual tax lines from tax_details */}
      {hasTaxes && taxBreakdown.tax_details.length > 0 && (
        <>
          {taxBreakdown.tax_details
            .filter((detail) => !detail.tax_name.toLowerCase().includes('timbre'))
            .map((detail, index) => {
              const displayName = detail.tax_type === 'percentage' && detail.tax_rate
                ? `${detail.tax_name} ${formatPercent(detail.tax_rate)}`
                : detail.tax_name

              return (
                <div key={index} className="flex items-center justify-between py-1">
                  <span className={`text-sm ${colorClasses.textGray600}`}>
                    {displayName}
                  </span>
                  <span className={`text-sm font-medium ${colorClasses.textGray900} font-mono`}>
                    {formatAmount(detail.tax_amount)} {currency}
                  </span>
                </div>
              )
            })}
        </>
      )}

      {/* Stamp duty (separate from other taxes) */}
      {hasStampDuty && (
        <div className="flex items-center justify-between py-1">
          <span className={`text-sm ${colorClasses.textGray600}`}>
            {t('tax.breakdown.stampDuty')}
          </span>
          <span className={`text-sm font-medium ${colorClasses.textGray900} font-mono`}>
            {formatAmount(taxBreakdown.stamp_duty_amount)} {currency}
          </span>
        </div>
      )}

      {/* Divider before total */}
      <div className={`border-t ${colorClasses.borderGray300} my-2`}></div>

      {/* Total */}
      <div className="flex items-center justify-between py-1">
        <span className={`text-base font-semibold ${colorClasses.textGray900}`}>
          {t('documents.total')}
        </span>
        <span className={`text-base font-bold ${colorClasses.textGray900} font-mono`}>
          {formatAmount(taxBreakdown.total)} {currency}
        </span>
      </div>

      {/* Balance due (for posted invoices) */}
      {hasBalanceDue && (
        <>
          <div className={`border-t ${colorClasses.borderGray200} my-2`}></div>
          <div className="flex items-center justify-between py-1">
            <span className={`text-sm font-medium ${colorClasses.textBlue700}`}>
              {t('invoices.balanceDue')}
            </span>
            <span className={`text-sm font-semibold ${colorClasses.textBlue700} font-mono`}>
              {formatAmount(balanceDue)} {currency}
            </span>
          </div>
        </>
      )}

      {/* No taxes message */}
      {!hasTaxes && (
        <div className={`flex items-start gap-2 rounded-lg ${colorClasses.bgGray50} p-3 mt-2`}>
          <AlertCircle className={`h-4 w-4 ${colorClasses.textGray600} flex-shrink-0 mt-0.5`} />
          <p className={`text-sm ${colorClasses.textGray600}`}>
            {t('tax.breakdown.noTaxes')}
          </p>
        </div>
      )}
    </div>
  )
}
