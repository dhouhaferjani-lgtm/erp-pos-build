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
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { fetchKeyComponents, deleteKeyComponent } from '../api/keyComponentApi'
import { parapharmacyListInvalidationPredicate } from './tenantScope'
import { toast } from 'sonner'

export function KeyComponentListPage() {
  const { t } = useTranslation(['common', 'parapharmacy'])
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const [page, setPage] = useState(1)
  const [deleteId, setDeleteId] = useState<string | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: tenantScopedKey(['parapharmacy', 'key-components', page]),
    queryFn: () => fetchKeyComponents({ page, per_page: 25 }),
    enabled: !!tenantId && !!companyId,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteKeyComponent,
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: parapharmacyListInvalidationPredicate(tenantId, companyId, 'key-components'),
      })
      toast.success(t('parapharmacy:keyComponentDeleted'))
      setDeleteId(null)
    },
    onError: (error: any) => {
      const message =
        error?.response?.data?.error?.message ||
        t('parapharmacy:deleteKeyComponentError')
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
          <h1 className="text-3xl font-bold">{t('parapharmacy:keyComponents')}</h1>
          <p className="text-gray-600">
            {t('parapharmacy:keyComponentsDescription')}
          </p>
        </div>
        <Button onClick={() => navigate('/parapharmacy/key-components/new')}>
          <Plus className="h-4 w-4 mr-2" />
          {t('parapharmacy:addKeyComponent')}
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
                  {t('parapharmacy:allergen')}
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('common:actions')}
                </th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {data?.data?.length === 0 ? (
                <tr>
                  <td colSpan={4} className="px-6 py-12">
                    <EmptyState
                      title={t('parapharmacy:noKeyComponents')}
                      description={t('parapharmacy:noKeyComponentsDescription')}
                    />
                    <div className="flex justify-center mt-4">
                      <Button onClick={() => navigate('/parapharmacy/key-components/new')}>
                        <Plus className="h-4 w-4 mr-2" />
                        {t('parapharmacy:addKeyComponent')}
                      </Button>
                    </div>
                  </td>
                </tr>
              ) : (
                data?.data?.map((keyComponent) => (
                  <tr key={keyComponent.id} className="hover:bg-gray-50">
                    <td className="px-6 py-4 text-sm font-medium text-gray-900">
                      {keyComponent.name}
                    </td>
                    <td className="px-6 py-4 text-sm text-gray-500">
                      <code className="text-xs bg-gray-100 px-2 py-1 rounded">
                        {keyComponent.slug}
                      </code>
                    </td>
                    <td className="px-6 py-4 text-sm">
                      {keyComponent.is_allergen ? (
                        <Badge variant="danger">
                          {t('parapharmacy:allergen')}
                        </Badge>
                      ) : (
                        <span className="text-gray-400">—</span>
                      )}
                    </td>
                    <td className="px-6 py-4 text-sm text-right">
                      <div className="flex items-center justify-end gap-2">
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() =>
                            navigate(`/parapharmacy/key-components/${keyComponent.id}`)
                          }
                          aria-label={t('common:edit')}
                        >
                          <Edit className="h-4 w-4" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => { setDeleteId(keyComponent.id); }}
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
        onClose={() => { setDeleteId(null); }}
        onConfirm={() => deleteId && deleteMutation.mutate(deleteId)}
        title={t('parapharmacy:confirmDeleteKeyComponent')}
        message={t('parapharmacy:confirmDeleteKeyComponentDescription')}
        confirmText={t('common:delete')}
        variant="danger"
        isLoading={deleteMutation.isPending}
      />
    </div>
  )
}
