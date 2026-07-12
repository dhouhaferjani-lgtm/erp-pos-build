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
import { bccomp } from '../../lib/decimal'
import { cn } from '../../lib/utils'
import { tokens, textColors, borderColors } from '../../lib/designTokens'
import { Button } from '../../components/atoms/Button'
import { Select } from '../../components/atoms/Select'
import { StatusBadge, statusTone, type StatusTone } from '../../components/atoms/StatusBadge'
import { EntityLink } from '../../components/molecules/EntityLink'
import { PageHeader } from '../../components/molecules/PageHeader'
import { Tabs, TabsList, TabsTrigger, TabsContent } from '../../components/molecules/Tabs'
import { useAccounts } from '../finance/hooks/useAccounts'
import { usePermissions } from '@/hooks/usePermissions'
import { AdjustBalanceDialog } from './components/AdjustBalanceDialog'
import { RepositoryMovementsTab } from './components/RepositoryMovementsTab'

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
  document_type?: 'invoice' | 'sales_order' | 'purchase_order' | 'supplier_invoice'
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

// Repository type → semantic StatusBadge tone. Repository types are not
// lifecycle statuses, so they are mapped explicitly rather than via the
// built-in statusTone map.
const typeTones: Record<Repository['type'], StatusTone> = {
  cash_register: 'success',
  safe: 'info',
  bank_account: 'info',
  virtual: 'neutral',
}

// Icon container background per repository type (token color classes only).
const typeIconBg: Record<Repository['type'], string> = {
  cash_register: cn(tokens.badge.green),
  safe: cn(tokens.badge.purple),
  bank_account: cn(tokens.badge.blue),
  virtual: cn(tokens.badge.gray),
}

// Transaction status tones. Treasury exposes a `reversed` status the built-in
// map does not cover, so it is supplied as an override.
const statusToneOverrides: Record<string, StatusTone> = {
  reversed: 'neutral',
}

