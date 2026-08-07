import { useTranslation } from 'react-i18next'
import { ShieldCheck } from 'lucide-react'
import { Button } from '@/components/atoms/Button'
import { semanticColorTokens as tokens } from '@/lib/designTokens'
import type { SupportAccessElevation } from '../types'

interface Props { elevations: SupportAccessElevation[]; onApprove: (id: string) => Promise<unknown> }

export function ElevationQueue({ elevations, onApprove }: Props) {
  const { t } = useTranslation('admin')
  if (elevations.length === 0) return null

  return (
    <section className={`rounded-2xl border ${tokens.intent.caution.bgSubtle} ${tokens.intent.caution.borderSubtle}`}>
      <div className="flex items-center gap-3 px-5 pt-5"><ShieldCheck className={`h-5 w-5 ${tokens.intent.caution.textStrong}`} /><div><h2 className={`font-semibold ${tokens.text.primary}`}>{t('supportAccess.approvals.title')}</h2><p className={`text-sm ${tokens.text.muted}`}>{t('supportAccess.approvals.description')}</p></div></div>
      <div className={`divide-y ${tokens.border.divider}`}>
        {elevations.map((elevation) => <article key={elevation.id} className="flex flex-wrap items-center justify-between gap-4 p-5">
          <div><p className={`font-medium ${tokens.text.primary}`}>{elevation.reason}</p><p className={`mt-1 text-sm ${tokens.text.muted}`}>{t('supportAccess.approvals.session', { session: elevation.session_id })}</p></div>
          <Button type="button" size="sm" onClick={() => { void onApprove(elevation.id).catch(() => undefined) }}>{t('supportAccess.actions.approveWrite')}</Button>
        </article>)}
      </div>
    </section>
  )
}
