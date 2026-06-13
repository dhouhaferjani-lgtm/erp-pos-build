import { useState, useRef, useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Search, X, ChevronDown, Package } from 'lucide-react'
import { api } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'

interface Product {
  id: string
  name: string
  sku: string
  price: number
}

interface ProductsResponse {
  data: Product[]
}

interface ProductSearchSelectProps {
  value: string
  onChange: (productId: string) => void
  placeholder?: string
  className?: string
  disabled?: boolean
}

export function ProductSearchSelect({
  value,
  onChange,
  placeholder,
  className,
  disabled = false,
}: ProductSearchSelectProps) {
  const { t } = useTranslation()
  const [isOpen, setIsOpen] = useState(false)
  const [searchQuery, setSearchQuery] = useState('')
  const containerRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const { data: productsData, isLoading } = useQuery({
    queryKey: tenantScopedKey(['products-search', searchQuery]),
    queryFn: async () => {
      const params = new URLSearchParams()
      if (searchQuery) {
        params.append('search', searchQuery)
      }
      const query = params.toString()
      const response = await api.get<ProductsResponse>(`/products${query ? `?${query}` : ''}`)
      return response.data
    },
    enabled: tenantId !== null && companyId !== null && isOpen,
    staleTime: 30000,
  })

  const { data: selectedProductData } = useQuery({
    queryKey: tenantScopedKey(['product', value]),
    queryFn: async () => {
      const response = await api.get<{ data: Product }>(`/products/${value}`)
      return response.data.data
    },
    enabled: tenantId !== null && companyId !== null && Boolean(value) && !isOpen,
    staleTime: 60000,
  })

  const products = productsData?.data ?? []
  const selectedProduct = selectedProductData

  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setIsOpen(false)
        setSearchQuery('')
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => { document.removeEventListener('mousedown', handleClickOutside) }
  }, [])

  useEffect(() => {
    if (isOpen && inputRef.current) {
      inputRef.current.focus()
    }
  }, [isOpen])

  const handleSelect = (product: Product) => {
    onChange(product.id)
    setIsOpen(false)
    setSearchQuery('')
  }

  const handleClear = () => {
    onChange('')
    setSearchQuery('')
  }

  return (
    <div ref={containerRef} className={`relative ${className ?? ''}`}>
      <div
        role="button"
        tabIndex={disabled ? -1 : 0}
        onClick={() => !disabled && setIsOpen(!isOpen)}
        onKeyDown={(e) => {
          if ((e.key === 'Enter' || e.key === ' ') && !disabled) {
            e.preventDefault()
            setIsOpen(!isOpen)
          }
        }}
        aria-disabled={disabled}
        aria-expanded={isOpen}
        aria-haspopup="listbox"
        className={`flex w-full items-center justify-between rounded-lg border border-gray-300 px-3 py-2 text-start shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 ${
          disabled ? 'bg-gray-100 cursor-not-allowed' : 'bg-white hover:bg-gray-50 cursor-pointer'
        }`}
      >
        <span className={selectedProduct ? 'text-gray-900' : 'text-gray-500'}>
          {selectedProduct
            ? `${selectedProduct.name}${selectedProduct.sku ? ` (${selectedProduct.sku})` : ''}`
            : (placeholder ?? t('common:actions.select'))}
        </span>
        <div className="flex items-center gap-1">
          {value && !disabled && (
            <button
              type="button"
              onClick={(e) => {
                e.stopPropagation()
                handleClear()
              }}
              aria-label={t('common:clearSearch')}
              className="rounded p-0.5 text-gray-400 hover:bg-gray-200 hover:text-gray-600"
            >
              <X className="h-4 w-4" />
            </button>
          )}
          <ChevronDown className={`h-4 w-4 text-gray-400 transition-transform ${isOpen ? 'rotate-180' : ''}`} />
        </div>
      </div>

      {isOpen && (
        <div className="absolute left-0 top-full z-50 mt-1 w-full min-w-[300px] rounded-lg border border-gray-200 bg-white shadow-lg">
          <div className="border-b border-gray-200 p-3">
            <div className="relative">
              <Search className="absolute inset-y-0 start-0 ms-3 h-full w-4 text-gray-400" />
              <input
                ref={inputRef}
                type="text"
                value={searchQuery}
                onChange={(e) => { setSearchQuery(e.target.value) }}
                placeholder={t('inventory:products.searchPlaceholder')}
                className="w-full rounded-lg border border-gray-300 py-2 pe-10 ps-10 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
              {searchQuery && (
                <button
                  type="button"
                  onClick={() => { setSearchQuery('') }}
                  className="absolute inset-y-0 end-0 flex items-center pe-3 text-gray-400 hover:text-gray-600"
                >
                  <X className="h-4 w-4" />
                </button>
              )}
            </div>
          </div>

          <div className="max-h-60 overflow-y-auto">
            {isLoading ? (
              <div className="p-4 text-center text-sm text-gray-500">
                {t('common:status.loading')}
              </div>
            ) : products.length === 0 ? (
              <div className="p-4 text-center text-sm">
                <Package className="mx-auto h-8 w-8 text-gray-300" />
                <p className="mt-2 text-gray-500">
                  {searchQuery
                    ? t('inventory:products.noSearchResults')
                    : t('inventory:products.noProducts')}
                </p>
              </div>
            ) : (
              <ul className="divide-y divide-gray-100">
                {products.map((product) => (
                  <li key={product.id}>
                    <button
                      type="button"
                      onClick={() => { handleSelect(product) }}
                      className={`flex w-full items-center gap-3 px-4 py-3 text-start hover:bg-gray-50 ${
                        product.id === value ? 'bg-blue-50' : ''
                      }`}
                    >
                      <div className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-gray-100">
                        <Package className="h-4 w-4 text-gray-500" />
                      </div>
                      <div className="min-w-0 flex-1">
                        <div className="truncate text-sm font-medium text-gray-900">
                          {product.name}
                        </div>
                        {product.sku && (
                          <div className="truncate text-xs text-gray-500">
                            {product.sku}
                          </div>
                        )}
                      </div>
                      {product.id === value && (
                        <div className="flex-shrink-0">
                          <div className="h-2 w-2 rounded-full bg-blue-600" />
                        </div>
                      )}
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
