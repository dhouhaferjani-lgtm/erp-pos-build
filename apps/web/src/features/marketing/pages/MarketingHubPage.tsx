import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { usePageTitle } from '../../../hooks/usePageTitle'
import { usePermissions } from '../../../hooks/usePermissions'
import {
  Megaphone,
  Percent,
  Ticket,
  Award,
  Users,
  Building2,
  UserCircle,
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

const cards: HubCard[] = [
  {
    titleKey: 'hub.cards.promotions.title',
    descriptionKey: 'hub.cards.promotions.description',
    icon: <Percent className="h-6 w-6" />,
    href: '/pos/promotions',
    color: 'bg-red-100 text-red-600',
    permissionModule: 'promotions',
  },
  {
    titleKey: 'hub.cards.coupons.title',
    descriptionKey: 'hub.cards.coupons.description',
    icon: <Ticket className="h-6 w-6" />,
    href: '/pos/coupons',
    color: 'bg-orange-100 text-orange-600',
    permissionModule: 'coupons',
  },
  {
    titleKey: 'hub.cards.loyaltyPrograms.title',
    descriptionKey: 'hub.cards.loyaltyPrograms.description',
    icon: <Award className="h-6 w-6" />,
    href: '/pos/loyalty/programs',
    color: 'bg-purple-100 text-purple-600',
    permissionModule: 'loyalty',
  },
  {
    titleKey: 'hub.cards.loyaltyMembers.title',
    descriptionKey: 'hub.cards.loyaltyMembers.description',
    icon: <Users className="h-6 w-6" />,
    href: '/pos/loyalty/members',
    color: 'bg-indigo-100 text-indigo-600',
    permissionModule: 'loyalty',
  },
  {
    titleKey: 'hub.cards.companies.title',
    descriptionKey: 'hub.cards.companies.description',
    icon: <Building2 className="h-6 w-6" />,
    href: '/crm/companies',
    color: 'bg-blue-100 text-blue-600',
    permissionModule: 'contacts',
  },
  {
    titleKey: 'hub.cards.contacts.title',
    descriptionKey: 'hub.cards.contacts.description',
    icon: <UserCircle className="h-6 w-6" />,
    href: '/crm/contacts',
    color: 'bg-green-100 text-green-600',
    permissionModule: 'contacts',
  },
]

export function MarketingHubPage() {
  const { t } = useTranslation(['marketing'])
  usePageTitle('hub.title', 'marketing')
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
          <Megaphone className="h-6 w-6 text-gray-400" />
          {t('hub.title')}
        </h1>
        <p className="text-gray-500">{t('hub.description')}</p>
      </div>

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
