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
          className={`flex items-center justify-between px-4 py-3 hover:bg-gray-50 ${
            level > 0 ? 'border-s-2 border-gray-200' : ''
          }`}
          style={{ paddingInlineStart: `${1 + level * 1.5}rem` }}
        >
          <div className="flex items-center gap-3">
            <FolderTree className="h-5 w-5 text-gray-400" />
            <div>
              <p className="font-medium text-gray-900">{category.name}</p>
              {category.description && (
                <p className="text-sm text-gray-500">{category.description}</p>
              )}
            </div>
          </div>
          <div className="flex items-center gap-3">
            <span
              className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${
                category.is_active
                  ? 'bg-green-100 text-green-800'
                  : 'bg-gray-100 text-gray-800'
              }`}
            >
              {category.is_active ? t('status.active') : t('status.inactive')}
            </span>
            <div className="flex items-center gap-1">
              <button
                onClick={() => { openEditModal(category); }}
                className="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                title={t('actions.edit')}
              >
                <Edit className="h-4 w-4" />
              </button>
              <button
                onClick={() => { handleDelete(category); }}
                disabled={deleteMutation.isPending}
                className="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-50"
                title={t('actions.delete')}
              >
                <Trash2 className="h-4 w-4" />
              </button>
            </div>
          </div>
        </div>
        {childCategories.length > 0 && (
          <div className="border-s border-gray-100" style={{ marginInlineStart: `${1 + level * 1.5}rem` }}>
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
            className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
          >
            <ArrowLeft className="h-4 w-4" />
            {t('actions.back')}
          </Link>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">
              {t('services.categories', 'Service Categories')}
            </h1>
            <p className="text-gray-500">
              {categories.length} {categories.length === 1 ? t('services.categoryCount.singular', 'category') : t('services.categoryCount.plural', 'categories')}
            </p>
          </div>
        </div>
        <button
          onClick={openCreateModal}
          className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
        >
          <Plus className="h-4 w-4" />
          {t('services.addCategory', 'Add Category')}
        </button>
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="flex items-center justify-center py-12">
          <div className="text-gray-500">{t('status.loading')}</div>
        </div>
      ) : error ? (
        <div className="rounded-lg bg-red-50 p-4 text-red-700">
          {t('errors.loadingFailed', 'Error loading data. Please try again.')}
        </div>
      ) : categories.length === 0 ? (
        <div className="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
          <FolderTree className="mx-auto h-12 w-12 text-gray-400" />
          <h3 className="mt-2 text-sm font-semibold text-gray-900">
            {t('services.noCategories', 'No categories')}
          </h3>
          <p className="mt-1 text-sm text-gray-500">
            {t('services.noCategoriesDescription', 'Create categories to organize your services.')}
          </p>
          <div className="mt-6">
            <button
              onClick={openCreateModal}
              className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
            >
              <Plus className="h-4 w-4" />
              {t('services.addCategory', 'Add Category')}
            </button>
          </div>
        </div>
      ) : (
        <div className="rounded-lg border border-gray-200 bg-white divide-y divide-gray-100">
          {rootCategories.map((category) => renderCategory(category))}
        </div>
      )}

      {/* Create/Edit Modal */}
      {showModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="w-full max-w-md rounded-lg bg-white shadow-xl">
            <div className="flex items-center justify-between border-b border-gray-200 px-6 py-4">
              <h3 className="text-lg font-semibold text-gray-900">
                {editingCategory
                  ? t('services.editCategory', 'Edit Category')
                  : t('services.createCategory', 'Create Category')}
              </h3>
              <button
                onClick={closeModal}
                className="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
              >
                <X className="h-5 w-5" />
              </button>
            </div>
            <form onSubmit={(e) => void handleSubmit(onSubmit)(e)} className="p-6 space-y-4">
              {/* Name */}
              <div>
                <label htmlFor="name" className="block text-sm font-medium text-gray-700">
                  {t('fields.name', 'Name')} *
                </label>
                <input
                  type="text"
                  id="name"
                  {...register('name', { required: t('validation.required', 'This field is required') })}
                  className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  placeholder={t('services.categoryNamePlaceholder', 'Maintenance')}
                />
                {errors.name && (
                  <p className="mt-1 text-sm text-red-600">{errors.name.message}</p>
                )}
              </div>

              {/* Parent Category */}
              <div>
                <label htmlFor="parent_id" className="block text-sm font-medium text-gray-700">
                  {t('services.parentCategory', 'Parent Category')}
                </label>
                <select
                  id="parent_id"
                  {...register('parent_id')}
                  className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
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
                <label htmlFor="description" className="block text-sm font-medium text-gray-700">
                  {t('fields.description', 'Description')}
                </label>
                <textarea
                  id="description"
                  {...register('description')}
                  rows={2}
                  className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
              </div>

              {/* Sort Order */}
              <div>
                <label htmlFor="sort_order" className="block text-sm font-medium text-gray-700">
                  {t('services.sortOrder', 'Sort Order')}
                </label>
                <input
                  type="number"
                  id="sort_order"
                  {...register('sort_order', { valueAsNumber: true })}
                  className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
              </div>

              {/* Status */}
              <div className="flex items-center gap-2">
                <input
                  type="checkbox"
                  id="is_active"
                  {...register('is_active')}
                  className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                />
                <label htmlFor="is_active" className="text-sm text-gray-700">
                  {t('status.active')}
                </label>
              </div>

              {/* Actions */}
              <div className="flex items-center justify-end gap-3 pt-4">
                <button
                  type="button"
                  onClick={closeModal}
                  className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                  {t('actions.cancel')}
                </button>
                <button
                  type="submit"
                  disabled={createMutation.isPending || updateMutation.isPending}
                  className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
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
