import { useState, useCallback, useMemo, useEffect, useRef } from 'react'
import axios from 'axios'
import { useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import type { TFunction } from 'i18next'
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
import { isDeprecatedImportType } from '../types'
import type { DuplicatePolicy, ImportJobOptions, ImportResult, ImportType, LiveImportType, LocationNodeType } from '../types'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import { Select } from '@/components/atoms/Select/Select'
import { useScopedLocations } from '@/features/locations/hooks/useScopedLocations'
import type { ScopedLocation } from '@/features/locations/api/scopedLocations'
import { KNOWN_WARNING_CODES } from '../warningCodes'

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
const PLACEMENT_NODE_TYPES: LocationNodeType[] = ['zone', 'aisle', 'rack', 'shelf', 'bin', 'section']
const DEFAULT_PLACEMENT_DEPTH_TYPES: LocationNodeType[] = ['aisle', 'rack', 'shelf', 'bin', 'section', 'zone']

/**
 * The message `api.ts`'s response interceptor substitutes for a request that
 * never got a response. That branch is unreachable today (`isApiError` returns
 * false without a response body envelope, so the raw AxiosError falls through
 * instead) — but repairing `isApiError` into a plain axios guard is a tempting
 * one-line cleanup, and the moment it lands every network failure arrives here
 * as this bare Error with no axios shape. Recognising the sentinel keeps the
 * taxonomy correct across that change instead of silently reinstating BUG-004.
 */
const INTERCEPTOR_NETWORK_ERROR = 'network error'

/**
 * Turn a `parseHeaders` rejection into the message the operator should act on.
 *
 * The guiding rule, and the whole point of BUG-004: never assert a cause the
 * frontend cannot know. Each branch below is a cause we CAN establish.
 *
 * - 401 / 419 → the session died (401 is `UNAUTHENTICATED`; 419 is a CSRF
 *   bounce, which the interceptor's auto-retry never sees because `isApiError`
 *   rejects Laravel's bare `{"message":…}` body). `api.ts` is already
 *   redirecting to /login, so the action is "sign in again and re-upload" —
 *   NOT "contact your administrator".
 * - 413 → the file is genuinely too large for a proxy in front of the API.
 *   This is the one status where the file IS the cause, so it must not inherit
 *   the generic "try again" copy: retrying is guaranteed to fail.
 * - 422 → the backend genuinely could not parse the spreadsheet, or rejected
 *   its mime/size. `MigrationWizardController::parseHeaders` emits 422 for both
 *   and for nothing else.
 * - any other HTTP status → an infrastructure/API failure. Surface the status
 *   for support and claim NOTHING about the file.
 * - no response, or the interceptor's network sentinel → the request never
 *   completed (offline, CORS, timeout).
 */
function describeUploadFailure(
  error: unknown,
  t: TFunction<'import'>,
): string {
  if (axios.isAxiosError(error)) {
    const status = error.response?.status
    if (status === undefined) {
      return t('wizard.upload.networkError')
    }
    if (status === 401 || status === 419) {
      return t('wizard.upload.sessionExpired')
    }
    if (status === 413) {
      return t('wizard.upload.tooLarge')
    }
    if (status !== 422) {
      return t('wizard.upload.serverError', { status })
    }
    return t('wizard.upload.parseError')
  }

  if (error instanceof Error && error.message.toLowerCase() === INTERCEPTOR_NETWORK_ERROR) {
    return t('wizard.upload.networkError')
  }

  return t('wizard.upload.parseError')
}

function isPlacementMode(value: string): value is NonNullable<ImportJobOptions['placement_mode']> {
  return value === 'strict' || value === 'auto_create'
}

function isLocationNodeType(value: string): value is LocationNodeType {
  return PLACEMENT_NODE_TYPES.some((nodeType) => nodeType === value)
}

function defaultPlacementNodeType(depth: number): LocationNodeType {
  return DEFAULT_PLACEMENT_DEPTH_TYPES[depth] ?? 'section'
}

interface StockLocationOptionsProps {
  locations: ScopedLocation[]
  value: string
  onChange: (value: string) => void
}

function StockLocationOptions({ locations, value, onChange }: StockLocationOptionsProps) {
  const { t } = useTranslation('import')

  return (
    <section className="space-y-4" aria-labelledby="stock-location-import-options">
      <div>
        <h2 id="stock-location-import-options" className={`text-lg font-semibold ${colorTokens.text.primary}`}>
          {t('options.stockLocation.title')}
        </h2>
        <p className={`mt-1 text-sm ${colorTokens.text.muted}`}>
          {t('options.stockLocation.hint')}
        </p>
      </div>
      <label className={`block text-sm font-medium ${colorTokens.text.secondary}`}>
        {t('options.stockLocation.label')}
        <Select
          className="mt-1"
          value={value}
          onChange={(event) => { onChange(event.target.value) }}
        >
          <option value="" disabled>{t('options.stockLocation.select')}</option>
          {locations.map((location) => {
            const code = location.code.trim()
            return (
              <option key={location.id} value={code} disabled={code === ''}>
                {code !== ''
                  ? `${location.name} (${code})`
                  : `${location.name} — ${t('options.stockLocation.noCode')}`}
              </option>
            )
          })}
        </Select>
      </label>
    </section>
  )
}

// Target columns per import type.
// Keyed by LiveImportType, NOT ImportType: retired types are excluded from the
// key set (they can no longer be imported), while every type that IS live must
// still have an entry or this fails to compile. Do not widen this to a Partial —
// a live type with no entry would give `isMappingValid` an empty required-column
// list, i.e. "valid" with zero mappings.
const TARGET_COLUMNS: Record<LiveImportType, { name: string; required: boolean; description?: string }[]> = {
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
    // W4-1: without this entry `expiry_date` is neither auto-mapped nor
    // selectable, ColumnMapper drops it into `skippedColumns`, and
    // ImportService::applyColumnMapping() — which keeps ONLY mapped targets —
    // strips it from every row. The operator fills the column in the official
    // template and every opening lot still opens undated, with no error and no
    // mention in the result workbook.
    { name: 'expiry_date', required: false, description: 'YYYY-MM-DD lot expiry for the opening stock' },
    { name: 'placement_path', required: false, description: 'A1 > R2 > B7' },
    { name: 'barcode', required: false },
    { name: 'brand', required: false },
    { name: 'category_name', required: false, description: 'Category name (must exist)' },
    { name: 'tax_rate', required: false, description: 'Tax rate percentage' },
    { name: 'unit', required: false, description: 'Unit of measure' },
    { name: 'is_active', required: false, description: 'true/false, yes/no, 1/0' },
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
  const { data: scopedLocations = [] } = useScopedLocations()
  const locations = useMemo(
    () => scopedLocations.filter((location) => location.isActive),
    [scopedLocations],
  )

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
  const [placementMode, setPlacementMode] = useState<NonNullable<ImportJobOptions['placement_mode']>>('strict')
  const [placementNodeTypeOverrides, setPlacementNodeTypeOverrides] = useState<Record<number, LocationNodeType>>({})
  const [duplicatePolicy, setDuplicatePolicy] = useState<DuplicatePolicy>('override')
  const [selectedLocationCode, setSelectedLocationCode] = useState('')

  const stockLocationCode = useMemo(() => {
    const codedLocations = locations.filter((location) => location.code.trim() !== '')
    if (codedLocations.length === 0) {
      return ''
    }
    if (codedLocations.some((location) => location.code.trim() === selectedLocationCode)) {
      return selectedLocationCode
    }

    const fallbackLocation = codedLocations.find((location) => location.isDefault) ?? codedLocations[0]

    return fallbackLocation.code.trim()
  }, [locations, selectedLocationCode])

  // Job state
  const [jobId, setJobId] = useState<string | null>(null)

  // Dialog state for partial import confirmation
  const [showPartialImportDialog, setShowPartialImportDialog] = useState(false)
  const [showDiscardImportDialog, setShowDiscardImportDialog] = useState(false)
  const [isDiscardingImport, setIsDiscardingImport] = useState(false)
  const [isPolicyPending, setIsPolicyPending] = useState(false)
  const [policyError, setPolicyError] = useState<string | null>(null)
  const [showAllNameMatches, setShowAllNameMatches] = useState(false)

  // Import results state (from execute response)
  const [importResults, setImportResults] = useState<ImportResult | null>(null)

  // Mutations
  const createImport = useCreateImport()
  const executeImport = useExecuteImport()
  const suggestMapping = useSuggestMapping()

  // Get real-time progress from WebSocket store
  const { getImportProgress, updateProgress, completeImport } = useImportProgressStore()
  const realtimeProgress = jobId ? getImportProgress(jobId) : undefined
  const completedProgressJobsRef = useRef<Set<string>>(new Set())
  const terminalTransitionJobsRef = useRef<Set<string>>(new Set())
  const isWizardMountedRef = useRef(true)

  useEffect(() => {
    isWizardMountedRef.current = true

    return () => {
      isWizardMountedRef.current = false
    }
  }, [])

  // Track if we're actively importing (for polling fallback)
  const [isImporting, setIsImporting] = useState(false)

  // Fetch job status from API - poll during importing as fallback for WebSocket
  const shouldFetchJob = jobId !== null && (currentStep === 'validation' || currentStep === 'execute' || currentStep === 'complete')
  const { data: apiJobData, refetch: refetchJob } = useImportJob(jobId ?? '', {
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

  const optionVisibility = useMemo(() => {
    if (importType !== 'products') {
      return { prices: false, placement: false, stock: false }
    }

    const mappedPriceColumns = new Set(
      Object.values(columnMapping).filter((target) => PRODUCT_PRICE_COLUMNS.has(target))
    )

    return {
      prices: mappedPriceColumns.size >= 2,
      placement: Object.values(columnMapping).includes('placement_path'),
      stock: Object.values(columnMapping).includes('quantity'),
    }
  }, [columnMapping, importType])

  const shouldShowOptionsStep = optionVisibility.prices || optionVisibility.placement || optionVisibility.stock

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
    if (
      jobId
      && (status === 'completed' || status === 'failed')
      && currentStep === 'execute'
      && !terminalTransitionJobsRef.current.has(jobId)
    ) {
      terminalTransitionJobsRef.current.add(jobId)
      const enterCompleteStep = () => {
        if (!isWizardMountedRef.current) {
          return
        }

        setIsImporting(false)
        markStepCompleted('execute')
        setCurrentStep('complete')
      }
      if (realtimeProgress?.status === 'completed' || realtimeProgress?.status === 'failed') {
        void refetchJob().then((result) => {
          if (result.isError) {
            console.error('Import wizard: final job refetch failed', result.error)
          }

          enterCompleteStep()
        }, (error: unknown) => {
          console.error('Import wizard: final job refetch failed', error)
          enterCompleteStep()
        })
      } else {
        enterCompleteStep()
      }
    }
  }, [realtimeProgress?.status, apiJobData?.status, currentStep, jobId, markStepCompleted, refetchJob])

  // Fetch validation errors when on validation step
  const { data: errorsData } = useImportErrors(
    currentStep === 'validation' && jobId ? jobId : ''
  )
  const validationRows = errorsData?.data ?? []

  // Fetch preview data when on validation step
  const {
    data: previewData,
    isLoading: isPreviewLoading,
    isError: isPreviewError,
    refetch: refetchPreview,
  } = useImportPreview(
    jobId && (
      currentStep === 'validation'
      || (currentStep === 'options' && optionVisibility.placement)
    ) ? jobId : ''
  )
  const placementDepthCount = Math.max(previewData?.placement?.max_depth ?? 1, 1)
  const placementNodeTypes = useMemo(
    () => Array.from(
      { length: placementDepthCount },
      (_, depth) => placementNodeTypeOverrides[depth] ?? defaultPlacementNodeType(depth),
    ),
    [placementDepthCount, placementNodeTypeOverrides],
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
    } catch (uploadError: unknown) {
      // BUG-004: a bare `catch {}` used to map EVERY failure — 500, nginx 413,
      // CSRF bounce, dropped connection — to "invalid file", which is what hid
      // the real causes of BUG-001/BUG-002 for days. Only a 422 from
      // MigrationWizardController::parseHeaders actually means the file could
      // not be parsed; anything else is an infrastructure problem the operator
      // must not be blamed for.
      console.error('Import wizard: parse-headers failed', uploadError)
      toast.error(describeUploadFailure(uploadError, t))
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

    await importApi.updateOptions(jobId, {
      ...(optionVisibility.prices ? { price_authority: priceAuthority } : {}),
      ...(optionVisibility.placement ? {
        placement_mode: placementMode,
        ...(placementMode === 'auto_create' ? { placement_node_types: placementNodeTypes } : {}),
      } : {}),
      ...(optionVisibility.stock && stockLocationCode !== ''
        ? { location_code: stockLocationCode }
        : {}),
    })
    await refetchPreview()
    markStepCompleted('options')
    setCurrentStep('validation')
  }, [jobId, markStepCompleted, optionVisibility, placementMode, placementNodeTypes, priceAuthority, refetchPreview, stockLocationCode])

  // Handle validation step completion
  const handleDuplicatePolicyChange = useCallback((policy: DuplicatePolicy | 'cancel') => {
    if (policy === 'cancel') {
      setShowDiscardImportDialog(true)
      return
    }

    setDuplicatePolicy(policy)
    setPolicyError(null)
  }, [])

  const handleValidationComplete = useCallback(async () => {
    if (isPolicyPending) return

    setPolicyError(null)
    if (jobId && previewData?.duplicates) {
      setIsPolicyPending(true)
      try {
        await importApi.updateOptions(jobId, { duplicate_policy: duplicatePolicy })
      } catch {
        setPolicyError(t('duplicates.policy.persistenceError'))
        return
      } finally {
        setIsPolicyPending(false)
      }
    }
    const hasErrors = (jobData?.failed_rows ?? 0) > 0
    if (hasErrors) {
      // Show dialog to confirm partial import
      setShowPartialImportDialog(true)
      return
    }

    markStepCompleted('validation')
    setCurrentStep('execute')
  }, [duplicatePolicy, isPolicyPending, jobData?.failed_rows, jobId, markStepCompleted, previewData?.duplicates, t])

  const handleDiscardImport = useCallback(async () => {
    if (!jobId || isDiscardingImport) return

    setIsDiscardingImport(true)
    try {
      // G-6a owns the DELETE route; this lane consumes the existing client seam.
      await importApi.deleteJob(jobId)
      navigate('/settings/import')
    } catch {
      toast.error(t('messages.deleteError'))
    } finally {
      setIsDiscardingImport(false)
    }
  }, [isDiscardingImport, jobId, navigate, t])

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
        // Capture import results if present (synchronous import)
        if (response.import_result) {
          setImportResults(response.import_result)
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
    // A retired type can never be mapped — and must not fall through to
    // `every()` over an empty required list, which would report "valid".
    if (isDeprecatedImportType(importType)) return false

    const requiredCols = TARGET_COLUMNS[importType].flatMap((c) => (c.required ? [c.name] : []))
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

            {/*
              SIZE BOUNDARY — keep this equal to the server rule. Client
              10 * 1024 * 1024 = 10,485,760 B; server `max:10240` (KB) in
              MigrationWizardController::parseHeaders = 10,485,760 B. They are
              EXACTLY equal today, which is why an oversize file is stopped
              here and never produces a server 422.

              If the two ever diverge, a gap band opens: files inside it pass
              the client check, get a Laravel mime/size 422, and — because 422
              maps to `parseError` — the operator is told "Could not read the
              file. Please upload a valid CSV or Excel file." That is BUG-004
              reinstated for the most common large-import case. Change one side,
              change the other (or key off `error.code`; see the PARSE_FAILED
              follow-up in
              docs/superpowers/tickets/2026-08-06-l6-partners-followups.md).
            */}
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
                data-testid="import-wizard-next"
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
              targetColumns={isDeprecatedImportType(importType) ? [] : TARGET_COLUMNS[importType]}
              suggestions={suggestions}
              mapping={columnMapping}
              onMappingChange={setColumnMapping}
            />

            <div className={`flex items-center justify-between border-t ${colorTokens.border.subtle} pt-4`}>
              <button
                data-testid="import-wizard-back"
                type="button"
                onClick={() => { setCurrentStep('upload'); }}
                className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.intent.neutral.textHoverStrongest}`}
              >
                <ArrowLeft className="h-4 w-4" />
                {t('common:actions.back')}
              </button>
              <button
                data-testid="import-wizard-validate"
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
            {optionVisibility.prices && <div>
              <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
                {t('options.priceAuthorityTitle')}
              </h2>
              <p className={`mt-1 text-sm ${colorTokens.text.muted}`}>
                {t('options.priceAuthorityHint')}
              </p>
            </div>}

            {optionVisibility.prices && <fieldset className="space-y-3">
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
            </fieldset>}

            {optionVisibility.placement && (
              <section className="space-y-4" aria-labelledby="placement-import-options">
                <div>
                  <h2 id="placement-import-options" className={`text-lg font-semibold ${colorTokens.text.primary}`}>
                    {t('options.placementTitle')}
                  </h2>
                  <p className={`mt-1 text-sm ${colorTokens.text.muted}`}>{t('options.placementHint')}</p>
                </div>
                <label className={`block text-sm font-medium ${colorTokens.text.secondary}`}>
                  {t('options.placementMode')}
                  <Select
                    className="mt-1"
                    value={placementMode}
                    onChange={(event) => {
                      if (isPlacementMode(event.target.value)) {
                        setPlacementMode(event.target.value)
                      }
                    }}
                  >
                    <option value="strict">{t('options.placementModes.strict')}</option>
                    <option value="auto_create">{t('options.placementModes.autoCreate')}</option>
                  </Select>
                </label>
                {placementMode === 'auto_create' && (
                  <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {placementNodeTypes.map((type, depth) => (
                      <label key={String(depth)} className={`block text-sm font-medium ${colorTokens.text.secondary}`}>
                        {t('options.placementDepth', { depth: depth + 1 })}
                        <Select
                          className="mt-1"
                          value={type}
                          onChange={(event) => {
                            const nodeType = event.target.value
                            if (!isLocationNodeType(nodeType)) {
                              return
                            }
                            setPlacementNodeTypeOverrides((current) => ({
                              ...current,
                              [depth]: nodeType,
                            }))
                          }}
                        >
                          {PLACEMENT_NODE_TYPES.map((nodeType) => (
                            <option key={nodeType} value={nodeType}>{t(`options.nodeTypes.${nodeType}`)}</option>
                          ))}
                        </Select>
                      </label>
                    ))}
                  </div>
                )}
              </section>
            )}

            {optionVisibility.stock && (
              <StockLocationOptions
                locations={locations}
                value={stockLocationCode}
                onChange={setSelectedLocationCode}
              />
            )}

            <div className={`flex items-center justify-between border-t ${colorTokens.border.subtle} pt-4`}>
              <button
                data-testid="import-wizard-back"
                type="button"
                onClick={() => { setCurrentStep('mapping'); }}
                className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.intent.neutral.textHoverStrongest}`}
              >
                <ArrowLeft className="h-4 w-4" />
                {t('common:actions.back')}
              </button>
              <button
                data-testid="import-wizard-next"
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
              <div className={`flex items-center gap-2 rounded-lg border ${colorTokens.intent.danger.borderSubtle} ${colorTokens.intent.danger.bgSubtle} px-4 py-3 text-sm ${colorTokens.intent.danger.textStrong}`}>
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

            {!isPreviewLoading && !isPreviewError && previewData?.duplicates && (
              <section
                data-testid="import-preview-duplicate-summary"
                className={`space-y-4 rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.page} p-4`}
                aria-labelledby="import-duplicate-summary-title"
              >
                <div>
                  <h3 id="import-duplicate-summary-title" className={`font-semibold ${colorTokens.text.primary}`}>
                    {t('duplicates.title')}
                  </h3>
                  <p className={`mt-1 text-sm ${colorTokens.text.muted}`}>
                    {t('duplicates.summary', {
                      existing: previewData.duplicates.counts.existing_sku
                        + previewData.duplicates.counts.existing_barcode
                        + previewData.duplicates.counts.existing_name,
                      inFile: previewData.duplicates.counts.in_file,
                    })}
                  </p>
                </div>
                <dl className="grid gap-2 sm:grid-cols-5">
                  {(['new', 'existing_sku', 'existing_barcode', 'existing_name', 'in_file'] as const).map((bucket) => (
                    <div key={bucket} className={`rounded-md ${colorTokens.surface.base} p-3`}>
                      <dt className={`text-xs ${colorTokens.text.muted}`}>{t(`duplicates.bucket.${bucket}`)}</dt>
                      <dd className={`text-lg font-semibold ${colorTokens.text.primary}`}>
                        {previewData.duplicates?.counts[bucket] ?? 0}
                      </dd>
                    </div>
                  ))}
                </dl>
                <p className={`text-sm ${colorTokens.text.secondary}`}>{t('duplicates.blankCells')}</p>
                <p className={`text-sm ${colorTokens.text.secondary}`}>{t('duplicates.lastRowWins')}</p>
                {previewData.duplicates.matched_by_name.length > 0 && (
                  <div className={`rounded-md ${colorTokens.intent.warning.bgSubtle} p-3 text-sm ${colorTokens.intent.warning.textStronger}`}>
                    <p>{t('duplicates.matchedByName', { count: previewData.duplicates.matched_by_name.length })}</p>
                    <p className="mt-1 font-mono">
                      {(showAllNameMatches
                        ? previewData.duplicates.matched_by_name
                        : previewData.duplicates.matched_by_name.slice(0, 10)).join(', ')}
                    </p>
                    {previewData.duplicates.matched_by_name.length > 10 && (
                      <button
                        type="button"
                        onClick={() => { setShowAllNameMatches((shown) => !shown) }}
                        className={`mt-2 font-medium ${colorTokens.intent.primary.text} ${colorTokens.intent.primary.textHoverStronger}`}
                      >
                        {t(showAllNameMatches ? 'duplicates.showLess' : 'duplicates.showAll')}
                      </button>
                    )}
                  </div>
                )}
                <fieldset className="space-y-2">
                  <legend className={`text-sm font-medium ${colorTokens.text.primary}`}>{t('duplicates.policy.title')}</legend>
                  {(['override', 'skip', 'cancel'] as const).map((policy) => (
                    <label key={policy} className={`flex cursor-pointer items-start gap-3 rounded-lg border ${colorTokens.border.subtle} p-3 ${colorTokens.intent.neutral.bgHover}`}>
                      <input
                        data-testid={`import-preview-policy-${policy}`}
                        type="radio"
                        name="duplicate_policy"
                        value={policy}
                        checked={policy !== 'cancel' && duplicatePolicy === policy}
                        onChange={() => { handleDuplicatePolicyChange(policy) }}
                        disabled={isPolicyPending}
                        className={`mt-0.5 h-4 w-4 ${colorTokens.border.default} ${colorTokens.intent.primary.text} ${colorTokens.focus.primaryRing}`}
                      />
                      <span>
                        <span className={`block text-sm font-medium ${colorTokens.text.primary}`}>{t(`duplicates.policy.${policy}.label`)}</span>
                        <span className={`block text-xs ${colorTokens.text.muted}`}>{t(`duplicates.policy.${policy}.description`)}</span>
                      </span>
                    </label>
                  ))}
                </fieldset>
                {policyError && (
                  <p role="alert" className={`text-sm ${colorTokens.intent.danger.textStrong}`}>{policyError}</p>
                )}
              </section>
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
                data-testid="import-wizard-back"
                type="button"
                onClick={() => { setCurrentStep(shouldShowOptionsStep ? 'options' : 'mapping'); }}
                className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.intent.neutral.textHoverStrongest}`}
              >
                <ArrowLeft className="h-4 w-4" />
                {t('common:actions.back')}
              </button>
              <button
                data-testid="import-wizard-next"
                type="button"
                onClick={() => { void handleValidationComplete() }}
                disabled={isPolicyPending}
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
                      <span className={colorTokens.text.subtle}>{t('wizard.execute.progress')}</span>
                      <span className={colorTokens.text.primary}>
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
                data-testid="import-wizard-back"
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
                  data-testid="import-wizard-execute"
                  type="button"
                  onClick={handleExecute}
                  disabled={executeImport.isPending || isPolicyPending}
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

                {(jobData?.warning_rows ?? 0) > 0 && (
                  <section
                    className={`mt-4 rounded-lg border ${colorTokens.intent.warning.borderSubtle} ${colorTokens.intent.warning.bgSubtle} p-4`}
                    aria-labelledby="import-warning-summary"
                  >
                    <h4 id="import-warning-summary" className={`font-semibold ${colorTokens.intent.warning.textStrongest}`}>
                      {t('wizard.complete.warnings', { count: jobData?.warning_rows ?? 0 })}
                    </h4>
                    <ul className={`mt-2 space-y-1 text-sm ${colorTokens.intent.warning.textStronger}`}>
                      {Object.entries(jobData?.warning_summary ?? {})
                        .filter(([, count]) => count > 0)
                        .map(([code, count]) => (
                          <li key={code}>
                            {KNOWN_WARNING_CODES.has(code)
                              ? t(`warnings.${code}`, { count })
                              : t('warnings.other', { code, count })}
                          </li>
                        ))}
                    </ul>
                  </section>
                )}

                {jobData?.id && (
                  <div className={`mt-4 flex flex-wrap gap-4 border-t ${colorTokens.border.subtle} pt-4`}>
                    <button
                      data-testid="import-complete-download-workbook"
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
                        data-testid="import-complete-download-rows_export_csv"
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

  // Retired import types (owner ruling D4) are unreachable from the dashboard,
  // but a bookmarked URL still lands here. Say why and point at the replacement
  // rather than showing an upload form the API would refuse.
  // Placed after every hook so the hook order stays stable.
  if (isDeprecatedImportType(importType)) {
    return (
      <div className="space-y-6">
        <div className="flex items-center gap-4">
          <Link
            to="/settings/import"
            className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.intent.neutral.textHoverStrongest}`}
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:actions.back')}
          </Link>
          <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>
            {t('wizard.retired.title')}
          </PageHeaderTitle>
        </div>

        <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
          <p className={colorTokens.text.secondary}>{t('wizard.retired.description')}</p>
          <Link
            to="/settings/import/products"
            className={`mt-4 inline-flex items-center gap-1.5 rounded-lg ${colorTokens.intent.primary.bgStrong} px-3 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrongHover}`}
          >
            {t('wizard.retired.goToProducts')}
            <ArrowRight className="h-4 w-4" />
          </Link>
        </div>
      </div>
    )
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
          <p className={colorTokens.text.subtle}>
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
      <div
        data-testid={`import-wizard-step-${currentStep === 'validation' ? 'preview' : currentStep}`}
        className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}
      >
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
      <ConfirmDialog
        isOpen={showDiscardImportDialog}
        onClose={() => { setShowDiscardImportDialog(false) }}
        onConfirm={() => { void handleDiscardImport() }}
        title={t('duplicates.discard.title')}
        message={t('duplicates.discard.message')}
        confirmText={t('duplicates.discard.confirm')}
        cancelText={t('duplicates.discard.keep')}
        variant="danger"
        isLoading={isDiscardingImport}
      />
    </div>
  )
}
