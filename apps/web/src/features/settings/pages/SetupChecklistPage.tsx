import { useTranslation } from 'react-i18next'
import { SetupChecklist } from '../components/SetupChecklist'

export function SetupChecklistPage() {
  const { t } = useTranslation('settings')

  return (
    <div className="mx-auto max-w-2xl px-4 py-8">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">{t('onboarding.title')}</h1>
        <p className="mt-1 text-sm text-gray-600">{t('onboarding.description')}</p>
      </div>
      <SetupChecklist />
    </div>
  )
}
