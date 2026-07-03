import { usePageTitle } from '../../hooks/usePageTitle'
import { useTranslation } from 'react-i18next'
import {
  Users,
  Shield,
  Building2,
  MapPin,
  Upload,
  Calculator,
  Package,
  Receipt,
  Ruler,
  Store,
  RotateCcw,
  ShieldAlert,
  type LucideIcon,
} from 'lucide-react'
import { cn } from '../../lib/utils'
import { tokens, textColors, colors, borderColors } from '../../lib/designTokens'
import { PageHeader } from '../../components/molecules/PageHeader'
import { HubCard, HubGrid } from '../../components/molecules/HubCard'

interface SettingsSection {
  titleKey: string
  descriptionKey: string
  icon: LucideIcon
  href: string
}

const sections: SettingsSection[] = [
  {
    titleKey: 'sections.users.title',
    descriptionKey: 'sections.users.description',
    icon: Users,
    href: '/settings/users',
  },
  {
    titleKey: 'sections.roles.title',
    descriptionKey: 'sections.roles.description',
    icon: Shield,
    href: '/settings/roles',
  },
  {
    titleKey: 'sections.company.title',
    descriptionKey: 'sections.company.description',
    icon: Building2,
    href: '/settings/company',
  },
  {
    titleKey: 'sections.locations.title',
    descriptionKey: 'sections.locations.description',
    icon: MapPin,
    href: '/settings/locations',
  },
  {
    titleKey: 'sections.tax.title',
    descriptionKey: 'sections.tax.description',
    icon: Receipt,
    href: '/settings/tax',
  },
  {
    titleKey: 'sections.inventory.title',
    descriptionKey: 'sections.inventory.description',
    icon: Package,
    href: '/settings/inventory',
  },
  {
    titleKey: 'sections.units.title',
    descriptionKey: 'sections.units.description',
    icon: Ruler,
    href: '/settings/units',
  },
  {
    titleKey: 'sections.import.title',
    descriptionKey: 'sections.import.description',
    icon: Upload,
    href: '/settings/import',
  },
  {
    titleKey: 'sections.openingBalances.title',
    descriptionKey: 'sections.openingBalances.description',
    icon: Calculator,
    href: '/settings/opening-balances',
  },
  {
    titleKey: 'sections.pos.title',
    descriptionKey: 'sections.pos.description',
    icon: Store,
    href: '/pos/terminals',
  },
  {
    titleKey: 'sections.posRefundPolicies.title',
    descriptionKey: 'sections.posRefundPolicies.description',
    icon: RotateCcw,
    href: '/settings/pos-refund-policies',
  },
  {
    titleKey: 'sections.customerHistoryAudit.title',
    descriptionKey: 'sections.customerHistoryAudit.description',
    icon: ShieldAlert,
    href: '/settings/audit/customer-history',
  },
]

export function SettingsPage() {
  const { t } = useTranslation(['settings'])
  usePageTitle('settings.title', 'common')

  return (
    <div className="space-y-6">
      <PageHeader title={t('title')} subtitle={t('description')} className="mb-0" />

      {/* Settings Sections */}
      <HubGrid>
        {sections.map((section) => (
          <HubCard
            key={section.href}
            to={section.href}
            icon={section.icon}
            title={t(section.titleKey)}
            description={t(section.descriptionKey)}
          />
        ))}
      </HubGrid>

      {/* App Info */}
      <div
        className={cn(
          'rounded-lg border p-6',
          borderColors.light,
          colors.neutral[50],
        )}
      >
        <h2 className={cn(tokens.heading.section, 'mb-4')}>{t('appInfo.title')}</h2>
        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('appInfo.version')}</dt>
            <dd className={cn('mt-1 text-sm', textColors.primary)}>1.0.0</dd>
          </div>
          <div>
            <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('appInfo.environment')}</dt>
            <dd className={cn('mt-1 text-sm', textColors.primary)}>
              {import.meta.env.MODE}
            </dd>
          </div>
          <div>
            <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('appInfo.apiUrl')}</dt>
            <dd className={cn('mt-1 truncate font-mono text-xs', textColors.primary)}>
              {typeof import.meta.env['VITE_API_URL'] === 'string'
                ? import.meta.env['VITE_API_URL']
                : t('appInfo.notConfigured')}
            </dd>
          </div>
          <div>
            <dt className={cn('text-sm font-medium', textColors.tertiary)}>{t('appInfo.buildDate')}</dt>
            <dd className={cn('mt-1 text-sm', textColors.primary)}>
              {new Date().toLocaleDateString()}
            </dd>
          </div>
        </dl>
      </div>
    </div>
  )
}
