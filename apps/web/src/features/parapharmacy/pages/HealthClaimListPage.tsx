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
import { fetchHealthClaims, deleteHealthClaim } from '../api/healthClaimApi'
import { toast } from 'sonner'

export function HealthClaimListPage() {
  const { t } = useTranslation(['common', 'parapharmacy'])
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const [deleteId, setDeleteId] = useState<string | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['parapharmacy', 'health-claims', page],
    queryFn: () => fetchHealthClaims({ page, per_page: 25 }),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteHealthClaim,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['parapharmacy', 'health-claims'] })
      toast.success(t('parapharmacy:healthClaimDeleted'))
      setDeleteId(null)
    },
    onError: (error: any) => {
      const message =
        error?.response?.data?.error?.message ||
        t('parapharmacy:deleteHealthClaimError')
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
          <h1 className="text-3xl font-bold">{t('parapharmacy:healthClaims')}</h1>
          <p className="text-gray-600">
            {t('parapharmacy:healthClaimsDescription')}
          </p>
        </div>
        <Button onClick={() => navigate('/parapharmacy/health-claims/new')}>
          <Plus className="h-4 w-4 mr-2" />
          {t('parapharmacy:addHealthClaim')}
        </Button>
      </div>

      <div className="rounded-lg border border-gray-200 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('parapharmacy:claim')}
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('parapharmacy:claimType')}
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('parapharmacy:regulatoryStatus')}
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('parapharmacy:disclaimer')}
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('common:actions')}
                </th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {data?.data?.length === 0 ? (
                <tr>
                  <td colSpan={5} className="px-6 py-12">
                    <EmptyState
                      title={t('parapharmacy:noHealthClaims')}
                      description={t('parapharmacy:noHealthClaimsDescription')}
                      action={{
                        label: t('parapharmacy:addHealthClaim'),
                        onClick: () => navigate('/parapharmacy/health-claims/new'),
                        icon: Plus,
                      }}
                    />
                  </td>
                </tr>
              ) : (
                data?.data?.map((healthClaim) => (
                  <tr key={healthClaim.id} className="hover:bg-gray-50">
                    <td className="px-6 py-4 text-sm font-medium text-gray-900 max-w-md truncate">
                      {healthClaim.claim}
                    </td>
                    <td className="px-6 py-4 text-sm text-gray-500">
                      <code className="text-xs bg-gray-100 px-2 py-1 rounded">
                        {healthClaim.claim_type}
                      </code>
                    </td>
                    <td className="px-6 py-4 text-sm">
                      <Badge
                        variant={
                          healthClaim.regulatory_status === 'approved'
                            ? 'success'
                            : healthClaim.regulatory_status === 'pending'
                            ? 'warning'
                            : 'danger'
                        }
                      >
                        {t(
                          `parapharmacy:regulatoryStatus.${healthClaim.regulatory_status}`
                        )}
                      </Badge>
                    </td>
                    <td className="px-6 py-4 text-sm">
                      {healthClaim.requires_disclaimer ? (
                        <Badge variant="info">
                          {t('parapharmacy:required')}
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
                            navigate(`/parapharmacy/health-claims/${healthClaim.id}`)
                          }
                          aria-label={t('common:edit')}
                        >
                          <Edit className="h-4 w-4" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setDeleteId(healthClaim.id)}
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
          totalPages={Math.ceil(
            data.meta.total / data.meta.per_page
          )}
          onPageChange={setPage}
        />
      )}

      <ConfirmDialog
        isOpen={!!deleteId}
        onClose={() => setDeleteId(null)}
        onConfirm={() => deleteId && deleteMutation.mutate(deleteId)}
        title={t('parapharmacy:confirmDeleteHealthClaim')}
        message={t('parapharmacy:confirmDeleteHealthClaimDescription')}
        confirmText={t('common:delete')}
        confirmVariant="danger"
        isLoading={deleteMutation.isPending}
      />
    </div>
  )
}
