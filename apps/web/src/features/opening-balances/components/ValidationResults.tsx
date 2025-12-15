import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { CheckCircle, XCircle, AlertCircle, ChevronDown, ChevronRight } from 'lucide-react'
import type { OpeningBalanceImportRow } from '../types'

interface ValidationResultsProps {
  rows: OpeningBalanceImportRow[]
  validationResult: {
    valid: boolean
    total_rows: number
    valid_rows: number
    invalid_rows: number
  } | null
}

function RowStatusBadge({ status }: { status: string }) {
  const { t } = useTranslation()

  switch (status) {
    case 'VALID':
      return (
        <span className="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">
          <CheckCircle className="h-3 w-3" />
          {t('openingBalances.validation.status.valid')}
        </span>
      )
    case 'INVALID':
      return (
        <span className="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
          <XCircle className="h-3 w-3" />
          {t('openingBalances.validation.status.invalid')}
        </span>
      )
    case 'PENDING':
      return (
        <span className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">
          <AlertCircle className="h-3 w-3" />
          {t('openingBalances.validation.status.pending')}
        </span>
      )
    case 'POSTED':
      return (
        <span className="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700">
          <CheckCircle className="h-3 w-3" />
          {t('openingBalances.validation.status.posted')}
        </span>
      )
    default:
      return null
  }
}

function ValidationErrorRow({ row }: { row: OpeningBalanceImportRow }) {
  const [isExpanded, setIsExpanded] = useState(false)
  const hasErrors = row.validation_errors && Object.keys(row.validation_errors).length > 0

  return (
    <div className="border-b border-gray-200 last:border-b-0">
      <div
        className={`flex items-center gap-4 px-4 py-3 ${hasErrors ? 'cursor-pointer hover:bg-gray-50' : ''}`}
        onClick={() => hasErrors && setIsExpanded(!isExpanded)}
      >
        <span className="w-12 text-sm text-gray-500">{row.row_number}</span>
        <div className="flex-1">
          <RowStatusBadge status={row.status} />
        </div>
        <div className="flex-1 text-sm text-gray-700">
          {/* Show preview of raw data */}
          {Object.entries(row.raw_data)
            .slice(0, 3)
            .map(([key, value]) => (
              <span key={key} className="me-3">
                <span className="text-gray-500">{key}:</span>{' '}
                <span className="font-medium">{String(value)}</span>
              </span>
            ))}
        </div>
        {hasErrors && (
          <button type="button" className="p-1 text-gray-400 hover:text-gray-600">
            {isExpanded ? (
              <ChevronDown className="h-4 w-4" />
            ) : (
              <ChevronRight className="h-4 w-4" />
            )}
          </button>
        )}
      </div>

      {/* Expanded error details */}
      {isExpanded && row.validation_errors && (
        <div className="bg-red-50 px-4 py-3 ps-16">
          <ul className="space-y-1">
            {Object.entries(row.validation_errors).map(([field, errors]) => (
              <li key={field} className="text-sm">
                <span className="font-medium text-red-800">{field}:</span>{' '}
                <span className="text-red-700">
                  {Array.isArray(errors) ? errors.join(', ') : errors}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}

export function ValidationResults({ rows, validationResult }: ValidationResultsProps) {
  const { t } = useTranslation()
  const [showOnlyErrors, setShowOnlyErrors] = useState(false)

  const filteredRows = showOnlyErrors
    ? rows.filter((r) => r.status === 'INVALID')
    : rows

  const validCount = rows.filter((r) => r.status === 'VALID').length
  const invalidCount = rows.filter((r) => r.status === 'INVALID').length
  const pendingCount = rows.filter((r) => r.status === 'PENDING').length

  return (
    <div className="space-y-4">
      {/* Summary */}
      {validationResult && (
        <div
          className={`rounded-lg p-4 ${
            validationResult.valid
              ? 'bg-green-50 border border-green-200'
              : 'bg-amber-50 border border-amber-200'
          }`}
        >
          <div className="flex items-center gap-3">
            {validationResult.valid ? (
              <CheckCircle className="h-6 w-6 text-green-600" />
            ) : (
              <AlertCircle className="h-6 w-6 text-amber-600" />
            )}
            <div>
              <h3
                className={`font-medium ${validationResult.valid ? 'text-green-800' : 'text-amber-800'}`}
              >
                {validationResult.valid
                  ? t('openingBalances.validation.allValid')
                  : t('openingBalances.validation.hasErrors')}
              </h3>
              <p
                className={`text-sm ${validationResult.valid ? 'text-green-700' : 'text-amber-700'}`}
              >
                {t('openingBalances.validation.summary', {
                  valid: validationResult.valid_rows,
                  invalid: validationResult.invalid_rows,
                  total: validationResult.total_rows,
                })}
              </p>
            </div>
          </div>
        </div>
      )}

      {/* Stats */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <span className="text-sm text-gray-500">
            {t('openingBalances.validation.totalRows', { count: rows.length })}
          </span>
          <span className="inline-flex items-center gap-1 text-sm text-green-600">
            <CheckCircle className="h-3 w-3" />
            {validCount} {t('openingBalances.validation.valid')}
          </span>
          {invalidCount > 0 && (
            <span className="inline-flex items-center gap-1 text-sm text-red-600">
              <XCircle className="h-3 w-3" />
              {invalidCount} {t('openingBalances.validation.invalid')}
            </span>
          )}
          {pendingCount > 0 && (
            <span className="inline-flex items-center gap-1 text-sm text-gray-500">
              <AlertCircle className="h-3 w-3" />
              {pendingCount} {t('openingBalances.validation.pending')}
            </span>
          )}
        </div>

        {invalidCount > 0 && (
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={showOnlyErrors}
              onChange={(e) => setShowOnlyErrors(e.target.checked)}
              className="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
            />
            {t('openingBalances.validation.showOnlyErrors')}
          </label>
        )}
      </div>

      {/* Rows list */}
      <div className="rounded-lg border border-gray-200 overflow-hidden">
        <div className="bg-gray-50 px-4 py-2 border-b border-gray-200">
          <div className="flex items-center gap-4">
            <span className="w-12 text-xs font-medium text-gray-500">
              {t('openingBalances.validation.row')}
            </span>
            <span className="flex-1 text-xs font-medium text-gray-500">
              {t('openingBalances.validation.statusLabel')}
            </span>
            <span className="flex-1 text-xs font-medium text-gray-500">
              {t('openingBalances.validation.data')}
            </span>
            <span className="w-8" />
          </div>
        </div>
        <div className="max-h-96 overflow-y-auto">
          {filteredRows.length === 0 ? (
            <div className="px-4 py-8 text-center text-sm text-gray-500">
              {showOnlyErrors
                ? t('openingBalances.validation.noErrors')
                : t('openingBalances.validation.noRows')}
            </div>
          ) : (
            filteredRows.map((row) => <ValidationErrorRow key={row.id} row={row} />)
          )}
        </div>
      </div>
    </div>
  )
}
