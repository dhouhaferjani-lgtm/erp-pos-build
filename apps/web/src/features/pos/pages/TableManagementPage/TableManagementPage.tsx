import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import {
  useFloors,
  useTables,
  useCreateFloor,
  useDeleteFloor,
  useCreateTable,
  useDeleteTable,
} from '../../hooks/useTables'
import { TableStatusBadge } from '../../atoms/TableStatusBadge'
import type { FloorData, TableData } from '../../api/tableApi'

/**
 * Admin page for managing floors and tables (CRUD).
 */
export function TableManagementPage() {
  const { t } = useTranslation('pos')
  const { data: floors, isLoading: floorsLoading } = useFloors()
  const { data: tables, isLoading: tablesLoading } = useTables()

  const createFloor = useCreateFloor()
  const deleteFloor = useDeleteFloor()
  const createTable = useCreateTable()
  const deleteTableMutation = useDeleteTable()

  const [newFloorName, setNewFloorName] = useState('')
  const [newTableNumber, setNewTableNumber] = useState('')
  const [newTableLabel, setNewTableLabel] = useState('')
  const [newTableSeats, setNewTableSeats] = useState('4')
  const [newTableFloorId, setNewTableFloorId] = useState<string>('')

  const handleCreateFloor = () => {
    if (!newFloorName.trim()) return
    createFloor.mutate(
      { name: newFloorName.trim(), position: (floors?.length ?? 0) },
      { onSuccess: () => { setNewFloorName('') } }
    )
  }

  const handleDeleteFloor = (floorId: string) => {
    deleteFloor.mutate(floorId)
  }

  const handleCreateTable = () => {
    if (!newTableNumber.trim()) return
    createTable.mutate(
      {
        floor_id: newTableFloorId || null,
        table_number: newTableNumber.trim(),
        label: newTableLabel.trim() || null,
        seats: parseInt(newTableSeats, 10) || 4,
      },
      {
        onSuccess: () => {
          setNewTableNumber('')
          setNewTableLabel('')
          setNewTableSeats('4')
        },
      }
    )
  }

  const handleDeleteTable = (tableId: string) => {
    deleteTableMutation.mutate(tableId)
  }

  if (floorsLoading || tablesLoading) {
    return (
      <div className="flex items-center justify-center p-12">
        <span className="text-gray-400">{t('common:loading', 'Loading...')}</span>
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-5xl space-y-8 p-6">
      <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">
        {t('tables.title')}
      </h1>

      {/* Floors Section */}
      <section>
        <h2 className="mb-4 text-lg font-semibold text-gray-800 dark:text-gray-200">
          {t('tables.floors')}
        </h2>

        {/* Add Floor */}
        <div className="mb-4 flex gap-2">
          <input
            type="text"
            value={newFloorName}
            onChange={(e) => { setNewFloorName(e.target.value) }}
            placeholder={t('tables.floorNamePlaceholder')}
            className="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
            onKeyDown={(e) => { if (e.key === 'Enter') handleCreateFloor() }}
          />
          <button
            type="button"
            onClick={handleCreateFloor}
            disabled={createFloor.isPending || !newFloorName.trim()}
            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
          >
            {t('tables.addFloor')}
          </button>
        </div>

        {/* Floor List */}
        <div className="space-y-2">
          {(floors ?? []).map((floor: FloorData) => (
            <div
              key={floor.id}
              className="flex items-center justify-between rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800"
            >
              <div>
                <span className="font-medium text-gray-900 dark:text-gray-100">
                  {floor.name}
                </span>
                <span className="ml-2 text-sm text-gray-500 dark:text-gray-400">
                  ({floor.tables?.length ?? 0} {t('tables.tablesCount')})
                </span>
                {!floor.is_active && (
                  <span className="ml-2 rounded bg-gray-200 px-1.5 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-400">
                    {t('common:inactive', 'Inactive')}
                  </span>
                )}
              </div>
              <button
                type="button"
                onClick={() => { handleDeleteFloor(floor.id) }}
                disabled={deleteFloor.isPending}
                className="text-sm text-red-600 hover:text-red-800 disabled:opacity-50 dark:text-red-400"
              >
                {t('common:delete', 'Delete')}
              </button>
            </div>
          ))}
        </div>
      </section>

      {/* Tables Section */}
      <section>
        <h2 className="mb-4 text-lg font-semibold text-gray-800 dark:text-gray-200">
          {t('tables.tablesList')}
        </h2>

        {/* Add Table */}
        <div className="mb-4 flex flex-wrap gap-2">
          <select
            value={newTableFloorId}
            onChange={(e) => { setNewTableFloorId(e.target.value) }}
            className="rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
          >
            <option value="">{t('tables.noFloor')}</option>
            {(floors ?? []).map((floor: FloorData) => (
              <option key={floor.id} value={floor.id}>
                {floor.name}
              </option>
            ))}
          </select>
          <input
            type="text"
            value={newTableNumber}
            onChange={(e) => { setNewTableNumber(e.target.value) }}
            placeholder={t('tables.tableNumberPlaceholder')}
            className="w-24 rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
          />
          <input
            type="text"
            value={newTableLabel}
            onChange={(e) => { setNewTableLabel(e.target.value) }}
            placeholder={t('tables.labelPlaceholder')}
            className="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
          />
          <input
            type="number"
            value={newTableSeats}
            onChange={(e) => { setNewTableSeats(e.target.value) }}
            min="1"
            max="100"
            className="w-16 rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
          />
          <button
            type="button"
            onClick={handleCreateTable}
            disabled={createTable.isPending || !newTableNumber.trim()}
            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
          >
            {t('tables.addTable')}
          </button>
        </div>

        {/* Table List */}
        <div className="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
          <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead className="bg-gray-50 dark:bg-gray-800">
              <tr>
                <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                  {t('tables.tableNumber')}
                </th>
                <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                  {t('tables.label')}
                </th>
                <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                  {t('tables.floor')}
                </th>
                <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                  {t('tables.seats')}
                </th>
                <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                  {t('tables.statusLabel')}
                </th>
                <th className="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                  {t('common:actions', 'Actions')}
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-900">
              {(tables ?? []).map((table: TableData) => (
                <tr key={table.id}>
                  <td className="whitespace-nowrap px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">
                    {table.table_number}
                  </td>
                  <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                    {table.label ?? '-'}
                  </td>
                  <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                    {table.floor?.name ?? t('tables.noFloor')}
                  </td>
                  <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                    {table.seats}
                  </td>
                  <td className="whitespace-nowrap px-4 py-3">
                    <TableStatusBadge status={table.status} />
                  </td>
                  <td className="whitespace-nowrap px-4 py-3 text-right">
                    <button
                      type="button"
                      onClick={() => { handleDeleteTable(table.id) }}
                      disabled={deleteTableMutation.isPending || table.status === 'occupied'}
                      className="text-sm text-red-600 hover:text-red-800 disabled:opacity-50 dark:text-red-400"
                    >
                      {t('common:delete', 'Delete')}
                    </button>
                  </td>
                </tr>
              ))}
              {(tables ?? []).length === 0 && (
                <tr>
                  <td colSpan={6} className="px-4 py-8 text-center text-sm text-gray-400">
                    {t('tables.noTables')}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  )
}
