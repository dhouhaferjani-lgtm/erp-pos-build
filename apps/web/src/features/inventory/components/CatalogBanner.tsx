import { useTranslation } from 'react-i18next'
import { tokens } from '@/lib/designTokens'
import type { LookupState } from '../types/platform'

interface CatalogBannerProps {
  state: LookupState
  confidenceTier?: string | null
}

export function CatalogBanner({ state, confidenceTier }: CatalogBannerProps) {
  const { t } = useTranslation('inventory')

  if (state === 'idle' || state === 'searching') {
    return null
  }

  if (state === 'found') {
    return (
      <div
        className={`flex items-center gap-2 rounded-lg border px-3.5 py-2.5 text-sm ${tokens.alert.success}`}
      >
        <span className="text-base">{'\u2713'}</span>
        <span className="font-medium">{t('barcodeLookup.catalogFound')}</span>
        {confidenceTier && (
          <span className="ml-auto text-xs opacity-70">
            {t('barcodeLookup.confidence', { tier: confidenceTier })}
          </span>
        )}
      </div>
    )
  }

  if (state === 'not_found') {
    return (
      <div
        className={`flex items-center gap-2 rounded-lg border px-3.5 py-2.5 text-sm ${tokens.alert.warning}`}
      >
        <span className="text-base">{'\u2139'}</span>
        <span className="font-medium">{t('barcodeLookup.notInCatalog')}</span>
      </div>
    )
  }

  return (
    <div
      className={`flex items-center gap-2 rounded-lg border px-3.5 py-2.5 text-sm ${tokens.alert.error}`}
    >
      <span className="text-base">{'\u26A0'}</span>
      <span className="font-medium">{t('barcodeLookup.catalogUnavailable')}</span>
    </div>
  )
}
