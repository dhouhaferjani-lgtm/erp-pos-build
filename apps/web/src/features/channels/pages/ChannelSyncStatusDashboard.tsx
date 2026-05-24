import { useState } from 'react'
import { Activity } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { header, page, panel, select, subtitle, table, tableShell, td, th, title } from './channelPageStyles'

export function ChannelSyncStatusDashboard() {
  const { t } = useTranslation(['channels'])
  const [status, setStatus] = useState('all')

  return (
    <div className={page}>
      <div className={header}>
        <div>
          <h1 className={title}>{t('channels:sync.title')}</h1>
          <p className={subtitle}>{t('channels:sync.subtitle')}</p>
        </div>
        <select value={status} onChange={(event) => { setStatus(event.target.value) }} className={select}>
          <option value="all">{t('channels:sync.filters.all')}</option>
          <option value="pending">{t('channels:sync.filters.pending')}</option>
          <option value="failed">{t('channels:sync.filters.failed')}</option>
        </select>
      </div>
      <div className={tableShell}>
        <table className={table}>
          <thead>
            <tr>
              <th className={th}>{t('channels:sync.operation')}</th>
              <th className={th}>{t('common:fields.status')}</th>
              <th className={th}>{t('channels:sync.attempts')}</th>
              <th className={th}>{t('channels:sync.nextRetry')}</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td colSpan={4} className={td}>
                <div className={panel}>
                  <Activity className="mb-3 h-6 w-6 text-gray-500" />
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