function allocationDocumentType(
  paymentType: Transaction['payment_type'],
  allocationType: Allocation['document_type'],
): 'invoice' | 'sales_order' | 'purchase_order' | 'supplier_invoice' {
  if (allocationType) return allocationType
  if (paymentType === 'supplier_payment') return 'supplier_invoice'
  if (paymentType === 'advance') return 'sales_order'
  return 'invoice'
}

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
          queryKey: ['payment-repository', repository.id],
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
        <dt className={cn(textColors.disabled, 'flex items-center gap-1')}>
          <BookOpen className="h-3.5 w-3.5" />
          {t('treasury:repositories.glAccount')}
        </dt>
        <dd className="flex items-center gap-2">
          <Select
            value={selectedAccountId}
            onChange={(e) => { setSelectedAccountId(e.target.value) }}
            className="mt-0 inline-block w-auto px-2 py-1 text-sm"
          >
            <option value="">{t('treasury:repositories.noGlAccount')}</option>
            {accounts.map((acc) => (
              <option key={acc.id} value={acc.id}>
                {acc.code} - {acc.name}
              </option>
            ))}
          </Select>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => { mutation.mutate(selectedAccountId || null) }}
            disabled={mutation.isPending}
            className={cn('p-1', textColors.success)}
            aria-label={t('common:actions.save')}
          >
            <Check className="h-4 w-4" />
          </Button>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => { setIsEditing(false); setSelectedAccountId(repository.gl_account_id ?? '') }}
            className="p-1"
            aria-label={t('common:actions.cancel')}
          >
            <X className="h-4 w-4" />
          </Button>
        </dd>
      </div>
    )
  }

  return (
    <div className="flex justify-between">
      <dt className={cn(textColors.disabled, 'flex items-center gap-1')}>
        <BookOpen className="h-3.5 w-3.5" />
        {t('treasury:repositories.glAccount')}
      </dt>
      <dd className="flex items-center gap-2">
        {repository.gl_account ? (
          <span className={cn(textColors.primary, 'font-mono text-sm')}>
            {repository.gl_account.code} - {repository.gl_account.name}
          </span>
        ) : (
          <span className={cn(textColors.warningDark, 'text-sm italic')}>
            {t('treasury:repositories.noGlAccountWarning')}
          </span>
        )}
        <Button
          variant="ghost"
          size="sm"
          onClick={() => { setIsEditing(true) }}
          className="p-1"
          aria-label={t('common:actions.edit')}
        >
          <Pencil className="h-3.5 w-3.5" />
        </Button>
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
  const { hasPermission } = usePermissions()
  const [isAdjustBalanceOpen, setIsAdjustBalanceOpen] = useState(false)

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
        <div className={textColors.tertiary}>{t('common:status.loading')}</div>
      </div>
    )
  }

  if (repositoryError || !repository) {
    return (
      <div className="space-y-6">
        <Link
          to="/treasury/repositories"
          className={cn('inline-flex items-center gap-2 text-sm', textColors.tertiary, textColors.hoverSecondary)}
        >
          <ArrowLeft className="h-4 w-4" />
          {t('common:actions.back')}
        </Link>
        <div className={cn(tokens.alert.base, tokens.alert.error)}>
          {t('common:errors.loadingFailed')}
        </div>
      </div>
    )
  }

  const Icon = typeIcons[repository.type]

  return (
    <div className="space-y-6">
      <PageHeader
        title={repository.name}
        breadcrumb={
          <Link
            to="/treasury/repositories"
            className={cn('inline-flex items-center gap-2 text-sm', textColors.tertiary, textColors.hoverSecondary)}
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:navigation.repositories')}
          </Link>
        }
        actions={
          <div className="flex items-center gap-4">
            {hasPermission('treasury.adjust') && (
              <Button variant="secondary" onClick={() => { setIsAdjustBalanceOpen(true) }}>
                {t('treasury:repositories.adjustBalance.action')}
              </Button>
            )}
            <div className={cn('rounded-xl p-3', typeIconBg[repository.type])}>
              <Icon className="h-8 w-8" />
            </div>
            <div className="text-end">
              <p className={cn('text-sm', textColors.tertiary)}>{t('treasury:repositories.currentBalance')}</p>
              <p className={cn('text-3xl font-bold tabular-nums', parseFloat(repository.balance) >= 0 ? textColors.success : textColors.error)}>
                {formatAmount(repository.balance)}
              </p>
            </div>
          </div>
        }
      />
      <AdjustBalanceDialog
        isOpen={isAdjustBalanceOpen}
        onClose={() => { setIsAdjustBalanceOpen(false) }}
        repositoryId={repository.id}
        repositoryCurrency={companyCurrency}
        onSuccess={() => {
          toast.success(t('treasury:repositories.adjustBalance.success'))
          setIsAdjustBalanceOpen(false)
        }}
      />
      <p className={cn('-mt-4 text-sm font-mono', textColors.tertiary)}>{repository.code}</p>

      <Tabs defaultValue="overview">
        <TabsList>
          <TabsTrigger value="overview">{t('treasury:repositories.tabs.overview')}</TabsTrigger>
          <TabsTrigger value="movements">{t('treasury:repositories.tabs.movements')}</TabsTrigger>
        </TabsList>

        <TabsContent value="overview" className="mt-6 space-y-6">
        {/* Repository Info */}
        <div className="grid gap-6 md:grid-cols-2">
          <div className={tokens.card.base}>
            <h2 className={cn(tokens.heading.section, 'mb-4')}>{t('common:details')}</h2>
            <dl className="space-y-3">
              <div className="flex justify-between">
                <dt className={textColors.tertiary}>{t('treasury:repositories.type')}</dt>
                <dd>
                  <StatusBadge tone={typeTones[repository.type]}>
                    {t(`treasury:repositories.types.${repository.type}`)}
                  </StatusBadge>
                </dd>
              </div>
              <div className="flex justify-between">
                <dt className={textColors.tertiary}>{t('common:fields.status')}</dt>
                <dd>
                  <StatusBadge tone={repository.is_active ? 'success' : 'neutral'}>
                    {repository.is_active ? t('common:active') : t('common:inactive')}
                  </StatusBadge>
                </dd>
              </div>
              {repository.bank_name && (
                <div className="flex justify-between">
                  <dt className={textColors.tertiary}>{t('treasury:repositories.bankName')}</dt>
                  <dd className={textColors.primary}>{repository.bank_name}</dd>
                </div>
              )}
              {repository.account_number && (
                <div className="flex justify-between">
                  <dt className={textColors.tertiary}>{t('treasury:repositories.accountNumber')}</dt>
                  <dd className={cn(textColors.primary, 'font-mono')}>{repository.account_number}</dd>
                </div>
              )}
              {repository.iban && (
                <div className="flex justify-between">
                  <dt className={textColors.tertiary}>IBAN</dt>
                  <dd className={cn(textColors.primary, 'font-mono text-sm')}>{repository.iban}</dd>
                </div>
              )}
              {repository.bic && (
                <div className="flex justify-between">
                  <dt className={textColors.tertiary}>BIC/SWIFT</dt>
                  <dd className={cn(textColors.primary, 'font-mono')}>{repository.bic}</dd>
                </div>
              )}
              <GlAccountField repository={repository} />
            </dl>
          </div>

          <div className={tokens.card.base}>
            <h2 className={cn(tokens.heading.section, 'mb-4')}>{t('treasury:repositories.summary')}</h2>
            <dl className="space-y-3">
              <div className="flex justify-between">
                <dt className={textColors.tertiary}>{t('treasury:repositories.totalTransactions')}</dt>
                <dd className={cn(textColors.primary, 'font-semibold tabular-nums')}>{transactions.length}</dd>
              </div>
              <div className="flex justify-between">
                <dt className={textColors.tertiary}>{t('treasury:repositories.totalReceived')}</dt>
                <dd className={cn(textColors.success, 'font-semibold tabular-nums')}>
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
        <div className={cn('rounded-lg border bg-white', borderColors.light)}>
          <div className={cn('border-b px-6 py-4', borderColors.light)}>
            <h2 className={tokens.heading.section}>
              {t('treasury:repositories.transactionHistory')}
            </h2>
          </div>

          {isLoadingTransactions ? (
            <div className="flex items-center justify-center py-12">
              <div className={textColors.tertiary}>{t('common:status.loading')}</div>
            </div>
          ) : transactions.length === 0 ? (
            <div className="px-6 py-12 text-center">
              <p className={textColors.tertiary}>{t('treasury:repositories.noTransactions')}</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className={cn('min-w-full divide-y', borderColors.divideDefault)}>
                <thead className={tokens.table.header}>
                  <tr>
                    <th className={cn('px-6 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                      {t('treasury:payments.title')}
                    </th>
                    <th className={cn('px-6 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                      {t('common:fields.contact')}
                    </th>
                    <th className={cn('px-6 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                      {t('treasury:payments.method')}
                    </th>
                    <th className={cn('px-6 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                      {t('common:fields.date')}
                    </th>
                    <th className={cn('px-6 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                      {t('common:fields.status')}
                    </th>
                    <th className={cn('px-6 py-3 text-start text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                      {t('treasury:repositories.allocatedTo')}
                    </th>
                    <th className={cn('px-6 py-3 text-end text-xs font-medium uppercase tracking-wider', textColors.tertiary)}>
                      {t('treasury:payments.amount')}
                    </th>
                  </tr>
                </thead>
                <tbody className={cn('divide-y bg-white', borderColors.divideDefault)}>
                  {transactions.map((transaction) => {
                    // `transaction.amount` already carries its own sign (a
                    // refund payment is stored negative — PaymentRefundService
                    // — unlike RepositoryMovement.amount, which is unsigned
                    // with a separate `direction`). Mirror the Movements tab's
                    // sign handling: never concatenate a literal '+' onto an
                    // already-negative formatted amount (that produced the
                    // "+-50,000 TND" defect).
                    const isNegative = bccomp(transaction.amount, '0') < 0

                    return (
                    <tr key={transaction.id} className={tokens.table.rowHover}>
                      <td className="whitespace-nowrap px-6 py-4">
                        <EntityLink
                          type="payment"
                          id={transaction.id}
                          label={transaction.payment_number}
                          className="font-medium"
                        />
                      </td>
                      <td className={cn('whitespace-nowrap px-6 py-4 text-sm', textColors.primary)}>
                        {transaction.partner_id ? (
                          <EntityLink
                            type="partner"
                            id={transaction.partner_id}
                            partnerType={transaction.payment_type === 'supplier_payment' ? 'supplier' : 'customer'}
                            label={transaction.partner_name ?? t('common:status.unknown')}
                          />
                        ) : (
                          <span className={textColors.tertiary}>{transaction.partner_name ?? t('common:status.unknown')}</span>
                        )}
                      </td>
                      <td className={cn('whitespace-nowrap px-6 py-4 text-sm', textColors.tertiary)}>
                        {transaction.payment_method_name ?? '-'}
                      </td>
                      <td className={cn('whitespace-nowrap px-6 py-4 text-sm', textColors.tertiary)}>
                        <div className="flex items-center gap-1">
                          <Calendar className="h-3.5 w-3.5" />
                          {new Date(transaction.payment_date).toLocaleDateString()}
                        </div>
                      </td>
                      <td className="whitespace-nowrap px-6 py-4">
                        <StatusBadge tone={statusTone(transaction.status, statusToneOverrides)}>
                          {t(`treasury:payments.statuses.${transaction.status}`, transaction.status)}
                        </StatusBadge>
                      </td>
                      <td className={cn('px-6 py-4 text-sm', textColors.tertiary)}>
                        {transaction.allocations.length > 0 ? (
                          <div className="flex flex-wrap gap-1">
                            {transaction.allocations.slice(0, 2).map((allocation) => (
                              <EntityLink
                                key={allocation.document_id}
                                type="document"
                                id={allocation.document_id}
                                documentType={allocationDocumentType(transaction.payment_type, allocation.document_type)}
                                label={(
                                  <span className="inline-flex items-center gap-1">
                                    {allocation.document_number}
                                    <ExternalLink className="h-3 w-3" />
                                  </span>
                                )}
                                className="inline-flex items-center gap-1"
                              />
                            ))}
                            {transaction.allocations.length > 2 && (
                              <span className={textColors.disabled}>
                                +{transaction.allocations.length - 2} {t('treasury:repositories.moreAllocations')}
                              </span>
                            )}
                          </div>
                        ) : (
                          <span className={cn(textColors.disabled, 'italic')}>
                            {transaction.payment_type === 'advance' ? t('treasury:payments.types.advance') : '-'}
                          </span>
                        )}
                      </td>
                      <td
                        className={cn(
                          'whitespace-nowrap px-6 py-4 text-end text-sm font-medium tabular-nums',
                          isNegative ? textColors.error : textColors.success,
                        )}
                      >
                        {isNegative ? '' : '+'}{formatAmount(transaction.amount)}
                      </td>
                    </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>
        </TabsContent>

        <TabsContent value="movements" className="mt-6">
          <RepositoryMovementsTab repositoryId={repository.id} />
        </TabsContent>
      </Tabs>
    </div>
  )
}
