import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Vault, Building2, CreditCard, Wallet, Calendar, ExternalLink, BookOpen, Pencil, Check, X } from 'lucide-react'
import { toast } from 'sonner'
import { api, apiPatch } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { formatCurrency } from '../../lib/format'
import { textColors, borderColors } from '../../lib/designTokens'
import { useAccounts } from '../finance/hooks/useAccounts'

interface Repository {
  id: string
  code: string
  name: string
  type: 'cash_register' | 'safe' | 'bank_account' | 'virtual'
  bank_name: string | null
  account_number: string | null
  iban: string | null
  bic: string | null
  balance: string
  is_active: boolean
  gl_account_id: string | null
  gl_account: { id: string; code: string; name: string } | null
}

interface RepositoryResponse {
  data: Repository
}

interface Allocation {
  document_id: string
  document_number: string
  amount: string
}

interface Transaction {
  id: string
  payment_number: string
  partner_id: string
  partner_name: string | null
  payment_method_name: string | null
  amount: string
  currency: string
  payment_date: string
  status: string
  payment_type: string | null
  reference: string | null
  notes: string | null
  allocations: Allocation[]
  created_at: string
}

interface TransactionsResponse {
  data: Transaction[]
  meta: {
    total: number
    repository_id: string
    repository_name: string
  }
}

const typeIcons: Record<Repository['type'], React.ComponentType<{ className?: string }>> = {
  cash_register: CreditCard,
  safe: Vault,
  bank_account: Building2,
  virtual: Wallet,
}

// typeLabels resolved at render time via t() — see usage sites

const typeColors: Record<Repository['type'], string> = {
  cash_register: 'bg-green-100 text-green-800',
  safe: 'bg-purple-100 text-purple-800',
  bank_account: 'bg-blue-100 text-blue-800',
  virtual: 'bg-gray-100 text-gray-800',
}

const statusColors: Record<string, string> = {
  pending: 'bg-yellow-100 text-yellow-800',
  completed: 'bg-green-100 text-green-800',
  cancelled: 'bg-red-100 text-red-800',
  failed: 'bg-red-100 text-red-800',
  reversed: 'bg-gray-100 text-gray-800',
}

// statusLabels resolved at render time via t() — see usage sites

function GlAccountField({ repository }: { repository: Repository }) {
  const { t } = useTranslation(['treasury', 'common'])
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const [isEditing, setIsEditing] = useState(false)
  const [selectedAccountId, setSelectedAccountId] = useState(repository.gl_account_id ?? '')

  const { data: accountsData } = useAccounts({ active: true })
  const accounts = accountsData ?? []

  const mutation = useMutation({
    mutationFn: (glAccountId: string | null) =>
      apiPatch(`/payment-repositories/${repository.id}`, { gl_account_id: glAccountId }),
    onSuccess: async () => {
      if (tenantId !== null && companyId !== null) {
        await queryClient.invalidateQueries({
          queryKey: tenantScopedKey(['payment-repository', repository.id]),
        })
      }
      toast.success(t('treasury:repositories.glAccountUpdated'))
      setIsEditing(false)
    },
    onError: () => {
      toast.error(t('common:errors.operationFailed'))
    },
  })

  if (isEditing) {
    return (
      <div className="flex justify-between items-start">
        <dt className={`${textColors.disabled} flex items-center gap-1`}>
          <BookOpen className="h-3.5 w-3.5" />
          {t('treasury:repositories.glAccount')}
        </dt>
        <dd className="flex items-center gap-2">
          <select
            value={selectedAccountId}
            onChange={(e) => { setSelectedAccountId(e.target.value) }}
            className={`rounded-md border ${borderColors.default} px-2 py-1 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none`}
          >
            <option value="">{t('treasury:repositories.noGlAccount')}</option>
            {accounts.map((acc) => (
              <option key={acc.id} value={acc.id}>
                {acc.code} - {acc.name}
              </option>
            ))}
          </select>
          <button
            onClick={() => { mutation.mutate(selectedAccountId || null) }}
            disabled={mutation.isPending}
            className={`rounded p-1 ${textColors.success} hover:bg-green-50`}
          >
            <Check className="h-4 w-4" />
          </button>
          <button
            onClick={() => { setIsEditing(false); setSelectedAccountId(repository.gl_account_id ?? '') }}
            className={`rounded p-1 ${textColors.disabled} hover:bg-gray-50`}
          >
            <X className="h-4 w-4" />
          </button>
        </dd>
      </div>
    )
  }

  return (
    <div className="flex justify-between">
      <dt className={`${textColors.disabled} flex items-center gap-1`}>
        <BookOpen className="h-3.5 w-3.5" />
        {t('treasury:repositories.glAccount')}
      </dt>
      <dd className="flex items-center gap-2">
        {repository.gl_account ? (
          <span className={`${textColors.primary} font-mono text-sm`}>
            {repository.gl_account.code} - {repository.gl_account.name}
          </span>
        ) : (
          <span className={`${textColors.warningDark} text-sm italic`}>
            {t('treasury:repositories.noGlAccountWarning')}
          </span>
        )}
        <button
          onClick={() => { setIsEditing(true) }}
          className={`rounded p-1 ${textColors.disabled} ${textColors.hoverSecondary} hover:bg-gray-50`}
        >
          <Pencil className="h-3.5 w-3.5" />
        </button>
      </dd>
    </div>
  )
}

