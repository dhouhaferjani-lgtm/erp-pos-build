import { useTranslation } from 'react-i18next'
import type { VerticalType } from '../types/compositeItem'

/**
 * Hook that returns a label getter function based on the vertical type.
 * Falls back to generic labels if a vertical-specific key doesn't exist.
 */
export function useVerticalLabels(verticalType: VerticalType = 'generic') {
  const { t } = useTranslation('catalog')

  return (key: string): string => {
    const verticalKey = `vertical.${verticalType}.${key}`
    const genericValue = t(key)
    return t(verticalKey, { defaultValue: genericValue })
  }
}
