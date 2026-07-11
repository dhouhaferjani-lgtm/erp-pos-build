import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  ArrowLeft,
  ArrowRightLeft,
  Building2,
  Calendar,
  CheckCircle,
  CreditCard,
  MapPin,
  Send,
  User,
  XCircle,
} from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router-dom'

import { Button, Input, MoneyInput, Select, StatusBadge, Textarea, statusTone, type StatusTone } from '@/components/atoms'
import { PageHeader } from '@/components/molecules/PageHeader'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal/Modal'
import { usePermissions } from '@/hooks/usePermissions'
import { api } from '@/lib/api'
import { tokens, textColors } from '@/lib/designTokens'
import { formatCurrency } from '@/lib/format'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { cn } from '@/lib/utils'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { useInstrumentEvents } from './hooks/useInstrumentEvents'

type InstrumentStatus =
  | 'received'
  | 'in_transit'
  | 'deposited'
  | 'clearing'
  | 'cleared'
  | 'bounced'
  | 'expired'
  | 'cancelled'
  | 'collected'

interface Relation {
  id: string
  code: string
  name: string
  type?: string
}

interface InstrumentDetail {
  id: string
  payment_method_id: string
  payment_method: Relation | null
  reference: string
  partner_id: string | null
  partner: Pick<Relation, 'id' | 'name'> | null
  drawer_name: string | null
  amount: string
  currency: string
  received_date: string
  maturity_date: string | null
  expiry_date: string | null
  status: InstrumentStatus
  kind: 'cheque' | 'effet' | 'other' | null
  direction: 'inbound' | 'outbound'
  needs_details: boolean
  repository_id: string | null
  repository: Relation | null
  bank_name: string | null
  bank_branch: string | null
  bank_account: string | null
  deposited_to: Relation | null
}

interface InstrumentResponse {
  data: InstrumentDetail
}

const statusToneOverrides: Record<InstrumentStatus, StatusTone> = {
  received: 'warning',
  in_transit: 'info',
  deposited: 'info',
  clearing: 'info',
  cleared: 'success',
  bounced: 'danger',
  expired: 'neutral',
  cancelled: 'neutral',
  collected: 'success',
}

function formatDate(value: string | null) {
  return value ? new Date(value).toLocaleDateString() : '—'
}

function formatBankDetails(...values: Array<string | null>) {
  let details = ''
  for (const value of values) {
    if (!value) continue
    details += `${details ? ' · ' : ''}${value}`
  }
  return details
}

