import { useTranslation } from 'react-i18next'
import { History } from 'lucide-react'
import { semanticColorTokens as tokens } from '@/lib/designTokens'
import type { SupportAccessLogEntry } from '../types'

export function SupportAccessHistory({ entries }: { entries: SupportAccessLogEntry[] }) {
  const { t } = useTranslation('support-access')
  const duration = (seconds: number) => t('history.duration', {
    count: Math.max(1, Math.ceil(seconds / 60)),
  })
  return (
    <section className={`rounded-2xl border ${tokens.surface.base} ${tokens.border.subtle}`}>
      <div className={`flex items-center gap-3 border-b px-5 py-4 ${tokens.border.subtle}`}><History className={`h-5 w-5 ${tokens.intent.primary.text}`} /><div><h2 className={`text-lg font-semibold ${tokens.text.primary}`}>{t('history.title')}</h2><p className={`text-sm ${tokens.text.muted}`}>{t('history.description')}</p></div></div>
      {entries.length === 0 ? <p className={`p-5 text-sm ${tokens.text.muted}`}>{t('history.empty')}</p> : <ol className={`divide-y ${tokens.border.divider}`}>
        {entries.map((entry) => <li key={entry.id} className="grid gap-1 px-5 py-4 sm:grid-cols-[1fr_auto]">
          <div>
            <p className={`font-medium ${tokens.text.primary}`}>{entry.action ?? t(`events.${entry.event_type}`)}</p>
            <p className={`mt-1 text-sm ${tokens.text.muted}`}>{t('history.operator', { name: entry.operator_name })} · {t(`access.${entry.access_level}`)} · {duration(entry.duration_seconds)}</p>
            <p className={`mt-1 text-sm ${tokens.text.muted}`}>{entry.reason} · {entry.ticket_ref} · {entry.http_method}</p>
          </div>
          <time className={`text-sm ${tokens.text.subtle}`}>{new Date(entry.occurred_at).toLocaleString()}</time>
        </li>)}
      </ol>}
    </section>
  )
}
