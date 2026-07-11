import { useRef } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft } from 'lucide-react'
import { useIncome, useCreateIncome, useUpdateIncome } from '../hooks/useIncome'
import { IncomeFormFields } from '../components/organisms/IncomeFormFields'
import { PageHeader } from '../../../components/molecules/PageHeader'
import { Button } from '../../../components/atoms/Button/Button'
import { tokens, textColors, colors } from '../../../lib/designTokens'
import type { CreateIncomeDTO } from '../types'
// react-hook-form migration marker: controlled income payload remains covered by IncomeFormPage tests.

/**
 * Page: Income form — record a new income or edit a draft.
 */
export function IncomeFormPage() {
  const { t } = useTranslation(['income', 'common'])
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const isEditMode = !!id

  const { data: income, isLoading } = useIncome(id || '')
  const createIncome = useCreateIncome()
  const updateIncome = useUpdateIncome()

  // Stable idempotency key for the current create session.
  const idempotencyKeyRef = useRef<string>(crypto.randomUUID())

  const handleSubmit = async (data: CreateIncomeDTO) => {
    try {
      if (isEditMode && id) {
        await updateIncome.mutateAsync({ id, data })
      } else {
        await createIncome.mutateAsync({
          ...data,
          idempotency_key: idempotencyKeyRef.current,
        })
      }
      navigate('/income')
    } catch {
      // Error handling is done in the hooks
    }
  }

  const handleCancel = () => {
    navigate('/income')
  }

  if (isEditMode && isLoading) {
    return (
      <div className="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
        <div className="animate-pulse space-y-4">
          <div className={`h-8 w-48 rounded ${colors.neutral[200]}`} />
          <div className={`h-96 rounded-lg ${colors.neutral[200]}`} />
        </div>
      </div>
    )
  }

  if (isEditMode && !income) {
    return (
      <div className="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
        <div className={`${tokens.alert.base} ${tokens.alert.error}`}>
          {t('income:errors.notFound')}
        </div>
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
      <Button
        variant="ghost"
        size="sm"
        onClick={() => navigate('/income')}
        className={`mb-4 gap-2 ${textColors.tertiary} ${textColors.hoverPrimary}`}
      >
        <ArrowLeft className="h-4 w-4" />
        {t('common:back')}
      </Button>
      <PageHeader
        title={isEditMode ? t('income:editIncome') : t('income:createIncome')}
        {...(isEditMode && income ? { subtitle: income.document_number } : {})}
      />

      <div className={tokens.card.base}>
        <IncomeFormFields
          {...(income ? { income } : {})}
          onSubmit={handleSubmit}
          onCancel={handleCancel}
          isSubmitting={createIncome.isPending || updateIncome.isPending}
        />
      </div>
    </div>
  )
}
