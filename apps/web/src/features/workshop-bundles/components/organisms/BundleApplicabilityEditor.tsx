import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, X } from 'lucide-react'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import type { ServiceBundleData, VehicleTypeRef } from '../../types'
import { useReplaceBundleApplicabilities } from '../../hooks/useBundles'
import { VehicleApplicabilityChip } from '../atoms/VehicleApplicabilityChip'

const VEHICLE_TYPE_OPTIONS: readonly VehicleTypeRef[] = [
  'pc',
  'cv',
  'mtb',
  'eng',
  'axl',
  'universal',
]

/**
 * Applicability rule in its draft/edit form. `vehicle_type` and
 * `vehicle_display` must either both be empty (universal) or both be
 * filled (scoped) — matches the server invariant in
 * BundleAuthoringService::assertApplicabilityShape.
 *
 * `platform_vehicle_id` is tracked so existing rows authored via the
 * (future) PlatformVehiclePicker flow don't get dropped, but there is
 * no UI path to set it from this component — we only preserve it.
 */
interface DraftRule {
  platform_vehicle_id: string | null
  vehicle_type: VehicleTypeRef | ''
  vehicle_display: string
  year_from: string
  year_to: string
}

function fromExisting(existing: ServiceBundleData['vehicle_applicabilities'][number]): DraftRule {
  return {
    platform_vehicle_id: existing.platform_vehicle_id,
    vehicle_type: existing.vehicle_type ?? '',
    vehicle_display: existing.vehicle_display ?? '',
    year_from: existing.year_from !== null ? String(existing.year_from) : '',
    year_to: existing.year_to !== null ? String(existing.year_to) : '',
  }
}

interface BundleApplicabilityEditorProps {
  bundle: ServiceBundleData
}

