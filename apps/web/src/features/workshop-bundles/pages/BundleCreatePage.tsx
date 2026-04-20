import { useTranslation } from 'react-i18next'
import { BundleForm } from '../components/organisms/BundleForm'

export function BundleCreatePage() {
  const { t } = useTranslation('workshop-bundles')

  return (
    <div className="mx-auto max-w-3xl p-6">
      <h1 className="mb-4 text-2xl font-semibold text-gray-900">
        {t('create.title')}
      </h1>
      <BundleForm />
    </div>
  )
}
