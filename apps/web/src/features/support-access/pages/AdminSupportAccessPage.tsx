import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Headphones, Plus } from 'lucide-react'
import { QueryError } from '@/components/QueryError'
import { Button } from '@/components/atoms/Button'
import { PageHeader } from '@/components/molecules/PageHeader'
import { OffsetPagination } from '@/components/ui/OffsetPagination'
import { semanticColorTokens as tokens } from '@/lib/designTokens'
import { useAuthStore } from '@/stores/authStore'
import { useAdminSupportAccess } from '@/features/admin/support-access/useAdminSupportAccess'
import { useAdminAuthStore } from '@/features/admin/stores/adminAuthStore'
import { ActiveSessionPanel } from '../components/ActiveSessionPanel'
import { GrantQueue } from '../components/GrantQueue'
import { ElevationQueue } from '../components/ElevationQueue'
import { RequestAccessDialog } from '../components/RequestAccessDialog'
import type { SupportAccessGrant } from '../types'

export function AdminSupportAccessPage() {
  const { t } = useTranslation('admin')
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [dialogOpen, setDialogOpen] = useState(false)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)
  const access = useAdminSupportAccess(page, perPage)
  const adminRole = useAdminAuthStore((state) => state.admin?.role)
  const canOperate = adminRole === 'super_admin'
  const canApprove = adminRole === 'support_approver'

  if (access.isLoading) return <div className={`p-8 ${tokens.text.muted}`}>{t('supportAccess.loading')}</div>
  if (access.error) return <div className="p-8"><QueryError error={access.error} title={t('supportAccess.loadError')} /></div>

  const start = async (grant: SupportAccessGrant) => {
    if (!grant.subject_user_id) return
    let started
    try {
      started = await access.startSession(grant.id, grant.subject_user_id)
    } catch {
      return
    }
    queryClient.clear()
    useAuthStore.getState().setAuth({
      id: grant.subject_user_id,
      name: grant.subject_user_id,
      email: '',
      tenant_id: grant.tenant_id,
      roles: [],
      permissions: started.permissions,
      email_verified_at: null,
      impersonation: {
        session_id: started.session_id,
        subject_user_id: grant.subject_user_id,
        subject_name: started.subject_name,
        reason: grant.reason,
        ticket_ref: grant.ticket_ref,
        access_level: 'read_only',
        expires_at: started.expires_at,
        remaining_seconds: Math.max(0, Math.floor((Date.parse(started.expires_at) - Date.now()) / 1000)),
      },
    }, started.plain_text_token)
    void navigate('/dashboard')
  }

  return (
    <div className="p-6 lg:p-8">
      <div className="mx-auto max-w-7xl space-y-6">
        <PageHeader
          title={t('supportAccess.title')}
          subtitle={t('supportAccess.subtitle')}
          breadcrumb={<div className={`inline-flex h-11 w-11 items-center justify-center rounded-xl ${tokens.intent.primary.bgSoft}`}><Headphones className={`h-5 w-5 ${tokens.intent.primary.textStrong}`} /></div>}
          actions={canOperate ? <Button type="button" onClick={() => { setDialogOpen(true) }} className="gap-2"><Plus className="h-4 w-4" />{t('supportAccess.actions.requestAccess')}</Button> : undefined}
          className="mb-0"
        />
        {access.overview?.active_sessions.map((session) => <ActiveSessionPanel key={session.id} session={session} canRequestElevation={canOperate} onRequestElevation={(reason) => access.requestElevation(session.id, reason)} />)}
        {canApprove && <ElevationQueue elevations={access.overview?.pending_elevations ?? []} onApprove={access.approveElevation} />}
        <GrantQueue grants={access.overview?.grants ?? []} busy={access.isMutating} canApprove={canApprove} canOperate={canOperate} onApprove={access.approveGrant} onRevoke={(id) => access.revokeGrant(id, t('supportAccess.revokeReason'))} onStart={start} />
        {access.meta && <OffsetPagination
          currentPage={access.meta.current_page}
          lastPage={access.meta.last_page}
          total={access.meta.total}
          perPage={access.meta.per_page}
          from={access.meta.from}
          to={access.meta.to}
          onPageChange={setPage}
          onPerPageChange={(value) => { setPerPage(value); setPage(1) }}
        />}
      </div>
      {canOperate && <RequestAccessDialog open={dialogOpen} busy={access.isMutating} onClose={() => { setDialogOpen(false) }} onSubmit={access.requestAccess} />}
    </div>
  )
}