export function BundleApplicabilityEditor({ bundle }: BundleApplicabilityEditorProps) {
  const { t } = useTranslation('workshop-bundles')
  const replaceMutation = useReplaceBundleApplicabilities(bundle.id)

  const [isAddOpen, setIsAddOpen] = useState(false)
  const [draft, setDraft] = useState<DraftRule>({
    platform_vehicle_id: null,
    vehicle_type: '',
    vehicle_display: '',
    year_from: '',
    year_to: '',
  })
  const [addError, setAddError] = useState<string | null>(null)

  const existingRules: DraftRule[] = bundle.vehicle_applicabilities.map(fromExisting)

  function resetDraft(): void {
    setDraft({
      platform_vehicle_id: null,
      vehicle_type: '',
      vehicle_display: '',
      year_from: '',
      year_to: '',
    })
    setAddError(null)
  }

  function buildPayloadRule(rule: DraftRule): {
    platform_vehicle_id: string | null
    vehicle_type: string | null
    vehicle_display: string | null
    year_from: number | null
    year_to: number | null
  } {
    const vehicleType = rule.vehicle_type === '' ? null : rule.vehicle_type
    const vehicleDisplay = rule.vehicle_display.trim() === '' ? null : rule.vehicle_display.trim()
    return {
      platform_vehicle_id: rule.platform_vehicle_id,
      vehicle_type: vehicleType,
      vehicle_display: vehicleDisplay,
      year_from: rule.year_from === '' ? null : Number.parseInt(rule.year_from, 10),
      year_to: rule.year_to === '' ? null : Number.parseInt(rule.year_to, 10),
    }
  }

  function submitReplacement(nextRules: DraftRule[]): void {
    replaceMutation.mutate({
      applicabilities: nextRules.map(buildPayloadRule),
    })
  }

  function handleAdd(): void {
    // Enforce both-null-or-both-non-null invariant client-side to match
    // the server guard in BundleAuthoringService::assertApplicabilityShape.
    const hasType = draft.vehicle_type !== ''
    const hasDisplay = draft.vehicle_display.trim() !== ''
    const hasPlatformId = draft.platform_vehicle_id !== null
    const isScoped = hasPlatformId || hasType

    if (isScoped && !hasType) {
      setAddError(t('authoring.applicability.errors.pairInvariant'))
      return
    }
    if (hasType && !hasDisplay) {
      setAddError(t('authoring.applicability.errors.pairInvariant'))
      return
    }

    if (draft.year_from !== '' && draft.year_to !== '') {
      const from = Number.parseInt(draft.year_from, 10)
      const to = Number.parseInt(draft.year_to, 10)
      if (from > to) {
        setAddError(t('authoring.applicability.errors.yearRange'))
        return
      }
    }

    const next = [...existingRules, draft]
    submitReplacement(next)
    setIsAddOpen(false)
    resetDraft()
  }

  function handleRemove(index: number): void {
    const next = existingRules.filter((_, i) => i !== index)
    submitReplacement(next)
  }

  return (
    <section className={`rounded-lg border ${borderColors.light} bg-white p-4`}>
      <div className="mb-3 flex items-center justify-between">
        <h2 className={`text-sm font-medium uppercase tracking-wide ${textColors.tertiary}`}>
          {t('authoring.applicability.sectionTitle')}
        </h2>
        <button
          type="button"
          onClick={() => {
            setIsAddOpen(true)
          }}
          className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.sm} inline-flex items-center gap-1`}
          data-testid="bundle-applicability-add-btn"
        >
          <Plus className="h-4 w-4" aria-hidden />
          {t('authoring.applicability.addRule')}
        </button>
      </div>

      {bundle.vehicle_applicabilities.length === 0 ? (
        <p className={`text-sm ${textColors.tertiary}`}>{t('detail.emptyApplicabilities')}</p>
      ) : (
        <ul className="flex flex-wrap gap-2" data-testid="bundle-applicability-list">
          {bundle.vehicle_applicabilities.map((applicability, idx) => (
            <li key={applicability.id} className="flex items-center gap-1">
              <VehicleApplicabilityChip applicability={applicability} />
              <button
                type="button"
                onClick={() => {
                  handleRemove(idx)
                }}
                aria-label={t('authoring.applicability.remove')}
                className={`${textColors.tertiary} ${textColors.hoverPrimary}`}
                data-testid={`bundle-applicability-remove-${applicability.id}`}
              >
                <X className="h-3.5 w-3.5" aria-hidden />
              </button>
            </li>
          ))}
        </ul>
      )}

      {isAddOpen ? (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/50"
          data-testid="bundle-applicability-modal"
        >
          <div
            className="relative mx-4 rounded-xl bg-white p-6 shadow-xl"
            style={{ width: '480px', maxWidth: '100%' }}
          >
            <div className={`mb-4 flex items-center justify-between border-b ${borderColors.light} pb-3`}>
              <h3 className={`text-base font-semibold ${textColors.primary}`}>
                {t('authoring.applicability.addTitle')}
              </h3>
              <button
                type="button"
                onClick={() => {
                  setIsAddOpen(false)
                  resetDraft()
                }}
                aria-label={t('authoring.modal.close')}
                className={tokens.modal.closeButton}
              >
                <X className="h-5 w-5" />
              </button>
            </div>

            <div className="space-y-3">
              <div>
                <label className={tokens.label.base}>
                  {t('authoring.applicability.vehicleType')}
                </label>
                <select
                  data-testid="bundle-applicability-vehicle-type"
                  className={tokens.input.base}
                  value={draft.vehicle_type}
                  onChange={(e) => {
                    setDraft((d) => ({ ...d, vehicle_type: e.target.value as VehicleTypeRef | '' }))
                  }}
                >
                  <option value="">{t('authoring.applicability.vehicleTypePlaceholder')}</option>
                  {VEHICLE_TYPE_OPTIONS.map((vt) => (
                    <option key={vt} value={vt}>
                      {t(`authoring.applicability.vehicleTypeOptions.${vt}`)}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className={tokens.label.base}>
                  {t('authoring.applicability.vehicleDisplay')}
                </label>
                <input
                  data-testid="bundle-applicability-vehicle-display"
                  type="text"
                  className={tokens.input.base}
                  value={draft.vehicle_display}
                  onChange={(e) => {
                    setDraft((d) => ({ ...d, vehicle_display: e.target.value }))
                  }}
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className={tokens.label.base}>
                    {t('authoring.applicability.yearFrom')}
                  </label>
                  <input
                    data-testid="bundle-applicability-year-from"
                    type="number"
                    className={tokens.input.base}
                    value={draft.year_from}
                    onChange={(e) => {
                      setDraft((d) => ({ ...d, year_from: e.target.value }))
                    }}
                  />
                </div>
                <div>
                  <label className={tokens.label.base}>
                    {t('authoring.applicability.yearTo')}
                  </label>
                  <input
                    data-testid="bundle-applicability-year-to"
                    type="number"
                    className={tokens.input.base}
                    value={draft.year_to}
                    onChange={(e) => {
                      setDraft((d) => ({ ...d, year_to: e.target.value }))
                    }}
                  />
                </div>
              </div>

              {addError !== null ? (
                <div className={`${tokens.alert.base} ${tokens.alert.error}`}>{addError}</div>
              ) : null}

              <div className={`flex items-center justify-end gap-2 border-t ${borderColors.light} pt-4`}>
                <button
                  type="button"
                  onClick={() => {
                    setIsAddOpen(false)
                    resetDraft()
                  }}
                  className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.sm}`}
                >
                  {t('authoring.applicability.cancel')}
                </button>
                <button
                  type="button"
                  onClick={handleAdd}
                  disabled={replaceMutation.isPending}
                  className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.sm}`}
                  data-testid="bundle-applicability-save-btn"
                >
                  {t('authoring.applicability.save')}
                </button>
              </div>
            </div>
          </div>
        </div>
      ) : null}
    </section>
  )
}
