import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Link2 } from 'lucide-react'
import { Button } from '@/components/atoms/Button/Button'
import { Select } from '@/components/atoms/Select/Select'
import { Spinner } from '@/components/atoms/Spinner/Spinner'
import { DataTable } from '@/components/molecules/DataTable/DataTable'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { getErrorMessage } from '@/lib/api'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import type { UnmappedUnitText } from '../api/uomApi'
import { useApplyUnitTextMapping, useUnits, useUnmappedUnitTexts } from '../hooks/useUnits'

interface PendingMapping {
  sourceText: string | null
  sourceLabel: string
  targetUnitId: string
  targetUnitCode: string
}

function sourceTestId(sourceText: string | null): string {
  if (sourceText === null) return 'blank'
  return sourceText.toLowerCase().replace(/[^a-z0-9]+/g, '-')
}

export function UnmappedUnitTextsPanel() {
  const { t } = useTranslation(['common', 'uom'])
  const { data: sources, error: sourcesError, isLoading: sourcesLoading } = useUnmappedUnitTexts()
  const { data: units, error: unitsError, isLoading: unitsLoading } = useUnits()
  const applyMapping = useApplyUnitTextMapping()
  const [targets, setTargets] = useState<Record<string, string>>({})
  const [pending, setPending] = useState<PendingMapping | null>(null)

  const activeUnits = useMemo(
    () => (units ?? []).filter((unit) => unit.isActive).sort((left, right) => left.code.localeCompare(right.code)),
    [units],
  )
  const pcUnit = activeUnits.find((unit) => unit.code === 'pc')

  const selectedTarget = (source: UnmappedUnitText): string => {
    if (source.sourceText === null) return pcUnit?.id ?? ''
    return targets[source.sourceText] ?? ''
  }

  const prepareMapping = (source: UnmappedUnitText) => {
    const targetUnitId = selectedTarget(source)
    const target = activeUnits.find((unit) => unit.id === targetUnitId)
    if (!target) return

    setPending({
      sourceText: source.sourceText,
      sourceLabel: source.sourceText ?? t('uom:unmapped.blank'),
      targetUnitId,
      targetUnitCode: target.code,
    })
  }

  const confirmMapping = async () => {
    if (!pending) return

    try {
      const result = await applyMapping.mutateAsync({
        sourceText: pending.sourceText,
        targetUnitId: pending.targetUnitId,
      })
      toast.success(t(
        result.aliasStored && !result.applied
          ? 'uom:unmapped.successAliasStored'
          : 'uom:unmapped.success',
      ))
      setPending(null)
    } catch (error: unknown) {
      toast.error(getErrorMessage(error))
    }
  }

  const isLoading = sourcesLoading || unitsLoading
  const hasError = sourcesError !== null || unitsError !== null

  return (
    <section
      className={`overflow-hidden rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base}`}
      data-testid="unmapped-unit-texts-panel"
    >
      <div className={`border-b px-6 py-5 ${colorTokens.border.subtle}`}>
        <div className="flex items-start gap-3">
          <div className={`mt-0.5 rounded-md p-2 ${colorTokens.intent.primary.bgSubtle} ${colorTokens.intent.primary.text}`}>
            <Link2 className="h-5 w-5" aria-hidden="true" />
          </div>
          <div>
            <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>{t('uom:unmapped.title')}</h2>
            <p className={`mt-1 text-sm ${colorTokens.text.muted}`}>{t('uom:unmapped.description')}</p>
          </div>
        </div>
      </div>

      {isLoading ? (
        <div className="flex h-32 items-center justify-center"><Spinner size="lg" /></div>
      ) : hasError ? (
        <p className={`px-6 py-8 text-sm ${colorTokens.intent.danger.textSubtle}`}>{t('uom:unmapped.loadError')}</p>
      ) : sources?.length === 0 ? (
        <p className={`px-6 py-8 text-sm ${colorTokens.text.muted}`}>{t('uom:unmapped.empty')}</p>
      ) : (
        <div className="overflow-x-auto">
          <DataTable className="min-w-full">
            <thead className={colorTokens.surface.page}>
              <tr>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>{t('uom:unmapped.columns.source')}</th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>{t('uom:unmapped.columns.count')}</th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>{t('uom:unmapped.columns.target')}</th>
                <th className="px-6 py-3"><span className="sr-only">{t('common:table.actionsColumn')}</span></th>
              </tr>
            </thead>
            <tbody className={`divide-y ${colorTokens.border.divider}`}>
              {sources?.map((source) => {
                const key = source.sourceText ?? '__blank__'
                const targetId = selectedTarget(source)
                return (
                  <tr key={key} data-testid={`unit-text-source-${sourceTestId(source.sourceText)}`}>
                    <td className={`px-6 py-4 text-sm font-medium ${colorTokens.text.primary}`}>
                      <code className={`rounded px-2 py-1 ${colorTokens.surface.muted}`}>{source.sourceText ?? t('uom:unmapped.blank')}</code>
                    </td>
                    <td className="px-6 py-4">
                      <div className={`text-sm font-semibold ${colorTokens.text.primary}`}>{source.totalCount}</div>
                      <div className={`text-xs ${colorTokens.text.muted}`}>
                        {t('uom:unmapped.products', { count: source.productCount })}
                        {' · '}
                        {t('uom:unmapped.importRows', { count: source.importRowCount })}
                        {source.pendingImportCount > 1 && (
                          <>
                            {' '}
                            {t('uom:unmapped.pendingImports', { count: source.pendingImportCount })}
                          </>
                        )}
                      </div>
                    </td>
                    <td className="min-w-64 px-6 py-4">
                      <Select
                        aria-label={t('uom:unmapped.targetLabel', { source: source.sourceText ?? t('uom:unmapped.blank') })}
                        className="w-full"
                        disabled={source.sourceText === null}
                        onChange={(event) => {
                          const sourceText = source.sourceText
                          if (sourceText !== null) {
                            setTargets((current) => ({ ...current, [sourceText]: event.target.value }))
                          }
                        }}
                        value={targetId}
                      >
                        {source.sourceText !== null && <option value="">{t('uom:unmapped.selectTarget')}</option>}
                        {activeUnits.map((unit) => (
                          <option key={unit.id} value={unit.id}>{unit.code} — {unit.name}</option>
                        ))}
                      </Select>
                    </td>
                    <td className="px-6 py-4 text-end">
                      <Button
                        disabled={targetId === '' || applyMapping.isPending}
                        onClick={() => { prepareMapping(source) }}
                        size="sm"
                        variant="secondary"
                      >
                        {t('uom:unmapped.map')}
                      </Button>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </DataTable>
        </div>
      )}

      <ConfirmDialog
        confirmText={t('uom:unmapped.map')}
        isLoading={applyMapping.isPending}
        isOpen={pending !== null}
        message={t('uom:unmapped.confirm', { source: pending?.sourceLabel ?? '', code: pending?.targetUnitCode ?? '' })}
        onClose={() => { setPending(null) }}
        onConfirm={() => { void confirmMapping() }}
        title={t('uom:unmapped.confirmTitle')}
        variant="info"
      />
    </section>
  )
}
