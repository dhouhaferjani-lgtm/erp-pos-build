import { useTranslation } from 'react-i18next'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface VatSpecialItemsProps {
  specialItems: Record<string, unknown>
  countryCode: string
}

interface SpecialItemConfig {
  key: string
  labelKey: string
}

const countryItemConfigs: Record<string, SpecialItemConfig[]> = {
  TN: [
    { key: 'timbre_fiscal_count', labelKey: 'finance:vatReporting.specialItems.timbreFiscalCount' },
    { key: 'timbre_fiscal_amount', labelKey: 'finance:vatReporting.specialItems.timbreFiscalAmount' },
    { key: 'retenue_source_amount', labelKey: 'finance:vatReporting.specialItems.retenueSourceAmount' },
  ],
  FR: [
    { key: 'credit_tva_previous', labelKey: 'finance:vatReporting.specialItems.creditTvaPrevious' },
  ],
  GB: [
    { key: 'eu_acquisitions_vat', labelKey: 'finance:vatReporting.specialItems.euAcquisitionsVat' },
  ],
}

function formatItemValue(value: unknown): string {
  if (value === null || value === undefined) return '-'
  if (typeof value === 'number') {
    return new Intl.NumberFormat('en-US', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(value)
  }
  if (typeof value === 'string') {
    const num = parseFloat(value)
    if (!isNaN(num)) {
      return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }).format(num)
    }
    return value
  }
  return String(value)
}

export function VatSpecialItems({ specialItems, countryCode }: VatSpecialItemsProps) {
  const { t } = useTranslation('finance')
  const configs = countryItemConfigs[countryCode]

  if (!configs || configs.length === 0) {
    return null
  }

  const hasValues = configs.some((config) => specialItems[config.key] !== undefined && specialItems[config.key] !== null)

  if (!hasValues) {
    return null
  }

  return (
    <div>
      <h3 className={`mb-3 text-sm font-semibold ${colorTokens.text.secondary}`}>
        {t('finance:vatReporting.specialItems.title')}
      </h3>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {configs.map((config) => {
          const value = specialItems[config.key]
          if (value === undefined || value === null) return null
          return (
            <div
              key={config.key}
              className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.page} p-3`}
            >
              <p className={`text-xs font-medium ${colorTokens.text.subtle}`}>
                {t(config.labelKey)}
              </p>
              <p className={`mt-1 text-lg font-semibold ${colorTokens.text.primary}`}>
                {formatItemValue(value)}
              </p>
            </div>
          )
        })}
      </div>
    </div>
  )
}
