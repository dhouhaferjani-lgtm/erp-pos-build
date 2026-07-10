import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  ArrowLeft,
  ArrowRight,
  Calculator,
  History,
  Image,
  Landmark,
  Package,
  Upload,
  Users,
  UtensilsCrossed,
} from 'lucide-react'
import type { ImportType } from '../types'
import { useCompanyConfig } from '@/contexts/CompanyConfigContext'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

interface ImportTypeConfig {
  type: ImportType
  icon: React.ReactNode
  colorClass: string
}

interface AdvancedLinkConfig {
  key: string
  to: string
  icon: React.ReactNode
  colorClass: string
}

const PRIMARY_IMPORT_TYPES: ImportTypeConfig[] = [
  {
    type: 'parties',
    icon: <Users className="h-6 w-6" />,
    colorClass: `${colorTokens.intent.info.bgSoft} ${colorTokens.intent.info.textStrong}`,
  },
  {
    type: 'products',
    icon: <Package className="h-6 w-6" />,
    colorClass: `${colorTokens.intent.available.bgSoft} ${colorTokens.intent.available.textStrong}`,
  },
]

const ADVANCED_IMPORT_TYPES: ImportTypeConfig[] = [
  {
    type: 'partners',
    icon: <Users className="h-6 w-6" />,
    colorClass: `${colorTokens.intent.primary.bgSoft} ${colorTokens.intent.primary.text}`,
  },
  {
    type: 'products',
    icon: <Package className="h-6 w-6" />,
    colorClass: `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.text}`,
  },
  {
    type: 'product_images',
    icon: <Image className="h-6 w-6" />,
    colorClass: `${colorTokens.intent.notice.bgSoft} ${colorTokens.intent.notice.text}`,
  },
  {
    type: 'composite_items',
    icon: <UtensilsCrossed className="h-6 w-6" />,
    colorClass: `${colorTokens.intent.caution.bgSoft} ${colorTokens.intent.caution.text}`,
  },
  {
    type: 'opening_balances',
    icon: <Calculator className="h-6 w-6" />,
    colorClass: `${colorTokens.intent.accent.bgSoft} ${colorTokens.intent.accent.text}`,
  },
]

const ADVANCED_LINKS: AdvancedLinkConfig[] = [
  {
    key: 'accountingOpeningBalances',
    to: '/settings/opening-balances',
    icon: <Landmark className="h-6 w-6" />,
    colorClass: `${colorTokens.intent.ledger.bgSoft} ${colorTokens.intent.ledger.text}`,
  },
]

