import { useTranslation } from 'react-i18next'
import { JetExportForm } from '../components/JetExportForm'
import { ChainVerificationPanel } from '../components/ChainVerificationPanel'
import { ReprintLogTable } from '../components/ReprintLogTable'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

export function ComplianceExportPage() {
  const { t } = useTranslation('compliance')

  return (
    <div className="space-y-6">
      <div>
        <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>{t('title')}</PageHeaderTitle>
        <p className={`mt-1 text-sm ${colorTokens.text.muted}`}>{t('subtitle')}</p>
      </div>
      <JetExportForm />
      <ChainVerificationPanel />
      <ReprintLogTable />
    </div>
  )
}
