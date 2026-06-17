import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Plus, Edit2, Trash2 } from 'lucide-react'
import { EmptyState } from '@/components/molecules/EmptyState/EmptyState'
import { CategoryTree } from '@/components/catalog/CategoryTree'
import { useCategoryTree, useCreateCategory, useUpdateCategory, useDeleteCategory } from '../api/queries'
import type { CategoryTreeNode, CreateCategoryData, UpdateCategoryData } from '../types'
import { Button } from '@/components/atoms/Button/Button'
import { Modal } from '@/components/organisms/Modal/Modal'
import { Input } from '@/components/atoms/Input/Input'
import { Select } from '@/components/atoms/Select/Select'
import { Textarea } from '@/components/atoms/Textarea/Textarea'
import { PageHeader } from '@/components/molecules/PageHeader'
import { tokens, textColors, borderColors, colors } from '@/lib/designTokens'

export function CategoryManagementPage() {
  const { t } = useTranslation()
  const { data: categoryTree, isLoading } = useCategoryTree()
  const createMutation = useCreateCategory()
  const updateMutation = useUpdateCategory()
  const deleteMutation = useDeleteCategory()

  const [selectedCategory, setSelectedCategory] = useState<CategoryTreeNode | null>(null)
  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false)
  const [isEditModalOpen, setIsEditModalOpen] = useState(false)
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false)

  const [formData, setFormData] = useState<CreateCategoryData>({
    name: '',
    description: '',
    parent_id: null,
  })

  const handleOpenCreate = () => {
    setFormData({ name: '', description: '', parent_id: null })
    setIsCreateModalOpen(true)
  }

  const handleOpenEdit = (category: CategoryTreeNode) => {
    setSelectedCategory(category)
    setFormData({
      name: category.name,
      description: category.description || '',
      parent_id: category.parent_id,
    })
    setIsEditModalOpen(true)
  }

  const handleOpenDelete = (category: CategoryTreeNode) => {
    setSelectedCategory(category)
    setIsDeleteDialogOpen(true)
  }

  const handleCreate = async () => {
    try {
      await createMutation.mutateAsync(formData)
      setIsCreateModalOpen(false)
      setFormData({ name: '', description: '', parent_id: null })
    } catch (error) {
      console.error('Create category error:', error)
      toast.error(t('common:errors.operationFailed'))
    }
  }

  const handleUpdate = async () => {
    if (!selectedCategory) return

    try {
      const updateData: UpdateCategoryData = {
        name: formData.name,
        description: formData.description,
        parent_id: formData.parent_id,
      }
      await updateMutation.mutateAsync({ id: selectedCategory.id, data: updateData })
      setIsEditModalOpen(false)
      setSelectedCategory(null)
    } catch (error) {
      console.error('Update category error:', error)
      toast.error(t('common:errors.operationFailed'))
    }
  }

  const handleDelete = async () => {
    if (!selectedCategory) return

    try {
      await deleteMutation.mutateAsync(selectedCategory.id)
      setIsDeleteDialogOpen(false)
      setSelectedCategory(null)
    } catch (error) {
      console.error('Delete category error:', error)
      toast.error(t('common:errors.operationFailed'))
    }
  }

  const flattenCategories = (categories: CategoryTreeNode[]): CategoryTreeNode[] => {
    return categories.flatMap((cat) => [cat, ...flattenCategories(cat.children || [])])
  }

  const allCategories = categoryTree ? flattenCategories(categoryTree) : []

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className={textColors.tertiary}>{t('common:loading')}</div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <PageHeader
        title={t('common:catalog.categories.title')}
        subtitle={t('common:catalog.categories.tree')}
        actions={
          <Button onClick={handleOpenCreate}>
            <Plus className="h-4 w-4 me-2" />
            {t('common:catalog.categories.create')}
          </Button>
        }
        className="mb-0"
      />

      {/* Category Tree */}
      <div className={tokens.card.base}>
        <div className="flex justify-between items-center mb-4">
          <h2 className={tokens.heading.section}>
            {t('common:catalog.categories.tree')}
          </h2>
        </div>

        <div className={`border ${borderColors.light} rounded-lg overflow-hidden`}>
          {categoryTree && categoryTree.length > 0 ? (
            <div className="max-h-[600px] overflow-y-auto">
              <CategoryTree
                categories={categoryTree}
                selectedId={selectedCategory?.id}
                onSelect={(cat) => { setSelectedCategory(cat); }}
              />
            </div>
          ) : (
            <div className="py-4">
              <EmptyState title={t('common:table.noData')} />
              <div className="flex justify-center mt-4">
                <Button onClick={handleOpenCreate}>
                  <Plus className="h-4 w-4 me-2" />
                  {t('common:catalog.categories.create')}
                </Button>
              </div>
            </div>
          )}
        </div>

        {/* Selected Category Actions */}
        {selectedCategory && (
          <div className={`mt-4 p-4 ${colors.neutral[50]} rounded-lg border ${borderColors.light}`}>
            <div className="flex items-center justify-between">
              <div>
                <h3 className={`font-semibold ${textColors.primary}`}>{selectedCategory.name}</h3>
                {selectedCategory.description && (
                  <p className={`text-sm ${textColors.tertiary} mt-1`}>{selectedCategory.description}</p>
                )}
                {selectedCategory.products_count !== null && (
                  <p className={`text-sm ${textColors.tertiary} mt-1`}>
                    {t('common:catalog.categories.productsCount', { count: selectedCategory.products_count })}
                  </p>
                )}
              </div>
              <div className="flex gap-2">
                <Button variant="secondary" size="sm" onClick={() => { handleOpenEdit(selectedCategory); }}>
                  <Edit2 className="h-4 w-4 me-2" />
                  {t('common:actions.edit')}
                </Button>
                <Button
                  variant="secondary"
                  size="sm"
                  onClick={() => { handleOpenDelete(selectedCategory); }}
                  className={`${textColors.error} ${textColors.hoverError} ${borderColors.hover}`}
                >
                  <Trash2 className="h-4 w-4 me-2" />
                  {t('common:actions.delete')}
                </Button>
              </div>
            </div>
          </div>
        )}
      </div>

      {/* Create Category Modal */}
      <Modal
        isOpen={isCreateModalOpen}
        onClose={() => { setIsCreateModalOpen(false); }}
        title={t('common:catalog.categories.create')}
      >
        <div className="space-y-4">
          <div>
            <label className={`${tokens.label.base} mb-1`}>
              {t('common:catalog.categories.name')}
            </label>
            <Input
              value={formData.name}
              onChange={(e) => { setFormData({ ...formData, name: e.target.value }); }}
              placeholder={t('common:catalog.categories.name')}
            />
          </div>

          <div>
            <label className={`${tokens.label.base} mb-1`}>
              {t('common:catalog.categories.parent')}
            </label>
            <Select
              value={formData.parent_id?.toString() || ''}
              onChange={(e) =>
                { setFormData({ ...formData, parent_id: e.target.value ? Number(e.target.value) : null }); }
              }
            >
              <option value="">{t('common:catalog.categories.rootCategory')}</option>
              {allCategories.map((cat) => (
                <option key={cat.id} value={cat.id}>
                  {'\u00A0'.repeat(cat.depth * 4)}
                  {cat.name}
                </option>
              ))}
            </Select>
          </div>

          <div>
            <label className={`${tokens.label.base} mb-1`}>
              {t('common:catalog.categories.description')}
            </label>
            <Textarea
              value={formData.description}
              onChange={(e) => { setFormData({ ...formData, description: e.target.value }); }}
              placeholder={t('common:catalog.categories.description')}
              rows={3}
            />
          </div>

          <div className="flex justify-end gap-2 mt-6">
            <Button variant="secondary" onClick={() => { setIsCreateModalOpen(false); }}>
              {t('common:actions.cancel')}
            </Button>
            <Button onClick={handleCreate} disabled={createMutation.isPending || !formData.name}>
              {createMutation.isPending ? t('common:status.creating') : t('common:actions.create')}
            </Button>
          </div>
        </div>
      </Modal>

      {/* Edit Category Modal */}
      <Modal
        isOpen={isEditModalOpen}
        onClose={() => { setIsEditModalOpen(false); }}
        title={t('common:catalog.categories.edit')}
      >
        <div className="space-y-4">
          <div>
            <label className={`${tokens.label.base} mb-1`}>
              {t('common:catalog.categories.name')}
            </label>
            <Input
              value={formData.name}
              onChange={(e) => { setFormData({ ...formData, name: e.target.value }); }}
              placeholder={t('common:catalog.categories.name')}
            />
          </div>

          <div>
            <label className={`${tokens.label.base} mb-1`}>
              {t('common:catalog.categories.parent')}
            </label>
            <Select
              value={formData.parent_id?.toString() || ''}
              onChange={(e) =>
                { setFormData({ ...formData, parent_id: e.target.value ? Number(e.target.value) : null }); }
              }
            >
              <option value="">{t('common:catalog.categories.rootCategory')}</option>
              {allCategories
                .filter((cat) => cat.id !== selectedCategory?.id) // Can't be own parent
                .map((cat) => (
                  <option key={cat.id} value={cat.id}>
                    {'\u00A0'.repeat(cat.depth * 4)}
                    {cat.name}
                  </option>
                ))}
            </Select>
          </div>

          <div>
            <label className={`${tokens.label.base} mb-1`}>
              {t('common:catalog.categories.description')}
            </label>
            <Textarea
              value={formData.description}
              onChange={(e) => { setFormData({ ...formData, description: e.target.value }); }}
              placeholder={t('common:catalog.categories.description')}
              rows={3}
            />
          </div>

          <div className="flex justify-end gap-2 mt-6">
            <Button variant="secondary" onClick={() => { setIsEditModalOpen(false); }}>
              {t('common:actions.cancel')}
            </Button>
            <Button onClick={handleUpdate} disabled={updateMutation.isPending || !formData.name}>
              {updateMutation.isPending ? t('common:status.saving') : t('common:actions.save')}
            </Button>
          </div>
        </div>
      </Modal>

      {/* Delete Confirmation Dialog */}
      <Modal
        isOpen={isDeleteDialogOpen}
        onClose={() => { setIsDeleteDialogOpen(false); }}
        title={t('common:actions.delete')}
      >
        <div className="space-y-4">
          <p className={`text-sm ${textColors.tertiary}`}>
            {t('common:confirmation.delete')}
          </p>
          {selectedCategory?.products_count && selectedCategory.products_count > 0 && (
            <div className={`${tokens.alert.base} ${tokens.alert.error}`}>
              <p className="text-sm">
                {t('common:catalog.categories.cannotDelete')}
              </p>
            </div>
          )}

          <div className="flex justify-end gap-2 mt-6">
            <Button variant="secondary" onClick={() => { setIsDeleteDialogOpen(false); }}>
              {t('common:actions.cancel')}
            </Button>
            <Button
              variant="danger"
              onClick={handleDelete}
              disabled={
                deleteMutation.isPending ||
                (selectedCategory?.products_count != null && selectedCategory.products_count > 0)
              }
            >
              {deleteMutation.isPending ? t('common:status.processing') : t('common:actions.delete')}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
