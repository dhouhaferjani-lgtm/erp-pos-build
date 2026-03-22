import { Link, useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import {
  ArrowLeft,
  Edit,
  Trash2,
  Wrench,
  Clock,
  DollarSign,
  Percent,
  FolderTree,
} from 'lucide-react'
import { api } from '../../lib/api'
import { useCompany } from '../../hooks/useCompany'
import { useTaxConfigName } from '../../hooks/useTaxConfigName'
import type { Service, PricingType } from './types'

interface ServiceResponse {
  data: Service
}

const pricingTypeColors: Record<PricingType, string> = {
  flat_rate: 'bg-blue-100 text-blue-800',
  hourly: 'bg-purple-100 text-purple-800',
  percentage: 'bg-orange-100 text-orange-800',
}

const pricingTypeIcons: Record<PricingType, typeof DollarSign> = {
  flat_rate: DollarSign,
  hourly: Clock,
  percentage: Percent,
}

export function ServiceDetailPage() {
  const { t } = useTranslation()
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { currentCompany } = useCompany()

  const { data, isLoading, error } = useQuery({
    queryKey: ['service', id],
    queryFn: async () => {
      const response = await api.get<ServiceResponse>(`/services/${id}`)
      return response.data
    },
    enabled: Boolean(id),
  })

  const deleteMutation = useMutation({
    mutationFn: async () => {
      await api.delete(`/services/${id}`)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['services'] })
      navigate('/services')
    },
  })

  const service = data?.data

  const taxConfigName = useTaxConfigName(service?.default_tax_configuration_id)

  const formatCurrency = (amount: string | null) => {
    if (!amount) return '-'
    const currencyCode = service?.currency ?? currentCompany?.currency ?? 'USD'
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: currencyCode,
    }).format(parseFloat(amount))
  }

  const formatDuration = (minutes: number | null) => {
    if (!minutes) return '-'
    if (minutes < 60) return `${minutes} min`
    const hours = Math.floor(minutes / 60)
    const remainingMinutes = minutes % 60
    return remainingMinutes > 0 ? `${hours}h ${remainingMinutes}m` : `${hours}h`
  }

  const formatDate = (dateString: string | null) => {
    if (!dateString) return '-'
    return new Date(dateString).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    })
  }

  const getPricingTypeLabel = (type: PricingType) => {
    const labels: Record<PricingType, string> = {
      flat_rate: t('services.pricingTypes.flatRate', 'Flat Rate'),
      hourly: t('services.pricingTypes.hourly', 'Hourly'),
      percentage: t('services.pricingTypes.percentage', 'Percentage'),
    }
    return labels[type]
  }

  const handleDelete = () => {
    if (window.confirm(t('confirmation.delete'))) {
      deleteMutation.mutate()
    }
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className="text-gray-500">{t('status.loading')}</div>
      </div>
    )
  }

  if (error || !service) {
    return (
      <div className="rounded-lg bg-red-50 p-4 text-red-700">
        {t('errors.loadingFailed', 'Error loading data. Please try again.')}
      </div>
    )
  }

  const PricingIcon = pricingTypeIcons[service.pricing_type]

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Link
            to="/services"
            className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
          >
            <ArrowLeft className="h-4 w-4" />
            {t('actions.back')}
          </Link>
          <div>
            <div className="flex items-center gap-2">
              <Wrench className="h-6 w-6 text-gray-400" />
              <h1 className="text-2xl font-bold text-gray-900">{service.name}</h1>
              <span
                className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ${pricingTypeColors[service.pricing_type]}`}
              >
                <PricingIcon className="h-3 w-3" />
                {getPricingTypeLabel(service.pricing_type)}
              </span>
              <span
                className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${
                  service.is_active
                    ? 'bg-green-100 text-green-800'
                    : 'bg-gray-100 text-gray-800'
                }`}
              >
                {service.is_active ? t('status.active') : t('status.inactive')}
              </span>
            </div>
            <p className="text-gray-500 font-mono">{service.code}</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Link
            to={`/services/${id}/edit`}
            className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
          >
            <Edit className="h-4 w-4" />
            {t('actions.edit')}
          </Link>
          <button
            onClick={handleDelete}
            disabled={deleteMutation.isPending}
            className="inline-flex items-center gap-2 rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
          >
            <Trash2 className="h-4 w-4" />
            {t('actions.delete')}
          </button>
        </div>
      </div>

      {/* Details Card */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <h2 className="text-lg font-semibold text-gray-900 mb-4">
          {t('services.details', 'Service Details')}
        </h2>
        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <dt className="text-sm text-gray-500">{t('services.fields.code', 'Code')}</dt>
            <dd className="mt-1 text-sm font-mono font-medium text-gray-900">{service.code}</dd>
          </div>
          <div>
            <dt className="text-sm text-gray-500">{t('services.pricingType', 'Pricing Type')}</dt>
            <dd className="mt-1">
              <span
                className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ${pricingTypeColors[service.pricing_type]}`}
              >
                <PricingIcon className="h-3 w-3" />
                {getPricingTypeLabel(service.pricing_type)}
              </span>
            </dd>
          </div>
          <div>
            <dt className="text-sm text-gray-500">{t('fields.status', 'Status')}</dt>
            <dd className="mt-1">
              <span
                className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${
                  service.is_active
                    ? 'bg-green-100 text-green-800'
                    : 'bg-gray-100 text-gray-800'
                }`}
              >
                {service.is_active ? t('status.active') : t('status.inactive')}
              </span>
            </dd>
          </div>
          <div>
            <dt className="text-sm text-gray-500">{t('fields.currency', 'Currency')}</dt>
            <dd className="mt-1 text-sm font-medium text-gray-900">{service.currency}</dd>
          </div>
        </dl>
      </div>

      {/* Pricing Card */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <h2 className="text-lg font-semibold text-gray-900 mb-4">
          {t('services.pricing', 'Pricing')}
        </h2>
        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {service.pricing_type === 'flat_rate' && (
            <div>
              <dt className="text-sm text-gray-500">{t('services.fields.basePrice', 'Base Price')}</dt>
              <dd className="mt-1 text-lg font-semibold text-gray-900">
                {formatCurrency(service.base_price)}
              </dd>
            </div>
          )}
          {service.pricing_type === 'hourly' && (
            <>
              <div>
                <dt className="text-sm text-gray-500">{t('services.fields.hourlyRate', 'Hourly Rate')}</dt>
                <dd className="mt-1 text-lg font-semibold text-gray-900">
                  {formatCurrency(service.hourly_rate)}/h
                </dd>
              </div>
              <div>
                <dt className="text-sm text-gray-500">{t('services.fields.defaultDuration', 'Default Duration')}</dt>
                <dd className="mt-1 text-lg font-semibold text-gray-900">
                  {formatDuration(service.default_duration_minutes)}
                </dd>
              </div>
            </>
          )}
          {service.pricing_type === 'percentage' && (
            <div>
              <dt className="text-sm text-gray-500">{t('services.fields.percentage', 'Percentage')}</dt>
              <dd className="mt-1 text-lg font-semibold text-gray-900">
                {service.base_price}%
              </dd>
            </div>
          )}
          {(service.tax_rate || service.default_tax_configuration_id) && (
            <div>
              <dt className="text-sm text-gray-500">{t('services.fields.taxRate', 'Tax Rate')}</dt>
              <dd className="mt-1 text-sm font-medium text-gray-900">
                {taxConfigName ?? (service.tax_rate ? `${service.tax_rate}%` : '—')}
              </dd>
            </div>
          )}
        </dl>
      </div>

      {/* Category Card */}
      {service.category && (
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-4">
            {t('services.category', 'Category')}
          </h2>
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-100">
              <FolderTree className="h-5 w-5 text-gray-600" />
            </div>
            <div>
              <p className="font-medium text-gray-900">{service.category.name}</p>
              {service.category.description && (
                <p className="text-sm text-gray-500">{service.category.description}</p>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Description Card */}
      {service.description && (
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-4">
            {t('fields.description', 'Description')}
          </h2>
          <p className="text-gray-700 whitespace-pre-wrap">{service.description}</p>
        </div>
      )}

      {/* Metadata Card */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <h2 className="text-lg font-semibold text-gray-900 mb-4">
          {t('services.metadata', 'Metadata')}
        </h2>
        <dl className="grid gap-4 sm:grid-cols-2">
          <div>
            <dt className="text-sm text-gray-500">{t('fields.created', 'Created')}</dt>
            <dd className="mt-1 text-sm font-medium text-gray-900">
              {formatDate(service.created_at)}
            </dd>
          </div>
          {service.updated_at && (
            <div>
              <dt className="text-sm text-gray-500">{t('fields.updated', 'Last Updated')}</dt>
              <dd className="mt-1 text-sm font-medium text-gray-900">
                {formatDate(service.updated_at)}
              </dd>
            </div>
          )}
        </dl>
      </div>
    </div>
  )
}
