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
import { fetchCertifications, deleteCertification } from '../api/certificationApi'
import { parapharmacyListInvalidationPredicate } from './tenantScope'
import { toast } from 'sonner'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

export function CertificationListPage() {
  const { t } = useTranslation(['common', 'parapharmacy'])
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const [page, setPage] = useState(1)
  const [deleteId, setDeleteId] = useState<string | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: tenantScopedKey(['parapharmacy', 'certifications', page]),
    queryFn: () => fetchCertifications({ page, per_page: 25 }),
    enabled: !!tenantId && !!companyId,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteCertification,
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: parapharmacyListInvalidationPredicate(tenantId, companyId, 'certifications'),
      })
      toast.success(t('parapharmacy:certificationDeleted'))
      setDeleteId(null)
    },
    onError: (error: any) => {
      const message =
        error?.response?.data?.error?.message ||
        t('parapharmacy:deleteCertificationError')
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
          <PageHeaderTitle className="text-3xl font-bold">{t('parapharmacy:certifications')}</PageHeaderTitle>
          <p className={`${colorTokens.text.muted}`}>
            {t('parapharmacy:certificationsDescription')}
          </p>
        </div>
        <Button onClick={() => navigate('/parapharmacy/certifications/new')}>
          <Plus className="h-4 w-4 mr-2" />
          {t('parapharmacy:addCertification')}
        </Button>
      </div>

      <div className={`rounded-lg border ${colorTokens.border.subtle} overflow-hidden`}>
        <div className="overflow-x-auto">
          <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
            <thead className={`${colorTokens.surface.page}`}>
              <tr>
                <th className={`px-6 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                  {t('parapharmacy:name')}
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                  {t('parapharmacy:type')}
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                  {t('parapharmacy:certifyingBody')}
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                  {t('parapharmacy:status')}
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                  {t('parapharmacy:displayOrder')}
                </th>
                <th className={`px-6 py-3 text-right text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
                  {t('common:actions')}
                </th>
              </tr>
            </thead>
            <tbody className={`bg-white divide-y ${colorTokens.border.divider}`}>
              {data?.data?.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-6 py-12">
                    <EmptyState
                      title={t('parapharmacy:noCertifications')}
                      description={t('parapharmacy:noCertificationsDescription')}
                    />
                    <div className="flex justify-center mt-4">
                      <Button onClick={() => navigate('/parapharmacy/certifications/new')}>
                        <Plus className="h-4 w-4 mr-2" />
                        {t('parapharmacy:addCertification')}
                      </Button>
                    </div>
                  </td>
                </tr>
              ) : (
                data?.data?.map((certification) => (
                  <tr key={certification.id} className={`hover:${colorTokens.surface.page}`}>
                    <td className={`px-6 py-4 text-sm font-medium ${colorTokens.text.primary}`}>
                      {certification.name}
                    </td>
                    <td className={`px-6 py-4 text-sm ${colorTokens.text.subtle}`}>
                      <code className={`text-xs ${colorTokens.surface.muted} px-2 py-1 rounded`}>
                        {certification.type}
                      </code>
                    </td>
                    <td className={`px-6 py-4 text-sm ${colorTokens.text.subtle}`}>
                      {certification.certifying_body || '—'}
                    </td>
                    <td className="px-6 py-4 text-sm">
                      {certification.is_active ? (
                        <Badge variant="success">
                          {t('parapharmacy:active')}
                        </Badge>
                      ) : (
                        <Badge variant="default">
                          {t('parapharmacy:inactive')}
                        </Badge>
                      )}
                    </td>
                    <td className={`px-6 py-4 text-sm ${colorTokens.text.subtle}`}>
                      {certification.display_order}
                    </td>
                    <td className="px-6 py-4 text-sm text-right">
                      <div className="flex items-center justify-end gap-2">
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() =>
                            navigate(`/parapharmacy/certifications/${certification.id}`)
                          }
                          aria-label={t('common:edit')}
                        >
                          <Edit className="h-4 w-4" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => { setDeleteId(certification.id); }}
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
          </DataTable>
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
        title={t('parapharmacy:confirmDeleteCertification')}
        message={t('parapharmacy:confirmDeleteCertificationDescription')}
        confirmText={t('common:delete')}
        variant="danger"
        isLoading={deleteMutation.isPending}
      />
    </div>
  )
}
