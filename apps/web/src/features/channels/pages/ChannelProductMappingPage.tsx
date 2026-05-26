import { RefreshCw } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { header, page, panel, primaryButton, subtitle, table, tableShell, td, th, title } from './channelPageStyles'

export function ChannelProductMappingPage() {
  const { t } = useTranslation(['channels'])

  return (
    <div className={page}>
      <div className={header}>
        <div>
          <h1 className={title}>{t('channels:mappings.title')}</h1>
          <p className={subtitle}>{t('channels:mappings.subtitle')}</p>
        </div>
        <button type="button" className={primaryButton}>
          <RefreshCw className="h-4 w-4" />
          {t('channels:mappings.syncSelected')}
        </button>
      </div>
      <div className={tableShell}>
        <table className={table}>
          <thead>
            <tr>
              <th className={th}>{t('channels:mappings.product')}</th>
              <th className={th}>{t('channels:mappings.externalId')}</th>
              <th className={th}>{t('channels:mappings.priceOverride')}</th>
              <th className={th}>{t('channels:mappings.quantityCap')}</th>
              <th className={th}>{t('channels:fields.lastSync')}</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td colSpan={5} className={td}>
                <div className={panel}>{t('channels:mappings.empty')}</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  )
}
