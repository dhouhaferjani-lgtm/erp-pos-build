import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import {
  ArrowLeft,
  Plus,
  Edit,
  Trash2,
  FolderTree,
  X,
  Check,
} from 'lucide-react'
import { api } from '../../lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { serviceCategoriesInvalidationPredicate } from './_invalidation'
import type { ServiceCategory, CategoriesResponse, CreateCategoryData } from './types'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

interface CategoryFormData {
  name: string
  description: string
  parent_id: string
  sort_order: number
  is_active: boolean
}

export function ServiceCategoryListPage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const [showModal, setShowModal] = useState(false)
  const [editingCategory, setEditingCategory] = useState<ServiceCategory | null>(null)

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<CategoryFormData>({
    defaultValues: {
      name: '',
      description: '',
      parent_id: '',
      sort_order: 0,
      is_active: true,
    },
  })

  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['service-categories']),
    queryFn: async () => {
      const response = await api.get<CategoriesResponse>('/services/categories')
      return response.data
    },
    enabled: !!tenantId && !!companyId,
  })

  const createMutation = useMutation({
    mutationFn: async (data: CreateCategoryData) => {
      const response = await api.post('/services/categories', data)
      return response.data
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: serviceCategoriesInvalidationPredicate(tenantId, companyId),
      })
      closeModal()
    },
  })

  const updateMutation = useMutation({
    mutationFn: async ({ id, data }: { id: string; data: CreateCategoryData }) => {
      const response = await api.put(`/services/categories/${id}`, data)
      return response.data
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: serviceCategoriesInvalidationPredicate(tenantId, companyId),
      })
      closeModal()
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async (id: string) => {
      await api.delete(`/services/categories/${id}`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: serviceCategoriesInvalidationPredicate(tenantId, companyId),
      })
    },
  })

  const categories = data?.data ?? []
  const rootCategories = categories.filter((c) => !c.parent_id)

  const openCreateModal = () => {
    setEditingCategory(null)
    reset({
      name: '',
      description: '',
      parent_id: '',
      sort_order: 0,
      is_active: true,
    })
    setShowModal(true)
  }

  const openEditModal = (category: ServiceCategory) => {
    setEditingCategory(category)
    reset({
      name: category.name,
      description: category.description ?? '',
      parent_id: category.parent_id ?? '',
      sort_order: category.sort_order,
      is_active: category.is_active,
    })
    setShowModal(true)
  }

  const closeModal = () => {
    setShowModal(false)
    setEditingCategory(null)
    reset()
  }

  const onSubmit = (data: CategoryFormData) => {
    const payload: CreateCategoryData = {
      name: data.name,
      description: data.description || null,
      parent_id: data.parent_id || null,
      sort_order: data.sort_order,
      is_active: data.is_active,
    }

    if (editingCategory) {
      updateMutation.mutate({ id: editingCategory.id, data: payload })
    } else {
      createMutation.mutate(payload)
    }
  }

  const handleDelete = (category: ServiceCategory) => {
    if (window.confirm(t('confirmation.delete'))) {
      deleteMutation.mutate(category.id)
    }
  }

  const renderCategory = (category: ServiceCategory, level = 0) => {
    const childCategories = categories.filter((c) => c.parent_id === category.id)

    return (
      <div key={category.id}>
        <div
          className={`flex items-center justify-between px-4 py-3 ${colorTokens.variants.hoverBgGray50} ${
            level > 0 ? `border-s-2 ${colorTokens.border.subtle}` : ''
          }`}
          style={{ paddingInlineStart: `${1 + level * 1.5}rem` }}
        >
          <div className="flex items-center gap-3">
            <FolderTree className={`h-5 w-5 ${colorTokens.text.disabled}`} />
            <div>
              <p className={`font-medium ${colorTokens.text.primary}`}>{category.name}</p>
              {category.description && (
                <p className={`text-sm ${colorTokens.text.subtle}`}>{category.description}</p>
              )}
            </div>
          </div>
          <div className="flex items-center gap-3">
            <span
              className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${
                category.is_active
                  ? `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStronger}`
                  : `${colorTokens.surface.muted} ${colorTokens.text.strong}`
              }`}
            >
              {category.is_active ? t('status.active') : t('status.inactive')}
            </span>
            <div className="flex items-center gap-1">
              <button
                onClick={() => { openEditModal(category); }}
                className={`rounded p-1 ${colorTokens.text.disabled} ${colorTokens.variants.hoverBgGray100} ${colorTokens.variants.hoverTextGray600}`}
                title={t('actions.edit')}
              >
                <Edit className="h-4 w-4" />
              </button>
              <button
                onClick={() => { handleDelete(category); }}
                disabled={deleteMutation.isPending}
                className={`rounded p-1 ${colorTokens.text.disabled} ${colorTokens.variants.hoverBgRed50} ${colorTokens.variants.hoverTextRed600} disabled:opacity-50`}
                title={t('actions.delete')}
              >
                <Trash2 className="h-4 w-4" />
              </button>
            </div>
          </div>
        </div>
        {childCategories.length > 0 && (
          <div className={`border-s ${colorTokens.border.hairline}`} style={{ marginInlineStart: `${1 + level * 1.5}rem` }}>
            {childCategories.map((child) => renderCategory(child, level + 1))}
          </div>
        )}
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Link
            to="/services"
            className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.variants.hoverTextGray900}`}
          >
            <ArrowLeft className="h-4 w-4" />
            {t('actions.back')}
          </Link>
          <div>
            <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>
              {t('services.categories', 'Service Categories')}
            </PageHeaderTitle>
            <p className={`${colorTokens.text.subtle}`}>
              {categories.length} {categories.length === 1 ? t('services.categoryCount.singular', 'category') : t('services.categoryCount.plural', 'categories')}
            </p>
          </div>
        </div>
        <button
          onClick={openCreateModal}
          className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium text-white ${colorTokens.intent.primary.bgStrongHover}`}
        >
          <Plus className="h-4 w-4" />
          {t('services.addCategory', 'Add Category')}
        </button>
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="flex items-center justify-center py-12">
          <div className={`${colorTokens.text.subtle}`}>{t('status.loading')}</div>
        </div>
      ) : error ? (
        <div className={`rounded-lg ${colorTokens.intent.danger.bgSubtle} p-4 ${colorTokens.intent.danger.textStrong}`}>
          {t('errors.loadingFailed', 'Error loading data. Please try again.')}
        </div>
      ) : categories.length === 0 ? (
        <div className={`rounded-lg border-2 border-dashed ${colorTokens.border.default} p-12 text-center`}>
          <FolderTree className={`mx-auto h-12 w-12 ${colorTokens.text.disabled}`} />
          <h3 className={`mt-2 text-sm font-semibold ${colorTokens.text.primary}`}>
            {t('services.noCategories', 'No categories')}
          </h3>
          <p className={`mt-1 text-sm ${colorTokens.text.subtle}`}>
            {t('services.noCategoriesDescription', 'Create categories to organize your services.')}
          </p>
          <div className="mt-6">
            <button
              onClick={openCreateModal}
              className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium text-white ${colorTokens.intent.primary.bgStrongHover}`}
            >
              <Plus className="h-4 w-4" />
              {t('services.addCategory', 'Add Category')}
            </button>
          </div>
        </div>
      ) : (
        <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white divide-y ${colorTokens.border.dividerSubtle}`}>
          {rootCategories.map((category) => renderCategory(category))}
        </div>
      )}

      {/* Create/Edit Modal */}
      {showModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="w-full max-w-md rounded-lg bg-white shadow-xl">
            <div className={`flex items-center justify-between border-b ${colorTokens.border.subtle} px-6 py-4`}>
              <h3 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
                {editingCategory
                  ? t('services.editCategory', 'Edit Category')
                  : t('services.createCategory', 'Create Category')}
              </h3>
              <button
                onClick={closeModal}
                className={`rounded p-1 ${colorTokens.text.disabled} ${colorTokens.variants.hoverBgGray100} ${colorTokens.variants.hoverTextGray600}`}
              >
                <X className="h-5 w-5" />
              </button>
            </div>
            <form onSubmit={(e) => void handleSubmit(onSubmit)(e)} className="p-6 space-y-4">
              {/* Name */}
              <div>
                <label htmlFor="name" className={`block text-sm font-medium ${colorTokens.text.secondary}`}>
                  {t('fields.name', 'Name')} *
                </label>
                <input
                  type="text"
                  id="name"
                  {...register('name', { required: t('validation.required', 'This field is required') })}
                  className={`mt-1 block w-full rounded-lg border ${colorTokens.border.default} px-3 py-2 shadow-sm ${colorTokens.variants.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorTokens.variants.focusRingBlue500}`}
                  placeholder={t('services.categoryNamePlaceholder', 'Maintenance')}
                />
                {errors.name && (
                  <p className={`mt-1 text-sm ${colorTokens.intent.danger.text}`}>{errors.name.message}</p>
                )}
              </div>

              {/* Parent Category */}
              <div>
                <label htmlFor="parent_id" className={`block text-sm font-medium ${colorTokens.text.secondary}`}>
                  {t('services.parentCategory', 'Parent Category')}
                </label>
                <select
                  id="parent_id"
                  {...register('parent_id')}
                  className={`mt-1 block w-full rounded-lg border ${colorTokens.border.default} px-3 py-2 shadow-sm ${colorTokens.variants.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorTokens.variants.focusRingBlue500}`}
                >
                  <option value="">{t('services.noParent', 'None (Root Category)')}</option>
                  {categories
                    .filter((c) => c.id !== editingCategory?.id)
                    .map((category) => (
                      <option key={category.id} value={category.id}>
                        {category.name}
                      </option>
                    ))}
                </select>
              </div>

              {/* Description */}
              <div>
                <label htmlFor="description" className={`block text-sm font-medium ${colorTokens.text.secondary}`}>
                  {t('fields.description', 'Description')}
                </label>
                <textarea
                  id="description"
                  {...register('description')}
                  rows={2}
                  className={`mt-1 block w-full rounded-lg border ${colorTokens.border.default} px-3 py-2 shadow-sm ${colorTokens.variants.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorTokens.variants.focusRingBlue500}`}
                />
              </div>

              {/* Sort Order */}
              <div>
                <label htmlFor="sort_order" className={`block text-sm font-medium ${colorTokens.text.secondary}`}>
                  {t('services.sortOrder', 'Sort Order')}
                </label>
                <input
                  type="number"
                  id="sort_order"
                  {...register('sort_order', { valueAsNumber: true })}
                  className={`mt-1 block w-full rounded-lg border ${colorTokens.border.default} px-3 py-2 shadow-sm ${colorTokens.variants.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorTokens.variants.focusRingBlue500}`}
                />
              </div>

              {/* Status */}
              <div className="flex items-center gap-2">
                <input
                  type="checkbox"
                  id="is_active"
                  {...register('is_active')}
                  className={`h-4 w-4 rounded ${colorTokens.border.default} ${colorTokens.intent.primary.text} ${colorTokens.variants.focusRingBlue500}`}
                />
                <label htmlFor="is_active" className={`text-sm ${colorTokens.text.secondary}`}>
                  {t('status.active')}
                </label>
              </div>

              {/* Actions */}
              <div className="flex items-center justify-end gap-3 pt-4">
                <button
                  type="button"
                  onClick={closeModal}
                  className={`rounded-lg border ${colorTokens.border.default} bg-white px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.variants.hoverBgGray50}`}
                >
                  {t('actions.cancel')}
                </button>
                <button
                  type="submit"
                  disabled={createMutation.isPending || updateMutation.isPending}
                  className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium text-white ${colorTokens.intent.primary.bgStrongHover} disabled:opacity-50`}
                >
                  <Check className="h-4 w-4" />
                  {editingCategory ? t('actions.save') : t('actions.create')}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
