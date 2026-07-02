import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, CreditCard, Calendar } from 'lucide-react'
import { SearchInput } from '../../components/molecules/SearchInput/SearchInput'
import { api } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { cn } from '../../lib/utils'
import { tokens, textColors } from '../../lib/designTokens'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { formatCurrency } from '../../lib/format'
import { Button, StatusBadge, statusTone, type StatusTone } from '../../components/atoms'
import {
  DataTable,
  type DataTableColumn,
  EmptyState,
  ListPageLayout,
} from '../../components/molecules'

interface Payment {
  id: string
  payment_number: string
  amount: number
  payment_date: string
  payment_method_id: string
  payment_method_name: string
  partner_id: string
  partner_name: string
  partner_type: 'customer' | 'supplier' | 'both' | null
  payment_type: string | null
  status: 'pending' | 'completed' | 'cancelled'
  created_at: string
}

interface PaymentsResponse {
  data: Payment[]
  meta?: { total: number }
}

/**
 * Payment lifecycle statuses routed through the one sanctioned StatusBadge
 * palette. `pending`/`completed`/`cancelled` are already in the built-in tone
 * map, so no overrides are needed — kept here for parity/documentation.
 */
const paymentStatusTones: Record<string, StatusTone> = {}

export function PaymentListPage() {
  const { t } = useTranslation(['common', 'treasury'])
  const navigate = useNavigate()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const currentCompany = useCompanyStore((state) => state.getCurrentCompany())
  const [search, setSearch] = useState('')

  // Get translated status label
  const getStatusLabel = (status: Payment['status']) => {
    return t(`treasury:payments.statuses.${status}`, status)
  }

  // Get company currency with fallback
  const companyCurrency = currentCompany?.currency ?? 'EUR'
  const companyLocale = currentCompany?.locale?.replace('_', '-') ?? 'en-US'

  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['payments', search]),
    queryFn: async () => {
      const params = new URLSearchParams()
      if (search) params.set('search', search)
      const queryString = params.toString()
      const response = await api.get<PaymentsResponse>(
        `/payments${queryString ? `?${queryString}` : ''}`,
      )
      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  const payments = data?.data ?? []
  const total = data?.meta?.total ?? payments.length

  // Format currency using company settings
  const formatAmount = (amount: number) => {
    return formatCurrency(amount, {
      currency: companyCurrency,
      locale: companyLocale,
    })
  }

  const addPath = '/treasury/payments/new'

  const columns: DataTableColumn<Payment>[] = [
    {
      key: 'number',
      header: t('treasury:payments.number'),
      render: (payment) => (
        <Link
          to={`/treasury/payments/${payment.id}`}
          className={cn('font-medium', textColors.primary, 'hover:underline')}
        >
          {payment.payment_number}
        </Link>
      ),
    },
    {
      key: 'partner',
      header: t('treasury:payments.partner'),
      render: (payment) =>
        payment.partner_id ? (
          <Link
            to={
              payment.partner_type === 'supplier' || payment.payment_type === 'supplier_payment'
                ? `/purchases/suppliers/${payment.partner_id}`
                : `/sales/customers/${payment.partner_id}`
            }
            className={cn(textColors.brand, 'hover:underline')}
          >
            {payment.partner_name ?? t('treasury:payments.messages.noPartnerLinked')}
          </Link>
        ) : (
          <span className={textColors.tertiary}>
            {payment.partner_name ?? t('treasury:payments.messages.noPartnerLinked')}
          </span>
        ),
    },
    {
      key: 'method',
      header: t('treasury:payments.method'),
      render: (payment) => (
        <span className={textColors.tertiary}>{payment.payment_method_name}</span>
      ),
    },
    {
      key: 'date',
      header: t('treasury:payments.date'),
      render: (payment) => (
        <div className={cn('flex items-center gap-1', textColors.tertiary)}>
          <Calendar className="h-3.5 w-3.5" />
          {new Date(payment.payment_date).toLocaleDateString()}
        </div>
      ),
    },
    {
      key: 'status',
      header: t('treasury:payments.status'),
      render: (payment) => (
        <StatusBadge tone={statusTone(payment.status, paymentStatusTones)}>
          {getStatusLabel(payment.status)}
        </StatusBadge>
      ),
    },
    {
      key: 'amount',
      header: t('treasury:payments.amount'),
      numeric: true,
      cellClassName: 'font-medium',
      render: (payment) => formatAmount(payment.amount),
    },
    {
      key: 'actions',
      header: <span className="sr-only">{t('actions.actions')}</span>,
      align: 'right',
      render: (payment) => (
        <Link to={`/treasury/payments/${payment.id}`} className={textColors.brand}>
          {t('actions.view')}
        </Link>
      ),
    },
  ]

  return (
    <ListPageLayout
      title={t('treasury:payments.title')}
      subtitle={`${String(total)} ${
        total === 1
          ? t('treasury:payments.singular', 'payment')
          : t('treasury:payments.plural', 'payments')
      } ${t('total')}`}
      actions={
        <Button className="gap-2" onClick={() => { void navigate(addPath) }}>
          <Plus className="h-4 w-4" />
          {t('treasury:payments.record')}
        </Button>
      }
    >
      <div className="mb-4">
        <SearchInput
          value={search}
          onChange={setSearch}
          placeholder={t('common:actions.search')}
          className="w-full sm:w-96"
        />
      </div>
      {error ? (
        <div className={cn(tokens.alert.base, tokens.alert.error)}>
          {t('errors.loadingFailed', 'Error loading data. Please try again.')}
        </div>
      ) : (
        <DataTable
          columns={columns}
          data={payments}
          keyExtractor={(payment) => payment.id}
          isLoading={isLoading}
          emptyState={
            <div className="py-6">
              <EmptyState
                icon={<CreditCard className={cn('mx-auto h-12 w-12', textColors.disabled)} />}
                title={t('treasury:payments.empty.title')}
                description={t('treasury:payments.empty.description')}
              />
              <div className="mt-6 flex justify-center">
                <Button className="gap-2" onClick={() => { void navigate(addPath) }}>
                  <Plus className="h-4 w-4" />
                  {t('treasury:payments.record')}
                </Button>
              </div>
            </div>
          }
        />
      )}
    </ListPageLayout>
  )
}
