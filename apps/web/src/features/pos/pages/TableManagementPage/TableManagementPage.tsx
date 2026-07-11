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
import { tokens, colors, textColors, borderColors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import { DataTable } from '@/components/molecules/DataTable/DataTable'
import { Button } from '@/components/atoms'

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
        <span className={textColors.disabled}>{t('common:loading', 'Loading...')}</span>
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-5xl space-y-8 p-6">
      <PageHeaderTitle className={cn('text-2xl font-bold', textColors.primary)}>
        {t('tables.title')}
      </PageHeaderTitle>

      {/* Floors Section */}
      <section>
        <h2 className={cn('mb-4 text-lg font-semibold', textColors.primary)}>
          {t('tables.floors')}
        </h2>

        {/* Add Floor */}
        <div className="mb-4 flex gap-2">
          <input
            type="text"
            value={newFloorName}
            onChange={(e) => { setNewFloorName(e.target.value) }}
            placeholder={t('tables.floorNamePlaceholder')}
            className={cn('flex-1 rounded-lg border px-3 py-2 text-sm', borderColors.default)}
            onKeyDown={(e) => { if (e.key === 'Enter') handleCreateFloor() }}
          />
          <Button
            type="button"
            onClick={handleCreateFloor}
            disabled={createFloor.isPending || !newFloorName.trim()}
            className={cn( 'rounded-lg')}
          >
            {t('tables.addFloor')}
          </Button>
        </div>

        {/* Floor List */}
        <div className="space-y-2">
          {(floors ?? []).map((floor: FloorData) => (
            <div
              key={floor.id}
              className={cn('flex items-center justify-between rounded-lg border bg-white p-3', borderColors.light)}
            >
              <div>
                <span className={cn('font-medium', textColors.primary)}>
                  {floor.name}
                </span>
                <span className={cn('ml-2 text-sm', textColors.tertiary)}>
                  ({floor.tables?.length ?? 0} {t('tables.tablesCount')})
                </span>
                {!floor.is_active && (
                  <span className={cn('ml-2 rounded px-1.5 py-0.5 text-xs', colors.neutral[200], textColors.tertiary)}>
                    {t('common:inactive', 'Inactive')}
                  </span>
                )}
              </div>
              <Button
                type="button"
                onClick={() => { handleDeleteFloor(floor.id) }}
                disabled={deleteFloor.isPending}
                className={cn('text-sm disabled:opacity-50', textColors.error, textColors.hoverError)}
              >
                {t('common:delete', 'Delete')}
              </Button>
            </div>
          ))}
        </div>
      </section>

      {/* Tables Section */}
      <section>
        <h2 className={cn('mb-4 text-lg font-semibold', textColors.primary)}>
          {t('tables.tablesList')}
        </h2>

        {/* Add Table */}
        <div className="mb-4 flex flex-wrap gap-2">
          <select
            value={newTableFloorId}
            onChange={(e) => { setNewTableFloorId(e.target.value) }}
            className={cn('rounded-lg border px-3 py-2 text-sm', borderColors.default)}
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
            className={cn('w-24 rounded-lg border px-3 py-2 text-sm', borderColors.default)}
          />
          <input
            type="text"
            value={newTableLabel}
            onChange={(e) => { setNewTableLabel(e.target.value) }}
            placeholder={t('tables.labelPlaceholder')}
            className={cn('flex-1 rounded-lg border px-3 py-2 text-sm', borderColors.default)}
          />
          <input
            type="number"
            value={newTableSeats}
            onChange={(e) => { setNewTableSeats(e.target.value) }}
            min="1"
            max="100"
            className={cn('w-16 rounded-lg border px-3 py-2 text-sm', borderColors.default)}
          />
          <Button
            type="button"
            onClick={handleCreateTable}
            disabled={createTable.isPending || !newTableNumber.trim()}
            className={cn( 'rounded-lg')}
          >
            {t('tables.addTable')}
          </Button>
        </div>

        {/* Table List */}
        <div className={cn('overflow-hidden rounded-lg border', borderColors.light)}>
          <DataTable className={cn('min-w-full divide-y', borderColors.divideDefault)}>
            <thead className={tokens.table.header}>
              <tr>
                <th className={cn('px-4 py-3 text-left text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                  {t('tables.tableNumber')}
                </th>
                <th className={cn('px-4 py-3 text-left text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                  {t('tables.label')}
                </th>
                <th className={cn('px-4 py-3 text-left text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                  {t('tables.floor')}
                </th>
                <th className={cn('px-4 py-3 text-right text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                  {t('tables.seats')}
                </th>
                <th className={cn('px-4 py-3 text-left text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                  {t('tables.statusLabel')}
                </th>
                <th className={cn('px-4 py-3 text-right text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                  {t('common:actions', 'Actions')}
                </th>
              </tr>
            </thead>
            <tbody className={cn('divide-y bg-white', borderColors.divideDefault)}>
              {(tables ?? []).map((table: TableData) => (
                <tr key={table.id}>
                  <td className={cn('whitespace-nowrap px-4 py-3 text-sm font-medium', textColors.primary)}>
                    {table.table_number}
                  </td>
                  <td className={cn('whitespace-nowrap px-4 py-3 text-sm', textColors.tertiary)}>
                    {table.label ?? '-'}
                  </td>
                  <td className={cn('whitespace-nowrap px-4 py-3 text-sm', textColors.tertiary)}>
                    {table.floor?.name ?? t('tables.noFloor')}
                  </td>
                  <td className={cn('whitespace-nowrap px-4 py-3 text-right text-sm tabular-nums', textColors.tertiary)}>
                    {table.seats}
                  </td>
                  <td className="whitespace-nowrap px-4 py-3">
                    <TableStatusBadge status={table.status} />
                  </td>
                  <td className="whitespace-nowrap px-4 py-3 text-right">
                    <Button
                      type="button"
                      onClick={() => { handleDeleteTable(table.id) }}
                      disabled={deleteTableMutation.isPending || table.status === 'occupied'}
                      className={cn('text-sm disabled:opacity-50', textColors.error, textColors.hoverError)}
                    >
                      {t('common:delete', 'Delete')}
                    </Button>
                  </td>
                </tr>
              ))}
              {(tables ?? []).length === 0 && (
                <tr>
                  <td colSpan={6} className={cn('px-4 py-8 text-center text-sm', textColors.disabled)}>
                    {t('tables.noTables')}
                  </td>
                </tr>
              )}
            </tbody>
          </DataTable>
        </div>
      </section>
    </div>
  )
}
