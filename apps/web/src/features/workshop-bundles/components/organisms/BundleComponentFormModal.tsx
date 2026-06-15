import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { AxiosError } from 'axios'
import {
  BundlePicker,
  ProductPicker,
  ServicePicker,
  type ProductPickerValue,
  type ServicePickerValue,
} from '@/components/molecules/pickers'
import { Button } from '@/components/atoms/Button'
import { FormField } from '@/components/atoms/FormField'
import { Input } from '@/components/atoms/Input'
import { Select } from '@/components/atoms/Select'
import { Textarea } from '@/components/atoms/Textarea'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal'
import { bccomp } from '@/lib/decimal'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import type { ApplicableBundleData } from '@/features/workshop-bundles/types'
import type {
  BundleComponentType,
  ServiceBundleComponentData,
  ServiceBundleData,
} from '../../types'
import {
  useAddBundleComponent,
  useUpdateBundleComponent,
} from '../../hooks/useBundles'
import { useUnits } from '../../hooks/useUnits'

interface BundleComponentFormModalProps {
  bundle: ServiceBundleData
  /** Undefined = create mode, defined = edit mode. */
  component?: ServiceBundleComponentData
  onClose: () => void
  onSaved: () => void
}

interface FormState {
  component_type: BundleComponentType
  product: ProductPickerValue | null
  service: ServicePickerValue | null
  nestedBundle: ApplicableBundleData | null
  quantity: string
  unit_id: string
  override_unit_price: string
  is_optional: boolean
  notes: string
}

type FormErrors = Partial<Record<keyof FormState | 'form', string>>

/**
 * Fixed-dimension authoring modal for a single bundle component line.
 *
 * Width is pinned to 560px and height flows with content; the modal
 * MUST NOT resize on interaction (project memory rule
 * feedback_modal_fixed_size.md). Field set swaps based on
 * component_type — Part / Labor / NestedBundle wire to their matching
 * picker. Nested bundles exclude the current bundle id to block self
 * cycles at the UI layer (the server enforces the full BFS guard).
 */
export function BundleComponentFormModal({
  bundle,
  component,
  onClose,
  onSaved,
}: BundleComponentFormModalProps) {
  const { data: units, isLoading: unitsLoading } = useUnits()
  const isEditMode = component !== undefined
  // In edit mode we need the units list to resolve the persisted unit
  // symbol back to a unit id for the <select>. If we rendered before the
  // units arrived, `useState(initial)` would lock in an empty unit_id and
  // the user would see a blank select / trip the client-side
  // unitRequired guard. Gate rendering on units being loaded.
  if (isEditMode && (unitsLoading || units === undefined)) {
    return <BundleComponentFormModalLoading onClose={onClose} />
  }
  return (
    <BundleComponentFormModalReady
      bundle={bundle}
      component={component}
      units={units ?? []}
      onClose={onClose}
      onSaved={onSaved}
    />
  )
}

interface BundleComponentFormModalReadyProps {
  bundle: ServiceBundleData
  component?: ServiceBundleComponentData | undefined
  onClose: () => void
  onSaved: () => void
  units: { id: string; code: string; name: string; symbol: string }[]
}

