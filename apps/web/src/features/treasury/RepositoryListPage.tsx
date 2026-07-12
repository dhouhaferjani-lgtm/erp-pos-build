import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, Vault, Building2, CreditCard, Wallet } from 'lucide-react'
import { api } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { cn } from '../../lib/utils'
import { tokens, textColors, borderColors } from '../../lib/designTokens'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { formatCurrency } from '../../lib/format'
import { usePermissions } from '../../hooks/usePermissions'
import { AddRepositoryModal } from '../../components/organisms'
import { Button, StatusBadge, type StatusTone } from '../../components/atoms'
import {
  DataTable,
  type DataTableColumn,
  EmptyState,
  ListPageLayout,
} from '../../components/molecules'

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

/**
 * Repository types mapped to design-token badge palettes (replacing the bespoke
 * `bg-x-100 text-x-800` map) for both the summary-card icon chips and the
 * type pills. Each value is an existing `tokens.badge.*` class.
 */
const typeBadge: Record<Repository['type'], string> = {
  cash_register: tokens.badge.green,
  safe: tokens.badge.purple,
  bank_account: tokens.badge.blue,
  virtual: tokens.badge.gray,
}

/**
 * Active/inactive toggle routed through the one sanctioned StatusBadge palette.
 */
const activeTone: StatusTone = 'success'
const inactiveTone: StatusTone = 'neutral'

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

  const columns: DataTableColumn<Repository>[] = [
    {
      key: 'repository',
      header: t('treasury:repositories.table.repository'),
      render: (repo) => {
        const Icon = typeIcons[repo.type]
        return (
          <div className="flex items-center gap-3">
            <div className={cn('rounded-lg p-2', typeBadge[repo.type])}>
              <Icon className="h-4 w-4" />
            </div>
            <div>
              <Link
                to={`/treasury/repositories/${repo.id}`}
                className={cn('font-medium', textColors.primary, 'hover:underline')}
              >
                {repo.name}
              </Link>
              <p className={cn('text-sm font-mono', textColors.tertiary)}>{repo.code}</p>
            </div>
          </div>
        )
      },
    },
    {
      key: 'type',
      header: t('treasury:repositories.table.type'),
      render: (repo) => (
        <span className={cn(tokens.badge.base, typeBadge[repo.type])}>
          {getTypeLabel(repo.type)}
        </span>
      ),
    },
    {
      key: 'bankInfo',
      header: t('treasury:repositories.table.bankInfo'),
      render: (repo) =>
        repo.bank_name ? (
          <div className={cn('text-sm', textColors.tertiary)}>
            <p>{repo.bank_name}</p>
            {repo.account_number && (
              <p className="font-mono text-xs">{repo.account_number}</p>
            )}
          </div>
        ) : (
          <span className={textColors.disabled}>-</span>
        ),
    },
    {
      key: 'status',
      header: t('treasury:repositories.table.status'),
      render: (repo) => (
        <StatusBadge tone={repo.is_active ? activeTone : inactiveTone}>
          {repo.is_active ? t('status.active') : t('status.inactive')}
        </StatusBadge>
      ),
    },
    {
      key: 'balance',
      header: t('treasury:repositories.table.balance'),
      numeric: true,
      cellClassName: 'font-semibold',
      render: (repo) => (
        <span className={parseFloat(repo.balance) >= 0 ? textColors.success : textColors.error}>
          {formatAmount(repo.balance)}
        </span>
      ),
    },
  ]

  const addButton = canManageRepositories ? (
    <Button className="gap-2" onClick={() => { setShowAddModal(true) }}>
      <Plus className="h-4 w-4" />
      {t('treasury:repositories.add')}
    </Button>
  ) : undefined

  return (
    <ListPageLayout
      title={t('navigation.repositories', 'Repositories')}
      subtitle={`${String(repositories.length)} ${
        repositories.length === 1
          ? t('treasury:repositories.singular')
          : t('treasury:repositories.plural')
      } | ${t('common:fields.total')}: ${formatAmount(totalBalance)}`}
      {...(addButton !== undefined ? { actions: addButton } : {})}
    >
      {error ? (
        <div className={cn(tokens.alert.base, tokens.alert.error)}>
          {t('errors.loadingFailed', 'Error loading data. Please try again.')}
        </div>
      ) : (
        <div className="space-y-6">
          {/* Summary Cards */}
          {repositories.length > 0 && (
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              {(['cash_register', 'safe', 'bank_account', 'virtual'] as const).map((type) => {
                const repos = groupedRepos[type] ?? ([] as Repository[])
                const Icon = typeIcons[type]
                const typeBalance = repos.reduce((sum, r) => sum + parseFloat(r.balance), 0)
                return (
                  <div key={type} className={cn('rounded-lg border bg-white p-4', borderColors.light)}>
                    <div className="flex items-center gap-3">
                      <div className={cn('rounded-lg p-2', typeBadge[type])}>
                        <Icon className="h-5 w-5" />
                      </div>
                      <div>
                        <p className={cn('text-sm', textColors.tertiary)}>{getTypeLabel(type)}</p>
                        <p className={cn('text-lg font-semibold', textColors.primary)}>
                          {formatAmount(typeBalance)}
                        </p>
                      </div>
                    </div>
                    <p className={cn('mt-2 text-xs', textColors.tertiary)}>
                      {repos.length}{' '}
                      {repos.length === 1
                        ? t('treasury:repositories.account')
                        : t('treasury:repositories.accounts')}
                    </p>
                  </div>
                )
              })}
            </div>
          )}

          {/* Repository List */}
          <DataTable
            columns={columns}
            data={repositories}
            keyExtractor={(repo) => repo.id}
            isLoading={isLoading}
            emptyState={
              <div className="py-6">
                <EmptyState
                  icon={<Vault className={cn('mx-auto h-12 w-12', textColors.disabled)} />}
                  title={t('treasury:repositories.empty.title')}
                  description={
                    canManageRepositories
                      ? t('treasury:repositories.empty.description')
                      : t('treasury:repositories.empty.descriptionNoPermission')
                  }
                />
                {canManageRepositories && (
                  <div className="mt-6 flex justify-center">
                    <Button className="gap-2" onClick={() => { setShowAddModal(true) }}>
                      <Plus className="h-4 w-4" />
                      {t('treasury:repositories.add')}
                    </Button>
                  </div>
                )}
              </div>
            }
          />
        </div>
      )}

      {/* Add Repository Modal */}
      <AddRepositoryModal
        isOpen={showAddModal}
        onClose={() => { setShowAddModal(false) }}
        onSuccess={() => {
          void queryClient.invalidateQueries({ queryKey: ['payment-repositories'] })
        }}
      />
    </ListPageLayout>
  )
}
