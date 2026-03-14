import { useTranslation } from 'react-i18next'
import { JetExportForm } from '../components/JetExportForm'
import { ChainVerificationPanel } from '../components/ChainVerificationPanel'
import { ReprintLogTable } from '../components/ReprintLogTable'

export function ComplianceExportPage() {
  const { t } = useTranslation('compliance')

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">{t('title')}</h1>
        <p className="mt-1 text-sm text-gray-600">{t('subtitle')}</p>
      </div>
      <JetExportForm />
      <ChainVerificationPanel />
      <ReprintLogTable />
    </div>
  )
}
