import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import {
  ArrowLeft,
  Building2,
  Calendar,
  CreditCard,
  User,
  MapPin,
  CheckCircle,
  XCircle,
  ArrowRightLeft,
  Landmark,
} from 'lucide-react'
import { api } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { cn } from '../../lib/utils'
import { tokens, textColors } from '../../lib/designTokens'
import { Button } from '../../components/atoms/Button/Button'
import { Select } from '../../components/atoms/Select/Select'
import { Textarea } from '../../components/atoms/Textarea/Textarea'
import {
  StatusBadge,
  type StatusTone,
} from '../../components/atoms/StatusBadge/StatusBadge'
import { statusTone } from '../../components/atoms/StatusBadge/statusTone'
import { PageHeader } from '../../components/molecules/PageHeader/PageHeader'
import {
  Modal,
  ModalContent,
  ModalFooter,
} from '../../components/organisms/Modal/Modal'

interface PaymentMethod {
  id: string
  code: string
  name: string
}

interface Partner {
  id: string
  name: string
}

interface Repository {
  id: string
  code: string
  name: string
  type?: string
}

interface InstrumentDetail {
  id: string
  payment_method_id: string
  payment_method: PaymentMethod | null
  reference: string
  partner_id: string | null
  partner: Partner | null
  drawer_name: string | null
  amount: number
  currency: string
  received_date: string
  maturity_date: string | null
  expiry_date: string | null
  status: 'received' | 'deposited' | 'cleared' | 'bounced' | 'cancelled'
  repository_id: string | null
  repository: Repository | null
  bank_name: string | null
  bank_branch: string | null
  bank_account: string | null
  deposited_at: string | null
  deposited_to_id: string | null
  deposited_to: Repository | null
  cleared_at: string | null
  bounced_at: string | null
  bounce_reason: string | null
  created_at: string
}

interface InstrumentResponse {
  data: InstrumentDetail
}

/**
 * Maps instrument lifecycle statuses to semantic StatusBadge tones, preserving
 * the prior color semantics (received=warning/yellow, deposited=info/blue,
 * cleared=success/green, bounced=danger/red, cancelled=neutral/gray).
 */
const statusToneOverrides: Record<InstrumentDetail['status'], StatusTone> = {
  received: 'warning',
  deposited: 'info',
  cleared: 'success',
  bounced: 'danger',
  cancelled: 'neutral',
}

const statusLabels: Record<InstrumentDetail['status'], string> = {
  received: 'Received',
  deposited: 'Deposited',
  cleared: 'Cleared',
  bounced: 'Bounced',
  cancelled: 'Cancelled',
}

