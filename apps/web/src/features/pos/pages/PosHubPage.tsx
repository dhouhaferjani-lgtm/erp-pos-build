import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { usePageTitle } from '../../../hooks/usePageTitle'
import { usePermissions } from '../../../hooks/usePermissions'
import {
  Store,
  ShoppingCart,
  UtensilsCrossed,
  ChefHat,
  Monitor,
  Clock,
  ChevronRight,
  ExternalLink,
} from 'lucide-react'

interface HubCard {
  titleKey: string
  descriptionKey: string
  icon: React.ReactNode
  href: string
  color: string
  permissionModule?: string
}

const cards: HubCard[] = [
  {
    titleKey: 'hub.cards.orders.title',
    descriptionKey: 'hub.cards.orders.description',
    icon: <ShoppingCart className="h-6 w-6" />,
    href: '/pos/orders',
    color: 'bg-blue-100 text-blue-600',
    permissionModule: 'pos',
  },
  {
    titleKey: 'hub.cards.tables.title',
    descriptionKey: 'hub.cards.tables.description',
    icon: <UtensilsCrossed className="h-6 w-6" />,
    href: '/pos/tables',
    color: 'bg-purple-100 text-purple-600',
    permissionModule: 'pos',
  },
  {
    titleKey: 'hub.cards.kitchen.title',
    descriptionKey: 'hub.cards.kitchen.description',
    icon: <ChefHat className="h-6 w-6" />,
    href: '/pos/kitchen',
    color: 'bg-orange-100 text-orange-600',
    permissionModule: 'pos',
  },
  {
    titleKey: 'hub.cards.terminals.title',
    descriptionKey: 'hub.cards.terminals.description',
    icon: <Monitor className="h-6 w-6" />,
    href: '/pos/terminals',
    color: 'bg-green-100 text-green-600',
    permissionModule: 'pos',
  },
  {
    titleKey: 'hub.cards.shiftHistory.title',
    descriptionKey: 'hub.cards.shiftHistory.description',
    icon: <Clock className="h-6 w-6" />,
    href: '/pos/shift-history',
    color: 'bg-amber-100 text-amber-600',
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
      <div>
        <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
          <Store className="h-6 w-6 text-gray-400" />
          {t('hub.title')}
        </h1>
        <p className="text-gray-500">{t('hub.description')}</p>
      </div>

      {/* Prominent Open POS button */}
      <Link
        to="/pos/transactions"
        className="flex items-center justify-between rounded-lg border-2 border-blue-200 bg-blue-50 p-6 hover:border-blue-400 hover:bg-blue-100 transition-all group"
      >
        <div className="flex items-center gap-4">
          <div className="rounded-lg bg-blue-600 p-3 text-white">
            <Store className="h-8 w-8" />
          </div>
          <div>
            <h2 className="text-lg font-bold text-blue-900 group-hover:text-blue-700 transition-colors">
              {t('hub.openPos')}
            </h2>
            <p className="text-sm text-blue-700">{t('hub.openPosDescription')}</p>
          </div>
        </div>
        <ExternalLink className="h-6 w-6 text-blue-400 group-hover:text-blue-600 transition-colors" />
      </Link>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {visibleCards.map((card) => (
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
  )
}
