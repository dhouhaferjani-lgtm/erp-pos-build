import { useState } from 'react'
import { Link, useParams, useNavigate } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Edit, Trash2, Car, Calendar, Gauge, Fuel, Settings } from 'lucide-react'
import { apiDelete } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { usePermissions } from '@/hooks/usePermissions'
import { OwnershipHistoryTimeline } from './components/organisms/OwnershipHistoryTimeline'
import { MileageLogList } from './components/organisms/MileageLogList'
import { TransferOwnershipModal } from './components/organisms/TransferOwnershipModal'
import { useLogVehicleMileage } from './hooks/useLogVehicleMileage'
import { useVehicleWithCurrentOwner } from './hooks/useVehicleWithCurrentOwner'
import type { MileageSource } from './types'

const MILEAGE_SOURCES: MileageSource[] = ['service', 'manual', 'odometer_photo', 'external_api']

/** Type guard matching a MileageSource without a type assertion. */
function isMileageSource(value: string): value is MileageSource {
  return (MILEAGE_SOURCES as string[]).includes(value)
}

export function VehicleDetailPage() {
  const { t } = useTranslation(['vehicles', 'vehicle-ownership', 'common'])
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { hasPermission } = usePermissions()
  const canTransferOwnership = hasPermission('vehicles.manage_ownership')
  const canLogMileage = hasPermission('vehicles.log_mileage')
  const [showDeleteDialog, setShowDeleteDialog] = useState(false)
  const [showTransferModal, setShowTransferModal] = useState(false)
  const [showMileageForm, setShowMileageForm] = useState(false)
  const [mileageValue, setMileageValue] = useState('')
  const [mileageSource, setMileageSource] = useState<MileageSource>('manual')
  const [mileageError, setMileageError] = useState<string | null>(null)

  const { data, isLoading, error } = useVehicleWithCurrentOwner(id)

  const deleteMutation = useMutation({
    mutationFn: async () => {
      if (id === undefined || id === '') throw new Error('No vehicle ID')
      return apiDelete(`/vehicles/${id}`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey(['vehicles']) })
      void navigate('/vehicles')
    },
  })

  const vehicleId = id ?? ''
  const logMileageMutation = useLogVehicleMileage(vehicleId)

  const handleDelete = () => {
    setShowDeleteDialog(true)
  }

  const confirmDelete = () => {
    void deleteMutation.mutateAsync()
    setShowDeleteDialog(false)
  }

  const handleLogMileage = (e: React.FormEvent<HTMLFormElement>): void => {
    e.preventDefault()
    setMileageError(null)
    const parsed = Number.parseInt(mileageValue, 10)
    if (Number.isNaN(parsed) || parsed < 0) {
      setMileageError(t('vehicle-ownership:mileage.logError'))
      return
    }
    logMileageMutation.mutate(
      {
        mileage: parsed,
        recorded_at: new Date().toISOString(),
        source: mileageSource,
        notes: null,
      },
      {
        onSuccess: () => {
          setMileageValue('')
          setShowMileageForm(false)
        },
        onError: () => {
          setMileageError(t('vehicle-ownership:mileage.logError'))
        },
      },
    )
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className={textColors.tertiary}>{t('common:status.loading')}</div>
      </div>
    )
  }

  if (error || !data) {
    return (
      <div className="space-y-6">
        <Link
          to="/vehicles"
          className={`inline-flex items-center gap-2 text-sm ${textColors.tertiary} ${textColors.hoverSecondary}`}
        >
          <ArrowLeft className="h-4 w-4" />
          {t('common:actions.back')}
        </Link>
        <div className={`${tokens.alert.base} ${tokens.alert.error}`}>
          {t('messages.notFound')}
        </div>
      </div>
    )
  }

  // `data` extends VehicleData directly — vehicle fields live at the top
  // level of the detail response (closes 🟠-3). Aliased for readability in
  // the JSX below.
  const vehicle = data

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Link
            to="/vehicles"
            className={`inline-flex items-center gap-2 text-sm ${textColors.tertiary} ${textColors.hoverSecondary}`}
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:actions.back')}
          </Link>
          <div>
            <h1 className={`text-2xl font-bold ${textColors.primary}`}>
              {vehicle.brand} {vehicle.model}
            </h1>
            <p className={`${textColors.tertiary} font-mono`}>{vehicle.license_plate}</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Link
            to={`/vehicles/${vehicleId}/edit`}
            className={`inline-flex items-center gap-2 rounded-lg border ${borderColors.default} bg-white px-4 py-2 text-sm font-medium ${textColors.secondary} hover:bg-gray-50`}
          >
            <Edit className="h-4 w-4" />
            {t('common:actions.edit')}
          </Link>
          <button
            onClick={handleDelete}
            disabled={deleteMutation.isPending}
            className={`${tokens.button.base} ${tokens.button.dangerOutline} ${tokens.button.sizes.md} gap-2`}
          >
            <Trash2 className="h-4 w-4" />
            {t('common:actions.delete')}
          </button>
        </div>
      </div>

      {/* Vehicle Details */}
      <div className="grid gap-6 lg:grid-cols-2">
        {/* Main Info */}
        <div className={`rounded-lg border ${borderColors.light} bg-white p-6`}>
          <h2 className={`text-lg font-semibold ${textColors.primary} mb-4 flex items-center gap-2`}>
            <Car className={`h-5 w-5 ${textColors.disabled}`} />
            {t('sections.vehicleInfo')}
          </h2>
          <dl className="grid grid-cols-2 gap-4">
            <div>
              <dt className={`text-sm font-medium ${textColors.tertiary}`}>{t('brand')}</dt>
              <dd className={`mt-1 text-sm ${textColors.primary}`}>{vehicle.brand}</dd>
            </div>
            <div>
              <dt className={`text-sm font-medium ${textColors.tertiary}`}>{t('model')}</dt>
              <dd className={`mt-1 text-sm ${textColors.primary}`}>{vehicle.model}</dd>
            </div>
            <div>
              <dt className={`text-sm font-medium ${textColors.tertiary}`}>{t('licensePlate')}</dt>
              <dd className="mt-1">
                <span className={`inline-flex rounded-md bg-gray-100 px-2 py-1 text-sm font-mono font-medium ${textColors.secondary}`}>
                  {vehicle.license_plate}
                </span>
              </dd>
            </div>
            <div>
              <dt className={`text-sm font-medium ${textColors.tertiary}`}>{t('vin')}</dt>
              <dd className={`mt-1 text-sm ${textColors.primary} font-mono`}>
                {vehicle.vin ?? '-'}
              </dd>
            </div>
            <div>
              <dt className={`text-sm font-medium ${textColors.tertiary}`}>{t('color')}</dt>
              <dd className={`mt-1 text-sm ${textColors.primary}`}>{vehicle.color ?? '-'}</dd>
            </div>
            <div>
              <dt className={`text-sm font-medium ${textColors.tertiary}`}>{t('year')}</dt>
              <dd className={`mt-1 text-sm ${textColors.primary} flex items-center gap-1`}>
                <Calendar className={`h-4 w-4 ${textColors.disabled}`} />
                {vehicle.year ?? '-'}
              </dd>
            </div>
          </dl>
        </div>

        {/* Technical Info */}
        <div className={`rounded-lg border ${borderColors.light} bg-white p-6`}>
          <h2 className={`text-lg font-semibold ${textColors.primary} mb-4 flex items-center gap-2`}>
            <Settings className={`h-5 w-5 ${textColors.disabled}`} />
            {t('sections.technicalDetails')}
          </h2>
          <dl className="grid grid-cols-2 gap-4">
            <div>
              <dt className={`text-sm font-medium ${textColors.tertiary}`}>{t('mileage')}</dt>
              <dd className={`mt-1 text-sm ${textColors.primary} flex items-center gap-1`}>
                <Gauge className={`h-4 w-4 ${textColors.disabled}`} />
                {vehicle.mileage != null ? `${vehicle.mileage.toLocaleString()} km` : '-'}
              </dd>
            </div>
            <div>
              <dt className={`text-sm font-medium ${textColors.tertiary}`}>{t('fuelType')}</dt>
              <dd className={`mt-1 text-sm ${textColors.primary} flex items-center gap-1`}>
                <Fuel className={`h-4 w-4 ${textColors.disabled}`} />
                {vehicle.fuel_type ?? '-'}
              </dd>
            </div>
            <div>
              <dt className={`text-sm font-medium ${textColors.tertiary}`}>{t('transmission')}</dt>
              <dd className={`mt-1 text-sm ${textColors.primary}`}>{vehicle.transmission ?? '-'}</dd>
            </div>
            <div>
              <dt className={`text-sm font-medium ${textColors.tertiary}`}>{t('engineCode')}</dt>
              <dd className={`mt-1 text-sm ${textColors.primary} font-mono`}>{vehicle.engine_code ?? '-'}</dd>
            </div>
          </dl>
        </div>
      </div>

      {/* Ownership Section */}
      <div className={`rounded-lg border ${borderColors.light} bg-white p-6`}>
        <div className="flex items-center justify-between mb-4">
          <h2 className={`text-lg font-semibold ${textColors.primary}`}>
            {t('vehicle-ownership:page.ownershipSection')}
          </h2>
          {canTransferOwnership ? (
            <button
              type="button"
              onClick={() => { setShowTransferModal(true) }}
              className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.sm}`}
            >
              {t('vehicle-ownership:page.transferOwnership')}
            </button>
          ) : null}
        </div>
        <OwnershipHistoryTimeline vehicleId={vehicleId} />
      </div>

      {/* Mileage Section */}
      <div className={`rounded-lg border ${borderColors.light} bg-white p-6`}>
        <div className="flex items-center justify-between mb-4">
          <h2 className={`text-lg font-semibold ${textColors.primary}`}>
            {t('vehicle-ownership:page.mileageSection')}
          </h2>
          {canLogMileage ? (
            <button
              type="button"
              onClick={() => { setShowMileageForm((prev) => !prev) }}
              className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.sm}`}
            >
              {t('vehicle-ownership:page.logMileage')}
            </button>
          ) : null}
        </div>
        {showMileageForm && canLogMileage ? (
          <form onSubmit={handleLogMileage} className="flex flex-col gap-3 mb-4">
            <label className="flex flex-col gap-1">
              <span className={`text-sm ${textColors.secondary}`}>
                {t('vehicle-ownership:mileage.mileage')}
              </span>
              <input
                type="number"
                min="0"
                required
                value={mileageValue}
                onChange={(e) => { setMileageValue(e.target.value) }}
                className={`rounded border px-2 py-1 text-sm ${borderColors.default}`}
              />
            </label>
            <label className="flex flex-col gap-1">
              <span className={`text-sm ${textColors.secondary}`}>
                {t('vehicle-ownership:mileage.source')}
              </span>
              <select
                value={mileageSource}
                onChange={(e) => {
                  const value = e.target.value
                  if (isMileageSource(value)) {
                    setMileageSource(value)
                  }
                }}
                className={`rounded border px-2 py-1 text-sm ${borderColors.default}`}
              >
                <option value="manual">{t('vehicle-ownership:mileageSource.manual')}</option>
                <option value="service">{t('vehicle-ownership:mileageSource.service')}</option>
                <option value="odometer_photo">{t('vehicle-ownership:mileageSource.odometer_photo')}</option>
                <option value="external_api">{t('vehicle-ownership:mileageSource.external_api')}</option>
              </select>
            </label>
            {mileageError !== null ? (
              <div className={`text-sm ${textColors.error}`} role="alert">
                {mileageError}
              </div>
            ) : null}
            <div className="flex items-center justify-end gap-2">
              <button
                type="button"
                onClick={() => {
                  setShowMileageForm(false)
                  setMileageError(null)
                  setMileageValue('')
                }}
                className={`rounded border px-3 py-1 text-sm ${borderColors.default} ${textColors.secondary}`}
                disabled={logMileageMutation.isPending}
              >
                {t('vehicle-ownership:ownership.cancel')}
              </button>
              <button
                type="submit"
                disabled={logMileageMutation.isPending || mileageValue.trim() === ''}
                className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.sm}`}
              >
                {t('vehicle-ownership:mileage.logButton')}
              </button>
            </div>
          </form>
        ) : null}
        <MileageLogList vehicleId={vehicleId} />
      </div>

      {/* Notes */}
      {vehicle.notes ? (
        <div className={`rounded-lg border ${borderColors.light} bg-white p-6`}>
          <h2 className={`text-lg font-semibold ${textColors.primary} mb-4`}>{t('notes')}</h2>
          <p className={`text-sm ${textColors.secondary} whitespace-pre-wrap`}>{vehicle.notes}</p>
        </div>
      ) : null}

      {/* Metadata */}
      <div className={`text-sm ${textColors.tertiary}`}>
        <p>{t('created')}: {new Date(vehicle.created_at).toLocaleString()}</p>
        {vehicle.updated_at !== null ? (
          <p>{t('updated')}: {new Date(vehicle.updated_at).toLocaleString()}</p>
        ) : null}
      </div>

      {/* Delete Confirmation Dialog */}
      <ConfirmDialog
        isOpen={showDeleteDialog}
        onClose={() => { setShowDeleteDialog(false) }}
        onConfirm={confirmDelete}
        title={t('messages.deleteVehicle')}
        message={t('messages.confirmDeleteVehicle', { brand: vehicle.brand, model: vehicle.model, licensePlate: vehicle.license_plate })}
        confirmText={t('common:actions.delete')}
        variant="danger"
        isLoading={deleteMutation.isPending}
      />

      {/* Transfer Ownership Modal */}
      <TransferOwnershipModal
        vehicleId={vehicleId}
        isOpen={showTransferModal}
        onClose={() => { setShowTransferModal(false) }}
      />
    </div>
  )
}
