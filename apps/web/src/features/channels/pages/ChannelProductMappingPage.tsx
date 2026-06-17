import { RefreshCw } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import { Button } from '@/components/atoms'
import { PageHeader } from '@/components/molecules/PageHeader'

const thClass = cn('px-6 py-3 text-start text-xs font-medium uppercase', textColors.tertiary)
const tdClass = cn('px-6 py-4 text-sm', textColors.secondary)

export function ChannelProductMappingPage() {
  const { t } = useTranslation(['channels'])

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('channels:mappings.title')}
        subtitle={t('channels:mappings.subtitle')}
        actions={
          <Button type="button" className="gap-2">
            <RefreshCw className="h-4 w-4" />
            {t('channels:mappings.syncSelected')}
          </Button>
        }
      />
      <div className={cn('overflow-hidden rounded-lg border bg-white', borderColors.light)}>
        <table className={cn('min-w-full divide-y', borderColors.divideDefault)}>
          <thead>
            <tr>
              <th className={thClass}>{t('channels:mappings.product')}</th>
              <th className={thClass}>{t('channels:mappings.externalId')}</th>
              <th className={thClass}>{t('channels:mappings.priceOverride')}</th>
              <th className={thClass}>{t('channels:mappings.quantityCap')}</th>
              <th className={thClass}>{t('channels:fields.lastSync')}</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td colSpan={5} className={tdClass}>
                <div className={tokens.card.base}>{t('channels:mappings.empty')}</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  )
}
