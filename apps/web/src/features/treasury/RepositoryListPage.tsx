import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, Vault, Building2, CreditCard, Wallet } from 'lucide-react'
import { api } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { formatCurrency } from '../../lib/format'
import { usePermissions } from '../../hooks/usePermissions'
import { AddRepositoryModal } from '../../components/organisms'

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
}

interface RepositoriesResponse {
  data: Repository[]
}

const typeIcons: Record<Repository['type'], React.ComponentType<{ className?: string }>> = {
  cash_register: CreditCard,
  safe: Vault,
  bank_account: Building2,
  virtual: Wallet,
}

// Type labels are now loaded from translations

const typeColors: Record<Repository['type'], string> = {
  cash_register: 'bg-green-100 text-green-800',
  safe: 'bg-purple-100 text-purple-800',
  bank_account: 'bg-blue-100 text-blue-800',
  virtual: 'bg-gray-100 text-gray-800',
}

export function RepositoryListPage() {
  const { t } = useTranslation(['common', 'treasury'])
  const queryClient = useQueryClient()
  const [showAddModal, setShowAddModal] = useState(false)
  const { hasPermission } = usePermissions()
  const canManageRepositories = hasPermission('repositories.manage')
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const currentCompany = useCompanyStore((state) => state.getCurrentCompany())

  // Get translated type label
  const getTypeLabel = (type: Repository['type']) => {
    return t(`treasury:repositories.types.${type}`, type)
  }

  // Get company currency with fallback
  const companyCurrency = currentCompany?.currency ?? 'EUR'
  const companyLocale = currentCompany?.locale?.replace('_', '-') ?? 'en-US'

  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['payment-repositories']),
    queryFn: async () => {
      const response = await api.get<RepositoriesResponse>('/payment-repositories')
      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  const repositories = data?.data ?? []

  // Format currency using company settings
  const formatAmount = (amount: string | number) => {
    const num = typeof amount === 'string' ? parseFloat(amount) : amount
    return formatCurrency(num, {
      currency: companyCurrency,
      locale: companyLocale,
    })
  }

  // Group by type
  const groupedRepos = repositories.reduce<Partial<Record<Repository['type'], Repository[]>>>(
    (acc, repo) => {
      const existing = acc[repo.type]
      if (existing === undefined) {
        acc[repo.type] = [repo]
      } else {
        existing.push(repo)
      }
      return acc
    },
    {}
  )

  // Calculate total balance
  const totalBalance = repositories.reduce(
    (sum, repo) => sum + parseFloat(repo.balance),
    0
  )

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">{t('navigation.repositories', 'Repositories')}</h1>
          <p className="text-gray-500">
            {repositories.length} {repositories.length === 1 ? t('treasury:repositories.singular') : t('treasury:repositories.plural')} | {t('common:fields.total')}: {formatAmount(totalBalance)}
          </p>
        </div>
        {canManageRepositories && (
          <button
            onClick={() => { setShowAddModal(true) }}
            className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
          >
            <Plus className="h-4 w-4" />
            {t('treasury:repositories.add')}
          </button>
        )}
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
      ) : repositories.length === 0 ? (
        <div className="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
          <Vault className="mx-auto h-12 w-12 text-gray-400" />
          <h3 className="mt-2 text-sm font-semibold text-gray-900">{t('treasury:repositories.empty.title')}</h3>
          <p className="mt-1 text-sm text-gray-500">
            {canManageRepositories
              ? t('treasury:repositories.empty.description')
              : t('treasury:repositories.empty.descriptionNoPermission')}
          </p>
          {canManageRepositories && (
            <div className="mt-6">
              <button
                onClick={() => { setShowAddModal(true) }}
                className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
              >
                <Plus className="h-4 w-4" />
                {t('treasury:repositories.add')}
              </button>
            </div>
          )}
        </div>
      ) : (
        <div className="space-y-6">
          {/* Summary Cards */}
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {(['cash_register', 'safe', 'bank_account', 'virtual'] as const).map((type) => {
              const repos = groupedRepos[type] ?? [] as Repository[]
              const Icon = typeIcons[type]
              const typeBalance = repos.reduce((sum, r) => sum + parseFloat(r.balance), 0)
              return (
                <div key={type} className="rounded-lg border border-gray-200 bg-white p-4">
                  <div className="flex items-center gap-3">
                    <div className={`rounded-lg p-2 ${typeColors[type]}`}>
                      <Icon className="h-5 w-5" />
                    </div>
                    <div>
                      <p className="text-sm text-gray-500">{getTypeLabel(type)}</p>
                      <p className="text-lg font-semibold text-gray-900">
                        {formatAmount(typeBalance)}
                      </p>
                    </div>
                  </div>
                  <p className="mt-2 text-xs text-gray-500">
                    {repos.length} {repos.length === 1 ? t('treasury:repositories.account') : t('treasury:repositories.accounts')}
                  </p>
                </div>
              )
            })}
          </div>

          {/* Repository List */}
          <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('treasury:repositories.table.repository')}
                  </th>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('treasury:repositories.table.type')}
                  </th>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('treasury:repositories.table.bankInfo')}
                  </th>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('treasury:repositories.table.status')}
                  </th>
                  <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('treasury:repositories.table.balance')}
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {repositories.map((repo) => {
                  const Icon = typeIcons[repo.type]
                  return (
                    <tr key={repo.id} className="hover:bg-gray-50">
                      <td className="whitespace-nowrap px-6 py-4">
                        <div className="flex items-center gap-3">
                          <div className={`rounded-lg p-2 ${typeColors[repo.type]}`}>
                            <Icon className="h-4 w-4" />
                          </div>
                          <div>
                            <Link
                              to={`/treasury/repositories/${repo.id}`}
                              className="font-medium text-gray-900 hover:text-blue-600"
                            >
                              {repo.name}
                            </Link>
                            <p className="text-sm text-gray-500 font-mono">{repo.code}</p>
                          </div>
                        </div>
                      </td>
                      <td className="whitespace-nowrap px-6 py-4">
                        <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${typeColors[repo.type]}`}>
                          {getTypeLabel(repo.type)}
                        </span>
                      </td>
                      <td className="px-6 py-4 text-sm text-gray-500">
                        {repo.bank_name ? (
                          <div>
                            <p>{repo.bank_name}</p>
                            {repo.account_number && (
                              <p className="font-mono text-xs">{repo.account_number}</p>
                            )}
                          </div>
                        ) : (
                          <span className="text-gray-400">-</span>
                        )}
                      </td>
                      <td className="whitespace-nowrap px-6 py-4">
                        <span
                          className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${
                            repo.is_active
                              ? 'bg-green-100 text-green-800'
                              : 'bg-gray-100 text-gray-800'
                          }`}
                        >
                          {repo.is_active ? t('status.active') : t('status.inactive')}
                        </span>
                      </td>
                      <td className="whitespace-nowrap px-6 py-4 text-end">
                        <span className={`text-sm font-semibold ${
                          parseFloat(repo.balance) >= 0 ? 'text-green-600' : 'text-red-600'
                        }`}>
                          {formatAmount(repo.balance)}
                        </span>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Add Repository Modal */}
      <AddRepositoryModal
        isOpen={showAddModal}
        onClose={() => { setShowAddModal(false) }}
        onSuccess={() => {
          void queryClient.invalidateQueries({ queryKey: tenantScopedKey(['payment-repositories']) })
        }}
      />
    </div>
  )
}
