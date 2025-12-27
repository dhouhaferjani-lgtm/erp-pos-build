import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Settings, Users, Shield, Building2, Upload, Calculator, Package, ChevronRight } from 'lucide-react'

interface SettingsSection {
  titleKey: string
  descriptionKey: string
  icon: React.ReactNode
  href: string
  color: string
}

const sections: SettingsSection[] = [
  {
    titleKey: 'settings.sections.users.title',
    descriptionKey: 'settings.sections.users.description',
    icon: <Users className="h-6 w-6" />,
    href: '/settings/users',
    color: 'bg-blue-100 text-blue-600',
  },
  {
    titleKey: 'settings.sections.roles.title',
    descriptionKey: 'settings.sections.roles.description',
    icon: <Shield className="h-6 w-6" />,
    href: '/settings/roles',
    color: 'bg-purple-100 text-purple-600',
  },
  {
    titleKey: 'settings.sections.company.title',
    descriptionKey: 'settings.sections.company.description',
    icon: <Building2 className="h-6 w-6" />,
    href: '/settings/company',
    color: 'bg-green-100 text-green-600',
  },
  {
    titleKey: 'settings.sections.inventory.title',
    descriptionKey: 'settings.sections.inventory.description',
    icon: <Package className="h-6 w-6" />,
    href: '/settings/inventory',
    color: 'bg-teal-100 text-teal-600',
  },
  {
    titleKey: 'settings.sections.import.title',
    descriptionKey: 'settings.sections.import.description',
    icon: <Upload className="h-6 w-6" />,
    href: '/settings/import',
    color: 'bg-amber-100 text-amber-600',
  },
  {
    titleKey: 'settings.sections.openingBalances.title',
    descriptionKey: 'settings.sections.openingBalances.description',
    icon: <Calculator className="h-6 w-6" />,
    href: '/settings/opening-balances',
    color: 'bg-indigo-100 text-indigo-600',
  },
]

export function SettingsPage() {
  const { t } = useTranslation()

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
          <Settings className="h-6 w-6 text-gray-400" />
          {t('settings.title')}
        </h1>
        <p className="text-gray-500">{t('settings.description')}</p>
      </div>

      {/* Settings Sections */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {sections.map((section) => (
          <Link
            key={section.href}
            to={section.href}
            className="group rounded-lg border border-gray-200 bg-white p-6 hover:border-blue-300 hover:shadow-md transition-all"
          >
            <div className="flex items-start gap-4">
              <div className={`rounded-lg p-3 ${section.color}`}>
                {section.icon}
              </div>
              <div className="flex-1">
                <div className="flex items-center justify-between">
                  <h3 className="font-semibold text-gray-900 group-hover:text-blue-600 transition-colors">
                    {t(section.titleKey)}
                  </h3>
                  <ChevronRight className="h-5 w-5 text-gray-400 group-hover:text-blue-600 transition-colors" />
                </div>
                <p className="mt-1 text-sm text-gray-500">{t(section.descriptionKey)}</p>
              </div>
            </div>
          </Link>
        ))}
      </div>

      {/* App Info */}
      <div className="rounded-lg border border-gray-200 bg-gray-50 p-6">
        <h2 className="text-lg font-semibold text-gray-900 mb-4">{t('settings.appInfo.title')}</h2>
        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <dt className="text-sm font-medium text-gray-500">{t('settings.appInfo.version')}</dt>
            <dd className="mt-1 text-sm text-gray-900">1.0.0</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">{t('settings.appInfo.environment')}</dt>
            <dd className="mt-1 text-sm text-gray-900">
              {import.meta.env.MODE}
            </dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">{t('settings.appInfo.apiUrl')}</dt>
            <dd className="mt-1 text-sm text-gray-900 font-mono text-xs truncate">
              {(import.meta.env['VITE_API_URL'] as string | undefined) ?? t('settings.appInfo.notConfigured')}
            </dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">{t('settings.appInfo.buildDate')}</dt>
            <dd className="mt-1 text-sm text-gray-900">
              {new Date().toLocaleDateString()}
            </dd>
          </div>
        </dl>
      </div>
    </div>
  )
}
