import { useTranslation } from 'react-i18next'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { formatDecimalAmount } from '@/lib/format'
import { useCurrency } from '@/hooks/useCurrency'

interface VatSpecialItemsProps {
  specialItems: Record<string, unknown>
  countryCode: string
}

interface SpecialItemConfig {
  key: string
  labelKey: string
  kind: 'money' | 'count'
}

// MAJOR-2 (2026-08-07 FE gate, docs/superpowers/reviews/2026-08-07-r2g-fe-gate.md):
// aligned to the REAL per-country keys the backend strategies emit. The
// prior key set (credit_tva_previous, eu_acquisitions_vat, timbre_fiscal_*)
// had ZERO overlap with what any strategy actually returns, so this panel
// never rendered in any country -- `hasValues` below was always false. See
// apps/api/app/Modules/Taxation/Infrastructure/Strategies/TunisiaVatStrategy.php:118-122,
// FranceVatStrategy.php:114-117, UkVatStrategy.php:131-134.
const countryItemConfigs: Record<string, SpecialItemConfig[]> = {
  TN: [
    { key: 'stamp_duty_count', labelKey: 'finance:vatReporting.specialItems.timbreFiscalCount', kind: 'count' },
    { key: 'stamp_duty_total', labelKey: 'finance:vatReporting.specialItems.timbreFiscalAmount', kind: 'money' },
    { key: 'retenue_source_total', labelKey: 'finance:vatReporting.specialItems.retenueSourceAmount', kind: 'money' },
  ],
  FR: [
    { key: 'intra_community_acquisitions', labelKey: 'finance:vatReporting.specialItems.intraCommunityAcquisitions', kind: 'money' },
    { key: 'intra_community_supplies', labelKey: 'finance:vatReporting.specialItems.intraCommunitySupplies', kind: 'money' },
  ],
  GB: [
    { key: 'ec_supplies', labelKey: 'finance:vatReporting.specialItems.ecSupplies', kind: 'money' },
    { key: 'ec_acquisitions', labelKey: 'finance:vatReporting.specialItems.ecAcquisitions', kind: 'money' },
  ],
}

// MINOR-2 (2026-08-07 FE gate): a plain decimal-string regex probe --
// never Number()/parseFloat, even as a validity check. Junk input like
// "12abc" now renders as the literal string instead of silently
// prefix-parsing to "12.00" the way parseFloat used to.
const NUMERIC_STRING_PATTERN = /^-?\d+(\.\d+)?$/

function isNumericString(value: string): boolean {
  return NUMERIC_STRING_PATTERN.test(value.trim())
}

/**
 * MAJOR-1 (2026-08-07 FE gate): money fields render through the SAME
 * currency-driven, float-free path as every sibling on this screen
 * (VatSummaryCards, VatBreakdownTable) -- a TND tenant now sees millimes
 * here too, instead of the hardcoded en-US/2dp formatter that disagreed
 * with the rest of the page (the exact W-7 F-7 bug class). Count fields
 * (MINOR-3: "3.00 stamps" was wrong) render as plain grouped integers,
 * never through the currency formatter.
 */
function formatSpecialItem(
  value: unknown,
  kind: 'money' | 'count',
  formatMoney: (amount: string | number, options?: { symbol?: boolean }) => string,
  locale: string,
): string {
  if (value === null || value === undefined) return '-'

  if (typeof value === 'number') {
    return kind === 'money' ? formatMoney(value, { symbol: false }) : formatDecimalAmount(value, locale, 0)
  }

  if (typeof value === 'string') {
    if (!isNumericString(value)) return value

    return kind === 'money' ? formatMoney(value, { symbol: false }) : formatDecimalAmount(value, locale, 0)
  }

  return String(value)
}

export function VatSpecialItems({ specialItems, countryCode }: VatSpecialItemsProps) {
  const { t } = useTranslation('finance')
  const { format: formatMoney, locale } = useCurrency()
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
                {formatSpecialItem(value, config.kind, formatMoney, locale)}
              </p>
            </div>
          )
        })}
      </div>
    </div>
  )
}
