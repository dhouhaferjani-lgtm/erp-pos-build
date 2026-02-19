import { useNavigate, useParams, Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Edit } from 'lucide-react'
import { BatchForm, type BatchFormData } from '../components/BatchForm'
import { useBatch, useUpdateBatch } from '../hooks/useBatches'

export function EditBatchPage() {
  const { t } = useTranslation(['batches', 'common'])
  const { uuid = '' } = useParams<{ uuid: string }>()
  const navigate = useNavigate()

  const { data: batch, isLoading, error } = useBatch(uuid)
  const updateMutation = useUpdateBatch()

  const handleSubmit = (data: BatchFormData) => {
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
        <div className="text-gray-500">{t('common:status.loading')}</div>
      </div>
    )
  }

  if (error || !batch) {
    return (
      <div className="rounded-lg bg-red-50 p-4 text-red-700">
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
            className="text-gray-400 hover:text-gray-600"
          >
            <ArrowLeft className="h-6 w-6" />
          </Link>
          <h1 className="text-2xl font-bold text-gray-900">
            {t('batches:actions.editBatch')}
          </h1>
        </div>
        <div className="rounded-lg bg-orange-50 p-4 text-orange-700">
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
            className="text-gray-400 hover:text-gray-600"
          >
            <ArrowLeft className="h-6 w-6" />
          </Link>
          <h1 className="text-2xl font-bold text-gray-900">
            {t('batches:actions.editBatch')}
          </h1>
        </div>
        <div className="rounded-lg bg-gray-50 p-4 text-gray-700">
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
          className="text-gray-400 hover:text-gray-600"
        >
          <ArrowLeft className="h-6 w-6" />
        </Link>
        <div>
          <h1 className="text-2xl font-bold text-gray-900">
            {t('batches:actions.editBatch')}
          </h1>
          <p className="mt-1 text-sm text-gray-500">
            {batch.batch_number}
          </p>
        </div>
      </div>

      {/* Form Card */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <div className="mb-6 flex items-center gap-3 border-b border-gray-200 pb-4">
          <div className="rounded-lg bg-blue-50 p-2">
            <Edit className="h-5 w-5 text-blue-600" />
          </div>
          <div>
            <h2 className="text-lg font-semibold text-gray-900">
              {t('batches:form.batchInformation')}
            </h2>
            <p className="text-sm text-gray-500">
              {t('batches:form.updateBatchDetails')}
            </p>
          </div>
        </div>

        <BatchForm
          batch={batch}
          onSubmit={handleSubmit}
          isSubmitting={updateMutation.isPending}
          submitLabel={t('common:actions.save')}
        />
      </div>
    </div>
  )
}
