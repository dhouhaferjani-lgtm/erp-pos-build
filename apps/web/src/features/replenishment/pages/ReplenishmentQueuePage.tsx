import { useMemo, useState } from 'react'
import { StickyNote } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { RequirePermission } from '@/components/auth'
import { Button } from '@/components/atoms/Button/Button'
import { Checkbox } from '@/components/atoms/Checkbox/Checkbox'
import { LoadingSpinner } from '@/components/atoms/Spinner/Spinner'
import { EmptyState } from '@/components/molecules/EmptyState/EmptyState'
import { FilterTabs } from '@/components/molecules/FilterTabs/FilterTabs'
import { PageHeader } from '@/components/molecules/PageHeader'
import { QueryError } from '@/components/QueryError'
import { OffsetPagination } from '@/components/ui/OffsetPagination'
import { DateRangeFilter } from '@/components/ui/filters/DateRangeFilter'
import { LocationSelectorMulti } from '@/features/locations/components/LocationSelectorMulti'
import { borderColors, colors, textColors, tokens } from '@/lib/designTokens'
import { useOpenReplenishment, useReplenishmentHistory } from '../api/queries'
import { ReplenishmentStatusBadge } from '../components/ReplenishmentStatusBadge'
import { RequestContextPanel } from '../components/RequestContextPanel'
import type { ReplenishmentLine, ReplenishmentStatus } from '../types'

type QueueView = 'shop' | 'product'
type StatusFilter = 'open' | ReplenishmentStatus

interface QueueFilters {
  locationIds: string[]
  from: string | undefined
  to: string | undefined
}

interface MatrixRow {
  key: string
  productName: string
  cells: Partial<Record<string, ReplenishmentLine>>
  representative: ReplenishmentLine
}

function toggleId(current: Set<string>, id: string): Set<string> {
  const next = new Set(current)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  return next
}

