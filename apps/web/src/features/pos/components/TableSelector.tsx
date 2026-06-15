import { useTranslation } from 'react-i18next'
import { colors, textColors, borderColors, tokens } from '@/lib/designTokens'
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
      <div className={`py-4 text-center text-sm ${textColors.disabled}`}>
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
        <h3 className={`text-sm font-semibold ${textColors.secondary}`}>
          {t('tables.selectTable')}
        </h3>
        {selectedTableId && (
          <button
            type="button"
            onClick={() => { onSelectTable(null) }}
            className={`text-xs ${textColors.brand} ${textColors.hoverPrimary}`}
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
            <p className={`mb-2 text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}>
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
                        ? `${borderColors.primary} ${colors.primary[50]}`
                        : isAvailable
                          ? `${borderColors.light} ${colors.white} ${tokens.card.hoverPrimary}`
                          : `cursor-not-allowed ${borderColors.light} ${colors.neutral[50]} opacity-50`
                    }`}
                  >
                    <div className={`text-sm font-bold ${textColors.primary}`}>
                      {table.table_number}
                    </div>
                    {table.label && (
                      <div className={`mt-0.5 truncate text-xs ${textColors.tertiary}`}>
                        {table.label}
                      </div>
                    )}
                    <div className={`mt-1 text-xs ${textColors.disabled}`}>
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
