import React from 'react'
import { useTranslation } from 'react-i18next'
import { Loader2, RefreshCw } from 'lucide-react'
import { Input } from '@/components/atoms/Input/Input'
import { tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'

/**
 * Enrichment state for the Synerivia enrichment service slot.
 * - idle    : no enrichment attempted yet (pill hidden)
 * - loading : enrichment request in-flight
 * - success : enrichment completed — fieldsCount available
 * - error   : enrichment service unavailable
 */
export type EnrichmentState = 'idle' | 'loading' | 'success' | 'error'

/** Status descriptor passed to BarcodeHero. */
export interface EnrichmentStatus {
  state: EnrichmentState
  /** Number of fields enriched — populated on `success` state. */
  fieldsCount?: number
}

/**
 * BarcodeHero — compact (≤64px tall) hero bar for the product editor.
 *
 * Direction-A "Crisp / Operational":
 * - Flat/hairline, mono numerics, navy structure.
 * - Orange (secondary-500) is the ONE reserved accent — NOT used here; the
 *   hero uses gray badge tokens only.
 * - Barcode input comes FIRST with `font-mono` for scan-readability.
 * - Status pill is only visible when `status.state !== 'idle'`.
 * - Refresh button only rendered when `onManualRefresh` is provided.
 */
export function BarcodeHero(props: {
  barcode: string
  onBarcodeChange: (value: string) => void
  name: string
  onNameChange: (value: string) => void
  status?: EnrichmentStatus | null
  onManualRefresh?: () => void
  disabled?: boolean
}): React.JSX.Element {
  const { barcode, onBarcodeChange, name, onNameChange, status, onManualRefresh, disabled } = props
  const { t } = useTranslation('catalog')

  // Narrow: activeStatus is non-null and non-idle when the pill should be visible
  const activeStatus = status != null && status.state !== 'idle' ? status : null
  const isLoading = activeStatus !== null && activeStatus.state === 'loading'

  // Badge color: red for error, gray for loading/success
  const badgeVariant = activeStatus?.state === 'error' ? tokens.badge.red : tokens.badge.gray

  return (
    <div className="flex flex-row items-center gap-3 py-2">
      {/* Barcode input — mono font for scan-readability; fixed width so name can grow */}
      <Input
        type="text"
        value={barcode}
        onChange={(e) => {
          onBarcodeChange(e.target.value)
        }}
        placeholder={t('editor.hero.barcodePlaceholder')}
        disabled={disabled}
        className={cn('w-48 shrink-0 font-mono')}
        aria-label={t('editor.hero.barcodePlaceholder')}
      />

      {/* Product name input — grows to fill remaining width */}
      <Input
        type="text"
        value={name}
        onChange={(e) => {
          onNameChange(e.target.value)
        }}
        placeholder={t('editor.hero.namePlaceholder')}
        disabled={disabled}
        className="min-w-0 flex-1"
        aria-label={t('editor.hero.namePlaceholder')}
      />

      {/* Enrichment status pill — hidden when idle or no status */}
      {activeStatus !== null && (
        <span className={cn(tokens.badge.base, badgeVariant)}>
          {activeStatus.state === 'loading' && (
            <>
              <Loader2 className="mr-1 h-3 w-3 animate-spin" aria-hidden="true" />
              {t('editor.hero.enrichmentLoading')}
            </>
          )}
          {activeStatus.state === 'success' && (
            <span className="font-mono">
              {t('editor.hero.enrichmentSuccess', { count: activeStatus.fieldsCount ?? 0 })}
            </span>
          )}
          {activeStatus.state === 'error' && t('editor.hero.enrichmentUnavailable')}
        </span>
      )}

      {/* Manual refresh button — only rendered when callback is provided */}
      {onManualRefresh != null && (
        <button
          type="button"
          aria-label={t('editor.hero.refreshLabel')}
          onClick={onManualRefresh}
          disabled={isLoading}
          className={cn(
            tokens.button.base,
            tokens.button.ghost,
            tokens.button.sizes.sm,
          )}
        >
          <RefreshCw className="h-4 w-4" aria-hidden="true" />
        </button>
      )}
    </div>
  )
}
