import { useState, useCallback, useMemo } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, ArrowRight, Upload, Loader2, CheckCircle, XCircle } from 'lucide-react'
import { Link } from 'react-router-dom'
import {
  FileUpload,
  ColumnMapper,
  ValidationGrid,
  ImportProgress,
} from '../components'
import {
  useCreateImport,
  useExecuteImport,
  useSuggestMapping,
  useImportJob,
  useImportErrors,
} from '../api/queries'
import { importApi } from '../api/importApi'
import type { ImportType } from '../types'

type WizardStep = 'upload' | 'mapping' | 'validation' | 'execute' | 'complete'

const STEPS: { key: WizardStep; label: string }[] = [
  { key: 'upload', label: 'wizard.steps.upload' },
  { key: 'mapping', label: 'wizard.steps.mapping' },
  { key: 'validation', label: 'wizard.steps.validation' },
  { key: 'execute', label: 'wizard.steps.execute' },
  { key: 'complete', label: 'wizard.steps.complete' },
]

// Target columns per import type
const TARGET_COLUMNS: Record<ImportType, { name: string; required: boolean; description?: string }[]> = {
  partners: [
    { name: 'name', required: true, description: 'Partner name' },
    { name: 'type', required: true, description: 'customer or supplier' },
    { name: 'email', required: false },
    { name: 'phone', required: false },
    { name: 'tax_id', required: false },
    { name: 'address_line1', required: false },
    { name: 'address_city', required: false },
    { name: 'address_postal_code', required: false },
    { name: 'address_country', required: false },
    { name: 'notes', required: false },
  ],
  products: [
    { name: 'sku', required: true, description: 'Unique product code' },
    { name: 'name', required: true },
    { name: 'type', required: true, description: 'goods or service' },
    { name: 'unit_price', required: true },
    { name: 'cost_price', required: false },
    { name: 'category', required: false },
    { name: 'brand', required: false },
    { name: 'barcode', required: false },
    { name: 'tax_rate', required: false },
    { name: 'description', required: false },
  ],
  stock_levels: [
    { name: 'sku', required: true, description: 'Product SKU' },
    { name: 'location', required: true, description: 'Warehouse location' },
    { name: 'quantity', required: true },
    { name: 'unit_cost', required: false },
    { name: 'min_stock', required: false },
    { name: 'max_stock', required: false },
  ],
  opening_balances: [
    { name: 'account_code', required: true, description: 'GL account code' },
    { name: 'debit', required: false },
    { name: 'credit', required: false },
    { name: 'currency', required: false },
    { name: 'reference', required: false },
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

  // Job state
  const [jobId, setJobId] = useState<number | null>(null)

  // Mutations
  const createImport = useCreateImport()
  const executeImport = useExecuteImport()
  const suggestMapping = useSuggestMapping()

  // Poll job status when executing
  const isPolling = jobId !== null && (currentStep === 'execute' || currentStep === 'complete')
  const { data: jobData } = useImportJob(jobId ?? 0, {
    enabled: isPolling,
    refetchInterval: isPolling ? 2000 : false, // Poll every 2 seconds while on execute/complete step
  })

  // Fetch validation errors when on validation step
  const { data: errorsData } = useImportErrors(jobId ?? 0)
  const validationRows = errorsData?.data ?? []

  // Get step index
  const stepIndex = useMemo(() => {
    return STEPS.findIndex((s) => s.key === currentStep)
  }, [currentStep])

  // Translated steps
  const translatedSteps = useMemo(() => {
    return STEPS.map((s) => ({
      key: s.key,
      label: t(s.label),
    }))
  }, [t])

  // Handle file selection
  const handleFileSelect = useCallback(async (file: File) => {
    setSelectedFile(file)

    // Parse CSV headers
    const text = await file.text()
    const lines = text.split('\n')
    if (lines.length > 0) {
      const headers = lines[0].split(',').map((h) => h.trim().replace(/^"|"$/g, ''))
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
    }
  }, [importType, suggestMapping])

  // Handle upload step completion
  const handleUploadComplete = useCallback(() => {
    if (!selectedFile || sourceColumns.length === 0) return

    setCompletedSteps((prev) => [...prev, 0])
    setCurrentStep('mapping')
  }, [selectedFile, sourceColumns])

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
          setCompletedSteps((prev) => [...prev, 1])
          setCurrentStep('validation')
        },
      }
    )
  }, [selectedFile, importType, columnMapping, createImport])

  // Handle validation step completion
  const handleValidationComplete = useCallback(() => {
    const hasErrors = validationRows.some((row) => !row.is_valid)
    if (hasErrors) {
      // Show warning but allow proceeding
      if (!window.confirm(t('wizard.proceedWithErrors'))) {
        return
      }
    }

    setCompletedSteps((prev) => [...prev, 2])
    setCurrentStep('execute')
  }, [validationRows, t])

  // Handle execute step
  const handleExecute = useCallback(() => {
    if (!jobId) return

    executeImport.mutate(jobId, {
      onSuccess: () => {
        setCompletedSteps((prev) => [...prev, 3])
      },
    })
  }, [jobId, executeImport])


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
              <h2 className="text-lg font-semibold text-gray-900">
                {t('wizard.upload.title')}
              </h2>
              <p className="mt-1 text-sm text-gray-600">
                {t('wizard.upload.description')}
              </p>
            </div>

            <FileUpload
              onFileSelect={handleFileSelect}
              accept=".csv,.xlsx,.xls"
              maxSize={10 * 1024 * 1024}
            />

            {selectedFile && sourceColumns.length > 0 && (
              <div className="rounded-lg bg-green-50 p-4">
                <div className="flex items-center gap-2">
                  <CheckCircle className="h-5 w-5 text-green-600" />
                  <span className="font-medium text-green-900">
                    {t('wizard.upload.fileReady', { name: selectedFile.name })}
                  </span>
                </div>
                <p className="mt-1 text-sm text-green-700">
                  {t('wizard.upload.columnsDetected', { count: sourceColumns.length })}
                </p>
              </div>
            )}

            <div className="flex items-center justify-between border-t border-gray-200 pt-4">
              <a
                href={importApi.downloadTemplateUrl(importType)}
                download
                className="text-sm text-blue-600 hover:text-blue-800"
              >
                {t('wizard.upload.downloadTemplate')}
              </a>
              <button
                type="button"
                onClick={handleUploadComplete}
                disabled={!selectedFile || sourceColumns.length === 0}
                className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-300"
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
              <h2 className="text-lg font-semibold text-gray-900">
                {t('wizard.mapping.title')}
              </h2>
              <p className="mt-1 text-sm text-gray-600">
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

            <div className="flex items-center justify-between border-t border-gray-200 pt-4">
              <button
                type="button"
                onClick={() => { setCurrentStep('upload'); }}
                className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
              >
                <ArrowLeft className="h-4 w-4" />
                {t('common:actions.back')}
              </button>
              <button
                type="button"
                onClick={handleMappingComplete}
                disabled={!isMappingValid || createImport.isPending}
                className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-300"
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

      case 'validation':
        return (
          <div className="space-y-6">
            <div>
              <h2 className="text-lg font-semibold text-gray-900">
                {t('wizard.validation.title')}
              </h2>
              <p className="mt-1 text-sm text-gray-600">
                {t('wizard.validation.description')}
              </p>
            </div>

            <ValidationGrid
              rows={validationRows}
              showOnlyErrors
            />

            <div className="flex items-center justify-between border-t border-gray-200 pt-4">
              <button
                type="button"
                onClick={() => { setCurrentStep('mapping'); }}
                className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
              >
                <ArrowLeft className="h-4 w-4" />
                {t('common:actions.back')}
              </button>
              <button
                type="button"
                onClick={handleValidationComplete}
                className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
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
              <h2 className="text-lg font-semibold text-gray-900">
                {t('wizard.execute.title')}
              </h2>
              <p className="mt-1 text-sm text-gray-600">
                {t('wizard.execute.description')}
              </p>
            </div>

            {/* Summary before execution */}
            <div className="rounded-lg border border-gray-200 bg-white p-6">
              <h3 className="font-medium text-gray-900 mb-4">
                {t('wizard.execute.summary')}
              </h3>
              <dl className="space-y-3">
                <div className="flex justify-between">
                  <dt className="text-sm text-gray-500">{t('wizard.execute.file')}</dt>
                  <dd className="text-sm font-medium text-gray-900">{selectedFile?.name}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-sm text-gray-500">{t('wizard.execute.totalRows')}</dt>
                  <dd className="text-sm font-medium text-gray-900">{validationRows.length}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-sm text-gray-500">{t('wizard.execute.validRows')}</dt>
                  <dd className="text-sm font-medium text-green-600">
                    {validationRows.filter((r) => r.is_valid).length}
                  </dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-sm text-gray-500">{t('wizard.execute.invalidRows')}</dt>
                  <dd className="text-sm font-medium text-red-600">
                    {validationRows.filter((r) => !r.is_valid).length}
                  </dd>
                </div>
              </dl>
            </div>

            {/* Progress during execution */}
            {jobData && (jobData.status === 'importing' || jobData.status === 'completed' || jobData.status === 'failed') && (
              <div className="rounded-lg border border-gray-200 bg-gray-50 p-6">
                <div className="flex items-center gap-3 mb-4">
                  {jobData.status === 'importing' && (
                    <>
                      <Loader2 className="h-5 w-5 animate-spin text-blue-600" />
                      <span className="font-medium text-gray-900">{t('wizard.execute.importing')}</span>
                    </>
                  )}
                  {jobData.status === 'completed' && (
                    <>
                      <CheckCircle className="h-5 w-5 text-green-600" />
                      <span className="font-medium text-green-900">{t('wizard.execute.completed')}</span>
                    </>
                  )}
                  {jobData.status === 'failed' && (
                    <>
                      <XCircle className="h-5 w-5 text-red-600" />
                      <span className="font-medium text-red-900">{t('wizard.execute.failed')}</span>
                    </>
                  )}
                </div>

                {jobData.processed_rows !== undefined && (
                  <div className="space-y-2">
                    <div className="flex justify-between text-sm">
                      <span className="text-gray-500">{t('wizard.execute.progress')}</span>
                      <span className="text-gray-900">
                        {jobData.processed_rows} / {jobData.total_rows}
                      </span>
                    </div>
                    <div className="h-2 w-full rounded-full bg-gray-200">
                      <div
                        className="h-2 rounded-full bg-blue-600 transition-all"
                        style={{
                          width: `${((jobData.processed_rows ?? 0) / (jobData.total_rows ?? 1)) * 100}%`,
                        }}
                      />
                    </div>
                  </div>
                )}
              </div>
            )}

            <div className="flex items-center justify-between border-t border-gray-200 pt-4">
              <button
                type="button"
                onClick={() => { setCurrentStep('validation'); }}
                disabled={executeImport.isPending || jobData?.status === 'importing'}
                className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900 disabled:cursor-not-allowed disabled:opacity-50"
              >
                <ArrowLeft className="h-4 w-4" />
                {t('common:actions.back')}
              </button>

              {!jobData || jobData.status === 'validated' ? (
                <button
                  type="button"
                  onClick={handleExecute}
                  disabled={executeImport.isPending}
                  className="inline-flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:cursor-not-allowed disabled:bg-gray-300"
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
                  className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
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
              <CheckCircle className="mx-auto h-16 w-16 text-green-500" />
              <h2 className="mt-4 text-2xl font-bold text-gray-900">
                {t('wizard.complete.title')}
              </h2>
              <p className="mt-2 text-gray-600">
                {t('wizard.complete.description')}
              </p>
            </div>

            {/* Results summary */}
            {jobData && (
              <div className="rounded-lg border border-gray-200 bg-white p-6">
                <h3 className="font-medium text-gray-900 mb-4">
                  {t('wizard.complete.results')}
                </h3>
                <dl className="grid grid-cols-2 gap-4">
                  <div className="rounded-lg bg-green-50 p-4">
                    <dt className="text-sm text-green-600">{t('wizard.complete.imported')}</dt>
                    <dd className="text-2xl font-bold text-green-900">
                      {jobData.successful_rows ?? 0}
                    </dd>
                  </div>
                  <div className="rounded-lg bg-red-50 p-4">
                    <dt className="text-sm text-red-600">{t('wizard.complete.failed')}</dt>
                    <dd className="text-2xl font-bold text-red-900">
                      {jobData.failed_rows ?? 0}
                    </dd>
                  </div>
                </dl>
              </div>
            )}

            <div className="flex items-center justify-center gap-4 border-t border-gray-200 pt-6">
              <Link
                to="/settings/import"
                className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
              >
                {t('wizard.complete.backToDashboard')}
              </Link>
              <button
                type="button"
                onClick={() => navigate(`/settings/import/${importType}`)}
                className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
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
          className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('common:actions.back')}
        </Link>
        <div>
          <h1 className="text-2xl font-bold text-gray-900">
            {t(`types.${importType}.title`)}
          </h1>
          <p className="text-gray-500">
            {t(`types.${importType}.description`)}
          </p>
        </div>
      </div>

      {/* Progress indicator */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <ImportProgress
          steps={translatedSteps}
          currentStep={stepIndex}
          completedSteps={completedSteps}
        />
      </div>

      {/* Step content */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        {renderStepContent()}
      </div>
    </div>
  )
}