function OpenQueue({ filters }: { filters: QueueFilters }) {
  const { t } = useTranslation('replenishment')
  const [view, setView] = useState<QueueView>('shop')
  const [selectedIds, setSelectedIds] = useState<Set<string>>(() => new Set())
  const [contextLine, setContextLine] = useState<ReplenishmentLine | null>(null)
  const query = useOpenReplenishment({
    ...(filters.locationIds.length > 0 ? { location_ids: filters.locationIds } : {}),
    ...(filters.from ? { from: filters.from } : {}),
    ...(filters.to ? { to: filters.to } : {}),
  })
  const lines = useMemo(() => query.data?.data ?? [], [query.data])

  const shops = useMemo(() => {
    const grouped = new Map<string, { name: string; lines: ReplenishmentLine[] }>()
    for (const line of lines) {
      const group = grouped.get(line.location_id) ?? { name: line.location_name, lines: [] }
      group.lines.push(line)
      grouped.set(line.location_id, group)
    }
    return [...grouped.entries()]
  }, [lines])

  const matrix = useMemo(() => {
    const columns = new Map<string, string>()
    const rows = new Map<string, MatrixRow>()
    for (const line of lines) {
      columns.set(line.location_id, line.location_name)
      const key = `${line.product_id}:${line.variant_id ?? ''}`
      const row = rows.get(key) ?? {
        key,
        productName: line.variant_name ? `${line.product_name} — ${line.variant_name}` : line.product_name,
        cells: {},
        representative: line,
      }
      row.cells[line.location_id] = line
      rows.set(key, row)
    }
    return { columns: [...columns.entries()], rows: [...rows.values()] }
  }, [lines])

  const selected = lines.filter((line) => selectedIds.has(line.id))

  if (query.isLoading) return <LoadingSpinner fullScreen />
  if (query.error) return <QueryError error={query.error} onRetry={() => { void query.refetch() }} />
  if (lines.length === 0) {
    return <EmptyState title={t('queue.empty_title')} description={t('queue.empty_description')} />
  }

  return (
    <div className="space-y-4">
      <FilterTabs
        value={view}
        onChange={setView}
        tabs={[
          { value: 'shop', label: t('queue.by_shop') },
          { value: 'product', label: t('queue.by_product') },
        ]}
      />

      {view === 'shop' ? (
        <div className="space-y-4">
          {shops.map(([locationId, group]) => {
            const allSelected = group.lines.every((line) => selectedIds.has(line.id))
            return (
              <section key={locationId} data-testid="shop-group" className={tokens.card.base}>
                <div className={`mb-3 flex items-center justify-between gap-3 border-b pb-3 ${borderColors.light}`}>
                  <h2 className={`font-semibold ${textColors.primary}`}>{group.name} ({group.lines.length})</h2>
                  <label className={`flex items-center gap-2 text-sm ${textColors.secondary}`}>
                    <Checkbox
                      checked={allSelected}
                      onChange={() => {
                        setSelectedIds((current) => {
                          const next = new Set(current)
                          for (const line of group.lines) {
                            if (allSelected) next.delete(line.id)
                            else next.add(line.id)
                          }
                          return next
                        })
                      }}
                    />
                    {t('selection.select_all')}
                  </label>
                </div>
                <ul className={`divide-y ${borderColors.divideLight}`}>
                  {group.lines.map((line) => (
                    <li key={line.id} className="flex items-center gap-3 py-3">
                      <Checkbox
                        aria-label={`${line.product_name} ${line.location_name}`}
                        checked={selectedIds.has(line.id)}
                        onChange={() => { setSelectedIds((current) => toggleId(current, line.id)) }}
                      />
                      <button type="button" className="min-w-0 flex-1 text-start" onClick={() => { setContextLine(line) }}>
                        <span className={`block font-medium ${textColors.primary}`}>{line.product_name}</span>
                        {line.variant_name ? <span className={`text-sm ${textColors.tertiary}`}>{line.variant_name}</span> : null}
                      </button>
                      <span className={textColors.secondary}>{line.requested_qty ?? t('matrix.requested_no_qty')}</span>
                      <span className={`text-sm ${textColors.tertiary}`}>×{line.request_count}</span>
                      <time className={`text-xs ${textColors.tertiary}`} dateTime={line.last_requested_at}>
                        {new Date(line.last_requested_at).toLocaleDateString()}
                      </time>
                      {line.note ? <StickyNote aria-label={t('capture.note')} className={`h-4 w-4 ${textColors.tertiary}`} /> : null}
                    </li>
                  ))}
                </ul>
              </section>
            )
          })}
        </div>
      ) : (
        <section className={`${tokens.card.base} overflow-x-auto`}>
          <div
            className="grid min-w-[900px] gap-3"
            style={{ gridTemplateColumns: `minmax(220px, 1fr) repeat(${String(matrix.columns.length)}, minmax(180px, 1fr))` }}
          >
            <div className={`border-b pb-3 ${borderColors.light}`} />
            {matrix.columns.map(([locationId, locationName]) => (
              <div key={locationId} data-testid="matrix-shop" className={`border-b pb-3 font-semibold ${borderColors.light} ${textColors.primary}`}>
                {locationName}
              </div>
            ))}
            {matrix.rows.map((row) => (
              <div key={row.key} className="contents">
                <button
                  type="button"
                  data-testid="matrix-product"
                  className={`border-b py-3 text-start font-medium ${borderColors.light} ${textColors.primary}`}
                  onClick={() => { setContextLine(row.representative) }}
                >
                  {row.productName}
                </button>
                {matrix.columns.map(([locationId, locationName]) => {
                  const cell = row.cells[locationId]
                  return (
                    <div key={`${row.key}-${locationId}`} className={`border-b py-3 ${borderColors.light}`}>
                      {cell ? (
                        <button
                          type="button"
                          data-testid={`matrix-cell-${cell.id}`}
                          aria-label={`${row.productName} ${locationName}`}
                          className={`w-full rounded-md p-2 text-start ${selectedIds.has(cell.id) ? tokens.alert.info : colors.neutral[50]}`}
                          onClick={() => { setSelectedIds((current) => toggleId(current, cell.id)) }}
                        >
                          {cell.requested_qty ?? t('matrix.requested_no_qty')}
                          {cell.request_count > 1 ? <sup className="ms-1">{cell.request_count}</sup> : null}
                        </button>
                      ) : null}
                    </div>
                  )
                })}
              </div>
            ))}
          </div>
        </section>
      )}

      {selected.length > 0 ? (
        <RequirePermission permission="replenishment.process">
          <div className={`sticky bottom-0 flex flex-wrap items-center gap-2 border-t p-3 ${borderColors.light} ${colors.white}`}>
            <span className={`me-auto text-sm ${textColors.secondary}`}>{t('selection.count', { count: selected.length })}</span>
            <Button type="button">{t('actions.create_transfer')}</Button>
            <Button type="button" variant="secondary">{t('actions.add_to_po')}</Button>
            <Button type="button" variant="danger">{t('actions.reject')}</Button>
          </div>
        </RequirePermission>
      ) : null}

      {contextLine ? <RequestContextPanel line={contextLine} onClose={() => { setContextLine(null) }} /> : null}
    </div>
  )
}

