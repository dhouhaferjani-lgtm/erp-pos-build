import { useState, useCallback, useMemo } from 'react'
import { useParams, Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  ArrowLeft,
  ArrowRight,
  Loader2,
  CheckCircle,
  AlertCircle,
  Play,
} from 'lucide-react'
import {
  useOpeningBatch,
  useOpeningBatchStatus,
  useCreateOpeningBatch,
  useImportOpeningRows,
  useValidateOpeningBatch,
  useOpeningBatchPreview,
  usePostOpeningBatch,
  useLockOpeningBatch,
  useOpeningBatchRows,
} from '../api/queries'
import { FileUpload } from '../components/FileUpload'
import { ValidationResults } from '../components/ValidationResults'
import { BatchPreview } from '../components/BatchPreview'
import { LockConfirmation } from '../components/LockConfirmation'
import { openingBatchTypeKey } from '../i18nKeys'
import type { OpeningBatchType } from '../types'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

type WizardStep = 'setup' | 'upload' | 'validate' | 'preview' | 'post' | 'lock' | 'complete'

const STEPS: { key: WizardStep; labelKey: string }[] = [
  { key: 'setup', labelKey: 'openingBalances.wizard.steps.setup' },
  { key: 'upload', labelKey: 'openingBalances.wizard.steps.upload' },
  { key: 'validate', labelKey: 'openingBalances.wizard.steps.validate' },
  { key: 'preview', labelKey: 'openingBalances.wizard.steps.preview' },
  { key: 'post', labelKey: 'openingBalances.wizard.steps.post' },
  { key: 'lock', labelKey: 'openingBalances.wizard.steps.lock' },
]

function StepIndicator({
  steps,
  currentIndex,
  completedSteps,
}: {
  steps: { key: WizardStep; labelKey: string }[]
  currentIndex: number
  completedSteps: Set<WizardStep>
}) {
  const { t } = useTranslation()

  return (
    <div className="flex items-center justify-between">
      {steps.map((step, index) => {
        const isCompleted = completedSteps.has(step.key)
        const isCurrent = index === currentIndex
        const isPast = index < currentIndex

        return (
          <div key={step.key} className="flex items-center">
            <div className="flex flex-col items-center">
              <div
                className={`flex h-8 w-8 items-center justify-center rounded-full text-sm font-medium transition-colors ${
                  isCompleted
                    ? `${colorTokens.intent.success.bg} ${colorTokens.text.inverse}`
                    : isCurrent
                      ? `${colorTokens.intent.primary.bg} ${colorTokens.text.inverse}`
                      : isPast
                        ? `${colorTokens.intent.primary.bgSoftStrong} ${colorTokens.intent.primary.textStrong}`
                        : `${colorTokens.surface.subdued} ${colorTokens.text.subtle}`
                }`}
              >
                {isCompleted ? <CheckCircle className="h-4 w-4" /> : index + 1}
              </div>
              <span
                className={`mt-1 text-xs ${isCurrent ? `font-medium ${colorTokens.intent.primary.text}` : colorTokens.text.subtle}`}
              >
                {t(step.labelKey)}
              </span>
            </div>
            {index < steps.length - 1 && (
              <div
                className={`mx-2 h-0.5 w-12 ${
                  isPast || isCompleted ? colorTokens.intent.primary.bgSoftStronger : colorTokens.surface.subdued
                }`}
              />
            )}
          </div>
        )
      })}
    </div>
  )
}