function BundleComponentFormModalReady({
  bundle,
  component,
  onClose,
  onSaved,
  units,
}: BundleComponentFormModalReadyProps) {
  const { t } = useTranslation('workshop-bundles')
  const addMutation = useAddBundleComponent(bundle.id)
  const updateMutation = useUpdateBundleComponent(bundle.id)

  const isEditing = component !== undefined

  // Seed picker slots from the existing component in edit mode. Product /
  // Service / NestedBundle pickers only need id + a display label, which
  // we can synthesise from the persisted component_display_name.
  //
  // `component.unit` is the unit's *symbol* (e.g. "EA", "HR"), not its
  // UUID — the DTO projects the symbol for display. To pre-select the
  // correct option in the unit <select>, we resolve the symbol back to a
  // unit id against the loaded units list. If the symbol doesn't match
  // (shouldn't happen, but possible after a unit rename/delete), fall
  // back to empty string and let the user re-pick.
  const initial: FormState = useMemo(() => {
    if (component === undefined) {
      return {
        component_type: 'part',
        product: null,
        service: null,
        nestedBundle: null,
        quantity: '1',
        unit_id: '',
        override_unit_price: '',
        is_optional: false,
        notes: '',
      }
    }
    const resolvedUnitId =
      units.find((u) => u.symbol === component.unit)?.id ?? ''
    const seed: FormState = {
      component_type: component.component_type,
      product: null,
      service: null,
      nestedBundle: null,
      quantity: component.quantity,
      unit_id: resolvedUnitId,
      override_unit_price: component.override_unit_price ?? '',
      is_optional: component.is_optional,
      notes: component.notes ?? '',
    }
    if (component.component_type === 'part') {
      seed.product = {
        id: component.component_id,
        sku: '',
        name: component.component_display_name,
      }
    } else if (component.component_type === 'labor') {
      seed.service = {
        id: component.component_id,
        code: '',
        name: component.component_display_name,
      }
    } else if (component.component_type === 'nested_bundle') {
      seed.nestedBundle = {
        id: component.component_id,
        code: '',
        name: component.component_display_name,
        description: null,
        pricing_mode: 'standard',
        base_price: null,
        currency: bundle.currency,
        service_interval_km: null,
        service_interval_months: null,
        estimated_labor_hours: null,
        component_count: 0,
      }
    }
    return seed
  }, [component, bundle.currency, units])

  const [state, setState] = useState<FormState>(initial)
  const [errors, setErrors] = useState<FormErrors>({})

  const isPending = addMutation.isPending || updateMutation.isPending

  function validate(): FormErrors {
    const next: FormErrors = {}

    if (state.component_type === 'part' && state.product === null) {
      next.product = t('authoring.errors.productRequired')
    } else if (state.component_type === 'labor' && state.service === null) {
      next.service = t('authoring.errors.serviceRequired')
    } else if (state.component_type === 'nested_bundle' && state.nestedBundle === null) {
      next.nestedBundle = t('authoring.errors.nestedBundleRequired')
    }

    if (state.quantity === '' || Number.isNaN(Number(state.quantity))) {
      next.quantity = t('authoring.errors.quantityRequired')
    } else if (bccomp(state.quantity, '0') <= 0) {
      next.quantity = t('authoring.errors.quantityPositive')
    }

    if (state.unit_id === '') {
      next.unit_id = t('authoring.errors.unitRequired')
    }

    if (
      state.override_unit_price !== '' &&
      (Number.isNaN(Number(state.override_unit_price)) ||
        bccomp(state.override_unit_price, '0') < 0)
    ) {
      next.override_unit_price = t('authoring.errors.pricePositive')
    }

    return next
  }

  function selectedReferenceId(): string | null {
    if (state.component_type === 'part') return state.product?.id ?? null
    if (state.component_type === 'labor') return state.service?.id ?? null
    return state.nestedBundle?.id ?? null
  }

  function handleSubmit(e: React.FormEvent<HTMLFormElement>): void {
    e.preventDefault()
    const validationErrors = validate()
    if (Object.keys(validationErrors).length > 0) {
      setErrors(validationErrors)
      return
    }
    const refId = selectedReferenceId()
    if (refId === null) {
      setErrors({ form: t('authoring.errors.referenceRequired') })
      return
    }

    const basePayload = {
      component_type: state.component_type,
      component_id: refId,
      quantity: state.quantity,
      unit_id: state.unit_id,
      override_unit_price: state.override_unit_price === '' ? null : state.override_unit_price,
      is_optional: state.is_optional,
      notes: state.notes === '' ? null : state.notes,
    }

    const onError = (err: unknown): void => {
      if (err instanceof AxiosError) {
        const data = err.response?.data as
          | { error?: { code?: string; message?: string }; errors?: Record<string, string[]> }
          | undefined
        if (data?.error?.code === 'BUNDLE_CYCLE') {
          setErrors({ nestedBundle: t('authoring.errors.cycleDetected') })
          return
        }
        if (data?.errors !== undefined) {
          const next: FormErrors = {}
          for (const [field, messages] of Object.entries(data.errors)) {
            if (messages.length > 0) {
              next[field as keyof FormErrors] = messages[0]
            }
          }
          setErrors(next)
          return
        }
      }
      setErrors({ form: t('authoring.errors.generic') })
    }

    if (isEditing && component !== undefined) {
      updateMutation.mutate(
        { componentId: component.id, payload: basePayload },
        { onSuccess: onSaved, onError },
      )
    } else {
      addMutation.mutate(
        { ...basePayload, display_order: bundle.components.length },
        { onSuccess: onSaved, onError },
      )
    }
  }

  const excludeBundleIds = useMemo<string[]>(() => [bundle.id], [bundle.id])

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={isEditing ? t('authoring.modal.editTitle') : t('authoring.modal.createTitle')}
      size="md"
    >
      <form onSubmit={handleSubmit} data-testid="bundle-component-form-modal">
        <ModalContent>
          <FormField
            label={t('authoring.fields.componentType')}
            htmlFor="bundle-component-type-select"
          >
            <Select
              id="bundle-component-type-select"
              data-testid="bundle-component-type-select"
              value={state.component_type}
              disabled={isEditing}
              onChange={(e) => {
                const nextType = e.target.value as BundleComponentType
                setState((s) => ({
                  ...s,
                  component_type: nextType,
                  product: null,
                  service: null,
                  nestedBundle: null,
                }))
              }}
            >
              <option value="part">{t('authoring.componentType.part')}</option>
              <option value="labor">{t('authoring.componentType.labor')}</option>
              <option value="nested_bundle">{t('authoring.componentType.nestedBundle')}</option>
            </Select>
          </FormField>

          {state.component_type === 'part' ? (
            <div>
              <ProductPicker
                value={state.product}
                onChange={(next) => {
                  setState((s) => ({ ...s, product: next }))
                }}
                required
              />
              {errors.product !== undefined ? (
                <p className={tokens.helperText.error}>{errors.product}</p>
              ) : null}
            </div>
          ) : state.component_type === 'labor' ? (
            <div>
              <ServicePicker
                value={state.service}
                onChange={(next) => {
                  setState((s) => ({ ...s, service: next }))
                }}
                required
              />
              {errors.service !== undefined ? (
                <p className={tokens.helperText.error}>{errors.service}</p>
              ) : null}
            </div>
          ) : (
            <div>
              <BundlePicker
                value={state.nestedBundle}
                onChange={(next) => {
                  setState((s) => ({ ...s, nestedBundle: next }))
                }}
                excludeBundleIds={excludeBundleIds}
                label={t('authoring.fields.nestedBundle')}
              />
              {errors.nestedBundle !== undefined ? (
                <p className={tokens.helperText.error}>{errors.nestedBundle}</p>
              ) : null}
            </div>
          )}

          <div className="grid grid-cols-2 gap-3">
            <FormField
              label={t('authoring.fields.quantity')}
              htmlFor="bundle-component-quantity"
              error={errors.quantity}
            >
              <Input
                id="bundle-component-quantity"
                data-testid="bundle-component-quantity"
                type="text"
                inputMode="decimal"
                value={state.quantity}
                onChange={(e) => {
                  setState((s) => ({ ...s, quantity: e.target.value }))
                }}
              />
            </FormField>
            <FormField
              label={t('authoring.fields.unit')}
              htmlFor="bundle-component-unit-select"
              error={errors.unit_id}
            >
              <Select
                id="bundle-component-unit-select"
                data-testid="bundle-component-unit-select"
                value={state.unit_id}
                onChange={(e) => {
                  setState((s) => ({ ...s, unit_id: e.target.value }))
                }}
              >
                <option value="">{t('authoring.fields.unitPlaceholder')}</option>
                {units.map((u) => (
                  <option key={u.id} value={u.id}>
                    {u.symbol} — {u.name}
                  </option>
                ))}
              </Select>
            </FormField>
          </div>

          <FormField
            label={t('authoring.fields.overridePrice', { currency: bundle.currency })}
            htmlFor="bundle-component-override-price"
            error={errors.override_unit_price}
          >
            <Input
              id="bundle-component-override-price"
              type="text"
              inputMode="decimal"
              value={state.override_unit_price}
              placeholder={t('authoring.fields.overridePricePlaceholder')}
              onChange={(e) => {
                setState((s) => ({ ...s, override_unit_price: e.target.value }))
              }}
            />
          </FormField>

          <div className="flex items-center gap-2">
            <input
              id="bundle-component-optional"
              type="checkbox"
              checked={state.is_optional}
              onChange={(e) => {
                setState((s) => ({ ...s, is_optional: e.target.checked }))
              }}
              className={tokens.checkbox.base}
            />
            <label
              htmlFor="bundle-component-optional"
              className={cn('text-sm', textColors.secondary)}
            >
              {t('authoring.fields.isOptional')}
            </label>
          </div>

          <FormField label={t('authoring.fields.notes')} htmlFor="bundle-component-notes">
            <Textarea
              id="bundle-component-notes"
              rows={2}
              value={state.notes}
              onChange={(e) => {
                setState((s) => ({ ...s, notes: e.target.value }))
              }}
            />
          </FormField>

          {errors.form !== undefined ? (
            <div className={`${tokens.alert.base} ${tokens.alert.error}`}>{errors.form}</div>
          ) : null}
        </ModalContent>

        <ModalFooter className={cn('border-t pt-4', borderColors.light)}>
          <Button type="button" variant="secondary" size="sm" onClick={onClose}>
            {t('authoring.modal.cancel')}
          </Button>
          <Button type="submit" variant="primary" size="sm" disabled={isPending}>
            {isPending ? t('authoring.modal.saving') : t('authoring.modal.save')}
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}

/**
 * Placeholder rendered while `useUnits` is resolving in edit mode. The
 * outer modal gates on this so `useState(initial)` inside the Ready
 * subcomponent runs AFTER units are available, letting us resolve the
 * persisted unit symbol back to its id for the <select>.
 */
function BundleComponentFormModalLoading({ onClose }: { onClose: () => void }) {
  const { t } = useTranslation('workshop-bundles')
  return (
    <Modal isOpen onClose={onClose} title={t('authoring.modal.editTitle')} size="md">
      <ModalContent>
        <div
          className={cn('py-8 text-center text-sm', textColors.tertiary)}
          data-testid="bundle-component-form-modal"
        >
          {t('authoring.modal.loading')}
        </div>
      </ModalContent>
    </Modal>
  )
}
