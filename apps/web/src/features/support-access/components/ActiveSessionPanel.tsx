import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Clock3, Eye } from 'lucide-react'
import { Button } from '@/components/atoms/Button'
import { StatusBadge } from '@/components/atoms/StatusBadge'
import { semanticColorTokens as tokens } from '@/lib/designTokens'
import type { SupportAccessSession } from '../types'
import { ElevationDialog } from './ElevationDialog'

interface Props { session: SupportAccessSession; canRequestElevation: boolean; onRequestElevation: (reason: string) => Promise<unknown> }

export function ActiveSessionPanel({ session, canRequestElevation, onRequestElevation }: Props) {
  const { t } = useTranslation('admin')
  const [open, setOpen] = useState(false)
  return (
    <section className={`rounded-2xl border p-5 ${tokens.intent.warning.bgSubtle} ${tokens.intent.warning.borderSubtle}`}>
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <div className={`flex items-center gap-2 text-sm font-semibold ${tokens.intent.warning.textStrong}`}><Eye className="h-4 w-4" />{t('supportAccess.session.active')}</div>
          <h2 className={`mt-2 text-lg font-semibold ${tokens.text.primary}`}>{session.ticket_ref}</h2>
          <p className={`mt-1 text-sm ${tokens.text.muted}`}>{session.reason}</p>
          <p className={`mt-3 flex items-center gap-2 text-sm ${tokens.text.secondary}`}><Clock3 className="h-4 w-4" />{t('supportAccess.session.expires', { date: new Date(session.expires_at).toLocaleString() })}</p>
        </div>
        <div className="text-end">
          <StatusBadge tone={session.access_level === 'write_elevated' ? 'warning' : 'info'}>{t(`supportAccess.access.${session.access_level}`)}</StatusBadge>
          {canRequestElevation && <div><Button type="button" variant="secondary" size="sm" onClick={() => { setOpen(true) }} className="mt-3">{t('supportAccess.actions.requestWrite')}</Button></div>}
        </div>
      </div>
      {canRequestElevation && <ElevationDialog open={open} onClose={() => { setOpen(false) }} onSubmit={onRequestElevation} />}
    </section>
  )
}
