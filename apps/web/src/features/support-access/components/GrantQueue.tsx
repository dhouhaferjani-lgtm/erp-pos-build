import { useTranslation } from 'react-i18next'
import { Button } from '@/components/atoms/Button'
import { StatusBadge, statusTone } from '@/components/atoms/StatusBadge'
import { semanticColorTokens as tokens } from '@/lib/designTokens'
import type { SupportAccessGrant } from '../types'

interface Props {
  grants: SupportAccessGrant[]
  busy: boolean
  canApprove: boolean
  canOperate: boolean
  onApprove: (id: string) => Promise<unknown>
  onRevoke: (id: string) => Promise<unknown>
  onStart: (grant: SupportAccessGrant) => Promise<unknown>
}

export function GrantQueue({ grants, busy, canApprove, canOperate, onApprove, onRevoke, onStart }: Props) {
  const { t } = useTranslation('admin')

  return (
    <section aria-labelledby="grant-queue-title" className={`rounded-2xl border ${tokens.surface.base} ${tokens.border.subtle}`}>
      <div className={`border-b px-5 py-4 ${tokens.border.subtle}`}>
        <h2 id="grant-queue-title" className={`font-semibold ${tokens.text.primary}`}>{t('supportAccess.queue.title')}</h2>
        <p className={`mt-1 text-sm ${tokens.text.muted}`}>{t('supportAccess.queue.description')}</p>
      </div>
      <div className={`divide-y ${tokens.border.divider}`}>
        {grants.map((grant) => (
          <article key={grant.id} className="grid gap-4 p-5 lg:grid-cols-[1fr_auto] lg:items-center">
            <div>
              <div className="flex flex-wrap items-center gap-2">
                <StatusBadge tone={statusTone(grant.status, { pending_tenant_approval: 'warning', pending_internal_approval: 'warning', revoked: 'danger', expired: 'neutral' })}>{t(`supportAccess.status.${grant.status}`)}</StatusBadge>
                <span className={`font-mono text-xs ${tokens.text.subtle}`}>{grant.ticket_ref}</span>
              </div>
              <p className={`mt-3 font-medium ${tokens.text.primary}`}>{grant.reason}</p>
              <p className={`mt-1 text-sm ${tokens.text.muted}`}>{t('supportAccess.queue.scope', { tenant: grant.tenant_id, subject: grant.subject_user_id })}</p>
            </div>
            <div className="flex flex-wrap gap-2">
              {canApprove && grant.status === 'pending_internal_approval' && <Button type="button" size="sm" disabled={busy} onClick={() => { void onApprove(grant.id).catch(() => undefined) }}>{t('supportAccess.actions.secondApprove')}</Button>}
              {canOperate && grant.status === 'active' && grant.subject_user_id && <Button type="button" size="sm" disabled={busy} onClick={() => { void onStart(grant).catch(() => undefined) }}>{t('supportAccess.actions.startSession')}</Button>}
              {canOperate && !['rejected', 'revoked', 'expired'].includes(grant.status) && <Button type="button" variant="dangerOutline" size="sm" disabled={busy} onClick={() => { void onRevoke(grant.id).catch(() => undefined) }}>{t('supportAccess.actions.revoke')}</Button>}
            </div>
          </article>
        ))}
      </div>
    </section>
  )
}
