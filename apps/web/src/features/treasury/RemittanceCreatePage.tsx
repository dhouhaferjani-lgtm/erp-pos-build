import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useSearchParams } from 'react-router-dom'

import { Button, Checkbox, Input, Select, StatusBadge } from '@/components/atoms'
import { PageHeader } from '@/components/molecules/PageHeader'
import { api } from '@/lib/api'
import { tokens, textColors } from '@/lib/designTokens'
import { formatCurrency } from '@/lib/format'
import { cn } from '@/lib/utils'

import { type Remittance, type RemittanceInstrument, useEligibleInstruments, useRemittanceRepositories } from './hooks/useRemittances'

const EMPTY_INSTRUMENTS: RemittanceInstrument[] = []

export function RemittanceCreatePage() {
  const { t } = useTranslation(['common', 'treasury'])
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const requestedInstrumentId = searchParams.get('instrument_id')
  const requestedKind = searchParams.get('kind')
  const [bankRepositoryId, setBankRepositoryId] = useState('')
  const [kind, setKind] = useState(requestedKind === 'effet' ? 'effet' : 'cheque')
  const [search, setSearch] = useState('')
  const [selectedIds, setSelectedIds] = useState<Set<string>>(() => new Set(requestedInstrumentId ? [requestedInstrumentId] : []))
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const { data: repositories = [], isLoading: repositoriesLoading } = useRemittanceRepositories()
  const { data: eligibleInstruments, isLoading: instrumentsLoading } = useEligibleInstruments(kind)
  const instruments = eligibleInstruments ?? EMPTY_INSTRUMENTS

  useEffect(() => {
    setSelectedIds((current) => {
      const validIds = new Set(instruments.map((instrument) => instrument.id))
      return new Set([...current].filter((id) => validIds.has(id) || id === requestedInstrumentId))
    })
  }, [instruments, requestedInstrumentId])

  const filteredInstruments = useMemo(() => {
    const needle = search.trim().toLocaleLowerCase()
    return needle === '' ? instruments : instruments.filter((instrument) =>
      [instrument.reference, instrument.drawer_name, instrument.bank_name].some((value) => value?.toLocaleLowerCase().includes(needle) === true),
    )
  }, [instruments, search])

  const hasEarlyInstrument = instruments.some((instrument) => selectedIds.has(instrument.id)
    && instrument.maturity_date !== null
    && instrument.maturity_date !== undefined
    && instrument.maturity_date > new Date().toISOString().slice(0, 10))

  function toggleInstrument(id: string) {
    setSelectedIds((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  async function submit() {
    if (!bankRepositoryId || selectedIds.size === 0) return
    setIsSubmitting(true)
    setError(null)
    try {
      const response = await api.post<{ data: Remittance }>('/instrument-remittances', {
        bank_repository_id: bankRepositoryId,
        instrument_kind: kind,
        remittance_type: 'collection',
      })
      const slipId = response.data.data.id
      let lineRequests = Promise.resolve()
      for (const instrumentId of selectedIds) {
        lineRequests = lineRequests.then(async () => {
          await api.post(`/instrument-remittances/${slipId}/lines`, { instrument_id: instrumentId })
        })
      }
      await lineRequests
      await api.post(`/instrument-remittances/${slipId}/remit`)
      void navigate(`/treasury/remittances/${slipId}`)
    } catch {
      setError(t('treasury:remittances.createError'))
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader title={t('treasury:remittances.createTitle')} subtitle={t('treasury:remittances.createSubtitle')} />
      {error ? <div className={cn(tokens.alert.base, tokens.alert.error)}>{error}</div> : null}
      <section className={tokens.card.base}>
        <div className="grid gap-4 md:grid-cols-2">
          <label className={tokens.label.base}>{t('treasury:remittances.bankRepository')}<Select aria-label={t('treasury:remittances.bankRepository')} value={bankRepositoryId} disabled={repositoriesLoading} onChange={(event) => { setBankRepositoryId(event.target.value) }}><option value="">{t('common:common.selectOption')}</option>{repositories.map((repository) => <option key={repository.id} value={repository.id}>{repository.name}</option>)}</Select></label>
          <label className={tokens.label.base}>{t('treasury:remittances.kind')}<Select aria-label={t('treasury:remittances.kind')} value={kind} onChange={(event) => { setKind(event.target.value); setSelectedIds(new Set()) }}><option value="cheque">{t('treasury:instruments.kinds.cheque')}</option><option value="effet">{t('treasury:instruments.kinds.effet')}</option></Select></label>
        </div>
      </section>
      <section className={tokens.card.base}>
        <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
          <div><h2 className={tokens.heading.section}>{t('treasury:remittances.instruments')}</h2><p className={cn('text-sm', textColors.tertiary)}>{t('treasury:remittances.selectedCount', { count: selectedIds.size })}</p></div>
          <Input aria-label={t('common:actions.search')} placeholder={t('common:actions.search')} value={search} onChange={(event) => { setSearch(event.target.value) }} />
        </div>
        {hasEarlyInstrument ? <div className={cn(tokens.alert.base, tokens.alert.warning)}>{t('treasury:remittances.earlyWarning')}</div> : null}
        <div className="mt-4 divide-y">
          {instrumentsLoading ? <p className={textColors.tertiary}>{t('common:status.loading')}</p> : filteredInstruments.map((instrument) => (
            <label key={instrument.id} className="flex cursor-pointer items-center gap-3 py-3">
              <Checkbox checked={selectedIds.has(instrument.id)} onChange={() => { toggleInstrument(instrument.id) }} aria-label={`${instrument.reference} ${instrument.drawer_name ?? ''}`} />
              <span className="min-w-0 flex-1"><span className={cn('block font-medium', textColors.primary)}>{instrument.reference}</span><span className={cn('block text-sm', textColors.tertiary)}>{instrument.drawer_name ?? instrument.bank_name ?? '—'}</span></span>
              {instrument.maturity_date ? <StatusBadge tone={instrument.maturity_date > new Date().toISOString().slice(0, 10) ? 'warning' : 'neutral'}>{new Date(instrument.maturity_date).toLocaleDateString()}</StatusBadge> : null}
              <span className="tabular-nums">{formatCurrency(instrument.amount, { currency: instrument.currency })}</span>
            </label>
          ))}
        </div>
      </section>
      <div className="flex justify-end"><Button onClick={() => { void submit() }} disabled={!bankRepositoryId || selectedIds.size === 0 || isSubmitting}>{t('treasury:remittances.createAndRemit')}</Button></div>
    </div>
  )
}
