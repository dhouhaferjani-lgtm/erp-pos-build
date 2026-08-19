import { useTranslation } from 'react-i18next'
import { usePageTitle } from '../../../hooks/usePageTitle'
import { usePermissions, type ModuleKey } from '../../../hooks/usePermissions'
import { useCompanyConfig } from '../../../contexts/CompanyConfigContext'
import {
  Package,
  FolderTree,
  BarChart3,
  ArrowRightLeft,
  ClipboardCheck,
  Tags,
  Boxes,
  Layers,
  SlidersHorizontal,
  BookOpen,
  ClipboardList,
  MapPinned,
  type LucideIcon,
} from 'lucide-react'
import { PageHeader } from '../../../components/molecules/PageHeader'
import { HubCard, HubGrid } from '../../../components/molecules/HubCard'

interface HubCardDef {
  titleKey: string
  descriptionKey: string
  icon: LucideIcon
  href: string
  permissionModule?: ModuleKey
  requiredModule?: string
}

const baseCards: HubCardDef[] = [
  {
    titleKey: 'hub.cards.products.title',
    descriptionKey: 'hub.cards.products.description',
    icon: Package,
    href: '/inventory/products',
    permissionModule: 'inventory',
  },
  {
    titleKey: 'hub.cards.categories.title',
    descriptionKey: 'hub.cards.categories.description',
    icon: FolderTree,
    href: '/inventory/categories',
    permissionModule: 'inventory',
  },
  {
    titleKey: 'hub.cards.stockLevels.title',
    descriptionKey: 'hub.cards.stockLevels.description',
    icon: BarChart3,
    href: '/inventory/stock',
    permissionModule: 'inventory',
  },
  {
    titleKey: 'hub.cards.stockMovements.title',
    descriptionKey: 'hub.cards.stockMovements.description',
    icon: ArrowRightLeft,
    href: '/inventory/movements',
    permissionModule: 'inventory',
  },
  {
    titleKey: 'hub.cards.entryExitNotes.title',
    descriptionKey: 'hub.cards.entryExitNotes.description',
    icon: ClipboardList,
    href: '/inventory/entry-exit-notes',
    permissionModule: 'inventory',
  },
  {
    titleKey: 'hub.cards.inventoryCounting.title',
    descriptionKey: 'hub.cards.inventoryCounting.description',
    icon: ClipboardCheck,
    href: '/inventory/counting',
    permissionModule: 'inventory',
  },
  {
    titleKey: 'placement.title',
    descriptionKey: 'placement.subtitle',
    icon: MapPinned,
    href: '/inventory/placement',
    permissionModule: 'inventory',
  },
  {
    titleKey: 'hub.cards.priceLists.title',
    descriptionKey: 'hub.cards.priceLists.description',
    icon: Tags,
    href: '/pricing/price-lists',
    permissionModule: 'pricing',
  },
]

const moduleGatedCards: HubCardDef[] = [
  {
    titleKey: 'hub.cards.batches.title',
    descriptionKey: 'hub.cards.batches.description',
    icon: Boxes,
    href: '/inventory/batches',
    permissionModule: 'inventory',
    requiredModule: 'Parapharmacy',
  },
  {
    titleKey: 'hub.cards.compositeItems.title',
    descriptionKey: 'hub.cards.compositeItems.description',
    icon: Layers,
    href: '/catalog/composite-items',
    permissionModule: 'composite-items',
    requiredModule: 'CompositeItems',
  },
  {
    titleKey: 'hub.cards.modifierGroups.title',
    descriptionKey: 'hub.cards.modifierGroups.description',
    icon: SlidersHorizontal,
    href: '/catalog/modifier-groups',
    permissionModule: 'modifier-groups',
    requiredModule: 'CompositeItems',
  },
  {
    titleKey: 'hub.cards.menus.title',
    descriptionKey: 'hub.cards.menus.description',
    icon: BookOpen,
    href: '/catalog/menus',
    permissionModule: 'composite-items',
    requiredModule: 'CompositeItems',
  },
]

export function InventoryHubPage() {
  const { t } = useTranslation(['inventory'])
  usePageTitle('hub.title', 'inventory')
  const { canAccessModule } = usePermissions()
  const { hasModule } = useCompanyConfig()

  const allCards = [...baseCards, ...moduleGatedCards]

  const visibleCards = allCards.filter((card) => {
    if (card.permissionModule && !canAccessModule(card.permissionModule)) {
      return false
    }
    if (card.requiredModule && !hasModule(card.requiredModule)) {
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
