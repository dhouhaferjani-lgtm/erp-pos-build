import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  ArrowLeft,
  Upload,
  History,
  Users,
  Package,
  Calculator,
  ArrowRight,
  Image,
  UtensilsCrossed,
  Warehouse,
} from 'lucide-react'
import type { ImportType } from '../types'

interface ImportTypeConfig {
  type: ImportType
  icon: React.ReactNode
  colorClass: string
}

const IMPORT_TYPES: ImportTypeConfig[] = [
  {
    type: 'partners',
    icon: <Users className="h-6 w-6" />,
    colorClass: 'bg-blue-100 text-blue-600',
  },
  {
    type: 'products',
    icon: <Package className="h-6 w-6" />,
    colorClass: 'bg-green-100 text-green-600',
  },
  {
    type: 'product_images',
    icon: <Image className="h-6 w-6" />,
    colorClass: 'bg-orange-100 text-orange-600',
  },
  {
    type: 'composite_items',
    icon: <UtensilsCrossed className="h-6 w-6" />,
    colorClass: 'bg-amber-100 text-amber-600',
  },
  {
    type: 'stock_levels',
    icon: <Warehouse className="h-6 w-6" />,
    colorClass: 'bg-teal-100 text-teal-600',
  },
  {
    type: 'opening_balances',
    icon: <Calculator className="h-6 w-6" />,
    colorClass: 'bg-purple-100 text-purple-600',
  },
]

export function ImportDashboardPage() {
  const { t } = useTranslation('import')

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Link
            to="/settings"
            className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:actions.back')}
          </Link>
          <div>
            <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
              <Upload className="h-6 w-6 text-blue-500" />
              {t('dashboard.title')}
            </h1>
            <p className="text-gray-500">{t('dashboard.description')}</p>
          </div>
        </div>

        <Link
          to="/settings/import/history"
          className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
        >
          <History className="h-4 w-4" />
          {t('actions.viewHistory')}
        </Link>
      </div>

      {/* Import Types Grid */}
      <div className="space-y-4">
        <h2 className="text-lg font-semibold text-gray-900">
          {t('dashboard.importTypes')}
        </h2>
        <div className="grid gap-4 md:grid-cols-2">
          {IMPORT_TYPES.map((config) => (
            <Link
              key={config.type}
              to={`/settings/import/${config.type}`}
              className="group block rounded-lg border border-gray-200 bg-white p-6 shadow-sm transition-all hover:border-blue-300 hover:shadow-md"
            >
              <div className="flex items-start justify-between">
                <div className="flex items-start gap-4">
                  <div className={`rounded-lg p-3 ${config.colorClass}`}>
                    {config.icon}
                  </div>
                  <div>
                    <h3 className="font-semibold text-gray-900 group-hover:text-blue-600">
                      {t(`types.${config.type}.title`)}
                    </h3>
                    <p className="mt-1 text-sm text-gray-500">
                      {t(`types.${config.type}.description`)}
                    </p>
                  </div>
                </div>
                <ArrowRight className="h-5 w-5 text-gray-400 transition-transform group-hover:translate-x-1 group-hover:text-blue-500" />
              </div>
            </Link>
          ))}
        </div>
      </div>

      {/* How It Works */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <h2 className="text-lg font-semibold text-gray-900 mb-2">
          {t('dashboard.howItWorks')}
        </h2>
        <p className="text-sm text-gray-600 mb-4">
          {t('dashboard.howItWorksDescription')}
        </p>
        <ol className="list-decimal list-inside space-y-2 text-sm text-gray-600">
          <li>{t('dashboard.step1')}</li>
          <li>{t('dashboard.step2')}</li>
          <li>{t('dashboard.step3')}</li>
          <li>{t('dashboard.step4')}</li>
        </ol>
      </div>

      {/* Tips */}
      <div className="rounded-lg bg-gray-50 p-4">
        <h3 className="font-medium text-gray-900 mb-2">{t('dashboard.tips')}</h3>
        <ul className="list-disc list-inside space-y-1 text-sm text-gray-600">
          <li>{t('dashboard.tip1')}</li>
          <li>{t('dashboard.tip2')}</li>
          <li>{t('dashboard.tip3')}</li>
        </ul>
      </div>
    </div>
  )
}
