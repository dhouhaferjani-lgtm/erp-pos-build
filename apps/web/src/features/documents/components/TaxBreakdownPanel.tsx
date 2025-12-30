import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { AlertCircle, Receipt, Percent, DollarSign } from 'lucide-react'
import { cn } from '@/lib/utils'
import { fetchTaxBreakdown, type TaxBreakdown } from '../api/taxApi'

interface TaxBreakdownPanelProps {
  documentId: string
  documentType: string
  documentStatus: 'draft' | 'confirmed' | 'posted' | 'cancelled'
  className?: string
}

export function TaxBreakdownPanel({
  documentId,
  documentType,
  documentStatus,
  className,
}: TaxBreakdownPanelProps) {
  const { t } = useTranslation('sales')

  const { data: taxBreakdown, isLoading, error } = useQuery<TaxBreakdown>({
    queryKey: ['tax-breakdown', documentId],
    queryFn: () => fetchTaxBreakdown(documentId),
    enabled: !!documentId,
  })

  if (isLoading) {
    return (
      <div className={cn('rounded-lg border bg-white p-6', className)}>
        <div className="flex items-center gap-2 mb-4">
          <Receipt className="h-5 w-5 text-gray-400" />
          <h3 className="font-semibold text-gray-900">
            {t('tax.breakdown.title')}
          </h3>
        </div>
        <div className="space-y-2">
          <div className="h-4 bg-gray-200 rounded animate-pulse"></div>
          <div className="h-4 bg-gray-200 rounded animate-pulse"></div>
          <div className="h-4 bg-gray-200 rounded animate-pulse"></div>
        </div>
      </div>
    )
  }

  if (error || !taxBreakdown) {
    return (
      <div className={cn('rounded-lg border border-red-200 bg-red-50 p-6', className)}>
        <div className="flex items-center gap-2 text-red-700">
          <AlertCircle className="h-5 w-5" />
          <p className="text-sm font-medium">
            {t('common.error')}
          </p>
        </div>
      </div>
    )
  }

  const hasStampDuty = parseFloat(taxBreakdown.stamp_duty_amount) > 0
  const hasDiscount = parseFloat(taxBreakdown.discount) > 0
  const hasTaxes = parseFloat(taxBreakdown.total_tax_amount) > 0
  const isInvoice = documentType === 'invoice'
  const isDraft = documentStatus === 'draft'
  const shouldShowStampWarning = isInvoice && isDraft

  return (
    <div className={cn('rounded-lg border bg-white p-6', className)}>
      {/* Header */}
      <div className="flex items-center gap-2 mb-4">
        <Receipt className="h-5 w-5 text-gray-700" />
        <h3 className="font-semibold text-gray-900">
          {t('tax.breakdown.title')}
        </h3>
      </div>

      {/* Stamp Duty Warning for Draft Invoices */}
      {shouldShowStampWarning && (
        <div className="mb-4 flex items-start gap-2 rounded-lg bg-blue-50 p-3">
          <AlertCircle className="h-4 w-4 text-blue-600 flex-shrink-0 mt-0.5" />
          <p className="text-sm text-blue-700">
            {t('tax.stampDuty.applied')}
          </p>
        </div>
      )}

      {/* No Taxes Message */}
      {!hasTaxes && (
        <div className="flex items-start gap-2 rounded-lg bg-gray-50 p-3">
          <AlertCircle className="h-4 w-4 text-gray-600 flex-shrink-0 mt-0.5" />
          <p className="text-sm text-gray-600">
            {t('tax.breakdown.noTaxes')}
          </p>
        </div>
      )}

      {/* Breakdown Details */}
      <div className="space-y-2">
        {/* Subtotal */}
        <div className="flex items-center justify-between py-2">
          <span className="text-sm text-gray-600">
            {t('tax.breakdown.subtotal')}
          </span>
          <span className="text-sm font-medium text-gray-900">
            {parseFloat(taxBreakdown.subtotal).toFixed(3)}
          </span>
        </div>

        {/* Discount (if applicable) */}
        {hasDiscount && (
          <div className="flex items-center justify-between py-2">
            <span className="text-sm text-gray-600">
              {t('tax.breakdown.discount')}
            </span>
            <span className="text-sm font-medium text-red-600">
              -{parseFloat(taxBreakdown.discount).toFixed(3)}
            </span>
          </div>
        )}

        {/* Line Tax (VAT) */}
        {parseFloat(taxBreakdown.line_tax_amount) > 0 && (
          <div className="flex items-center justify-between py-2">
            <div className="flex items-center gap-1.5">
              <Percent className="h-3.5 w-3.5 text-gray-500" />
              <span className="text-sm text-gray-600">
                {t('tax.breakdown.lineTax')}
              </span>
            </div>
            <span className="text-sm font-medium text-gray-900">
              {parseFloat(taxBreakdown.line_tax_amount).toFixed(3)}
            </span>
          </div>
        )}

        {/* Stamp Duty */}
        {hasStampDuty && (
          <div className="flex items-center justify-between py-2">
            <div className="flex items-center gap-1.5">
              <DollarSign className="h-3.5 w-3.5 text-gray-500" />
              <span className="text-sm text-gray-600">
                {t('tax.breakdown.stampDuty')}
              </span>
            </div>
            <span className="text-sm font-medium text-gray-900">
              {parseFloat(taxBreakdown.stamp_duty_amount).toFixed(3)}
            </span>
          </div>
        )}

        {/* Divider */}
        <div className="border-t border-gray-200 my-2"></div>

        {/* Total Tax */}
        {hasTaxes && (
          <div className="flex items-center justify-between py-2">
            <span className="text-sm font-medium text-gray-700">
              {t('tax.breakdown.totalTax')}
            </span>
            <span className="text-sm font-semibold text-gray-900">
              {parseFloat(taxBreakdown.total_tax_amount).toFixed(3)}
            </span>
          </div>
        )}

        {/* Total */}
        <div className="flex items-center justify-between py-2 bg-gray-50 -mx-6 px-6 rounded-lg">
          <span className="text-base font-semibold text-gray-900">
            {t('tax.breakdown.total')}
          </span>
          <span className="text-base font-bold text-gray-900">
            {parseFloat(taxBreakdown.total).toFixed(3)}
          </span>
        </div>
      </div>

      {/* Individual Tax Details */}
      {taxBreakdown.tax_details.length > 0 && (
        <div className="mt-6 pt-6 border-t border-gray-200">
          <h4 className="text-sm font-medium text-gray-700 mb-3">
            {t('common.details')}
          </h4>
          <div className="space-y-2">
            {taxBreakdown.tax_details.map((detail, index) => (
              <div
                key={index}
                className="flex items-center justify-between py-2 text-xs"
              >
                <div className="flex items-center gap-2">
                  {detail.tax_type === 'percentage' ? (
                    <Percent className="h-3 w-3 text-gray-400" />
                  ) : (
                    <DollarSign className="h-3 w-3 text-gray-400" />
                  )}
                  <span className="text-gray-600">
                    {detail.tax_name}
                    {detail.tax_type === 'percentage' && detail.tax_rate && (
                      <span className="ml-1 text-gray-500">
                        ({detail.tax_rate}%)
                      </span>
                    )}
                  </span>
                </div>
                <span className="font-medium text-gray-900">
                  {parseFloat(detail.tax_amount).toFixed(3)}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
