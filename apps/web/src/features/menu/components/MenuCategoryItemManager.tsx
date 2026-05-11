import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, Trash2, ChevronDown, ChevronRight, Package, Coffee } from 'lucide-react'
import { toast } from 'sonner'
import { useQuery } from '@tanstack/react-query'
import { Input, Button } from '@/components/atoms'
import { api } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { useAddMenuCategoryItem, useRemoveMenuCategoryItem } from '../hooks/useMenus'
import type { MenuCategoryData, MenuItemData } from '../types/menu'

interface MenuCategoryItemManagerProps {
  category: MenuCategoryData
}

interface SellableOption {
  id: string
  name: string
  code?: string
  sku?: string
  base_price?: string
  sale_price?: string | null
}

type SearchTab = 'composite_item' | 'product'

export function MenuCategoryItemManager({ category }: MenuCategoryItemManagerProps) {
  const { t } = useTranslation(['menu', 'common', 'catalog'])
  const [isExpanded, setIsExpanded] = useState(false)
  const [showAddForm, setShowAddForm] = useState(false)
  const [searchQuery, setSearchQuery] = useState('')
  const [overridePrice, setOverridePrice] = useState('')
  const [searchTab, setSearchTab] = useState<SearchTab>('composite_item')
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  const addItemMutation = useAddMenuCategoryItem()
  const removeItemMutation = useRemoveMenuCategoryItem()

  const endpoint = searchTab === 'composite_item' ? '/composite-items' : '/products'

  const { data: searchResults, isLoading: isSearching } = useQuery({
    queryKey: tenantScopedKey([searchTab + '-search', searchQuery]),
    queryFn: async () => {
      const params = new URLSearchParams({ per_page: '10' })
      if (searchQuery) params.append('search', searchQuery)
      const response = await api.get<{ data: SellableOption[] }>(`${endpoint}?${params}`)
      return response.data.data
    },
    enabled: showAddForm && !!tenantId && !!companyId,
    staleTime: 30000,
  })

  const items = category.items ?? []
  const existingItemIds = new Set(items.map((item) => item.sellable_id))
  const availableItems = (searchResults ?? []).filter((ci) => !existingItemIds.has(ci.id))

  const handleAddItem = (sellableId: string) => {
    addItemMutation.mutate(
      {
        categoryId: category.id,
        data: {
          sellable_type: searchTab,
          sellable_id: sellableId,
          override_price: overridePrice ? Number(overridePrice) : null,
        },
      },
      {
        onSuccess: () => {
          toast.success(t('common:saved'))
          setOverridePrice('')
        },
      }
    )
  }

  const handleRemoveItem = (itemId: string) => {
    if (!window.confirm(t('menu:confirmRemoveItem'))) return
    removeItemMutation.mutate(
      { categoryId: category.id, itemId },
      { onSuccess: () => toast.success(t('common:deleted')) }
    )
  }

  return (
    <div className="rounded-lg border border-gray-100 bg-gray-50">
      {/* Category header - click to expand */}
      <button
        type="button"
        onClick={() => { setIsExpanded(!isExpanded); }}
        className="flex w-full items-center justify-between p-3"
      >
        <div className="flex items-center gap-3">
          {isExpanded ? (
            <ChevronDown className="h-4 w-4 text-gray-500" />
          ) : (
            <ChevronRight className="h-4 w-4 text-gray-500" />
          )}
          <span className="text-sm font-medium text-gray-900">{category.name}</span>
          <span className="text-xs text-gray-500">
            ({items.length} {t('menu:items')})
          </span>
        </div>
      </button>

      {/* Expanded content */}
      {isExpanded && (
        <div className="border-t border-gray-200 p-3 space-y-3">
          {/* Items list */}
          {items.length === 0 ? (
            <p className="text-sm text-gray-500 text-center py-2">{t('menu:noItems')}</p>
          ) : (
            <div className="space-y-2">
              {items.map((item: MenuItemData) => (
                <div
                  key={item.id}
                  className="flex items-center justify-between rounded-md border border-gray-200 bg-white px-3 py-2"
                >
                  <div className="flex items-center gap-3">
                    {item.sellable_type === 'composite_item' ? (
                      <Coffee className="h-4 w-4 text-amber-500" />
                    ) : (
                      <Package className="h-4 w-4 text-gray-400" />
                    )}
                    <div>
                      <span className="text-sm font-medium text-gray-900">{item.name}</span>
                      <span className="ml-2 text-xs text-gray-500">({item.code})</span>
                    </div>
                    <span className={`inline-flex items-center rounded-full px-1.5 py-0.5 text-xs ${
                      item.sellable_type === 'composite_item'
                        ? 'bg-amber-50 text-amber-700'
                        : 'bg-gray-100 text-gray-600'
                    }`}>
                      {item.sellable_type === 'composite_item' ? t('menu:prepared') : t('menu:retail')}
                    </span>
                  </div>
                  <div className="flex items-center gap-4">
                    <div className="text-sm text-gray-600">
                      {item.override_price ? (
                        <span>
                          <span className="line-through text-gray-400 mr-1">{item.base_price}</span>
                          <span className="font-medium">{item.override_price}</span>
                        </span>
                      ) : (
                        <span>{item.base_price}</span>
                      )}
                    </div>
                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                      item.is_available
                        ? 'bg-green-50 text-green-700'
                        : 'bg-red-50 text-red-700'
                    }`}>
                      {item.is_available ? t('common:active') : t('common:inactive')}
                    </span>
                    <button
                      type="button"
                      onClick={(e) => {
                        e.stopPropagation()
                        handleRemoveItem(item.id)
                      }}
                      className="text-gray-400 hover:text-red-500"
                      disabled={removeItemMutation.isPending}
                    >
                      <Trash2 className="h-4 w-4" />
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}

          {/* Add item form */}
          {showAddForm ? (
            <div className="rounded-md border border-blue-200 bg-blue-50 p-3 space-y-3">
              {/* Type toggle tabs */}
              <div className="flex gap-1 rounded-md bg-blue-100 p-0.5">
                <button
                  type="button"
                  onClick={() => { setSearchTab('composite_item'); setSearchQuery('') }}
                  className={`flex-1 rounded px-3 py-1.5 text-xs font-medium transition-colors ${
                    searchTab === 'composite_item'
                      ? 'bg-white text-gray-900 shadow-sm'
                      : 'text-gray-600 hover:text-gray-800'
                  }`}
                >
                  <Coffee className="mr-1 inline h-3 w-3" />
                  {t('menu:menuItems')}
                </button>
                <button
                  type="button"
                  onClick={() => { setSearchTab('product'); setSearchQuery('') }}
                  className={`flex-1 rounded px-3 py-1.5 text-xs font-medium transition-colors ${
                    searchTab === 'product'
                      ? 'bg-white text-gray-900 shadow-sm'
                      : 'text-gray-600 hover:text-gray-800'
                  }`}
                >
                  <Package className="mr-1 inline h-3 w-3" />
                  {t('menu:products')}
                </button>
              </div>

              <div className="flex items-center gap-2">
                <Input
                  value={searchQuery}
                  onChange={(e) => { setSearchQuery(e.target.value); }}
                  placeholder={t('menu:searchItems')}
                  className="flex-1"
                />
                <Input
                  type="number"
                  step="0.01"
                  min="0"
                  value={overridePrice}
                  onChange={(e) => { setOverridePrice(e.target.value); }}
                  placeholder={t('menu:overridePrice')}
                  className="w-32"
                />
              </div>

              {isSearching ? (
                <p className="text-sm text-gray-500 text-center">{t('common:loading')}</p>
              ) : availableItems.length > 0 ? (
                <div className="max-h-40 overflow-y-auto space-y-1">
                  {availableItems.map((ci) => (
                    <button
                      key={ci.id}
                      type="button"
                      onClick={() => { handleAddItem(ci.id); }}
                      disabled={addItemMutation.isPending}
                      className="flex w-full items-center justify-between rounded-md bg-white px-3 py-2 text-sm hover:bg-gray-50 disabled:opacity-50"
                    >
                      <span>
                        <span className="font-medium text-gray-900">{ci.name}</span>
                        <span className="ml-2 text-gray-500">({ci.code ?? ci.sku})</span>
                      </span>
                      <span className="text-gray-600">{ci.base_price ?? ci.sale_price}</span>
                    </button>
                  ))}
                </div>
              ) : searchQuery ? (
                <p className="text-sm text-gray-500 text-center">
                  {searchTab === 'composite_item' ? t('catalog:noCompositeItems') : t('common:noResults')}
                </p>
              ) : null}

              <div className="flex justify-end">
                <Button
                  type="button"
                  variant="secondary"
                  size="sm"
                  onClick={() => {
                    setShowAddForm(false)
                    setSearchQuery('')
                    setOverridePrice('')
                  }}
                >
                  {t('common:cancel')}
                </Button>
              </div>
            </div>
          ) : (
            <Button
              type="button"
              variant="secondary"
              size="sm"
              onClick={() => { setShowAddForm(true); }}
            >
              <Plus className="mr-1 h-4 w-4" />
              {t('menu:addItem')}
            </Button>
          )}
        </div>
      )}
    </div>
  )
}
