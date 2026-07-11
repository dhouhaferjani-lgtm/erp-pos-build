import { useTranslation } from 'react-i18next'
import { textColors } from '../../../lib/designTokens'
import { BundleForm } from '../components/organisms/BundleForm'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

export function BundleCreatePage() {
  const { t } = useTranslation('workshop-bundles')

  return (
    <div className="mx-auto max-w-3xl p-6">
      <PageHeaderTitle className={`mb-4 text-2xl font-semibold ${textColors.primary}`}>
        {t('create.title')}
      </PageHeaderTitle>
      <BundleForm />
    </div>
  )
}
