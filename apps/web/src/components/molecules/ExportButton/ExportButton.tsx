import { useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { Download } from 'lucide-react'

interface ExportColumn {
  key: string
  label: string
}

interface ExportButtonProps {
  data: Record<string, unknown>[]
  filename: string
  columns: ExportColumn[]
  className?: string
}

function escapeCsvValue(value: unknown): string {
  if (value === null || value === undefined) {
    return ''
  }
  const str = typeof value === 'object' ? JSON.stringify(value) : String(value as string | number | boolean)
  if (str.includes(',') || str.includes('"') || str.includes('\n')) {
    return `"${str.replace(/"/g, '""')}"`
  }
  return str
}

function generateCsv(data: Record<string, unknown>[], columns: ExportColumn[]): string {
  const header = columns.map((col) => escapeCsvValue(col.label)).join(',')
  const rows = data.map((row) =>
    columns.map((col) => escapeCsvValue(row[col.key])).join(',')
  )
  return [header, ...rows].join('\n')
}

function downloadCsv(csv: string, filename: string): void {
  const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename.endsWith('.csv') ? filename : `${filename}.csv`
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(url)
}

export function ExportButton({ data, filename, columns, className }: ExportButtonProps) {
  const { t } = useTranslation(['common'])

  const handleExport = useCallback(() => {
    const csv = generateCsv(data, columns)
    downloadCsv(csv, filename)
  }, [data, filename, columns])

  return (
    <button
      type="button"
      onClick={handleExport}
      disabled={data.length === 0}
      className={`inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors disabled:opacity-50 disabled:cursor-not-allowed ${className ?? ''}`}
    >
      <Download className="h-4 w-4" />
      {t('actions.export')}
    </button>
  )
}
