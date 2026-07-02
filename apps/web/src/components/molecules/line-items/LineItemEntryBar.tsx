import { useId, useMemo, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { PackageSearch, Search } from 'lucide-react'
import { apiGet } from '@/lib/api'
import { useDebouncedValue } from '@/lib/hooks'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useBarcodeScanner } from '@/hooks/useBarcodeScanner'
import { borderColors, colors, textColors, tokens } from '@/lib/designTokens'
import type { ProductPickerValue } from '@/components/molecules/pickers/ProductPicker'

export interface LineEntryVariantValue {
  id: string
  sku?: string | null
  name_suffix?: string | null
}

export interface LineEntryAddRequest {
  product: ProductPickerValue
  variantId: string | null
  source: 'search' | 'scan' | 'variant-chooser'
  code?: string | undefined
  matchedCodeType?: string | undefined
}

export interface LineEntryVariantChoiceRequest {
  product: ProductPickerValue
  variants: LineEntryVariantValue[]
  code?: string | undefined
  matchedCodeType?: string | undefined
}

type ResolveCodeResponse =
  | {
      kind: 'product'
      product: ProductPickerValue
      matched_code_type?: string
    }
  | {
      kind: 'variant'
      product: ProductPickerValue
      variant: LineEntryVariantValue
      matched_code_type?: string
    }
  | {
      kind: 'requires_variant'
      product: ProductPickerValue
      variants: LineEntryVariantValue[]
      matched_code_type?: string
    }
  | {
      kind: 'multiple'
      candidates?: ProductPickerValue[]
      matched_code_type?: string
    }
  | {
      kind: 'not_found'
      matched_code_type?: string
    }

interface ProductListResponse {
  data: ProductPickerValue[]
}

export interface LineItemEntryBarLabels {
  search: string
  browseCatalog: string
  loading: string
  empty: string
}

export interface LineItemEntryBarProps {
  labels: LineItemEntryBarLabels
  context?: 'document' | 'transfer'
  sourceLocationId?: string
  productType?: 'part' | 'consumable' | 'good' | 'all'
  onAddProduct: (request: LineEntryAddRequest) => void
  onRequiresVariant?: (request: LineEntryVariantChoiceRequest) => void
  onBeforeAdd?: (source: LineEntryAddRequest['source']) => boolean
  onBrowseCatalog?: () => void
  onNotFound?: (code: string) => void
}

function productLabel(product: ProductPickerValue): string {
  return `${product.sku} ${product.name}`
}

