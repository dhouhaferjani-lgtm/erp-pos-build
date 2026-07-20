import { useState } from 'react'
import Big from 'big.js'
import { Link2, Search } from 'lucide-react'
import { useTranslation } from 'react-i18next'

import { Button, Input, MoneyInput, Select } from '@/components/atoms'
import { getDecimals } from '@/hooks/useCurrency'
import { semanticColorTokens, textColors, tokens } from '@/lib/designTokens'
import { formatCurrency } from '@/lib/format'
import { cn } from '@/lib/utils'

import type { RepositoryMovementCandidate } from './api'
import { formatAtCurrencyScale } from './status'

interface ManualMatchSearchProps {
  movements: RepositoryMovementCandidate[]
  currency: string
  lineRemaining: string
  search: string
  amount: string
  disabled?: boolean
  onSearch: (value: string) => void
  onAmountChange: (value: string) => void
  onAllocate: (movementId: string, amount: string) => void
}

export function ManualMatchSearch({ movements, currency, lineRemaining, search, amount, disabled = false, onSearch, onAmountChange, onAllocate }: ManualMatchSearchProps) {
  const { t } = useTranslation('treasury')
  const [selectedId, setSelectedId] = useState(() => movements[0]?.id ?? '')
  const effectiveSelectedId = movements.some((movement) => movement.id === selectedId) ? selectedId : ''
  const selected = movements.find((movement) => movement.id === effectiveSelectedId)
  const lineCapacity = new Big(lineRemaining)
  const movementCapacity = new Big(selected?.remaining_allocatable_amount ?? '0')
  const maxAmount = formatAtCurrencyScale((lineCapacity.lte(movementCapacity) ? lineCapacity : movementCapacity).toString(), currency)
  const minimumAmount = formatAtCurrencyScale(new Big(1).div(new Big(10).pow(getDecimals(currency))).toString(), currency)
  const validAmount = amount !== '' && new Big(amount || '0').gt(0) && new Big(amount || '0').lte(maxAmount)

  return (
    <section className="space-y-3" aria-label={t('statements.workspace.manual.title')}>
      <h3 className={cn('flex items-center gap-2 text-sm font-semibold', textColors.primary)}><Search className="h-4 w-4" />{t('statements.workspace.manual.title')}</h3>
      <label className={tokens.label.base}>{t('statements.workspace.manual.search')}<Input value={search} onChange={(event) => { onSearch(event.target.value) }} placeholder={t('statements.workspace.manual.searchPlaceholder')} /></label>
      <label className={tokens.label.base}>{t('statements.workspace.manual.movement')}<Select value={effectiveSelectedId} disabled={movements.length === 0} onChange={(event) => { setSelectedId(event.target.value) }}><option value="">{t('statements.workspace.manual.none')}</option>{movements.map((movement) => <option key={movement.id} value={movement.id}>{t(`statements.workspace.sourceType.${movement.source_type}`)} · {movement.occurred_at.slice(0, 10)} · #{movement.ordinal} · {formatCurrency(movement.remaining_allocatable_amount, { currency })}</option>)}</Select></label>
      {selected ? <div className={cn('grid gap-2 rounded-lg border p-3 text-sm sm:grid-cols-2', semanticColorTokens.surface.pageAlpha)}><span>{t('statements.workspace.manual.original')}: <strong>{formatCurrency(selected.amount, { currency })}</strong></span><span>{t('statements.workspace.manual.available')}: <strong>{formatCurrency(selected.remaining_allocatable_amount, { currency })}</strong></span></div> : null}
      <label className={tokens.label.base}>{t('statements.workspace.manual.amount')}<MoneyInput aria-label={t('statements.workspace.manual.amount')} value={amount} onChange={onAmountChange} currency={currency} min={minimumAmount} max={maxAmount} /></label>
      <Button variant="secondary" disabled={disabled || !selected || !validAmount} onClick={() => { if (selected) onAllocate(selected.id, amount) }}><Link2 className="me-2 h-4 w-4" />{t('statements.workspace.manual.allocate')}</Button>
    </section>
  )
}
