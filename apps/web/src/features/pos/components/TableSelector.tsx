import { useTranslation } from 'react-i18next'
import { useFloors } from '../hooks/useTables'
import { TableStatusBadge } from '../atoms/TableStatusBadge'
import type { TableData } from '../api/tableApi'

interface TableSelectorProps {
  selectedTableId: string | null
  onSelectTable: (tableId: string | null) => void
}

/**
 * Grid of table cards grouped by floor, for selecting a table during order creation.
 * Only shown for dine-in (SUR_PLACE) when the company has tables configured.
 */
export function TableSelector({ selectedTableId, onSelectTable }: TableSelectorProps) {
  const { t } = useTranslation('pos')
  const { data: floors, isLoading } = useFloors()

  if (isLoading) {
    return (
      <div className="py-4 text-center text-sm text-gray-400">
        {t('common:loading', 'Loading...')}
      </div>
    )
  }

  if (!floors || floors.length === 0) {
    return null
  }

  const allTables = floors.flatMap((floor) => floor.tables ?? [])
  if (allTables.length === 0) {
    return null
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">
          {t('tables.selectTable')}
        </h3>
        {selectedTableId && (
          <button
            type="button"
            onClick={() => { onSelectTable(null) }}
            className="text-xs text-blue-600 hover:text-blue-800 dark:text-blue-400"
          >
            {t('tables.clearSelection')}
          </button>
        )}
      </div>

      {floors.map((floor) => {
        const tables = floor.tables ?? []
        if (tables.length === 0) return null

        return (
          <div key={floor.id}>
            <p className="mb-2 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
              {floor.name}
            </p>
            <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
              {tables.map((table: TableData) => {
                const isSelected = selectedTableId === table.id
                const isAvailable = table.status === 'available'

                return (
                  <button
                    key={table.id}
                    type="button"
                    disabled={!isAvailable && !isSelected}
                    onClick={() => { onSelectTable(isSelected ? null : table.id) }}
                    className={`relative rounded-lg border-2 p-3 text-center transition-all ${
                      isSelected
                        ? 'border-blue-500 bg-blue-50 dark:border-blue-400 dark:bg-blue-900/20'
                        : isAvailable
                          ? 'border-gray-200 bg-white hover:border-blue-300 dark:border-gray-600 dark:bg-gray-800 dark:hover:border-blue-600'
                          : 'cursor-not-allowed border-gray-100 bg-gray-50 opacity-50 dark:border-gray-700 dark:bg-gray-900'
                    }`}
                  >
                    <div className="text-sm font-bold text-gray-900 dark:text-gray-100">
                      {table.table_number}
                    </div>
                    {table.label && (
                      <div className="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">
                        {table.label}
                      </div>
                    )}
                    <div className="mt-1 text-xs text-gray-400 dark:text-gray-500">
                      {table.seats} {t('tables.seats')}
                    </div>
                    <div className="mt-1">
                      <TableStatusBadge status={table.status} />
                    </div>
                  </button>
                )
              })}
            </div>
          </div>
        )
      })}
    </div>
  )
}
