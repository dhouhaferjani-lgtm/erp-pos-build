import { useTranslation } from 'react-i18next'
import { useCompanyConfig } from '@/contexts/CompanyConfigContext'
import type { VerticalType } from '../types/compositeItem'

/**
 * Maps a company vertical (from tenant config) to a catalog VerticalType.
 */
export function companyVerticalToCatalog(companyVertical: string | undefined): VerticalType {
  switch (companyVertical) {
    case 'restaurant':
    case 'coffee_shop':
      return 'fnb'
    case 'fashion':
      return 'sewing'
    default:
      return 'generic'
  }
}

/**
 * Hook that returns a label getter function based on the vertical type.
 * Falls back to generic labels if a vertical-specific key doesn't exist.
 */
export function useVerticalLabels(verticalType: VerticalType = 'generic') {
  const { t } = useTranslation('catalog')

  const getLabel = (key: string): string => {
    const verticalKey = `vertical.${verticalType}.${key}`
    const genericValue = t(key)
    return t(verticalKey, { defaultValue: genericValue })
  }

  return getLabel
}

/**
 * Convenience hook that derives vertical labels from the company config.
 * Use this in list pages where there's no specific item to get the vertical from.
 */
export function useCompanyVerticalLabels() {
  const { config } = useCompanyConfig()
  const catalogVertical = companyVerticalToCatalog(config?.vertical)
  return useVerticalLabels(catalogVertical)
}
