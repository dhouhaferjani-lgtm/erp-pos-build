import type React from 'react'
import { cn } from '@/lib/utils'
import { colors, textColors, tokens } from '@/lib/designTokens'
import { EmptyState } from '@/components/molecules/EmptyState'

/**
 * Canonical column descriptor for {@link DataTable}.
 *
 * Cell values are produced exclusively by {@link DataTableColumn.render} (or
 * {@link DataTableColumn.accessor}) so the table stays strictly typed — there is
 * no `(row as any)[key]` fallback.
 */
export interface DataTableColumn<T> {
  /** Stable identity for the column (also used as the React key). */
  key: string
  /** Header cell content. */
  header: React.ReactNode
  /** Horizontal alignment of the column's cells. Defaults to `'left'`. */
  align?: 'left' | 'right' | 'center'
  /**
   * Marks the column as numeric: cells are right-aligned and rendered with
   * `tabular-nums` so digits line up across rows.
   */
  numeric?: boolean
  /** Produces the cell content for a given row. Preferred over `accessor`. */
  render?: (row: T, index: number) => React.ReactNode
  /** Alternative to `render` for simple value extraction. */
  accessor?: (row: T) => React.ReactNode
  /** Extra classes for the header cell. */
  headerClassName?: string
  /** Extra classes for each body cell in this column. */
  cellClassName?: string
  /** Optional fixed/min width (any CSS width value). */
  width?: string
}

export interface DataTableProps<T> {
  columns: DataTableColumn<T>[]
  data: T[]
  keyExtractor: (row: T, index: number) => string | number
  isLoading?: boolean
  /** Number of skeleton rows to show while loading. Defaults to `5`. */
  loadingRowCount?: number
  emptyTitle?: string
  emptyDescription?: string
  /** Custom empty-state node; overrides `emptyTitle`/`emptyDescription`. */
  emptyState?: React.ReactNode
  onRowClick?: (row: T) => void
  className?: string
}

function alignClass<T>(column: DataTableColumn<T>): string {
  if (column.numeric) return 'text-right tabular-nums'
  switch (column.align) {
    case 'right':
      return 'text-right'
    case 'center':
      return 'text-center'
    default:
      return 'text-left'
  }
}

function cellContent<T>(
  column: DataTableColumn<T>,
  row: T,
  index: number,
): React.ReactNode {
  if (column.render) return column.render(row, index)
  if (column.accessor) return column.accessor(row)
  return null
}

/**
 * The canonical list table for AutoERP. Renders a consistent header / hover /
 * alignment treatment via design tokens, right-aligns numeric columns with
 * `tabular-nums`, and provides built-in loading-skeleton and empty states.
 */
export function DataTable<T>({
  columns,
  data,
  keyExtractor,
  isLoading = false,
  loadingRowCount = 5,
  emptyTitle,
  emptyDescription,
  emptyState,
  onRowClick,
  className,
}: DataTableProps<T>) {
  const isInteractive = Boolean(onRowClick)

  const renderHeader = () => (
    <thead className={tokens.table.header}>
      <tr>
        {columns.map((column) => (
          <th
            key={column.key}
            scope="col"
            style={column.width ? { width: column.width } : undefined}
            className={cn(
              'px-4 py-2 text-xs font-medium uppercase tracking-wide',
              textColors.tertiary,
              alignClass(column),
              column.headerClassName,
            )}
          >
            {column.header}
          </th>
        ))}
      </tr>
    </thead>
  )

  const renderSkeletonBody = () => (
    <tbody className="divide-y divide-gray-200">
      {Array.from({ length: loadingRowCount }).map((_, rowIndex) => (
        <tr key={`skeleton-${String(rowIndex)}`}>
          {columns.map((column) => (
            <td key={column.key} className="px-4 py-3">
              <div className={cn('animate-pulse h-4 w-3/4 rounded', colors.neutral[200])} />
            </td>
          ))}
        </tr>
      ))}
    </tbody>
  )

  const renderBody = () => (
    <tbody className="divide-y divide-gray-200">
      {data.map((row, rowIndex) => {
        const handleClick = isInteractive
          ? () => onRowClick?.(row)
          : undefined
        const handleKeyDown = isInteractive
          ? (event: React.KeyboardEvent<HTMLTableRowElement>) => {
              if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault()
                onRowClick?.(row)
              }
            }
          : undefined

        return (
          <tr
            key={keyExtractor(row, rowIndex)}
            className={cn(
              tokens.table.rowHover,
              isInteractive && 'cursor-pointer',
            )}
            onClick={handleClick}
            onKeyDown={handleKeyDown}
            role={isInteractive ? 'button' : undefined}
            tabIndex={isInteractive ? 0 : undefined}
          >
            {columns.map((column) => (
              <td
                key={column.key}
                className={cn(
                  'px-4 py-3 text-sm',
                  textColors.primary,
                  alignClass(column),
                  column.cellClassName,
                )}
              >
                {cellContent(column, row, rowIndex)}
              </td>
            ))}
          </tr>
        )
      })}
    </tbody>
  )

  const showEmpty = !isLoading && data.length === 0

  return (
    <div className={cn('overflow-x-auto', className)}>
      <table className="min-w-full border-collapse text-left">
        {renderHeader()}
        {isLoading ? renderSkeletonBody() : !showEmpty ? renderBody() : null}
      </table>
      {showEmpty
        ? (emptyState ?? (
            <EmptyState
              title={emptyTitle ?? ''}
              {...(emptyDescription !== undefined ? { description: emptyDescription } : {})}
            />
          ))
        : null}
    </div>
  )
}

export default DataTable
