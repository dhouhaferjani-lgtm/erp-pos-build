import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { usePageTitle } from '../../../hooks/usePageTitle'
import { usePermissions, type ModuleKey } from '../../../hooks/usePermissions'
import {
  Store,
  ShoppingCart,
  UtensilsCrossed,
  ChefHat,
  Monitor,
  Clock,
  ExternalLink,
  type LucideIcon,
} from 'lucide-react'
import { cn } from '../../../lib/utils'
import { tokens, textColors } from '../../../lib/designTokens'
import { PageHeader } from '../../../components/molecules/PageHeader'
import { HubCard, HubGrid } from '../../../components/molecules/HubCard'

interface HubCardDef {
  titleKey: string
  descriptionKey: string
  icon: LucideIcon
  href: string
  permissionModule?: ModuleKey
}

const cards: HubCardDef[] = [
  {
    titleKey: 'hub.cards.orders.title',
    descriptionKey: 'hub.cards.orders.description',
    icon: ShoppingCart,
    href: '/pos/orders',
    permissionModule: 'pos',
  },
  {
    titleKey: 'hub.cards.tables.title',
    descriptionKey: 'hub.cards.tables.description',
    icon: UtensilsCrossed,
    href: '/pos/tables',
    permissionModule: 'pos',
  },
  {
    titleKey: 'hub.cards.kitchen.title',
    descriptionKey: 'hub.cards.kitchen.description',
    icon: ChefHat,
    href: '/pos/kitchen',
    permissionModule: 'pos',
  },
  {
    titleKey: 'hub.cards.terminals.title',
    descriptionKey: 'hub.cards.terminals.description',
    icon: Monitor,
    href: '/pos/terminals',
    permissionModule: 'pos',
  },
  {
    titleKey: 'hub.cards.shiftHistory.title',
    descriptionKey: 'hub.cards.shiftHistory.description',
    icon: Clock,
    href: '/pos/shift-history',
    permissionModule: 'pos',
  },
]

export function PosHubPage() {
  const { t } = useTranslation(['pos'])
  usePageTitle('hub.title', 'pos')
  const { canAccessModule } = usePermissions()

  const visibleCards = cards.filter((card) => {
    if (card.permissionModule && !canAccessModule(card.permissionModule)) {
      return false
    }
    return true
  })

  return (
    <div className="space-y-6">
      <PageHeader title={t('hub.title')} subtitle={t('hub.description')} className="mb-0" />

      {/* Prominent Open POS call-to-action */}
      <Link
        to="/pos/transactions"
        className={cn(
          tokens.card.base,
          tokens.card.hover,
          tokens.card.hoverPrimary,
          'flex items-center justify-between gap-4',
        )}
      >
        <div className="flex items-center gap-4">
          <span
            className={cn(
              'flex shrink-0 items-center justify-center rounded-lg p-3',
              tokens.button.primary,
            )}
          >
            <Store className="h-8 w-8" />
          </span>
          <div>
            <h2 className={cn('text-lg font-bold', textColors.primary)}>
              {t('hub.openPos')}
            </h2>
            <p className={cn('text-sm', textColors.tertiary)}>{t('hub.openPosDescription')}</p>
          </div>
        </div>
        <ExternalLink className={cn('h-6 w-6 shrink-0', textColors.disabled)} aria-hidden="true" />
      </Link>

      <HubGrid>
        {visibleCards.map((card) => (
          <HubCard
            key={card.href}
            to={card.href}
            icon={card.icon}
            title={t(card.titleKey)}
            description={t(card.descriptionKey)}
          />
        ))}
      </HubGrid>
    </div>
  )
}
