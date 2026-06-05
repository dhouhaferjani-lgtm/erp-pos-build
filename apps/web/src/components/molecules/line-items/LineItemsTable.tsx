import { useState, type ComponentType, type DragEvent, type ReactNode } from 'react'
import { GripVertical } from 'lucide-react'
import { QuantityInput } from '../../atoms/QuantityInput/QuantityInput'
import { borderColors, colors, textColors, tokens } from '../../../lib/designTokens'

export interface LineItemsTableColumn<TLine> {
  id: string
  header: ReactNode
  headerClassName?: string
  cellClassName?: string
  Cell: ComponentType<{ line: TLine; index: number }>
}

export interface LineItemsTableDragConfig {
  dragAriaLabel: string
  onReorder: (fromIndex: number, toIndex: number) => void
}

export interface LineItemsTableProps<TLine> {
  title?: ReactNode
  lines: readonly TLine[]
  columns: readonly LineItemsTableColumn<TLine>[]
  getLineKey: (line: TLine, index: number) => string
  emptyTitle: ReactNode
  emptyDescription?: ReactNode
  readonly?: boolean
  footer?: ReactNode
  addControls?: ReactNode
  dragAndDrop?: LineItemsTableDragConfig
}

export function LineItemsTable<TLine>({
  title,
  lines,
  columns,
  getLineKey,
  emptyTitle,
  emptyDescription,
  readonly = false,
  footer,
  addControls,
  dragAndDrop,
}: LineItemsTableProps<TLine>) {
  const [draggedIndex, setDraggedIndex] = useState<number | null>(null)
  const dragEnabled = !readonly && dragAndDrop !== undefined

  const handleDragStart = (index: number) => {
    setDraggedIndex(index)
  }

  const handleDragOver = (event: DragEvent<HTMLTableRowElement>, index: number) => {
    event.preventDefault()
    if (draggedIndex === null || draggedIndex === index || dragAndDrop === undefined) return
    dragAndDrop.onReorder(draggedIndex, index)
    setDraggedIndex(index)
  }

  const handleDragEnd = () => {
    setDraggedIndex(null)
  }

  return (
    <div className="space-y-4">
      <div className={`overflow-hidden rounded-lg border ${borderColors.light} ${colors.white}`}>
        {title !== undefined && (
          <div className={`border-b ${borderColors.light} px-6 py-4`}>
            <h3 className={`text-lg font-semibold ${textColors.primary}`}>{title}</h3>
          </div>
        )}

        {lines.length === 0 ? (
          <div className={`p-8 text-center ${textColors.disabled}`}>
            <p>{emptyTitle}</p>
            {emptyDescription !== undefined && (
              <p className={`mt-2 text-sm ${textColors.tertiary}`}>{emptyDescription}</p>
            )}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className={`min-w-full divide-y ${borderColors.divideDefault}`}>
              <thead className={colors.neutral[50]}>
                <tr>
                  {dragEnabled && (
                    <th className="w-10 px-3 py-3">
                      <span className="sr-only">{dragAndDrop.dragAriaLabel}</span>
                    </th>
                  )}
                  {columns.map((column) => (
                    <th
                      key={column.id}
                      scope="col"
                      className={`px-4 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.disabled} ${column.headerClassName ?? ''}`}
                    >
                      {column.header}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className={`divide-y ${borderColors.divideDefault}`}>
                {lines.map((line, index) => (
                  <tr
                    key={getLineKey(line, index)}
                    draggable={dragEnabled}
                    onDragStart={() => {
                      if (dragEnabled) handleDragStart(index)
                    }}
                    onDragOver={(event) => {
                      if (dragEnabled) handleDragOver(event, index)
                    }}
                    onDragEnd={handleDragEnd}
                    className={draggedIndex === index ? colors.primary[50] : colors.hover.gray50}
                  >
                    {dragEnabled && (
                      <td className="px-3 py-3 text-center">
                        <span
                          className={`cursor-grab ${textColors.disabled} ${textColors.hoverSecondary}`}
                          aria-hidden="true"
                        >
                          <GripVertical className="h-4 w-4" />
                        </span>
                      </td>
                    )}
                    {columns.map((column) => {
                      const Cell = column.Cell
                      return (
                        <td key={column.id} className={`px-4 py-3 ${column.cellClassName ?? ''}`}>
                          <Cell line={line} index={index} />
                        </td>
                      )
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {footer !== undefined && (
          <div className={`border-t ${borderColors.light} ${colors.neutral[50]} px-6 py-4`}>
            {footer}
          </div>
        )}
      </div>

      {addControls !== undefined && !readonly && <div>{addControls}</div>}
    </div>
  )
}

export interface QuantityCellProps {
  value: string | number
  onChange: (value: string) => void
  decimalPlaces: number
  readonly?: boolean
  min?: string
  ariaLabel?: string
  className?: string
}

export function QuantityCell({
  value,
  onChange,
  decimalPlaces,
  readonly = false,
  min = '0',
  ariaLabel,
  className = 'w-20 text-end text-sm',
}: QuantityCellProps) {
  if (readonly) {
    return <span className={`text-sm ${textColors.primary}`}>{value}</span>
  }

  return (
    <QuantityInput
      decimalPlaces={decimalPlaces}
      min={min}
      value={String(value)}
      onChange={(next) => {
        onChange(next)
      }}
      aria-label={ariaLabel}
      className={`${tokens.input.base} ${className}`}
    />
  )
}
