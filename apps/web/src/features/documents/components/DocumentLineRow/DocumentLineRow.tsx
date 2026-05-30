import { GripVertical, Trash2 } from 'lucide-react'
import { MarginIndicator, type MarginLevel } from '../../../../components/molecules/MarginIndicator'
import { TaxConfigurationSelect } from '../../../../components/atoms/TaxConfigurationSelect'
import { MoneyInput } from '../../../../components/atoms/MoneyInput'
import { QuantityInput } from '../../../../components/atoms/QuantityInput'
import { useTaxConfigName } from '../../../../hooks/useTaxConfigName'
import { DesignationCell } from '../DesignationCell'
import { NotesCell } from '../NotesCell'
import { useLineDesignationFeature } from '../../hooks/useLineDesignationFeature'
import { textColors } from '../../../../lib/designTokens'

export interface DocumentLineData {
  id: string
  product_id: string
  product_name: string
  description: string
  designation_default_snapshot?: string | null
  notes?: string | null
  quantity: number
  unit_price: number
  tax_rate: number
  tax_configuration_id?: string | null
  line_total: number
  cost_price?: number
}

export interface DocumentLineRowProps {
  line: DocumentLineData
  index: number
  readonly: boolean
  showMargin: boolean
  targetMargin?: number
  minimumMargin?: number
  isDragging: boolean
  documentType?: string
  /** ISO currency code used for the unit-price MoneyInput. Defaults to 'EUR'. */
  currency?: string
  onUpdate: (updates: Partial<DocumentLineData>) => void
  onRemove: () => void
  onDragStart: () => void
  onDragOver: (e: React.DragEvent) => void
  onDragEnd: () => void
  formatCurrency: (amount: number) => string
}

function calculateMargin(salePrice: number, costPrice: number): number | null {
  if (costPrice <= 0 || salePrice <= 0) return null
  return ((salePrice - costPrice) / salePrice) * 100
}

function getMarginLevel(
  margin: number | null,
  targetMargin: number,
  minimumMargin: number
): MarginLevel {
  if (margin === null) return 'red'
  if (margin < 0) return 'red'
  if (margin < minimumMargin) return 'orange'
  if (margin < targetMargin) return 'yellow'
  return 'green'
}

export function DocumentLineRow({
  line,
  readonly,
  showMargin,
  targetMargin = 25,
  minimumMargin = 15,
  isDragging,
  documentType,
  currency = 'EUR',
  onUpdate,
  onRemove,
  onDragStart,
  onDragOver,
  onDragEnd,
  formatCurrency,
}: DocumentLineRowProps) {
  const margin = line.cost_price ? calculateMargin(line.unit_price, line.cost_price) : null
  const taxConfigName = useTaxConfigName(line.tax_configuration_id)
  const level = margin !== null ? getMarginLevel(margin, targetMargin, minimumMargin) : null
  const designationFeatureEnabled = useLineDesignationFeature()

  return (
    <tr
      draggable={!readonly}
      onDragStart={onDragStart}
      onDragOver={onDragOver}
      onDragEnd={onDragEnd}
      className={isDragging ? 'bg-blue-50' : 'hover:bg-gray-50'}
    >
      {!readonly && (
        <td className="px-3 py-3 text-center">
          <button
            type="button"
            className="cursor-grab text-gray-400 hover:text-gray-600"
            aria-label="Drag to reorder"
          >
            <GripVertical className="h-4 w-4" />
          </button>
        </td>
      )}

      {/* Item Description + Notes */}
      <td className="px-4 py-3">
        {designationFeatureEnabled ? (
          <DesignationCell
            value={line.description || line.product_name}
            originalSnapshot={line.designation_default_snapshot ?? null}
            productDeleted={false /* TODO: derive from line.product_deleted once exposed in DocumentLineData DTO */}
            readOnly={readonly}
            onCommit={(next) => { onUpdate({ description: next }) }}
          />
        ) : (
          <span className={`text-sm ${textColors.primary}`}>{line.description || line.product_name}</span>
        )}
        {designationFeatureEnabled && (
          <NotesCell
            value={line.notes ?? null}
            readOnly={readonly}
            onCommit={(next) => { onUpdate({ notes: next === undefined ? null : next }) }}
          />
        )}
      </td>

      {/* Quantity */}
      <td className="px-4 py-3 text-end">
        {readonly ? (
          <span className="text-sm text-gray-900">{line.quantity}</span>
        ) : (
          <QuantityInput
            decimalPlaces={4}
            min="0"
            value={String(line.quantity)}
            onChange={(v) => { onUpdate({ quantity: parseFloat(v) || 0 }) }}
            className="w-20 rounded border border-gray-300 px-2 py-1 text-end text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          />
        )}
      </td>

      {/* Unit Price with Margin Indicator */}
      <td className="px-4 py-3 text-end">
        {readonly ? (
          <div className="flex flex-col items-end gap-1">
            <span className="text-sm text-gray-900">{formatCurrency(line.unit_price)}</span>
            {showMargin && margin !== null && level && (
              <MarginIndicator
                level={level}
                actualMargin={margin}
                message=""
                compact={true}
              />
            )}
          </div>
        ) : (
          <div className="flex flex-col items-end gap-1">
            <MoneyInput
              currency={currency}
              min="0"
              value={String(line.unit_price)}
              onChange={(v) => { onUpdate({ unit_price: parseFloat(v) || 0 }) }}
              error={level === 'red' || level === 'orange'}
              className={`w-28 rounded border px-2 py-1 text-end text-sm focus:outline-none focus:ring-1 ${
                level === 'red'
                  ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
                  : level === 'orange'
                  ? 'border-orange-300 focus:border-orange-500 focus:ring-orange-500'
                  : 'border-gray-300 focus:border-blue-500 focus:ring-blue-500'
              }`}
            />
            {showMargin && margin !== null && level && (
              <MarginIndicator
                level={level}
                actualMargin={margin}
                message=""
                compact={true}
              />
            )}
          </div>
        )}
      </td>

      {/* Tax Rate */}
      <td className="px-4 py-3 text-end">
        {readonly ? (
          <span className="text-sm text-gray-500">{taxConfigName ?? `${String(line.tax_rate)}%`}</span>
        ) : (
          <TaxConfigurationSelect
            value={line.tax_configuration_id ?? null}
            onChange={(configId, taxRate) => {
              onUpdate({ tax_configuration_id: configId, tax_rate: parseFloat(taxRate) || 0 })
            }}
            {...(documentType ? { documentType } : {})}
            size="sm"
          />
        )}
      </td>

      {/* Line Total */}
      <td className="whitespace-nowrap px-4 py-3 text-end text-sm font-medium text-gray-900">
        {formatCurrency(line.line_total)}
      </td>

      {/* Actions */}
      {!readonly && (
        <td className="px-3 py-3 text-center">
          <button
            type="button"
            onClick={onRemove}
            className="text-gray-400 hover:text-red-600"
            aria-label="Remove line"
          >
            <Trash2 className="h-4 w-4" />
          </button>
        </td>
      )}
    </tr>
  )
}

export default DocumentLineRow
