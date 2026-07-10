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
          <PageHeaderTitle className={`text-3xl font-bold ${colorTokens.text.primary} dark:${colorTokens.text.inverseFaint}`}>
            {t('expenses:categories.title')}
          </PageHeaderTitle>
          <p className={`mt-1 text-sm ${colorTokens.text.subtle} dark:${colorTokens.text.disabled}`}>
            {t('expenses:categories.description')}
          </p>
        </div>
        <button
          onClick={() => { handleOpenForm(); }}
          className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium text-white hover:${colorTokens.intent.primary.bgStrongHover} transition-colors`}
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
              <div key={i} className={`h-16 rounded-lg ${colorTokens.surface.subdued} dark:${colorTokens.surface.inverseMuted}`} />
            ))}
          </div>
        ) : rootCategories.length === 0 ? (
          <div className={`rounded-lg border-2 border-dashed ${colorTokens.border.default} ${colorTokens.surface.page} p-12 text-center dark:${colorTokens.border.inverseStrong} dark:${colorTokens.surface.inverse}`}>
            <FolderTree className={`mx-auto h-12 w-12 ${colorTokens.text.disabled}`} />
            <h3 className={`mt-4 text-lg font-medium ${colorTokens.text.primary} dark:${colorTokens.text.inverseFaint}`}>
              {t('expenses:categories.noCategories')}
            </h3>
            <p className={`mt-2 text-sm ${colorTokens.text.subtle} dark:${colorTokens.text.disabled}`}>
              {t('expenses:categories.noCategoriesDescription')}
            </p>
          </div>
        ) : (
          rootCategories.map((category) => (
            <div key={category.id} className="space-y-2">
              {/* Parent Category */}
              <div className={`flex items-center justify-between rounded-lg border ${colorTokens.border.subtle} bg-white p-4 shadow-sm dark:${colorTokens.border.inverseStrong} dark:${colorTokens.surface.inverse}`}>
                <div className="flex-1">
                  <h3 className={`text-lg font-semibold ${colorTokens.text.primary} dark:${colorTokens.text.inverseFaint}`}>
                    {category.name}
                  </h3>
                  {category.description && (
                    <p className={`mt-1 text-sm ${colorTokens.text.subtle} dark:${colorTokens.text.disabled}`}>
                      {category.description}
                    </p>
                  )}
                </div>
                <div className="flex items-center gap-2">
                  {!category.is_active && (
                    <span className={`rounded-full ${colorTokens.surface.muted} px-2 py-1 text-xs font-medium ${colorTokens.text.muted} dark:${colorTokens.surface.inverseMuted} dark:${colorTokens.text.disabled}`}>
                      {t('common:inactive')}
                    </span>
                  )}
                  <button
                    onClick={() => { handleOpenForm(category); }}
                    className={`rounded-md p-2 ${colorTokens.text.disabled} hover:${colorTokens.surface.muted} hover:${colorTokens.text.muted} dark:hover:${colorTokens.surface.inverseMuted} dark:hover:${colorTokens.text.faint}`}
                  >
                    <Pencil className="h-4 w-4" />
                  </button>
                  <button
                    onClick={() => handleDelete(category.id)}
                    className={`rounded-md p-2 ${colorTokens.text.disabled} hover:${colorTokens.intent.danger.bgSubtle} hover:${colorTokens.intent.danger.text} dark:hover:${colorTokens.intent.danger.bgInverse}/20 dark:hover:${colorTokens.intent.danger.textFaint}`}
                  >
                    <Trash2 className="h-4 w-4" />
                  </button>
                </div>
              </div>

              {/* Child Categories */}
              {getChildren(category.id).map((child) => (
                <div
                  key={child.id}
                  className={`ms-8 flex items-center justify-between rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.page} p-3 dark:${colorTokens.border.inverseStrong} dark:${colorTokens.surface.inverseStrong}`}
                >
                  <div className="flex-1">
                    <h4 className={`font-medium ${colorTokens.text.primary} dark:${colorTokens.text.inverseFaint}`}>
                      ↳ {child.name}
                    </h4>
                    {child.description && (
                      <p className={`mt-1 text-sm ${colorTokens.text.subtle} dark:${colorTokens.text.disabled}`}>
                        {child.description}
                      </p>
                    )}
                  </div>
                  <div className="flex items-center gap-2">
                    {!child.is_active && (
                      <span className={`rounded-full ${colorTokens.surface.muted} px-2 py-1 text-xs font-medium ${colorTokens.text.muted} dark:${colorTokens.surface.inverseMuted} dark:${colorTokens.text.disabled}`}>
                        {t('common:inactive')}
                      </span>
                    )}
                    <button
                      onClick={() => { handleOpenForm(child); }}
                      className={`rounded-md p-2 ${colorTokens.text.disabled} hover:${colorTokens.surface.muted} hover:${colorTokens.text.muted} dark:hover:${colorTokens.surface.inverseMuted} dark:hover:${colorTokens.text.faint}`}
                    >
                      <Pencil className="h-4 w-4" />
                    </button>
                    <button
                      onClick={() => handleDelete(child.id)}
                      className={`rounded-md p-2 ${colorTokens.text.disabled} hover:${colorTokens.intent.danger.bgSubtle} hover:${colorTokens.intent.danger.text} dark:hover:${colorTokens.intent.danger.bgInverse}/20 dark:hover:${colorTokens.intent.danger.textFaint}`}
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
          <div className={`w-full max-w-md rounded-lg bg-white p-6 shadow-xl dark:${colorTokens.surface.inverse}`}>
            <h2 className={`mb-4 text-xl font-semibold ${colorTokens.text.primary} dark:${colorTokens.text.inverseFaint}`}>
              {editingCategory
                ? t('expenses:categories.editCategory')
                : t('expenses:categories.createCategory')}
            </h2>

            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className={`text-sm font-medium ${colorTokens.text.secondary} dark:${colorTokens.text.faint}`}>
                  {t('expenses:categories.form.name')} *
                </label>
                <input
                  type="text"
                  value={formData.name}
                  onChange={(e) => { setFormData({ ...formData, name: e.target.value }); }}
                  required
                  className={`mt-1 w-full rounded-md border ${colorTokens.border.default} bg-white px-3 py-2 text-sm shadow-sm focus:${colorTokens.intent.primary.borderFocus} focus:outline-none focus:ring-1 focus:${colorTokens.intent.primary.ring} dark:${colorTokens.border.inverse} dark:${colorTokens.surface.inverseMuted} dark:${colorTokens.text.inverseFaint}`}
                />
              </div>

              <div>
                <label className={`text-sm font-medium ${colorTokens.text.secondary} dark:${colorTokens.text.faint}`}>
                  {t('expenses:categories.form.description')}
                </label>
                <textarea
                  value={formData.description}
                  onChange={(e) => { setFormData({ ...formData, description: e.target.value }); }}
                  rows={3}
                  className={`mt-1 w-full rounded-md border ${colorTokens.border.default} bg-white px-3 py-2 text-sm shadow-sm focus:${colorTokens.intent.primary.borderFocus} focus:outline-none focus:ring-1 focus:${colorTokens.intent.primary.ring} dark:${colorTokens.border.inverse} dark:${colorTokens.surface.inverseMuted} dark:${colorTokens.text.inverseFaint}`}
                />
              </div>

              <div>
                <label className={`text-sm font-medium ${colorTokens.text.secondary} dark:${colorTokens.text.faint}`}>
                  {t('expenses:categories.form.parentCategory')}
                </label>
                <Select
                  value={formData.parent_id}
                  onChange={(e) => { setFormData({ ...formData, parent_id: e.target.value }); }}
                  className={`mt-1 w-full rounded-md border ${colorTokens.border.default} bg-white px-3 py-2 text-sm shadow-sm focus:${colorTokens.intent.primary.borderFocus} focus:outline-none focus:ring-1 focus:${colorTokens.intent.primary.ring} dark:${colorTokens.border.inverse} dark:${colorTokens.surface.inverseMuted} dark:${colorTokens.text.inverseFaint}`}
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
                  className={`h-4 w-4 rounded ${colorTokens.border.default} ${colorTokens.intent.primary.text} focus:${colorTokens.intent.primary.ring}`}
                />
                <label
                  htmlFor="is_active"
                  className={`ms-2 text-sm ${colorTokens.text.secondary} dark:${colorTokens.text.faint}`}
                >
                  {t('common:active')}
                </label>
              </div>

              <div className={`flex justify-end gap-3 border-t ${colorTokens.border.subtle} pt-4 dark:${colorTokens.border.inverseStrong}`}>
                <button
                  type="button"
                  onClick={handleCloseForm}
                  className={`rounded-md border ${colorTokens.border.default} bg-white px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} shadow-sm hover:${colorTokens.surface.page} dark:${colorTokens.border.inverse} dark:${colorTokens.surface.inverseMuted} dark:${colorTokens.text.faint} dark:hover:${colorTokens.surface.neutralStrong}`}
                >
                  {t('common:cancel')}
                </button>
                <button
                  type="submit"
                  disabled={createCategory.isPending || updateCategory.isPending}
                  className={`rounded-md ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium text-white shadow-sm hover:${colorTokens.intent.primary.bgStrongHover} focus:outline-none focus:ring-2 focus:${colorTokens.intent.primary.ring} focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 transition-colors`}
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
