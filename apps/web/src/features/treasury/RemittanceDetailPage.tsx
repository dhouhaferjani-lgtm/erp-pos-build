import { useRef, useState } from 'react'
import Big from 'big.js'
import { ArrowLeft, Printer } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'

import { Button, Input, MoneyInput, Select, StatusBadge, Textarea } from '@/components/atoms'
import { PageHeader } from '@/components/molecules/PageHeader'
import { DataTable, type DataTableColumn } from '@/components/molecules/DataTable'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal/Modal'
import { usePermissions } from '@/hooks/usePermissions'
import { api } from '@/lib/api'
import { borderColors, tokens, textColors } from '@/lib/designTokens'
import { formatCurrency } from '@/lib/format'
import { cn } from '@/lib/utils'
import { useAuthStore } from '@/stores/authStore'
import { useQueryClient } from '@tanstack/react-query'

import { BordereauPrintView } from './components/BordereauPrintView'
import { type RemittanceLine, useRemittance, useRemittanceRepositories } from './hooks/useRemittances'

export function RemittanceDetailPage() {
  const { t } = useTranslation(['common', 'treasury'])
  const { id } = useParams<{ id: string }>()
  const remittanceId = id ?? ''
  const queryClient = useQueryClient()
  const userName = useAuthStore((state) => state.user?.name ?? '—')
  const { hasPermission } = usePermissions()
  const { data: slip, isLoading, error } = useRemittance(remittanceId)
  const { data: repositories = [] } = useRemittanceRepositories()
  const [dialog, setDialog] = useState<'clear' | 'bounce' | null>(null)
  const selectedLineRef = useRef<RemittanceLine | null>(null)
  const [feeAmount, setFeeAmount] = useState('0.000')
  const [feeVatAmount, setFeeVatAmount] = useState('0.000')
  const [valueDate, setValueDate] = useState('')
  const [routing, setRouting] = useState('')
  const [reason, setReason] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)

  async function refresh() {
    await queryClient.invalidateQueries({ queryKey: ['remittance', remittanceId] })
    setDialog(null)
    selectedLineRef.current = null
  }

  async function remitDraft() {
    setIsSubmitting(true)
    try {
      await api.post(`/instrument-remittances/${remittanceId}/remit`)
      await refresh()
    } finally {
      setIsSubmitting(false)
    }
  }

  async function submitLineAction() {
    const selectedLine = selectedLineRef.current
    if (!selectedLine || !dialog) return
    setIsSubmitting(true)
    try {
      const endpoint = `/instrument-remittances/${remittanceId}/lines/${selectedLine.id}/${dialog}`
      if (dialog === 'clear') await api.post(endpoint, { fee_amount: feeAmount, fee_vat_amount: feeVatAmount, value_date: valueDate || null })
      else await api.post(endpoint, { fee_amount: feeAmount, fee_vat_amount: feeVatAmount, reason: reason || null, routing })
      await refresh()
    } finally {
      setIsSubmitting(false)
    }
  }

  function openLineDialog(action: 'clear' | 'bounce', line: RemittanceLine) {
    selectedLineRef.current = line
    setDialog(action)
  }

  if (isLoading) return <p className={textColors.tertiary}>{t('common:status.loading')}</p>
  if (error || !slip) return <div className={cn(tokens.alert.base, tokens.alert.error)}>{t('common:errors.loadingFailed')}</div>

  const total = slip.lines.reduce((sum, line) => sum.plus(line.amount), new Big(0)).toFixed(3)
  const currency = slip.lines[0]?.instrument.currency ?? 'TND'
  const detailedRepository = repositories.find((repository) => repository.id === slip.bank_repository_id)
  const printSlip = { ...slip, bank_repository: detailedRepository ?? slip.bank_repository }
  const columns: DataTableColumn<RemittanceLine>[] = [
    { key: 'reference', header: t('treasury:remittances.reference'), render: (line) => <Link className={textColors.brand} to={`/treasury/instruments/${line.instrument_id}`}>{line.instrument.reference}</Link> },
    { key: 'status', header: t('treasury:remittances.status'), render: (line) => <StatusBadge tone={line.line_status === 'bounced' ? 'danger' : line.line_status === 'cleared' ? 'success' : 'warning'}>{t(`treasury:remittances.lineStatuses.${line.line_status}`)}</StatusBadge> },
    { key: 'amount', header: t('treasury:remittances.amount'), numeric: true, render: (line) => formatCurrency(line.amount, { currency: line.instrument.currency }) },
    { key: 'actions', header: t('common:actions.actions'), render: (line) => line.line_status === 'pending' ? <div className="flex gap-2">{hasPermission('instruments.clear') ? <Button size="sm" onClick={() => { openLineDialog('clear', line) }}>{t('treasury:instruments.clear')}</Button> : null}{hasPermission('instruments.bounce') ? <Button size="sm" variant="danger" onClick={() => { openLineDialog('bounce', line) }}>{t('treasury:instruments.bounce')}</Button> : null}</div> : '—' },
  ]

  return <div className="space-y-6">
    <PageHeader title={slip.number} subtitle={slip.bank_repository.name} breadcrumb={<Link className={cn('inline-flex items-center gap-2 text-sm', textColors.tertiary)} to="/treasury/remittances"><ArrowLeft className="h-4 w-4" />{t('common:actions.back')}</Link>} actions={<><StatusBadge tone={slip.status === 'closed' ? 'success' : slip.status === 'draft' ? 'warning' : 'info'}>{t(`treasury:remittances.statuses.${slip.status}`)}</StatusBadge>{slip.status === 'draft' && hasPermission('instruments.remit') ? <Button onClick={() => { void remitDraft() }} disabled={isSubmitting}>{t('treasury:remittances.remit')}</Button> : null}<Button variant="secondary" onClick={() => { window.print() }}><Printer className="h-4 w-4" />{t('treasury:remittances.print')}</Button></>} />
    <section className={tokens.card.base}>
      <div className="overflow-x-auto"><DataTable columns={columns} data={slip.lines} keyExtractor={(line) => line.id} className="min-w-full" /></div>
      <div className={cn('flex items-center justify-between border-t px-4 py-3 font-semibold', borderColors.default)}><span>{slip.lines.length}</span><span className="tabular-nums">{formatCurrency(total, { currency })}</span></div>
    </section>
    <BordereauPrintView slip={printSlip} depositor={userName} />

    <Modal isOpen={dialog === 'clear'} onClose={() => { setDialog(null) }} title={t('treasury:instruments.clearDialog.title')}><ModalContent><div className="grid gap-4 sm:grid-cols-2"><label className={tokens.label.base}>{t('treasury:instruments.feeAmount')}<MoneyInput value={feeAmount} onChange={setFeeAmount} currency={currency} /></label><label className={tokens.label.base}>{t('treasury:instruments.feeVatAmount')}<MoneyInput value={feeVatAmount} onChange={setFeeVatAmount} currency={currency} /></label><label className={tokens.label.base}>{t('treasury:instruments.valueDate')}<Input type="date" value={valueDate} onChange={(event) => { setValueDate(event.target.value) }} /></label></div></ModalContent><ModalFooter><Button variant="secondary" onClick={() => { setDialog(null) }}>{t('common:actions.cancel')}</Button><Button onClick={() => { void submitLineAction() }} disabled={isSubmitting}>{t('treasury:instruments.clear')}</Button></ModalFooter></Modal>
    <Modal isOpen={dialog === 'bounce'} onClose={() => { setDialog(null) }} title={t('treasury:instruments.bounceDialog.title')}><ModalContent><div className="space-y-4"><label className={tokens.label.base}>{t('treasury:instruments.bounceRouting')}<Select required value={routing} onChange={(event) => { setRouting(event.target.value) }}><option value="">{t('treasury:instruments.bounceRoutingPlaceholder')}</option><option value="re_present">{t('treasury:instruments.routings.re_present')}</option><option value="receivable">{t('treasury:instruments.routings.receivable')}</option><option value="doubtful">{t('treasury:instruments.routings.doubtful')}</option></Select></label><div className="grid gap-4 sm:grid-cols-2"><MoneyInput value={feeAmount} onChange={setFeeAmount} currency={currency} /><MoneyInput value={feeVatAmount} onChange={setFeeVatAmount} currency={currency} /></div><Textarea value={reason} onChange={(event) => { setReason(event.target.value) }} placeholder={t('treasury:instruments.bounceReason')} /></div></ModalContent><ModalFooter><Button variant="secondary" onClick={() => { setDialog(null) }}>{t('common:actions.cancel')}</Button><Button variant="danger" onClick={() => { void submitLineAction() }} disabled={!routing || isSubmitting}>{t('treasury:instruments.bounce')}</Button></ModalFooter></Modal>
  </div>
}
