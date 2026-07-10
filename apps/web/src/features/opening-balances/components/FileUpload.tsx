import { useCallback, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Upload, FileText, CheckCircle, XCircle, Loader2, Download } from 'lucide-react'
import type { OpeningBatchType } from '../types'
import { GL_COLUMNS, INVENTORY_COLUMNS, AR_AP_COLUMNS } from '../types'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

interface FileUploadProps {
  batchType: OpeningBatchType
  onUpload: (rows: Array<Record<string, unknown>>) => Promise<void>
  isUploading: boolean
}

function parseCSV(text: string): { headers: string[]; rows: Array<Record<string, string>> } {
  const lines = text.split('\n').filter((line) => line.trim())
  if (lines.length === 0) {
    return { headers: [], rows: [] }
  }

  // Parse headers
  const headers = lines[0].split(',').map((h) => h.trim().replace(/^"|"$/g, '').toLowerCase())

  // Parse rows
  const rows: Array<Record<string, string>> = []
  for (let i = 1; i < lines.length; i++) {
    const values = lines[i].split(',').map((v) => v.trim().replace(/^"|"$/g, ''))
    const row: Record<string, string> = {}
    headers.forEach((header, index) => {
      row[header] = values[index] ?? ''
    })
    rows.push(row)
  }

  return { headers, rows }
}

function getExpectedColumns(batchType: OpeningBatchType): readonly string[] {
  switch (batchType) {
    case 'ACCOUNTING':
      return GL_COLUMNS
    case 'INVENTORY':
      return INVENTORY_COLUMNS
    case 'AR_OPEN_ITEMS':
    case 'AP_OPEN_ITEMS':
      return AR_AP_COLUMNS
    default:
      return []
  }
}

function getTemplateContent(batchType: OpeningBatchType): string {
  switch (batchType) {
    case 'ACCOUNTING':
      return 'account_code,debit,credit,reference\n101000,10000.00,0.00,Opening Cash\n401000,0.00,5000.00,Opening Payables\n301000,0.00,5000.00,Opening Equity'
    case 'INVENTORY':
      return 'product_code,location_code,quantity,unit_cost\nSKU-001,MAIN,100,25.50\nSKU-002,MAIN,50,15.00\nSKU-003,WAREHOUSE,200,10.00'
    case 'AR_OPEN_ITEMS':
      return 'partner_code,external_invoice_number,document_date,due_date,total,open_amount,document_type,currency,notes\nCUST-001,INV-2024-001,2024-10-15,2024-11-15,1500.00,1500.00,invoice,TND,\nCUST-002,INV-2024-002,2024-11-01,2024-12-01,2500.00,1000.00,invoice,TND,Partial payment received'
    case 'AP_OPEN_ITEMS':
      return 'partner_code,external_invoice_number,document_date,due_date,total,open_amount,document_type,currency,notes\nSUPP-001,F2024-100,2024-10-20,2024-11-20,5000.00,5000.00,invoice,TND,\nSUPP-002,F2024-101,2024-11-05,2024-12-05,3000.00,3000.00,invoice,TND,'
    default:
      return ''
  }
}

