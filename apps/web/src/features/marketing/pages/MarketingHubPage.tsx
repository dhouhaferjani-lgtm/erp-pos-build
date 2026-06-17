import { useTranslation } from 'react-i18next'
import { usePageTitle } from '../../../hooks/usePageTitle'
import { usePermissions } from '../../../hooks/usePermissions'
import {
  Percent,
  Ticket,
  Award,
  Users,
  Building2,
  UserCircle,
  type LucideIcon,
} from 'lucide-react'
import { PageHeader } from '../../../components/molecules/PageHeader'
import { HubCard, HubGrid } from '../../../components/molecules/HubCard'

interface HubCardDef {
  titleKey: string
  descriptionKey: string
  icon: LucideIcon
  href: string
  permissionModule?: string
}

const cards: HubCardDef[] = [
  {
    titleKey: 'hub.cards.promotions.title',
    descriptionKey: 'hub.cards.promotions.description',
    icon: Percent,
    href: '/pos/promotions',
    permissionModule: 'promotions',
  },
  {
    titleKey: 'hub.cards.coupons.title',
    descriptionKey: 'hub.cards.coupons.description',
    icon: Ticket,
    href: '/pos/coupons',
    permissionModule: 'coupons',
  },
  {
    titleKey: 'hub.cards.loyaltyPrograms.title',
    descriptionKey: 'hub.cards.loyaltyPrograms.description',
    icon: Award,
    href: '/pos/loyalty/programs',
    permissionModule: 'loyalty',
  },
  {
    titleKey: 'hub.cards.loyaltyMembers.title',
    descriptionKey: 'hub.cards.loyaltyMembers.description',
    icon: Users,
    href: '/pos/loyalty/members',
    permissionModule: 'loyalty',
  },
  {
    titleKey: 'hub.cards.companies.title',
    descriptionKey: 'hub.cards.companies.description',
    icon: Building2,
    href: '/crm/companies',
    permissionModule: 'contacts',
  },
  {
    titleKey: 'hub.cards.contacts.title',
    descriptionKey: 'hub.cards.contacts.description',
    icon: UserCircle,
    href: '/crm/contacts',
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
      <PageHeader title={t('hub.title')} subtitle={t('hub.description')} className="mb-0" />

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
