import { FileText } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import { PageHeader } from '@/components/molecules/PageHeader'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

const thClass = cn('px-6 py-3 text-start text-xs font-medium uppercase', textColors.tertiary)
const tdClass = cn('px-6 py-4 text-sm', textColors.secondary)

export function ChannelOrdersPage() {
  const { t } = useTranslation(['channels'])

  return (
    <div className="space-y-6">
      <PageHeader title={t('channels:orders.title')} subtitle={t('channels:orders.subtitle')} />
      <div className={cn('overflow-hidden rounded-lg border bg-white', borderColors.light)}>
        <DataTable className={cn('min-w-full divide-y', borderColors.divideDefault)}>
          <thead>
            <tr>
              <th className={thClass}>{t('channels:orders.externalId')}</th>
              <th className={thClass}>{t('common:fields.status')}</th>
              <th className={thClass}>{t('channels:orders.receivedAt')}</th>
              <th className={thClass}>{t('channels:orders.document')}</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td colSpan={4} className={tdClass}>
                <div className={tokens.card.base}>
                  <FileText className={cn('mb-3 h-6 w-6', textColors.disabled)} />
                  {t('channels:orders.empty')}
                </div>
              </td>
            </tr>
          </tbody>
        </DataTable>
      </div>
    </div>
  )
}
