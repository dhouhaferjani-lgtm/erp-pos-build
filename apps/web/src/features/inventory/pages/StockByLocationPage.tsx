import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { EmptyState } from '@/components/molecules/EmptyState/EmptyState'
import { PageHeader } from '@/components/molecules/PageHeader'
import { Input } from '@/components/atoms/Input'
import { OffsetPagination } from '@/components/ui/OffsetPagination'
import { locationScopedKey } from '@/lib/locationScopedKey'
import { useViewScope } from '@/features/locations/hooks/useViewScope'
import { useScopedLocations } from '@/features/locations/hooks/useScopedLocations'
import { ProductLocationMatrix } from '@/components/organisms/ProductLocationMatrix'
import { getStockMatrix } from '../api/stockMatrix'
import { RebalancingView } from '../components/RebalancingView'

export function StockByLocationPage() {
  const { t } = useTranslation('inventory')
  const { scope, effectiveLocationIds } = useViewScope()
  const { data: locationData } = useScopedLocations()
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)
  const query = useQuery({ queryKey: locationScopedKey(['inventory-stock-matrix', search, page, perPage], scope), queryFn: () => getStockMatrix({ locationIds: effectiveLocationIds, search, page, perPage, includeIncoming: false }), enabled: effectiveLocationIds.length > 0 })
  const rows = query.data?.data ?? []
  const effectiveLocationSet = new Set(effectiveLocationIds)
  const locations = (locationData ?? []).filter((location) => effectiveLocationSet.has(location.id)).map((location) => ({ id: location.id, name: location.name }))
  return (
    <div className="space-y-6">
      <PageHeader title={t('stockByLocation.title')} subtitle={t('stockByLocation.subtitle')} />
      <Input aria-label={t('stockByLocation.search')} value={search} onChange={(event) => { setSearch(event.target.value); setPage(1) }} placeholder={t('stockByLocation.search')} />
      {query.isLoading ? <div role="status">{t('common:loading')}</div> : rows.length === 0 ? <EmptyState title={t('stockByLocation.empty')} /> : <ProductLocationMatrix rows={rows} locations={locations} />}
      {query.data ? <OffsetPagination currentPage={query.data.meta.current_page} lastPage={query.data.meta.last_page} total={query.data.meta.total} perPage={perPage} from={rows.length ? ((page - 1) * perPage) + 1 : null} to={rows.length ? ((page - 1) * perPage) + rows.length : null} onPageChange={setPage} onPerPageChange={(value) => { setPerPage(value); setPage(1) }} /> : null}
      <RebalancingView />
    </div>
  )
}