export function LineItemEntryBar({
  labels,
  context = 'document',
  sourceLocationId,
  productType = 'all',
  onAddProduct,
  onRequiresVariant,
  onBeforeAdd,
  onBrowseCatalog,
  onNotFound,
}: LineItemEntryBarProps) {
  const inputId = useId()
  const listboxId = useId()
  const inputRef = useRef<HTMLInputElement>(null)
  const [query, setQuery] = useState('')
  const [isOpen, setIsOpen] = useState(false)
  const [activeIndex, setActiveIndex] = useState(0)
  const debouncedQuery = useDebouncedValue(query, 250)
  const trimmedQuery = debouncedQuery.trim()

  const productsQuery = useQuery({
    queryKey: tenantScopedKey(['line-entry', 'products', productType, trimmedQuery]),
    enabled: isOpen && trimmedQuery.length > 0,
    queryFn: () => apiGet<ProductListResponse>('/products', {
      search: trimmedQuery,
      per_page: '20',
      is_active: 'true',
      ...(productType !== 'all' ? { type: productType } : {}),
    }),
  })

  const results = useMemo(() => productsQuery.data?.data ?? [], [productsQuery.data])

  const resetAfterAdd = (): void => {
    setQuery('')
    setIsOpen(false)
    setActiveIndex(0)
    window.setTimeout(() => inputRef.current?.focus(), 0)
  }

  const addSearchProduct = (product: ProductPickerValue): void => {
    if (onBeforeAdd?.('search') === false) {
      resetAfterAdd()
      return
    }
    onAddProduct({ product, variantId: null, source: 'search' })
    resetAfterAdd()
  }

  const handleScan = async (code: string): Promise<void> => {
    if (onBeforeAdd?.('scan') === false) {
      return
    }

    const response = await apiGet<ResolveCodeResponse>('/line-entry/resolve-code', {
      code,
      context,
      ...(sourceLocationId !== undefined && sourceLocationId !== '' ? { source_location_id: sourceLocationId } : {}),
    })

    if (response.kind === 'product') {
      onAddProduct({
        product: response.product,
        variantId: null,
        source: 'scan',
        code,
        matchedCodeType: response.matched_code_type,
      })
      return
    }

    if (response.kind === 'variant') {
      onAddProduct({
        product: response.product,
        variantId: response.variant.id,
        source: 'scan',
        code,
        matchedCodeType: response.matched_code_type,
      })
      return
    }

    if (response.kind === 'requires_variant') {
      onRequiresVariant?.({
        product: response.product,
        variants: response.variants,
        code,
        matchedCodeType: response.matched_code_type,
      })
      return
    }

    if (response.kind === 'not_found') {
      onNotFound?.(code)
    }
  }

  useBarcodeScanner({
    onScan: (code) => {
      void handleScan(code)
    },
  })

  const handleKeyDown = (event: React.KeyboardEvent<HTMLInputElement>): void => {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setIsOpen(true)
      setActiveIndex((index) => Math.min(index + 1, Math.max(results.length - 1, 0)))
      return
    }
    if (event.key === 'ArrowUp') {
      event.preventDefault()
      setActiveIndex((index) => Math.max(index - 1, 0))
      return
    }
    if (event.key === 'Escape') {
      event.preventDefault()
      setIsOpen(false)
      return
    }
    if (event.key === 'Enter') {
      event.preventDefault()
      if (results.length > 0) {
        const product = results[Math.min(activeIndex, results.length - 1)]
        addSearchProduct(product)
      }
    }
  }

  return (
    <div className="space-y-2">
      <div className="flex flex-col gap-2 md:flex-row">
        <div className="relative flex-1">
          <label htmlFor={inputId} className="sr-only">
            {labels.search}
          </label>
          <Search className={`pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 ${textColors.tertiary}`} />
          <input
            ref={inputRef}
            id={inputId}
            type="text"
            role="combobox"
            aria-label={labels.search}
            aria-autocomplete="list"
            aria-controls={listboxId}
            aria-expanded={isOpen}
            className={`${tokens.input.base} ps-9`}
            placeholder={labels.search}
            value={query}
            onChange={(event) => {
              setQuery(event.target.value)
              setIsOpen(true)
            }}
            onFocus={() => {
              if (query.trim() !== '') {
                setIsOpen(true)
              }
            }}
            onKeyDown={handleKeyDown}
          />
          {isOpen ? (
            <div
              id={listboxId}
              role="listbox"
              className={`absolute z-30 mt-1 max-h-72 w-full overflow-auto rounded-md border ${borderColors.light} bg-white py-1 shadow-lg`}
            >
              {productsQuery.isLoading ? (
                <div className={`px-3 py-2 text-sm ${textColors.tertiary}`}>{labels.loading}</div>
              ) : results.length === 0 ? (
                <div className={`px-3 py-2 text-sm ${textColors.tertiary}`}>{labels.empty}</div>
              ) : (
                results.map((product, index) => {
                  const active = index === activeIndex
                  return (
                    <button
                      key={product.id}
                      type="button"
                      role="option"
                      aria-selected={active}
                      aria-label={productLabel(product)}
                      className={`flex w-full items-center gap-3 px-3 py-2 text-start ${
                        active ? colors.primary[50] : `${colors.white} ${colors.hover.gray50}`
                      }`}
                      onMouseEnter={() => {
                        setActiveIndex(index)
                      }}
                      onClick={() => {
                        addSearchProduct(product)
                      }}
                    >
                      <span className={`${tokens.table.cellMonoBadge} max-w-28 truncate whitespace-nowrap`}>
                        {product.sku}
                      </span>
                      <span className={`min-w-0 flex-1 truncate text-sm font-medium ${textColors.primary}`}>
                        {product.name}
                      </span>
                    </button>
                  )
                })
              )}
            </div>
          ) : null}
        </div>
        <button
          type="button"
          disabled={onBrowseCatalog === undefined}
          onClick={onBrowseCatalog}
          className={`inline-flex items-center justify-center rounded-md border ${borderColors.default} bg-white px-3 py-2 text-sm font-medium ${textColors.secondary} ${colors.hover.gray50} disabled:cursor-not-allowed disabled:opacity-50`}
        >
          <PackageSearch className="me-2 h-4 w-4" aria-hidden />
          {labels.browseCatalog}
        </button>
      </div>
    </div>
  )
}
