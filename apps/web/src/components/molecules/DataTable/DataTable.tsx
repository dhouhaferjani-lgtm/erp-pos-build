import type React from 'react'
import { cn } from '@/lib/utils'
import { borderColors, colors, textColors, tokens } from '@/lib/designTokens'
import { EmptyState } from '@/components/molecules/EmptyState'
import { Checkbox } from '@/components/atoms'

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

/**
 * Opt-in row-selection capability for {@link DataTable}. When supplied, the
 * table renders a LEADING checkbox column (header = select-all, one checkbox per
 * selectable row). When omitted, the table renders exactly as before.
 *
 * Selection state is fully owned by the caller — DataTable is presentational and
 * only emits toggle intents.
 */
export interface DataTableSelection<T> {
  /** Ids (as produced by `keyExtractor`, coerced to string) currently selected. */
  selectedIds: Set<string>
  /** Toggle a single row's selection. */
  onToggle: (id: string) => void
  /** Toggle all currently-visible selectable rows. */
  onToggleAll: () => void
  /** Optional predicate gating which rows are selectable. Defaults to all. */
  isRowSelectable?: (row: T) => boolean
  /** Optional accessible label builder for a row's checkbox. */
  getRowLabel?: (row: T) => string
  /** Optional accessible label for the header select-all checkbox. */
  selectAllLabel?: string
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
  /** Opt-in row selection. When provided, a leading checkbox column renders. */
  selection?: DataTableSelection<T>
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
  selection,
  className,
}: DataTableProps<T>) {
  const isInteractive = Boolean(onRowClick)
  const hasSelection = Boolean(selection)

  const isRowSelectable = (row: T): boolean =>
    selection?.isRowSelectable ? selection.isRowSelectable(row) : true

  const selectableRows = selection ? data.filter(isRowSelectable) : []
  const selectedCount = selection
    ? selectableRows.filter((row, index) =>
        selection.selectedIds.has(String(keyExtractor(row, index))),
      ).length
    : 0
  const allSelected =
    selectableRows.length > 0 && selectedCount === selectableRows.length
  const someSelected = selectedCount > 0 && !allSelected

  const renderHeader = () => (
    <thead className={tokens.table.header}>
      <tr>
        {hasSelection ? (
          <th
            scope="col"
            className="px-4 py-2 w-px"
          >
            <Checkbox
              aria-label={selection?.selectAllLabel ?? 'Select all'}
              checked={allSelected}
              indeterminate={someSelected}
              onChange={() => selection?.onToggleAll()}
            />
          </th>
        ) : null}
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
    <tbody className={cn('divide-y', borderColors.divideDefault)}>
      {Array.from({ length: loadingRowCount }).map((_, rowIndex) => (
        <tr key={`skeleton-${String(rowIndex)}`}>
          {hasSelection ? (
            <td className="px-4 py-3 w-px">
              <div className={cn('animate-pulse h-4 w-4 rounded', colors.neutral[200])} />
            </td>
          ) : null}
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
    <tbody className={cn('divide-y', borderColors.divideDefault)}>
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
            {hasSelection ? (
              <td
                className="px-4 py-3 w-px"
                onClick={(event) => {
                  // Selecting a row must never trigger the row's onRowClick.
                  event.stopPropagation()
                }}
              >
                {isRowSelectable(row) ? (
                  <Checkbox
                    aria-label={
                      selection?.getRowLabel
                        ? selection.getRowLabel(row)
                        : 'Select row'
                    }
                    checked={selection?.selectedIds.has(
                      String(keyExtractor(row, rowIndex)),
                    )}
                    onChange={() =>
                      selection?.onToggle(String(keyExtractor(row, rowIndex)))
                    }
                  />
                ) : null}
              </td>
            ) : null}
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
