import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Loader2 } from 'lucide-react'
import { Modal, ModalHeader, ModalContent, ModalFooter } from '../Modal'
import { FormField } from '../../atoms/FormField'
import { Input } from '../../atoms/Input'
import { Button } from '../../atoms/Button'
import { apiPost } from '../../../lib/api'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'

function scopedNamespacePredicate(
  namespace: string,
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      k.length >= 3 &&
      k[0] === namespace &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

interface Vehicle {
  id: string
  partner_id: string | null
  license_plate: string
  brand: string
  model: string
  year: number | null
  color: string | null
  vin: string | null
}

interface VehicleFormData {
  partner_id: string
  license_plate: string
  brand: string
  model: string
  year: string
  color: string
  vin: string
}

export interface AddVehicleModalProps {
  /**
   * Controls modal visibility
   */
  isOpen: boolean

  /**
   * Callback when modal should close
   */
  onClose: () => void

  /**
   * Optional partner ID to pre-fill the owner field
   * Used when creating a vehicle from a partner detail page
   */
  partnerId?: string | undefined

  /**
   * Callback after successful vehicle creation
   * Receives the newly created vehicle
   */
  onSuccess?: (vehicle: Vehicle) => void
}

/**
 * AddVehicleModal - Simplified vehicle creation for in-flow usage
 *
 * Creates basic vehicles quickly while viewing partner details or work orders.
 * Only includes essential fields - full vehicle editor stays as separate page.
 *
 * @example
 * ```tsx
 * <AddVehicleModal
 *   isOpen={isOpen}
 *   onClose={() => setIsOpen(false)}
 *   partnerId={partner.id} // Pre-fill owner
 *   onSuccess={(vehicle) => {
 *     // Add vehicle to partner's vehicle list
 *     refetch()
 *   }}
 * />
 * ```
 */
export function AddVehicleModal({
  isOpen,
  onClose,
  partnerId,
  onSuccess,
}: AddVehicleModalProps) {
  const { t } = useTranslation(['vehicles', 'common'])
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  // Form state with React Hook Form
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<VehicleFormData>({
    defaultValues: {
      partner_id: partnerId ?? '',
      license_plate: '',
      brand: '',
      model: '',
      year: '',
      color: '',
      vin: '',
    },
  })

  // Reset form when modal opens or partnerId changes
  useEffect(() => {
    if (isOpen) {
      reset({
        partner_id: partnerId ?? '',
        license_plate: '',
        brand: '',
        model: '',
        year: '',
        color: '',
        vin: '',
      })
    }
  }, [isOpen, partnerId, reset])

  // React Query mutation
  const mutation = useMutation({
    mutationFn: (data: VehicleFormData) => {
      // Transform data for API
      const payload = {
        partner_id: data.partner_id || null,
        license_plate: data.license_plate,
        brand: data.brand,
        model: data.model,
        year: data.year ? parseInt(data.year, 10) : null,
        color: data.color || null,
        vin: data.vin || null,
      }
      return apiPost<{ data: Vehicle }>('/vehicles', payload)
    },
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('vehicles', tenantId, companyId),
      })
      if (partnerId) {
        await queryClient.invalidateQueries({ queryKey: tenantScopedKey(['partner', partnerId]) })
      }
      onSuccess?.(response.data)
      onClose()
    },
  })

  const onSubmit = (data: VehicleFormData) => {
    mutation.mutate(data)
  }

  const currentYear = new Date().getFullYear()

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="lg">
      <ModalHeader title={t('vehicles:new')} onClose={onClose} />

      <form onSubmit={(e) => { void handleSubmit(onSubmit)(e) }}>
        <ModalContent>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {/* License Plate */}
            <FormField
              label={t('vehicles:licensePlate')}
              htmlFor="vehicle-license-plate"
              required
              error={errors.license_plate?.message}
            >
              <Input
                id="vehicle-license-plate"
                {...register('license_plate', { required: t('common:validation.required') })}
                placeholder="AB-123-CD"
              />
            </FormField>

            {/* VIN */}
            <FormField
              label={t('vehicles:vin')}
              htmlFor="vehicle-vin"
              error={errors.vin?.message}
            >
              <Input
                id="vehicle-vin"
                {...register('vin', {
                  pattern: {
                    value: /^[A-HJ-NPR-Z0-9]{17}$/i,
                    message: 'VIN must be 17 characters (no I, O, Q)',
                  },
                })}
                placeholder="VF1RFB00X51234567"
                maxLength={17}
                className="font-mono"
              />
            </FormField>

            {/* Brand */}
            <FormField
              label={t('vehicles:brand')}
              htmlFor="vehicle-brand"
              required
              error={errors.brand?.message}
            >
              <Input
                id="vehicle-brand"
                {...register('brand', { required: t('common:validation.required') })}
                placeholder="Renault"
              />
            </FormField>

            {/* Model */}
            <FormField
              label={t('vehicles:model')}
              htmlFor="vehicle-model"
              required
              error={errors.model?.message}
            >
              <Input
                id="vehicle-model"
                {...register('model', { required: t('common:validation.required') })}
                placeholder="Clio"
              />
            </FormField>

            {/* Year */}
            <FormField
              label={t('vehicles:year')}
              htmlFor="vehicle-year"
              error={errors.year?.message}
            >
              <Input
                id="vehicle-year"
                type="number"
                min="1900"
                max={currentYear + 1}
                {...register('year', {
                  min: { value: 1900, message: 'Year must be after 1900' },
                  max: {
                    value: currentYear + 1,
                    message: 'Year is too far in the future',
                  },
                })}
                placeholder="2020"
              />
            </FormField>

            {/* Color */}
            <FormField
              label={t('vehicles:color')}
              htmlFor="vehicle-color"
            >
              <Input
                id="vehicle-color"
                {...register('color')}
                placeholder="Blue"
              />
            </FormField>
          </div>

          {/* Info message */}
          <div className="mt-4 rounded-lg bg-blue-50 p-3 text-sm text-blue-700">
            Quick vehicle creation. You can add more details (fuel type, transmission, mileage, etc.) by editing the vehicle later.
          </div>

          {/* Error message */}
          {mutation.isError && (
            <div className="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">
              {mutation.error instanceof Error
                ? mutation.error.message
                : t('common:errorMessages.generic')}
            </div>
          )}
        </ModalContent>

        <ModalFooter>
          <Button
            type="button"
            variant="secondary"
            onClick={onClose}
            disabled={mutation.isPending}
          >
            {t('common:actions.cancel')}
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={mutation.isPending}
          >
            {mutation.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
            {t('common:actions.create')}
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
