import { useState, useCallback, useEffect, useMemo, useRef } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, Trash2 } from 'lucide-react'
import { formatCurrency } from '../../../lib/format'
import { bcadd, bccomp, bcdiv, bcmul, bcsub } from '../../../lib/decimal'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import { AddQuickProductModal } from '../../../components/organisms/AddQuickProductModal/AddQuickProductModal'
import { TaxConfigurationSelect } from '../../../components/atoms/TaxConfigurationSelect/TaxConfigurationSelect'
import { MoneyInput } from '../../../components/atoms/MoneyInput/MoneyInput'
import { LineItemsTable, QuantityCell, type LineItemsTableColumn } from '../../../components/molecules/line-items/LineItemsTable'
import { LineItemEntryBar, ProductCell, type LineItemEntryAddMeta, type ProductLineProduct } from '../../../components/molecules/line-items'
import { DesignationCell } from './DesignationCell'
import { NotesCell } from './NotesCell'
import { useLineDesignationFeature } from '../hooks/useLineDesignationFeature'
import { getQuantityDecimals } from '../../../lib/quantityScale'
import { borderColors, colors, textColors, tokens } from '../../../lib/designTokens'

// Map frontend document type strings to backend applicable_document_types format
const DOCUMENT_TYPE_MAP: Record<string, string> = {
  invoice: 'TAX_INVOICE',
  delivery_note: 'DELIVERY_NOTE',
  credit_note: 'CREDIT_NOTE',
  return_note: 'RETURN_NOTE',
}

function taxSelectorDocumentType(documentType: string | undefined): string | undefined {
  if (!documentType) return undefined
  return DOCUMENT_TYPE_MAP[documentType]
}

function decimalValue(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '0'
  return String(value)
}

function calculateDiscountedSubtotal(
  quantity: string | number,
  unitPrice: string | number,
  discountPercent?: string | null,
  discountAmount?: string | null,
): string {
  const grossSubtotal = bcmul(decimalValue(quantity), decimalValue(unitPrice))
  const discount = discountPercent !== undefined && discountPercent !== null && discountPercent !== ''
    ? bcmul(grossSubtotal, bcdiv(discountPercent, '100', 6))
    : decimalValue(discountAmount)

  if (bccomp(discount, grossSubtotal) >= 0) return '0.000'

  return bcsub(grossSubtotal, discount)
}

function calculateLineTax(discountedSubtotal: string, taxRate: string | number): string {
  return bcmul(discountedSubtotal, bcdiv(decimalValue(taxRate), '100', 6))
}

function calculateLineTotal(
  quantity: string | number,
  unitPrice: string | number,
  taxRate: string | number,
  discountPercent?: string | null,
  discountAmount?: string | null,
): string {
  const discountedSubtotal = calculateDiscountedSubtotal(quantity, unitPrice, discountPercent, discountAmount)
  return bcadd(discountedSubtotal, calculateLineTax(discountedSubtotal, taxRate))
}

interface Product {
  id: string
  name: string
  sku?: string | null
  barcode?: string | null
  sale_price?: string | number | null
  tax_rate?: string | number | null
  default_tax_configuration_id?: string | null
  quantity_decimals?: number | null
  primary_image_url?: string | null
  has_variants?: boolean
  requires_batch_tracking?: boolean
}