export function InstrumentDetailPage() {
  const { t } = useTranslation(['common', 'treasury'])
  const { id } = useParams<{ id: string }>()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const [showDepositModal, setShowDepositModal] = useState(false)
  const [showTransferModal, setShowTransferModal] = useState(false)
  const [showBounceModal, setShowBounceModal] = useState(false)
  const [bounceReason, setBounceReason] = useState('')
  const [selectedRepositoryId, setSelectedRepositoryId] = useState('')

  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['instrument', id]),
    queryFn: async () => {
      const response = await api.get<InstrumentResponse>(`/payment-instruments/${id}`)
      return response.data
    },
    enabled: Boolean(id) && tenantId !== null && companyId !== null,
  })

  const { data: repositoriesData } = useQuery({
    queryKey: tenantScopedKey(['repositories']),
    queryFn: async () => {
      const response = await api.get<{ data: Repository[] }>('/payment-repositories')
      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  const repositories = repositoriesData?.data ?? []
  const bankAccounts = repositories.filter((r) => r.type === 'bank_account')

  const depositMutation = useMutation({
    mutationFn: async (repositoryId: string) => {
      return api.post(`/payment-instruments/${id}/deposit`, { repository_id: repositoryId })
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey(['instrument', id]) })
      setShowDepositModal(false)
    },
  })

  const clearMutation = useMutation({
    mutationFn: async () => {
      return api.post(`/payment-instruments/${id}/clear`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey(['instrument', id]) })
    },
  })

  const bounceMutation = useMutation({
    mutationFn: async (reason: string) => {
      return api.post(`/payment-instruments/${id}/bounce`, { reason })
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey(['instrument', id]) })
      setShowBounceModal(false)
      setBounceReason('')
    },
  })

  const transferMutation = useMutation({
    mutationFn: async (toRepositoryId: string) => {
      return api.post(`/payment-instruments/${id}/transfer`, { to_repository_id: toRepositoryId })
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: tenantScopedKey(['instrument', id]) })
      setShowTransferModal(false)
      setSelectedRepositoryId('')
    },
  })

  const instrument = data?.data

  const formatCurrency = (amount: number, currency: string) => {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: currency,
    }).format(amount)
  }

  const formatDate = (dateString: string | null) => {
    if (!dateString) return '-'
    return new Date(dateString).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    })
  }

  const formatDateTime = (dateString: string | null) => {
    if (!dateString) return '-'
    return new Date(dateString).toLocaleString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    })
  }

  const canDeposit = instrument?.status === 'received'
  const canTransfer = instrument?.status === 'received'
  const canClear = instrument?.status === 'deposited'
  const canBounce = instrument?.status === 'deposited'

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className={textColors.tertiary}>{t('common:status.loading')}</div>
      </div>
    )
  }

  if (error || !instrument) {
    return (
      <div className={cn(tokens.alert.base, tokens.alert.error)}>
        {t('common:errors.loadingFailed')}
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <PageHeader
        title={instrument.reference}
        subtitle={instrument.payment_method?.name ?? 'Unknown Method'}
        breadcrumb={
          <div className="flex items-center gap-3">
            <Link
              to="/treasury/instruments"
              className={cn(
                'inline-flex items-center gap-2 text-sm',
                textColors.tertiary,
                textColors.hoverPrimary,
              )}
            >
              <ArrowLeft className="h-4 w-4" />
              {t('common:actions.back')}
            </Link>
            <StatusBadge
              tone={statusTone(instrument.status, statusToneOverrides)}
            >
              {statusLabels[instrument.status]}
            </StatusBadge>
          </div>
        }
        actions={
          <>
            {canDeposit && (
              <Button
                variant="primary"
                onClick={() => { setShowDepositModal(true); }}
                className="gap-2"
              >
                <Landmark className="h-4 w-4" />
                {t('treasury:instruments.deposit', 'Deposit')}
              </Button>
            )}
            {canTransfer && (
              <Button
                variant="secondary"
                onClick={() => { setShowTransferModal(true); }}
                className="gap-2"
              >
                <ArrowRightLeft className="h-4 w-4" />
                {t('treasury:instruments.transfer', 'Transfer')}
              </Button>
            )}
            {canClear && (
              <Button
                variant="primary"
                onClick={() => { clearMutation.mutate(); }}
                disabled={clearMutation.isPending}
                className="gap-2"
              >
                <CheckCircle className="h-4 w-4" />
                {t('treasury:instruments.clear', 'Clear')}
              </Button>
            )}
            {canBounce && (
              <Button
                variant="danger"
                onClick={() => { setShowBounceModal(true); }}
                className="gap-2"
              >
                <XCircle className="h-4 w-4" />
                {t('treasury:instruments.bounce', 'Bounce')}
              </Button>
            )}
          </>
        }
      />

      {/* Main Info Card */}
      <div className={tokens.card.base}>
        <h2 className={cn(tokens.heading.section, 'mb-4')}>
          {t('treasury:instruments.details', 'Instrument Details')}
        </h2>
        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <dt className={cn('flex items-center gap-1 text-sm', textColors.tertiary)}>
              <CreditCard className="h-4 w-4" />
              {t('treasury:instruments.amount', 'Amount')}
            </dt>
            <dd className={cn('mt-1 text-xl font-semibold tabular-nums', textColors.primary)}>
              {formatCurrency(instrument.amount, instrument.currency)}
            </dd>
          </div>
          <div>
            <dt className={cn('flex items-center gap-1 text-sm', textColors.tertiary)}>
              <User className="h-4 w-4" />
              {t('treasury:instruments.partner', 'Partner')}
            </dt>
            <dd className={cn('mt-1 text-sm font-medium', textColors.primary)}>
              {instrument.partner?.name ?? instrument.drawer_name ?? '-'}
            </dd>
          </div>
          <div>
            <dt className={cn('flex items-center gap-1 text-sm', textColors.tertiary)}>
              <Calendar className="h-4 w-4" />
              {t('treasury:instruments.receivedDate', 'Received Date')}
            </dt>
            <dd className={cn('mt-1 text-sm font-medium', textColors.primary)}>
              {formatDate(instrument.received_date)}
            </dd>
          </div>
          <div>
            <dt className={cn('flex items-center gap-1 text-sm', textColors.tertiary)}>
              <Calendar className="h-4 w-4" />
              {t('treasury:instruments.maturityDate', 'Maturity Date')}
            </dt>
            <dd className={cn('mt-1 text-sm font-medium', textColors.primary)}>
              {formatDate(instrument.maturity_date)}
            </dd>
          </div>
        </dl>
      </div>

      {/* Location Card */}
      <div className={tokens.card.base}>
        <h2 className={cn(tokens.heading.section, 'mb-4 flex items-center gap-2')}>
          <MapPin className={cn('h-5 w-5', textColors.disabled)} />
          {t('treasury:instruments.location', 'Current Location')}
        </h2>
        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <dt className={cn('text-sm', textColors.tertiary)}>
              {t('treasury:instruments.repository', 'Repository')}
            </dt>
            <dd className={cn('mt-1 text-sm font-medium', textColors.primary)}>
              {instrument.repository?.name ?? '-'}
            </dd>
          </div>
          {instrument.deposited_to && (
            <div>
              <dt className={cn('text-sm', textColors.tertiary)}>
                {t('treasury:instruments.depositedTo', 'Deposited To')}
              </dt>
              <dd className={cn('mt-1 text-sm font-medium', textColors.primary)}>
                {instrument.deposited_to.name}
              </dd>
            </div>
          )}
        </div>
      </div>

      {/* Bank Information Card */}
      {(instrument.bank_name || instrument.bank_branch || instrument.bank_account) && (
        <div className={tokens.card.base}>
          <h2 className={cn(tokens.heading.section, 'mb-4 flex items-center gap-2')}>
            <Building2 className={cn('h-5 w-5', textColors.disabled)} />
            {t('treasury:instruments.bankInfo', 'Bank Information')}
          </h2>
          <dl className="grid gap-4 sm:grid-cols-3">
            {instrument.bank_name && (
              <div>
                <dt className={cn('text-sm', textColors.tertiary)}>
                  {t('treasury:instruments.bankName', 'Bank Name')}
                </dt>
                <dd className={cn('mt-1 text-sm font-medium', textColors.primary)}>
                  {instrument.bank_name}
                </dd>
              </div>
            )}
            {instrument.bank_branch && (
              <div>
                <dt className={cn('text-sm', textColors.tertiary)}>
                  {t('treasury:instruments.bankBranch', 'Branch')}
                </dt>
                <dd className={cn('mt-1 text-sm font-medium', textColors.primary)}>
                  {instrument.bank_branch}
                </dd>
              </div>
            )}
            {instrument.bank_account && (
              <div>
                <dt className={cn('text-sm', textColors.tertiary)}>
                  {t('treasury:instruments.bankAccount', 'Account Number')}
                </dt>
                <dd className={cn('mt-1 font-mono text-sm font-medium', textColors.primary)}>
                  {instrument.bank_account}
                </dd>
              </div>
            )}
          </dl>
        </div>
      )}

      {/* Timeline / History Card */}
      <div className={tokens.card.base}>
        <h2 className={cn(tokens.heading.section, 'mb-4')}>
          {t('treasury:instruments.history', 'History')}
        </h2>
        <ul className="space-y-4">
          <li className="flex items-start gap-3">
            <div className={cn('flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full', tokens.badge.yellow)}>
              <CreditCard className={cn('h-4 w-4', textColors.warningDark)} />
            </div>
            <div>
              <p className={cn('text-sm font-medium', textColors.primary)}>
                {t('treasury:instruments.received', 'Received')}
              </p>
              <p className={cn('text-xs', textColors.tertiary)}>
                {formatDateTime(instrument.received_date)}
              </p>
            </div>
          </li>

          {instrument.deposited_at && (
            <li className="flex items-start gap-3">
              <div className={cn('flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full', tokens.badge.blue)}>
                <Landmark className={cn('h-4 w-4', textColors.brand)} />
              </div>
              <div>
                <p className={cn('text-sm font-medium', textColors.primary)}>
                  {t('treasury:instruments.depositedTo', 'Deposited to')} {instrument.deposited_to?.name}
                </p>
                <p className={cn('text-xs', textColors.tertiary)}>
                  {formatDateTime(instrument.deposited_at)}
                </p>
              </div>
            </li>
          )}

          {instrument.cleared_at && (
            <li className="flex items-start gap-3">
              <div className={cn('flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full', tokens.badge.green)}>
                <CheckCircle className={cn('h-4 w-4', textColors.success)} />
              </div>
              <div>
                <p className={cn('text-sm font-medium', textColors.primary)}>
                  {t('treasury:instruments.cleared', 'Cleared')}
                </p>
                <p className={cn('text-xs', textColors.tertiary)}>
                  {formatDateTime(instrument.cleared_at)}
                </p>
              </div>
            </li>
          )}

          {instrument.bounced_at && (
            <li className="flex items-start gap-3">
              <div className={cn('flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full', tokens.badge.red)}>
                <XCircle className={cn('h-4 w-4', textColors.error)} />
              </div>
              <div>
                <p className={cn('text-sm font-medium', textColors.primary)}>
                  {t('treasury:instruments.bounced', 'Bounced')}
                </p>
                <p className={cn('text-xs', textColors.tertiary)}>
                  {formatDateTime(instrument.bounced_at)}
                </p>
                {instrument.bounce_reason && (
                  <p className={cn('mt-1 text-sm', textColors.error)}>
                    {instrument.bounce_reason}
                  </p>
                )}
              </div>
            </li>
          )}
        </ul>
      </div>

      {/* Deposit Modal */}
      <Modal
        isOpen={showDepositModal}
        onClose={() => { setShowDepositModal(false); }}
        title={t('treasury:instruments.depositToBank', 'Deposit to Bank Account')}
      >
        <ModalContent>
          <div>
            <label htmlFor="repository" className={cn('mb-1 block', tokens.label.base)}>
              {t('treasury:instruments.selectBankAccount', 'Select Bank Account')}
            </label>
            <Select
              id="repository"
              value={selectedRepositoryId}
              onChange={(e) => { setSelectedRepositoryId(e.target.value); }}
            >
              <option value="">
                {t('common:fields.selectOption', 'Select...')}
              </option>
              {bankAccounts.map((repo) => (
                <option key={repo.id} value={repo.id}>
                  {repo.name}
                </option>
              ))}
            </Select>
          </div>
        </ModalContent>
        <ModalFooter>
          <Button
            variant="secondary"
            onClick={() => { setShowDepositModal(false); }}
          >
            {t('common:actions.cancel')}
          </Button>
          <Button
            variant="primary"
            onClick={() => { depositMutation.mutate(selectedRepositoryId); }}
            disabled={!selectedRepositoryId || depositMutation.isPending}
          >
            {depositMutation.isPending
              ? t('common:status.saving')
              : t('treasury:instruments.deposit', 'Deposit')}
          </Button>
        </ModalFooter>
      </Modal>

      {/* Transfer Modal */}
      <Modal
        isOpen={showTransferModal}
        onClose={() => { setShowTransferModal(false); }}
        title={t('treasury:instruments.transferTo', 'Transfer to Repository')}
      >
        <ModalContent>
          <div>
            <label htmlFor="transfer-repository" className={cn('mb-1 block', tokens.label.base)}>
              {t('treasury:instruments.selectRepository', 'Select Repository')}
            </label>
            <Select
              id="transfer-repository"
              value={selectedRepositoryId}
              onChange={(e) => { setSelectedRepositoryId(e.target.value); }}
            >
              <option value="">
                {t('common:fields.selectOption', 'Select...')}
              </option>
              {repositories
                .filter((r) => r.id !== instrument.repository_id)
                .map((repo) => (
                  <option key={repo.id} value={repo.id}>
                    {repo.name}
                  </option>
                ))}
            </Select>
          </div>
        </ModalContent>
        <ModalFooter>
          <Button
            variant="secondary"
            onClick={() => { setShowTransferModal(false); }}
          >
            {t('common:actions.cancel')}
          </Button>
          <Button
            variant="primary"
            onClick={() => { transferMutation.mutate(selectedRepositoryId); }}
            disabled={!selectedRepositoryId || transferMutation.isPending}
          >
            {transferMutation.isPending
              ? t('common:status.saving')
              : t('treasury:instruments.transfer', 'Transfer')}
          </Button>
        </ModalFooter>
      </Modal>

      {/* Bounce Modal */}
      <Modal
        isOpen={showBounceModal}
        onClose={() => {
          setShowBounceModal(false)
          setBounceReason('')
        }}
        title={t('treasury:instruments.markAsBounced', 'Mark as Bounced')}
      >
        <ModalContent>
          <div>
            <label htmlFor="bounce-reason" className={cn('mb-1 block', tokens.label.base)}>
              {t('treasury:instruments.bounceReason', 'Reason (optional)')}
            </label>
            <Textarea
              id="bounce-reason"
              value={bounceReason}
              onChange={(e) => { setBounceReason(e.target.value); }}
              rows={3}
              placeholder={t('treasury:instruments.bounceReasonPlaceholder', 'e.g., Insufficient funds')}
            />
          </div>
        </ModalContent>
        <ModalFooter>
          <Button
            variant="secondary"
            onClick={() => {
              setShowBounceModal(false)
              setBounceReason('')
            }}
          >
            {t('common:actions.cancel')}
          </Button>
          <Button
            variant="danger"
            onClick={() => { bounceMutation.mutate(bounceReason); }}
            disabled={bounceMutation.isPending}
          >
            {bounceMutation.isPending
              ? t('common:status.saving')
              : t('treasury:instruments.bounce', 'Bounce')}
          </Button>
        </ModalFooter>
      </Modal>
    </div>
  )
}
