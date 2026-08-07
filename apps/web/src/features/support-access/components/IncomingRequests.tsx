import { useTranslation } from 'react-i18next'
import { Button } from '@/components/atoms/Button'
import { StatusBadge, statusTone } from '@/components/atoms/StatusBadge'
import { semanticColorTokens as tokens } from '@/lib/designTokens'
import type { SupportAccessGrant } from '../types'

interface Props {
  grants: SupportAccessGrant[]
  canManage: boolean
  onApprove: (id: string) => Promise<unknown>
  onReject: (id: string) => Promise<unknown>
  onRevoke: (id: string) => Promise<unknown>
}

export function IncomingRequests({ grants, canManage, onApprove, onReject, onRevoke }: Props) {
  const { t } = useTranslation('support-access')
  return (
    <section className={`rounded-2xl border ${tokens.surface.base} ${tokens.border.subtle}`}>
      <div className={`border-b px-5 py-4 ${tokens.border.subtle}`}>
        <h2 className={`text-lg font-semibold ${tokens.text.primary}`}>{t('requests.title')}</h2>
        <p className={`mt-1 text-sm ${tokens.text.muted}`}>{t('requests.description')}</p>
      </div>
      <div className={`divide-y ${tokens.border.divider}`}>
        {grants.map((grant) => (
          <article key={grant.id} className="flex flex-wrap items-center justify-between gap-4 p-5">
            <div>
              <div className="flex items-center gap-2"><span className={`font-mono text-xs ${tokens.text.subtle}`}>{grant.ticket_ref}</span><StatusBadge tone={statusTone(grant.status, { pending_tenant_approval: 'warning', pending_internal_approval: 'warning', revoked: 'danger', expired: 'neutral' })}>{t(`status.${grant.status}`)}</StatusBadge></div>
              <p className={`mt-2 font-medium ${tokens.text.primary}`}>{grant.reason}</p>
              <p className={`mt-1 text-sm ${tokens.text.muted}`}>{t('requests.expires', { date: new Date(grant.expires_at).toLocaleString() })}</p>
            </div>
            {canManage && <div className="flex gap-2">
              {grant.status === 'pending_tenant_approval' && <><Button type="button" size="sm" onClick={() => { void onApprove(grant.id).catch(() => undefined) }}>{t('actions.approve')}</Button><Button type="button" variant="dangerOutline" size="sm" onClick={() => { void onReject(grant.id).catch(() => undefined) }}>{t('actions.reject')}</Button></>}
              {!['revoked', 'rejected', 'expired'].includes(grant.status) && <Button type="button" variant="secondary" size="sm" onClick={() => { void onRevoke(grant.id).catch(() => undefined) }}>{t('actions.revoke')}</Button>}
            </div>}
          </article>
        ))}
      </div>
    </section>
  )
}
