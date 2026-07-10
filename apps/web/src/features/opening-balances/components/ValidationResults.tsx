import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { CheckCircle, XCircle, AlertCircle, ChevronDown, ChevronRight } from 'lucide-react'
import type { OpeningBalanceImportRow } from '../types'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface ValidationResultsProps {
  rows: OpeningBalanceImportRow[]
  validationResult: {
    valid: boolean
    total_rows: number
    valid_rows: number
    invalid_rows: number
  } | null
}

const rowBadgeConfig = {
  VALID: {
    Icon: CheckCircle,
    className: `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStrong}`,
    labelKey: 'openingBalances.validation.status.valid',
  },
  INVALID: {
    Icon: XCircle,
    className: `${colorTokens.intent.danger.bgSoft} ${colorTokens.intent.danger.textStrong}`,
    labelKey: 'openingBalances.validation.status.invalid',
  },
  PENDING: {
    Icon: AlertCircle,
    className: `${colorTokens.surface.muted} ${colorTokens.text.muted}`,
    labelKey: 'openingBalances.validation.status.pending',
  },
  POSTED: {
    Icon: CheckCircle,
    className: `${colorTokens.intent.primary.bgSoft} ${colorTokens.intent.primary.textStrong}`,
    labelKey: 'openingBalances.validation.status.posted',
  },
} as const

function RowStatusBadge({ status }: { status: string }) {
  const { t } = useTranslation()
  const config = rowBadgeConfig[status as keyof typeof rowBadgeConfig]
  if (!config) return null
  const { Icon } = config

  return (
    <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${config.className}`}>
      <Icon className="h-3 w-3" />
      {t(config.labelKey)}
    </span>
  )
}

function ValidationErrorRow({ row }: { row: OpeningBalanceImportRow }) {
  const [isExpanded, setIsExpanded] = useState(false)
  const hasErrors = row.validation_errors && Object.keys(row.validation_errors).length > 0

  return (
    <div className={`border-b ${colorTokens.border.subtle} last:border-b-0`}>
      <div
        className={`flex items-center gap-4 px-4 py-3 ${hasErrors ? `cursor-pointer ${colorTokens.intent.neutral.bgHover}` : ''}`}
        onClick={() => hasErrors && setIsExpanded(!isExpanded)}
      >
        <span className={`w-12 text-sm ${colorTokens.text.subtle}`}>{row.row_number}</span>
        <div className="flex-1">
          <RowStatusBadge status={row.status} />
        </div>
        <div className={`flex-1 text-sm ${colorTokens.text.secondary}`}>
          {/* Show preview of raw data */}
          {Object.entries(row.raw_data)
            .slice(0, 3)
            .map(([key, value]) => (
              <span key={key} className="me-3">
                <span className={colorTokens.text.subtle}>{key}:</span>{' '}
                <span className="font-medium">{String(value)}</span>
              </span>
            ))}
        </div>
        {hasErrors && (
          <button type="button" className={`p-1 ${colorTokens.text.disabled} ${colorTokens.intent.neutral.textHover}`}>
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
        <div className={`${colorTokens.intent.danger.bgSubtle} px-4 py-3 ps-16`}>
          <ul className="space-y-1">
            {Object.entries(row.validation_errors).map(([field, errors]) => (
              <li key={field} className="text-sm">
                <span className={`font-medium ${colorTokens.intent.danger.textStronger}`}>{field}:</span>{' '}
                <span className={colorTokens.intent.danger.textStrong}>
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
              ? `${colorTokens.intent.success.bgSubtle} border ${colorTokens.intent.success.borderSubtle}`
              : `${colorTokens.intent.caution.bgSubtle} border ${colorTokens.intent.caution.borderSubtle}`
          }`}
        >
          <div className="flex items-center gap-3">
            {validationResult.valid ? (
              <CheckCircle className={`h-6 w-6 ${colorTokens.intent.success.text}`} />
            ) : (
              <AlertCircle className={`h-6 w-6 ${colorTokens.intent.caution.text}`} />
            )}
            <div>
              <h3
                className={`font-medium ${validationResult.valid ? colorTokens.intent.success.textStronger : colorTokens.intent.caution.textStronger}`}
              >
                {validationResult.valid
                  ? t('openingBalances.validation.allValid')
                  : t('openingBalances.validation.hasErrors')}
              </h3>
              <p
                className={`text-sm ${validationResult.valid ? colorTokens.intent.success.textStrong : colorTokens.intent.caution.textStrong}`}
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
          <span className={`text-sm ${colorTokens.text.subtle}`}>
            {t('openingBalances.validation.totalRows', { count: rows.length })}
          </span>
          <span className={`inline-flex items-center gap-1 text-sm ${colorTokens.intent.success.text}`}>
            <CheckCircle className="h-3 w-3" />
            {validCount} {t('openingBalances.validation.valid')}
          </span>
          {invalidCount > 0 && (
            <span className={`inline-flex items-center gap-1 text-sm ${colorTokens.intent.danger.text}`}>
              <XCircle className="h-3 w-3" />
              {invalidCount} {t('openingBalances.validation.invalid')}
            </span>
          )}
          {pendingCount > 0 && (
            <span className={`inline-flex items-center gap-1 text-sm ${colorTokens.text.subtle}`}>
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
              onChange={(e) => { setShowOnlyErrors(e.target.checked); }}
              className={`rounded ${colorTokens.border.default} ${colorTokens.intent.primary.text} ${colorTokens.focus.primaryRing}`}
            />
            {t('openingBalances.validation.showOnlyErrors')}
          </label>
        )}
      </div>

      {/* Rows list */}
      <div className={`rounded-lg border ${colorTokens.border.subtle} overflow-hidden`}>
        <div className={`${colorTokens.surface.page} px-4 py-2 border-b ${colorTokens.border.subtle}`}>
          <div className="flex items-center gap-4">
            <span className={`w-12 text-xs font-medium ${colorTokens.text.subtle}`}>
              {t('openingBalances.validation.row')}
            </span>
            <span className={`flex-1 text-xs font-medium ${colorTokens.text.subtle}`}>
              {t('openingBalances.validation.statusLabel')}
            </span>
            <span className={`flex-1 text-xs font-medium ${colorTokens.text.subtle}`}>
              {t('openingBalances.validation.data')}
            </span>
            <span className="w-8" />
          </div>
        </div>
        <div className="max-h-96 overflow-y-auto">
          {filteredRows.length === 0 ? (
            <div className={`px-4 py-8 text-center text-sm ${colorTokens.text.subtle}`}>
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
