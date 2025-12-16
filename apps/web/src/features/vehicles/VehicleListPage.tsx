import { useState, useMemo } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, Car } from 'lucide-react'
import { api } from '../../lib/api'
import { SearchInput } from '../../components/ui/SearchInput'

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
  const [searchQuery, setSearchQuery] = useState('')

  const { data, isLoading, error } = useQuery({
    queryKey: ['vehicles', searchQuery],
    queryFn: async () => {
      const params = new URLSearchParams()
      if (searchQuery) params.append('search', searchQuery)
      const queryString = params.toString()
      const response = await api.get<VehiclesResponse>(`/vehicles${queryString ? `?${queryString}` : ''}`)
      return response.data
    },
  })

  const vehicles = useMemo(() => data?.data ?? [], [data?.data])
  const total = data?.meta?.total ?? vehicles.length

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">{t('vehicles:title')}</h1>
          <p className="text-gray-500">
            {total} {total === 1 ? t('vehicles:singular') : t('vehicles:plural')} {t('vehicles:registered')}
          </p>
        </div>
        <Link
          to="/vehicles/new"
          className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
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
          <div className="text-gray-500">{t('status.loading')}</div>
        </div>
      ) : error ? (
        <div className="rounded-lg bg-red-50 p-4 text-red-700">
          {t('errors.loadingFailed', 'Error loading data. Please try again.')}
        </div>
      ) : vehicles.length === 0 ? (
        <div className="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
          <Car className="mx-auto h-12 w-12 text-gray-400" />
          <h3 className="mt-2 text-sm font-semibold text-gray-900">
            {searchQuery ? t('common:status.noResults') : t('vehicles:empty.title')}
          </h3>
          <p className="mt-1 text-sm text-gray-500">
            {searchQuery
              ? t('common:status.tryDifferentSearch')
              : t('vehicles:empty.description')}
          </p>
          {!searchQuery && (
            <div className="mt-6">
              <Link
                to="/vehicles/new"
                className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
              >
                <Plus className="h-4 w-4" />
                {t('vehicles:new')}
              </Link>
            </div>
          )}
        </div>
      ) : (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('vehicles:table.vehicle')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('vehicles:licensePlate')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('vehicles:vin')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('vehicles:year')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('vehicles:mileage')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('vehicles:table.fuel')}
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {vehicles.map((vehicle) => (
                <tr key={vehicle.id} className="hover:bg-gray-50">
                  <td className="whitespace-nowrap px-6 py-4">
                    <Link
                      to={`/vehicles/${vehicle.id}`}
                      className="font-medium text-gray-900 hover:text-blue-600"
                    >
                      {vehicle.brand} {vehicle.model}
                    </Link>
                    {vehicle.color && (
                      <p className="text-sm text-gray-500">{vehicle.color}</p>
                    )}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <span className="inline-flex rounded-md bg-gray-100 px-2 py-1 text-sm font-mono font-medium text-gray-800">
                      {vehicle.license_plate}
                    </span>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500 font-mono">
                    {vehicle.vin ?? '-'}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                    {vehicle.year ?? '-'}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                    {vehicle.mileage != null ? `${vehicle.mileage.toLocaleString()} km` : '-'}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
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
