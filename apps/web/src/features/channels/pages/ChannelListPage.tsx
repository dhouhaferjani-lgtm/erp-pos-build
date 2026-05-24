import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Cable, Plus, RefreshCw } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { fetchChannels } from '../api'
import { header, page, panel, primaryButton, subtitle, table, tableShell, td, th, title } from './channelPageStyles'

export function ChannelListPage() {
  const { t } = useTranslation(['channels', 'common'])
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['channels']),
    queryFn: fetchChannels,
    enabled: Boolean(tenantId && companyId),
  })

  const channels = data?.channels ?? []
  const registeredAdapters = data?.registered_adapters ?? []

  return (
    <div className={page}>
      <div className={header}>
        <div>
          <h1 className={title}>{t('channels:title')}</h1>
          <p className={subtitle}>{t('channels:subtitle')}</p>
        </div>
        <Link to="/channels/new" className={primaryButton}>
          <Plus className="h-4 w-4" />
          {t('channels:create.action')}
        </Link>
      </div>

      {registeredAdapters.length === 0 && (
        <div className={panel}>
          <div className="flex items-start gap-3">
            <Cable className="mt-0.5 h-5 w-5 text-gray-500" />
            <div>
              <h2 className="text-base font-semibold text-gray-900">{t('channels:noAdapters.title')}</h2>
              <p className={subtitle}>{t('channels:noAdapters.body')}</p>
            </div>
          </div>
        </div>
      )}

      {isLoading ? (
        <div className={panel}>{t('common:status.loading')}</div>
      ) : error ? (
        <div className={panel}>{t('common:errors.loadingFailed')}</div>
      ) : channels.length === 0 ? (
        <div className={panel}>
          <RefreshCw className="mb-3 h-6 w-6 text-gray-500" />
          <h2 className="text-base font-semibold text-gray-900">{t('channels:empty.title')}</h2>
          <p className={subtitle}>{t('channels:empty.body')}</p>
        </div>
      ) : (
        <div className={tableShell}>
          <table className={table}>
            <thead>
              <tr>
                <th className={th}>{t('channels:fields.name')}</th>
                <th className={th}>{t('channels:fields.adapter')}</th>
                <th className={th}>{t('common:fields.status')}</th>
                <th className={th}>{t('channels:fields.lastSync')}</th>
              </tr>
            </thead>
            <tbody>
              {channels.map((channel) => (
                <tr key={channel.id}>
                  <td className={td}>{channel.name}</td>
                  <td className={td}>{channel.adapter_type}</td>
                  <td className={td}>{t(`channels:connectionStatus.${channel.connection_status}`)}</td>
                  <td className={td}>{channel.last_successful_sync_at ?? t('channels:fields.never')}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
