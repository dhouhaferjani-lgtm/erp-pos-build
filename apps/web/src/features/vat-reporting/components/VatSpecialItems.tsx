import { useTranslation } from 'react-i18next'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { formatDecimalAmount } from '@/lib/format'

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

// m-6 (2026-08-06 gate): these values mix money (timbre_fiscal_amount,
// retenue_source_amount, credit_tva_previous, eu_acquisitions_vat) and
// plain counts (timbre_fiscal_count) with no type tag to tell them apart
// at this layer, so a currency-scaled formatter can't be applied uniformly
// here. The precision-safe fix keeps the exact prior 'en-US'/2dp display
// behaviour but routes the string branch through the canonical
// float-free path (lib/format's formatDecimalAmount, Big.js/BigInt
// internally) instead of `parseFloat` -- rule 19: no float ever touches a
// numeric-string amount, even for a value that may turn out to be a count.
function formatItemValue(value: unknown): string {
  if (value === null || value === undefined) return '-'
  if (typeof value === 'number') {
    return formatDecimalAmount(value, 'en-US', 2)
  }
  if (typeof value === 'string') {
    const isNumeric = value.trim() !== '' && !isNaN(Number(value))
    if (isNumeric) {
      return formatDecimalAmount(value, 'en-US', 2)
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
