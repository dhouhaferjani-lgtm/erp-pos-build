import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { usePageTitle } from '../../../hooks/usePageTitle'
import { usePermissions } from '../../../hooks/usePermissions'
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
  ChevronRight,
} from 'lucide-react'

interface HubCard {
  titleKey: string
  descriptionKey: string
  icon: React.ReactNode
  href: string
  color: string
  permissionModule?: string
  requiredModule?: string
}

const baseCards: HubCard[] = [
  {
    titleKey: 'hub.cards.products.title',
    descriptionKey: 'hub.cards.products.description',
    icon: <Package className="h-6 w-6" />,
    href: '/inventory/products',
    color: 'bg-blue-100 text-blue-600',
    permissionModule: 'inventory',
  },
  {
    titleKey: 'hub.cards.categories.title',
    descriptionKey: 'hub.cards.categories.description',
    icon: <FolderTree className="h-6 w-6" />,
    href: '/inventory/categories',
    color: 'bg-purple-100 text-purple-600',
    permissionModule: 'inventory',
  },
  {
    titleKey: 'hub.cards.stockLevels.title',
    descriptionKey: 'hub.cards.stockLevels.description',
    icon: <BarChart3 className="h-6 w-6" />,
    href: '/inventory/stock',
    color: 'bg-green-100 text-green-600',
    permissionModule: 'inventory',
  },
  {
    titleKey: 'hub.cards.stockMovements.title',
    descriptionKey: 'hub.cards.stockMovements.description',
    icon: <ArrowRightLeft className="h-6 w-6" />,
    href: '/inventory/movements',
    color: 'bg-amber-100 text-amber-600',
    permissionModule: 'inventory',
  },
  {
    titleKey: 'hub.cards.inventoryCounting.title',
    descriptionKey: 'hub.cards.inventoryCounting.description',
    icon: <ClipboardCheck className="h-6 w-6" />,
    href: '/inventory/counting',
    color: 'bg-teal-100 text-teal-600',
    permissionModule: 'inventory',
  },
  {
    titleKey: 'hub.cards.priceLists.title',
    descriptionKey: 'hub.cards.priceLists.description',
    icon: <Tags className="h-6 w-6" />,
    href: '/pricing/price-lists',
    color: 'bg-pink-100 text-pink-600',
    permissionModule: 'pricing',
  },
]

const moduleGatedCards: HubCard[] = [
  {
    titleKey: 'hub.cards.batches.title',
    descriptionKey: 'hub.cards.batches.description',
    icon: <Boxes className="h-6 w-6" />,
    href: '/inventory/batches',
    color: 'bg-orange-100 text-orange-600',
    permissionModule: 'inventory',
    requiredModule: 'Parapharmacy',
  },
  {
    titleKey: 'hub.cards.compositeItems.title',
    descriptionKey: 'hub.cards.compositeItems.description',
    icon: <Layers className="h-6 w-6" />,
    href: '/catalog/composite-items',
    color: 'bg-indigo-100 text-indigo-600',
    permissionModule: 'composite-items',
    requiredModule: 'CompositeItems',
  },
  {
    titleKey: 'hub.cards.modifierGroups.title',
    descriptionKey: 'hub.cards.modifierGroups.description',
    icon: <SlidersHorizontal className="h-6 w-6" />,
    href: '/catalog/modifier-groups',
    color: 'bg-cyan-100 text-cyan-600',
    permissionModule: 'modifier-groups',
    requiredModule: 'CompositeItems',
  },
  {
    titleKey: 'hub.cards.menus.title',
    descriptionKey: 'hub.cards.menus.description',
    icon: <BookOpen className="h-6 w-6" />,
    href: '/catalog/menus',
    color: 'bg-emerald-100 text-emerald-600',
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
      <div>
        <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
          <Package className="h-6 w-6 text-gray-400" />
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
