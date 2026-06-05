import { useEffect, useId, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { X } from 'lucide-react'
import { api } from '@/lib/api'
import { useDebouncedValue } from '@/lib/hooks'
import { borderColors, colors, textColors, tokens } from '@/lib/designTokens'

/**
 * Minimal product shape the picker hands back. Callers needing the full
 * ProductData DTO should load it lazily once they hold the id — the
 * picker stays narrow so adding fields to Product server-side does not
 * ripple into every consumer.
 */
export interface ProductPickerValue {
  id: string
  sku: string
  name: string
  sale_price?: string | null
  currency?: string | null
}

interface ProductPickerProps {
  value: ProductPickerValue | null
  onChange: (next: ProductPickerValue | null) => void
  label?: string
  placeholder?: string
  disabled?: boolean
  required?: boolean
  /**
   * Filter by product `type`. Defaults to `part` so that workshop bundle
   * authoring surfaces only physical parts; callers that need services or
   * consumables can override.
   */
  productType?: 'part' | 'consumable' | 'good' | 'all'
  testId?: string
}

interface ProductListItem {
  id: string
  sku: string
  name: string
  sale_price?: string | null
  currency?: string | null
}

interface ProductListResponse {
  data: ProductListItem[]
}

function toValue(item: ProductListItem): ProductPickerValue {
  const value: ProductPickerValue = {
    id: item.id,
    sku: item.sku,
    name: item.name,
  }
  if (item.sale_price !== undefined && item.sale_price !== null) {
    value.sale_price = item.sale_price
  }
  if (item.currency !== undefined && item.currency !== null) {
    value.currency = item.currency
  }
  return value
}

export function ProductPicker({
  value,
  onChange,
  label,
  placeholder,
  disabled = false,
  required = false,
  productType = 'part',
  testId,
}: ProductPickerProps) {
  const { t } = useTranslation('pickers')
  const containerRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)
  const listboxId = useId()
  const inputId = useId()

  const [query, setQuery] = useState('')
  const [isOpen, setIsOpen] = useState(false)
  const [activeIndex, setActiveIndex] = useState(-1)
  const debouncedQuery = useDebouncedValue(query, 250)

  // Fetch whenever the dropdown is open so the user sees products immediately,
  // before typing — matching the document line editor / product search select.
  const listEnabled = isOpen && !disabled
  const trimmedQuery = debouncedQuery.trim()
  const queryKey = ['pickers', 'product', productType, debouncedQuery] as const

  const { data, isLoading, isError } = useQuery({
    queryKey,
    enabled: listEnabled,
    queryFn: async () => {
      const params = new URLSearchParams({ per_page: '20', is_active: 'true' })
      if (trimmedQuery.length > 0) {
        params.set('search', trimmedQuery)
      }
      if (productType !== 'all') {
        params.set('type', productType)
      }
      const response = await api.get<ProductListResponse>(`/products?${params.toString()}`)
      return response.data.data.map(toValue)
    },
  })

  useEffect(() => {
    setActiveIndex(-1)
  }, [data])

  useEffect(() => {
    function onClick(e: MouseEvent) {
      if (containerRef.current !== null && !containerRef.current.contains(e.target as Node)) {
        setIsOpen(false)
      }
    }
    document.addEventListener('mousedown', onClick)
    return () => {
      document.removeEventListener('mousedown', onClick)
    }
  }, [])

  const results = useMemo<ProductPickerValue[]>(() => data ?? [], [data])

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>): void => {
    if (!isOpen) {
      if (e.key === 'ArrowDown' || e.key === 'Enter') {
        setIsOpen(true)
      }
      return
    }
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      setActiveIndex((i) => Math.min(i + 1, Math.max(results.length - 1, 0)))
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setActiveIndex((i) => Math.max(i - 1, 0))
    } else if (e.key === 'Enter') {
      e.preventDefault()
      const choice = results[activeIndex]
      if (choice !== undefined) {
        onChange(choice)
        setQuery('')
        setIsOpen(false)
      }
    } else if (e.key === 'Escape') {
      e.preventDefault()
      setIsOpen(false)
    }
  }

  const effectivePlaceholder = placeholder ?? t('product.searchPlaceholder')
  const effectiveLabel = label ?? t('product.label')
  const testIdAttr = testId ?? 'product-picker'

  // Rendered identically in both selected and unselected states so the control
  // keeps the same vertical footprint and stays aligned with sibling fields
  // (e.g. the quantity column on the stock-transfer line).
  const labelNode =
    effectiveLabel !== '' ? (
      <label htmlFor={inputId} className={tokens.label.base}>
        {effectiveLabel}
        {required ? <span className={tokens.label.required}> *</span> : null}
      </label>
    ) : null

  if (value !== null) {
    return (
      <div ref={containerRef} className="relative" data-testid={testIdAttr}>
        {labelNode}
        <div
          className={`flex items-center gap-2 rounded-md border ${borderColors.default} bg-white px-3 py-2`}
        >
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2">
              <span className={`${tokens.table.cellMonoBadge}`}>{value.sku}</span>
              <span className={`truncate text-sm font-medium ${textColors.primary}`}>{value.name}</span>
            </div>
          </div>
          <button
            type="button"
            className={`${textColors.tertiary} ${textColors.hoverPrimary}`}
            aria-label={t('common.clear')}
            disabled={disabled}
            onClick={() => {
              onChange(null)
              setQuery('')
            }}
          >
            <X className="h-4 w-4" aria-hidden />
          </button>
        </div>
      </div>
    )
  }

  return (
    <div ref={containerRef} className="relative" data-testid={testIdAttr}>
      {labelNode}
      <input
        ref={inputRef}
        id={inputId}
        type="text"
        role="combobox"
        aria-expanded={isOpen}
        aria-controls={listboxId}
        aria-autocomplete="list"
        aria-label={effectiveLabel !== '' ? effectiveLabel : effectivePlaceholder}
        className={tokens.input.base}
        placeholder={effectivePlaceholder}
        value={query}
        disabled={disabled}
        onChange={(e) => {
          setQuery(e.target.value)
          setIsOpen(true)
        }}
        onFocus={() => {
          setIsOpen(true)
        }}
        onKeyDown={handleKeyDown}
      />
      {isOpen ? (
        <div
          id={listboxId}
          role="listbox"
          className={`absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-md border ${borderColors.light} bg-white py-1 shadow-lg`}
        >
          {isLoading ? (
            <div className={`px-3 py-2 text-sm ${textColors.tertiary}`}>{t('common.loading')}</div>
          ) : isError ? (
            <div className={`px-3 py-2 text-sm ${textColors.error}`}>{t('common.error')}</div>
          ) : results.length === 0 ? (
            <div className={`px-3 py-2 text-sm ${textColors.tertiary}`}>{t('product.empty')}</div>
          ) : (
            results.map((product, idx) => {
              const active = idx === activeIndex
              return (
                <button
                  key={product.id}
                  type="button"
                  role="option"
                  aria-selected={active}
                  className={`flex w-full items-center gap-3 px-3 py-2 text-left ${
                    active ? colors.primary[50] : `${colors.white} ${colors.hover.gray50}`
                  }`}
                  onMouseEnter={() => {
                    setActiveIndex(idx)
                  }}
                  onClick={() => {
                    onChange(product)
                    setQuery('')
                    setIsOpen(false)
                  }}
                >
                  <span className={tokens.table.cellMonoBadge}>{product.sku}</span>
                  <div className="min-w-0 flex-1">
                    <div className={`truncate text-sm font-medium ${textColors.primary}`}>
                      {product.name}
                    </div>
                    {product.sale_price !== undefined && product.sale_price !== null ? (
                      <div className={`truncate text-xs ${textColors.tertiary}`}>
                        {product.sale_price} {product.currency ?? ''}
                      </div>
                    ) : null}
                  </div>
                </button>
              )
            })
          )}
        </div>
      ) : null}
    </div>
  )
}