export function ImportDashboardPage() {
  const { t } = useTranslation('import')
  const { config } = useCompanyConfig()
  const showAdvancedImports = config?.vertical !== 'parapharmacy'

  const renderImportCard = (card: ImportTypeConfig) => (
    <Link
      key={card.type}
      to={`/settings/import/${card.type}`}
      className={`group block rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6 shadow-sm transition-all ${colorTokens.intent.primary.borderHover} hover:shadow-md`}
    >
      <div className="flex items-start justify-between">
        <div className="flex items-start gap-4">
          <div className={`rounded-lg p-3 ${card.colorClass}`}>
            {card.icon}
          </div>
          <div>
            <h3 className={`font-semibold ${colorTokens.text.primary} ${colorTokens.intent.primary.groupTextHover}`}>
              {t(`types.${card.type}.title`)}
            </h3>
            <p className={`mt-1 text-sm ${colorTokens.text.subtle}`}>
              {t(`types.${card.type}.description`)}
            </p>
          </div>
        </div>
        <ArrowRight className={`h-5 w-5 ${colorTokens.text.disabled} transition-transform group-hover:translate-x-1 ${colorTokens.intent.primary.groupTextHoverSubtle}`} />
      </div>
    </Link>
  )

  const renderAdvancedLink = (link: AdvancedLinkConfig) => (
    <Link
      key={link.key}
      to={link.to}
      className={`group block rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6 shadow-sm transition-all ${colorTokens.intent.primary.borderHover} hover:shadow-md`}
    >
      <div className="flex items-start justify-between">
        <div className="flex items-start gap-4">
          <div className={`rounded-lg p-3 ${link.colorClass}`}>
            {link.icon}
          </div>
          <div>
            <h3 className={`font-semibold ${colorTokens.text.primary} ${colorTokens.intent.primary.groupTextHover}`}>
              {t(`dashboard.${link.key}.title`)}
            </h3>
            <p className={`mt-1 text-sm ${colorTokens.text.subtle}`}>
              {t(`dashboard.${link.key}.description`)}
            </p>
          </div>
        </div>
        <ArrowRight className={`h-5 w-5 ${colorTokens.text.disabled} transition-transform group-hover:translate-x-1 ${colorTokens.intent.primary.groupTextHoverSubtle}`} />
      </div>
    </Link>
  )

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Link
            to="/settings"
            className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.intent.neutral.textHoverStrongest}`}
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:actions.back')}
          </Link>
          <div>
            <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary} flex items-center gap-2`}>
              <Upload className={`h-6 w-6 ${colorTokens.intent.primary.textSubtle}`} />
              {t('dashboard.title')}
            </PageHeaderTitle>
            <p className={colorTokens.text.subtle}>{t('dashboard.description')}</p>
          </div>
        </div>

        <Link
          to="/settings/import/history"
          className={`inline-flex items-center gap-2 rounded-lg border ${colorTokens.border.default} ${colorTokens.surface.base} px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.intent.neutral.bgHover}`}
        >
          <History className="h-4 w-4" />
          {t('actions.viewHistory')}
        </Link>
      </div>

      {/* Import Types Grid */}
      <div className="space-y-4">
        <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
          {t('dashboard.primaryTitle')}
        </h2>
        <p className={`text-sm ${colorTokens.text.muted}`}>{t('dashboard.orderHint')}</p>
        <div className="grid gap-4 md:grid-cols-2">
          {PRIMARY_IMPORT_TYPES.map(renderImportCard)}
        </div>
      </div>

      {showAdvancedImports && (
        <details className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.page} p-4`}>
          <summary className="cursor-pointer list-none">
            <div className="inline-flex flex-col gap-1">
              <span className={`text-lg font-semibold ${colorTokens.text.primary}`}>
                {t('dashboard.advancedTitle')}
              </span>
              <span className={`text-sm ${colorTokens.text.muted}`}>
                {t('dashboard.advancedHint')}
              </span>
            </div>
          </summary>
          <div className="mt-4 grid gap-4 md:grid-cols-2">
            {ADVANCED_IMPORT_TYPES.map(renderImportCard)}
            {ADVANCED_LINKS.map(renderAdvancedLink)}
          </div>
        </details>
      )}

      {/* How It Works */}
      <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
        <h2 className={`text-lg font-semibold ${colorTokens.text.primary} mb-2`}>
          {t('dashboard.howItWorks')}
        </h2>
        <p className={`text-sm ${colorTokens.text.muted} mb-4`}>
          {t('dashboard.howItWorksDescription')}
        </p>
        <ol className={`list-decimal list-inside space-y-2 text-sm ${colorTokens.text.muted}`}>
          <li>{t('dashboard.step1')}</li>
          <li>{t('dashboard.step2')}</li>
          <li>{t('dashboard.step3')}</li>
          <li>{t('dashboard.step4')}</li>
        </ol>
      </div>

      {/* Tips */}
      <div className={`rounded-lg ${colorTokens.surface.page} p-4`}>
        <h3 className={`font-medium ${colorTokens.text.primary} mb-2`}>{t('dashboard.tips')}</h3>
        <ul className={`list-disc list-inside space-y-1 text-sm ${colorTokens.text.muted}`}>
          <li>{t('dashboard.tip1')}</li>
          <li>{t('dashboard.tip2')}</li>
          <li>{t('dashboard.tip3')}</li>
        </ul>
      </div>
    </div>
  )
}
