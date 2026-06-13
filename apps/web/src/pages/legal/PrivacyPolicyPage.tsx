import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'

export function PrivacyPolicyPage() {
  const { t } = useTranslation('common')

  return (
    <div className="min-h-screen bg-gray-50 dark:bg-gray-900">
      <div className="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
        <Link
          to="/"
          className="mb-8 inline-flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('actions.back')}
        </Link>

        <h1 className="mb-8 text-3xl font-bold text-gray-900 dark:text-white">
          {t('legal.privacyPolicy')}
        </h1>

        <div className="prose prose-gray dark:prose-invert max-w-none space-y-8">
          <p className="text-sm text-gray-500 dark:text-gray-400">
            {t('legal.privacy.lastUpdated')}: 2026-03-20
          </p>

          {/* Section 1 */}
          <section>
            <h2 className="text-xl font-semibold text-gray-900 dark:text-white">
              {t('legal.privacy.section1.title')}
            </h2>
            <p className="mt-2 text-gray-600 dark:text-gray-300">
              {t('legal.privacy.section1.body')}
            </p>
          </section>

          {/* Section 2 */}
          <section>
            <h2 className="text-xl font-semibold text-gray-900 dark:text-white">
              {t('legal.privacy.section2.title')}
            </h2>
            <p className="mt-2 text-gray-600 dark:text-gray-300">
              {t('legal.privacy.section2.intro')}
            </p>
            <ul className="mt-2 list-disc space-y-1 pl-6 text-gray-600 dark:text-gray-300">
              <li>
                <strong>{t('legal.privacy.section2.accountInfo.label')}</strong>:{' '}
                {t('legal.privacy.section2.accountInfo.detail')}
              </li>
              <li>
                <strong>{t('legal.privacy.section2.usageData.label')}</strong>:{' '}
                {t('legal.privacy.section2.usageData.detail')}
              </li>
              <li>
                <strong>{t('legal.privacy.section2.businessData.label')}</strong>:{' '}
                {t('legal.privacy.section2.businessData.detail')}
              </li>
              <li>
                <strong>{t('legal.privacy.section2.cookies.label')}</strong>:{' '}
                {t('legal.privacy.section2.cookies.detail')}
              </li>
            </ul>
          </section>

          {/* Section 3 */}
          <section>
            <h2 className="text-xl font-semibold text-gray-900 dark:text-white">
              {t('legal.privacy.section3.title')}
            </h2>
            <ul className="mt-2 list-disc space-y-1 pl-6 text-gray-600 dark:text-gray-300">
              <li>{t('legal.privacy.section3.purpose1')}</li>
              <li>{t('legal.privacy.section3.purpose2')}</li>
              <li>{t('legal.privacy.section3.purpose3')}</li>
              <li>{t('legal.privacy.section3.purpose4')}</li>
            </ul>
            <p className="mt-2 text-gray-600 dark:text-gray-300">
              {t('legal.privacy.section3.legalBasis')}
            </p>
          </section>

          {/* Section 4 */}
          <section>
            <h2 className="text-xl font-semibold text-gray-900 dark:text-white">
              {t('legal.privacy.section4.title')}
            </h2>
            <p className="mt-2 text-gray-600 dark:text-gray-300">
              {t('legal.privacy.section4.body')}
            </p>
          </section>

          {/* Section 5 */}
          <section>
            <h2 className="text-xl font-semibold text-gray-900 dark:text-white">
              {t('legal.privacy.section5.title')}
            </h2>
            <p className="mt-2 text-gray-600 dark:text-gray-300">
              {t('legal.privacy.section5.intro')}
            </p>
            <ul className="mt-2 list-disc space-y-1 pl-6 text-gray-600 dark:text-gray-300">
              <li>
                <strong>{t('legal.privacy.section5.access.label')}</strong>:{' '}
                {t('legal.privacy.section5.access.detail')}
              </li>
              <li>
                <strong>{t('legal.privacy.section5.rectification.label')}</strong>:{' '}
                {t('legal.privacy.section5.rectification.detail')}
              </li>
              <li>
                <strong>{t('legal.privacy.section5.erasure.label')}</strong>:{' '}
                {t('legal.privacy.section5.erasure.detail')}
              </li>
              <li>
                <strong>{t('legal.privacy.section5.portability.label')}</strong>:{' '}
                {t('legal.privacy.section5.portability.detail')}
              </li>
              <li>
                <strong>{t('legal.privacy.section5.objection.label')}</strong>:{' '}
                {t('legal.privacy.section5.objection.detail')}
              </li>
            </ul>
          </section>

          {/* Section 6 */}
          <section>
            <h2 className="text-xl font-semibold text-gray-900 dark:text-white">
              {t('legal.privacy.section6.title')}
            </h2>
            <p className="mt-2 text-gray-600 dark:text-gray-300">
              {t('legal.privacy.section6.body')}
            </p>
          </section>

          {/* Section 7 */}
          <section>
            <h2 className="text-xl font-semibold text-gray-900 dark:text-white">
              {t('legal.privacy.section7.title')}
            </h2>
            <p className="mt-2 text-gray-600 dark:text-gray-300">
              {t('legal.privacy.section7.intro')}
            </p>
            <p className="mt-2 text-gray-600 dark:text-gray-300">
              <a
                href="mailto:privacy@company.com"
                className="text-primary-600 hover:text-primary-500 dark:text-primary-400"
              >
                privacy@company.com
              </a>
            </p>
            <p className="mt-2 text-gray-600 dark:text-gray-300">
              {t('legal.privacy.section7.cnil')}
            </p>
          </section>
        </div>
      </div>
    </div>
  )
}
