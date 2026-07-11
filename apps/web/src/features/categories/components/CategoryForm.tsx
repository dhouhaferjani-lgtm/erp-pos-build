import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { useForm } from 'react-hook-form'
import type { CategoryApiResponse, CreateCategoryInput, UpdateCategoryInput } from '../api'
import { useCategoryTree } from '../hooks'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface CategoryFormProps {
  category?: CategoryApiResponse | undefined
  onSubmit: (data: CreateCategoryInput | UpdateCategoryInput) => void
  onCancel: () => void
  isSubmitting?: boolean | undefined
}

interface FormData {
  name: string
  parentId: number | null
  description: string
  isActive: boolean
}

type FlatCategory = CategoryApiResponse & { depth: number }

export function CategoryForm({ category, onSubmit, onCancel, isSubmitting = false }: CategoryFormProps) {
  const { t } = useTranslation(['inventory'])
  const { data: categoryTree = [], isLoading: isLoadingTree } = useCategoryTree()

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<FormData>({
    defaultValues: {
      name: category?.name || '',
      parentId: category?.parent_id || null,
      description: category?.description || '',
      isActive: category?.is_active ?? true,
    },
  })

  // Flatten the category tree for the parent select dropdown
  const flatCategories = useMemo<FlatCategory[]>(() => {
    const flatten = (categories: CategoryApiResponse[], level = 0): FlatCategory[] => {
      return categories.flatMap(cat => [
        { ...cat, depth: level },
        ...(cat.children ? flatten(cat.children, level + 1) : []),
      ])
    }

    return flatten(categoryTree)
  }, [categoryTree])

  // Filter out current category and its descendants from parent options
  const availableParents = useMemo(() => {
    return flatCategories.filter(cat => {
      if (!category) return true
      if (cat.id === category.id) return false
      // Check if this category is a descendant of the current category
      return !cat.path.split('/').map(Number).includes(category.id)
    })
  }, [flatCategories, category])

  const handleFormSubmit = (data: FormData) => {
    onSubmit({
      name: data.name,
      parentId: data.parentId || null,
      description: data.description || undefined,
      isActive: data.isActive,
    })
  }

  return (
    <form onSubmit={handleSubmit(handleFormSubmit)} className="space-y-6">
      {/* Basic Information */}
      <div className="space-y-4">
        <h3 className={`text-sm font-medium ${colorTokens.text.primary}`}>
          {t('inventory:categories.form.basicInfo')}
        </h3>

        <div>
          <label htmlFor="name" className={`block text-sm font-medium ${colorTokens.text.secondary}`}>
            {t('inventory:categories.name')} <span className={`${colorTokens.intent.danger.textSubtle}`}>*</span>
          </label>
          <input
            {...register('name', {
              required: t('inventory:categories.form.nameRequired'),
            })}
            type="text"
            id="name"
            className={`mt-1 block w-full rounded-md ${colorTokens.border.default} shadow-sm ${colorTokens.variants.focusBorderBlue500} ${colorTokens.variants.focusRingBlue500} sm:text-sm`}
            placeholder={t('inventory:categories.namePlaceholder')}
          />
          {errors.name && (
            <p className={`mt-1 text-sm ${colorTokens.intent.danger.text}`}>{errors.name.message}</p>
          )}
        </div>

        <div>
          <label htmlFor="description" className={`block text-sm font-medium ${colorTokens.text.secondary}`}>
            {t('inventory:categories.description')}
          </label>
          <textarea
            {...register('description')}
            id="description"
            rows={3}
            className={`mt-1 block w-full rounded-md ${colorTokens.border.default} shadow-sm ${colorTokens.variants.focusBorderBlue500} ${colorTokens.variants.focusRingBlue500} sm:text-sm`}
            placeholder={t('inventory:categories.descriptionPlaceholder')}
          />
        </div>
      </div>

      {/* Hierarchy */}
      <div className="space-y-4">
        <h3 className={`text-sm font-medium ${colorTokens.text.primary}`}>
          {t('inventory:categories.form.hierarchy')}
        </h3>

        <div>
          <label htmlFor="parentId" className={`block text-sm font-medium ${colorTokens.text.secondary}`}>
            {t('inventory:categories.parent')}
          </label>
          <select
            {...register('parentId', {
              setValueAs: (v) => (v === '' || v === null ? null : Number(v)),
            })}
            id="parentId"
            className={`mt-1 block w-full rounded-md ${colorTokens.border.default} shadow-sm ${colorTokens.variants.focusBorderBlue500} ${colorTokens.variants.focusRingBlue500} sm:text-sm`}
            disabled={isLoadingTree}
          >
            <option value="">{t('inventory:categories.noParent')}</option>
            {availableParents.map(cat => (
              <option key={cat.id} value={cat.id}>
                {'  '.repeat(cat.depth)}
                {cat.name}
              </option>
            ))}
          </select>
        </div>
      </div>

      {/* Settings */}
      <div className="space-y-4">
        <h3 className={`text-sm font-medium ${colorTokens.text.primary}`}>
          {t('inventory:categories.form.settings')}
        </h3>

        <div className="flex items-center">
          <input
            {...register('isActive')}
            type="checkbox"
            id="isActive"
            className={`h-4 w-4 rounded ${colorTokens.border.default} ${colorTokens.intent.primary.text} ${colorTokens.variants.focusRingBlue500}`}
          />
          <label htmlFor="isActive" className={`ms-2 block text-sm ${colorTokens.text.primary}`}>
            {t('inventory:categories.isActive')}
          </label>
        </div>
      </div>

      {/* Form Actions */}
      <div className={`flex justify-end gap-3 pt-4 border-t ${colorTokens.border.subtle}`}>
        <button
          type="button"
          onClick={onCancel}
          disabled={isSubmitting}
          className={`px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} bg-white border ${colorTokens.border.default} rounded-md shadow-sm ${colorTokens.variants.hoverBgGray50} focus:outline-none focus:ring-2 focus:ring-offset-2 ${colorTokens.variants.focusRingBlue500} disabled:opacity-50 disabled:cursor-not-allowed`}
        >
          {t('inventory:categories.form.cancel')}
        </button>
        <button
          type="submit"
          disabled={isSubmitting}
          className={`px-4 py-2 text-sm font-medium text-white ${colorTokens.intent.primary.bgStrong} border border-transparent rounded-md shadow-sm ${colorTokens.intent.primary.bgStrongHover} focus:outline-none focus:ring-2 focus:ring-offset-2 ${colorTokens.variants.focusRingBlue500} disabled:opacity-50 disabled:cursor-not-allowed`}
        >
          {isSubmitting
            ? t('inventory:categories.form.saving')
            : t('inventory:categories.form.save')}
        </button>
      </div>
    </form>
  )
}
