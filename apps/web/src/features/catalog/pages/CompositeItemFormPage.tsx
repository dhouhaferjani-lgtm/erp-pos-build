import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import { toast } from 'sonner'
import { useCompositeItem, useCreateCompositeItem, useUpdateCompositeItem } from '../hooks/useCompositeItems'
import { useCreateRecipe } from '../hooks/useRecipes'
import { RecipeLineEditor } from '../components/RecipeLineEditor'
import { VariantEditor } from '../components/VariantEditor'
import { ModifierGroupAssigner } from '../components/ModifierGroupAssigner'
import type { VerticalType, ProductionType } from '../types/compositeItem'

const TABS = ['details', 'recipeTab', 'sizesTab', 'modifiersTab'] as const

export function CompositeItemFormPage() {
  const { t } = useTranslation(['catalog', 'common'])
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id && id !== 'new'

  const { data: item, isLoading } = useCompositeItem(isEdit ? id : '')
  const createMutation = useCreateCompositeItem()
  const updateMutation = useUpdateCompositeItem()
  const createRecipeMutation = useCreateRecipe()

  const [activeTab, setActiveTab] = useState<typeof TABS[number]>('details')
  const [form, setForm] = useState({
    code: '',
    name: '',
    category_id: '',
    vertical_type: 'generic' as VerticalType,
    base_price: '',
    production_type: 'made_to_order' as ProductionType,
    tax_rate: '',
    is_active: true,
    is_available: true,
    image_url: '',
  })

  useEffect(() => {
    if (item) {
      setForm({
        code: item.code,
        name: item.name,
        category_id: item.category_id ?? '',
        vertical_type: item.vertical_type,
        base_price: item.base_price,
        production_type: item.production_type,
        tax_rate: item.tax_rate ?? '',
        is_active: item.is_active,
        is_available: item.is_available,
        image_url: item.image_url ?? '',
      })
    }
  }, [item])

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    const data = {
      code: form.code,
      name: form.name,
      category_id: form.category_id || null,
      vertical_type: form.vertical_type,
      base_price: Number(form.base_price),
      production_type: form.production_type,
      tax_rate: form.tax_rate ? Number(form.tax_rate) : null,
      is_active: form.is_active,
      is_available: form.is_available,
      image_url: form.image_url || null,
    }

    if (isEdit) {
      updateMutation.mutate(
        { id, data },
        {
          onSuccess: () => {
            toast.success(t('common:saved'))
          },
          onError: () => toast.error(t('common:error')),
        }
      )
    } else {
      createMutation.mutate(data, {
        onSuccess: (created) => {
          toast.success(t('common:saved'))
          navigate(`/catalog/composite-items/${created.id}/edit`)
        },
        onError: () => toast.error(t('common:error')),
      })
    }
  }

  const handleCreateRecipe = () => {
    if (!isEdit || !id) return
    createRecipeMutation.mutate(
      { compositeItemId: id, data: {} },
      {
        onSuccess: () => {
          toast.success(t('common:created'))
        },
      }
    )
  }

  if (isEdit && isLoading) {
    return <div className="text-center py-8 text-gray-500">{t('common:loading')}</div>
  }

  return (
    <div className="space-y-6">
      <div className="sm:flex sm:items-center sm:justify-between">
        <h1 className="text-2xl font-semibold text-gray-900">
          {isEdit ? t('catalog:editCompositeItem') : t('catalog:createCompositeItem')}
        </h1>
      </div>

      {/* Tabs */}
      {isEdit && (
        <div className="border-b border-gray-200">
          <nav className="-mb-px flex space-x-8">
            {TABS.map((tab) => (
              <button
                key={tab}
                onClick={() => setActiveTab(tab)}
                className={`whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium ${
                  activeTab === tab
                    ? 'border-indigo-500 text-indigo-600'
                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                }`}
              >
                {t(`catalog:${tab}`)}
              </button>
            ))}
          </nav>
        </div>
      )}

      {/* Details tab / Create form */}
      {(activeTab === 'details' || !isEdit) && (
        <form onSubmit={handleSubmit} className="space-y-6">
          <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
            <div>
              <label className="block text-sm font-medium text-gray-700">{t('catalog:code')}</label>
              <input
                type="text"
                required
                value={form.code}
                onChange={(e) => setForm({ ...form, code: e.target.value })}
                className="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">{t('catalog:name')}</label>
              <input
                type="text"
                required
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
                className="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">{t('catalog:basePrice')}</label>
              <input
                type="number"
                required
                step="0.01"
                min="0"
                value={form.base_price}
                onChange={(e) => setForm({ ...form, base_price: e.target.value })}
                className="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">{t('catalog:verticalType')}</label>
              <select
                value={form.vertical_type}
                onChange={(e) => setForm({ ...form, vertical_type: e.target.value as VerticalType })}
                className="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
              >
                <option value="fnb">F&B</option>
                <option value="manufacturing">Manufacturing</option>
                <option value="sewing">Sewing</option>
                <option value="bakery">Bakery</option>
                <option value="generic">Generic</option>
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">{t('catalog:productionType')}</label>
              <select
                value={form.production_type}
                onChange={(e) => setForm({ ...form, production_type: e.target.value as ProductionType })}
                className="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
              >
                <option value="made_to_order">{t('catalog:productionTypes.made_to_order')}</option>
                <option value="batch">{t('catalog:productionTypes.batch')}</option>
                <option value="stock">{t('catalog:productionTypes.stock')}</option>
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">{t('catalog:taxRate')}</label>
              <input
                type="number"
                step="0.01"
                min="0"
                max="100"
                value={form.tax_rate}
                onChange={(e) => setForm({ ...form, tax_rate: e.target.value })}
                className="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
              />
            </div>
            <div className="flex items-center gap-6">
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={form.is_active}
                  onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
                  className="rounded border-gray-300"
                />
                <span className="text-sm text-gray-700">{t('catalog:isActive')}</span>
              </label>
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={form.is_available}
                  onChange={(e) => setForm({ ...form, is_available: e.target.checked })}
                  className="rounded border-gray-300"
                />
                <span className="text-sm text-gray-700">{t('catalog:isAvailable')}</span>
              </label>
            </div>
          </div>

          <div className="flex justify-end gap-3">
            <button
              type="button"
              onClick={() => navigate('/catalog/composite-items')}
              className="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-900 hover:bg-gray-50"
            >
              {t('common:cancel')}
            </button>
            <button
              type="submit"
              disabled={createMutation.isPending || updateMutation.isPending}
              className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
            >
              {t('common:save')}
            </button>
          </div>
        </form>
      )}

      {/* Recipe tab */}
      {isEdit && activeTab === 'recipeTab' && (
        <div>
          {item?.active_recipe ? (
            <RecipeLineEditor
              recipe={item.active_recipe}
              compositeItemId={id}
            />
          ) : (
            <div className="text-center py-8">
              <p className="text-gray-500 mb-4">{t('catalog:createRecipe')}</p>
              <button
                onClick={handleCreateRecipe}
                disabled={createRecipeMutation.isPending}
                className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
              >
                {t('catalog:createRecipe')}
              </button>
            </div>
          )}
        </div>
      )}

      {/* Sizes/Variants tab */}
      {isEdit && activeTab === 'sizesTab' && (
        <VariantEditor
          compositeItemId={id}
          variants={item?.variants ?? []}
        />
      )}

      {/* Modifiers tab */}
      {isEdit && activeTab === 'modifiersTab' && (
        <ModifierGroupAssigner
          compositeItemId={id}
          assignedGroups={item?.modifier_groups ?? []}
        />
      )}
    </div>
  )
}
