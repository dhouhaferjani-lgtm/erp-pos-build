import { useState, useCallback, useMemo, useRef } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, Trash2, Search, X } from 'lucide-react'
import { api } from '../../../lib/api'
import { formatCurrency } from '../../../lib/format'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import { AddQuickProductModal } from '../../../components/organisms/AddQuickProductModal/AddQuickProductModal'
import { TaxConfigurationSelect } from '../../../components/atoms/TaxConfigurationSelect/TaxConfigurationSelect'
import { MoneyInput } from '../../../components/atoms/MoneyInput/MoneyInput'
import { LineItemsTable, QuantityCell, type LineItemsTableColumn } from '../../../components/molecules/line-items/LineItemsTable'
import { useCompanyConfig } from '../../../contexts/CompanyConfigContext'
import { DesignationCell } from './DesignationCell'
import { NotesCell } from './NotesCell'
import { useLineDesignationFeature } from '../hooks/useLineDesignationFeature'
import { getQuantityDecimals } from '../../../lib/quantityScale'
import { borderColors, colors, textColors, tokens } from '../../../lib/designTokens'

// Map frontend document type strings to backend applicable_document_types format
const DOCUMENT_TYPE_MAP: Record<string, string> = {
  quote: 'QUOTATION',
  sales_order: 'SALES_ORDER',
  invoice: 'TAX_INVOICE',
  purchase_order: 'PURCHASE_ORDER',
  delivery_note: 'DELIVERY_NOTE',
  credit_note: 'CREDIT_NOTE',
  return_note: 'CREDIT_NOTE',
}

interface Product {
  id: string
  name: string
  sku: string
  sale_price: number
  tax_rate: number
  default_tax_configuration_id?: string | null
  quantity_decimals?: number | null
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
  designation_default_snapshot?: string | null
  notes?: string | null
  quantity: number
  unit_price: number
  tax_rate: number
  tax_configuration_id?: string | null
  line_total: number
  is_service?: boolean
  /** Unit precision (unit decimal_places) → drives the qty input step. */
  quantity_decimals?: number | null
}

type SearchTab = 'product' | 'service'

interface DocumentLineEditorProps {
  lines: DocumentLine[]
  onChange: (lines: DocumentLine[]) => void
  readonly?: boolean
  documentType?: string
}

