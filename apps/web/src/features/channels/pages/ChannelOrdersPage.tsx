import { FileText } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { header, page, panel, subtitle, table, tableShell, td, th, title } from './channelPageStyles'

export function ChannelOrdersPage() {
  const { t } = useTranslation(['channels'])

  return (
    <div className={page}>
      <div className={header}>
        <div>
          <h1 className={title}>{t('channels:orders.title')}</h1>
          <p className={subtitle}>{t('channels:orders.subtitle')}</p>
        </div>
      </div>
      <div className={tableShell}>
        <table className={table}>
          <thead>
            <tr>
              <th className={th}>{t('channels:orders.externalId')}</th>
              <th className={th}>{t('common:fields.status')}</th>
              <th className={th}>{t('channels:orders.receivedAt')}</th>
              <th className={th}>{t('channels:orders.document')}</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td colSpan={4} className={td}>
                <div className={panel}>
                  <FileText className="mb-3 h-6 w-6 text-gray-500" />
                  {t('channels:orders.empty')}
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  )
}
