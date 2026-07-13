import { Fragment } from 'react'
import { useTranslation } from 'react-i18next'
import { CheckCircle, XCircle, AlertTriangle } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { ImportPreview } from '../types'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

interface ImportPreviewTableProps {
  preview: ImportPreview
}

export function ImportPreviewTable({ preview }: ImportPreviewTableProps) {
  const { t } = useTranslation('import')

  const { headers, rows, summary } = preview
  const placement = preview.placement ?? { max_depth: 0, nodes_to_create: [], placements_to_set: [] }

  return (
    <div className="space-y-4">
      {/* Summary bar */}
      <div className={`flex flex-wrap items-center gap-4 rounded-lg ${colorTokens.surface.page} px-4 py-3 text-sm`}>
        <div className="flex items-center gap-2">
          <span className={`font-medium ${colorTokens.text.secondary}`}>
            {t('preview.totalRows', { count: summary.total_rows })}
          </span>
        </div>
        <div className={`flex items-center gap-1.5 ${colorTokens.intent.success.text}`}>
          <CheckCircle className="h-4 w-4" />
          <span>{t('preview.validRows', { count: summary.valid_rows })}</span>
        </div>
        {summary.invalid_rows > 0 && (
          <div className={`flex items-center gap-1.5 ${colorTokens.intent.danger.text}`}>
            <XCircle className="h-4 w-4" />
            <span>{t('preview.invalidRows', { count: summary.invalid_rows })}</span>
          </div>
        )}
      </div>

      {(placement.nodes_to_create.length > 0 || placement.placements_to_set.length > 0) && (
        <div className={`grid gap-3 rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.page} p-4 sm:grid-cols-2`}>
          <div>
            <p className={`text-sm font-medium ${colorTokens.text.secondary}`}>
              {t('preview.nodesToCreate', { count: placement.nodes_to_create.length })}
            </p>
            {placement.nodes_to_create.map((node) => (
              <code key={node.path} className={`mt-1 block text-xs ${colorTokens.text.muted}`}>{node.path}</code>
            ))}
          </div>
          <div>
            <p className={`text-sm font-medium ${colorTokens.text.secondary}`}>
              {t('preview.placementsToSet', { count: placement.placements_to_set.length })}
            </p>
            {placement.placements_to_set.map((plannedPlacement) => (
              <code key={`${String(plannedPlacement.row_number)}-${plannedPlacement.location_code}`} className={`mt-1 block text-xs ${colorTokens.text.muted}`}>
                {plannedPlacement.location_code}: {plannedPlacement.path}
              </code>
            ))}
          </div>
        </div>
      )}

      {/* Preview table */}
      <div className={`overflow-x-auto rounded-lg border ${colorTokens.border.subtle}`}>
        <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
          <thead className={colorTokens.surface.page}>
            <tr>
              <th
                scope="col"
                className={`px-3 py-2 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}
              >
                #
              </th>
              {headers.map((header) => (
                <th
                  key={header}
                  scope="col"
                  className={`px-3 py-2 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}
                >
                  {header}
                </th>
              ))}
              <th
                scope="col"
                className={`px-3 py-2 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}
              >
                {t('preview.status')}
              </th>
            </tr>
          </thead>
          <tbody className={`divide-y ${colorTokens.border.divider} ${colorTokens.surface.base}`}>
            {rows.map((row) => (
              <Fragment key={row.row_number}>
                <tr
                  className={cn(
                    colorTokens.intent.neutral.bgHover,
                    !row.is_valid && colorTokens.intent.danger.bgSubtleAlpha
                  )}
                >
                  <td className={`whitespace-nowrap px-3 py-2 text-sm ${colorTokens.text.subtle}`}>
                    {row.row_number}
                  </td>
                  {headers.map((header) => (
                    <td
                      key={header}
                      className={`whitespace-nowrap px-3 py-2 text-sm ${colorTokens.text.primary}`}
                    >
                      {row.data[header] || '-'}
                    </td>
                  ))}
                  <td className="whitespace-nowrap px-3 py-2">
                    {row.is_valid ? (
                      <span className={`inline-flex items-center gap-1 ${colorTokens.intent.success.text}`}>
                        <CheckCircle className="h-4 w-4" />
                        <span className="text-xs">{t('preview.valid')}</span>
                      </span>
                    ) : (
                      <span className={`inline-flex items-center gap-1 ${colorTokens.intent.danger.text}`}>
                        <XCircle className="h-4 w-4" />
                        <span className="text-xs">{t('preview.error')}</span>
                      </span>
                    )}
                  </td>
                </tr>
                {/* Inline error details row */}
                {!row.is_valid && row.errors && Object.keys(row.errors).length > 0 && (
                  <tr className={colorTokens.intent.danger.bgSubtle}>
                    <td colSpan={headers.length + 2} className="px-3 py-2">
                      <div className={`flex flex-wrap gap-x-4 gap-y-1 text-xs ${colorTokens.intent.danger.textStrong}`}>
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
        </DataTable>
      </div>

      {/* More rows indicator */}
      {summary.total_rows > rows.length && (
        <div className={`flex items-center gap-2 rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.page} px-4 py-2 text-sm ${colorTokens.text.muted}`}>
          <AlertTriangle className={`h-4 w-4 ${colorTokens.intent.caution.textSubtle}`} />
          <span>
            {t('preview.moreRows', { count: summary.total_rows - rows.length })}
          </span>
        </div>
      )}
    </div>
  )
}
