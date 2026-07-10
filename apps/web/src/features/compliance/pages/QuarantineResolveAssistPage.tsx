import { useMemo, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation } from '@tanstack/react-query'
import { AlertTriangle, CheckCircle2, FileSearch, Loader2, Save } from 'lucide-react'
import { getErrorMessage } from '@/lib/api'
import { tokens, textColors, borderColors, focusRing, colors } from '@/lib/designTokens'
import { bestEffortParseQuarantine, resolveParseFailure, type BestEffortParseResponse } from '../api/quarantineResolutionApi'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import { Button } from '@/components/atoms/Button'

export function QuarantineResolveAssistPage() {
  const { t } = useTranslation(['compliance'])
  const [quarantineId, setQuarantineId] = useState('')
  const [result, setResult] = useState<BestEffortParseResponse | null>(null)
  const [defectEdits, setDefectEdits] = useState<Record<string, string>>({})

  const resolveMutation = useMutation({
    mutationFn: ({ fiscalEventId, correctedPayload }: { fiscalEventId: string; correctedPayload: Record<string, unknown> }) =>
      resolveParseFailure(fiscalEventId, correctedPayload),
  })

  const parseMutation = useMutation({
    mutationFn: bestEffortParseQuarantine,
    onSuccess: (response) => {
      setResult(response)
      setDefectEdits(initialDefectEdits(response))
      resolveMutation.reset()
    },
  })

  const parsedJson = useMemo(
    () => JSON.stringify(result?.parsed ?? {}, null, 2),
    [result?.parsed],
  )

  const editableDefects = useMemo(
    () => result?.defects.filter(isEditablePayloadDefect) ?? [],
    [result?.defects],
  )

  const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const trimmed = quarantineId.trim()
    if (trimmed.length === 0) return
    parseMutation.mutate(trimmed)
  }

  const handleResolve = () => {
    if (!result?.fiscal_event_id || result.source !== 'fiscal_events') return
    resolveMutation.mutate({
      fiscalEventId: result.fiscal_event_id,
      correctedPayload: buildCorrectedPayload(result.parsed, defectEdits),
    })
  }

  return (
    <div className="space-y-6">
      <div>
        <PageHeaderTitle className={`flex items-center gap-2 text-2xl font-bold ${textColors.primary}`}>
          <FileSearch className={`h-6 w-6 ${textColors.tertiary}`} />
          {t('compliance:quarantine.pageTitle')}
        </PageHeaderTitle>
        <p className={`mt-1 text-sm ${textColors.tertiary}`}>
          {t('compliance:quarantine.pageSubtitle')}
        </p>
      </div>

      <form onSubmit={handleSubmit} className={`${tokens.card.base} p-4`}>
        <label htmlFor="quarantine-id" className={tokens.label.base}>
          {t('compliance:quarantine.idLabel')}
        </label>
        <div className="mt-2 flex flex-col gap-3 sm:flex-row">
          <input
            id="quarantine-id"
            value={quarantineId}
            onChange={(event) => {
              setQuarantineId(event.target.value)
            }}
            placeholder="00000000-0000-4000-8000-000000000000"
            className={`min-w-0 flex-1 rounded-md border ${borderColors.default} px-3 py-2 text-sm shadow-sm ${focusRing.default} ${focusRing.primary}`}
          />
          <Button
            type="submit"
            disabled={parseMutation.isPending || quarantineId.trim().length === 0}
            className="gap-2"
          >
            {parseMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <FileSearch className="h-4 w-4" />}
            {t('compliance:quarantine.parseButton')}
          </Button>
        </div>
      </form>

      {parseMutation.isError && (
        <div role="alert" className={`rounded-lg p-4 text-sm ${tokens.alert.base} ${tokens.alert.error}`}>
          {getErrorMessage(parseMutation.error)}
        </div>
      )}

      {resolveMutation.isError && (
        <div role="alert" className={`rounded-lg p-4 text-sm ${tokens.alert.base} ${tokens.alert.error}`}>
          {getErrorMessage(resolveMutation.error)}
        </div>
      )}

      {resolveMutation.isSuccess && (
        <div className={`rounded-lg p-4 text-sm ${tokens.alert.base} ${tokens.alert.success}`}>
          {t('compliance:quarantine.resolvedSuccess')}
        </div>
      )}

      {result && (
        <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
          <section className={`rounded-lg border ${borderColors.light} bg-white shadow-sm`}>
            <div className={`border-b ${borderColors.light} px-4 py-3`}>
              <div className="flex flex-wrap items-center gap-2">
                <span className={`rounded px-2 py-1 text-xs font-medium ${tokens.badge.gray}`}>
                  {result.event_type}
                </span>
                <span className={`rounded px-2 py-1 text-xs font-medium ${tokens.badge.gray}`}>
                  {result.source}
                </span>
                {result.defects.length === 0 ? (
                  <span className={`inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-medium ${tokens.badge.green}`}>
                    <CheckCircle2 className="h-3.5 w-3.5" />
                    {t('compliance:quarantine.noDefects')}
                  </span>
                ) : (
                  <span className={`inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-medium ${tokens.badge.yellow}`}>
                    <AlertTriangle className="h-3.5 w-3.5" />
                    {t('compliance:quarantine.defectsCount', { count: result.defects.length })}
                  </span>
                )}
              </div>
            </div>
            <textarea
              readOnly
              value={parsedJson}
              aria-label={t('compliance:quarantine.parsedPayloadAriaLabel')}
              className={`h-[560px] w-full resize-none border-0 ${colors.neutral['900']} p-4 font-mono text-xs ${textColors.inverse} outline-none`}
            />
          </section>

          <section className={`${tokens.card.base} p-4`}>
            <h2 className={`text-sm font-semibold ${textColors.primary}`}>{t('compliance:quarantine.defectsSectionTitle')}</h2>
            {result.defects.length === 0 ? (
              <p className={`mt-3 text-sm ${textColors.tertiary}`}>{t('compliance:quarantine.payloadPassedParsing')}</p>
            ) : (
              <ul className="mt-3 space-y-3">
                {result.defects.map((defect) => (
                  <li key={`${defect.path}:${defect.code}:${defect.message}`} className={`rounded-md border p-3 ${tokens.alert.warning}`}>
                    <div className="font-mono text-xs font-semibold">{defect.path}</div>
                    <div className="mt-1 text-xs font-medium uppercase">{defect.code}</div>
                    {/* defect.message is a backend domain string — not a UI literal */}
                    <p className="mt-2 text-sm">{defect.message}</p>
                  </li>
                ))}
              </ul>
            )}

            <div className={`mt-6 border-t ${borderColors.light} pt-4`}>
              <h2 className={`text-sm font-semibold ${textColors.primary}`}>{t('compliance:quarantine.correctionsSectionTitle')}</h2>
              {editableDefects.length === 0 ? (
                <p className={`mt-3 text-sm ${textColors.tertiary}`}>{t('compliance:quarantine.noEditableDefects')}</p>
              ) : (
                <div className="mt-3 space-y-3">
                  {editableDefects.map((defect) => (
                    <label key={`edit:${defect.path}:${defect.code}`} className="block">
                      <span className={`font-mono text-xs font-semibold ${textColors.secondary}`}>{defect.path}</span>
                      <textarea
                        value={defectEdits[defect.path] ?? ''}
                        onChange={(event) => {
                          setDefectEdits((current) => ({
                            ...current,
                            [defect.path]: event.target.value,
                          }))
                        }}
                        aria-label={t('compliance:quarantine.correctedValueAriaLabel', { path: defect.path })}
                        className={`mt-1 h-24 w-full resize-y rounded-md border ${borderColors.default} px-3 py-2 font-mono text-xs shadow-sm ${focusRing.default} ${focusRing.primary}`}
                      />
                    </label>
                  ))}
                </div>
              )}

              {result.source === 'fiscal_event_quarantine' && (
                <p className={`mt-4 text-sm ${textColors.tertiary}`}>
                  {t('compliance:quarantine.quarantineRowInspectNote')}
                </p>
              )}

              <Button
                type="button"
                onClick={handleResolve}
                disabled={
                  resolveMutation.isPending
                  || result.source !== 'fiscal_events'
                  || result.fiscal_event_id === null
                }
                className="mt-4 w-full gap-2"
              >
                {resolveMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
                {t('compliance:quarantine.submitCorrectionButton')}
              </Button>
            </div>
          </section>
        </div>
      )}
    </div>
  )
}

