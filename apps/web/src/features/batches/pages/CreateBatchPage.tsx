import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Plus } from 'lucide-react'
import { Link } from 'react-router-dom'
import { BatchForm, type BatchFormData } from '../components/BatchForm'
import { useCreateBatch } from '../hooks/useBatches'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

export function CreateBatchPage() {
  const { t } = useTranslation(['batches', 'common'])
  const navigate = useNavigate()
  const createMutation = useCreateBatch()

  const handleBatchSubmit = (data: BatchFormData) => {
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
          className={`${colorTokens.text.disabled} hover:${colorTokens.text.muted}`}
        >
          <ArrowLeft className="h-6 w-6" />
        </Link>
        <div>
          <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>
            {t('batches:actions.createBatch')}
          </PageHeaderTitle>
          <p className={`mt-1 text-sm ${colorTokens.text.subtle}`}>
            {t('batches:form.createBatchDescription')}
          </p>
        </div>
      </div>

      {/* Form Card */}
      <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white p-6`}>
        <div className={`mb-6 flex items-center gap-3 border-b ${colorTokens.border.subtle} pb-4`}>
          <div className={`rounded-lg ${colorTokens.intent.primary.bgSubtle} p-2`}>
            <Plus className={`h-5 w-5 ${colorTokens.intent.primary.text}`} />
          </div>
          <div>
            <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
              {t('batches:form.batchInformation')}
            </h2>
            <p className={`text-sm ${colorTokens.text.subtle}`}>
              {t('batches:form.fillInBatchDetails')}
            </p>
          </div>
        </div>

        <BatchForm
          onSave={handleBatchSubmit}
          isSubmitting={createMutation.isPending}
          submitLabel={t('batches:actions.createBatch')}
        />
      </div>
    </div>
  )
}
