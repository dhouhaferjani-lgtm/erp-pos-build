import { useTranslation } from 'react-i18next'
import { usePageTitle } from '../../../hooks/usePageTitle'
import { usePermissions, type Permission } from '../../../hooks/usePermissions'
import {
  Landmark,
  Wallet,
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
  ArrowLeftRight,
  type LucideIcon,
} from 'lucide-react'
import { cn } from '../../../lib/utils'
import { tokens } from '../../../lib/designTokens'
import { PageHeader } from '../../../components/molecules/PageHeader'
import { HubCard, HubGrid } from '../../../components/molecules/HubCard'
import { useViewScope } from '@/features/locations/hooks/useViewScope'

interface HubCardDef {
  titleKey: string
  descriptionKey: string
  icon: LucideIcon
  href: string
  permissionModule?: string
  permission?: Permission
}

interface HubSection {
  titleKey: string
  cards: HubCardDef[]
}

const sections: HubSection[] = [
  {
    titleKey: 'hub.sections.bankingAndPayments',
    cards: [
      {
        titleKey: 'hub.cards.treasuryOverview.title',
        descriptionKey: 'hub.cards.treasuryOverview.description',
        icon: Wallet,
        href: '/finance/overview',
        permission: 'reports.operational',
      },
      {
        titleKey: 'hub.cards.payments.title',
        descriptionKey: 'hub.cards.payments.description',
        icon: CreditCard,
        href: '/treasury/payments',
        permissionModule: 'treasury',
      },
      {
        titleKey: 'hub.cards.repositories.title',
        descriptionKey: 'hub.cards.repositories.description',
        icon: Landmark,
        href: '/treasury/repositories',
        permissionModule: 'treasury',
      },
      {
        titleKey: 'hub.cards.instruments.title',
        descriptionKey: 'hub.cards.instruments.description',
        icon: FileText,
        href: '/treasury/instruments',
        permissionModule: 'treasury',
      },
      {
        titleKey: 'hub.cards.bankReconciliation.title',
        descriptionKey: 'hub.cards.bankReconciliation.description',
        icon: ArrowLeftRight,
        href: '/treasury/statements',
        permissionModule: 'treasury',
        permission: 'bank-statements.view',
      },
      {
        titleKey: 'hub.cards.expenses.title',
        descriptionKey: 'hub.cards.expenses.description',
        icon: Receipt,
        href: '/expenses',
        permissionModule: 'treasury',
      },
      {
        titleKey: 'hub.cards.withholdingCertificates.title',
        descriptionKey: 'hub.cards.withholdingCertificates.description',
        icon: FileText,
        href: '/treasury/withholding-certificates',
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
        icon: BookOpen,
        href: '/finance/chart-of-accounts',
        permissionModule: 'accounts',
      },
      {
        titleKey: 'hub.cards.generalLedger.title',
        descriptionKey: 'hub.cards.generalLedger.description',
        icon: Landmark,
        href: '/finance/ledger',
        permissionModule: 'finance',
      },
      {
        titleKey: 'hub.cards.journalEntries.title',
        descriptionKey: 'hub.cards.journalEntries.description',
        icon: PenLine,
        href: '/finance/journal-entries',
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
        icon: Scale,
        href: '/finance/trial-balance',
        permission: 'reports.financial',
      },
      {
        titleKey: 'hub.cards.profitLoss.title',
        descriptionKey: 'hub.cards.profitLoss.description',
        icon: TrendingUp,
        href: '/finance/profit-loss',
        permission: 'reports.financial',
      },
      {
        titleKey: 'hub.cards.balanceSheet.title',
        descriptionKey: 'hub.cards.balanceSheet.description',
        icon: PieChart,
        href: '/finance/balance-sheet',
        permission: 'reports.financial',
      },
      {
        titleKey: 'hub.cards.agedReceivables.title',
        descriptionKey: 'hub.cards.agedReceivables.description',
        icon: Users,
        href: '/finance/aged-receivables',
        permission: 'reports.operational',
      },
      {
        titleKey: 'hub.cards.agedPayables.title',
        descriptionKey: 'hub.cards.agedPayables.description',
        icon: Building2,
        href: '/finance/aged-payables',
        permission: 'reports.operational',
      },
    ],
  },
]

export function FinanceHubPage() {
  const { t } = useTranslation(['finance'])
  usePageTitle('hub.title', 'finance')
  const { canAccessModule, hasPermission } = usePermissions()
  const { scope } = useViewScope()

  const scopedHref = (href: string): string => {
    if (scope === 'all' || scope.length === 0) return href
    const params = new URLSearchParams()
    scope.forEach((locationId) => params.append('location_ids[]', locationId))
    return `${href}${href.includes('?') ? '&' : '?'}${params.toString()}`
  }

  const filteredSections = sections
    .map((section) => ({
      ...section,
      cards: section.cards.filter((card) => {
        if (card.permission && !hasPermission(card.permission)) {
          return false
        }
        if (card.permissionModule && !canAccessModule(card.permissionModule)) {
          return false
        }
        return true
      }),
    }))
    .filter((section) => section.cards.length > 0)

  return (
    <div className="space-y-8">
      <PageHeader title={t('hub.title')} subtitle={t('hub.description')} className="mb-0" />

      {filteredSections.map((section) => (
        <div key={section.titleKey} className="space-y-4">
          <h2 className={cn(tokens.heading.section)}>{t(section.titleKey)}</h2>
          <HubGrid>
            {section.cards.map((card) => (
              <HubCard
                key={card.href}
                to={scopedHref(card.href)}
                icon={card.icon}
                title={t(card.titleKey)}
                description={t(card.descriptionKey)}
              />
            ))}
          </HubGrid>
        </div>
      ))}
    </div>
  )
}