function stringifyEditValue(value: unknown): string {
  if (value === undefined) return ''
  if (typeof value === 'string') return value
  return JSON.stringify(value, null, 2)
}

function initialDefectEdits(result: BestEffortParseResponse): Record<string, string> {
  const initialEdits: Record<string, string> = {}
  for (const defect of result.defects) {
    if (!isEditablePayloadDefect(defect)) continue
    initialEdits[defect.path] = stringifyEditValue(valueAtPayloadPath(result.parsed, defect.path))
  }
  return initialEdits
}

function isEditablePayloadDefect(defect: { path: string; code: string }): boolean {
  return defect.path.startsWith('payload.') && defect.code !== 'payload_extra_field'
}

function valueAtPayloadPath(payload: Record<string, unknown>, defectPath: string): unknown {
  const segments = payloadSegments(defectPath)
  let current: unknown = payload
  for (const segment of segments) {
    if (current === null || typeof current !== 'object' || Array.isArray(current)) return undefined
    current = (current as Record<string, unknown>)[segment]
  }
  return current
}

function buildCorrectedPayload(
  parsed: Record<string, unknown>,
  edits: Record<string, string>
): Record<string, unknown> {
  const corrected = cloneRecord(parsed)
  for (const [path, rawValue] of Object.entries(edits)) {
    const segments = payloadSegments(path)
    if (segments.length === 0) continue
    setPayloadPath(corrected, segments, parseEditValue(rawValue))
  }
  return corrected
}

function payloadSegments(defectPath: string): string[] {
  if (!defectPath.startsWith('payload.')) return []
  return defectPath.slice('payload.'.length).split('.').filter(Boolean)
}

function parseEditValue(rawValue: string): unknown {
  try {
    return JSON.parse(rawValue)
  } catch {
    return rawValue
  }
}

function cloneRecord(value: Record<string, unknown>): Record<string, unknown> {
  return JSON.parse(JSON.stringify(value)) as Record<string, unknown>
}

function setPayloadPath(target: Record<string, unknown>, segments: string[], value: unknown): void {
  let current: Record<string, unknown> = target
  for (const segment of segments.slice(0, -1)) {
    const next = current[segment]
    if (next === null || typeof next !== 'object' || Array.isArray(next)) {
      current[segment] = {}
    }
    current = current[segment] as Record<string, unknown>
  }
  current[segments[segments.length - 1]] = value
}
