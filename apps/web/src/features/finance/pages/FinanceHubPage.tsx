import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { usePageTitle } from '../../../hooks/usePageTitle'
import { usePermissions } from '../../../hooks/usePermissions'
import {
  Landmark,
  CreditCard,
  Receipt,
  FileText,
  BookOpen,
  PenLine,
  Scale,
  TrendingUp,
  PieChart,
  Users,
  Building2,
  BarChart3,
  FileCheck,
  ChevronRight,
} from 'lucide-react'

interface HubCard {
  titleKey: string
  descriptionKey: string
  icon: React.ReactNode
  href: string
  color: string
  permissionModule?: string
}

interface HubSection {
  titleKey: string
  cards: HubCard[]
}

const sections: HubSection[] = [
  {
    titleKey: 'hub.sections.money',
    cards: [
      {
        titleKey: 'hub.cards.payments.title',
        descriptionKey: 'hub.cards.payments.description',
        icon: <CreditCard className="h-6 w-6" />,
        href: '/treasury/payments',
        color: 'bg-green-100 text-green-600',
        permissionModule: 'treasury',
      },
      {
        titleKey: 'hub.cards.expenses.title',
        descriptionKey: 'hub.cards.expenses.description',
        icon: <Receipt className="h-6 w-6" />,
        href: '/expenses',
        color: 'bg-red-100 text-red-600',
        permissionModule: 'treasury',
      },
      {
        titleKey: 'hub.cards.withholdingCertificates.title',
        descriptionKey: 'hub.cards.withholdingCertificates.description',
        icon: <FileText className="h-6 w-6" />,
        href: '/treasury/withholding-certificates',
        color: 'bg-amber-100 text-amber-600',
        permissionModule: 'withholding',
      },
    ],
  },
  {
    titleKey: 'hub.sections.accounting',
    cards: [
      {
        titleKey: 'hub.cards.chartOfAccounts.title',
        descriptionKey: 'hub.cards.chartOfAccounts.description',
        icon: <BookOpen className="h-6 w-6" />,
        href: '/finance/chart-of-accounts',
        color: 'bg-blue-100 text-blue-600',
        permissionModule: 'accounts',
      },
      {
        titleKey: 'hub.cards.generalLedger.title',
        descriptionKey: 'hub.cards.generalLedger.description',
        icon: <FileCheck className="h-6 w-6" />,
        href: '/finance/ledger',
        color: 'bg-purple-100 text-purple-600',
        permissionModule: 'finance',
      },
      {
        titleKey: 'hub.cards.journalEntries.title',
        descriptionKey: 'hub.cards.journalEntries.description',
        icon: <PenLine className="h-6 w-6" />,
        href: '/finance/journal-entries',
        color: 'bg-indigo-100 text-indigo-600',
        permissionModule: 'finance',
      },
    ],
  },
  {
    titleKey: 'hub.sections.reports',
    cards: [
      {
        titleKey: 'hub.cards.trialBalance.title',
        descriptionKey: 'hub.cards.trialBalance.description',
        icon: <Scale className="h-6 w-6" />,
        href: '/finance/trial-balance',
        color: 'bg-teal-100 text-teal-600',
        permissionModule: 'accounts',
      },
      {
        titleKey: 'hub.cards.profitLoss.title',
        descriptionKey: 'hub.cards.profitLoss.description',
        icon: <TrendingUp className="h-6 w-6" />,
        href: '/finance/profit-loss',
        color: 'bg-emerald-100 text-emerald-600',
        permissionModule: 'accounts',
      },
      {
        titleKey: 'hub.cards.balanceSheet.title',
        descriptionKey: 'hub.cards.balanceSheet.description',
        icon: <PieChart className="h-6 w-6" />,
        href: '/finance/balance-sheet',
        color: 'bg-cyan-100 text-cyan-600',
        permissionModule: 'accounts',
      },
      {
        titleKey: 'hub.cards.agedReceivables.title',
        descriptionKey: 'hub.cards.agedReceivables.description',
        icon: <Users className="h-6 w-6" />,
        href: '/finance/aged-receivables',
        color: 'bg-orange-100 text-orange-600',
        permissionModule: 'accounts',
      },
      {
        titleKey: 'hub.cards.agedPayables.title',
        descriptionKey: 'hub.cards.agedPayables.description',
        icon: <Building2 className="h-6 w-6" />,
        href: '/finance/aged-payables',
        color: 'bg-pink-100 text-pink-600',
        permissionModule: 'accounts',
      },
      {
        titleKey: 'hub.cards.posAnalytics.title',
        descriptionKey: 'hub.cards.posAnalytics.description',
        icon: <BarChart3 className="h-6 w-6" />,
        href: '/pos/analytics',
        color: 'bg-violet-100 text-violet-600',
        permissionModule: 'pos',
      },
      {
        titleKey: 'hub.cards.zReports.title',
        descriptionKey: 'hub.cards.zReports.description',
        icon: <Landmark className="h-6 w-6" />,
        href: '/pos/z-reports',
        color: 'bg-slate-100 text-slate-600',
        permissionModule: 'pos',
      },
    ],
  },
]

export function FinanceHubPage() {
  const { t } = useTranslation(['finance'])
  usePageTitle('hub.title', 'finance')
  const { canAccessModule } = usePermissions()

  const filteredSections = sections
    .map((section) => ({
      ...section,
      cards: section.cards.filter((card) => {
        if (card.permissionModule && !canAccessModule(card.permissionModule)) {
          return false
        }
        return true
      }),
    }))
    .filter((section) => section.cards.length > 0)

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
          <Landmark className="h-6 w-6 text-gray-400" />
          {t('hub.title')}
        </h1>
        <p className="text-gray-500">{t('hub.description')}</p>
      </div>

      {filteredSections.map((section) => (
        <div key={section.titleKey} className="space-y-4">
          <h2 className="text-lg font-semibold text-gray-700">
            {t(section.titleKey)}
          </h2>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {section.cards.map((card) => (
              <Link
                key={card.href}
                to={card.href}
                className="group rounded-lg border border-gray-200 bg-white p-6 hover:border-blue-300 hover:shadow-md transition-all"
              >
                <div className="flex items-start gap-4">
                  <div className={`rounded-lg p-3 ${card.color}`}>
                    {card.icon}
                  </div>
                  <div className="flex-1">
                    <div className="flex items-center justify-between">
                      <h3 className="font-semibold text-gray-900 group-hover:text-blue-600 transition-colors">
                        {t(card.titleKey)}
                      </h3>
                      <ChevronRight className="h-5 w-5 text-gray-400 group-hover:text-blue-600 transition-colors" />
                    </div>
                    <p className="mt-1 text-sm text-gray-500">{t(card.descriptionKey)}</p>
                  </div>
                </div>
              </Link>
            ))}
          </div>
        </div>
      ))}
    </div>
  )
}
