import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Plus } from 'lucide-react'
import { Link } from 'react-router-dom'
import { BatchForm, type BatchFormData } from '../components/BatchForm'
import { useCreateBatch } from '../hooks/useBatches'

export function CreateBatchPage() {
  const { t } = useTranslation(['batches', 'common'])
  const navigate = useNavigate()
  const createMutation = useCreateBatch()

  const handleSubmit = (data: BatchFormData) => {
    createMutation.mutate(data, {
      onSuccess: (batch) => {
        navigate(`/inventory/batches/${batch.uuid}`)
      },
    })
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <Link
          to="/inventory/batches"
          className="text-gray-400 hover:text-gray-600"
        >
          <ArrowLeft className="h-6 w-6" />
        </Link>
        <div>
          <h1 className="text-2xl font-bold text-gray-900">
            {t('batches:actions.createBatch')}
          </h1>
          <p className="mt-1 text-sm text-gray-500">
            {t('batches:form.createBatchDescription')}
          </p>
        </div>
      </div>

      {/* Form Card */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <div className="mb-6 flex items-center gap-3 border-b border-gray-200 pb-4">
          <div className="rounded-lg bg-blue-50 p-2">
            <Plus className="h-5 w-5 text-blue-600" />
          </div>
          <div>
            <h2 className="text-lg font-semibold text-gray-900">
              {t('batches:form.batchInformation')}
            </h2>
            <p className="text-sm text-gray-500">
              {t('batches:form.fillInBatchDetails')}
            </p>
          </div>
        </div>

        <BatchForm
          onSubmit={handleSubmit}
          isSubmitting={createMutation.isPending}
          submitLabel={t('batches:actions.createBatch')}
        />
      </div>
    </div>
  )
}
