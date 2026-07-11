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
import { formatCurrency as formatMoney, formatPercent } from '../../lib/format'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { useCompany } from '../../hooks/useCompany'
import { useTaxConfigName } from '../../hooks/useTaxConfigName'
import { servicesInvalidationPredicate } from './_invalidation'
import type { Service, PricingType } from './types'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

interface ServiceResponse {
  data: Service
}

const pricingTypeColors: Record<PricingType, string> = {
  flat_rate: `${colorTokens.intent.primary.bgSoft} ${colorTokens.intent.primary.textStronger}`,
  hourly: `${colorTokens.intent.accent.bgSoft} ${colorTokens.intent.accent.textStronger}`,
  percentage: `${colorTokens.intent.notice.bgSoft} ${colorTokens.intent.notice.textStronger}`,
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
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const { currentCompany } = useCompany()

  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['service', id]),
    queryFn: async () => {
      const response = await api.get<ServiceResponse>(`/services/${id}`)
      return response.data
    },
    enabled: Boolean(id) && !!tenantId && !!companyId,
  })

  const deleteMutation = useMutation({
    mutationFn: async () => {
      await api.delete(`/services/${id}`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: servicesInvalidationPredicate(tenantId, companyId),
      })
      navigate('/services')
    },
  })

  const service = data?.data

  const taxConfigName = useTaxConfigName(service?.default_tax_configuration_id)

  const formatServiceCurrency = (amount: string | null) => {
    if (!amount) return '-'
    const currencyCode = service?.currency ?? currentCompany?.currency ?? 'USD'
    return formatMoney(amount, { currency: currencyCode, locale: 'en-US' })
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
        <div className={`${colorTokens.text.subtle}`}>{t('status.loading')}</div>
      </div>
    )
  }

  if (error || !service) {
    return (
      <div className={`rounded-lg ${colorTokens.intent.danger.bgSubtle} p-4 ${colorTokens.intent.danger.textStrong}`}>
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
            className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.variants.hoverTextGray900}`}
          >
            <ArrowLeft className="h-4 w-4" />
            {t('actions.back')}
          </Link>
          <div>
            <div className="flex items-center gap-2">
              <Wrench className={`h-6 w-6 ${colorTokens.text.disabled}`} />
              <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>{service.name}</PageHeaderTitle>
              <span
                className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ${pricingTypeColors[service.pricing_type]}`}
              >
                <PricingIcon className="h-3 w-3" />
                {getPricingTypeLabel(service.pricing_type)}
              </span>
              <span
                className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${
                  service.is_active
                    ? `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStronger}`
                    : `${colorTokens.surface.muted} ${colorTokens.text.strong}`
                }`}
              >
                {service.is_active ? t('status.active') : t('status.inactive')}
              </span>
            </div>
            <p className={`${colorTokens.text.subtle} font-mono`}>{service.code}</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Link
            to={`/services/${id}/edit`}
            className={`inline-flex items-center gap-2 rounded-lg border ${colorTokens.border.default} bg-white px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.variants.hoverBgGray50}`}
          >
            <Edit className="h-4 w-4" />
            {t('actions.edit')}
          </Link>
          <button
            onClick={handleDelete}
            disabled={deleteMutation.isPending}
            className={`inline-flex items-center gap-2 rounded-lg border ${colorTokens.intent.danger.border} bg-white px-4 py-2 text-sm font-medium ${colorTokens.intent.danger.textStrong} ${colorTokens.variants.hoverBgRed50} disabled:opacity-50`}
          >
            <Trash2 className="h-4 w-4" />
            {t('actions.delete')}
          </button>
        </div>
      </div>

      {/* Details Card */}
      <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white p-6`}>
        <h2 className={`text-lg font-semibold ${colorTokens.text.primary} mb-4`}>
          {t('services.details', 'Service Details')}
        </h2>
        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <dt className={`text-sm ${colorTokens.text.subtle}`}>{t('services.fields.code', 'Code')}</dt>
            <dd className={`mt-1 text-sm font-mono font-medium ${colorTokens.text.primary}`}>{service.code}</dd>
          </div>
          <div>
            <dt className={`text-sm ${colorTokens.text.subtle}`}>{t('services.pricingType', 'Pricing Type')}</dt>
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
            <dt className={`text-sm ${colorTokens.text.subtle}`}>{t('fields.status', 'Status')}</dt>
            <dd className="mt-1">
              <span
                className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${
                  service.is_active
                    ? `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStronger}`
                    : `${colorTokens.surface.muted} ${colorTokens.text.strong}`
                }`}
              >
                {service.is_active ? t('status.active') : t('status.inactive')}
              </span>
            </dd>
          </div>
          <div>
            <dt className={`text-sm ${colorTokens.text.subtle}`}>{t('fields.currency', 'Currency')}</dt>
            <dd className={`mt-1 text-sm font-medium ${colorTokens.text.primary}`}>{service.currency}</dd>
          </div>
        </dl>
      </div>

      {/* Pricing Card */}
      <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white p-6`}>
        <h2 className={`text-lg font-semibold ${colorTokens.text.primary} mb-4`}>
          {t('services.pricing', 'Pricing')}
        </h2>
        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {service.pricing_type === 'flat_rate' && (
            <div>
              <dt className={`text-sm ${colorTokens.text.subtle}`}>{t('services.fields.basePrice', 'Base Price')}</dt>
              <dd className={`mt-1 text-lg font-semibold ${colorTokens.text.primary}`}>
                {formatServiceCurrency(service.base_price)}
              </dd>
            </div>
          )}
          {service.pricing_type === 'hourly' && (
            <>
              <div>
                <dt className={`text-sm ${colorTokens.text.subtle}`}>{t('services.fields.hourlyRate', 'Hourly Rate')}</dt>
                <dd className={`mt-1 text-lg font-semibold ${colorTokens.text.primary}`}>
                  {formatServiceCurrency(service.hourly_rate)}/h
                </dd>
              </div>
              <div>
                <dt className={`text-sm ${colorTokens.text.subtle}`}>{t('services.fields.defaultDuration', 'Default Duration')}</dt>
                <dd className={`mt-1 text-lg font-semibold ${colorTokens.text.primary}`}>
                  {formatDuration(service.default_duration_minutes)}
                </dd>
              </div>
            </>
          )}
          {service.pricing_type === 'percentage' && (
            <div>
              <dt className={`text-sm ${colorTokens.text.subtle}`}>{t('services.fields.percentage', 'Percentage')}</dt>
              <dd className={`mt-1 text-lg font-semibold ${colorTokens.text.primary}`}>
                {formatPercent(service.base_price)}
              </dd>
            </div>
          )}
          {(service.tax_rate || service.default_tax_configuration_id) && (
            <div>
              <dt className={`text-sm ${colorTokens.text.subtle}`}>{t('services.fields.taxRate', 'Tax Rate')}</dt>
              <dd className={`mt-1 text-sm font-medium ${colorTokens.text.primary}`}>
                {taxConfigName ?? (service.tax_rate ? formatPercent(service.tax_rate) : '—')}
              </dd>
            </div>
          )}
        </dl>
      </div>

      {/* Category Card */}
      {service.category && (
        <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white p-6`}>
          <h2 className={`text-lg font-semibold ${colorTokens.text.primary} mb-4`}>
            {t('services.category', 'Category')}
          </h2>
          <div className="flex items-center gap-3">
            <div className={`flex h-10 w-10 items-center justify-center rounded-lg ${colorTokens.surface.muted}`}>
              <FolderTree className={`h-5 w-5 ${colorTokens.text.muted}`} />
            </div>
            <div>
              <p className={`font-medium ${colorTokens.text.primary}`}>{service.category.name}</p>
              {service.category.description && (
                <p className={`text-sm ${colorTokens.text.subtle}`}>{service.category.description}</p>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Description Card */}
      {service.description && (
        <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white p-6`}>
          <h2 className={`text-lg font-semibold ${colorTokens.text.primary} mb-4`}>
            {t('fields.description', 'Description')}
          </h2>
          <p className={`${colorTokens.text.secondary} whitespace-pre-wrap`}>{service.description}</p>
        </div>
      )}

      {/* Metadata Card */}
      <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white p-6`}>
        <h2 className={`text-lg font-semibold ${colorTokens.text.primary} mb-4`}>
          {t('services.metadata', 'Metadata')}
        </h2>
        <dl className="grid gap-4 sm:grid-cols-2">
          <div>
            <dt className={`text-sm ${colorTokens.text.subtle}`}>{t('fields.created', 'Created')}</dt>
            <dd className={`mt-1 text-sm font-medium ${colorTokens.text.primary}`}>
              {formatDate(service.created_at)}
            </dd>
          </div>
          {service.updated_at && (
            <div>
              <dt className={`text-sm ${colorTokens.text.subtle}`}>{t('fields.updated', 'Last Updated')}</dt>
              <dd className={`mt-1 text-sm font-medium ${colorTokens.text.primary}`}>
                {formatDate(service.updated_at)}
              </dd>
            </div>
          )}
        </dl>
      </div>
    </div>
  )
}
