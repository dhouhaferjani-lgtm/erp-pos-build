import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ClipboardList, Plus } from 'lucide-react'
import { Button } from '@/components/atoms/Button'
import { Select } from '@/components/atoms'
import { DataTable, type DataTableColumn } from '@/components/molecules/DataTable/DataTable'
import { EmptyState } from '@/components/molecules/EmptyState'
import { PageHeader } from '@/components/molecules/PageHeader'
import { OffsetPagination } from '@/components/ui/OffsetPagination'
import { RequirePermission } from '@/features/auth/components/RequirePermission'
import { textColors } from '@/lib/designTokens'
import { entityRoutes } from '@/lib/entityRoutes'
import { useStockAdjustmentList } from '../api/queries'
import { StockAdjustmentStatusBadge } from '../components/StockAdjustmentStatusBadge'
import { toStockAdjustmentStatus } from '../types'
import type { StockAdjustment, StockAdjustmentListFilters, StockAdjustmentStatus } from '../types'

const STATUS_OPTIONS: readonly (StockAdjustmentStatus | 'all')[] = [
  'all',
  'draft',
  'posted',
  'cancelled',
]

function isStatusOrAll(value: string): value is StockAdjustmentStatus | 'all' {
  return (STATUS_OPTIONS as readonly string[]).includes(value)
}

export function StockAdjustmentListPage() {
  const { t } = useTranslation(['stock-adjustments', 'common'])
  const [filters, setFilters] = useState<StockAdjustmentListFilters>({
    status: 'all',
    page: 1,
    per_page: 25,
  })

  const { data, isLoading } = useStockAdjustmentList(filters)
  const adjustments = data?.data ?? []
  const meta = data?.meta

  const columns: DataTableColumn<StockAdjustment>[] = [
    {
      key: 'number',
      header: t('list.number'),
      render: (adjustment) => (
        <Link
          to={entityRoutes.stockAdjustment(adjustment.id)}
          className={`font-medium ${textColors.primary}`}
        >
          {adjustment.adjustment_number ?? t('detail.draftTitle')}
        </Link>
      ),
    },
    {
      key: 'status',
      header: t('list.status'),
      render: (adjustment) => {
        const status = toStockAdjustmentStatus(adjustment.status)
        return status === null ? adjustment.status : <StockAdjustmentStatusBadge status={status} />
      },
    },
    {
      key: 'location',
      header: t('list.location'),
      render: (adjustment) => adjustment.location_name ?? '—',
    },
    {
      key: 'occurred_at',
      header: t('list.occurredAt'),
      render: (adjustment) => new Date(adjustment.occurred_at).toLocaleString(),
    },
    {
      key: 'created_by',
      header: t('list.createdBy'),
      render: (adjustment) => adjustment.created_by_name ?? '—',
    },
  ]

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('title')}
        subtitle={t('subtitle')}
        actions={
          // The destination route is `.create`-gated, so a `.view`-only operator
          // would otherwise be walked into a Permission Denied screen.
          <RequirePermission permission="inventory.adjustments.create" fallback={null}>
            <Link to="/inventory/stock-adjustments/new">
              <Button variant="primary">
                <Plus className="me-2 h-4 w-4" />
                {t('create.title')}
              </Button>
            </Link>
          </RequirePermission>
        }
        className="mb-0"
      />

      <div className="flex items-center gap-3">
        <Select
          aria-label={t('list.filterStatus')}
          value={filters.status ?? 'all'}
          onChange={(event) => {
            const raw = event.target.value
            if (isStatusOrAll(raw)) {
              // Every filter change resets to page 1 — otherwise a narrower
              // filter lands the operator on an empty page N.
              setFilters({ ...filters, status: raw, page: 1 })
            }
          }}
          className="w-48"
        >
          {STATUS_OPTIONS.map((option) => (
            <option key={option} value={option}>
              {option === 'all' ? t('list.all') : t(`status.${option}`)}
            </option>
          ))}
        </Select>
      </div>

      <DataTable
        columns={columns}
        data={adjustments}
        keyExtractor={(adjustment) => adjustment.id}
        isLoading={isLoading}
        emptyState={
          <div className="py-6">
            <EmptyState
              icon={<ClipboardList className={`mx-auto h-12 w-12 ${textColors.disabled}`} />}
              title={t('list.empty')}
            />
          </div>
        }
      />

      {meta !== undefined && meta.last_page > 1 && (
        <OffsetPagination
          currentPage={meta.current_page}
          lastPage={meta.last_page}
          total={meta.total}
          perPage={meta.per_page}
          from={meta.from}
          to={meta.to}
          onPageChange={(page) => {
            setFilters({ ...filters, page })
          }}
          onPerPageChange={(perPage) => {
            setFilters({ ...filters, per_page: perPage, page: 1 })
          }}
        />
      )}
    </div>
  )
}
