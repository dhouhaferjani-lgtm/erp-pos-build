import { useState, useCallback, useMemo, useEffect, useRef } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, ArrowRight, Upload, Loader2, CheckCircle, XCircle, Download } from 'lucide-react'
import { Link } from 'react-router-dom'
import { FileUpload } from '../components/FileUpload'
import { ColumnMapper } from '../components/ColumnMapper'
import { ValidationGrid } from '../components/ValidationGrid'
import { ImportProgress } from '../components/ImportProgress'
import { ImportPreviewTable } from '../components/ImportPreviewTable'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import {
  useCreateImport,
  useExecuteImport,
  useSuggestMapping,
  useImportJob,
  useImportErrors,
  useImportPreview,
} from '../api/queries'
import { toast } from 'sonner'
import { importApi } from '../api/importApi'
import { authenticatedDownload } from '@/lib/api'
import { useImportProgressStore } from '../../../stores/importProgressStore'
import type { ImportJobOptions, ImportType } from '../types'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

type WizardStep = 'upload' | 'mapping' | 'options' | 'validation' | 'execute' | 'complete'

const STEPS: { key: WizardStep; label: string }[] = [
  { key: 'upload', label: 'wizard.steps.upload' },
  { key: 'mapping', label: 'wizard.steps.mapping' },
  { key: 'options', label: 'wizard.steps.options' },
  { key: 'validation', label: 'wizard.steps.validation' },
  { key: 'execute', label: 'wizard.steps.execute' },
  { key: 'complete', label: 'wizard.steps.complete' },
]

const PRODUCT_PRICE_COLUMNS = new Set(['sale_price_incl_tax', 'sale_price_excl_tax', 'margin'])

// Target columns per import type
const TARGET_COLUMNS: Record<ImportType, { name: string; required: boolean; description?: string }[]> = {
  parties: [
    { name: 'name', required: true },
    { name: 'type', required: true },
    { name: 'code', required: false },
    { name: 'email', required: false },
    { name: 'phone', required: false },
    { name: 'tax_id', required: false },
    { name: 'address_line1', required: false },
    { name: 'address_city', required: false },
    { name: 'address_postal_code', required: false },
    { name: 'address_country', required: false },
    { name: 'opening_balance', required: false },
    { name: 'opening_balance_customer', required: false },
    { name: 'opening_balance_supplier', required: false },
    { name: 'balance_date', required: false },
    { name: 'reference', required: false },
  ],
  partners: [
    { name: 'name', required: true, description: 'Partner name' },
    { name: 'type', required: true, description: 'customer or supplier' },
    { name: 'email', required: false },
    { name: 'phone', required: false },
    { name: 'vat_number', required: false },
    { name: 'address', required: false },
    { name: 'city', required: false },
    { name: 'country', required: false },
  ],
  products: [
    { name: 'name', required: true },
    { name: 'sku', required: false, description: 'Unique product code' },
    { name: 'type', required: false, description: 'part, service, or consumable' },
    { name: 'description', required: false },
    { name: 'sale_price', required: false, description: 'Selling price' },
    { name: 'sale_price_incl_tax', required: false, description: 'Selling price including tax' },
    { name: 'sale_price_excl_tax', required: false, description: 'Selling price excluding tax' },
    { name: 'margin', required: false, description: 'Margin on cost' },
    { name: 'purchase_price', required: false, description: 'Cost price' },
    { name: 'quantity', required: false },
    { name: 'location_code', required: false },
    { name: 'barcode', required: false },
    { name: 'brand', required: false },
    { name: 'category_name', required: false, description: 'Category name (must exist)' },
    { name: 'tax_rate', required: false, description: 'Tax rate percentage' },
    { name: 'unit', required: false, description: 'Unit of measure' },
    { name: 'is_active', required: false, description: 'true/false, yes/no, 1/0' },
  ],
  stock_levels: [
    { name: 'product_sku', required: true, description: 'Product SKU' },
    { name: 'location_code', required: true, description: 'Location code' },
    { name: 'quantity', required: true },
    { name: 'notes', required: false },
  ],
  opening_balances: [
    { name: 'account_code', required: true, description: 'GL account code' },
    { name: 'debit', required: true },
    { name: 'credit', required: true },
    { name: 'description', required: false },
    { name: 'reference', required: false },
  ],
  product_images: [],
  composite_items: [
    { name: 'code', required: true, description: 'Unique item code' },
    { name: 'name', required: true },
    { name: 'base_price', required: true, description: 'Selling price' },
    { name: 'vertical_type', required: false, description: 'fnb, manufacturing, sewing, bakery, generic' },
    { name: 'production_type', required: false, description: 'made_to_order, batch, stock' },
    { name: 'pricing_mode', required: false, description: 'standard or fixed_bundle' },
    { name: 'tax_rate', required: false },
    { name: 'manual_cost', required: false, description: 'Estimated cost per unit' },
    { name: 'category_name', required: false },
    { name: 'is_active', required: false },
    { name: 'description', required: false },
  ],
}

