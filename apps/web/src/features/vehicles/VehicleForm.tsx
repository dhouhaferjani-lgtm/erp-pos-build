import { useEffect } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { ArrowLeft } from 'lucide-react'
import { api, apiPost, apiPatch } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import { Button, Input, Select, Textarea } from '@/components/atoms'

interface Partner {
  id: string
  name: string
}

interface PartnersResponse {
  data: Partner[]
}

interface Vehicle {
  id: string
  partner_id: string | null
  license_plate: string
  brand: string
  model: string
  year: number | null
  color: string | null
  mileage: number | null
  vin: string | null
  engine_code: string | null
  fuel_type: string | null
  transmission: string | null
  notes: string | null
}

interface VehicleResponse {
  data: Vehicle
}

interface VehicleFormData {
  partner_id: string
  license_plate: string
  brand: string
  model: string
  year: string
  color: string
  mileage: string
  vin: string
  engine_code: string
  fuel_type: string
  transmission: string
  notes: string
}

const fuelTypes = ['Petrol', 'Diesel', 'Electric', 'Hybrid', 'LPG', 'CNG']
const transmissions = ['Manual', 'Automatic', 'CVT', 'Semi-Automatic']

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

export function VehicleForm() {
  const { t } = useTranslation(['vehicles', 'common'])
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const isEdit = Boolean(id)
  const vehicleId = id ?? ''

  const { register, handleSubmit, reset, formState: { errors } } = useForm<VehicleFormData>({
    defaultValues: {
      partner_id: '',
      license_plate: '',
      brand: '',
      model: '',
      year: '',
      color: '',
      mileage: '',
      vin: '',
      engine_code: '',
      fuel_type: '',
      transmission: '',
      notes: '',
    },
  })

  // Fetch partners for dropdown
  const { data: partnersData } = useQuery({
    queryKey: tenantScopedKey(['partners']),
    queryFn: async () => {
      const response = await api.get<PartnersResponse>('/partners')
      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  // Fetch vehicle if editing
  const { data: vehicleData, isLoading: loadingVehicle } = useQuery({
    queryKey: tenantScopedKey(['vehicle', id]),
    queryFn: async () => {
      if (!id) return null
      const response = await api.get<VehicleResponse>(`/vehicles/${id}`)
      return response.data
    },
    enabled: isEdit && tenantId !== null && companyId !== null,
  })

  // Populate form when editing
  useEffect(() => {
    if (vehicleData?.data) {
      const v = vehicleData.data
      reset({
        partner_id: v.partner_id ?? '',
        license_plate: v.license_plate,
        brand: v.brand,
        model: v.model,
        year: v.year?.toString() ?? '',
        color: v.color ?? '',
        mileage: v.mileage?.toString() ?? '',
        vin: v.vin ?? '',
        engine_code: v.engine_code ?? '',
        fuel_type: v.fuel_type ?? '',
        transmission: v.transmission ?? '',
        notes: v.notes ?? '',
      })
    }
  }, [vehicleData, reset])

  const createMutation = useMutation({
    mutationFn: (data: VehicleFormData) => apiPost<VehicleResponse>('/vehicles', {
      partner_id: data.partner_id || null,
      license_plate: data.license_plate,
      brand: data.brand,
      model: data.model,
      year: data.year ? parseInt(data.year, 10) : null,
      color: data.color || null,
      mileage: data.mileage ? parseInt(data.mileage, 10) : null,
      vin: data.vin || null,
      engine_code: data.engine_code || null,
      fuel_type: data.fuel_type || null,
      transmission: data.transmission || null,
      notes: data.notes || null,
    }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('vehicles', tenantId, companyId),
      })
      void navigate('/vehicles')
    },
  })

  const updateMutation = useMutation({
    mutationFn: (data: VehicleFormData) => apiPatch<VehicleResponse>(`/vehicles/${vehicleId}`, {
      partner_id: data.partner_id || null,
      license_plate: data.license_plate,
      brand: data.brand,
      model: data.model,
      year: data.year ? parseInt(data.year, 10) : null,
      color: data.color || null,
      mileage: data.mileage ? parseInt(data.mileage, 10) : null,
      vin: data.vin || null,
      engine_code: data.engine_code || null,
      fuel_type: data.fuel_type || null,
      transmission: data.transmission || null,
      notes: data.notes || null,
    }),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('vehicles', tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['vehicle', vehicleId]) }),
      ])
      void navigate(`/vehicles/${vehicleId}`)
    },
  })

  const onSubmit = (data: VehicleFormData) => {
    if (isEdit) {
      updateMutation.mutate(data)
    } else {
      createMutation.mutate(data)
    }
  }

  const isSubmitting = createMutation.isPending || updateMutation.isPending
  const mutationError = createMutation.error ?? updateMutation.error
  const partners = partnersData?.data ?? []

  if (isEdit && loadingVehicle) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className={textColors.disabled}>{t('common:status.loading')}</div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <Link
          to={isEdit ? `/vehicles/${vehicleId}` : '/vehicles'}
          className={`inline-flex items-center gap-2 text-sm ${textColors.tertiary} ${textColors.hoverSecondary}`}
        >
          <ArrowLeft className="h-4 w-4" />
          {t('common:actions.back')}
        </Link>
        <PageHeaderTitle className={`text-2xl font-bold ${textColors.primary}`}>
          {isEdit ? t('vehicles:edit') : t('vehicles:new')}
        </PageHeaderTitle>
      </div>

      {/* Form */}
      <form onSubmit={(e) => void handleSubmit(onSubmit)(e)} className="space-y-6">
        <div className={`rounded-lg border ${borderColors.light} bg-white p-6`}>
          <h2 className={`text-lg font-semibold ${textColors.primary} mb-4`}>{t('vehicles:sections.vehicleInfo')}</h2>

          <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
            {/* Owner (Partner) */}
            <div>
              <label htmlFor="partner_id" className={tokens.label.base}>
                {t('vehicles:owner')}
              </label>
              <Select
                id="partner_id"
                {...register('partner_id')}
              >
                <option value="">{t('vehicles:noOwner')}</option>
                {partners.map((partner) => (
                  <option key={partner.id} value={partner.id}>
                    {partner.name}
                  </option>
                ))}
              </Select>
            </div>

            {/* License Plate */}
            <div>
              <label htmlFor="license_plate" className={tokens.label.base}>
                {t('vehicles:licensePlate')} *
              </label>
              <Input
                type="text"
                id="license_plate"
                {...register('license_plate', { required: t('vehicles:licensePlateRequired') })}
                placeholder={t('vehicles:placeholders.licensePlate')}
              />
              {errors.license_plate && (
                <p className={tokens.helperText.error}>{errors.license_plate.message}</p>
              )}
            </div>

            {/* VIN */}
            <div>
              <label htmlFor="vin" className={tokens.label.base}>
                {t('vehicles:vin')}
              </label>
              <Input
                type="text"
                id="vin"
                {...register('vin', {
                  pattern: {
                    value: /^[A-HJ-NPR-Z0-9]{17}$/i,
                    message: t('vehicles:vinValidation'),
                  }
                })}
                className="font-mono"
                placeholder={t('vehicles:placeholders.vin')}
                maxLength={17}
              />
              {errors.vin && (
                <p className={tokens.helperText.error}>{errors.vin.message}</p>
              )}
            </div>

            {/* Brand */}
            <div>
              <label htmlFor="brand" className={tokens.label.base}>
                {t('vehicles:brand')} *
              </label>
              <Input
                type="text"
                id="brand"
                {...register('brand', { required: t('vehicles:brandRequired') })}
                placeholder={t('vehicles:placeholders.brand')}
              />
              {errors.brand && (
                <p className={tokens.helperText.error}>{errors.brand.message}</p>
              )}
            </div>

            {/* Model */}
            <div>
              <label htmlFor="model" className={tokens.label.base}>
                {t('vehicles:model')} *
              </label>
              <Input
                type="text"
                id="model"
                {...register('model', { required: t('vehicles:modelRequired') })}
                placeholder={t('vehicles:placeholders.model')}
              />
              {errors.model && (
                <p className={tokens.helperText.error}>{errors.model.message}</p>
              )}
            </div>

            {/* Year */}
            <div>
              <label htmlFor="year" className={tokens.label.base}>
                {t('vehicles:year')}
              </label>
              <Input
                type="number"
                id="year"
                {...register('year', {
                  min: { value: 1900, message: t('vehicles:yearMin') },
                  max: { value: new Date().getFullYear() + 1, message: t('vehicles:yearMax') }
                })}
                placeholder={t('vehicles:placeholders.year')}
              />
              {errors.year && (
                <p className={tokens.helperText.error}>{errors.year.message}</p>
              )}
            </div>

            {/* Color */}
            <div>
              <label htmlFor="color" className={tokens.label.base}>
                {t('vehicles:color')}
              </label>
              <Input
                type="text"
                id="color"
                {...register('color')}
                placeholder={t('vehicles:placeholders.color')}
              />
            </div>

            {/* Mileage */}
            <div>
              <label htmlFor="mileage" className={tokens.label.base}>
                {t('vehicles:mileageKm')}
              </label>
              <Input
                type="number"
                id="mileage"
                {...register('mileage', { min: { value: 0, message: t('vehicles:mileageNegative') } })}
                placeholder="45000"
              />
              {errors.mileage && (
                <p className={tokens.helperText.error}>{errors.mileage.message}</p>
              )}
            </div>

            {/* Fuel Type */}
            <div>
              <label htmlFor="fuel_type" className={tokens.label.base}>
                {t('vehicles:fuelType')}
              </label>
              <Select
                id="fuel_type"
                {...register('fuel_type')}
              >
                <option value="">{t('common:common.selectOption')}</option>
                {fuelTypes.map((fuel) => (
                  <option key={fuel} value={fuel}>
                    {fuel}
                  </option>
                ))}
              </Select>
            </div>

            {/* Transmission */}
            <div>
              <label htmlFor="transmission" className={tokens.label.base}>
                {t('vehicles:transmission')}
              </label>
              <Select
                id="transmission"
                {...register('transmission')}
              >
                <option value="">{t('common:common.selectOption')}</option>
                {transmissions.map((trans) => (
                  <option key={trans} value={trans}>
                    {trans}
                  </option>
                ))}
              </Select>
            </div>

            {/* Engine Code */}
            <div>
              <label htmlFor="engine_code" className={tokens.label.base}>
                {t('vehicles:engineCode')}
              </label>
              <Input
                type="text"
                id="engine_code"
                {...register('engine_code')}
                placeholder={t('vehicles:placeholders.engineCode')}
              />
            </div>
          </div>

          {/* Notes */}
          <div className="mt-6">
            <label htmlFor="notes" className={tokens.label.base}>
              {t('vehicles:notes')}
            </label>
            <Textarea
              id="notes"
              {...register('notes')}
              rows={3}
              placeholder={t('vehicles:placeholders.notes')}
            />
          </div>
        </div>

        {/* Error Message */}
        {mutationError && (
          <div className={`${tokens.alert.base} ${tokens.alert.error}`}>
            {mutationError instanceof Error ? mutationError.message : t('common:errorMessages.generic')}
          </div>
        )}

        {/* Actions */}
        <div className="flex items-center justify-end gap-4">
          <Link
            to={isEdit ? `/vehicles/${vehicleId}` : '/vehicles'}
            className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.md}`}
          >
            {t('common:actions.cancel')}
          </Link>
          <Button
            type="submit"
            disabled={isSubmitting}
          >
            {isSubmitting ? t('common:status.saving') : t('common:actions.save')}
          </Button>
        </div>
      </form>
    </div>
  )
}
