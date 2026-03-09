import { useState, useCallback, useMemo } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, Trash2, GripVertical, Search, X } from 'lucide-react'
import { api } from '../../../lib/api'
import { formatCurrency } from '../../../lib/format'
import { useCompanyStore } from '../../../stores/companyStore'
import { AddQuickProductModal } from '../../../components/organisms'

interface Product {
  id: string
  name: string
  sku: string
  sale_price: number
  tax_rate: number
}

interface Service {
  id: string
  name: string
  code: string
  base_price: number
  tax_rate: number
}

interface ProductsResponse {
  data: Product[]
}

interface ServicesResponse {
  data: Service[]
}

export interface DocumentLine {
  id: string
  product_id: string
  service_id?: string
  product_code?: string
  product_name: string
  description: string
  quantity: number
  unit_price: number
  tax_rate: number
  line_total: number
  is_service?: boolean
}

type SearchTab = 'product' | 'service'

interface DocumentLineEditorProps {
  lines: DocumentLine[]
  onChange: (lines: DocumentLine[]) => void
  readonly?: boolean
}

export function DocumentLineEditor({ lines, onChange, readonly = false }: DocumentLineEditorProps) {
  const { t } = useTranslation(['sales', 'common'])
  const queryClient = useQueryClient()
  const currentCompany = useCompanyStore((state) => state.getCurrentCompany())
  const [searchQuery, setSearchQuery] = useState('')
  const [searchTab, setSearchTab] = useState<SearchTab>('product')
  const [showProductSearch, setShowProductSearch] = useState(false)
  const [showProductModal, setShowProductModal] = useState(false)
  const [editingLineId, setEditingLineId] = useState<string | null>(null)
  const [draggedIndex, setDraggedIndex] = useState<number | null>(null)

  // Get company currency with fallback
  const companyCurrency = currentCompany?.currency ?? 'EUR'
  const companyLocale = currentCompany?.locale?.replace('_', '-') ?? 'en-US'

  // Fetch products for search
  const { data: productsData, isLoading: isLoadingProducts } = useQuery({
    queryKey: ['products', searchQuery],
    queryFn: async () => {
      const params = searchQuery ? `?search=${encodeURIComponent(searchQuery)}` : ''
      const response = await api.get<ProductsResponse>(`/products${params}`)
      return response.data
    },
    enabled: showProductSearch, // Always fetch when dropdown is open
    staleTime: 30000, // Cache for 30 seconds
  })

  // Fetch services for search
  const { data: servicesData, isLoading: isLoadingServices } = useQuery({
    queryKey: ['services', searchQuery],
    queryFn: async () => {
      const params = searchQuery ? `?search=${encodeURIComponent(searchQuery)}` : ''
      const response = await api.get<ServicesResponse>(`/services${params}`)
      return response.data
    },
    enabled: showProductSearch && searchTab === 'service',
    staleTime: 30000,
  })

  const products = productsData?.data ?? []
  const services = servicesData?.data ?? []

  // Calculate totals
  const totals = useMemo(() => {
    const subtotal = lines.reduce((sum, line) => {
      const qty = Number(line.quantity) || 0
      const price = Number(line.unit_price) || 0
      return sum + qty * price
    }, 0)
    const tax = lines.reduce(
      (sum, line) => {
        const qty = Number(line.quantity) || 0
        const price = Number(line.unit_price) || 0
        const rate = Number(line.tax_rate) || 0
        return sum + qty * price * (rate / 100)
      },
      0
    )
    return {
      subtotal,
      tax,
      total: subtotal + tax,
    }
  }, [lines])

  // Generate unique ID for new lines
  const generateId = () => `line-${String(Date.now())}-${Math.random().toString(36).substring(2, 11)}`

  // Calculate line total
  const calculateLineTotal = (quantity: number, unitPrice: number, taxRate: number) => {
    const qty = Number(quantity) || 0
    const price = Number(unitPrice) || 0
    const rate = Number(taxRate) || 0
    const subtotal = qty * price
    const tax = subtotal * (rate / 100)
    return subtotal + tax
  }

  // Add product to lines
  const handleAddProduct = useCallback(
    (product: Product) => {
      const newLine: DocumentLine = {
        id: generateId(),
        product_id: product.id,
        product_code: product.sku,
        product_name: product.name,
        description: product.name,
        quantity: 1,
        unit_price: product.sale_price,
        tax_rate: product.tax_rate,
        line_total: calculateLineTotal(1, product.sale_price, product.tax_rate),
      }
      onChange([...lines, newLine])
      setShowProductSearch(false)
      setSearchQuery('')
    },
    [lines, onChange]
  )

  // Add service to lines
  const handleAddService = useCallback(
    (service: Service) => {
      const newLine: DocumentLine = {
        id: generateId(),
        product_id: '',
        service_id: service.id,
        product_code: service.code,
        product_name: service.name,
        description: service.name,
        quantity: 1,
        unit_price: service.base_price,
        tax_rate: service.tax_rate,
        line_total: calculateLineTotal(1, service.base_price, service.tax_rate),
        is_service: true,
      }
      onChange([...lines, newLine])
      setShowProductSearch(false)
      setSearchQuery('')
    },
    [lines, onChange]
  )

  // Add blank line
  const handleAddBlankLine = useCallback(() => {
    const newLine: DocumentLine = {
      id: generateId(),
      product_id: '',
      product_name: '',
      description: '',
      quantity: 1,
      unit_price: 0,
      tax_rate: 0,
      line_total: 0,
    }
    onChange([...lines, newLine])
    setEditingLineId(newLine.id)
  }, [lines, onChange])

  // Update line
  const handleUpdateLine = useCallback(
    (lineId: string, updates: Partial<DocumentLine>) => {
      onChange(
        lines.map((line) => {
          if (line.id !== lineId) return line
          const updatedLine = { ...line, ...updates }
          // Recalculate line total if quantity, price, or tax changed
          if ('quantity' in updates || 'unit_price' in updates || 'tax_rate' in updates) {
            updatedLine.line_total = calculateLineTotal(
              updatedLine.quantity,
              updatedLine.unit_price,
              updatedLine.tax_rate
            )
          }
          return updatedLine
        })
      )
    },
    [lines, onChange]
  )

  // Remove line
  const handleRemoveLine = useCallback(
    (lineId: string) => {
      onChange(lines.filter((line) => line.id !== lineId))
    },
    [lines, onChange]
  )

  // Drag and drop handlers
  const handleDragStart = (index: number) => {
    setDraggedIndex(index)
  }

  const handleDragOver = (e: React.DragEvent, index: number) => {
    e.preventDefault()
    if (draggedIndex === null || draggedIndex === index) return

    const newLines = [...lines]
    const draggedLine = newLines[draggedIndex]
    newLines.splice(draggedIndex, 1)
    newLines.splice(index, 0, draggedLine)
    onChange(newLines)
    setDraggedIndex(index)
  }

  const handleDragEnd = () => {
    setDraggedIndex(null)
  }

  // Format currency using company settings
  const formatAmount = (amount: string | number) => {
    const num = typeof amount === 'string' ? parseFloat(amount) : amount
    if (isNaN(num)) {
      return formatCurrency(0, { currency: companyCurrency, locale: companyLocale })
    }
    return formatCurrency(num, {
      currency: companyCurrency,
      locale: companyLocale,
    })
  }

  return (
    <div className="space-y-4">
      {/* Lines Table */}
      <div className="rounded-lg border border-gray-200 bg-white">
        <div className="border-b border-gray-200 px-6 py-4">
          <h3 className="text-lg font-semibold text-gray-900">{t('sales:lineItems.title')}</h3>
        </div>

        {lines.length === 0 ? (
          <div className="p-8 text-center text-gray-500">
            <p>{t('sales:lineItems.empty.title')}</p>
            {!readonly && (
              <p className="mt-2 text-sm">{t('sales:lineItems.empty.description')}</p>
            )}
          </div>
        ) : (
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                {!readonly && (
                  <th className="w-10 px-3 py-3">
                    <span className="sr-only">{t('sales:lineItems.actions.dragToReorder')}</span>
                  </th>
                )}
                <th className="w-32 px-4 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('sales:lineItems.article')}
                </th>
                <th className="px-4 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('sales:lineItems.description')}
                </th>
                <th className="w-24 px-4 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('sales:lineItems.quantity')}
                </th>
                <th className="w-32 px-4 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('sales:lineItems.unitPrice')}
                </th>
                <th className="w-20 px-4 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('sales:lineItems.taxPercent')}
                </th>
                <th className="w-32 px-4 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('sales:lineItems.total')}
                </th>
                {!readonly && (
                  <th className="w-12 px-3 py-3">
                    <span className="sr-only">{t('common:table.actionsColumn')}</span>
                  </th>
                )}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {lines.map((line, index) => (
                <tr
                  key={line.id}
                  draggable={!readonly}
                  onDragStart={() => {
                    handleDragStart(index)
                  }}
                  onDragOver={(e) => {
                    handleDragOver(e, index)
                  }}
                  onDragEnd={handleDragEnd}
                  className={draggedIndex === index ? 'bg-blue-50' : 'hover:bg-gray-50'}
                >
                  {!readonly && (
                    <td className="px-3 py-3 text-center">
                      <button
                        type="button"
                        className="cursor-grab text-gray-400 hover:text-gray-600"
                        aria-label={t('sales:lineItems.actions.dragToReorder')}
                      >
                        <GripVertical className="h-4 w-4" />
                      </button>
                    </td>
                  )}
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-1.5">
                      <span className="text-sm font-mono text-gray-600">
                        {line.product_code || '-'}
                      </span>
                      {line.is_service && (
                        <span className="inline-flex rounded-full bg-purple-100 px-1.5 py-0.5 text-[10px] font-medium text-purple-700">
                          {t('sales:lineItems.serviceBadge')}
                        </span>
                      )}
                    </div>
                  </td>
                  <td className="px-4 py-3">
                    {editingLineId === line.id && !readonly ? (
                      <input
                        type="text"
                        value={line.description}
                        onChange={(e) => {
                          handleUpdateLine(line.id, { description: e.target.value })
                        }}
                        onBlur={() => {
                          setEditingLineId(null)
                        }}
                        className="w-full rounded border border-gray-300 px-2 py-1 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                        autoFocus
                      />
                    ) : (
                      <button
                        type="button"
                        onClick={() => {
                          if (!readonly) setEditingLineId(line.id)
                        }}
                        className="text-start text-sm font-medium text-gray-900 hover:text-blue-600"
                        disabled={readonly}
                      >
                        {line.description || line.product_name || t('sales:lineItems.actions.clickToEdit')}
                      </button>
                    )}
                  </td>
                  <td className="px-4 py-3 text-end">
                    {readonly ? (
                      <span className="text-sm text-gray-900">{line.quantity}</span>
                    ) : (
                      <input
                        type="number"
                        min="0"
                        step="1"
                        value={line.quantity}
                        onChange={(e) => {
                          handleUpdateLine(line.id, { quantity: parseFloat(e.target.value) || 0 })
                        }}
                        className="w-20 rounded border border-gray-300 px-2 py-1 text-end text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                      />
                    )}
                  </td>
                  <td className="px-4 py-3 text-end">
                    {readonly ? (
                      <span className="text-sm text-gray-900">{formatAmount(line.unit_price)}</span>
                    ) : (
                      <input
                        type="number"
                        min="0"
                        step="0.01"
                        value={line.unit_price}
                        onChange={(e) => {
                          handleUpdateLine(line.id, { unit_price: parseFloat(e.target.value) || 0 })
                        }}
                        className="w-28 rounded border border-gray-300 px-2 py-1 text-end text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                      />
                    )}
                  </td>
                  <td className="px-4 py-3 text-end">
                    {readonly ? (
                      <span className="text-sm text-gray-500">{line.tax_rate}%</span>
                    ) : (
                      <input
                        type="number"
                        min="0"
                        step="0.1"
                        value={line.tax_rate}
                        onChange={(e) => {
                          handleUpdateLine(line.id, { tax_rate: parseFloat(e.target.value) || 0 })
                        }}
                        className="w-16 rounded border border-gray-300 px-2 py-1 text-end text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                      />
                    )}
                  </td>
                  <td className="whitespace-nowrap px-4 py-3 text-end text-sm font-medium text-gray-900">
                    {formatAmount(line.line_total)}
                  </td>
                  {!readonly && (
                    <td className="px-3 py-3 text-center">
                      <button
                        type="button"
                        onClick={() => {
                          handleRemoveLine(line.id)
                        }}
                        className="text-gray-400 hover:text-red-600"
                        aria-label={t('sales:lineItems.actions.removeLine')}
                      >
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        )}

        {/* Totals */}
        <div className="border-t border-gray-200 bg-gray-50 px-6 py-4">
          <div className="flex justify-end">
            <dl className="w-64 space-y-2">
              <div className="flex justify-between text-sm">
                <dt className="text-gray-500">{t('sales:lineItems.subtotal')}</dt>
                <dd className="font-medium text-gray-900">{formatAmount(totals.subtotal)}</dd>
              </div>
              <div className="flex justify-between text-sm">
                <dt className="text-gray-500">{t('sales:lineItems.tax')}</dt>
                <dd className="font-medium text-gray-900">{formatAmount(totals.tax)}</dd>
              </div>
              <div className="flex justify-between border-t border-gray-200 pt-2 text-base">
                <dt className="font-semibold text-gray-900">{t('sales:lineItems.total')}</dt>
                <dd className="font-semibold text-gray-900">{formatAmount(totals.total)}</dd>
              </div>
            </dl>
          </div>
        </div>
      </div>

      {/* Add Item Buttons */}
      {!readonly && (
        <div className="flex gap-2">
          <div className="relative">
            <button
              type="button"
              onClick={() => {
                setShowProductSearch(!showProductSearch)
              }}
              className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
            >
              <Search className="h-4 w-4" />
              {t('sales:lineItems.actions.searchProducts')}
            </button>

            {/* Product/Service Search Dropdown */}
            {showProductSearch && (
              <div className="absolute left-0 top-full z-10 mt-1 w-80 rounded-lg border border-gray-200 bg-white shadow-lg">
                {/* Tab Toggle */}
                <div className="flex border-b border-gray-200">
                  <button
                    type="button"
                    onClick={() => { setSearchTab('product'); setSearchQuery('') }}
                    className={`flex-1 px-4 py-2 text-sm font-medium ${searchTab === 'product' ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-500 hover:text-gray-700'}`}
                  >
                    {t('sales:lineItems.tabs.product')}
                  </button>
                  <button
                    type="button"
                    onClick={() => { setSearchTab('service'); setSearchQuery('') }}
                    className={`flex-1 px-4 py-2 text-sm font-medium ${searchTab === 'service' ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-500 hover:text-gray-700'}`}
                  >
                    {t('sales:lineItems.tabs.service')}
                  </button>
                </div>
                <div className="p-3">
                  <div className="relative">
                    <input
                      type="text"
                      value={searchQuery}
                      onChange={(e) => {
                        setSearchQuery(e.target.value)
                      }}
                      placeholder={searchTab === 'product' ? t('sales:lineItems.actions.searchProductsPlaceholder') : t('sales:lineItems.actions.searchServicesPlaceholder')}
                      className="w-full rounded-lg border border-gray-300 py-2 pe-10 ps-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                      autoFocus
                    />
                    {searchQuery && (
                      <button
                        type="button"
                        onClick={() => {
                          setSearchQuery('')
                        }}
                        className="absolute inset-y-0 end-0 flex items-center pe-3 text-gray-400 hover:text-gray-600"
                      >
                        <X className="h-4 w-4" />
                      </button>
                    )}
                  </div>
                </div>
                <div className="max-h-60 overflow-y-auto border-t border-gray-200">
                  {searchTab === 'product' ? (
                    <>
                      {isLoadingProducts ? (
                        <div className="p-4 text-center text-sm text-gray-500">
                          {t('sales:lineItems.loading')}
                        </div>
                      ) : products.length === 0 ? (
                        <div className="p-4 text-center text-sm">
                          <p className="text-gray-500">
                            {searchQuery ? t('sales:lineItems.noProductsFound') : t('sales:lineItems.noProductsAvailable')}
                          </p>
                        </div>
                      ) : (
                        <ul className="divide-y divide-gray-100">
                          {products.map((product) => (
                            <li key={product.id}>
                              <button
                                type="button"
                                onClick={() => {
                                  handleAddProduct(product)
                                }}
                                className="flex w-full items-center justify-between px-4 py-3 text-start hover:bg-gray-50"
                              >
                                <div>
                                  <div className="text-sm font-medium text-gray-900">
                                    {product.name}
                                  </div>
                                  <div className="text-xs text-gray-500">{product.sku}</div>
                                </div>
                                <div className="text-sm font-medium text-gray-900">
                                  {formatAmount(product.sale_price)}
                                </div>
                              </button>
                            </li>
                          ))}
                        </ul>
                      )}
                    </>
                  ) : (
                    <>
                      {isLoadingServices ? (
                        <div className="p-4 text-center text-sm text-gray-500">
                          {t('sales:lineItems.loading')}
                        </div>
                      ) : services.length === 0 ? (
                        <div className="p-4 text-center text-sm">
                          <p className="text-gray-500">
                            {searchQuery ? t('sales:lineItems.noServicesFound') : t('sales:lineItems.noServicesAvailable')}
                          </p>
                        </div>
                      ) : (
                        <ul className="divide-y divide-gray-100">
                          {services.map((service) => (
                            <li key={service.id}>
                              <button
                                type="button"
                                onClick={() => {
                                  handleAddService(service)
                                }}
                                className="flex w-full items-center justify-between px-4 py-3 text-start hover:bg-gray-50"
                              >
                                <div>
                                  <div className="flex items-center gap-2">
                                    <span className="text-sm font-medium text-gray-900">
                                      {service.name}
                                    </span>
                                    <span className="inline-flex rounded-full bg-purple-100 px-1.5 py-0.5 text-[10px] font-medium text-purple-700">
                                      {t('sales:lineItems.serviceBadge')}
                                    </span>
                                  </div>
                                  <div className="text-xs text-gray-500">{service.code}</div>
                                </div>
                                <div className="text-sm font-medium text-gray-900">
                                  {formatAmount(service.base_price)}
                                </div>
                              </button>
                            </li>
                          ))}
                        </ul>
                      )}
                    </>
                  )}
                </div>
                <div className="border-t border-gray-200 p-2 space-y-1">
                  {searchTab === 'product' && (
                    <button
                      type="button"
                      onClick={() => {
                        setShowProductSearch(false)
                        setShowProductModal(true)
                      }}
                      className="w-full flex items-center justify-center gap-2 rounded px-3 py-2 text-sm font-medium text-blue-600 hover:bg-blue-50 transition-colors"
                    >
                      <Plus className="h-4 w-4" />
                      {t('sales:lineItems.actions.createNewProduct')}
                    </button>
                  )}
                  <button
                    type="button"
                    onClick={() => {
                      setShowProductSearch(false)
                    }}
                    className="w-full rounded px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-100"
                  >
                    {t('sales:lineItems.actions.close')}
                  </button>
                </div>
              </div>
            )}
          </div>

          <button
            type="button"
            onClick={handleAddBlankLine}
            className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
          >
            <Plus className="h-4 w-4" />
            {t('sales:lineItems.actions.addBlankLine')}
          </button>
        </div>
      )}

      {/* Add Product Modal */}
      <AddQuickProductModal
        isOpen={showProductModal}
        onClose={() => {
          setShowProductModal(false)
        }}
        onSuccess={(product) => {
          // Transform product to match DocumentLineEditor's Product type
          const lineProduct: Product = {
            id: product.id,
            name: product.name,
            sku: product.sku ?? '',
            sale_price: product.sale_price,
            tax_rate: product.tax_rate,
          }
          // Add the new product to the lines
          handleAddProduct(lineProduct)
          void queryClient.invalidateQueries({ queryKey: ['products'] })
        }}
      />
    </div>
  )
}
