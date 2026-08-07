import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Headphones } from 'lucide-react'
import { QueryError } from '@/components/QueryError'
import { PageHeader } from '@/components/molecules/PageHeader'
import { OffsetPagination } from '@/components/ui/OffsetPagination'
import { usePermissions } from '@/hooks/usePermissions'
import { semanticColorTokens as tokens } from '@/lib/designTokens'
import { IncomingRequests } from '../components/IncomingRequests'
import { SupportAccessHistory } from '../components/SupportAccessHistory'
import { SupportWindowForm } from '../components/SupportWindowForm'
import { useTenantSupportAccess } from '../hooks/useTenantSupportAccess'

export function TenantSupportAccessPage() {
  const { t } = useTranslation('support-access')
  const { hasPermission } = usePermissions()
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)
  const access = useTenantSupportAccess(page, perPage)
  const canManage = hasPermission('support-access.manage')

  if (access.isLoading) return <div className={tokens.text.muted}>{t('loading')}</div>
  if (access.error) return <QueryError error={access.error} title={t('loadError')} />

  return (
    <div className="mx-auto w-full max-w-6xl space-y-6">
      <PageHeader
        title={t('title')}
        subtitle={t('subtitle')}
        breadcrumb={<div className={`inline-flex h-11 w-11 items-center justify-center rounded-xl ${tokens.intent.primary.bgSoft}`}><Headphones className={`h-5 w-5 ${tokens.intent.primary.textStrong}`} /></div>}
        className="mb-0"
      />
      {canManage && <SupportWindowForm
        busy={access.isMutating}
        maxWindowHours={access.overview?.max_grant_window_hours ?? 168}
        onSubmit={access.createWindow}
      />}
      <IncomingRequests
        grants={access.overview?.grants ?? []}
        canManage={canManage}
        onApprove={access.approveGrant}
        onReject={(id) => access.rejectGrant(id, t('rejection.defaultReason'))}
        onRevoke={(id) => access.revokeGrant(id, t('revocation.defaultReason'))}
      />
      {access.overview?.active_sessions.length ? <div className={`rounded-2xl border p-5 ${tokens.intent.warning.bgSubtle} ${tokens.intent.warning.borderSubtle}`}><h2 className={`font-semibold ${tokens.intent.warning.textStrong}`}>{t('active.title')}</h2><p className={`mt-1 text-sm ${tokens.text.secondary}`}>{t('active.description', { count: access.overview.active_sessions.length })}</p></div> : null}
      <SupportAccessHistory entries={access.overview?.log ?? []} />
      {access.overview?.log_meta && <OffsetPagination
        currentPage={access.overview.log_meta.current_page}
        lastPage={access.overview.log_meta.last_page}
        total={access.overview.log_meta.total}
        perPage={access.overview.log_meta.per_page}
        from={access.overview.log_meta.from}
        to={access.overview.log_meta.to}
        onPageChange={setPage}
        onPerPageChange={(value) => { setPerPage(value); setPage(1) }}
      />}
    </div>
  )
}
