import { useTranslation } from 'react-i18next'
import { PageHeader } from '../../../components/molecules/PageHeader'
import { SetupChecklist } from '../components/SetupChecklist'

export function SetupChecklistPage() {
  const { t } = useTranslation('settings')

  return (
    <div className="mx-auto max-w-2xl px-4 py-8">
      <PageHeader title={t('onboarding.title')} subtitle={t('onboarding.description')} />
      <SetupChecklist />
    </div>
  )
}