export interface DocumentLine {
  id: string
  product_id: string
  variant_id?: string | null
  service_id?: string
  product_code?: string
  product_barcode?: string | null
  product_name: string
  primary_image_url?: string | null
  description: string
  designation_default_snapshot?: string | null
  notes?: string | null
  quantity: number
  unit_price: number
  discount_percent?: string | null
  discount_amount?: string | null
  tax_rate: number
  tax_configuration_id?: string | null
  line_total: string | number
  is_service?: boolean
  /** Unit precision (unit decimal_places) → drives the qty input step. */
  quantity_decimals?: number | null
}

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
  const designationFeatureEnabled = useLineDesignationFeature()
  const [showProductModal, setShowProductModal] = useState(false)
  const linesRef = useRef(lines)

  useEffect(() => {
    linesRef.current = lines
  }, [lines])

  // Get company currency with fallback
  const companyCurrency = currentCompany?.currency ?? 'EUR'
  const companyLocale = currentCompany?.locale.replace('_', '-') ?? 'en-US'
  const taxDocumentType = taxSelectorDocumentType(documentType)

  // Calculate totals
  const totals = useMemo(() => {
    const subtotal = lines.reduce(
      (sum, line) => bcadd(sum, calculateDiscountedSubtotal(
        line.quantity,
        line.unit_price,
        line.discount_percent,
        line.discount_amount,
      )),
      '0.000',
    )
    const tax = lines.reduce(
      (sum, line) => {
        const lineSubtotal = calculateDiscountedSubtotal(
          line.quantity,
          line.unit_price,
          line.discount_percent,
          line.discount_amount,
        )
        return bcadd(sum, calculateLineTax(lineSubtotal, line.tax_rate))
      },
      '0.000',
    )
    return {
      subtotal,
      tax,
      total: bcadd(subtotal, tax),
    }
  }, [lines])

  // Generate unique ID for new lines
  const generateId = () => `line-${String(Date.now())}-${Math.random().toString(36).substring(2, 11)}`

  // Add product to lines
  const handleAddProduct = useCallback(
    (product: Product | ProductLineProduct, meta?: Partial<LineItemEntryAddMeta>) => {
      const variantId = meta?.variantId ?? null
      const incrementBy = meta?.incrementBy ?? 1
      const existingLine = linesRef.current.find((line) =>
        !line.is_service &&
        line.product_id === product.id &&
        (line.variant_id ?? null) === variantId
      )

      if (existingLine !== undefined) {
        onChange(
          linesRef.current.map((line) => {
            if (line.id !== existingLine.id) return line
            const quantity = line.quantity + incrementBy
            return {
              ...line,
              quantity,
              line_total: calculateLineTotal(
                quantity,
                line.unit_price,
                line.tax_rate,
                line.discount_percent,
                line.discount_amount,
              ),
            }
          })
        )
        return
      }

      const salePrice = Number(product.sale_price ?? 0) || 0
      const taxRate = Number(product.tax_rate ?? 0) || 0
      const newLine: DocumentLine = {
        id: generateId(),
        product_id: product.id,
        variant_id: variantId,
        product_code: product.sku ?? '',
        product_barcode: product.barcode ?? null,
        product_name: product.name,
        primary_image_url: product.primary_image_url ?? null,
        description: product.name,
        designation_default_snapshot: product.name,
        notes: null,
        quantity: incrementBy,
        unit_price: salePrice,
        discount_percent: null,
        discount_amount: null,
        tax_rate: taxRate,
        tax_configuration_id: product.default_tax_configuration_id ?? null,
        line_total: calculateLineTotal(incrementBy, salePrice, taxRate, null, null),
        quantity_decimals: product.quantity_decimals ?? null,
      }
      onChange([...linesRef.current, newLine])
    },
    [onChange]
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
      discount_percent: null,
      discount_amount: null,
      tax_rate: 0,
      line_total: '0.000',
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
          // Recalculate line total if quantity, price, discount, or tax changed
          if (
            'quantity' in updates ||
            'unit_price' in updates ||
            'discount_percent' in updates ||
            'discount_amount' in updates ||
            'tax_rate' in updates
          ) {
            updatedLine.line_total = calculateLineTotal(
              updatedLine.quantity,
              updatedLine.unit_price,
              updatedLine.tax_rate,
              updatedLine.discount_percent,
              updatedLine.discount_amount,
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
    return formatCurrency(amount, {
      currency: companyCurrency,
      locale: companyLocale,
    })
  }, [companyCurrency, companyLocale])

  const lineColumns = useMemo<LineItemsTableColumn<DocumentLine>[]>(() => [
    {
      id: 'article',
      header: t('sales:lineItems.article'),
      headerClassName: 'min-w-56',
      Cell: ({ line }) => (
        <div className="min-w-56">
          <ProductCell
            size="sm"
            product={{
              name: line.product_name || line.description || '-',
              sku: line.product_code ?? null,
              barcode: line.product_barcode ?? null,
              primary_image_url: line.primary_image_url ?? null,
            }}
          />
          {line.is_service && (
            <span className={`mt-1 inline-flex rounded-full ${colors.neutral[100]} px-1.5 py-0.5 text-[10px] font-medium ${textColors.secondary}`}>
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
      id: 'discount',
      header: t('sales:lineItems.discount'),
      headerClassName: 'w-28 text-end',
      cellClassName: 'text-end',
      Cell: ({ line }) => (
        readonly ? (
          <span className={`text-sm ${textColors.primary}`}>{line.discount_percent ?? '0'}%</span>
        ) : (
          <input
            type="number"
            inputMode="decimal"
            min="0"
            max="100"
            value={line.discount_percent ?? ''}
            onChange={(event) => {
              handleUpdateLine(line.id, {
                discount_percent: event.target.value === '' ? null : event.target.value,
                discount_amount: null,
              })
            }}
            aria-label={t('sales:lineItems.discount')}
            className={`${tokens.input.base} w-20 text-end text-sm`}
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
                tax_rate: Number(taxRate) || 0,
              })
            }}
            {...(taxDocumentType !== undefined ? { documentType: taxDocumentType } : {})}
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
    formatAmount,
    handleRemoveLine,
    handleUpdateLine,
    readonly,
    t,
    taxDocumentType,
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
          <div className="flex flex-col gap-2 md:flex-row md:items-start">
            <div className="min-w-0 flex-1">
              <LineItemEntryBar
                onAddProduct={(product, meta) => {
                  handleAddProduct(product, meta)
                }}
                onCreateFromCode={() => {
                  setShowProductModal(true)
                }}
                onRequiresVariant={() => {
                  // Phase 1 blocks parent-with-variants scans rather than adding
                  // an ambiguous document line. Variant chooser lands in Phase 1B.
                }}
              />
            </div>
            <button
              type="button"
              onClick={handleAddBlankLine}
              className={`inline-flex items-center justify-center gap-2 rounded-lg border ${borderColors.default} ${colors.white} px-4 py-2 text-sm font-medium ${textColors.secondary} ${colors.hover.gray50} transition-colors`}
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
