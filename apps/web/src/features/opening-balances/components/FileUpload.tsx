import { useCallback, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Upload, FileText, CheckCircle, XCircle, Loader2, Download } from 'lucide-react'
import type { OpeningBatchType } from '../types'
import { GL_COLUMNS, INVENTORY_COLUMNS, AR_AP_COLUMNS } from '../types'

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
      <div className="rounded-lg bg-blue-50 p-4">
        <h3 className="font-medium text-blue-800 mb-2">
          {t('openingBalances.upload.expectedColumns')}
        </h3>
        <div className="flex flex-wrap gap-2">
          {expectedColumns.map((col) => (
            <span
              key={col}
              className="inline-flex items-center rounded bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800"
            >
              {col}
            </span>
          ))}
        </div>
        <button
          type="button"
          onClick={handleDownloadTemplate}
          className="mt-3 inline-flex items-center gap-2 text-sm text-blue-600 hover:text-blue-800"
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
            ? 'border-blue-500 bg-blue-50'
            : 'border-gray-300 hover:border-gray-400'
        }`}
      >
        <input
          type="file"
          accept=".csv"
          onChange={handleFileInput}
          className="absolute inset-0 h-full w-full cursor-pointer opacity-0"
        />
        <Upload className="mx-auto h-12 w-12 text-gray-400" />
        <p className="mt-4 text-sm font-medium text-gray-900">
          {t('openingBalances.upload.dragDropText')}
        </p>
        <p className="mt-1 text-xs text-gray-500">
          {t('openingBalances.upload.supportedFormats')}
        </p>
      </div>

      {/* File selected */}
      {selectedFile && (
        <div
          className={`rounded-lg p-4 ${
            parseError ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200'
          }`}
        >
          <div className="flex items-start gap-3">
            {parseError ? (
              <XCircle className="h-5 w-5 text-red-600 mt-0.5" />
            ) : (
              <CheckCircle className="h-5 w-5 text-green-600 mt-0.5" />
            )}
            <div className="flex-1">
              <div className="flex items-center gap-2">
                <FileText className="h-4 w-4 text-gray-500" />
                <span className="font-medium text-gray-900">{selectedFile.name}</span>
              </div>
              {parseError ? (
                <p className="mt-1 text-sm text-red-700">{parseError}</p>
              ) : parsedData ? (
                <p className="mt-1 text-sm text-green-700">
                  {t('openingBalances.upload.rowsFound', { count: parsedData.rows.length })}
                </p>
              ) : null}
            </div>
          </div>
        </div>
      )}

      {/* Preview table */}
      {parsedData && !parseError && parsedData.rows.length > 0 && (
        <div className="overflow-hidden rounded-lg border border-gray-200">
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500">#</th>
                  {parsedData.headers.map((header) => (
                    <th
                      key={header}
                      className="px-3 py-2 text-left text-xs font-medium text-gray-500"
                    >
                      {header}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {parsedData.rows.slice(0, 5).map((row, index) => (
                  <tr key={index}>
                    <td className="px-3 py-2 text-xs text-gray-500">{index + 1}</td>
                    {parsedData.headers.map((header) => (
                      <td key={header} className="px-3 py-2 text-xs text-gray-900">
                        {row[header] ?? ''}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {parsedData.rows.length > 5 && (
            <div className="bg-gray-50 px-3 py-2 text-xs text-gray-500">
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
            className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-300"
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