export function FileUpload({ batchType, onUpload, isUploading }: FileUploadProps) {
  const { t } = useTranslation()
  const [dragActive, setDragActive] = useState(false)
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [parsedData, setParsedData] = useState<{
    headers: string[]
    rows: Array<Record<string, string>>
  } | null>(null)
  const [parseError, setParseError] = useState<string | null>(null)

  const expectedColumns = getExpectedColumns(batchType)

  const handleFile = useCallback(
    async (file: File) => {
      setSelectedFile(file)
      setParseError(null)

      try {
        const text = await file.text()
        const parsed = parseCSV(text)

        if (parsed.headers.length === 0) {
          setParseError(t('openingBalances.upload.errors.emptyFile'))
          return
        }

        // Check for required columns
        const missingColumns = expectedColumns.filter(
          (col) => !parsed.headers.includes(col)
        )

        if (missingColumns.length > 0) {
          setParseError(
            t('openingBalances.upload.errors.missingColumns', {
              columns: missingColumns.join(', '),
            })
          )
        }

        setParsedData(parsed)
      } catch {
        setParseError(t('openingBalances.upload.errors.parseError'))
      }
    },
    [expectedColumns, t]
  )

  const handleDrag = useCallback((e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    if (e.type === 'dragenter' || e.type === 'dragover') {
      setDragActive(true)
    } else if (e.type === 'dragleave') {
      setDragActive(false)
    }
  }, [])

  const handleDrop = useCallback(
    (e: React.DragEvent) => {
      e.preventDefault()
      e.stopPropagation()
      setDragActive(false)

      const files = e.dataTransfer.files
      if (files?.[0]) {
        void handleFile(files[0])
      }
    },
    [handleFile]
  )

  const handleFileInput = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      const files = e.target.files
      if (files?.[0]) {
        void handleFile(files[0])
      }
    },
    [handleFile]
  )

  const handleUpload = useCallback(async () => {
    if (!parsedData || parseError) return
    await onUpload(parsedData.rows)
  }, [parsedData, parseError, onUpload])

  const handleDownloadTemplate = useCallback(() => {
    const content = getTemplateContent(batchType)
    const blob = new Blob([content], { type: 'text/csv' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `opening-balance-${batchType.toLowerCase()}-template.csv`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
  }, [batchType])

  return (
    <div className="space-y-6">
      {/* Expected columns info */}
      <div className={`rounded-lg ${colorTokens.intent.primary.bgSubtle} p-4`}>
        <h3 className={`font-medium ${colorTokens.intent.primary.textStronger} mb-2`}>
          {t('openingBalances.upload.expectedColumns')}
        </h3>
        <div className="flex flex-wrap gap-2">
          {expectedColumns.map((col) => (
            <span
              key={col}
              className={`inline-flex items-center rounded ${colorTokens.intent.primary.bgSoft} px-2 py-0.5 text-xs font-medium ${colorTokens.intent.primary.textStronger}`}
            >
              {col}
            </span>
          ))}
        </div>
        <button
          type="button"
          onClick={handleDownloadTemplate}
          className={`mt-3 inline-flex items-center gap-2 text-sm ${colorTokens.intent.primary.text} ${colorTokens.intent.primary.textHoverStronger}`}
        >
          <Download className="h-4 w-4" />
          {t('openingBalances.upload.downloadTemplate')}
        </button>
      </div>

      {/* Drag and drop area */}
      <div
        onDragEnter={handleDrag}
        onDragLeave={handleDrag}
        onDragOver={handleDrag}
        onDrop={handleDrop}
        className={`relative rounded-lg border-2 border-dashed p-8 text-center transition-colors ${
          dragActive
            ? `${colorTokens.intent.primary.borderFocus} ${colorTokens.intent.primary.bgSubtle}`
            : `${colorTokens.border.default} ${colorTokens.border.hoverStrong}`
        }`}
      >
        <input
          type="file"
          accept=".csv"
          onChange={handleFileInput}
          className="absolute inset-0 h-full w-full cursor-pointer opacity-0"
        />
        <Upload className={`mx-auto h-12 w-12 ${colorTokens.text.disabled}`} />
        <p className={`mt-4 text-sm font-medium ${colorTokens.text.primary}`}>
          {t('openingBalances.upload.dragDropText')}
        </p>
        <p className={`mt-1 text-xs ${colorTokens.text.subtle}`}>
          {t('openingBalances.upload.supportedFormats')}
        </p>
      </div>

      {/* File selected */}
      {selectedFile && (
        <div
          className={`rounded-lg p-4 ${
            parseError ? `${colorTokens.intent.danger.bgSubtle} border ${colorTokens.intent.danger.borderSubtle}` : `${colorTokens.intent.success.bgSubtle} border ${colorTokens.intent.success.borderSubtle}`
          }`}
        >
          <div className="flex items-start gap-3">
            {parseError ? (
              <XCircle className={`h-5 w-5 ${colorTokens.intent.danger.text} mt-0.5`} />
            ) : (
              <CheckCircle className={`h-5 w-5 ${colorTokens.intent.success.text} mt-0.5`} />
            )}
            <div className="flex-1">
              <div className="flex items-center gap-2">
                <FileText className={`h-4 w-4 ${colorTokens.text.subtle}`} />
                <span className={`font-medium ${colorTokens.text.primary}`}>{selectedFile.name}</span>
              </div>
              {parseError ? (
                <p className={`mt-1 text-sm ${colorTokens.intent.danger.textStrong}`}>{parseError}</p>
              ) : parsedData ? (
                <p className={`mt-1 text-sm ${colorTokens.intent.success.textStrong}`}>
                  {t('openingBalances.upload.rowsFound', { count: parsedData.rows.length })}
                </p>
              ) : null}
            </div>
          </div>
        </div>
      )}

      {/* Preview table */}
      {parsedData && !parseError && parsedData.rows.length > 0 && (
        <div className={`overflow-hidden rounded-lg border ${colorTokens.border.subtle}`}>
          <div className="overflow-x-auto">
            <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
              <thead className={colorTokens.surface.page}>
                <tr>
                  <th className={`px-3 py-2 text-left text-xs font-medium ${colorTokens.text.subtle}`}>#</th>
                  {parsedData.headers.map((header) => (
                    <th
                      key={header}
                      className={`px-3 py-2 text-left text-xs font-medium ${colorTokens.text.subtle}`}
                    >
                      {header}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className={`divide-y ${colorTokens.border.divider} ${colorTokens.surface.base}`}>
                {parsedData.rows.slice(0, 5).map((row, index) => (
                  <tr key={index}>
                    <td className={`px-3 py-2 text-xs ${colorTokens.text.subtle}`}>{index + 1}</td>
                    {parsedData.headers.map((header) => (
                      <td key={header} className={`px-3 py-2 text-xs ${colorTokens.text.primary}`}>
                        {row[header] ?? ''}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </DataTable>
          </div>
          {parsedData.rows.length > 5 && (
            <div className={`${colorTokens.surface.page} px-3 py-2 text-xs ${colorTokens.text.subtle}`}>
              {t('openingBalances.upload.moreRows', { count: parsedData.rows.length - 5 })}
            </div>
          )}
        </div>
      )}

      {/* Upload button */}
      {parsedData && !parseError && (
        <div className="flex justify-end">
          <button
            type="button"
            onClick={handleUpload}
            disabled={isUploading}
            className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrongHover} disabled:cursor-not-allowed ${colorTokens.surface.disabledWhenDisabled}`}
          >
            {isUploading ? (
              <>
                <Loader2 className="h-4 w-4 animate-spin" />
                {t('openingBalances.upload.uploading')}
              </>
            ) : (
              <>
                <Upload className="h-4 w-4" />
                {t('openingBalances.upload.uploadButton', { count: parsedData.rows.length })}
              </>
            )}
          </button>
        </div>
      )}
    </div>
  )
}
