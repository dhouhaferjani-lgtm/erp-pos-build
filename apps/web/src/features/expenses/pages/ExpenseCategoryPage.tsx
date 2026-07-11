import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, Pencil, Trash2, FolderTree } from 'lucide-react'
import {
  useExpenseCategories,
  useCreateExpenseCategory,
  useUpdateExpenseCategory,
  useDeleteExpenseCategory,
} from '../hooks/useExpenseCategories'
import { useAccounts } from '../../finance/hooks/useAccounts'
import { tokens , semanticColorTokens as colorTokens } from '@/lib/designTokens'
import type { CreateExpenseCategoryDTO, ExpenseCategory } from '../types'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import { Select } from '@/components/atoms'

/**
 * Page: Expense categories
 *
 * Page for managing expense categories with hierarchy support.
 */
export function ExpenseCategoryPage() {
  const { t } = useTranslation(['expenses', 'common'])
  const [isFormOpen, setIsFormOpen] = useState(false)
  const [editingCategory, setEditingCategory] = useState<ExpenseCategory | null>(null)

  const { data: categories, isLoading } = useExpenseCategories()
  const createCategory = useCreateExpenseCategory()
  const updateCategory = useUpdateExpenseCategory()
  const deleteCategory = useDeleteExpenseCategory()
  const { data: accounts } = useAccounts({ type: 'expense' })

  const [formData, setFormData] = useState<CreateExpenseCategoryDTO>({
    name: '',
    description: '',
    parent_id: '',
    account_id: '',
    is_active: true,
  })

  const handleOpenForm = (category?: ExpenseCategory) => {
    if (category) {
      setEditingCategory(category)
      setFormData({
        name: category.name,
        description: category.description || '',
        parent_id: category.parent_id || '',
        account_id: category.account_id ?? '',
        is_active: category.is_active,
      })
    } else {
      setEditingCategory(null)
      setFormData({
        name: '',
        description: '',
        parent_id: '',
        account_id: '',
        is_active: true,
      })
    }
    setIsFormOpen(true)
  }

  const handleCloseForm = () => {
    setIsFormOpen(false)
    setEditingCategory(null)
    setFormData({
      name: '',
      description: '',
      parent_id: '',
      account_id: '',
      is_active: true,
    })
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    try {
      if (editingCategory) {
        await updateCategory.mutateAsync({
          id: editingCategory.id,
          data: formData,
        })
      } else {
        await createCategory.mutateAsync(formData)
      }
      handleCloseForm()
    } catch (error) {
      // Error handling is done in hooks
    }
  }

  const handleDelete = async (id: string) => {
    if (confirm(t('expenses:categories.confirmDelete'))) {
      deleteCategory.mutate(id)
    }
  }

  const rootCategories = categories?.filter((cat) => !cat.parent_id) || []
  const getChildren = (parentId: string) =>
    categories?.filter((cat) => cat.parent_id === parentId) || []

  return (
    <div className="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
      {/* Header */}
      <div className="mb-6 flex items-center justify-between">
        <div>
          <PageHeaderTitle className={`text-3xl font-bold ${colorTokens.text.primary} ${colorTokens.variants.darkTextGray100}`}>
            {t('expenses:categories.title')}
          </PageHeaderTitle>
          <p className={`mt-1 text-sm ${colorTokens.text.subtle} ${colorTokens.variants.darkTextGray400}`}>
            {t('expenses:categories.description')}
          </p>
        </div>
        <button
          onClick={() => { handleOpenForm(); }}
          className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium text-white ${colorTokens.intent.primary.bgStrongHover} transition-colors`}
        >
          <Plus className="h-4 w-4" />
          {t('expenses:categories.createCategory')}
        </button>
      </div>

      {/* Category List */}
      <div className="space-y-4">
        {isLoading ? (
          <div className="animate-pulse space-y-3">
            {Array.from({ length: 5 }).map((_, i) => (
              <div key={i} className={`h-16 rounded-lg ${colorTokens.surface.subdued} ${colorTokens.variants.darkBgGray700}`} />
            ))}
          </div>
        ) : rootCategories.length === 0 ? (
          <div className={`rounded-lg border-2 border-dashed ${colorTokens.border.default} ${colorTokens.surface.page} p-12 text-center ${colorTokens.variants.darkBorderGray700} ${colorTokens.variants.darkBgGray800}`}>
            <FolderTree className={`mx-auto h-12 w-12 ${colorTokens.text.disabled}`} />
            <h3 className={`mt-4 text-lg font-medium ${colorTokens.text.primary} ${colorTokens.variants.darkTextGray100}`}>
              {t('expenses:categories.noCategories')}
            </h3>
            <p className={`mt-2 text-sm ${colorTokens.text.subtle} ${colorTokens.variants.darkTextGray400}`}>
              {t('expenses:categories.noCategoriesDescription')}
            </p>
          </div>
        ) : (
          rootCategories.map((category) => (
            <div key={category.id} className="space-y-2">
              {/* Parent Category */}
              <div className={`flex items-center justify-between rounded-lg border ${colorTokens.border.subtle} bg-white p-4 shadow-sm ${colorTokens.variants.darkBorderGray700} ${colorTokens.variants.darkBgGray800}`}>
                <div className="flex-1">
                  <h3 className={`text-lg font-semibold ${colorTokens.text.primary} ${colorTokens.variants.darkTextGray100}`}>
                    {category.name}
                  </h3>
                  {category.description && (
                    <p className={`mt-1 text-sm ${colorTokens.text.subtle} ${colorTokens.variants.darkTextGray400}`}>
                      {category.description}
                    </p>
                  )}
                </div>
                <div className="flex items-center gap-2">
                  {!category.is_active && (
                    <span className={`rounded-full ${colorTokens.surface.muted} px-2 py-1 text-xs font-medium ${colorTokens.text.muted} ${colorTokens.variants.darkBgGray700} ${colorTokens.variants.darkTextGray400}`}>
                      {t('common:inactive')}
                    </span>
                  )}
                  <button
                    onClick={() => { handleOpenForm(category); }}
                    className={`rounded-md p-2 ${colorTokens.text.disabled} ${colorTokens.variants.hoverBgGray100} ${colorTokens.variants.hoverTextGray600} ${colorTokens.variants.darkHoverBgGray700} ${colorTokens.variants.darkHoverTextGray300}`}
                  >
                    <Pencil className="h-4 w-4" />
                  </button>
                  <button
                    onClick={() => handleDelete(category.id)}
                    className={`rounded-md p-2 ${colorTokens.text.disabled} ${colorTokens.variants.hoverBgRed50} ${colorTokens.variants.hoverTextRed600} ${colorTokens.variants.darkHoverBgRed900Alpha20} ${colorTokens.variants.darkHoverTextRed400}`}
                  >
                    <Trash2 className="h-4 w-4" />
                  </button>
                </div>
              </div>

              {/* Child Categories */}
              {getChildren(category.id).map((child) => (
                <div
                  key={child.id}
                  className={`ms-8 flex items-center justify-between rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.page} p-3 ${colorTokens.variants.darkBorderGray700} ${colorTokens.variants.darkBgGray900}`}
                >
                  <div className="flex-1">
                    <h4 className={`font-medium ${colorTokens.text.primary} ${colorTokens.variants.darkTextGray100}`}>
                      ↳ {child.name}
                    </h4>
                    {child.description && (
                      <p className={`mt-1 text-sm ${colorTokens.text.subtle} ${colorTokens.variants.darkTextGray400}`}>
                        {child.description}
                      </p>
                    )}
                  </div>
                  <div className="flex items-center gap-2">
                    {!child.is_active && (
                      <span className={`rounded-full ${colorTokens.surface.muted} px-2 py-1 text-xs font-medium ${colorTokens.text.muted} ${colorTokens.variants.darkBgGray700} ${colorTokens.variants.darkTextGray400}`}>
                        {t('common:inactive')}
                      </span>
                    )}
                    <button
                      onClick={() => { handleOpenForm(child); }}
                      className={`rounded-md p-2 ${colorTokens.text.disabled} ${colorTokens.variants.hoverBgGray100} ${colorTokens.variants.hoverTextGray600} ${colorTokens.variants.darkHoverBgGray700} ${colorTokens.variants.darkHoverTextGray300}`}
                    >
                      <Pencil className="h-4 w-4" />
                    </button>
                    <button
                      onClick={() => handleDelete(child.id)}
                      className={`rounded-md p-2 ${colorTokens.text.disabled} ${colorTokens.variants.hoverBgRed50} ${colorTokens.variants.hoverTextRed600} ${colorTokens.variants.darkHoverBgRed900Alpha20} ${colorTokens.variants.darkHoverTextRed400}`}
                    >
                      <Trash2 className="h-4 w-4" />
                    </button>
                  </div>
                </div>
              ))}
            </div>
          ))
        )}
      </div>

      {/* Form Modal */}
      {isFormOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className={`w-full max-w-md rounded-lg bg-white p-6 shadow-xl ${colorTokens.variants.darkBgGray800}`}>
            <h2 className={`mb-4 text-xl font-semibold ${colorTokens.text.primary} ${colorTokens.variants.darkTextGray100}`}>
              {editingCategory
                ? t('expenses:categories.editCategory')
                : t('expenses:categories.createCategory')}
            </h2>

            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className={`text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.variants.darkTextGray300}`}>
                  {t('expenses:categories.form.name')} *
                </label>
                <input
                  type="text"
                  value={formData.name}
                  onChange={(e) => { setFormData({ ...formData, name: e.target.value }); }}
                  required
                  className={`mt-1 w-full rounded-md border ${colorTokens.border.default} bg-white px-3 py-2 text-sm shadow-sm ${colorTokens.variants.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorTokens.variants.focusRingBlue500} ${colorTokens.variants.darkBorderGray600} ${colorTokens.variants.darkBgGray700} ${colorTokens.variants.darkTextGray100}`}
                />
              </div>

              <div>
                <label className={`text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.variants.darkTextGray300}`}>
                  {t('expenses:categories.form.description')}
                </label>
                <textarea
                  value={formData.description}
                  onChange={(e) => { setFormData({ ...formData, description: e.target.value }); }}
                  rows={3}
                  className={`mt-1 w-full rounded-md border ${colorTokens.border.default} bg-white px-3 py-2 text-sm shadow-sm ${colorTokens.variants.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorTokens.variants.focusRingBlue500} ${colorTokens.variants.darkBorderGray600} ${colorTokens.variants.darkBgGray700} ${colorTokens.variants.darkTextGray100}`}
                />
              </div>

              <div>
                <label className={`text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.variants.darkTextGray300}`}>
                  {t('expenses:categories.form.parentCategory')}
                </label>
                <Select
                  value={formData.parent_id}
                  onChange={(e) => { setFormData({ ...formData, parent_id: e.target.value }); }}
                  className={`mt-1 w-full rounded-md border ${colorTokens.border.default} bg-white px-3 py-2 text-sm shadow-sm ${colorTokens.variants.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorTokens.variants.focusRingBlue500} ${colorTokens.variants.darkBorderGray600} ${colorTokens.variants.darkBgGray700} ${colorTokens.variants.darkTextGray100}`}
                >
                  <option value="">{t('common:none')}</option>
                  {rootCategories
                    .filter((cat) => cat.id !== editingCategory?.id)
                    .map((cat) => (
                      <option key={cat.id} value={cat.id}>
                        {cat.name}
                      </option>
                    ))}
                </Select>
              </div>

              <div>
                <label
                  htmlFor="category-account"
                  className={tokens.label.base}
                >
                  {t('expenses:categories.form.glAccount')}
                </label>
                <Select
                  id="category-account"
                  value={formData.account_id}
                  onChange={(e) => { setFormData({ ...formData, account_id: e.target.value }); }}
                >
                  <option value="">{t('common:none')}</option>
                  {accounts?.map((account) => (
                    <option key={account.id} value={account.id}>
                      {account.code} – {account.name}
                    </option>
                  ))}
                </Select>
              </div>

              <div className="flex items-center">
                <input
                  type="checkbox"
                  id="is_active"
                  checked={formData.is_active}
                  onChange={(e) => { setFormData({ ...formData, is_active: e.target.checked }); }}
                  className={`h-4 w-4 rounded ${colorTokens.border.default} ${colorTokens.intent.primary.text} ${colorTokens.variants.focusRingBlue500}`}
                />
                <label
                  htmlFor="is_active"
                  className={`ms-2 text-sm ${colorTokens.text.secondary} ${colorTokens.variants.darkTextGray300}`}
                >
                  {t('common:active')}
                </label>
              </div>

              <div className={`flex justify-end gap-3 border-t ${colorTokens.border.subtle} pt-4 ${colorTokens.variants.darkBorderGray700}`}>
                <button
                  type="button"
                  onClick={handleCloseForm}
                  className={`rounded-md border ${colorTokens.border.default} bg-white px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} shadow-sm ${colorTokens.variants.hoverBgGray50} ${colorTokens.variants.darkBorderGray600} ${colorTokens.variants.darkBgGray700} ${colorTokens.variants.darkTextGray300} ${colorTokens.variants.darkHoverBgGray600}`}
                >
                  {t('common:cancel')}
                </button>
                <button
                  type="submit"
                  disabled={createCategory.isPending || updateCategory.isPending}
                  className={`rounded-md ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium text-white shadow-sm ${colorTokens.intent.primary.bgStrongHover} focus:outline-none focus:ring-2 ${colorTokens.variants.focusRingBlue500} focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 transition-colors`}
                >
                  {createCategory.isPending || updateCategory.isPending
                    ? t('common:saving')
                    : t('common:save')}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
