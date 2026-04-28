import { useEffect } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { ArrowLeft } from 'lucide-react'
import { api, apiPost, apiPatch } from '../../lib/api'
import { borderColors, textColors, tokens } from '@/lib/designTokens'

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

export function VehicleForm() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const queryClient = useQueryClient()
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
    queryKey: ['partners'],
    queryFn: async () => {
      const response = await api.get<PartnersResponse>('/partners')
      return response.data
    },
  })

  // Fetch vehicle if editing
  const { data: vehicleData, isLoading: loadingVehicle } = useQuery({
    queryKey: ['vehicle', id],
    queryFn: async () => {
      if (!id) return null
      const response = await api.get<VehicleResponse>(`/vehicles/${id}`)
      return response.data
    },
    enabled: isEdit,
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
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['vehicles'] })
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
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['vehicles'] })
      void queryClient.invalidateQueries({ queryKey: ['vehicle', vehicleId] })
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
        <div className={textColors.disabled}>{t('status.loading')}</div>
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
          {t('actions.back')}
        </Link>
        <h1 className={`text-2xl font-bold ${textColors.primary}`}>
          {isEdit ? 'Edit Vehicle' : 'New Vehicle'}
        </h1>
      </div>

      {/* Form */}
      <form onSubmit={(e) => void handleSubmit(onSubmit)(e)} className="space-y-6">
        <div className={`rounded-lg border ${borderColors.light} bg-white p-6`}>
          <h2 className={`text-lg font-semibold ${textColors.primary} mb-4`}>Vehicle Information</h2>

          <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
            {/* Owner (Partner) */}
            <div>
              <label htmlFor="partner_id" className={tokens.label.base}>
                Owner (Optional)
              </label>
              <select
                id="partner_id"
                {...register('partner_id')}
                className={tokens.select.base}
              >
                <option value="">-- No Owner --</option>
                {partners.map((partner) => (
                  <option key={partner.id} value={partner.id}>
                    {partner.name}
                  </option>
                ))}
              </select>
            </div>

            {/* License Plate */}
            <div>
              <label htmlFor="license_plate" className={tokens.label.base}>
                License Plate *
              </label>
              <input
                type="text"
                id="license_plate"
                {...register('license_plate', { required: 'License plate is required' })}
                className={tokens.input.base}
                placeholder="AB-123-CD"
              />
              {errors.license_plate && (
                <p className={tokens.helperText.error}>{errors.license_plate.message}</p>
              )}
            </div>

            {/* VIN */}
            <div>
              <label htmlFor="vin" className={tokens.label.base}>
                VIN
              </label>
              <input
                type="text"
                id="vin"
                {...register('vin', {
                  pattern: {
                    value: /^[A-HJ-NPR-Z0-9]{17}$/i,
                    message: 'VIN must be 17 characters (no I, O, Q)'
                  }
                })}
                className={`${tokens.input.base} font-mono`}
                placeholder="VF1RFB00X51234567"
                maxLength={17}
              />
              {errors.vin && (
                <p className={tokens.helperText.error}>{errors.vin.message}</p>
              )}
            </div>

            {/* Brand */}
            <div>
              <label htmlFor="brand" className={tokens.label.base}>
                Brand *
              </label>
              <input
                type="text"
                id="brand"
                {...register('brand', { required: 'Brand is required' })}
                className={tokens.input.base}
                placeholder="Renault"
              />
              {errors.brand && (
                <p className={tokens.helperText.error}>{errors.brand.message}</p>
              )}
            </div>

            {/* Model */}
            <div>
              <label htmlFor="model" className={tokens.label.base}>
                Model *
              </label>
              <input
                type="text"
                id="model"
                {...register('model', { required: 'Model is required' })}
                className={tokens.input.base}
                placeholder="Clio"
              />
              {errors.model && (
                <p className={tokens.helperText.error}>{errors.model.message}</p>
              )}
            </div>

            {/* Year */}
            <div>
              <label htmlFor="year" className={tokens.label.base}>
                Year
              </label>
              <input
                type="number"
                id="year"
                {...register('year', {
                  min: { value: 1900, message: 'Year must be after 1900' },
                  max: { value: new Date().getFullYear() + 1, message: 'Year is too far in the future' }
                })}
                className={tokens.input.base}
                placeholder="2020"
              />
              {errors.year && (
                <p className={tokens.helperText.error}>{errors.year.message}</p>
              )}
            </div>

            {/* Color */}
            <div>
              <label htmlFor="color" className={tokens.label.base}>
                Color
              </label>
              <input
                type="text"
                id="color"
                {...register('color')}
                className={tokens.input.base}
                placeholder="Blue"
              />
            </div>

            {/* Mileage */}
            <div>
              <label htmlFor="mileage" className={tokens.label.base}>
                Mileage (km)
              </label>
              <input
                type="number"
                id="mileage"
                {...register('mileage', { min: { value: 0, message: 'Mileage cannot be negative' } })}
                className={tokens.input.base}
                placeholder="45000"
              />
              {errors.mileage && (
                <p className={tokens.helperText.error}>{errors.mileage.message}</p>
              )}
            </div>

            {/* Fuel Type */}
            <div>
              <label htmlFor="fuel_type" className={tokens.label.base}>
                Fuel Type
              </label>
              <select
                id="fuel_type"
                {...register('fuel_type')}
                className={tokens.select.base}
              >
                <option value="">-- Select --</option>
                {fuelTypes.map((fuel) => (
                  <option key={fuel} value={fuel}>
                    {fuel}
                  </option>
                ))}
              </select>
            </div>

            {/* Transmission */}
            <div>
              <label htmlFor="transmission" className={tokens.label.base}>
                Transmission
              </label>
              <select
                id="transmission"
                {...register('transmission')}
                className={tokens.select.base}
              >
                <option value="">-- Select --</option>
                {transmissions.map((trans) => (
                  <option key={trans} value={trans}>
                    {trans}
                  </option>
                ))}
              </select>
            </div>

            {/* Engine Code */}
            <div>
              <label htmlFor="engine_code" className={tokens.label.base}>
                Engine Code
              </label>
              <input
                type="text"
                id="engine_code"
                {...register('engine_code')}
                className={tokens.input.base}
                placeholder="K9K 608"
              />
            </div>
          </div>

          {/* Notes */}
          <div className="mt-6">
            <label htmlFor="notes" className={tokens.label.base}>
              Notes
            </label>
            <textarea
              id="notes"
              {...register('notes')}
              rows={3}
              className={tokens.textarea.base}
              placeholder="Additional notes about the vehicle..."
            />
          </div>
        </div>

        {/* Error Message */}
        {mutationError && (
          <div className={`${tokens.alert.base} ${tokens.alert.error}`}>
            {mutationError instanceof Error ? mutationError.message : 'An error occurred. Please try again.'}
          </div>
        )}

        {/* Actions */}
        <div className="flex items-center justify-end gap-4">
          <Link
            to={isEdit ? `/vehicles/${vehicleId}` : '/vehicles'}
            className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.md}`}
          >
            {t('actions.cancel')}
          </Link>
          <button
            type="submit"
            disabled={isSubmitting}
            className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.md}`}
          >
            {isSubmitting ? t('status.saving', 'Saving...') : t('actions.save')}
          </button>
        </div>
      </form>
    </div>
  )
}
