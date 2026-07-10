import { useTranslation } from 'react-i18next'
import { AlertTriangle, CheckCircle, FileText } from 'lucide-react'
import { colorClasses } from '@/lib/designTokens'

interface TaxExemptionWarning {
  type: 'missing_certificate' | 'expired_certificate' | 'expiring_soon'
  message: string
  severity: 'error' | 'warning'
}

interface Partner {
  id: string
  name: string
  tax_status: 'REGISTERED' | 'NON_REGISTERED' | 'EXEMPT'
  exemption_reason?: string | null
  exemption_certificate_path?: string | null
  exemption_valid_until?: string | null
}

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
  if (!document.partner || document.partner.tax_status !== 'EXEMPT') {
    return null
  }

  const partner = document.partner
  const hasWarnings = warnings.length > 0
  const hasCertificate = !!partner.exemption_certificate_path
  const hasValidUntil = !!partner.exemption_valid_until

  // Determine overall status
  const hasErrors = warnings.some(w => w.severity === 'error')
  const severityColor = hasErrors
    ? `${colorClasses.borderRed200} ${colorClasses.bgRed50}`
    : hasWarnings
      ? `${colorClasses.borderYellow200} ${colorClasses.bgYellow50}`
      : `${colorClasses.borderBlue200} ${colorClasses.bgBlue50}`

  const iconColor = hasErrors
    ? `${colorClasses.textRed600}`
    : hasWarnings
      ? `${colorClasses.textYellow600}`
      : `${colorClasses.textBlue600}`

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

          {/* Certificate Info */}
          <div className="mt-2 space-y-1">
            {hasCertificate ? (
              <div className={`flex items-center gap-2 text-sm ${colorClasses.textGray600}`}>
                <FileText className="h-4 w-4" />
                <span>{t('partners.taxInfo.certificate')}: {t('common:yes')}</span>
              </div>
            ) : (
              <div className={`flex items-center gap-2 text-sm ${colorClasses.textGray600}`}>
                <FileText className="h-4 w-4" />
                <span>{t('partners.taxInfo.certificate')}: {t('common:no')}</span>
              </div>
            )}

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
                    warning.severity === 'error' ? `${colorClasses.textRed700}` : `${colorClasses.textYellow700}`
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
