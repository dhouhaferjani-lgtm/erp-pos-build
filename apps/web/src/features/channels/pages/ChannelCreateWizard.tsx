import { useQuery } from '@tanstack/react-query'
import { Cable } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { fetchChannels } from '../api'
import { header, input, page, panel, primaryButton, select, subtitle, title } from './channelPageStyles'

export function ChannelCreateWizard() {
  const { t } = useTranslation(['channels'])
  const { data } = useQuery({
    queryKey: tenantScopedKey(['channels', 'create-adapters']),
    queryFn: fetchChannels,
  })
  const adapters = data?.registered_adapters ?? []

  return (
    <div className={page}>
      <div className={header}>
        <div>
          <h1 className={title}>{t('channels:create.title')}</h1>
          <p className={subtitle}>{t('channels:create.subtitle')}</p>
        </div>
      </div>

      <div className={panel}>
        <div className="grid gap-4 md:grid-cols-2">
          <label className="block">
            <span className="text-sm font-medium text-gray-700">{t('channels:fields.name')}</span>
            <input className={input} placeholder={t('channels:create.namePlaceholder')} />
          </label>
          <label className="block">
            <span className="text-sm font-medium text-gray-700">{t('channels:fields.adapter')}</span>
            <select className={select} disabled={adapters.length === 0}>
              {adapters.length === 0 ? (
                <option>{t('channels:create.noAdapterOption')}</option>
              ) : (
                adapters.map((adapter) => <option key={adapter}>{adapter}</option>)
              )}
            </select>
          </label>
        </div>

        {adapters.length === 0 && (
          <div className="mt-6 flex items-start gap-3 rounded-lg border border-dashed border-gray-300 p-4">
            <Cable className="mt-0.5 h-5 w-5 text-gray-500" />
            <p className={subtitle}>{t('channels:create.adaptersComing')}</p>
          </div>
        )}

        <div className="mt-6">
          <button type="button" className={primaryButton} disabled={adapters.length === 0}>
            {t('channels:create.testConnection')}
          </button>
        </div>
      </div>
    </div>
  )
}
