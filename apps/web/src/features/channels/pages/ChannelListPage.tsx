import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Cable, Plus, RefreshCw } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import { PageHeader } from '@/components/molecules/PageHeader'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { fetchChannels } from '../api'

const thClass = cn('px-6 py-3 text-start text-xs font-medium uppercase', textColors.tertiary)
const tdClass = cn('px-6 py-4 text-sm', textColors.secondary)

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
    <div className="space-y-6">
      <PageHeader
        title={t('channels:title')}
        subtitle={t('channels:subtitle')}
        actions={
          <Link
            to="/channels/new"
            className={cn(tokens.button.base, tokens.button.primary, tokens.button.sizes.md, 'gap-2')}
          >
            <Plus className="h-4 w-4" />
            {t('channels:create.action')}
          </Link>
        }
      />

      {registeredAdapters.length === 0 && (
        <div className={tokens.card.base}>
          <div className="flex items-start gap-3">
            <Cable className={cn('mt-0.5 h-5 w-5', textColors.disabled)} />
            <div>
              <h2 className={cn('text-base font-semibold', textColors.primary)}>{t('channels:noAdapters.title')}</h2>
              <p className={cn('text-sm', textColors.tertiary)}>{t('channels:noAdapters.body')}</p>
            </div>
          </div>
        </div>
      )}

      {isLoading ? (
        <div className={tokens.card.base}>{t('common:status.loading')}</div>
      ) : error ? (
        <div className={tokens.card.base}>{t('common:errors.loadingFailed')}</div>
      ) : channels.length === 0 ? (
        <div className={tokens.card.base}>
          <RefreshCw className={cn('mb-3 h-6 w-6', textColors.disabled)} />
          <h2 className={cn('text-base font-semibold', textColors.primary)}>{t('channels:empty.title')}</h2>
          <p className={cn('text-sm', textColors.tertiary)}>{t('channels:empty.body')}</p>
        </div>
      ) : (
        <div className={cn('overflow-hidden rounded-lg border bg-white', borderColors.light)}>
          <table className={cn('min-w-full divide-y', borderColors.divideDefault)}>
            <thead>
              <tr>
                <th className={thClass}>{t('channels:fields.name')}</th>
                <th className={thClass}>{t('channels:fields.adapter')}</th>
                <th className={thClass}>{t('common:fields.status')}</th>
                <th className={thClass}>{t('channels:fields.lastSync')}</th>
              </tr>
            </thead>
            <tbody>
              {channels.map((channel) => (
                <tr key={channel.id}>
                  <td className={tdClass}>{channel.name}</td>
                  <td className={tdClass}>{channel.adapter_type}</td>
                  <td className={tdClass}>{t(`channels:connectionStatus.${channel.connection_status}`)}</td>
                  <td className={tdClass}>
                    {channel.last_successful_sync_at ?? t('channels:fields.never')}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
