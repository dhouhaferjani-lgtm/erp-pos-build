import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, X } from 'lucide-react'
import { Button } from '@/components/atoms/Button'
import { FormField } from '@/components/atoms/FormField'
import { Input } from '@/components/atoms/Input'
import { Select } from '@/components/atoms/Select'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
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
        <Button
          type="button"
          variant="secondary"
          size="sm"
          onClick={() => {
            setIsAddOpen(true)
          }}
          className="gap-1"
          data-testid="bundle-applicability-add-btn"
        >
          <Plus className="h-4 w-4" aria-hidden />
          {t('authoring.applicability.addRule')}
        </Button>
      </div>

      {bundle.vehicle_applicabilities.length === 0 ? (
        <p className={`text-sm ${textColors.tertiary}`}>{t('detail.emptyApplicabilities')}</p>
      ) : (
        <ul className="flex flex-wrap gap-2" data-testid="bundle-applicability-list">
          {bundle.vehicle_applicabilities.map((applicability, idx) => (
            <li key={applicability.id} className="flex items-center gap-1">
              <VehicleApplicabilityChip applicability={applicability} />
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => {
                  handleRemove(idx)
                }}
                aria-label={t('authoring.applicability.remove')}
                className={cn('!p-1', textColors.tertiary, textColors.hoverPrimary)}
                data-testid={`bundle-applicability-remove-${applicability.id}`}
              >
                <X className="h-3.5 w-3.5" aria-hidden />
              </Button>
            </li>
          ))}
        </ul>
      )}

      {isAddOpen ? (
        <Modal
          isOpen
          onClose={() => {
            setIsAddOpen(false)
            resetDraft()
          }}
          title={t('authoring.applicability.addTitle')}
          size="md"
        >
          <ModalContent>
            <div data-testid="bundle-applicability-modal" className="space-y-4">
            <FormField
              label={t('authoring.applicability.vehicleType')}
              htmlFor="bundle-applicability-vehicle-type"
            >
              <Select
                id="bundle-applicability-vehicle-type"
                data-testid="bundle-applicability-vehicle-type"
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
              </Select>
            </FormField>

            <FormField
              label={t('authoring.applicability.vehicleDisplay')}
              htmlFor="bundle-applicability-vehicle-display"
            >
              <Input
                id="bundle-applicability-vehicle-display"
                data-testid="bundle-applicability-vehicle-display"
                type="text"
                value={draft.vehicle_display}
                onChange={(e) => {
                  setDraft((d) => ({ ...d, vehicle_display: e.target.value }))
                }}
              />
            </FormField>

            <div className="grid grid-cols-2 gap-3">
              <FormField
                label={t('authoring.applicability.yearFrom')}
                htmlFor="bundle-applicability-year-from"
              >
                <Input
                  id="bundle-applicability-year-from"
                  data-testid="bundle-applicability-year-from"
                  type="number"
                  value={draft.year_from}
                  onChange={(e) => {
                    setDraft((d) => ({ ...d, year_from: e.target.value }))
                  }}
                />
              </FormField>
              <FormField
                label={t('authoring.applicability.yearTo')}
                htmlFor="bundle-applicability-year-to"
              >
                <Input
                  id="bundle-applicability-year-to"
                  data-testid="bundle-applicability-year-to"
                  type="number"
                  value={draft.year_to}
                  onChange={(e) => {
                    setDraft((d) => ({ ...d, year_to: e.target.value }))
                  }}
                />
              </FormField>
            </div>

            {addError !== null ? (
              <div className={`${tokens.alert.base} ${tokens.alert.error}`}>{addError}</div>
            ) : null}
            </div>
          </ModalContent>

          <ModalFooter className={cn('border-t pt-4', borderColors.light)}>
            <Button
              type="button"
              variant="secondary"
              size="sm"
              onClick={() => {
                setIsAddOpen(false)
                resetDraft()
              }}
            >
              {t('authoring.applicability.cancel')}
            </Button>
            <Button
              type="button"
              variant="primary"
              size="sm"
              onClick={handleAdd}
              disabled={replaceMutation.isPending}
              data-testid="bundle-applicability-save-btn"
            >
              {t('authoring.applicability.save')}
            </Button>
          </ModalFooter>
        </Modal>
      ) : null}
    </section>
  )
}
