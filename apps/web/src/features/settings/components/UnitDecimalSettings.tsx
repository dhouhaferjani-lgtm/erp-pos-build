import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Loader2, Save } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { useUnits, uomUnitsInvalidationPredicate, uomCategoriesInvalidationPredicate } from '../../uom/hooks/useUnits'
import { updateUnitPrecision } from '../../uom/api/uomApi'
import type { Unit, RoundingMethod } from '../../uom/api/uomApi'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import { tokens, textColors, borderColors , semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'
import { Button, Input, Select } from '@/components/atoms'

// ─── Types ────────────────────────────────────────────────────────────────────

/**
 * Per-row editable state: only the two precision fields.
 */
interface RowDraft {
  decimal_places: number
  rounding_method: RoundingMethod
}

const ROUNDING_METHODS: RoundingMethod[] = ['HalfUp', 'Floor', 'Ceil']

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Map the legacy snake_case rounding method stored on the Unit to the
 * PascalCase variant required by the precision endpoint.
 */
function toRoundingMethod(raw: string): RoundingMethod {
  if (raw === 'HalfUp' || raw === 'Floor' || raw === 'Ceil') return raw
  if (raw === 'half_up') return 'HalfUp'
  if (raw === 'floor') return 'Floor'
  if (raw === 'ceil') return 'Ceil'
  return 'HalfUp'
}

function initDraft(unit: Unit): RowDraft {
  return {
    decimal_places: unit.decimal_places,
    rounding_method: toRoundingMethod(unit.rounding_method),
  }
}

// ─── Sub-components ───────────────────────────────────────────────────────────

interface NarrowingWarningProps {
  message: string
}

function NarrowingWarning({ message }: NarrowingWarningProps) {
  return (
    <div
      className={`flex items-start gap-2 rounded-md p-2 ${tokens.alert.warning}`}
      data-testid="narrowing-warning"
    >
      <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
      <span className="text-xs">{message}</span>
    </div>
  )
}

// ─── Row ──────────────────────────────────────────────────────────────────────

interface UnitRowProps {
  unit: Unit
  draft: RowDraft
  originalDecimalPlaces: number
  onDraftChange: (draft: RowDraft) => void
  onSave: () => void
  isSaving: boolean
  t: (key: string) => string
}

function UnitRow({
  unit,
  draft,
  originalDecimalPlaces,
  onDraftChange,
  onSave,
  isSaving,
  t,
}: UnitRowProps) {
  const isNarrowing = draft.decimal_places < originalDecimalPlaces

  return (
    <>
      <tr className={tokens.table.rowHover} data-testid={`unit-row-${unit.id}`}>
        {/* Code */}
        <td className="whitespace-nowrap px-4 py-3 text-sm">
          <code className={tokens.table.cellMonoBadge}>{unit.code}</code>
        </td>

        {/* Name */}
        <td className={`whitespace-nowrap px-4 py-3 text-sm ${textColors.primary}`}>
          {unit.name}
        </td>

        {/* Decimal places */}
        <td className="px-4 py-3">
          <Input
            type="number"
            min={0}
            max={10}
            value={draft.decimal_places}
            onChange={(e) => {
              const val = Math.max(0, Math.min(10, parseInt(e.target.value, 10) || 0))
              onDraftChange({ ...draft, decimal_places: val })
            }}
            data-testid={`decimal-places-${unit.id}`}
            className={`w-20 `}
          />
        </td>

        {/* Rounding method */}
        <td className="px-4 py-3">
          <Select
            value={draft.rounding_method}
            onChange={(e) => {
              const v = e.target.value
              const method: RoundingMethod =
                v === 'HalfUp' || v === 'Floor' || v === 'Ceil' ? v : 'HalfUp'
              onDraftChange({
                ...draft,
                rounding_method: method,
              })
            }}
            data-testid={`rounding-method-${unit.id}`}
          >
            {ROUNDING_METHODS.map((m) => (
              <option key={m} value={m}>
                {t(`uom:precisionSettings.roundingOptions.${m}`)}
              </option>
            ))}
          </Select>
        </td>

        {/* Save button */}
        <td className="px-4 py-3 text-right">
          <Button size="sm"
            type="button"
            onClick={onSave}
            disabled={isSaving}
            data-testid={`save-${unit.id}`}
            className="gap-1"
          >
            {isSaving ? (
              <Loader2 className="h-3.5 w-3.5 animate-spin" />
            ) : (
              <Save className="h-3.5 w-3.5" />
            )}
            {isSaving
              ? t('uom:precisionSettings.saving')
              : t('uom:precisionSettings.save')}
          </Button>
        </td>
      </tr>

      {/* Narrowing warning row — rendered inline below the edited row */}
      {isNarrowing && (
        <tr data-testid={`narrowing-row-${unit.id}`}>
          <td colSpan={5} className="px-4 pb-2 pt-0">
            <NarrowingWarning message={t('uom:precisionSettings.narrowingWarning')} />
          </td>
        </tr>
      )}
    </>
  )
}

// ─── Main component ───────────────────────────────────────────────────────────

/**
 * UnitDecimalSettings
 *
 * Renders a table of all units with inline-editable decimal_places and
 * rounding_method fields. Each row has its own Save button that calls
 * PUT uom/units/{id} with the updated precision payload.
 *
 * A warning banner is shown when the user narrows a unit's scale
 * (new decimal_places < current).
 *
 * Route wiring is pending — the orchestrator will place this component
 * inside the settings/units page or a dedicated sub-route.
 */
export function UnitDecimalSettings() {
  const { t } = useTranslation(['uom', 'common'])
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  const { data: units, isLoading } = useUnits()

  // Per-unit draft state: keyed by unit.id
  const [drafts, setDrafts] = useState<Record<string, RowDraft>>({})

  // Per-unit saving state: keyed by unit.id
  const [savingIds, setSavingIds] = useState<Set<string>>(new Set())

  const mutation = useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: { decimal_places: number; rounding_method: RoundingMethod } }) =>
      updateUnitPrecision(id, payload),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: uomUnitsInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: uomCategoriesInvalidationPredicate(tenantId, companyId),
        }),
      ])
    },
  })

  /**
   * Return the draft for a given unit, falling back to the unit's current values.
   */
  function getDraft(unit: Unit): RowDraft {
    return drafts[unit.id] ?? initDraft(unit)
  }

  function handleDraftChange(unitId: string, draft: RowDraft) {
    setDrafts((prev) => ({ ...prev, [unitId]: draft }))
  }

  async function handleSave(unit: Unit) {
    const draft = getDraft(unit)

    setSavingIds((prev) => {
      const next = new Set(prev)
      next.add(unit.id)
      return next
    })

    try {
      await mutation.mutateAsync({ id: unit.id, payload: draft })
      toast.success(t('uom:precisionSettings.saved'))
      // Clear the draft so the row reflects fresh server data after re-fetch
      setDrafts((prev) => {
        const { [unit.id]: _removed, ...rest } = prev
        return rest
      })
    } catch {
      toast.error(t('uom:precisionSettings.saveError'))
    } finally {
      setSavingIds((prev) => {
        const next = new Set(prev)
        next.delete(unit.id)
        return next
      })
    }
  }

  // ── Render ────────────────────────────────────────────────────────────────

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className={`h-8 w-8 animate-spin ${textColors.brand}`} />
      </div>
    )
  }

  if (!units || units.length === 0) {
    return (
      <p className={`py-8 text-center text-sm ${textColors.tertiary}`}>
        {t('uom:noUnits')}
      </p>
    )
  }

  return (
    <div className="space-y-4">
      {/* Section header */}
      <div>
        <h2 className={`text-lg font-semibold ${textColors.primary}`}>
          {t('uom:precisionSettings.title')}
        </h2>
        <p className={`mt-1 text-sm ${textColors.tertiary}`}>
          {t('uom:precisionSettings.description')}
        </p>
      </div>

      {/* Table */}
      <div className={`overflow-hidden rounded-lg border ${borderColors.light}`}>
        <div className="overflow-x-auto">
          <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`} data-testid="unit-precision-table">
            <thead className={tokens.table.header}>
              <tr>
                <th
                  scope="col"
                  className={`px-4 py-3 text-left text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}
                >
                  {t('uom:precisionSettings.columns.code')}
                </th>
                <th
                  scope="col"
                  className={`px-4 py-3 text-left text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}
                >
                  {t('uom:precisionSettings.columns.name')}
                </th>
                <th
                  scope="col"
                  className={`px-4 py-3 text-left text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}
                >
                  {t('uom:precisionSettings.columns.decimalPlaces')}
                </th>
                <th
                  scope="col"
                  className={`px-4 py-3 text-left text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}
                >
                  {t('uom:precisionSettings.columns.roundingMethod')}
                </th>
                <th scope="col" className="px-4 py-3" />
              </tr>
            </thead>
            <tbody className={`divide-y ${colorTokens.border.divider} bg-white`}>
              {units.map((unit) => {
                const draft = getDraft(unit)
                return (
                  <UnitRow
                    key={unit.id}
                    unit={unit}
                    draft={draft}
                    originalDecimalPlaces={unit.decimal_places}
                    onDraftChange={(d) => { handleDraftChange(unit.id, d) }}
                    onSave={() => { void handleSave(unit) }}
                    isSaving={savingIds.has(unit.id)}
                    t={t}
                  />
                )
              })}
            </tbody>
          </DataTable>
        </div>
      </div>
    </div>
  )
}
