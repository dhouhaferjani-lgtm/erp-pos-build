import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Plus, Edit, Trash2 } from 'lucide-react'
import { Button } from '@/components/atoms/Button/Button'
import { Badge } from '@/components/atoms/Badge/Badge'
import { Spinner } from '@/components/atoms/Spinner/Spinner'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { OffsetPagination } from '@/components/ui/OffsetPagination'
import { EmptyState } from '@/components/molecules/EmptyState/EmptyState'
import { fetchIngredients, deleteIngredient } from '../api/ingredientApi'
import { toast } from 'sonner'

export function IngredientListPage() {
  const { t } = useTranslation(['common', 'parapharmacy'])
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const [deleteId, setDeleteId] = useState<string | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['parapharmacy', 'ingredients', page],
    queryFn: () => fetchIngredients({ page, per_page: 25 }),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteIngredient,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['parapharmacy', 'ingredients'] })
      toast.success(t('parapharmacy:ingredientDeleted'))
      setDeleteId(null)
    },
    onError: (error: any) => {
      const message =
        error?.response?.data?.error?.message ||
        t('parapharmacy:deleteIngredientError')
      toast.error(message)
      setDeleteId(null)
    },
  })

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <Spinner size="lg" />
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">{t('parapharmacy:ingredients')}</h1>
          <p className="text-gray-600">
            {t('parapharmacy:ingredientsDescription')}
          </p>
        </div>
        <Button onClick={() => navigate('/parapharmacy/ingredients/new')}>
          <Plus className="h-4 w-4 mr-2" />
          {t('parapharmacy:addIngredient')}
        </Button>
      </div>

      <div className="rounded-lg border border-gray-200 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('parapharmacy:name')}
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('parapharmacy:slug')}
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('parapharmacy:casNumber')}
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('parapharmacy:allergen')}
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('parapharmacy:regulatoryStatus')}
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('common:actions')}
                </th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {data?.data?.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-6 py-12">
                    <EmptyState
                      title={t('parapharmacy:noIngredients')}
                      description={t('parapharmacy:noIngredientsDescription')}
                    />
                    <div className="flex justify-center mt-4">
                      <Button onClick={() => navigate('/parapharmacy/ingredients/new')}>
                        <Plus className="h-4 w-4 mr-2" />
                        {t('parapharmacy:addIngredient')}
                      </Button>
                    </div>
                  </td>
                </tr>
              ) : (
                data?.data?.map((ingredient) => (
                  <tr key={ingredient.id} className="hover:bg-gray-50">
                    <td className="px-6 py-4 text-sm font-medium text-gray-900">
                      {ingredient.name}
                    </td>
                    <td className="px-6 py-4 text-sm text-gray-500">
                      <code className="text-xs bg-gray-100 px-2 py-1 rounded">
                        {ingredient.slug}
                      </code>
                    </td>
                    <td className="px-6 py-4 text-sm text-gray-500">
                      {ingredient.cas_number || '—'}
                    </td>
                    <td className="px-6 py-4 text-sm">
                      {ingredient.is_allergen ? (
                        <Badge variant="danger">
                          {t('parapharmacy:allergen')}
                        </Badge>
                      ) : (
                        <span className="text-gray-400">—</span>
                      )}
                    </td>
                    <td className="px-6 py-4 text-sm">
                      {ingredient.regulatory_status ? (
                        <Badge
                          variant={
                            ingredient.regulatory_status === 'approved'
                              ? 'success'
                              : ingredient.regulatory_status === 'restricted'
                              ? 'warning'
                              : 'danger'
                          }
                        >
                          {t(
                            `parapharmacy:regulatoryStatus.${ingredient.regulatory_status}`
                          )}
                        </Badge>
                      ) : (
                        '—'
                      )}
                    </td>
                    <td className="px-6 py-4 text-sm text-right">
                      <div className="flex items-center justify-end gap-2">
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() =>
                            navigate(`/parapharmacy/ingredients/${ingredient.id}`)
                          }
                          aria-label={t('common:edit')}
                        >
                          <Edit className="h-4 w-4" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setDeleteId(ingredient.id)}
                          aria-label={t('common:delete')}
                        >
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {data?.meta && (
        <OffsetPagination
          currentPage={page}
          lastPage={Math.ceil(data.meta.total / data.meta.per_page)}
          total={data.meta.total}
          perPage={data.meta.per_page}
          from={data.meta.from ?? null}
          to={data.meta.to ?? null}
          onPageChange={setPage}
          onPerPageChange={() => {}}
        />
      )}

      <ConfirmDialog
        isOpen={!!deleteId}
        onClose={() => setDeleteId(null)}
        onConfirm={() => deleteId && deleteMutation.mutate(deleteId)}
        title={t('parapharmacy:confirmDeleteIngredient')}
        message={t('parapharmacy:confirmDeleteIngredientDescription')}
        confirmText={t('common:delete')}
        variant="danger"
        isLoading={deleteMutation.isPending}
      />
    </div>
  )
}
