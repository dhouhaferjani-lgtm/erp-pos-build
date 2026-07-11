import { useState } from 'react'
import Big from 'big.js'
import { FileStack, Plus } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'

import { Button, Select, StatusBadge } from '@/components/atoms'
import { DataTable, EmptyState, ListPageLayout, type DataTableColumn } from '@/components/molecules'
import { OffsetPagination } from '@/components/ui/OffsetPagination'
import { tokens, textColors } from '@/lib/designTokens'
import { formatCurrency } from '@/lib/format'
import { cn } from '@/lib/utils'

import { type Remittance, useRemittances } from './hooks/useRemittances'

export function RemittanceListPage() {
  const { t } = useTranslation(['common', 'treasury'])
  const [status, setStatus] = useState('')
  const [kind, setKind] = useState('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)
  const { data, isLoading, error } = useRemittances({ status, kind, page, perPage })
  const slips = data?.data ?? []
  const meta = data?.meta ?? { current_page: 1, last_page: 1, per_page: 25, total: slips.length }

  const columns: DataTableColumn<Remittance>[] = [
    { key: 'number', header: t('treasury:remittances.number'), render: (slip) => <Link className={cn('font-medium hover:underline', textColors.brand)} to={`/treasury/remittances/${slip.id}`}>{slip.number}</Link> },
    { key: 'bank', header: t('treasury:remittances.bankRepository'), render: (slip) => slip.bank_repository.name },
    { key: 'kind', header: t('treasury:remittances.kind'), render: (slip) => <StatusBadge tone={slip.instrument_kind === 'cheque' ? 'info' : 'warning'}>{t(`treasury:instruments.kinds.${slip.instrument_kind}`)}</StatusBadge> },
    { key: 'status', header: t('treasury:remittances.status'), render: (slip) => <StatusBadge tone={slip.status === 'draft' ? 'warning' : slip.status === 'closed' ? 'success' : 'info'}>{t(`treasury:remittances.statuses.${slip.status}`)}</StatusBadge> },
    { key: 'count', header: t('treasury:remittances.count'), numeric: true, render: (slip) => slip.lines.length },
    { key: 'total', header: t('treasury:remittances.total'), numeric: true, render: (slip) => formatCurrency(slip.lines.reduce((total, line) => total.plus(line.amount), new Big(0)).toFixed(3), { currency: slip.lines[0]?.instrument.currency ?? 'TND' }) },
  ]

  const filters = <div className="grid w-full gap-3 sm:grid-cols-2"><label className={tokens.label.base}>{t('treasury:remittances.status')}<Select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1) }}><option value="">{t('treasury:instruments.filters.all')}</option><option value="draft">{t('treasury:remittances.statuses.draft')}</option><option value="remitted">{t('treasury:remittances.statuses.remitted')}</option><option value="closed">{t('treasury:remittances.statuses.closed')}</option></Select></label><label className={tokens.label.base}>{t('treasury:remittances.kind')}<Select value={kind} onChange={(event) => { setKind(event.target.value); setPage(1) }}><option value="">{t('treasury:instruments.filters.all')}</option><option value="cheque">{t('treasury:instruments.kinds.cheque')}</option><option value="effet">{t('treasury:instruments.kinds.effet')}</option></Select></label></div>

  return <ListPageLayout title={t('treasury:remittances.title')} subtitle={t('treasury:remittances.listSubtitle')} filters={filters} actions={<Link to="/treasury/remittances/new"><Button><Plus className="h-4 w-4" />{t('treasury:remittances.new')}</Button></Link>} pagination={<OffsetPagination currentPage={meta.current_page} lastPage={meta.last_page} total={meta.total} perPage={meta.per_page} from={meta.total ? (meta.current_page - 1) * meta.per_page + 1 : null} to={meta.total ? Math.min(meta.current_page * meta.per_page, meta.total) : null} onPageChange={setPage} onPerPageChange={(value) => { setPerPage(value); setPage(1) }} />}>
    {error ? <div className={cn(tokens.alert.base, tokens.alert.error)}>{t('common:errors.loadingFailed')}</div> : <DataTable columns={columns} data={slips} keyExtractor={(slip) => slip.id} isLoading={isLoading} emptyState={<EmptyState icon={<FileStack className="h-12 w-12" />} title={t('treasury:remittances.empty')} />} />}
  </ListPageLayout>
}
