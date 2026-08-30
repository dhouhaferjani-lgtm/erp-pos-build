import { useTranslation } from 'react-i18next'
import { AlertTriangle, CheckCircle } from 'lucide-react'
import { colorClasses } from '@/lib/designTokens'
import type { PartnerData } from '@/features/partners/types'

interface TaxExemptionWarning {
  type: 'missing_certificate' | 'expired_certificate' | 'expiring_soon'
  message: string
  severity: 'error' | 'warning'
}

/**
 * Narrowed view of the generated PartnerData DTO — not a re-declaration.
 * `exemption_certificate_path` is deliberately absent: no such field exists on
 * PartnerData (the column is `tax_exemption_certificate_media_id` and is not
 * serialised), so the old hand-rolled interface could only ever have rendered
 * an unconditional "Certificate: No" (merge-gate r1 FE-3/FE-12).
 */
type Partner = Pick<
  PartnerData,
  'id' | 'name' | 'tax_status' | 'exemption_reason' | 'exemption_valid_until'
>

interface Document {
  partner?: Partner
  partner_id?: string
  partner_name?: string
}

interface TaxExemptionNoticeProps {
  document: Document
  warnings?: TaxExemptionWarning[]
}

export function TaxExemptionNotice({ document, warnings = [] }: TaxExemptionNoticeProps) {
  const { t } = useTranslation('sales')

  // Don't show notice if partner is not exempt
  if (document.partner?.tax_status !== 'EXEMPT') {
    return null
  }

  const partner = document.partner
  const hasWarnings = warnings.length > 0
  const hasValidUntil = !!partner.exemption_valid_until

  // Determine overall status
  const hasErrors = warnings.some(w => w.severity === 'error')
  const severityColor = hasErrors
    ? `${colorClasses.borderRed200} ${colorClasses.bgRed50}`
    : hasWarnings
      ? `${colorClasses.borderYellow200} ${colorClasses.bgYellow50}`
      : `${colorClasses.borderBlue200} ${colorClasses.bgBlue50}`

  const iconColor = hasErrors
    ? colorClasses.textRed600
    : hasWarnings
      ? colorClasses.textYellow600
      : colorClasses.textBlue600

  return (
    <div className={`rounded-lg border p-4 ${severityColor}`}>
      <div className="flex items-start gap-3">
        <div className={`flex-shrink-0 ${iconColor}`}>
          {hasErrors || hasWarnings ? (
            <AlertTriangle className="h-5 w-5" />
          ) : (
            <CheckCircle className="h-5 w-5" />
          )}
        </div>

        <div className="flex-1">
          <h3 className={`text-sm font-semibold ${colorClasses.textGray900}`}>
            {t('partners.taxInfo.statusExempt')}
          </h3>

          {/* Exemption Reason */}
          {partner.exemption_reason && (
            <p className={`mt-1 text-sm ${colorClasses.textGray700}`}>
              <span className="font-medium">{t('partners.taxInfo.exemptionReason')}:</span>{' '}
              {partner.exemption_reason}
            </p>
          )}

          {/* Certificate presence is not readable from PartnerData, so it is not
              claimed here. */}
          <div className="mt-2 space-y-1">
            {hasValidUntil && partner.exemption_valid_until && (
              <p className={`text-sm ${colorClasses.textGray600}`}>
                {t('partners.taxInfo.validUntil')}: {new Date(partner.exemption_valid_until).toLocaleDateString()}
              </p>
            )}
          </div>

          {/* Warnings */}
          {warnings.length > 0 && (
            <div className="mt-3 space-y-1">
              {warnings.map((warning, index) => (
                <div
                  key={index}
                  className={`flex items-start gap-2 text-sm ${
                    warning.severity === 'error' ? colorClasses.textRed700 : colorClasses.textYellow700
                  }`}
                >
                  <AlertTriangle className="h-4 w-4 mt-0.5 flex-shrink-0" />
                  <span>{warning.message}</span>
                </div>
              ))}
            </div>
          )}

          {/* Tax Mention */}
          {!hasErrors && (
            <p className={`mt-3 text-xs ${colorClasses.textGray600} italic`}>
              {t('documents.taxExemptionMention', {
                partner: partner.name,
                reason: partner.exemption_reason || t('partners.taxInfo.statusExempt')
              })}
            </p>
          )}
        </div>
      </div>
    </div>
  )
}
