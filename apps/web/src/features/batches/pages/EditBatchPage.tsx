import { useNavigate, useParams, Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Edit } from 'lucide-react'
import { BatchForm, type BatchFormData } from '../components/BatchForm'
import { useBatch, useUpdateBatch } from '../hooks/useBatches'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

export function EditBatchPage() {
  const { t } = useTranslation(['batches', 'common'])
  const { uuid = '' } = useParams<{ uuid: string }>()
  const navigate = useNavigate()

  const { data: batch, isLoading, error } = useBatch(uuid)
  const updateMutation = useUpdateBatch()

  const handleBatchSubmit = (data: BatchFormData) => {
    updateMutation.mutate(
      { uuid, input: data },
      {
        onSuccess: () => {
          navigate(`/inventory/batches/${uuid}`)
        },
      }
    )
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className={`${colorTokens.text.subtle}`}>{t('common:status.loading')}</div>
      </div>
    )
  }

  if (error || !batch) {
    return (
      <div className={`rounded-lg ${colorTokens.intent.danger.bgSubtle} p-4 ${colorTokens.intent.danger.textStrong}`}>
        {t('batches:messages.loadError')}
      </div>
    )
  }

  // Prevent editing recalled or inactive batches
  if (batch.is_recalled) {
    return (
      <div className="space-y-4">
        <div className="flex items-center gap-4">
          <Link
            to={`/inventory/batches/${uuid}`}
            className={`${colorTokens.text.disabled} hover:${colorTokens.text.muted}`}
          >
            <ArrowLeft className="h-6 w-6" />
          </Link>
          <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>
            {t('batches:actions.editBatch')}
          </PageHeaderTitle>
        </div>
        <div className={`rounded-lg ${colorTokens.intent.notice.bgSubtle} p-4 ${colorTokens.intent.notice.textStrong}`}>
          <p className="font-medium">{t('batches:form.cannotEditRecalled')}</p>
          <p className="mt-1 text-sm">{t('batches:form.recalledBatchExplanation')}</p>
        </div>
      </div>
    )
  }

  if (!batch.is_active) {
    return (
      <div className="space-y-4">
        <div className="flex items-center gap-4">
          <Link
            to={`/inventory/batches/${uuid}`}
            className={`${colorTokens.text.disabled} hover:${colorTokens.text.muted}`}
          >
            <ArrowLeft className="h-6 w-6" />
          </Link>
          <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>
            {t('batches:actions.editBatch')}
          </PageHeaderTitle>
        </div>
        <div className={`rounded-lg ${colorTokens.surface.page} p-4 ${colorTokens.text.secondary}`}>
          <p className="font-medium">{t('batches:form.cannotEditInactive')}</p>
          <p className="mt-1 text-sm">{t('batches:form.inactiveBatchExplanation')}</p>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <Link
          to={`/inventory/batches/${uuid}`}
          className={`${colorTokens.text.disabled} hover:${colorTokens.text.muted}`}
        >
          <ArrowLeft className="h-6 w-6" />
        </Link>
        <div>
          <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>
            {t('batches:actions.editBatch')}
          </PageHeaderTitle>
          <p className={`mt-1 text-sm ${colorTokens.text.subtle}`}>
            {batch.batch_number}
          </p>
        </div>
      </div>

      {/* Form Card */}
      <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white p-6`}>
        <div className={`mb-6 flex items-center gap-3 border-b ${colorTokens.border.subtle} pb-4`}>
          <div className={`rounded-lg ${colorTokens.intent.primary.bgSubtle} p-2`}>
            <Edit className={`h-5 w-5 ${colorTokens.intent.primary.text}`} />
          </div>
          <div>
            <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
              {t('batches:form.batchInformation')}
            </h2>
            <p className={`text-sm ${colorTokens.text.subtle}`}>
              {t('batches:form.updateBatchDetails')}
            </p>
          </div>
        </div>

        <BatchForm
          batch={batch}
          onSave={handleBatchSubmit}
          isSubmitting={updateMutation.isPending}
          submitLabel={t('common:actions.save')}
        />
      </div>
    </div>
  )
}
