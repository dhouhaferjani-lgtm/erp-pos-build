import { Fragment } from 'react'
import { useTranslation } from 'react-i18next'
import { CheckCircle, XCircle, AlertTriangle } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { ImportPreview } from '../types'

interface ImportPreviewTableProps {
  preview: ImportPreview
}

export function ImportPreviewTable({ preview }: ImportPreviewTableProps) {
  const { t } = useTranslation('import')

  const { headers, rows, summary } = preview

  return (
    <div className="space-y-4">
      {/* Summary bar */}
      <div className="flex flex-wrap items-center gap-4 rounded-lg bg-gray-50 px-4 py-3 text-sm">
        <div className="flex items-center gap-2">
          <span className="font-medium text-gray-700">
            {t('preview.totalRows', { count: summary.total_rows })}
          </span>
        </div>
        <div className="flex items-center gap-1.5 text-green-600">
          <CheckCircle className="h-4 w-4" />
          <span>{t('preview.validRows', { count: summary.valid_rows })}</span>
        </div>
        {summary.invalid_rows > 0 && (
          <div className="flex items-center gap-1.5 text-red-600">
            <XCircle className="h-4 w-4" />
            <span>{t('preview.invalidRows', { count: summary.invalid_rows })}</span>
          </div>
        )}
      </div>

      {/* Preview table */}
      <div className="overflow-x-auto rounded-lg border border-gray-200">
        <table className="min-w-full divide-y divide-gray-200">
          <thead className="bg-gray-50">
            <tr>
              <th
                scope="col"
                className="px-3 py-2 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
              >
                #
              </th>
              {headers.map((header) => (
                <th
                  key={header}
                  scope="col"
                  className="px-3 py-2 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
                >
                  {header}
                </th>
              ))}
              <th
                scope="col"
                className="px-3 py-2 text-start text-xs font-medium uppercase tracking-wider text-gray-500"
              >
                {t('preview.status')}
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 bg-white">
            {rows.map((row) => (
              <Fragment key={row.row_number}>
                <tr
                  className={cn(
                    'hover:bg-gray-50',
                    !row.is_valid && 'bg-red-50/50'
                  )}
                >
                  <td className="whitespace-nowrap px-3 py-2 text-sm text-gray-500">
                    {row.row_number}
                  </td>
                  {headers.map((header) => (
                    <td
                      key={header}
                      className="whitespace-nowrap px-3 py-2 text-sm text-gray-900"
                    >
                      {row.data[header] || '-'}
                    </td>
                  ))}
                  <td className="whitespace-nowrap px-3 py-2">
                    {row.is_valid ? (
                      <span className="inline-flex items-center gap-1 text-green-600">
                        <CheckCircle className="h-4 w-4" />
                        <span className="text-xs">{t('preview.valid')}</span>
                      </span>
                    ) : (
                      <span className="inline-flex items-center gap-1 text-red-600">
                        <XCircle className="h-4 w-4" />
                        <span className="text-xs">{t('preview.error')}</span>
                      </span>
                    )}
                  </td>
                </tr>
                {/* Inline error details row */}
                {!row.is_valid && row.errors && Object.keys(row.errors).length > 0 && (
                  <tr className="bg-red-50">
                    <td colSpan={headers.length + 2} className="px-3 py-2">
                      <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-red-700">
                        {Object.entries(row.errors).map(([field, msgs]) => (
                          <span key={field} className="inline-flex items-center gap-1">
                            <strong className="font-medium">{field}:</strong>
                            <span>{Array.isArray(msgs) ? msgs.join(', ') : msgs}</span>
                          </span>
                        ))}
                      </div>
                    </td>
                  </tr>
                )}
              </Fragment>
            ))}
          </tbody>
        </table>
      </div>

      {/* More rows indicator */}
      {summary.total_rows > rows.length && (
        <div className="flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-4 py-2 text-sm text-gray-600">
          <AlertTriangle className="h-4 w-4 text-amber-500" />
          <span>
            {t('preview.moreRows', { count: summary.total_rows - rows.length })}
          </span>
        </div>
      )}
    </div>
  )
}
