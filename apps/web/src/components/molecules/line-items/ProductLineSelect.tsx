import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ChevronDown, Search, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'

import { api } from '../../../lib/api'
import { borderColors, colors, textColors, tokens } from '../../../lib/designTokens'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import { ProductCell, type ProductCellProduct } from './ProductCell'

interface ProductLineSelectProduct extends ProductCellProduct {
  id: string
}

interface ProductsResponse {
  data: ProductLineSelectProduct[]
}

export interface ProductLineSelectProps {
  value: string
  onChange: (productId: string) => void
  placeholder?: string
  className?: string
  disabled?: boolean
}

export function ProductLineSelect({
  value,
  onChange,
  placeholder,
  className,
  disabled = false,
}: ProductLineSelectProps) {
  const { t } = useTranslation(['common', 'inventory'])
  const [isOpen, setIsOpen] = useState(false)
  const [searchQuery, setSearchQuery] = useState('')
  const [highlightedIndex, setHighlightedIndex] = useState(0)
  const containerRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const effectivePlaceholder = placeholder ?? t('common:actions.select')
  const trimmedQuery = searchQuery.trim()

  const { data: productsData, isLoading } = useQuery({
    queryKey: tenantScopedKey(['line-entry-product-select-search', trimmedQuery]),
    queryFn: async () => {
      const params = new URLSearchParams()
      if (trimmedQuery !== '') {
        params.set('search', trimmedQuery)
      }
      const query = params.toString()
      const response = await api.get<ProductsResponse>(`/products${query !== '' ? `?${query}` : ''}`)
      return response.data
    },
    enabled: tenantId !== null && companyId !== null && isOpen,
    staleTime: 30000,
  })

  const { data: selectedProduct } = useQuery({
    queryKey: tenantScopedKey(['line-entry-product-select', value]),
    queryFn: async () => {
      const response = await api.get<{ data: ProductLineSelectProduct }>(`/products/${value}`)
      return response.data.data
    },
    enabled: tenantId !== null && companyId !== null && value !== '' && !isOpen,
    staleTime: 60000,
  })

  const products = productsData?.data ?? []

  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      const target = event.target
      if (target instanceof Node && containerRef.current !== null && !containerRef.current.contains(target)) {
        setIsOpen(false)
        setSearchQuery('')
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => {
      document.removeEventListener('mousedown', handleClickOutside)
    }
  }, [])

  useEffect(() => {
    if (isOpen) {
      inputRef.current?.focus()
    }
  }, [isOpen])

  function selectProduct(product: ProductLineSelectProduct) {
    onChange(product.id)
    setIsOpen(false)
    setSearchQuery('')
    setHighlightedIndex(0)
  }

  return (
    <div ref={containerRef} className={`relative ${className ?? ''}`}>
      <div className={`flex w-full items-center rounded-md border ${borderColors.default} ${colors.white} px-3 py-2 shadow-sm transition-colors ${
        disabled ? `cursor-not-allowed ${colors.neutral[100]}` : colors.hover.gray50
      }`}>
        <button
          type="button"
          disabled={disabled}
          aria-label={selectedProduct?.name ?? effectivePlaceholder}
          aria-expanded={isOpen}
          aria-haspopup="listbox"
          onClick={() => {
            if (!disabled) setIsOpen((current) => !current)
          }}
          className="min-w-0 flex-1 text-start focus-visible:outline-none"
        >
          {selectedProduct !== undefined ? (
            <ProductCell product={selectedProduct} size="sm" />
          ) : (
            <span className={`text-sm ${textColors.disabled}`}>{effectivePlaceholder}</span>
          )}
        </button>
        <span className="ms-2 flex shrink-0 items-center gap-1">
          {value !== '' && !disabled && (
            <button
              type="button"
              aria-label={t('common:clearSearch')}
              className={`rounded p-0.5 ${textColors.disabled} ${colors.hover.gray50} ${textColors.hoverSecondary}`}
              onClick={() => {
                onChange('')
                setSearchQuery('')
              }}
            >
              <X className="h-4 w-4" aria-hidden="true" />
            </button>
          )}
          <ChevronDown className={`h-4 w-4 ${textColors.disabled} transition-transform ${isOpen ? 'rotate-180' : ''}`} />
        </span>
      </div>

      {isOpen && (
        <div className={`absolute start-0 top-full z-50 mt-1 w-full min-w-[300px] rounded-md border ${borderColors.light} ${colors.white} shadow-lg`}>
          <div className={`border-b ${borderColors.light} p-3`}>
            <div className="relative">
              <Search className={`absolute inset-y-0 start-0 ms-3 h-full w-4 ${textColors.disabled}`} />
              <input
                ref={inputRef}
                type="text"
                role="combobox"
                aria-label={effectivePlaceholder}
                value={searchQuery}
                onChange={(event) => {
                  setSearchQuery(event.target.value)
                  setHighlightedIndex(0)
                }}
                onKeyDown={(event) => {
                  if (event.key === 'ArrowDown') {
                    event.preventDefault()
                    setHighlightedIndex((current) => Math.min(current + 1, Math.max(products.length - 1, 0)))
                  } else if (event.key === 'ArrowUp') {
                    event.preventDefault()
                    setHighlightedIndex((current) => Math.max(current - 1, 0))
                  } else if (event.key === 'Enter' && products.length > 0) {
                    event.preventDefault()
                    selectProduct(products[highlightedIndex] ?? products[0])
                  } else if (event.key === 'Escape') {
                    event.preventDefault()
                    setIsOpen(false)
                  }
                }}
                placeholder={t('inventory:products.searchPlaceholder')}
                className={tokens.input.base}
              />
            </div>
          </div>

          <div role="listbox" className="max-h-60 overflow-y-auto">
            {isLoading ? (
              <div className={`p-4 text-center text-sm ${textColors.disabled}`}>{t('common:status.loading')}</div>
            ) : products.length === 0 ? (
              <div className={`p-4 text-center text-sm ${textColors.disabled}`}>{t('inventory:products.noProductsFound')}</div>
            ) : (
              products.map((product, index) => (
                <button
                  key={product.id}
                  type="button"
                  role="option"
                  aria-selected={index === highlightedIndex}
                  aria-label={`${product.sku ?? ''} ${product.name}`.trim()}
                  className={`w-full px-3 py-2 text-start ${index === highlightedIndex ? colors.neutral[50] : colors.white} ${colors.hover.gray50}`}
                  onMouseEnter={() => {
                    setHighlightedIndex(index)
                  }}
                  onClick={() => {
                    selectProduct(product)
                  }}
                >
                  <ProductCell product={product} />
                </button>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  )
}