export function OpeningBalanceWizardPage() {
  const { type } = useParams<{ type: string }>()
  const batchType = type?.toUpperCase() as OpeningBatchType
  const { t } = useTranslation()

  // State
  const [currentStep, setCurrentStep] = useState<WizardStep>('setup')
  const [completedSteps, setCompletedSteps] = useState<Set<WizardStep>>(new Set())
  const [batchId, setBatchId] = useState<string | null>(null)
  const [batchName, setBatchName] = useState('')
  const [cutoverDate, setCutoverDate] = useState(new Date().toISOString().split('T')[0])
  const [validationResult, setValidationResult] = useState<{
    valid: boolean
    total_rows: number
    valid_rows: number
    invalid_rows: number
  } | null>(null)

  // Check if batch already exists
  const { data: statusData } = useOpeningBatchStatus()
  const existingStatus = statusData?.types?.[batchType]

  // If batch exists, load it
  const { data: existingBatch } = useOpeningBatch(existingStatus?.batch?.id ?? undefined)

  // Rows for validation display
  const { data: rowsData, refetch: refetchRows } = useOpeningBatchRows(
    batchId ?? existingStatus?.batch?.id ?? undefined,
    { per_page: 100 }
  )

  // Preview data
  const { data: previewData, isLoading: previewLoading } = useOpeningBatchPreview(
    currentStep === 'preview' || currentStep === 'post' ? (batchId ?? existingStatus?.batch?.id ?? undefined) : undefined
  )

  // N-3: the three preview variants carry DIFFERENT totals keys — ACCOUNTING
  // has {debit,credit,is_balanced}, INVENTORY {total_lines,total_quantity,
  // total_value}, AR/AP {total_documents,total_amount,total_open_amount}. The
  // post step used to read `total_lines ?? total_documents ?? 0` plus optional
  // `total_value`/`total_debit` off one flattened shape, so an ACCOUNTING batch
  // showed "Total Rows 0" and no amount at all. Narrow on the discriminator.
  const postSummary = ((): { rows: number; amountLabelKey: string; amount: string } | null => {
    if (previewData === undefined) {
      return null
    }

    switch (previewData.batch_type) {
      case 'ACCOUNTING':
        return {
          rows: previewData.lines.length,
          amountLabelKey: 'openingBalances.wizard.post.totalDebit',
          amount: previewData.totals.debit,
        }
      case 'INVENTORY':
        return {
          rows: previewData.totals.total_lines,
          amountLabelKey: 'openingBalances.wizard.post.totalValue',
          amount: previewData.totals.total_value,
        }
      default:
        return {
          rows: previewData.totals.total_documents,
          amountLabelKey: 'openingBalances.wizard.post.totalValue',
          amount: previewData.totals.total_amount,
        }
    }
  })()

  // Mutations
  const createBatch = useCreateOpeningBatch()
  const importRows = useImportOpeningRows()
  const validateBatch = useValidateOpeningBatch()
  const postBatch = usePostOpeningBatch()
  const lockBatch = useLockOpeningBatch()

  // Initialize from existing batch
  useMemo(() => {
    if (existingBatch && !batchId) {
      setBatchId(existingBatch.id)
      setBatchName(existingBatch.name)
      setCutoverDate(existingBatch.cutover_date)

      // Determine starting step based on status
      if (existingBatch.status === 'LOCKED') {
        setCurrentStep('complete')
        setCompletedSteps(new Set(['setup', 'upload', 'validate', 'preview', 'post', 'lock']))
      } else if (existingBatch.status === 'VALIDATED') {
        setCurrentStep('lock')
        setCompletedSteps(new Set(['setup', 'upload', 'validate', 'preview', 'post']))
      } else if (existingBatch.rows_count && existingBatch.rows_count > 0) {
        setCurrentStep('validate')
        setCompletedSteps(new Set(['setup', 'upload']))
      } else {
        setCurrentStep('upload')
        setCompletedSteps(new Set(['setup']))
      }
    }
  }, [existingBatch, batchId])

  const stepIndex = STEPS.findIndex((s) => s.key === currentStep)

  // Handlers
  const handleSetupComplete = useCallback(async () => {
    if (!batchName.trim() || !cutoverDate) return

    try {
      const batch = await createBatch.mutateAsync({
        type: batchType,
        name: batchName,
        cutover_date: cutoverDate,
      })
      setBatchId(batch.id)
      setCompletedSteps((prev) => new Set([...prev, 'setup']))
      setCurrentStep('upload')
    } catch {
      // Error handled by mutation
    }
  }, [batchName, cutoverDate, batchType, createBatch])

  const handleFileUpload = useCallback(
    async (rows: Array<Record<string, unknown>>) => {
      const targetBatchId = batchId ?? existingStatus?.batch?.id
      if (!targetBatchId || rows.length === 0) return

      try {
        await importRows.mutateAsync({
          batchId: targetBatchId,
          payload: { rows },
        })
        await refetchRows()
        setCompletedSteps((prev) => new Set([...prev, 'upload']))
        setCurrentStep('validate')
      } catch {
        // Error handled by mutation
      }
    },
    [batchId, existingStatus?.batch?.id, importRows, refetchRows]
  )

  const handleValidate = useCallback(async () => {
    const targetBatchId = batchId ?? existingStatus?.batch?.id
    if (!targetBatchId) return

    try {
      const result = await validateBatch.mutateAsync(targetBatchId)
      setValidationResult(result)
      await refetchRows()

      if (result.valid) {
        setCompletedSteps((prev) => new Set([...prev, 'validate']))
        setCurrentStep('preview')
      }
    } catch {
      // Error handled by mutation
    }
  }, [batchId, existingStatus?.batch?.id, validateBatch, refetchRows])

  const handlePreviewContinue = useCallback(() => {
    setCompletedSteps((prev) => new Set([...prev, 'preview']))
    setCurrentStep('post')
  }, [])

  const handlePost = useCallback(async () => {
    const targetBatchId = batchId ?? existingStatus?.batch?.id
    if (!targetBatchId) return

    try {
      await postBatch.mutateAsync(targetBatchId)
      setCompletedSteps((prev) => new Set([...prev, 'post']))
      setCurrentStep('lock')
    } catch {
      // Error handled by mutation
    }
  }, [batchId, existingStatus?.batch?.id, postBatch])

  const handleLock = useCallback(async () => {
    const targetBatchId = batchId ?? existingStatus?.batch?.id
    if (!targetBatchId) return

    try {
      await lockBatch.mutateAsync(targetBatchId)
      setCompletedSteps((prev) => new Set([...prev, 'lock']))
      setCurrentStep('complete')
    } catch {
      // Error handled by mutation
    }
  }, [batchId, existingStatus?.batch?.id, lockBatch])

  const typeKey = batchType ? openingBatchTypeKey(batchType) : ''

  // Render step content
  const renderStepContent = () => {
    switch (currentStep) {
      case 'setup':
        return (
          <div className="space-y-6">
            <div>
              <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
                {t('openingBalances.wizard.setup.title')}
              </h2>
              <p className={`mt-1 text-sm ${colorTokens.text.muted}`}>
                {t('openingBalances.wizard.setup.description')}
              </p>
            </div>

            <div className="space-y-4">
              <div>
                <label htmlFor="batchName" className={`block text-sm font-medium ${colorTokens.text.secondary}`}>
                  {t('openingBalances.wizard.setup.nameLabel')}
                </label>
                <input
                  type="text"
                  id="batchName"
                  value={batchName}
                  onChange={(e) => { setBatchName(e.target.value); }}
                  placeholder={t('openingBalances.wizard.setup.namePlaceholder')}
                  className={`mt-1 block w-full rounded-md border ${colorTokens.border.default} px-3 py-2 shadow-sm ${colorTokens.focus.primaryBorder} focus:outline-none focus:ring-1 ${colorTokens.focus.primaryRing}`}
                />
              </div>

              <div>
                <label htmlFor="cutoverDate" className={`block text-sm font-medium ${colorTokens.text.secondary}`}>
                  {t('openingBalances.wizard.setup.cutoverDateLabel')}
                </label>
                <input
                  type="date"
                  id="cutoverDate"
                  value={cutoverDate}
                  onChange={(e) => { setCutoverDate(e.target.value); }}
                  className={`mt-1 block w-full rounded-md border ${colorTokens.border.default} px-3 py-2 shadow-sm ${colorTokens.focus.primaryBorder} focus:outline-none focus:ring-1 ${colorTokens.focus.primaryRing}`}
                />
                <p className={`mt-1 text-xs ${colorTokens.text.subtle}`}>
                  {t('openingBalances.wizard.setup.cutoverDateHelp')}
                </p>
              </div>
            </div>

            <div className={`flex justify-end border-t ${colorTokens.border.subtle} pt-4`}>
              <button
                type="button"
                onClick={handleSetupComplete}
                disabled={!batchName.trim() || !cutoverDate || createBatch.isPending}
                className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrongHover} disabled:cursor-not-allowed ${colorTokens.surface.disabledWhenDisabled}`}
              >
                {createBatch.isPending ? (
                  <>
                    <Loader2 className="h-4 w-4 animate-spin" />
                    {t('status.creating')}
                  </>
                ) : (
                  <>
                    {t('actions.next')}
                    <ArrowRight className="h-4 w-4" />
                  </>
                )}
              </button>
            </div>
          </div>
        )

      case 'upload':
        return (
          <div className="space-y-6">
            <div>
              <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
                {t('openingBalances.wizard.upload.title')}
              </h2>
              <p className={`mt-1 text-sm ${colorTokens.text.muted}`}>
                {t(`openingBalances.types.${typeKey}.uploadHelp`)}
              </p>
            </div>

            <FileUpload
              batchType={batchType}
              onUpload={handleFileUpload}
              isUploading={importRows.isPending}
            />

            <div className={`flex items-center justify-between border-t ${colorTokens.border.subtle} pt-4`}>
              <button
                type="button"
                onClick={() => { setCurrentStep('setup'); }}
                disabled={importRows.isPending}
                className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.intent.neutral.textHoverStrongest}`}
              >
                <ArrowLeft className="h-4 w-4" />
                {t('actions.back')}
              </button>
              {rowsData?.data && rowsData.data.length > 0 && (
                <button
                  type="button"
                  onClick={() => {
                    setCompletedSteps((prev) => new Set([...prev, 'upload']))
                    setCurrentStep('validate')
                  }}
                  className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrongHover}`}
                >
                  {t('actions.next')}
                  <ArrowRight className="h-4 w-4" />
                </button>
              )}
            </div>
          </div>
        )

      case 'validate':
        return (
          <div className="space-y-6">
            <div>
              <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
                {t('openingBalances.wizard.validate.title')}
              </h2>
              <p className={`mt-1 text-sm ${colorTokens.text.muted}`}>
                {t('openingBalances.wizard.validate.description')}
              </p>
            </div>

            <ValidationResults
              rows={rowsData?.data ?? []}
              validationResult={validationResult}
            />

            <div className={`flex items-center justify-between border-t ${colorTokens.border.subtle} pt-4`}>
              <button
                type="button"
                onClick={() => { setCurrentStep('upload'); }}
                className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.intent.neutral.textHoverStrongest}`}
              >
                <ArrowLeft className="h-4 w-4" />
                {t('actions.back')}
              </button>
              <button
                type="button"
                onClick={handleValidate}
                disabled={validateBatch.isPending}
                className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrongHover} disabled:cursor-not-allowed ${colorTokens.surface.disabledWhenDisabled}`}
              >
                {validateBatch.isPending ? (
                  <>
                    <Loader2 className="h-4 w-4 animate-spin" />
                    {t('openingBalances.wizard.validate.validating')}
                  </>
                ) : (
                  <>
                    <CheckCircle className="h-4 w-4" />
                    {t('openingBalances.wizard.validate.validateButton')}
                  </>
                )}
              </button>
            </div>
          </div>
        )

      case 'preview':
        return (
          <div className="space-y-6">
            <div>
              <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
                {t('openingBalances.wizard.preview.title')}
              </h2>
              <p className={`mt-1 text-sm ${colorTokens.text.muted}`}>
                {t('openingBalances.wizard.preview.description')}
              </p>
            </div>

            {previewLoading ? (
              <div className="flex items-center justify-center py-12">
                <Loader2 className={`h-8 w-8 animate-spin ${colorTokens.intent.primary.textSubtle}`} />
              </div>
            ) : previewData ? (
              <BatchPreview preview={previewData} />
            ) : null}

            <div className={`flex items-center justify-between border-t ${colorTokens.border.subtle} pt-4`}>
              <button
                type="button"
                onClick={() => { setCurrentStep('validate'); }}
                className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.intent.neutral.textHoverStrongest}`}
              >
                <ArrowLeft className="h-4 w-4" />
                {t('actions.back')}
              </button>
              <button
                type="button"
                onClick={handlePreviewContinue}
                className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrongHover}`}
              >
                {t('openingBalances.wizard.preview.continueButton')}
                <ArrowRight className="h-4 w-4" />
              </button>
            </div>
          </div>
        )

      case 'post':
        return (
          <div className="space-y-6">
            <div>
              <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
                {t('openingBalances.wizard.post.title')}
              </h2>
              <p className={`mt-1 text-sm ${colorTokens.text.muted}`}>
                {t('openingBalances.wizard.post.description')}
              </p>
            </div>

            <div className={`rounded-lg border ${colorTokens.intent.caution.borderSubtle} ${colorTokens.intent.caution.bgSubtle} p-4`}>
              <div className="flex items-start gap-3">
                <AlertCircle className={`h-5 w-5 ${colorTokens.intent.caution.text} mt-0.5`} />
                <div>
                  <h3 className={`font-medium ${colorTokens.intent.caution.textStronger}`}>
                    {t('openingBalances.wizard.post.warningTitle')}
                  </h3>
                  <p className={`mt-1 text-sm ${colorTokens.intent.caution.textStrong}`}>
                    {t('openingBalances.wizard.post.warningMessage')}
                  </p>
                </div>
              </div>
            </div>

            {postSummary !== null && (
              <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-4`}>
                <h3 className={`font-medium ${colorTokens.text.primary} mb-3`}>
                  {t('openingBalances.wizard.post.summary')}
                </h3>
                <dl className="space-y-2 text-sm">
                  <div className="flex justify-between">
                    <dt className={colorTokens.text.subtle}>{t('openingBalances.wizard.post.totalRows')}</dt>
                    <dd className={`font-medium ${colorTokens.text.primary}`}>{postSummary.rows}</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className={colorTokens.text.subtle}>{t(postSummary.amountLabelKey)}</dt>
                    <dd className={`font-medium ${colorTokens.text.primary}`}>{postSummary.amount}</dd>
                  </div>
                </dl>
              </div>
            )}

            <div className={`flex items-center justify-between border-t ${colorTokens.border.subtle} pt-4`}>
              <button
                type="button"
                onClick={() => { setCurrentStep('preview'); }}
                disabled={postBatch.isPending}
                className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.intent.neutral.textHoverStrongest}`}
              >
                <ArrowLeft className="h-4 w-4" />
                {t('actions.back')}
              </button>
              <button
                type="button"
                onClick={handlePost}
                disabled={postBatch.isPending}
                className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.success.bgStrong} px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.success.bgStrongHover} disabled:cursor-not-allowed ${colorTokens.surface.disabledWhenDisabled}`}
              >
                {postBatch.isPending ? (
                  <>
                    <Loader2 className="h-4 w-4 animate-spin" />
                    {t('openingBalances.wizard.post.posting')}
                  </>
                ) : (
                  <>
                    <Play className="h-4 w-4" />
                    {t('openingBalances.wizard.post.postButton')}
                  </>
                )}
              </button>
            </div>
          </div>
        )

      case 'lock':
        return (
          <LockConfirmation
            onLock={handleLock}
            onBack={() => { setCurrentStep('post'); }}
            isLocking={lockBatch.isPending}
          />
        )

      case 'complete':
        return (
          <div className="space-y-6 text-center py-8">
            <CheckCircle className={`mx-auto h-16 w-16 ${colorTokens.intent.success.textSubtle}`} />
            <h2 className={`text-2xl font-bold ${colorTokens.text.primary}`}>
              {t('openingBalances.wizard.complete.title')}
            </h2>
            <p className={colorTokens.text.muted}>
              {t('openingBalances.wizard.complete.description')}
            </p>

            <div className="flex items-center justify-center gap-4 pt-6">
              <Link
                to="/settings/opening-balances"
                className={`inline-flex items-center gap-2 rounded-lg border ${colorTokens.border.default} ${colorTokens.surface.base} px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.intent.neutral.bgHover}`}
              >
                {t('openingBalances.wizard.complete.backToDashboard')}
              </Link>
            </div>
          </div>
        )

      default:
        return null
    }
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <Link
          to="/settings/opening-balances"
          className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.intent.neutral.textHoverStrongest}`}
        >
          <ArrowLeft className="h-4 w-4" />
          {t('actions.back')}
        </Link>
        <div>
          <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>
            {t(`openingBalances.types.${typeKey}.title`)}
          </PageHeaderTitle>
          <p className={colorTokens.text.subtle}>{t(`openingBalances.types.${typeKey}.description`)}</p>
        </div>
      </div>

      {/* Step indicator */}
      {currentStep !== 'complete' && (
        <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
          <StepIndicator
            steps={STEPS}
            currentIndex={stepIndex}
            completedSteps={completedSteps}
          />
        </div>
      )}

      {/* Step content */}
      <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
        {renderStepContent()}
      </div>
    </div>
  )
}
