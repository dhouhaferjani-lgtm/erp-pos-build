import { useState, useMemo } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, Car } from 'lucide-react'
import { api } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { SearchInput } from '../../components/ui/SearchInput'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'

interface Vehicle {
  id: string
  tenant_id: string
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
  created_at: string
  updated_at: string
}

interface VehiclesResponse {
  data: Vehicle[]
  meta?: {
    current_page: number
    per_page: number
    total: number
  }
}

export function VehicleListPage() {
  const { t } = useTranslation(['common', 'vehicles'])
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const [searchQuery, setSearchQuery] = useState('')

  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['vehicles', searchQuery]),
    queryFn: async () => {
      const params = new URLSearchParams()
      if (searchQuery) params.append('search', searchQuery)
      const queryString = params.toString()
      const response = await api.get<VehiclesResponse>(`/vehicles${queryString ? `?${queryString}` : ''}`)
      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  const vehicles = useMemo(() => data?.data ?? [], [data?.data])
  const total = data?.meta?.total ?? vehicles.length

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className={`text-2xl font-bold ${textColors.primary}`}>{t('vehicles:title')}</h1>
          <p className={textColors.disabled}>
            {total} {total === 1 ? t('vehicles:singular') : t('vehicles:plural')} {t('vehicles:registered')}
          </p>
        </div>
        <Link
          to="/vehicles/new"
          className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.md} gap-2`}
        >
          <Plus className="h-4 w-4" />
          {t('vehicles:new')}
        </Link>
      </div>

      {/* Search */}
      <div className="flex items-center">
        <SearchInput
          value={searchQuery}
          onChange={setSearchQuery}
          placeholder={t('vehicles:searchPlaceholder')}
          className="w-full sm:w-96"
        />
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="flex items-center justify-center py-12">
          <div className={textColors.disabled}>{t('status.loading')}</div>
        </div>
      ) : error ? (
        <div className={`${tokens.alert.base} ${tokens.alert.error}`}>
          {t('errors.loadingFailed', 'Error loading data. Please try again.')}
        </div>
      ) : vehicles.length === 0 ? (
        <div className={`rounded-lg border-2 border-dashed ${borderColors.default} p-12 text-center`}>
          <Car className={`mx-auto h-12 w-12 ${textColors.disabled}`} />
          <h3 className={`mt-2 text-sm font-semibold ${textColors.primary}`}>
            {searchQuery ? t('common:status.noResults') : t('vehicles:empty.title')}
          </h3>
          <p className={`mt-1 text-sm ${textColors.disabled}`}>
            {searchQuery
              ? t('common:status.tryDifferentSearch')
              : t('vehicles:empty.description')}
          </p>
          {!searchQuery && (
            <div className="mt-6">
              <Link
                to="/vehicles/new"
                className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.md} gap-2`}
              >
                <Plus className="h-4 w-4" />
                {t('vehicles:new')}
              </Link>
            </div>
          )}
        </div>
      ) : (
        <div className={`overflow-hidden rounded-lg border ${borderColors.light} bg-white`}>
          <table className={`min-w-full divide-y ${borderColors.divideDefault}`}>
            <thead className={tokens.table.header}>
              <tr>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.disabled}`}>
                  {t('vehicles:table.vehicle')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.disabled}`}>
                  {t('vehicles:licensePlate')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.disabled}`}>
                  {t('vehicles:vin')}
                </th>
                <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${textColors.disabled}`}>
                  {t('vehicles:year')}
                </th>
                <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${textColors.disabled}`}>
                  {t('vehicles:mileage')}
                </th>
                <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.disabled}`}>
                  {t('vehicles:table.fuel')}
                </th>
              </tr>
            </thead>
            <tbody className={`divide-y ${borderColors.divideDefault} bg-white`}>
              {vehicles.map((vehicle) => (
                <tr key={vehicle.id} className={tokens.table.rowHover}>
                  <td className="whitespace-nowrap px-6 py-4">
                    <Link
                      to={`/vehicles/${vehicle.id}`}
                      className={`font-medium ${textColors.primary} hover:${textColors.brand}`}
                    >
                      {vehicle.brand} {vehicle.model}
                    </Link>
                    {vehicle.color && (
                      <p className={`text-sm ${textColors.disabled}`}>{vehicle.color}</p>
                    )}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <span className={`${tokens.table.cellMonoBadge} ${textColors.secondary}`}>
                      {vehicle.license_plate}
                    </span>
                  </td>
                  <td className={`whitespace-nowrap px-6 py-4 text-sm ${textColors.disabled} font-mono`}>
                    {vehicle.vin ?? '-'}
                  </td>
                  <td className={`whitespace-nowrap px-6 py-4 text-end text-sm ${textColors.primary}`}>
                    {vehicle.year ?? '-'}
                  </td>
                  <td className={`whitespace-nowrap px-6 py-4 text-end text-sm ${textColors.primary}`}>
                    {vehicle.mileage != null ? `${vehicle.mileage.toLocaleString()} km` : '-'}
                  </td>
                  <td className={`whitespace-nowrap px-6 py-4 text-sm ${textColors.disabled}`}>
                    {vehicle.fuel_type ?? '-'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
