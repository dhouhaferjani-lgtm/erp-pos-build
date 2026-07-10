import { useCallback, useEffect, useId, useRef, useState, type KeyboardEvent } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Search, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../lib/api'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import { borderColors, colors, textColors, tokens } from '../../../lib/designTokens'
import { useBarcodeScanner } from '../../../hooks/useBarcodeScanner'
import { ProductCell } from './ProductCell'
import { useProductLineLookup, type ProductLineLookupOutcome, type ProductLineProduct } from './useProductLineLookup'

interface ProductsResponse {
  data: ProductLineProduct[]
}

export type LineEntryMatchedCodeType = 'product_barcode' | 'product_sku' | 'variant_barcode' | 'variant_sku'

export interface LineItemEntryAddMeta {
  source: 'search' | 'scan'
  incrementBy: number
  variantId?: string | null
  code?: string
  matchedCodeType?: LineEntryMatchedCodeType
}

export interface LineItemEntryBarProps {
  onAddProduct: (product: ProductLineProduct, meta: LineItemEntryAddMeta) => void
  onCreateFromCode?: (code: string) => void
  onRequiresVariant?: (product: ProductLineProduct, code: string) => void
  onMultipleMatches?: (outcome: ProductLineLookupOutcome) => void
  /**
   * Fired when a scanned code resolves to nothing. Distinct from
   * `onCreateFromCode`: consumers that cannot create products in-context (e.g.
   * stock transfers) use this to surface an error instead of a create modal.
   */
  onNotFound?: (code: string) => void
  /**
   * Transfer-specific guard grafted for the stock-transfer flow: return `false`
   * to veto a search-add or scan before it happens (e.g. no source location
   * selected yet). Returning `true`/`undefined` allows the add.
   */
  onBeforeAdd?: (source: LineItemEntryAddMeta['source']) => boolean
  disabled?: boolean
}