function scopedNamespacePredicate(
  namespace: string,
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      k.length >= 3 &&
      k[0] === namespace &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function DocumentLineEditor({ lines, onChange, readonly = false, documentType }: DocumentLineEditorProps) {
  const { t } = useTranslation(['sales', 'common'])
  const queryClient = useQueryClient()
  const currentCompany = useCompanyStore((state) => state.getCurrentCompany())
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const { hasModule } = useCompanyConfig()
  const designationFeatureEnabled = useLineDesignationFeature()
  const [searchQuery, setSearchQuery] = useState('')
  const [searchTab, setSearchTab] = useState<SearchTab>('product')
  const [showProductSearch, setShowProductSearch] = useState(false)
  const [showProductModal, setShowProductModal] = useState(false)
  const linesRef = useRef(lines)
  linesRef.current = lines

  // Get company currency with fallback
  const companyCurrency = currentCompany?.currency ?? 'EUR'
  const companyLocale = currentCompany?.locale.replace('_', '-') ?? 'en-US'
  const canSearchServices = hasModule('Workshop')
  const activeSearchTab: SearchTab = canSearchServices ? searchTab : 'product'

  // Fetch products for search
  const { data: productsData, isLoading: isLoadingProducts } = useQuery({
    queryKey: tenantScopedKey(['products', searchQuery]),
    queryFn: async () => {
      const params = searchQuery ? `?search=${encodeURIComponent(searchQuery)}` : ''
      const response = await api.get<ProductsResponse>(`/products${params}`)
      return response.data
    },
    enabled: tenantId !== null && companyId !== null && showProductSearch, // Always fetch when dropdown is open
    staleTime: 30000, // Cache for 30 seconds
  })

  // Fetch services for search
  const { data: servicesData, isLoading: isLoadingServices } = useQuery({
    queryKey: tenantScopedKey(['services', searchQuery]),
    queryFn: async () => {
      const params = searchQuery ? `?search=${encodeURIComponent(searchQuery)}` : ''
      const response = await api.get<ServicesResponse>(`/services${params}`)
      return response.data
    },
    enabled: tenantId !== null && companyId !== null && showProductSearch && canSearchServices && activeSearchTab === 'service',
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
        designation_default_snapshot: product.name,
        notes: null,
        quantity: 1,
        unit_price: product.sale_price,
        tax_rate: product.tax_rate,
        tax_configuration_id: product.default_tax_configuration_id ?? null,
        line_total: calculateLineTotal(1, product.sale_price, product.tax_rate),
        quantity_decimals: product.quantity_decimals ?? null,
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
        designation_default_snapshot: service.name,
        notes: null,
        quantity: 1,
        unit_price: service.base_price,
        tax_rate: service.tax_rate,
        tax_configuration_id: null,
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
  }, [lines, onChange])

  // Update line
  const handleUpdateLine = useCallback(
    (lineId: string, updates: Partial<DocumentLine>) => {
      onChange(
        linesRef.current.map((line) => {
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
    [onChange]
  )

  // Remove line
  const handleRemoveLine = useCallback(
    (lineId: string) => {
      onChange(linesRef.current.filter((line) => line.id !== lineId))
    },
    [onChange]
  )

  const handleReorderLines = useCallback(
    (fromIndex: number, toIndex: number) => {
      if (fromIndex === toIndex) return
      const newLines = [...linesRef.current]
      const [draggedLine] = newLines.splice(fromIndex, 1)
      newLines.splice(toIndex, 0, draggedLine)
      onChange(newLines)
    },
    [onChange]
  )

  // Format currency using company settings
  const formatAmount = useCallback((amount: string | number) => {
    const num = typeof amount === 'string' ? parseFloat(amount) : amount
    if (isNaN(num)) {
      return formatCurrency(0, { currency: companyCurrency, locale: companyLocale })
    }
    return formatCurrency(num, {
      currency: companyCurrency,
      locale: companyLocale,
    })
  }, [companyCurrency, companyLocale])

  const lineColumns = useMemo<LineItemsTableColumn<DocumentLine>[]>(() => [
    {
      id: 'article',
      header: t('sales:lineItems.article'),
      headerClassName: 'w-32',
      Cell: ({ line }) => (
        <div className="flex items-center gap-1.5">
          <span className={`font-mono text-sm ${textColors.tertiary}`}>
            {line.product_code === undefined || line.product_code === '' ? '-' : line.product_code}
          </span>
          {line.is_service && (
            <span className={`inline-flex rounded-full ${colors.neutral[100]} px-1.5 py-0.5 text-[10px] font-medium ${textColors.secondary}`}>
              {t('sales:lineItems.serviceBadge')}
            </span>
          )}
        </div>
      ),
    },
    {
      id: 'description',
      header: t('sales:lineItems.description'),
      Cell: ({ line }) => (
        <>
          {designationFeatureEnabled ? (
            <DesignationCell
              value={line.description || line.product_name}
              originalSnapshot={line.designation_default_snapshot ?? null}
              readOnly={readonly}
              onCommit={(next) => {
                handleUpdateLine(line.id, { description: next })
              }}
            />
          ) : (
            <span className={`text-sm ${textColors.primary}`}>{line.description || line.product_name}</span>
          )}
          {designationFeatureEnabled && (
            <NotesCell
              value={line.notes ?? null}
              readOnly={readonly}
              onCommit={(next) => {
                handleUpdateLine(line.id, { notes: next })
              }}
            />
          )}
        </>
      ),
    },
    {
      id: 'quantity',
      header: t('sales:lineItems.quantity'),
      headerClassName: 'w-24 text-end',
      cellClassName: 'text-end',
      Cell: ({ line }) => (
        <QuantityCell
          decimalPlaces={getQuantityDecimals(line)}
          min="0"
          readonly={readonly}
          value={line.quantity}
          onChange={(value) => {
            handleUpdateLine(line.id, { quantity: parseFloat(value) || 0 })
          }}
          ariaLabel={t('sales:lineItems.quantity')}
        />
      ),
    },
    {
      id: 'unit-price',
      header: t('sales:lineItems.unitPrice'),
      headerClassName: 'w-32 text-end',
      cellClassName: 'text-end',
      Cell: ({ line }) => (
        readonly ? (
          <span className={`text-sm ${textColors.primary}`}>{formatAmount(line.unit_price)}</span>
        ) : (
          <MoneyInput
            currency={companyCurrency}
            min="0"
            value={String(line.unit_price)}
            onChange={(value) => {
              handleUpdateLine(line.id, { unit_price: parseFloat(value) || 0 })
            }}
            className={`${tokens.input.base} w-28 text-end text-sm`}
          />
        )
      ),
    },
    {
      id: 'tax',
      header: t('sales:lineItems.taxPercent'),
      headerClassName: 'w-20 text-end',
      cellClassName: 'text-end',
      Cell: ({ line }) => (
        readonly ? (
          <span className={`text-sm ${textColors.disabled}`}>{line.tax_rate}%</span>
        ) : (
          <TaxConfigurationSelect
            value={line.tax_configuration_id ?? null}
            onChange={(configId, taxRate) => {
              handleUpdateLine(line.id, {
                tax_configuration_id: configId,
                tax_rate: parseFloat(taxRate) || 0,
              })
            }}
            {...(documentType ? { documentType: DOCUMENT_TYPE_MAP[documentType] ?? documentType } : {})}
            size="sm"
          />
        )
      ),
    },
    {
      id: 'total',
      header: t('sales:lineItems.total'),
      headerClassName: 'w-32 text-end',
      cellClassName: `whitespace-nowrap text-end text-sm font-medium ${textColors.primary}`,
      Cell: ({ line }) => <>{formatAmount(line.line_total)}</>,
    },
    ...(!readonly
      ? [
          {
            id: 'actions',
            header: <span className="sr-only">{t('common:table.actionsColumn')}</span>,
            headerClassName: 'w-12',
            cellClassName: 'text-center',
            Cell: ({ line }) => (
              <button
                type="button"
                onClick={() => {
                  handleRemoveLine(line.id)
                }}
                className={`${textColors.disabled} ${textColors.hoverError}`}
                aria-label={t('sales:lineItems.actions.removeLine')}
              >
                <Trash2 className="h-4 w-4" />
              </button>
            ),
          } satisfies LineItemsTableColumn<DocumentLine>,
        ]
      : []),
  ], [
    companyCurrency,
    designationFeatureEnabled,
    documentType,
    formatAmount,
    handleRemoveLine,
    handleUpdateLine,
    readonly,
    t,
  ])

  const totalsFooter = (
    <div className="flex justify-end">
      <dl className="w-64 space-y-2">
        <div className="flex justify-between text-sm">
          <dt className={textColors.disabled}>{t('sales:lineItems.subtotal')}</dt>
          <dd className={`font-medium ${textColors.primary}`}>{formatAmount(totals.subtotal)}</dd>
        </div>
        <div className="flex justify-between text-sm">
          <dt className={textColors.disabled}>{t('sales:lineItems.tax')}</dt>
          <dd className={`font-medium ${textColors.primary}`}>{formatAmount(totals.tax)}</dd>
        </div>
        <div className={`flex justify-between border-t ${borderColors.light} pt-2 text-base`}>
          <dt className={`font-semibold ${textColors.primary}`}>{t('sales:lineItems.total')}</dt>
          <dd className={`font-semibold ${textColors.primary}`}>{formatAmount(totals.total)}</dd>
        </div>
      </dl>
    </div>
  )

  return (
    <div className="space-y-4">
      <LineItemsTable
        title={t('sales:lineItems.title')}
        lines={lines}
        columns={lineColumns}
        getLineKey={(line) => line.id}
        emptyTitle={t('sales:lineItems.empty.title')}
        emptyDescription={!readonly ? t('sales:lineItems.empty.description') : undefined}
        readonly={readonly}
        footer={totalsFooter}
        dragAndDrop={{
          dragAriaLabel: t('sales:lineItems.actions.dragToReorder'),
          onReorder: handleReorderLines,
        }}
        addControls={(
          <div className="flex gap-2">
          <div className="relative">
            <button
              type="button"
              onClick={() => {
                setShowProductSearch(!showProductSearch)
              }}
              className={`inline-flex items-center gap-2 rounded-lg border ${borderColors.default} ${colors.white} px-4 py-2 text-sm font-medium ${textColors.secondary} ${colors.hover.gray50} transition-colors`}
            >
              <Search className="h-4 w-4" />
              {t('sales:lineItems.actions.searchProducts')}
            </button>

            {/* Product/Service Search Dropdown */}
            {showProductSearch && (
              <div className={`absolute left-0 top-full z-10 mt-1 w-80 rounded-lg border ${borderColors.light} ${colors.white} shadow-lg`}>
                {/* Tab Toggle */}
                <div className={`flex border-b ${borderColors.light}`}>
                  <button
                    type="button"
                    onClick={() => { setSearchTab('product'); setSearchQuery('') }}
                    className={`flex-1 px-4 py-2 text-sm font-medium ${activeSearchTab === 'product' ? `border-b-2 ${borderColors.primary} ${textColors.brand}` : `${textColors.disabled} ${textColors.hoverSecondary}`}`}
                  >
                    {t('sales:lineItems.tabs.product')}
                  </button>
                  {canSearchServices && (
                    <button
                      type="button"
                      onClick={() => { setSearchTab('service'); setSearchQuery('') }}
                      className={`flex-1 px-4 py-2 text-sm font-medium ${activeSearchTab === 'service' ? `border-b-2 ${borderColors.primary} ${textColors.brand}` : `${textColors.disabled} ${textColors.hoverSecondary}`}`}
                    >
                      {t('sales:lineItems.tabs.service')}
                    </button>
                  )}
                </div>
                <div className="p-3">
                  <div className="relative">
                    <input
                      type="text"
                      value={searchQuery}
                      onChange={(e) => {
                        setSearchQuery(e.target.value)
                      }}
                      placeholder={activeSearchTab === 'product' ? t('sales:lineItems.actions.searchProductsPlaceholder') : t('sales:lineItems.actions.searchServicesPlaceholder')}
                      className={`${tokens.input.base} pe-10 ps-3 text-sm`}
                      autoFocus
                    />
                    {searchQuery && (
                      <button
                        type="button"
                        onClick={() => {
                          setSearchQuery('')
                        }}
                        className={`absolute inset-y-0 end-0 flex items-center pe-3 ${textColors.disabled} ${textColors.hoverSecondary}`}
                      >
                        <X className="h-4 w-4" />
                      </button>
                    )}
                  </div>
                </div>
                <div className={`max-h-60 overflow-y-auto border-t ${borderColors.light}`}>
                  {activeSearchTab === 'product' ? (
                    <>
                      {isLoadingProducts ? (
                        <div className={`p-4 text-center text-sm ${textColors.disabled}`}>
                          {t('sales:lineItems.loading')}
                        </div>
                      ) : products.length === 0 ? (
                        <div className="p-4 text-center text-sm">
                          <p className={textColors.disabled}>
                            {searchQuery ? t('sales:lineItems.noProductsFound') : t('sales:lineItems.noProductsAvailable')}
                          </p>
                        </div>
                      ) : (
                        <ul className={`divide-y ${borderColors.divideLight}`}>
                          {products.map((product) => (
                            <li key={product.id}>
                              <button
                                type="button"
                                onClick={() => {
                                  handleAddProduct(product)
                                }}
                                className={`flex w-full items-center justify-between px-4 py-3 text-start ${colors.hover.gray50}`}
                              >
                                <div>
                                  <div className={`text-sm font-medium ${textColors.primary}`}>
                                    {product.name}
                                  </div>
                                  <div className={`text-xs ${textColors.disabled}`}>{product.sku}</div>
                                </div>
                                <div className={`text-sm font-medium ${textColors.primary}`}>
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
                        <div className={`p-4 text-center text-sm ${textColors.disabled}`}>
                          {t('sales:lineItems.loading')}
                        </div>
                      ) : services.length === 0 ? (
                        <div className="p-4 text-center text-sm">
                          <p className={textColors.disabled}>
                            {searchQuery ? t('sales:lineItems.noServicesFound') : t('sales:lineItems.noServicesAvailable')}
                          </p>
                        </div>
                      ) : (
                        <ul className={`divide-y ${borderColors.divideLight}`}>
                          {services.map((service) => (
                            <li key={service.id}>
                              <button
                                type="button"
                                onClick={() => {
                                  handleAddService(service)
                                }}
                                className={`flex w-full items-center justify-between px-4 py-3 text-start ${colors.hover.gray50}`}
                              >
                                <div>
                                  <div className="flex items-center gap-2">
                                    <span className={`text-sm font-medium ${textColors.primary}`}>
                                      {service.name}
                                    </span>
                                    <span className={`inline-flex rounded-full ${colors.neutral[100]} px-1.5 py-0.5 text-[10px] font-medium ${textColors.secondary}`}>
                                      {t('sales:lineItems.serviceBadge')}
                                    </span>
                                  </div>
                                  <div className={`text-xs ${textColors.disabled}`}>{service.code}</div>
                                </div>
                                <div className={`text-sm font-medium ${textColors.primary}`}>
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
                <div className={`space-y-1 border-t ${borderColors.light} p-2`}>
                  {activeSearchTab === 'product' && (
                    <button
                      type="button"
                      onClick={() => {
                        setShowProductSearch(false)
                        setShowProductModal(true)
                      }}
                      className={`flex w-full items-center justify-center gap-2 rounded px-3 py-2 text-sm font-medium ${textColors.brand} ${colors.primary[50]} transition-colors`}
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
                    className={`w-full rounded px-3 py-1.5 text-sm ${textColors.tertiary} ${colors.hover.gray100}`}
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
            className={`inline-flex items-center gap-2 rounded-lg border ${borderColors.default} ${colors.white} px-4 py-2 text-sm font-medium ${textColors.secondary} ${colors.hover.gray50} transition-colors`}
          >
            <Plus className="h-4 w-4" />
            {t('sales:lineItems.actions.addBlankLine')}
          </button>
        </div>
        )}
      />

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
            quantity_decimals: product.quantity_decimals ?? null,
          }
          // Add the new product to the lines
          handleAddProduct(lineProduct)
          void queryClient.invalidateQueries({
            predicate: scopedNamespacePredicate('products', tenantId, companyId),
          })
        }}
      />
    </div>
  )
}
