import { useQuery } from '@tanstack/react-query'
import { Cable } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import { Button, Input, Select } from '@/components/atoms'
import { PageHeader } from '@/components/molecules/PageHeader'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { fetchChannels } from '../api'

export function ChannelCreateWizard() {
  const { t } = useTranslation(['channels'])
  const { data } = useQuery({
    queryKey: tenantScopedKey(['channels', 'create-adapters']),
    queryFn: fetchChannels,
  })
  const adapters = data?.registered_adapters ?? []

  return (
    <div className="space-y-6">
      <PageHeader title={t('channels:create.title')} subtitle={t('channels:create.subtitle')} />

      <div className={tokens.card.base}>
        <div className="grid gap-4 md:grid-cols-2">
          <label className="block">
            <span className={tokens.label.base}>{t('channels:fields.name')}</span>
            <Input placeholder={t('channels:create.namePlaceholder')} />
          </label>
          <label className="block">
            <span className={tokens.label.base}>{t('channels:fields.adapter')}</span>
            <Select disabled={adapters.length === 0}>
              {adapters.length === 0 ? (
                <option>{t('channels:create.noAdapterOption')}</option>
              ) : (
                adapters.map((adapter) => <option key={adapter}>{adapter}</option>)
              )}
            </Select>
          </label>
        </div>

        {adapters.length === 0 && (
          <div className={cn('mt-6 flex items-start gap-3 rounded-lg border border-dashed p-4', borderColors.default)}>
            <Cable className={cn('mt-0.5 h-5 w-5', textColors.disabled)} />
            <p className={cn('text-sm', textColors.tertiary)}>{t('channels:create.adaptersComing')}</p>
          </div>
        )}

        <div className="mt-6">
          <Button type="button" disabled={adapters.length === 0}>
            {t('channels:create.testConnection')}
          </Button>
        </div>
      </div>
    </div>
  )
}