export function LineItemEntryBar({
  onAddProduct,
  onCreateFromCode,
  onRequiresVariant,
  onMultipleMatches,
  onNotFound,
  onBeforeAdd,
  disabled = false,
}: LineItemEntryBarProps) {
  const { t } = useTranslation(['sales'])
  const [query, setQuery] = useState('')
  const [isOpen, setIsOpen] = useState(false)
  const [highlightedIndex, setHighlightedIndex] = useState(0)
  const [message, setMessage] = useState<string | null>(null)
  const containerRef = useRef<HTMLDivElement | null>(null)
  const inputRef = useRef<HTMLInputElement | null>(null)
  const listboxId = useId()
  const { enqueueScan } = useProductLineLookup()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const trimmedQuery = query.trim()
  const { data: productsData, isLoading } = useQuery({
    queryKey: tenantScopedKey(['line-entry-products', trimmedQuery]),
    queryFn: async () => {
      const response = await api.get<ProductsResponse>('/products', {
        params: trimmedQuery !== '' ? { search: trimmedQuery } : undefined,
      })
      return response.data
    },
    enabled: !disabled && isOpen && tenantId !== null && companyId !== null,
    staleTime: 30000,
  })

  const products = productsData?.data ?? []

  useEffect(() => {
    function handlePointerDown(event: PointerEvent) {
      if (containerRef.current !== null && !containerRef.current.contains(event.target as Node)) {
        setIsOpen(false)
      }
    }

    document.addEventListener('pointerdown', handlePointerDown)
    return () => {
      document.removeEventListener('pointerdown', handlePointerDown)
    }
  }, [])

  const focusInput = useCallback(() => {
    window.requestAnimationFrame(() => {
      inputRef.current?.focus()
    })
  }, [])

  const resetAfterAdd = useCallback(() => {
    setQuery('')
    setIsOpen(false)
    setMessage(null)
    focusInput()
  }, [focusInput])

  const addProduct = useCallback((
    product: ProductLineProduct,
    source: LineItemEntryAddMeta['source'],
    incrementBy = 1,
    variantId: string | null = null,
    extra: { code?: string; matchedCodeType?: LineEntryMatchedCodeType } = {},
  ) => {
    onAddProduct(product, { source, incrementBy, variantId, ...extra })
    resetAfterAdd()
  }, [onAddProduct, resetAfterAdd])

  // Search-add commit path. Grafted `onBeforeAdd('search')` guard vetoes the add
  // (e.g. transfer without a source location) and simply resets the field.
  const commitSearchAdd = useCallback((product: ProductLineProduct) => {
    if (onBeforeAdd?.('search') === false) {
      resetAfterAdd()
      return
    }
    addProduct(product, 'search')
  }, [addProduct, onBeforeAdd, resetAfterAdd])

  const handleLookupOutcome = useCallback((outcome: ProductLineLookupOutcome, code: string) => {
    const incrementBy = outcome.incrementBy ?? 1

    if (outcome.kind === 'product') {
      if (outcome.product.has_variants === true) {
        setMessage(t('sales:lineItems.entry.requiresVariant'))
        onRequiresVariant?.(outcome.product, code)
        return
      }
      addProduct(outcome.product, 'scan', incrementBy, null, { code, matchedCodeType: outcome.matched_code_type })
      return
    }

    if (outcome.kind === 'variant') {
      addProduct(outcome.product, 'scan', incrementBy, outcome.variant.id, { code, matchedCodeType: outcome.matched_code_type })
      return
    }

    if (outcome.kind === 'multiple') {
      onMultipleMatches?.(outcome)
      return
    }

    setMessage(t('sales:lineItems.entry.productNotFound', { code: outcome.code }))
    onNotFound?.(outcome.code)
    onCreateFromCode?.(outcome.code)
  }, [addProduct, onCreateFromCode, onMultipleMatches, onNotFound, onRequiresVariant, t])

  const resolveScan = useCallback((code: string) => {
    const trimmed = code.trim()
    if (trimmed === '') return

    // Grafted scan guard: veto before we ever hit the code resolver so a
    // transfer without a source never fires a resolve-code request.
    if (onBeforeAdd?.('scan') === false) return

    void enqueueScan(trimmed).then((outcome) => {
      handleLookupOutcome(outcome, trimmed)
    })
  }, [enqueueScan, handleLookupOutcome, onBeforeAdd])

  useBarcodeScanner({
    enabled: !disabled,
    ignoreInputElements: true,
    onScan: resolveScan,
  })

  const handleKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setHighlightedIndex((current) => Math.min(current + 1, Math.max(products.length - 1, 0)))
      return
    }

    if (event.key === 'ArrowUp') {
      event.preventDefault()
      setHighlightedIndex((current) => Math.max(current - 1, 0))
      return
    }

    if (event.key === 'Escape') {
      event.preventDefault()
      setIsOpen(false)
      return
    }

    if (event.key !== 'Enter' && event.key !== 'Tab') return

    if (isOpen && products.length > 0) {
      event.preventDefault()
      event.stopPropagation()
      commitSearchAdd(products[highlightedIndex] ?? products[0])
      return
    }

    if (trimmedQuery === '') {
      if (event.key === 'Enter') event.preventDefault()
      return
    }

    event.preventDefault()
    event.stopPropagation()

    resolveScan(trimmedQuery)
  }

  return (
    <div ref={containerRef} className="relative w-full">
      <div className={`flex items-center gap-2 rounded-md border ${borderColors.default} ${colors.white} px-3 py-2`}>
        <Search className={`h-4 w-4 shrink-0 ${textColors.disabled}`} aria-hidden="true" />
        <input
          ref={inputRef}
          type="text"
          role="combobox"
          aria-expanded={isOpen}
          aria-controls={isOpen ? listboxId : undefined}
          aria-label={t('sales:lineItems.entry.placeholder')}
          placeholder={t('sales:lineItems.entry.placeholder')}
          value={query}
          disabled={disabled}
          onFocus={() => {
            setIsOpen(true)
          }}
          onChange={(event) => {
            setQuery(event.target.value)
            setIsOpen(true)
            setHighlightedIndex(0)
            setMessage(null)
          }}
          onKeyDown={handleKeyDown}
          className={`min-w-0 flex-1 border-0 bg-transparent p-0 text-sm ${textColors.primary} placeholder:${textColors.disabled} focus:outline-none focus:ring-0`}
        />
        {query !== '' && (
          <button
            type="button"
            onClick={() => {
              setQuery('')
              setMessage(null)
              focusInput()
            }}
            className={`${textColors.disabled} ${textColors.hoverSecondary}`}
            aria-label={t('common:clear')}
          >
            <X className="h-4 w-4" />
          </button>
        )}
      </div>

      {message !== null && (
        <p className={tokens.helperText.base}>{message}</p>
      )}

      {isOpen && (
        <div
          id={listboxId}
          role="listbox"
          className={`absolute start-0 top-full z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-md border ${borderColors.light} ${colors.white} shadow-lg`}
        >
          {isLoading ? (
            <div className={`p-3 text-sm ${textColors.disabled}`}>{t('sales:lineItems.loading')}</div>
          ) : products.length === 0 ? (
            <div className={`p-3 text-sm ${textColors.disabled}`}>{t('sales:lineItems.noProductsFound')}</div>
          ) : (
            <ul className={`divide-y ${borderColors.divideLight}`}>
              {products.map((product, index) => (
                <li key={product.id}>
                  <button
                    type="button"
                    role="option"
                    aria-selected={index === highlightedIndex}
                    aria-label={`${product.sku ?? ''} ${product.name}`.trim()}
                    onMouseEnter={() => {
                      setHighlightedIndex(index)
                    }}
                    onClick={() => {
                      commitSearchAdd(product)
                    }}
                    className={`w-full px-3 py-2 ${index === highlightedIndex ? colors.neutral[50] : colors.white} ${colors.hover.gray50}`}
                  >
                    <ProductCell product={product} />
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  )
}
