import { useState } from 'react'
import { Activity } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import { Select } from '@/components/atoms'
import { PageHeader } from '@/components/molecules/PageHeader'

const thClass = cn('px-6 py-3 text-start text-xs font-medium uppercase', textColors.tertiary)
const tdClass = cn('px-6 py-4 text-sm', textColors.secondary)

export function ChannelSyncStatusDashboard() {
  const { t } = useTranslation(['channels'])
  const [status, setStatus] = useState('all')

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('channels:sync.title')}
        subtitle={t('channels:sync.subtitle')}
        actions={
          <Select
            value={status}
            onChange={(event) => {
              setStatus(event.target.value)
            }}
          >
            <option value="all">{t('channels:sync.filters.all')}</option>
            <option value="pending">{t('channels:sync.filters.pending')}</option>
            <option value="failed">{t('channels:sync.filters.failed')}</option>
          </Select>
        }
      />
      <div className={cn('overflow-hidden rounded-lg border bg-white', borderColors.light)}>
        <table className={cn('min-w-full divide-y', borderColors.divideDefault)}>
          <thead>
            <tr>
              <th className={thClass}>{t('channels:sync.operation')}</th>
              <th className={thClass}>{t('common:fields.status')}</th>
              <th className={thClass}>{t('channels:sync.attempts')}</th>
              <th className={thClass}>{t('channels:sync.nextRetry')}</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td colSpan={4} className={tdClass}>
                <div className={tokens.card.base}>
                  <Activity className={cn('mb-3 h-6 w-6', textColors.disabled)} />
                  {t('channels:sync.empty')}
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  )
}