export function RepositoryDetailPage() {
  const { t } = useTranslation(['treasury', 'common'])
  const { id } = useParams<{ id: string }>()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const currentCompany = useCompanyStore((state) => state.getCurrentCompany())

  // Get company currency with fallback
  const companyCurrency = currentCompany?.currency ?? 'EUR'
  const companyLocale = currentCompany?.locale?.replace('_', '-') ?? 'en-US'

  const { data: repositoryData, isLoading: isLoadingRepository, error: repositoryError } = useQuery({
    queryKey: tenantScopedKey(['payment-repository', id]),
    queryFn: async () => {
      const response = await api.get<RepositoryResponse>(`/payment-repositories/${id}`)
      return response.data
    },
    enabled: tenantId !== null && companyId !== null && !!id,
  })

  const { data: transactionsData, isLoading: isLoadingTransactions } = useQuery({
    queryKey: tenantScopedKey(['payment-repository-transactions', id]),
    queryFn: async () => {
      const response = await api.get<TransactionsResponse>(`/payment-repositories/${id}/transactions`)
      return response.data
    },
    enabled: tenantId !== null && companyId !== null && !!id,
  })

  const repository = repositoryData?.data
  const transactions = Array.isArray(transactionsData?.data) ? transactionsData.data : []

  // Format currency using company settings
  const formatAmount = (amount: string | number) => {
    const num = typeof amount === 'string' ? parseFloat(amount) : amount
    return formatCurrency(num, {
      currency: companyCurrency,
      locale: companyLocale,
    })
  }

  if (isLoadingRepository) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className="text-gray-500">{t('common:status.loading')}</div>
      </div>
    )
  }

  if (repositoryError || !repository) {
    return (
      <div className="space-y-6">
        <Link
          to="/treasury/repositories"
          className="inline-flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('common:actions.back')}
        </Link>
        <div className="rounded-lg bg-red-50 p-4 text-red-700">
          {t('common:errors.loadingFailed')}
        </div>
      </div>
    )
  }

  const Icon = typeIcons[repository.type]

  return (
    <div className="space-y-6">
      {/* Back Link */}
      <Link
        to="/treasury/repositories"
        className="inline-flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700"
      >
        <ArrowLeft className="h-4 w-4" />
        {t('common:navigation.repositories')}
      </Link>

      {/* Header */}
      <div className="flex items-start justify-between">
        <div className="flex items-center gap-4">
          <div className={`rounded-xl p-3 ${typeColors[repository.type]}`}>
            <Icon className="h-8 w-8" />
          </div>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">{repository.name}</h1>
            <p className="text-sm text-gray-500 font-mono">{repository.code}</p>
          </div>
        </div>
        <div className="text-end">
          <p className="text-sm text-gray-500">{t('treasury:repositories.currentBalance')}</p>
          <p className={`text-3xl font-bold ${parseFloat(repository.balance) >= 0 ? 'text-green-600' : 'text-red-600'}`}>
            {formatAmount(repository.balance)}
          </p>
        </div>
      </div>

      {/* Repository Info */}
      <div className="grid gap-6 md:grid-cols-2">
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-4">{t('common:details')}</h2>
          <dl className="space-y-3">
            <div className="flex justify-between">
              <dt className="text-gray-500">{t('treasury:repositories.type')}</dt>
              <dd>
                <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${typeColors[repository.type]}`}>
                  {t(`treasury:repositories.types.${repository.type}`)}
                </span>
              </dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-gray-500">{t('common:fields.status')}</dt>
              <dd>
                <span
                  className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${
                    repository.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
                  }`}
                >
                  {repository.is_active ? t('common:active') : t('common:inactive')}
                </span>
              </dd>
            </div>
            {repository.bank_name && (
              <div className="flex justify-between">
                <dt className="text-gray-500">{t('treasury:repositories.bankName')}</dt>
                <dd className="text-gray-900">{repository.bank_name}</dd>
              </div>
            )}
            {repository.account_number && (
              <div className="flex justify-between">
                <dt className="text-gray-500">{t('treasury:repositories.accountNumber')}</dt>
                <dd className="text-gray-900 font-mono">{repository.account_number}</dd>
              </div>
            )}
            {repository.iban && (
              <div className="flex justify-between">
                <dt className="text-gray-500">IBAN</dt>
                <dd className="text-gray-900 font-mono text-sm">{repository.iban}</dd>
              </div>
            )}
            {repository.bic && (
              <div className="flex justify-between">
                <dt className="text-gray-500">BIC/SWIFT</dt>
                <dd className="text-gray-900 font-mono">{repository.bic}</dd>
              </div>
            )}
            <GlAccountField repository={repository} />
          </dl>
        </div>

        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-4">{t('treasury:repositories.summary')}</h2>
          <dl className="space-y-3">
            <div className="flex justify-between">
              <dt className="text-gray-500">{t('treasury:repositories.totalTransactions')}</dt>
              <dd className="text-gray-900 font-semibold">{transactions.length}</dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-gray-500">{t('treasury:repositories.totalReceived')}</dt>
              <dd className="text-green-600 font-semibold">
                {formatAmount(
                  transactions
                    .filter((t) => t.status === 'completed')
                    .reduce((sum, t) => sum + parseFloat(t.amount), 0)
                )}
              </dd>
            </div>
          </dl>
        </div>
      </div>

      {/* Transaction History */}
      <div className="rounded-lg border border-gray-200 bg-white">
        <div className="border-b border-gray-200 px-6 py-4">
          <h2 className="text-lg font-semibold text-gray-900">
            {t('treasury:repositories.transactionHistory')}
          </h2>
        </div>

        {isLoadingTransactions ? (
          <div className="flex items-center justify-center py-12">
            <div className="text-gray-500">{t('common:status.loading')}</div>
          </div>
        ) : transactions.length === 0 ? (
          <div className="px-6 py-12 text-center">
            <p className="text-gray-500">{t('treasury:repositories.noTransactions')}</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('treasury:payments.title')}
                  </th>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('common:fields.contact')}
                  </th>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('treasury:payments.method')}
                  </th>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('common:fields.date')}
                  </th>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('common:fields.status')}
                  </th>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('treasury:repositories.allocatedTo')}
                  </th>
                  <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('treasury:payments.amount')}
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {transactions.map((transaction) => (
                  <tr key={transaction.id} className="hover:bg-gray-50">
                    <td className="whitespace-nowrap px-6 py-4">
                      <Link
                        to={`/treasury/payments/${transaction.id}`}
                        className="font-medium text-blue-600 hover:text-blue-800 hover:underline"
                      >
                        {transaction.payment_number}
                      </Link>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                      {transaction.partner_id ? (
                        <Link
                          to={`/sales/customers/${transaction.partner_id}`}
                          className="text-blue-600 hover:text-blue-800 hover:underline"
                        >
                          {transaction.partner_name ?? t('common:status.unknown')}
                        </Link>
                      ) : (
                        <span className="text-gray-500">{transaction.partner_name ?? t('common:status.unknown')}</span>
                      )}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                      {transaction.payment_method_name ?? '-'}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                      <div className="flex items-center gap-1">
                        <Calendar className="h-3.5 w-3.5" />
                        {new Date(transaction.payment_date).toLocaleDateString()}
                      </div>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <span
                        className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${
                          statusColors[transaction.status] ?? 'bg-gray-100 text-gray-800'
                        }`}
                      >
                        {t(`treasury:payments.statuses.${transaction.status}`, transaction.status)}
                      </span>
                    </td>
                    <td className="px-6 py-4 text-sm text-gray-500">
                      {transaction.allocations.length > 0 ? (
                        <div className="flex flex-wrap gap-1">
                          {transaction.allocations.slice(0, 2).map((allocation) => (
                            <Link
                              key={allocation.document_id}
                              to={`/sales/invoices/${allocation.document_id}`}
                              className="inline-flex items-center gap-1 text-blue-600 hover:text-blue-800"
                            >
                              {allocation.document_number}
                              <ExternalLink className="h-3 w-3" />
                            </Link>
                          ))}
                          {transaction.allocations.length > 2 && (
                            <span className="text-gray-400">
                              +{transaction.allocations.length - 2} {t('treasury:repositories.moreAllocations')}
                            </span>
                          )}
                        </div>
                      ) : (
                        <span className="text-gray-400 italic">
                          {transaction.payment_type === 'advance' ? t('treasury:payments.types.advance') : '-'}
                        </span>
                      )}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-end text-sm font-medium text-green-600">
                      +{formatAmount(transaction.amount)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  )
}
