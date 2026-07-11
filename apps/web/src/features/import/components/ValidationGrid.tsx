import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { AlertCircle, CheckCircle, ChevronLeft, ChevronRight } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { ImportRow } from '../types'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

interface ValidationGridProps {
  rows: ImportRow[]
  onRowUpdate?: (rowId: number, field: string, value: string) => void
  showOnlyErrors?: boolean
}

export function ValidationGrid({
  rows,
  onRowUpdate,
  showOnlyErrors = true,
}: ValidationGridProps) {
  const { t } = useTranslation('import')
  const [currentPage, setCurrentPage] = useState(1)
  const pageSize = 20

  // Helper to check if row has errors (errors is an object, not an array)
  const hasErrors = (errors: ImportRow['errors']): boolean => {
    return errors !== null && Object.keys(errors).length > 0
  }

  // Filter rows based on showOnlyErrors (includes validation errors and execution errors)
  const filteredRows = useMemo(() => {
    if (showOnlyErrors) {
      return rows.filter((row) => !row.is_valid || hasErrors(row.errors) || row.import_error)
    }
    return rows
  }, [rows, showOnlyErrors])

  // Get unique columns from all rows
  const columns = useMemo(() => {
    const colSet = new Set<string>()
    for (const row of filteredRows) {
      for (const key of Object.keys(row.data)) {
        colSet.add(key)
      }
    }
    return Array.from(colSet)
  }, [filteredRows])

  // Paginate
  const totalPages = Math.ceil(filteredRows.length / pageSize)
  const paginatedRows = useMemo(() => {
    const start = (currentPage - 1) * pageSize
    return filteredRows.slice(start, start + pageSize)
  }, [filteredRows, currentPage])

  // Get error for a specific field in a row (errors is now an object keyed by field)
  const getFieldError = (row: ImportRow, field: string): string | null => {
    if (!row.errors) return null
    const fieldErrors = row.errors[field]
    return fieldErrors && fieldErrors.length > 0 ? fieldErrors[0] : null
  }

  if (filteredRows.length === 0) {
    return (
      <div className={`rounded-lg border ${colorTokens.intent.success.borderSubtle} ${colorTokens.intent.success.bgSubtle} p-8 text-center`}>
        <CheckCircle className={`mx-auto h-12 w-12 ${colorTokens.intent.success.textSubtle}`} />
        <h3 className={`mt-2 text-sm font-semibold ${colorTokens.intent.success.textStrongest}`}>
          {t('validation.allValid')}
        </h3>
        <p className={`mt-1 text-sm ${colorTokens.intent.success.textStrong}`}>
          {t('validation.allValidDescription')}
        </p>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      {/* Summary */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2 text-sm">
          <AlertCircle className={`h-4 w-4 ${colorTokens.intent.caution.textSubtle}`} />
          <span className={colorTokens.text.muted}>
            {t('validation.rowsWithErrors', { count: filteredRows.length })}
          </span>
        </div>
      </div>

      {/* Table */}
      <div className={`overflow-x-auto rounded-lg border ${colorTokens.border.subtle}`}>
        <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
          <thead className={colorTokens.surface.page}>
            <tr>
              <th className={`sticky left-0 ${colorTokens.surface.page} px-4 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                {t('validation.row')}
              </th>
              {columns.map((col) => (
                <th
                  key={col}
                  className={`px-4 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}
                >
                  {col}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className={`divide-y ${colorTokens.border.divider} ${colorTokens.surface.base}`}>
            {paginatedRows.map((row) => (
              <tr
                key={row.row_number}
                className={cn(
                  colorTokens.intent.neutral.bgHover,
                  (!row.is_valid || row.import_error) && colorTokens.intent.danger.bgSubtleAlpha
                )}
              >
                <td className={`sticky left-0 ${colorTokens.surface.base} whitespace-nowrap px-4 py-3 text-sm font-medium ${colorTokens.text.subtle}`}>
                  <div className="flex items-center gap-1">
                    #{row.row_number}
                    {(!row.is_valid || row.import_error) && (
                      <AlertCircle className={`h-3.5 w-3.5 ${colorTokens.intent.danger.textSubtle}`} />
                    )}
                  </div>
                  {row.import_error && (
                    <p className={`mt-1 text-xs ${colorTokens.intent.danger.text} font-normal max-w-[200px] truncate`} title={row.import_error}>
                      {t('validation.executionError')}: {row.import_error}
                    </p>
                  )}
                </td>
                {columns.map((col) => {
                  const value = row.data[col] ?? ''
                  const error = getFieldError(row, col)

                  return (
                    <td
                      key={col}
                      className={cn(
                        'px-4 py-3',
                        error && colorTokens.intent.danger.bgSubtle
                      )}
                    >
                      {onRowUpdate ? (
                        <input
                          type="text"
                          value={value}
                          onChange={(e) => {
                            onRowUpdate(row.row_number, col, e.target.value)
                          }}
                          className={cn(
                            'w-full min-w-[120px] rounded border px-2 py-1 text-sm focus:outline-none focus:ring-1',
                            error
                              ? `${colorTokens.intent.danger.borderSubtle} ${colorTokens.focus.dangerBorder} ${colorTokens.focus.dangerRing}`
                              : `${colorTokens.border.default} ${colorTokens.focus.primaryBorder} ${colorTokens.focus.primaryRing}`
                          )}
                        />
                      ) : (
                        <span
                          className={cn(
                            'text-sm',
                            error ? colorTokens.intent.danger.textStrong : colorTokens.text.primary
                          )}
                        >
                          {value || '-'}
                        </span>
                      )}
                      {error && (
                        <p className={`mt-1 text-xs ${colorTokens.intent.danger.text}`}>{error}</p>
                      )}
                    </td>
                  )
                })}
              </tr>
            ))}
          </tbody>
        </DataTable>
      </div>

      {/* Pagination */}
      {totalPages > 1 && (
        <div className={`flex items-center justify-between border-t ${colorTokens.border.subtle} pt-4`}>
          <p className={`text-sm ${colorTokens.text.subtle}`}>
            {t('validation.showingPage', {
              current: currentPage,
              total: totalPages,
            })}
          </p>
          <div className="flex gap-2">
            <button
              type="button"
              onClick={() => { setCurrentPage((p) => Math.max(1, p - 1)) }}
              disabled={currentPage === 1}
              className={`inline-flex items-center rounded-md border ${colorTokens.border.default} ${colorTokens.surface.base} px-3 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.intent.neutral.bgHover} disabled:cursor-not-allowed disabled:opacity-50`}
            >
              <ChevronLeft className="h-4 w-4" />
            </button>
            <button
              type="button"
              onClick={() => { setCurrentPage((p) => Math.min(totalPages, p + 1)) }}
              disabled={currentPage === totalPages}
              className={`inline-flex items-center rounded-md border ${colorTokens.border.default} ${colorTokens.surface.base} px-3 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.intent.neutral.bgHover} disabled:cursor-not-allowed disabled:opacity-50`}
            >
              <ChevronRight className="h-4 w-4" />
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
