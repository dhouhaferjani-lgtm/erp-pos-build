import React from 'react'
import { useTranslation } from 'react-i18next'
import { ImageIcon, Loader2, RefreshCw, Sparkles, Barcode } from 'lucide-react'
import { colors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import { useCatalogBarcodeLookup } from '@/features/inventory/hooks/useCatalogBarcodeLookup'
import type { SuggestedProduct, LookupState } from '@/features/inventory/types/platform'

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

/*
 * Dark hero input surfaces. The navy band is gray-900 (theme token), but the
 * INSET input/refresh surfaces inside it have no close theme token — these
 * arbitrary hexes come straight from the mock (band #14283F = gray-900;
 * input bg #0F2138; input/refresh border #2C4A6E; input text #EAF1FA;
 * helper #6E86A5). Kept literal & commented per the task brief.
 */
const HERO_INPUT_BASE =
  'w-full rounded-[var(--radius-input)] border border-[#2C4A6E] bg-[#0F2138] text-[#EAF1FA] ' +
  'placeholder:text-[#5B7AA3] focus:border-[#5B7AA3] focus:outline-none focus:ring-0 ' +
  'disabled:cursor-not-allowed disabled:opacity-60'

/**
 * BarcodeHero — the dark navy "barcode-first" hero band of the product editor.
 *
 * Mirrors the mock's hero (gray-900 band, radius 12px): a 52×52 thumbnail
 * placeholder, a mono barcode input + a name input on dark surfaces, a green
 * "Synerivia · N fields" enrichment pill (hidden while idle — enrichment is
 * Stage 4), a 38×38 refresh button, and a full-width helper line.
 *
 * Barcode + name inputs are controlled and bound to the form fields upstream.
 *
 * When `onProductData` and `onLookupStateChange` are provided, the hero drives
 * the catalog barcode lookup engine (debounce + scanner detection) directly
 * from the barcode input value — eliminating the need for a separate
 * BarcodeLookupInput in the General section.
 */
export function BarcodeHero(props: {
  barcode: string
  onBarcodeChange: (value: string) => void
  name: string
  onNameChange: (value: string) => void
  status?: EnrichmentStatus | null
  onManualRefresh?: () => void
  disabled?: boolean
  /** Called when the catalog lookup returns a matched product. */
  onProductData?: (data: SuggestedProduct) => void
  /** Called when the lookup state transitions (idle → searching → found | not_found | error). */
  onLookupStateChange?: (state: LookupState) => void
}): React.JSX.Element {
  const {
    barcode,
    onBarcodeChange,
    name,
    onNameChange,
    status,
    onManualRefresh,
    disabled,
    onProductData,
    onLookupStateChange,
  } = props
  const { t } = useTranslation('catalog')

  // Wire up the lookup engine when callbacks are provided.
  // No-op callbacks are used so the hook is always called (Rules of Hooks).
  const { isSearching } = useCatalogBarcodeLookup({
    barcode,
    onProductData: onProductData ?? (() => undefined),
    onLookupStateChange: onLookupStateChange ?? (() => undefined),
    onScan: onBarcodeChange,
  })

  // Narrow: activeStatus is non-null and non-idle when the pill should be visible
  const activeStatus = status != null && status.state !== 'idle' ? status : null
  const isLoading = activeStatus !== null && activeStatus.state === 'loading'

  return (
    <div className={cn('rounded-xl px-3.5 py-3', colors.neutral[900])}>
      <div className="flex flex-wrap items-center gap-3">
        {/* 52×52 thumbnail placeholder */}
        <div
          aria-hidden="true"
          className="flex h-[52px] w-[52px] shrink-0 items-center justify-center rounded-[9px] border border-[#2C4A6E] bg-[#1E3A57] text-[#7E97B5]"
        >
          <ImageIcon className="h-5 w-5" />
        </div>

        {/* Barcode input — mono for scan-readability, fixed width */}
        <div className="relative w-60 shrink-0">
          <Barcode
            aria-hidden="true"
            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[#5B7AA3]"
          />
          <input
            type="text"
            value={barcode}
            onChange={(e) => {
              onBarcodeChange(e.target.value)
            }}
            placeholder={t('editor.hero.barcodePlaceholder')}
            disabled={disabled === true || isSearching}
            aria-label={t('editor.hero.barcodePlaceholder')}
            className={cn(HERO_INPUT_BASE, 'py-[9px] pl-9 pr-3 font-mono text-[15px]')}
          />
        </div>

        {/* Product name input — grows to fill remaining width */}
        <div className="min-w-[12rem] flex-1">
          <input
            type="text"
            value={name}
            onChange={(e) => {
              onNameChange(e.target.value)
            }}
            placeholder={t('editor.hero.namePlaceholder')}
            disabled={disabled}
            aria-label={t('editor.hero.namePlaceholder')}
            className={cn(HERO_INPUT_BASE, 'px-3 py-[9px] text-[15px] font-semibold')}
          />
        </div>

        {/* Enrichment status pill — hidden when idle or no status. Green
            "Synerivia · N fields" treatment; dark-surface arbitrary hexes. */}
        {activeStatus !== null && (
          <span
            className={cn(
              'inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-semibold',
              activeStatus.state === 'error'
                ? 'bg-[rgba(214,69,69,.18)] text-[#F0A6A6]'
                : 'bg-[rgba(31,138,91,.18)] text-[#7BE0B0]',
            )}
          >
            {activeStatus.state === 'loading' && (
              <>
                <Loader2 className="h-3 w-3 animate-spin" aria-hidden="true" />
                {t('editor.hero.enrichmentLoading')}
              </>
            )}
            {activeStatus.state === 'success' && (
              <>
                <Sparkles className="h-3 w-3" aria-hidden="true" />
                <span className="font-mono">
                  {t('editor.hero.enrichmentSuccess', { count: activeStatus.fieldsCount ?? 0 })}
                </span>
              </>
            )}
            {activeStatus.state === 'error' && t('editor.hero.enrichmentUnavailable')}
          </span>
        )}

        {/* 38×38 refresh button — only rendered when callback is provided */}
        {onManualRefresh != null && (
          <button
            type="button"
            aria-label={t('editor.hero.refreshLabel')}
            onClick={onManualRefresh}
            disabled={isLoading}
            className="flex h-[38px] w-[38px] shrink-0 items-center justify-center rounded-[var(--radius-input)] border border-[#2C4A6E] bg-transparent text-[#9FB4CE] transition-colors hover:border-[#5B7AA3] hover:text-white disabled:cursor-not-allowed disabled:opacity-60"
          >
            <RefreshCw className="h-4 w-4" aria-hidden="true" />
          </button>
        )}

        {/* Full-width helper line — 11px muted, indented to clear the thumbnail */}
        <p className="basis-full pl-16 text-[11px] text-[#6E86A5]">
          {t('editor.hero.helper')}
        </p>
      </div>
    </div>
  )
}