export function ImportWizardPage() {
  const { type } = useParams<{ type: string }>()
  const importType = type as ImportType
  const navigate = useNavigate()
  const { t } = useTranslation('import')

  // Wizard state
  const [currentStep, setCurrentStep] = useState<WizardStep>('upload')
  const [completedSteps, setCompletedSteps] = useState<number[]>([])

  // File state
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [sourceColumns, setSourceColumns] = useState<string[]>([])

  // Mapping state
  const [columnMapping, setColumnMapping] = useState<Record<string, string>>({})
  const [suggestions, setSuggestions] = useState<Record<string, string | null>>({})
  const [priceAuthority, setPriceAuthority] = useState<NonNullable<ImportJobOptions['price_authority']>>('ttc')

  // Job state
  const [jobId, setJobId] = useState<string | null>(null)

  // Dialog state for partial import confirmation
  const [showPartialImportDialog, setShowPartialImportDialog] = useState(false)

  // Import results state (from execute response)
  const [importResults, setImportResults] = useState<{
    imported_count: number
    skipped_count: number
    execution_error_count: number
    total_rows: number
    failed_rows_csv_url: string | null
  } | null>(null)

  // Mutations
  const createImport = useCreateImport()
  const executeImport = useExecuteImport()
  const suggestMapping = useSuggestMapping()

  // Get real-time progress from WebSocket store
  const { getImportProgress, updateProgress, completeImport } = useImportProgressStore()
  const realtimeProgress = jobId ? getImportProgress(jobId) : undefined
  const completedProgressJobsRef = useRef<Set<string>>(new Set())

  // Track if we're actively importing (for polling fallback)
  const [isImporting, setIsImporting] = useState(false)

  // Fetch job status from API - poll during importing as fallback for WebSocket
  const shouldFetchJob = jobId !== null && (currentStep === 'validation' || currentStep === 'execute' || currentStep === 'complete')
  const { data: apiJobData } = useImportJob(jobId ?? '', {
    enabled: shouldFetchJob,
    // Poll every 2 seconds during importing as fallback (WebSocket may not be working)
    refetchInterval: isImporting ? 2000 : false,
  })

  // Merge API data with real-time WebSocket progress
  // Real-time data takes precedence during execution
  const jobData = useMemo(() => {
    if (!apiJobData) return undefined

    // If we have real-time progress from WebSocket, merge it with API data
    if (realtimeProgress && (realtimeProgress.status === 'importing' || realtimeProgress.status === 'completed' || realtimeProgress.status === 'failed')) {
      return {
        ...apiJobData,
        status: realtimeProgress.status,
        processed_rows: realtimeProgress.processedRows,
        successful_rows: realtimeProgress.successfulRows,
        failed_rows: realtimeProgress.failedRows,
        error_message: realtimeProgress.errorMessage,
      }
    }

    return apiJobData
  }, [apiJobData, realtimeProgress])

  const shouldShowOptionsStep = useMemo(() => {
    if (importType !== 'products') {
      return false
    }

    const mappedPriceColumns = new Set(
      Object.values(columnMapping).filter((target) => PRODUCT_PRICE_COLUMNS.has(target))
    )

    return mappedPriceColumns.size >= 2
  }, [columnMapping, importType])

  const visibleSteps = useMemo(() => {
    return STEPS.filter((step) => step.key !== 'options' || shouldShowOptionsStep)
  }, [shouldShowOptionsStep])

  const markStepCompleted = useCallback((step: WizardStep) => {
    const completedIndex = visibleSteps.findIndex((visibleStep) => visibleStep.key === step)
    if (completedIndex < 0) {
      return
    }

    setCompletedSteps((prev) => (
      prev.includes(completedIndex) ? prev : [...prev, completedIndex]
    ))
  }, [visibleSteps])

  // Initialize real-time progress store when execution starts
  useEffect(() => {
    if (
      jobId &&
      apiJobData &&
      executeImport.isSuccess &&
      apiJobData.status !== 'completed' &&
      apiJobData.status !== 'failed'
    ) {
      // Seed the progress store with initial data when execution starts
      updateProgress({
        import_job_id: jobId,
        status: apiJobData.status,
        total_rows: apiJobData.total_rows ?? 0,
        processed_rows: apiJobData.processed_rows ?? 0,
        successful_rows: apiJobData.successful_rows ?? 0,
        failed_rows: apiJobData.failed_rows ?? 0,
        progress_percentage: apiJobData.progress_percentage ?? 0,
        import_type: importType,
        original_filename: selectedFile?.name ?? '',
      })
    }
  }, [jobId, apiJobData, executeImport.isSuccess, updateProgress, importType, selectedFile?.name])

  // Mirror API polling into the global progress widget when WebSocket events are absent.
  useEffect(() => {
    if (!jobId || !apiJobData || !executeImport.isSuccess) {
      return
    }

    if (apiJobData.status !== 'completed' && apiJobData.status !== 'failed') {
      updateProgress({
        import_job_id: jobId,
        status: apiJobData.status,
        total_rows: apiJobData.total_rows,
        processed_rows: apiJobData.processed_rows,
        successful_rows: apiJobData.successful_rows,
        failed_rows: apiJobData.failed_rows,
        progress_percentage: apiJobData.progress_percentage,
        import_type: apiJobData.type,
        original_filename: apiJobData.original_filename,
      })
      return
    }

    if (completedProgressJobsRef.current.has(jobId)) {
      return
    }
    completedProgressJobsRef.current.add(jobId)

    completeImport({
      import_job_id: jobId,
      status: apiJobData.status,
      total_rows: apiJobData.total_rows,
      successful_rows: apiJobData.successful_rows,
      failed_rows: apiJobData.failed_rows,
      import_type: apiJobData.type,
      original_filename: apiJobData.original_filename,
      completed_at: apiJobData.completed_at ?? new Date().toISOString(),
      is_success: apiJobData.status === 'completed' && apiJobData.failed_rows === 0,
      is_partial_success: apiJobData.status === 'completed' && apiJobData.successful_rows > 0 && apiJobData.failed_rows > 0,
      ...(apiJobData.error_message ? { error_message: apiJobData.error_message } : {}),
    })
  }, [apiJobData, completeImport, executeImport.isSuccess, jobId, updateProgress])

  // Auto-navigate to complete step when import is completed (from API or WebSocket)
  useEffect(() => {
    const status = realtimeProgress?.status ?? apiJobData?.status
    if ((status === 'completed' || status === 'failed') && currentStep === 'execute') {
      setIsImporting(false)
      markStepCompleted('execute')
      setCurrentStep('complete')
    }
  }, [realtimeProgress?.status, apiJobData?.status, currentStep, markStepCompleted])

  // Fetch validation errors when on validation step
  const { data: errorsData } = useImportErrors(jobId ?? '')
  const validationRows = errorsData?.data ?? []

  // Fetch preview data when on validation step
  const {
    data: previewData,
    isLoading: isPreviewLoading,
    isError: isPreviewError
  } = useImportPreview(
    currentStep === 'validation' && jobId ? jobId : ''
  )

  // Get step index
  const stepIndex = useMemo(() => {
    return visibleSteps.findIndex((s) => s.key === currentStep)
  }, [currentStep, visibleSteps])

  // Translated steps
  const translatedSteps = useMemo(() => {
    return visibleSteps.map((s) => ({
      key: s.key,
      label: t(s.label),
    }))
  }, [t, visibleSteps])

  // Handle file selection
  const handleFileSelect = useCallback(async (file: File) => {
    setSelectedFile(file)

    // Parse headers server-side: handles XLSX/XLS and any CSV delimiter
    // (semicolon is the default Excel CSV export in French/European locales),
    // which the browser cannot split as plain comma-separated text.
    try {
      const { headers } = await importApi.parseHeaders(file)
      setSourceColumns(headers)

      // Get mapping suggestions
      suggestMapping.mutate(
        { type: importType, headers },
        {
          onSuccess: (data) => {
            setSuggestions(data.suggestions)
          },
        }
      )
    } catch {
      toast.error(t('wizard.upload.parseError'))
      setSelectedFile(null)
      setSourceColumns([])
    }
  }, [importType, suggestMapping, t])

  // Handle upload step completion
  const handleUploadComplete = useCallback(() => {
    if (!selectedFile || sourceColumns.length === 0) return

    markStepCompleted('upload')
    setCurrentStep('mapping')
  }, [markStepCompleted, selectedFile, sourceColumns])

  // Handle mapping step completion
  const handleMappingComplete = useCallback(async () => {
    if (!selectedFile) return

    // Create import job with mapping
    createImport.mutate(
      {
        type: importType,
        file: selectedFile,
        columnMapping,
      },
      {
        onSuccess: (data) => {
          setJobId(data.data.id)
          // Backend returns validation status in the job, not rows directly
          // Fetch validation rows separately if needed
          markStepCompleted('mapping')
          setCurrentStep(shouldShowOptionsStep ? 'options' : 'validation')
        },
      }
    )
  }, [selectedFile, importType, columnMapping, createImport, markStepCompleted, shouldShowOptionsStep])

  const handleOptionsComplete = useCallback(async () => {
    if (!jobId) return

    await importApi.updateOptions(jobId, { price_authority: priceAuthority })
    markStepCompleted('options')
    setCurrentStep('validation')
  }, [jobId, markStepCompleted, priceAuthority])

  // Handle validation step completion
  const handleValidationComplete = useCallback(() => {
    const hasErrors = (jobData?.failed_rows ?? 0) > 0
    if (hasErrors) {
      // Show dialog to confirm partial import
      setShowPartialImportDialog(true)
      return
    }

    markStepCompleted('validation')
    setCurrentStep('execute')
  }, [jobData?.failed_rows, markStepCompleted])

  // Handle confirmation to proceed with partial import
  const handleConfirmPartialImport = useCallback(() => {
    setShowPartialImportDialog(false)
    markStepCompleted('validation')
    setCurrentStep('execute')
  }, [markStepCompleted])

  // Handle execute step
  const handleExecute = useCallback(() => {
    if (!jobId) return

    executeImport.mutate(jobId, {
      onSuccess: (response) => {
        // Cast to any to access import_result from extended response
        const fullResponse = response as typeof response & {
          import_result?: {
            imported_count: number
            skipped_count: number
            execution_error_count: number
            total_rows: number
            failed_rows_csv_url: string | null
          }
        }

        // Capture import results if present (synchronous import)
        if (fullResponse.import_result) {
          setImportResults(fullResponse.import_result)
        }

        // Check if import completed synchronously (small imports < 100 rows)
        if (response.status === 'completed' || response.status === 'failed') {
          // Import finished synchronously - go directly to complete step
          setIsImporting(false)
          markStepCompleted('execute')
          setCurrentStep('complete')
        } else {
          // Import is async (pending/importing) - start polling for updates
          setIsImporting(true)
          // Also populate the progress store with initial state so GlobalImportProgress shows
          updateProgress({
            import_job_id: response.id,
            status: response.status as 'pending' | 'validating' | 'validated' | 'importing' | 'completed' | 'failed',
            total_rows: response.total_rows,
            processed_rows: response.processed_rows,
            successful_rows: response.successful_rows,
            failed_rows: response.failed_rows,
            progress_percentage: response.progress_percentage,
            import_type: response.type,
            original_filename: response.original_filename,
          })
          markStepCompleted('execute')
        }
      },
    })
  }, [jobId, executeImport, markStepCompleted, updateProgress])


  // Check if mapping is valid
  const isMappingValid = useMemo(() => {
    const targetCols = TARGET_COLUMNS[importType]
    const requiredCols = targetCols.filter((c) => c.required).map((c) => c.name)
    const mappedTargets = new Set(Object.values(columnMapping))
    return requiredCols.every((col) => mappedTargets.has(col))
  }, [importType, columnMapping])

  // Render step content
  const renderStepContent = () => {
    switch (currentStep) {
      case 'upload':
        return (
          <div className="space-y-6">
            <div>
              <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
                {t('wizard.upload.title')}
              </h2>
              <p className={`mt-1 text-sm ${colorTokens.text.muted}`}>
                {t('wizard.upload.description')}
              </p>
            </div>

            <FileUpload
              onFileSelect={handleFileSelect}
              accept=".csv,.xlsx,.xls"
              maxSize={10 * 1024 * 1024}
            />

            {selectedFile && sourceColumns.length > 0 && (
              <div className={`rounded-lg ${colorTokens.intent.success.bgSubtle} p-4`}>
                <div className="flex items-center gap-2">
                  <CheckCircle className={`h-5 w-5 ${colorTokens.intent.success.text}`} />
                  <span className={`font-medium ${colorTokens.intent.success.textStrongest}`}>
                    {t('wizard.upload.fileReady', { name: selectedFile.name })}
                  </span>
                </div>
                <p className={`mt-1 text-sm ${colorTokens.intent.success.textStrong}`}>
                  {t('wizard.upload.columnsDetected', { count: sourceColumns.length })}
                </p>
              </div>
            )}

            <div className={`flex items-center justify-between border-t ${colorTokens.border.subtle} pt-4`}>
              <button
                type="button"
                onClick={() => authenticatedDownload(
                  importApi.downloadTemplateUrl(importType),
                  `${importType}_template.csv`
                )}
                className={`text-sm ${colorTokens.intent.primary.text} ${colorTokens.intent.primary.textHoverStronger}`}
              >
                {t('wizard.upload.downloadTemplate')}
              </button>
              <button
                type="button"
                onClick={handleUploadComplete}
                disabled={!selectedFile || sourceColumns.length === 0}
                className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrongHover} disabled:cursor-not-allowed ${colorTokens.surface.disabledWhenDisabled}`}
              >
                {t('common:actions.next')}
                <ArrowRight className="h-4 w-4" />
              </button>
            </div>
          </div>
        )

      case 'mapping':
        return (
          <div className="space-y-6">
            <div>
              <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
                {t('wizard.mapping.title')}
              </h2>
              <p className={`mt-1 text-sm ${colorTokens.text.muted}`}>
                {t('wizard.mapping.description')}
              </p>
            </div>

            <ColumnMapper
              sourceColumns={sourceColumns}
              targetColumns={TARGET_COLUMNS[importType]}
              suggestions={suggestions}
              mapping={columnMapping}
              onMappingChange={setColumnMapping}
            />

            <div className={`flex items-center justify-between border-t ${colorTokens.border.subtle} pt-4`}>
              <button
                type="button"
                onClick={() => { setCurrentStep('upload'); }}
                className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.intent.neutral.textHoverStrongest}`}
              >
                <ArrowLeft className="h-4 w-4" />
                {t('common:actions.back')}
              </button>
              <button
                type="button"
                onClick={handleMappingComplete}
                disabled={!isMappingValid || createImport.isPending}
                className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrongHover} disabled:cursor-not-allowed ${colorTokens.surface.disabledWhenDisabled}`}
              >
                {createImport.isPending ? (
                  <>
                    <Loader2 className="h-4 w-4 animate-spin" />
                    {t('wizard.mapping.validating')}
                  </>
                ) : (
                  <>
                    {t('wizard.mapping.validate')}
                    <ArrowRight className="h-4 w-4" />
                  </>
                )}
              </button>
            </div>
          </div>
        )

      case 'options':
        return (
          <div className="space-y-6">
            <div>
              <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
                {t('options.priceAuthorityTitle')}
              </h2>
              <p className={`mt-1 text-sm ${colorTokens.text.muted}`}>
                {t('options.priceAuthorityHint')}
              </p>
            </div>

            <fieldset className="space-y-3">
              {(['ttc', 'ht', 'margin'] as const).map((authority) => (
                <label
                  key={authority}
                  className={`flex cursor-pointer items-center gap-3 rounded-lg border ${colorTokens.border.subtle} p-3 text-sm ${colorTokens.text.strong} ${colorTokens.intent.neutral.bgHover}`}
                >
                  <input
                    type="radio"
                    name="price_authority"
                    value={authority}
                    checked={priceAuthority === authority}
                    onChange={() => { setPriceAuthority(authority) }}
                    className={`h-4 w-4 ${colorTokens.border.default} ${colorTokens.intent.primary.text} ${colorTokens.focus.primaryRing}`}
                  />
                  <span>{t(`options.priceAuthority.${authority}`)}</span>
                </label>
              ))}
            </fieldset>

            <div className={`flex items-center justify-between border-t ${colorTokens.border.subtle} pt-4`}>
              <button
                type="button"
                onClick={() => { setCurrentStep('mapping'); }}
                className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.intent.neutral.textHoverStrongest}`}
              >
                <ArrowLeft className="h-4 w-4" />
                {t('common:actions.back')}
              </button>
              <button
                type="button"
                onClick={() => { void handleOptionsComplete() }}
                className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrongHover}`}
              >
                {t('common:actions.next')}
                <ArrowRight className="h-4 w-4" />
              </button>
            </div>
          </div>
        )

      case 'validation':
        return (
          <div className="space-y-6">
            <div>
              <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
                {t('wizard.validation.title')}
              </h2>
              <p className={`mt-1 text-sm ${colorTokens.text.muted}`}>
                {t('wizard.validation.description')}
              </p>
            </div>

            {/* Data Preview Table */}
            {isPreviewLoading && (
              <div className={`flex items-center justify-center gap-2 py-8 ${colorTokens.text.subtle}`}>
                <Loader2 className="h-5 w-5 animate-spin" />
                <span>{t('preview.loading')}</span>
              </div>
            )}

            {isPreviewError && (
              <div className={`flex items-center gap-2 rounded-lg border ${colorTokens.intent.danger.borderSubtleSoft} ${colorTokens.intent.danger.bgSubtle} px-4 py-3 text-sm ${colorTokens.intent.danger.textStrong}`}>
                <XCircle className="h-5 w-5" />
                <span>{t('preview.loadError')}</span>
              </div>
            )}

            {!isPreviewLoading && !isPreviewError && previewData && (
              <div className="space-y-2">
                <h3 className={`text-sm font-medium ${colorTokens.text.secondary}`}>
                  {t('preview.title')}
                </h3>
                <ImportPreviewTable preview={previewData} />
              </div>
            )}

            {/* Validation Errors Grid */}
            {validationRows.length > 0 && (
              <div className="space-y-2">
                <h3 className={`text-sm font-medium ${colorTokens.text.secondary}`}>
                  {t('validation.errorsTitle')}
                </h3>
                <ValidationGrid
                  rows={validationRows}
                  showOnlyErrors
                />
              </div>
            )}

            <div className={`flex items-center justify-between border-t ${colorTokens.border.subtle} pt-4`}>
              <button
                type="button"
                onClick={() => { setCurrentStep(shouldShowOptionsStep ? 'options' : 'mapping'); }}
                className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.intent.neutral.textHoverStrongest}`}
              >
                <ArrowLeft className="h-4 w-4" />
                {t('common:actions.back')}
              </button>
              <button
                type="button"
                onClick={handleValidationComplete}
                className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrongHover}`}
              >
                {t('wizard.validation.proceed')}
                <ArrowRight className="h-4 w-4" />
              </button>
            </div>
          </div>
        )

      case 'execute':
        return (
          <div className="space-y-6">
            <div>
              <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
                {t('wizard.execute.title')}
              </h2>
              <p className={`mt-1 text-sm ${colorTokens.text.muted}`}>
                {t('wizard.execute.description')}
              </p>
            </div>

            {/* Summary before execution */}
            <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
              <h3 className={`font-medium ${colorTokens.text.primary} mb-4`}>
                {t('wizard.execute.summary')}
              </h3>
              <dl className="space-y-3">
                <div className="flex justify-between">
                  <dt className={`text-sm ${colorTokens.text.subtle}`}>{t('wizard.execute.file')}</dt>
                  <dd className={`text-sm font-medium ${colorTokens.text.primary}`}>{selectedFile?.name}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className={`text-sm ${colorTokens.text.subtle}`}>{t('wizard.execute.totalRows')}</dt>
                  <dd className={`text-sm font-medium ${colorTokens.text.primary}`}>{jobData?.total_rows ?? 0}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className={`text-sm ${colorTokens.text.subtle}`}>{t('wizard.execute.validRows')}</dt>
                  <dd className={`text-sm font-medium ${colorTokens.intent.success.text}`}>
                    {(jobData?.total_rows ?? 0) - (jobData?.failed_rows ?? 0)}
                  </dd>
                </div>
                <div className="flex justify-between">
                  <dt className={`text-sm ${colorTokens.text.subtle}`}>{t('wizard.execute.invalidRows')}</dt>
                  <dd className={`text-sm font-medium ${colorTokens.intent.danger.text}`}>
                    {jobData?.failed_rows ?? 0}
                  </dd>
                </div>
              </dl>
            </div>

            {/* Progress during execution */}
            {jobData && (jobData.status === 'importing' || jobData.status === 'completed' || jobData.status === 'failed') && (
              <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.page} p-6`}>
                <div className="flex items-center gap-3 mb-4">
                  {jobData.status === 'importing' && (
                    <>
                      <Loader2 className={`h-5 w-5 animate-spin ${colorTokens.intent.primary.text}`} />
                      <span className={`font-medium ${colorTokens.text.primary}`}>{t('wizard.execute.importing')}</span>
                    </>
                  )}
                  {jobData.status === 'completed' && (
                    <>
                      <CheckCircle className={`h-5 w-5 ${colorTokens.intent.success.text}`} />
                      <span className={`font-medium ${colorTokens.intent.success.textStrongest}`}>{t('wizard.execute.completed')}</span>
                    </>
                  )}
                  {jobData.status === 'failed' && (
                    <div className="flex-1">
                      <div className="flex items-center gap-2">
                        <XCircle className={`h-5 w-5 ${colorTokens.intent.danger.text}`} />
                        <span className={`font-medium ${colorTokens.intent.danger.textStrongest}`}>{t('wizard.execute.failed')}</span>
                      </div>
                      {jobData.error_message && (
                        <p className={`mt-2 text-sm ${colorTokens.intent.danger.textStrong} ${colorTokens.intent.danger.bgSoft} rounded-md px-3 py-2`}>
                          {jobData.error_message}
                        </p>
                      )}
                    </div>
                  )}
                </div>

                {jobData.processed_rows !== undefined && (
                  <div className="space-y-2">
                    <div className="flex justify-between text-sm">
                      <span className={`${colorTokens.text.subtle}`}>{t('wizard.execute.progress')}</span>
                      <span className={`${colorTokens.text.primary}`}>
                        {jobData.processed_rows} / {jobData.total_rows}
                      </span>
                    </div>
                    <div className={`h-2 w-full rounded-full ${colorTokens.surface.subdued}`}>
                      <div
                        className={`h-2 rounded-full ${colorTokens.intent.primary.bgStrong} transition-all`}
                        style={{
                          width: `${((jobData.processed_rows ?? 0) / (jobData.total_rows ?? 1)) * 100}%`,
                        }}
                      />
                    </div>
                  </div>
                )}
              </div>
            )}

            <div className={`flex items-center justify-between border-t ${colorTokens.border.subtle} pt-4`}>
              <button
                type="button"
                onClick={() => { setCurrentStep('validation'); }}
                disabled={executeImport.isPending || jobData?.status === 'importing'}
                className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.intent.neutral.textHoverStrongest} disabled:cursor-not-allowed disabled:opacity-50`}
              >
                <ArrowLeft className="h-4 w-4" />
                {t('common:actions.back')}
              </button>

              {!jobData || jobData.status === 'validated' ? (
                <button
                  type="button"
                  onClick={handleExecute}
                  disabled={executeImport.isPending}
                  className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.success.bgStrong} px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.success.bgStrongHover} disabled:cursor-not-allowed ${colorTokens.surface.disabledWhenDisabled}`}
                >
                  {executeImport.isPending ? (
                    <>
                      <Loader2 className="h-4 w-4 animate-spin" />
                      {t('wizard.execute.starting')}
                    </>
                  ) : (
                    <>
                      <Upload className="h-4 w-4" />
                      {t('wizard.execute.start')}
                    </>
                  )}
                </button>
              ) : jobData.status === 'completed' ? (
                <button
                  type="button"
                  onClick={() => { setCurrentStep('complete'); }}
                  className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrongHover}`}
                >
                  {t('wizard.execute.viewResults')}
                  <ArrowRight className="h-4 w-4" />
                </button>
              ) : null}
            </div>
          </div>
        )

      case 'complete':
        return (
          <div className="space-y-6">
            <div className="text-center py-8">
              <CheckCircle className={`mx-auto h-16 w-16 ${colorTokens.intent.success.textSubtle}`} />
              <h2 className={`mt-4 text-2xl font-bold ${colorTokens.text.primary}`}>
                {t('wizard.complete.title')}
              </h2>
              <p className={`mt-2 ${colorTokens.text.muted}`}>
                {t('wizard.complete.description')}
              </p>
            </div>

            {/* Results summary */}
            {(jobData || importResults) && (
              <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
                <h3 className={`font-medium ${colorTokens.text.primary} mb-4`}>
                  {t('wizard.complete.results')}
                </h3>
                <dl className="grid grid-cols-2 gap-4">
                  <div className={`rounded-lg ${colorTokens.intent.success.bgSubtle} p-4`}>
                    <dt className={`text-sm ${colorTokens.intent.success.text}`}>{t('wizard.complete.imported')}</dt>
                    <dd className={`text-2xl font-bold ${colorTokens.intent.success.textStrongest}`}>
                      {importResults?.imported_count ?? jobData?.successful_rows ?? 0}
                    </dd>
                  </div>
                  <div className={`rounded-lg ${colorTokens.intent.danger.bgSubtle} p-4`}>
                    <dt className={`text-sm ${colorTokens.intent.danger.text}`}>{t('wizard.complete.failed')}</dt>
                    <dd className={`text-2xl font-bold ${colorTokens.intent.danger.textStrongest}`}>
                      {((importResults?.skipped_count ?? 0) + (importResults?.execution_error_count ?? 0)) || (jobData?.failed_rows ?? 0)}
                    </dd>
                  </div>
                </dl>

                {jobData?.id && (
                  <div className={`mt-4 flex flex-wrap gap-4 border-t ${colorTokens.border.subtle} pt-4`}>
                    <button
                      type="button"
                      onClick={() => authenticatedDownload(
                        importApi.downloadResultWorkbookUrl(jobData.id),
                        `import-${jobData.id}-result.xlsx`
                      )}
                      className={`inline-flex items-center gap-2 text-sm font-medium ${colorTokens.intent.primary.text} ${colorTokens.intent.primary.textHoverStronger}`}
                    >
                      <Download className="h-4 w-4" />
                      {t('results.downloadWorkbook')}
                    </button>
                    {importResults?.failed_rows_csv_url && (
                      <button
                        type="button"
                        onClick={() => authenticatedDownload(
                          importResults.failed_rows_csv_url!,
                          `import_failed_rows.csv`
                        )}
                        className={`inline-flex items-center gap-2 text-sm font-medium ${colorTokens.intent.primary.text} ${colorTokens.intent.primary.textHoverStronger}`}
                      >
                        <Download className="h-4 w-4" />
                        {t('wizard.complete.downloadFailedRows')}
                      </button>
                    )}
                  </div>
                )}
              </div>
            )}

            <div className={`flex items-center justify-center gap-4 border-t ${colorTokens.border.subtle} pt-6`}>
              <Link
                to="/settings/import"
                className={`inline-flex items-center gap-2 rounded-lg border ${colorTokens.border.default} ${colorTokens.surface.base} px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.intent.neutral.bgHover}`}
              >
                {t('wizard.complete.backToDashboard')}
              </Link>
              <button
                type="button"
                onClick={() => navigate(`/settings/import/${importType}`)}
                className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrongHover}`}
              >
                {t('wizard.complete.importMore')}
              </button>
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
          to="/settings/import"
          className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.intent.neutral.textHoverStrongest}`}
        >
          <ArrowLeft className="h-4 w-4" />
          {t('common:actions.back')}
        </Link>
        <div>
          <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>
            {t(`types.${importType}.title`)}
          </PageHeaderTitle>
          <p className={`${colorTokens.text.subtle}`}>
            {t(`types.${importType}.description`)}
          </p>
        </div>
      </div>

      {/* Progress indicator */}
      <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
        <ImportProgress
          steps={translatedSteps}
          currentStep={stepIndex}
          completedSteps={completedSteps}
        />
      </div>

      {/* Step content */}
      <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
        {renderStepContent()}
      </div>

      {/* Partial import confirmation dialog */}
      <ConfirmDialog
        isOpen={showPartialImportDialog}
        onClose={() => { setShowPartialImportDialog(false); }}
        onConfirm={handleConfirmPartialImport}
        title={t('wizard.confirmPartialImport')}
        message={t('wizard.partialImportDescription', {
          valid: (jobData?.total_rows ?? 0) - (jobData?.failed_rows ?? 0),
          failed: jobData?.failed_rows ?? 0
        })}
        confirmText={t('wizard.proceedWithValid')}
        cancelText={t('common:actions.cancel')}
        variant="warning"
      />
    </div>
  )
}