export function InstrumentDetailPage() {
  const { t } = useTranslation(['common', 'treasury'])
  const { id } = useParams<{ id: string }>()
  const instrumentId = id ?? ''
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { hasPermission } = usePermissions()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const [dialog, setDialog] = useState<'clear' | 'bounce' | 'transfer' | 'cancel' | null>(null)
  const [selectedRepositoryId, setSelectedRepositoryId] = useState('')
  const [feeAmount, setFeeAmount] = useState('0.000')
  const [feeVatAmount, setFeeVatAmount] = useState('0.000')
  const [valueDate, setValueDate] = useState('')
  const [bounceRouting, setBounceRouting] = useState('')
  const [reason, setReason] = useState('')

  const detailKey = tenantScopedKey(['instrument', instrumentId])
  const eventsKey = tenantScopedKey(['instrument-events', instrumentId])
  const { data, isLoading, error } = useQuery({
    queryKey: detailKey,
    queryFn: async () => {
      const response = await api.get<InstrumentResponse>(`/payment-instruments/${instrumentId}`)
      return response.data
    },
    enabled: Boolean(id) && tenantId !== null && companyId !== null,
  })
  const { data: events = [], isLoading: eventsLoading } = useInstrumentEvents(instrumentId)
  const { data: repositoriesData } = useQuery({
    queryKey: tenantScopedKey(['repositories']),
    queryFn: async () => {
      const response = await api.get<{ data: Relation[] }>('/payment-repositories')
      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  async function refresh() {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: detailKey }),
      queryClient.invalidateQueries({ queryKey: eventsKey }),
    ])
    setDialog(null)
    setReason('')
  }

  const clearMutation = useMutation({
    mutationFn: () => api.post(`/payment-instruments/${instrumentId}/clear`, {
      fee_amount: feeAmount,
      fee_vat_amount: feeVatAmount,
      value_date: valueDate || null,
    }),
    onSuccess: refresh,
  })
  const bounceMutation = useMutation({
    mutationFn: () => api.post(`/payment-instruments/${instrumentId}/bounce`, {
      routing: bounceRouting,
      fee_amount: feeAmount,
      fee_vat_amount: feeVatAmount,
      reason: reason || null,
    }),
    onSuccess: refresh,
  })
  const transferMutation = useMutation({
    mutationFn: () => api.post(`/payment-instruments/${instrumentId}/transfer`, { to_repository_id: selectedRepositoryId }),
    onSuccess: refresh,
  })
  const cancelMutation = useMutation({
    mutationFn: () => api.post(`/payment-instruments/${instrumentId}/cancel`, { reason }),
    onSuccess: refresh,
  })

  const instrument = data?.data
  const repositories = repositoriesData?.data ?? []

  if (isLoading) {
    return <div className={cn('py-12 text-center', textColors.tertiary)}>{t('common:status.loading')}</div>
  }
  if (error || !instrument) {
    return <div className={cn(tokens.alert.base, tokens.alert.error)}>{t('common:errors.loadingFailed')}</div>
  }

  const canRemit = instrument.status === 'received' && hasPermission('instruments.remit')
  const canTransfer = instrument.status === 'received' && hasPermission('instruments.transfer')
  const canCancel = instrument.status === 'received' && hasPermission('instruments.update')
  const canClear = ['deposited', 'clearing'].includes(instrument.status) && hasPermission('instruments.clear')
  const canBounce = ['deposited', 'clearing', 'cleared'].includes(instrument.status) && hasPermission('instruments.bounce')

  return (
    <div className="space-y-6">
      <PageHeader
        title={instrument.reference}
        subtitle={instrument.payment_method?.name ?? t('treasury:instruments.unknownMethod')}
        breadcrumb={
          <div className="flex items-center gap-3">
            <Link to="/treasury/instruments" className={cn('inline-flex items-center gap-2 text-sm', textColors.tertiary, textColors.hoverPrimary)}>
              <ArrowLeft className="h-4 w-4" />
              {t('common:actions.back')}
            </Link>
            <StatusBadge tone={statusTone(instrument.status, statusToneOverrides)}>
              {t(`treasury:instruments.statuses.${instrument.status}`)}
            </StatusBadge>
          </div>
        }
        actions={
          <>
            {canRemit ? (
              <Button className="gap-2" onClick={() => { void navigate(`/treasury/remittances/new?instrument_id=${instrument.id}`) }}>
                <Send className="h-4 w-4" /> {t('treasury:instruments.remit')}
              </Button>
            ) : null}
            {canTransfer ? (
              <Button variant="secondary" className="gap-2" onClick={() => { setDialog('transfer') }}>
                <ArrowRightLeft className="h-4 w-4" /> {t('treasury:instruments.transfer')}
              </Button>
            ) : null}
            {canClear ? (
              <Button className="gap-2" onClick={() => { setDialog('clear') }}>
                <CheckCircle className="h-4 w-4" /> {t('treasury:instruments.clear')}
              </Button>
            ) : null}
            {canBounce ? (
              <Button variant="danger" className="gap-2" onClick={() => { setDialog('bounce') }}>
                <XCircle className="h-4 w-4" /> {t('treasury:instruments.bounce')}
              </Button>
            ) : null}
            {canCancel ? (
              <Button variant="danger" onClick={() => { setDialog('cancel') }}>
                {t('treasury:instruments.cancel')}
              </Button>
            ) : null}
          </>
        }
      />

      <section className={tokens.card.base}>
        <h2 className={cn(tokens.heading.section, 'mb-4')}>{t('treasury:instruments.details')}</h2>
        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <dt className={cn('flex items-center gap-1 text-sm', textColors.tertiary)}><CreditCard className="h-4 w-4" />{t('treasury:instruments.amount')}</dt>
            <dd className={cn('mt-1 text-xl font-semibold tabular-nums', textColors.primary)}>{formatCurrency(instrument.amount, { currency: instrument.currency })}</dd>
          </div>
          <div>
            <dt className={cn('flex items-center gap-1 text-sm', textColors.tertiary)}><User className="h-4 w-4" />{t('treasury:instruments.partner')}</dt>
            <dd className={cn('mt-1 text-sm font-medium', textColors.primary)}>{instrument.partner?.name ?? instrument.drawer_name ?? '—'}</dd>
          </div>
          <div>
            <dt className={cn('flex items-center gap-1 text-sm', textColors.tertiary)}><Calendar className="h-4 w-4" />{t('treasury:instruments.receivedDate')}</dt>
            <dd className={cn('mt-1 text-sm font-medium', textColors.primary)}>{formatDate(instrument.received_date)}</dd>
          </div>
          <div>
            <dt className={cn('flex items-center gap-1 text-sm', textColors.tertiary)}><Calendar className="h-4 w-4" />{t('treasury:instruments.maturityDate')}</dt>
            <dd className={cn('mt-1 text-sm font-medium', textColors.primary)}>{formatDate(instrument.maturity_date)}</dd>
          </div>
        </dl>
      </section>

      <section className={tokens.card.base}>
        <h2 className={cn(tokens.heading.section, 'mb-4 flex items-center gap-2')}><MapPin className={cn('h-5 w-5', textColors.disabled)} />{t('treasury:instruments.location')}</h2>
        <p className={textColors.primary}>{instrument.repository?.name ?? '—'}</p>
      </section>

      {(instrument.bank_name || instrument.bank_branch || instrument.bank_account) ? (
        <section className={tokens.card.base}>
          <h2 className={cn(tokens.heading.section, 'mb-4 flex items-center gap-2')}><Building2 className={cn('h-5 w-5', textColors.disabled)} />{t('treasury:instruments.bankInfo')}</h2>
          <p className={textColors.primary}>{formatBankDetails(instrument.bank_name, instrument.bank_branch, instrument.bank_account)}</p>
        </section>
      ) : null}

      <section className={tokens.card.base}>
        <h2 className={cn(tokens.heading.section, 'mb-4')}>{t('treasury:instruments.history')}</h2>
        {eventsLoading ? <p className={textColors.tertiary}>{t('common:status.loading')}</p> : (
          <ol className="relative space-y-4 border-s ps-5">
            {events.map((event) => (
              <li key={event.id} className="relative">
                <span className={cn('absolute -start-[1.55rem] top-1.5 h-3 w-3 rounded-full', tokens.badge.blue)} />
                <p className={cn('text-sm font-medium', textColors.primary)}>{t(`treasury:instruments.events.${event.event_type}`)}</p>
                {event.from_status && event.to_status ? (
                  <p className={cn('text-xs', textColors.tertiary)}>
                    {t(`treasury:instruments.statuses.${event.from_status}`)} → {t(`treasury:instruments.statuses.${event.to_status}`)}
                  </p>
                ) : null}
                <time className={cn('text-xs', textColors.tertiary)}>{new Date(event.occurred_at).toLocaleString()}</time>
              </li>
            ))}
          </ol>
        )}
      </section>

      <Modal isOpen={dialog === 'clear'} onClose={() => { setDialog(null) }} title={t('treasury:instruments.clearDialog.title')}>
        <ModalContent><div className="grid gap-4 sm:grid-cols-2">
          <label className={tokens.label.base}>{t('treasury:instruments.feeAmount')}<MoneyInput aria-label={t('treasury:instruments.feeAmount')} value={feeAmount} onChange={setFeeAmount} currency={instrument.currency} /></label>
          <label className={tokens.label.base}>{t('treasury:instruments.feeVatAmount')}<MoneyInput aria-label={t('treasury:instruments.feeVatAmount')} value={feeVatAmount} onChange={setFeeVatAmount} currency={instrument.currency} /></label>
          <label className={tokens.label.base}>{t('treasury:instruments.valueDate')}<Input aria-label={t('treasury:instruments.valueDate')} type="date" value={valueDate} onChange={(event) => { setValueDate(event.target.value) }} /></label>
        </div></ModalContent>
        <ModalFooter><Button variant="secondary" onClick={() => { setDialog(null) }}>{t('common:actions.cancel')}</Button><Button onClick={() => { clearMutation.mutate() }} disabled={clearMutation.isPending}>{t('treasury:instruments.clear')}</Button></ModalFooter>
      </Modal>

      <Modal isOpen={dialog === 'bounce'} onClose={() => { setDialog(null) }} title={t('treasury:instruments.bounceDialog.title')}>
        <ModalContent><div className="space-y-4">
          <label className={tokens.label.base}>{t('treasury:instruments.bounceRouting')}<Select aria-label={t('treasury:instruments.bounceRouting')} required value={bounceRouting} onChange={(event) => { setBounceRouting(event.target.value) }}><option value="">{t('treasury:instruments.bounceRoutingPlaceholder')}</option><option value="re_present">{t('treasury:instruments.routings.re_present')}</option><option value="receivable">{t('treasury:instruments.routings.receivable')}</option><option value="doubtful">{t('treasury:instruments.routings.doubtful')}</option></Select></label>
          <div className="grid gap-4 sm:grid-cols-2"><label className={tokens.label.base}>{t('treasury:instruments.feeAmount')}<MoneyInput aria-label={t('treasury:instruments.feeAmount')} value={feeAmount} onChange={setFeeAmount} currency={instrument.currency} /></label><label className={tokens.label.base}>{t('treasury:instruments.feeVatAmount')}<MoneyInput aria-label={t('treasury:instruments.feeVatAmount')} value={feeVatAmount} onChange={setFeeVatAmount} currency={instrument.currency} /></label></div>
          <label className={tokens.label.base}>{t('treasury:instruments.bounceReason')}<Textarea aria-label={t('treasury:instruments.bounceReason')} value={reason} onChange={(event) => { setReason(event.target.value) }} /></label>
        </div></ModalContent>
        <ModalFooter><Button variant="secondary" onClick={() => { setDialog(null) }}>{t('common:actions.cancel')}</Button><Button variant="danger" onClick={() => { bounceMutation.mutate() }} disabled={!bounceRouting || bounceMutation.isPending}>{t('treasury:instruments.bounce')}</Button></ModalFooter>
      </Modal>

      <Modal isOpen={dialog === 'transfer'} onClose={() => { setDialog(null) }} title={t('treasury:instruments.transferTo')}>
        <ModalContent><label className={tokens.label.base}>{t('treasury:instruments.selectRepository')}<Select aria-label={t('treasury:instruments.selectRepository')} value={selectedRepositoryId} onChange={(event) => { setSelectedRepositoryId(event.target.value) }}><option value="">{t('common:fields.selectOption')}</option>{repositories.map((repository) => repository.id === instrument.repository_id ? null : <option key={repository.id} value={repository.id}>{repository.name}</option>)}</Select></label></ModalContent>
        <ModalFooter><Button variant="secondary" onClick={() => { setDialog(null) }}>{t('common:actions.cancel')}</Button><Button onClick={() => { transferMutation.mutate() }} disabled={!selectedRepositoryId || transferMutation.isPending}>{t('treasury:instruments.transfer')}</Button></ModalFooter>
      </Modal>

      <Modal isOpen={dialog === 'cancel'} onClose={() => { setDialog(null) }} title={t('treasury:instruments.cancelDialog.title')}>
        <ModalContent><label className={tokens.label.base}>{t('treasury:instruments.cancelReason')}<Textarea aria-label={t('treasury:instruments.cancelReason')} required value={reason} onChange={(event) => { setReason(event.target.value) }} /></label></ModalContent>
        <ModalFooter><Button variant="secondary" onClick={() => { setDialog(null) }}>{t('common:actions.back')}</Button><Button variant="danger" onClick={() => { cancelMutation.mutate() }} disabled={!reason.trim() || cancelMutation.isPending}>{t('treasury:instruments.cancel')}</Button></ModalFooter>
      </Modal>
    </div>
  )
}
