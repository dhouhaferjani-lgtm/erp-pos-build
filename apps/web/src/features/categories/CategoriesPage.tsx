import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import {
  useCategoryTree,
  useCreateCategory,
  useUpdateCategory,
  useDeleteCategory,
} from './hooks'
import { CategoryTreeView, CategoryForm } from './components'
import type { CategoryApiResponse, CreateCategoryInput, UpdateCategoryInput } from './api'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

type DialogMode = 'create' | 'edit' | 'create-child' | null

export function CategoriesPage() {
  const { t } = useTranslation(['inventory', 'common'])
  const [dialogMode, setDialogMode] = useState<DialogMode>(null)
  const [selectedCategory, setSelectedCategory] = useState<CategoryApiResponse | null>(null)
  const [parentCategory, setParentCategory] = useState<CategoryApiResponse | null>(null)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [categoryToDelete, setCategoryToDelete] = useState<CategoryApiResponse | null>(null)

  const { data: categoryTree = [], isLoading, error } = useCategoryTree()
  const createMutation = useCreateCategory()
  const updateMutation = useUpdateCategory()
  const deleteMutation = useDeleteCategory()

  const handleCreate = () => {
    setDialogMode('create')
    setSelectedCategory(null)
    setParentCategory(null)
  }

  const handleEdit = (category: CategoryApiResponse) => {
    setDialogMode('edit')
    setSelectedCategory(category)
    setParentCategory(null)
  }

  const handleAddSubcategory = (parent: CategoryApiResponse) => {
    setDialogMode('create-child')
    setSelectedCategory(null)
    setParentCategory(parent)
  }

  const handleDeleteClick = (category: CategoryApiResponse) => {
    setCategoryToDelete(category)
    setDeleteDialogOpen(true)
  }

  const handleDeleteConfirm = async () => {
    if (!categoryToDelete) return

    try {
      await deleteMutation.mutateAsync(categoryToDelete.id)
      toast.success(t('inventory:categories.messages.deleted'))
      setDeleteDialogOpen(false)
      setCategoryToDelete(null)
    } catch (error: unknown) {
      const errorMessage = error instanceof Error ? error.message : String(error)
      toast.error(t('inventory:categories.messages.deleteFailed', { error: errorMessage }))
    }
  }

  const handleFormSubmit = async (data: CreateCategoryInput | UpdateCategoryInput) => {
    try {
      if (dialogMode === 'edit' && selectedCategory) {
        await updateMutation.mutateAsync({ id: selectedCategory.id, data })
        toast.success(t('inventory:categories.messages.updated'))
      } else {
        // For create and create-child modes
        const createData: CreateCategoryInput = {
          ...(data as CreateCategoryInput),
          parentId: parentCategory?.id || (data as CreateCategoryInput).parentId,
        }
        await createMutation.mutateAsync(createData)
        toast.success(t('inventory:categories.messages.created'))
      }
      setDialogMode(null)
      setSelectedCategory(null)
      setParentCategory(null)
    } catch (error: unknown) {
      const errorMessage = error instanceof Error ? error.message : String(error)
      const messageKey = dialogMode === 'edit' ? 'updateFailed' : 'createFailed'
      toast.error(t(`inventory:categories.messages.${messageKey}`, { error: errorMessage }))
    }
  }

  const handleFormCancel = () => {
    setDialogMode(null)
    setSelectedCategory(null)
    setParentCategory(null)
  }

  const isFormSubmitting = createMutation.isPending || updateMutation.isPending

  // Determine dialog title
  const getDialogTitle = () => {
    if (dialogMode === 'edit') return t('inventory:categories.edit')
    if (dialogMode === 'create-child') {
      return `${t('inventory:categories.actions.addSubcategory')} - ${parentCategory?.name}`
    }
    return t('inventory:categories.new')
  }

  // Check if category can be deleted
  const canDelete = (category: CategoryApiResponse) => {
    const hasProducts = category.products_count && category.products_count > 0
    const hasChildren = category.children && category.children.length > 0
    return !hasProducts && !hasChildren
  }

  const getDeleteErrorMessage = (category: CategoryApiResponse) => {
    if (category.products_count && category.products_count > 0) {
      return t('inventory:categories.deleteDialog.hasProducts', { count: category.products_count })
    }
    if (category.children && category.children.length > 0) {
      return t('inventory:categories.deleteDialog.hasChildren')
    }
    return null
  }

  return (
    <div className="px-4 sm:px-6 lg:px-8 py-8">
      {/* Page Header */}
      <div className="sm:flex sm:items-center sm:justify-between">
        <div>
          <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>
            {t('inventory:categories.tree.title')}
          </PageHeaderTitle>
          <p className={`mt-2 text-sm ${colorTokens.text.secondary}`}>
            {t('inventory:categories.tree.description')}
          </p>
        </div>
        <div className="mt-4 sm:mt-0">
          <button
            onClick={handleCreate}
            className={`inline-flex items-center gap-2 px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white ${colorTokens.intent.primary.bgStrong} ${colorTokens.intent.primary.bgStrongHover} focus:outline-none focus:ring-2 focus:ring-offset-2 ${colorTokens.variants.focusRingBlue500}`}
          >
            <Plus className="w-4 h-4" />
            {t('inventory:categories.new')}
          </button>
        </div>
      </div>

      {/* Content */}
      <div className="mt-8">
        {isLoading ? (
          <div className="flex items-center justify-center py-12">
            <Loader2 className={`w-8 h-8 animate-spin ${colorTokens.text.disabled}`} />
            <span className={`ms-3 text-sm ${colorTokens.text.subtle}`}>
              {t('inventory:categories.tree.loading')}
            </span>
          </div>
        ) : error ? (
          <div className="text-center py-12">
            <p className={`text-sm ${colorTokens.intent.danger.text}`}>{t('common:error')}: {error.message}</p>
          </div>
        ) : categoryTree.length === 0 ? (
          <div className="text-center py-12">
            <h3 className={`mt-2 text-sm font-medium ${colorTokens.text.primary}`}>
              {t('inventory:categories.empty.title')}
            </h3>
            <p className={`mt-1 text-sm ${colorTokens.text.subtle}`}>
              {t('inventory:categories.empty.description')}
            </p>
            <div className="mt-6">
              <button
                onClick={handleCreate}
                className={`inline-flex items-center gap-2 px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white ${colorTokens.intent.primary.bgStrong} ${colorTokens.intent.primary.bgStrongHover}`}
              >
                <Plus className="w-4 h-4" />
                {t('inventory:categories.empty.action')}
              </button>
            </div>
          </div>
        ) : (
          <div className="bg-white shadow rounded-lg p-6">
            <CategoryTreeView
              categories={categoryTree}
              onEdit={handleEdit}
              onDelete={handleDeleteClick}
              onAddSubcategory={handleAddSubcategory}
            />
          </div>
        )}
      </div>

      {/* Create/Edit Dialog */}
      {dialogMode && (
        <div className="fixed inset-0 z-10 overflow-y-auto">
          <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
            <div className={`fixed inset-0 ${colorTokens.variants.bgGray500Alpha75} transition-opacity`} onClick={handleFormCancel} />
            <div className="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-start shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
              <div className="mb-4">
                <h3 className={`text-lg font-medium leading-6 ${colorTokens.text.primary}`}>
                  {getDialogTitle()}
                </h3>
              </div>
              <CategoryForm
                category={selectedCategory || undefined}
                onSubmit={handleFormSubmit}
                onCancel={handleFormCancel}
                isSubmitting={isFormSubmitting}
              />
            </div>
          </div>
        </div>
      )}

      {/* Delete Confirmation Dialog */}
      <ConfirmDialog
        isOpen={deleteDialogOpen}
        onClose={() => { setDeleteDialogOpen(false); }}
        onConfirm={handleDeleteConfirm}
        title={t('inventory:categories.deleteDialog.title')}
        message={
          categoryToDelete && !canDelete(categoryToDelete)
            ? getDeleteErrorMessage(categoryToDelete) || ''
            : t('inventory:categories.deleteDialog.description', { name: categoryToDelete?.name })
        }
        confirmText={t('inventory:categories.deleteDialog.confirm')}
        variant={categoryToDelete && canDelete(categoryToDelete) ? 'danger' : 'warning'}
      />
    </div>
  )
}
