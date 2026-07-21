import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import Big from 'big.js'
import { FileSpreadsheet, Upload } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router-dom'

import { Button, Select, StatusBadge, type StatusTone } from '@/components/atoms'
import { DataTable, EmptyState, ListPageLayout, type DataTableColumn } from '@/components/molecules'
import { Modal, ModalContent } from '@/components/organisms/Modal/Modal'
import { OffsetPagination } from '@/components/ui/OffsetPagination'
import { usePermissions } from '@/hooks/usePermissions'
import { textColors, tokens } from '@/lib/designTokens'
import { formatCurrency, formatDate } from '@/lib/format'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { cn } from '@/lib/utils'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { usePaymentRepositories } from '../hooks/usePaymentRepositories'
import { listBankStatements, listStatementProfiles, type BankStatementSummary, type StatementStatus } from './api'
import { StatementUploadWizard } from './StatementUploadWizard'
import { formatAtCurrencyScale } from './status'

const statusTones: Record<StatementStatus, StatusTone> = {
  imported: 'pending',
  reconciling: 'info',
  reconciled: 'success',
  voided: 'neutral',
}

export function StatementListPage() {
  const { t } = useTranslation(['treasury', 'common'])
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const { hasPermission } = usePermissions()
  const [status, setStatus] = useState('')
  const [repositoryId, setRepositoryId] = useState('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)
  const [showUpload, setShowUpload] = useState(false)
  const filters = { status, repositoryId, page, perPage }

  const statementsQuery = useQuery({
    queryKey: tenantScopedKey(['bank-statements', filters]),
    queryFn: () => listBankStatements(filters),
    enabled: tenantId !== null && companyId !== null,
  })
  const profilesQuery = useQuery({
    queryKey: tenantScopedKey(['statement-import-profiles']),
    queryFn: listStatementProfiles,
    enabled: showUpload && tenantId !== null && companyId !== null,
  })
  const repositoriesQuery = usePaymentRepositories()
  const bankRepositories = (repositoriesQuery.data ?? []).filter((repository) => repository.type === 'bank_account' && repository.is_active)
  const statements = statementsQuery.data?.data ?? []
  const meta = statementsQuery.data?.meta ?? { current_page: 1, last_page: 1, per_page: perPage, total: statements.length }

  const columns: DataTableColumn<BankStatementSummary>[] = [
    {
      key: 'period',
      header: t('treasury:statements.columns.period'),
      render: (statement) => <Link className={cn('font-medium hover:underline', textColors.brand)} to={`/treasury/statements/${statement.id}`}>{formatDate(statement.period_start)} → {formatDate(statement.period_end)}</Link>,
    },
    {
      key: 'repository',
      header: t('treasury:statements.columns.repository'),
      render: (statement) => bankRepositories.find((item) => item.id === statement.payment_repository_id)?.name ?? statement.payment_repository_id,
    },
    {
      key: 'lines',
      header: t('treasury:statements.columns.lines'),
      numeric: true,
      render: (statement) => statement.lines_count,
    },
    {
      key: 'delta',
      header: t('treasury:statements.columns.delta'),
      numeric: true,
      render: (statement) => <span className="tabular-nums">{formatCurrency(formatAtCurrencyScale(new Big(statement.closing_balance).minus(statement.opening_balance).toString(), statement.currency), { currency: statement.currency })}</span>,
    },
    {
      key: 'status',
      header: t('treasury:statements.columns.status'),
      render: (statement) => <StatusBadge tone={statusTones[statement.status]}>{t(`treasury:statements.status.${statement.status}`)}</StatusBadge>,
    },
  ]

  const filtersView = (
    <div className="grid w-full gap-3 sm:grid-cols-2">
      <label className={tokens.label.base}>{t('treasury:statements.filters.status')}<Select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1) }}><option value="">{t('treasury:statements.filters.allStatuses')}</option>{(['imported', 'reconciling', 'reconciled', 'voided'] as const).map((value) => <option key={value} value={value}>{t(`treasury:statements.status.${value}`)}</option>)}</Select></label>
      <label className={tokens.label.base}>{t('treasury:statements.filters.repository')}<Select value={repositoryId} onChange={(event) => { setRepositoryId(event.target.value); setPage(1) }}><option value="">{t('treasury:statements.filters.allRepositories')}</option>{bankRepositories.map((repository) => <option key={repository.id} value={repository.id}>{repository.name}</option>)}</Select></label>
    </div>
  )

  return (
    <>
      <ListPageLayout
        title={t('treasury:statements.title')}
        subtitle={t('treasury:statements.subtitle')}
        filters={filtersView}
        actions={hasPermission('bank-statements.import') ? <Button onClick={() => setShowUpload(true)}><Upload className="me-2 h-4 w-4" />{t('treasury:statements.upload.action')}</Button> : undefined}
        pagination={<OffsetPagination currentPage={meta.current_page} lastPage={meta.last_page} total={meta.total} perPage={meta.per_page} from={meta.total ? (meta.current_page - 1) * meta.per_page + 1 : null} to={meta.total ? Math.min(meta.current_page * meta.per_page, meta.total) : null} onPageChange={setPage} onPerPageChange={(value) => { setPerPage(value); setPage(1) }} />}
      >
        {statementsQuery.error ? <div className={cn(tokens.alert.base, tokens.alert.error)}>{t('common:errors.loadingFailed')}</div> : <DataTable columns={columns} data={statements} keyExtractor={(statement) => statement.id} isLoading={statementsQuery.isLoading} emptyState={<EmptyState icon={<FileSpreadsheet className="h-12 w-12" />} title={t('treasury:statements.empty.title')} description={t('treasury:statements.empty.description')} />} />}
      </ListPageLayout>

      <Modal isOpen={showUpload} onClose={() => setShowUpload(false)} size="xl" title={t('treasury:statements.upload.title')} className="max-h-[92vh] overflow-y-auto">
        <ModalContent>
            <p className={cn('text-sm', textColors.tertiary)}>{t('treasury:statements.upload.subtitle')}</p>
            <StatementUploadWizard
              repositories={bankRepositories.map(({ id, name, currency }) => ({ id, name, currency }))}
              profiles={profilesQuery.data ?? []}
              onProfileCreated={() => queryClient.invalidateQueries({ queryKey: ['statement-import-profiles'] })}
              onImported={(statementId) => { setShowUpload(false); navigate(`/treasury/statements/${statementId}`) }}
            />
        </ModalContent>
      </Modal>
    </>
  )
}