function HistoryQueue({ status, filters }: { status: ReplenishmentStatus; filters: QueueFilters }) {
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)
  const query = useReplenishmentHistory({
    status,
    ...(filters.locationIds.length > 0 ? { location_ids: filters.locationIds } : {}),
    ...(filters.from ? { from: filters.from } : {}),
    ...(filters.to ? { to: filters.to } : {}),
    page,
    per_page: perPage,
  })

  if (query.isLoading) return <LoadingSpinner fullScreen />
  if (query.error) return <QueryError error={query.error} onRetry={() => { void query.refetch() }} />
  if (!query.data || query.data.data.length === 0) return <HistoryEmpty />

  return (
    <section className={`${tokens.card.base} p-0`}>
      <ul className={`divide-y ${borderColors.divideLight}`}>
        {query.data.data.map((line) => (
          <li key={line.id} className="flex flex-wrap items-center gap-3 p-4">
            <span className={`min-w-0 flex-1 font-medium ${textColors.primary}`}>{line.product_name}</span>
            <span className={textColors.secondary}>{line.location_name}</span>
            <ReplenishmentStatusBadge status={line.status} />
          </li>
        ))}
      </ul>
      <OffsetPagination
        currentPage={query.data.meta.current_page}
        lastPage={query.data.meta.last_page}
        total={query.data.meta.total}
        perPage={query.data.meta.per_page}
        from={query.data.meta.from ?? null}
        to={query.data.meta.to ?? null}
        onPageChange={setPage}
        onPerPageChange={(value) => {
          setPerPage(value)
          setPage(1)
        }}
      />
    </section>
  )
}

function HistoryEmpty() {
  const { t } = useTranslation('replenishment')
  return <EmptyState title={t('queue.empty_title')} description={t('queue.empty_description')} />
}

export function ReplenishmentQueuePage() {
  const { t } = useTranslation('replenishment')
  const [status, setStatus] = useState<StatusFilter>('open')
  const [locationIds, setLocationIds] = useState<string[]>([])
  const [from, setFrom] = useState<string>()
  const [to, setTo] = useState<string>()
  const filters = { locationIds, from, to }

  return (
    <div className="space-y-6">
      <PageHeader title={t('title')} />
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <LocationSelectorMulti value={locationIds} onChange={setLocationIds} />
        <DateRangeFilter
          label={t('filters.date')}
          fromValue={from}
          toValue={to}
          onFromChange={setFrom}
          onToChange={setTo}
        />
      </div>
      <FilterTabs
        value={status}
        onChange={setStatus}
        tabs={[
          { value: 'open', label: t('filters.open') },
          { value: 'pending', label: t('status.pending') },
          { value: 'in_progress', label: t('status.in_progress') },
          { value: 'fulfilled', label: t('status.fulfilled') },
          { value: 'rejected', label: t('status.rejected') },
          { value: 'cancelled', label: t('status.cancelled') },
        ]}
      />
      {status === 'open' ? <OpenQueue filters={filters} /> : <HistoryQueue status={status} filters={filters} />}
    </div>
  )
}
