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

const statusColors: Record<InstrumentDetail['status'], string> = {
  received: 'bg-yellow-100 text-yellow-800',
  deposited: 'bg-blue-100 text-blue-800',
  cleared: 'bg-green-100 text-green-800',
  bounced: 'bg-red-100 text-red-800',
  cancelled: 'bg-gray-100 text-gray-800',
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

  const [showDepositModal, setShowDepositModal] = useState(false)
  const [showTransferModal, setShowTransferModal] = useState(false)
  const [showBounceModal, setShowBounceModal] = useState(false)
  const [bounceReason, setBounceReason] = useState('')
  const [selectedRepositoryId, setSelectedRepositoryId] = useState('')

  const { data, isLoading, error } = useQuery({
    queryKey: ['instrument', id],
    queryFn: async () => {
      const response = await api.get<InstrumentResponse>(`/payment-instruments/${id}`)
      return response.data
    },
    enabled: Boolean(id),
  })

  const { data: repositoriesData } = useQuery({
    queryKey: ['repositories'],
    queryFn: async () => {
      const response = await api.get<{ data: Repository[] }>('/payment-repositories')
      return response.data
    },
  })

  const repositories = repositoriesData?.data ?? []
  const bankAccounts = repositories.filter((r) => r.type === 'bank_account')

  const depositMutation = useMutation({
    mutationFn: async (repositoryId: string) => {
      return api.post(`/payment-instruments/${id}/deposit`, { repository_id: repositoryId })
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['instrument', id] })
      setShowDepositModal(false)
    },
  })

  const clearMutation = useMutation({
    mutationFn: async () => {
      return api.post(`/payment-instruments/${id}/clear`)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['instrument', id] })
    },
  })

  const bounceMutation = useMutation({
    mutationFn: async (reason: string) => {
      return api.post(`/payment-instruments/${id}/bounce`, { reason })
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['instrument', id] })
      setShowBounceModal(false)
      setBounceReason('')
    },
  })

  const transferMutation = useMutation({
    mutationFn: async (toRepositoryId: string) => {
      return api.post(`/payment-instruments/${id}/transfer`, { to_repository_id: toRepositoryId })
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['instrument', id] })
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
        <div className="text-gray-500">{t('common:status.loading')}</div>
      </div>
    )
  }

  if (error || !instrument) {
    return (
      <div className="rounded-lg bg-red-50 p-4 text-red-700">
        {t('common:errors.loadingFailed')}
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Link
            to="/treasury/instruments"
            className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:actions.back')}
          </Link>
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold text-gray-900">{instrument.reference}</h1>
              <span
                className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${statusColors[instrument.status]}`}
              >
                {statusLabels[instrument.status]}
              </span>
            </div>
            <p className="text-gray-500">
              {instrument.payment_method?.name ?? 'Unknown Method'}
            </p>
          </div>
        </div>

        {/* Action Buttons */}
        <div className="flex items-center gap-2">
          {canDeposit && (
            <button
              onClick={() => { setShowDepositModal(true); }}
              className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
            >
              <Landmark className="h-4 w-4" />
              {t('treasury:instruments.deposit', 'Deposit')}
            </button>
          )}
          {canTransfer && (
            <button
              onClick={() => { setShowTransferModal(true); }}
              className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
            >
              <ArrowRightLeft className="h-4 w-4" />
              {t('treasury:instruments.transfer', 'Transfer')}
            </button>
          )}
          {canClear && (
            <button
              onClick={() => { clearMutation.mutate(); }}
              disabled={clearMutation.isPending}
              className="inline-flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
            >
              <CheckCircle className="h-4 w-4" />
              {t('treasury:instruments.clear', 'Clear')}
            </button>
          )}
          {canBounce && (
            <button
              onClick={() => { setShowBounceModal(true); }}
              className="inline-flex items-center gap-2 rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50"
            >
              <XCircle className="h-4 w-4" />
              {t('treasury:instruments.bounce', 'Bounce')}
            </button>
          )}
        </div>
      </div>

      {/* Main Info Card */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <h2 className="text-lg font-semibold text-gray-900 mb-4">
          {t('treasury:instruments.details', 'Instrument Details')}
        </h2>
        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <dt className="text-sm text-gray-500 flex items-center gap-1">
              <CreditCard className="h-4 w-4" />
              {t('treasury:instruments.amount', 'Amount')}
            </dt>
            <dd className="mt-1 text-xl font-semibold text-gray-900">
              {formatCurrency(instrument.amount, instrument.currency)}
            </dd>
          </div>
          <div>
            <dt className="text-sm text-gray-500 flex items-center gap-1">
              <User className="h-4 w-4" />
              {t('treasury:instruments.partner', 'Partner')}
            </dt>
            <dd className="mt-1 text-sm font-medium text-gray-900">
              {instrument.partner?.name ?? instrument.drawer_name ?? '-'}
            </dd>
          </div>
          <div>
            <dt className="text-sm text-gray-500 flex items-center gap-1">
              <Calendar className="h-4 w-4" />
              {t('treasury:instruments.receivedDate', 'Received Date')}
            </dt>
            <dd className="mt-1 text-sm font-medium text-gray-900">
              {formatDate(instrument.received_date)}
            </dd>
          </div>
          <div>
            <dt className="text-sm text-gray-500 flex items-center gap-1">
              <Calendar className="h-4 w-4" />
              {t('treasury:instruments.maturityDate', 'Maturity Date')}
            </dt>
            <dd className="mt-1 text-sm font-medium text-gray-900">
              {formatDate(instrument.maturity_date)}
            </dd>
          </div>
        </dl>
      </div>

      {/* Location Card */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <h2 className="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
          <MapPin className="h-5 w-5 text-gray-400" />
          {t('treasury:instruments.location', 'Current Location')}
        </h2>
        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <dt className="text-sm text-gray-500">
              {t('treasury:instruments.repository', 'Repository')}
            </dt>
            <dd className="mt-1 text-sm font-medium text-gray-900">
              {instrument.repository?.name ?? '-'}
            </dd>
          </div>
          {instrument.deposited_to && (
            <div>
              <dt className="text-sm text-gray-500">
                {t('treasury:instruments.depositedTo', 'Deposited To')}
              </dt>
              <dd className="mt-1 text-sm font-medium text-gray-900">
                {instrument.deposited_to.name}
              </dd>
            </div>
          )}
        </div>
      </div>

      {/* Bank Information Card */}
      {(instrument.bank_name || instrument.bank_branch || instrument.bank_account) && (
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <Building2 className="h-5 w-5 text-gray-400" />
            {t('treasury:instruments.bankInfo', 'Bank Information')}
          </h2>
          <dl className="grid gap-4 sm:grid-cols-3">
            {instrument.bank_name && (
              <div>
                <dt className="text-sm text-gray-500">
                  {t('treasury:instruments.bankName', 'Bank Name')}
                </dt>
                <dd className="mt-1 text-sm font-medium text-gray-900">
                  {instrument.bank_name}
                </dd>
              </div>
            )}
            {instrument.bank_branch && (
              <div>
                <dt className="text-sm text-gray-500">
                  {t('treasury:instruments.bankBranch', 'Branch')}
                </dt>
                <dd className="mt-1 text-sm font-medium text-gray-900">
                  {instrument.bank_branch}
                </dd>
              </div>
            )}
            {instrument.bank_account && (
              <div>
                <dt className="text-sm text-gray-500">
                  {t('treasury:instruments.bankAccount', 'Account Number')}
                </dt>
                <dd className="mt-1 text-sm font-medium text-gray-900 font-mono">
                  {instrument.bank_account}
                </dd>
              </div>
            )}
          </dl>
        </div>
      )}

      {/* Timeline / History Card */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <h2 className="text-lg font-semibold text-gray-900 mb-4">
          {t('treasury:instruments.history', 'History')}
        </h2>
        <ul className="space-y-4">
          <li className="flex items-start gap-3">
            <div className="flex-shrink-0 w-8 h-8 rounded-full bg-yellow-100 flex items-center justify-center">
              <CreditCard className="h-4 w-4 text-yellow-600" />
            </div>
            <div>
              <p className="text-sm font-medium text-gray-900">
                {t('treasury:instruments.received', 'Received')}
              </p>
              <p className="text-xs text-gray-500">
                {formatDateTime(instrument.received_date)}
              </p>
            </div>
          </li>

          {instrument.deposited_at && (
            <li className="flex items-start gap-3">
              <div className="flex-shrink-0 w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center">
                <Landmark className="h-4 w-4 text-blue-600" />
              </div>
              <div>
                <p className="text-sm font-medium text-gray-900">
                  {t('treasury:instruments.depositedTo', 'Deposited to')} {instrument.deposited_to?.name}
                </p>
                <p className="text-xs text-gray-500">
                  {formatDateTime(instrument.deposited_at)}
                </p>
              </div>
            </li>
          )}

          {instrument.cleared_at && (
            <li className="flex items-start gap-3">
              <div className="flex-shrink-0 w-8 h-8 rounded-full bg-green-100 flex items-center justify-center">
                <CheckCircle className="h-4 w-4 text-green-600" />
              </div>
              <div>
                <p className="text-sm font-medium text-gray-900">
                  {t('treasury:instruments.cleared', 'Cleared')}
                </p>
                <p className="text-xs text-gray-500">
                  {formatDateTime(instrument.cleared_at)}
                </p>
              </div>
            </li>
          )}

          {instrument.bounced_at && (
            <li className="flex items-start gap-3">
              <div className="flex-shrink-0 w-8 h-8 rounded-full bg-red-100 flex items-center justify-center">
                <XCircle className="h-4 w-4 text-red-600" />
              </div>
              <div>
                <p className="text-sm font-medium text-gray-900">
                  {t('treasury:instruments.bounced', 'Bounced')}
                </p>
                <p className="text-xs text-gray-500">
                  {formatDateTime(instrument.bounced_at)}
                </p>
                {instrument.bounce_reason && (
                  <p className="mt-1 text-sm text-red-600">
                    {instrument.bounce_reason}
                  </p>
                )}
              </div>
            </li>
          )}
        </ul>
      </div>

      {/* Deposit Modal */}
      {showDepositModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="w-full max-w-md rounded-lg bg-white p-6">
            <h3 className="text-lg font-semibold text-gray-900 mb-4">
              {t('treasury:instruments.depositToBank', 'Deposit to Bank Account')}
            </h3>
            <div className="mb-4">
              <label htmlFor="repository" className="block text-sm font-medium text-gray-700 mb-1">
                {t('treasury:instruments.selectBankAccount', 'Select Bank Account')}
              </label>
              <select
                id="repository"
                value={selectedRepositoryId}
                onChange={(e) => { setSelectedRepositoryId(e.target.value); }}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              >
                <option value="">
                  {t('common:fields.selectOption', 'Select...')}
                </option>
                {bankAccounts.map((repo) => (
                  <option key={repo.id} value={repo.id}>
                    {repo.name}
                  </option>
                ))}
              </select>
            </div>
            <div className="flex justify-end gap-3">
              <button
                onClick={() => { setShowDepositModal(false); }}
                className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
              >
                {t('common:actions.cancel')}
              </button>
              <button
                onClick={() => { depositMutation.mutate(selectedRepositoryId); }}
                disabled={!selectedRepositoryId || depositMutation.isPending}
                className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
              >
                {depositMutation.isPending
                  ? t('common:status.saving')
                  : t('treasury:instruments.deposit', 'Deposit')}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Transfer Modal */}
      {showTransferModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="w-full max-w-md rounded-lg bg-white p-6">
            <h3 className="text-lg font-semibold text-gray-900 mb-4">
              {t('treasury:instruments.transferTo', 'Transfer to Repository')}
            </h3>
            <div className="mb-4">
              <label htmlFor="transfer-repository" className="block text-sm font-medium text-gray-700 mb-1">
                {t('treasury:instruments.selectRepository', 'Select Repository')}
              </label>
              <select
                id="transfer-repository"
                value={selectedRepositoryId}
                onChange={(e) => { setSelectedRepositoryId(e.target.value); }}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
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
              </select>
            </div>
            <div className="flex justify-end gap-3">
              <button
                onClick={() => { setShowTransferModal(false); }}
                className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
              >
                {t('common:actions.cancel')}
              </button>
              <button
                onClick={() => { transferMutation.mutate(selectedRepositoryId); }}
                disabled={!selectedRepositoryId || transferMutation.isPending}
                className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
              >
                {transferMutation.isPending
                  ? t('common:status.saving')
                  : t('treasury:instruments.transfer', 'Transfer')}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Bounce Modal */}
      {showBounceModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="w-full max-w-md rounded-lg bg-white p-6">
            <h3 className="text-lg font-semibold text-gray-900 mb-4">
              {t('treasury:instruments.markAsBounced', 'Mark as Bounced')}
            </h3>
            <div className="mb-4">
              <label htmlFor="bounce-reason" className="block text-sm font-medium text-gray-700 mb-1">
                {t('treasury:instruments.bounceReason', 'Reason (optional)')}
              </label>
              <textarea
                id="bounce-reason"
                value={bounceReason}
                onChange={(e) => { setBounceReason(e.target.value); }}
                rows={3}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                placeholder={t('treasury:instruments.bounceReasonPlaceholder', 'e.g., Insufficient funds')}
              />
            </div>
            <div className="flex justify-end gap-3">
              <button
                onClick={() => {
                  setShowBounceModal(false)
                  setBounceReason('')
                }}
                className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
              >
                {t('common:actions.cancel')}
              </button>
              <button
                onClick={() => { bounceMutation.mutate(bounceReason); }}
                disabled={bounceMutation.isPending}
                className="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
              >
                {bounceMutation.isPending
                  ? t('common:status.saving')
                  : t('treasury:instruments.bounce', 'Bounce')}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
