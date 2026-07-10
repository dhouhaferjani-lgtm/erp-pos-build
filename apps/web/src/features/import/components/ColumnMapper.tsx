import { useEffect, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { ArrowRight, Check, AlertCircle, HelpCircle } from 'lucide-react'
import { cn } from '@/lib/utils'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

interface ColumnMapperProps {
  sourceColumns: string[]
  targetColumns: { name: string; required: boolean; description?: string }[]
  suggestions: Record<string, string | null>
  mapping: Record<string, string>
  onMappingChange: (mapping: Record<string, string>) => void
}

export function ColumnMapper({
  sourceColumns,
  targetColumns,
  suggestions,
  mapping,
  onMappingChange,
}: ColumnMapperProps) {
  const { t } = useTranslation('import')
  const targetNames = useMemo(() => {
    return new Set(targetColumns.map((col) => col.name))
  }, [targetColumns])

  // Apply suggestions on mount if mapping is empty
  useEffect(() => {
    if (Object.keys(mapping).length === 0 && Object.keys(suggestions).length > 0) {
      const initialMapping: Record<string, string> = {}
      for (const [target, source] of Object.entries(suggestions)) {
        if (source && targetNames.has(target)) {
          initialMapping[source] = target
        }
      }
      onMappingChange(initialMapping)
    }
  }, [suggestions, mapping, onMappingChange, targetNames])

  // Get which target columns are already mapped
  const mappedTargets = useMemo(() => {
    return new Set(Object.values(mapping).filter((target) => targetNames.has(target)))
  }, [mapping, targetNames])

  // Check which required columns are missing
  const missingRequired = useMemo(() => {
    return targetColumns
      .filter((col) => col.required && !mappedTargets.has(col.name))
      .map((col) => col.name)
  }, [targetColumns, mappedTargets])

  const skippedColumns = useMemo(() => {
    return sourceColumns.filter((sourceCol) => !targetNames.has(mapping[sourceCol] ?? ''))
  }, [sourceColumns, mapping, targetNames])

  const handleMappingChange = (sourceColumn: string, targetColumn: string) => {
    const newMapping = { ...mapping }
    if (targetColumn === '') {
      delete newMapping[sourceColumn]
    } else {
      // Remove any existing mapping to this target
      for (const [key, value] of Object.entries(newMapping)) {
        if (value === targetColumn && key !== sourceColumn) {
          delete newMapping[key]
        }
      }
      newMapping[sourceColumn] = targetColumn
    }
    onMappingChange(newMapping)
  }

  // Check if a source column has a suggestion
  const hasSuggestion = (sourceCol: string): boolean => {
    return Object.entries(suggestions).some(([target, source]) => {
      return source === sourceCol && targetNames.has(target)
    })
  }

  return (
    <div className="space-y-6">
      {/* Missing Required Warning */}
      {missingRequired.length > 0 && (
        <div className={`flex items-start gap-2 rounded-lg ${colorTokens.intent.caution.bgSubtle} p-4 ${colorTokens.intent.caution.textStronger}`}>
          <AlertCircle className="h-5 w-5 flex-shrink-0 mt-0.5" />
          <div>
            <p className="font-medium">{t('mapping.missingRequired')}</p>
            <p className="mt-1 text-sm">
              {missingRequired.join(', ')}
            </p>
          </div>
        </div>
      )}

      {/* Mapping Table */}
      <div className={`overflow-hidden rounded-lg border ${colorTokens.border.subtle}`}>
        <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
          <thead className={colorTokens.surface.page}>
            <tr>
              <th className={`px-4 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                {t('mapping.sourceColumn')}
              </th>
              <th className={`px-4 py-3 text-center text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle} w-12`}>
                &nbsp;
              </th>
              <th className={`px-4 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                {t('mapping.targetColumn')}
              </th>
              <th className={`px-4 py-3 text-center text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle} w-20`}>
                {t('mapping.status')}
              </th>
            </tr>
          </thead>
          <tbody className={`divide-y ${colorTokens.border.divider} ${colorTokens.surface.base}`}>
            {sourceColumns.map((sourceCol) => {
              const currentTarget = mapping[sourceCol]
              const isMapped = Boolean(currentTarget && targetNames.has(currentTarget))
              const suggested = hasSuggestion(sourceCol)

              return (
                <tr key={sourceCol} className={colorTokens.intent.neutral.bgHover}>
                  <td className="whitespace-nowrap px-4 py-3">
                    <div className="flex items-center gap-2">
                      <span className={`font-medium ${colorTokens.text.primary}`}>{sourceCol}</span>
                      {suggested && (
                        <span className={`text-xs ${colorTokens.intent.success.text}`}>
                          {t('mapping.suggested')}
                        </span>
                      )}
                    </div>
                  </td>
                  <td className="px-4 py-3 text-center">
                    <ArrowRight className={`h-4 w-4 ${colorTokens.text.disabled} mx-auto`} />
                  </td>
                  <td className="px-4 py-3">
                    <select
                      value={isMapped ? currentTarget : ''}
                      onChange={(e) => { handleMappingChange(sourceCol, e.target.value) }}
                      className={cn(
                        `block w-full rounded-md border px-3 py-2 text-sm focus:outline-none focus:ring-1 ${colorTokens.focus.primaryRing}`,
                        isMapped
                          ? `${colorTokens.intent.success.border} ${colorTokens.intent.success.bgSubtle}`
                          : colorTokens.border.default
                      )}
                    >
                      <option value="">{t('mapping.skipColumn')}</option>
                      <optgroup label={t('mapping.requiredFields')}>
                        {targetColumns
                          .filter((col) => col.required)
                          .map((col) => (
                            <option
                              key={col.name}
                              value={col.name}
                              disabled={
                                mappedTargets.has(col.name) &&
                                mapping[sourceCol] !== col.name
                              }
                            >
                              {col.name}
                              {col.required ? ' *' : ''}
                              {mappedTargets.has(col.name) &&
                                mapping[sourceCol] !== col.name
                                ? ` (${t('mapping.alreadyMapped')})`
                                : ''}
                            </option>
                          ))}
                      </optgroup>
                      <optgroup label={t('mapping.optionalFields')}>
                        {targetColumns
                          .filter((col) => !col.required)
                          .map((col) => (
                            <option
                              key={col.name}
                              value={col.name}
                              disabled={
                                mappedTargets.has(col.name) &&
                                mapping[sourceCol] !== col.name
                              }
                            >
                              {col.name}
                              {mappedTargets.has(col.name) &&
                                mapping[sourceCol] !== col.name
                                ? ` (${t('mapping.alreadyMapped')})`
                                : ''}
                            </option>
                          ))}
                      </optgroup>
                    </select>
                  </td>
                  <td className="px-4 py-3 text-center">
                    {isMapped ? (
                      <Check data-testid={`mapped-status-${sourceCol}`} className={`h-5 w-5 ${colorTokens.intent.success.text} mx-auto`} />
                    ) : (
                      <HelpCircle data-testid={`skipped-status-${sourceCol}`} className={`h-5 w-5 ${colorTokens.text.faint} mx-auto`} />
                    )}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </DataTable>
      </div>

      {skippedColumns.length > 0 && (
        <div className={`flex items-start gap-2 rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.page} p-4 ${colorTokens.text.secondary}`}>
          <HelpCircle className={`mt-0.5 h-5 w-5 flex-shrink-0 ${colorTokens.text.disabled}`} />
          <p className="text-sm">
            {t('mapping.skippedColumnsNotice', { columns: skippedColumns.join(', ') })}
          </p>
        </div>
      )}

      {/* Legend */}
      <div className={`flex flex-wrap gap-4 text-sm ${colorTokens.text.subtle}`}>
        <div className="flex items-center gap-1.5">
          <span className={`h-2 w-2 rounded-full ${colorTokens.intent.success.bg}`} />
          {t('mapping.legendMapped')}
        </div>
        <div className="flex items-center gap-1.5">
          <span className={`h-2 w-2 rounded-full ${colorTokens.surface.disabled}`} />
          {t('mapping.legendSkipped')}
        </div>
        <div className="flex items-center gap-1.5">
          <span className={colorTokens.intent.danger.text}>*</span>
          {t('mapping.legendRequired')}
        </div>
      </div>
    </div>
  )
}
