import { Check, Sparkles } from 'lucide-react'
import { useTranslation } from 'react-i18next'

import { Button, StatusBadge } from '@/components/atoms'
import { semanticColorTokens, textColors } from '@/lib/designTokens'
import { formatCurrency } from '@/lib/format'
import { cn } from '@/lib/utils'

import type { StatementSuggestion } from './api'

interface SuggestionListProps {
  suggestions: StatementSuggestion[]
  currency: string
  disabled?: boolean
  onConfirm: (suggestion: StatementSuggestion) => void
}

export function SuggestionList({ suggestions, currency, disabled = false, onConfirm }: SuggestionListProps) {
  const { t } = useTranslation('treasury')

  return (
    <section className="space-y-3" aria-label={t('statements.workspace.suggestions.title')}>
      <h3 className={cn('flex items-center gap-2 text-sm font-semibold', textColors.primary)}><Sparkles className="h-4 w-4" />{t('statements.workspace.suggestions.title')}</h3>
      {suggestions.length === 0 ? <p className={cn('text-sm', textColors.tertiary)}>{t('statements.workspace.suggestions.empty')}</p> : null}
      {suggestions.map((suggestion) => (
        <article key={`${String(suggestion.tier)}-${suggestion.action_type ?? suggestion.movement_ids.join('-')}`} className={cn('rounded-lg border p-3', semanticColorTokens.border.subtle, semanticColorTokens.surface.pageAlpha)}>
          <div className="flex items-start justify-between gap-3">
            <div className="space-y-1">
              <div className="flex items-center gap-2"><StatusBadge tone={suggestion.tier === 1 ? 'success' : suggestion.tier === 2 ? 'info' : 'pending'}>{t('statements.workspace.suggestions.tier', { tier: String(suggestion.tier) })}</StatusBadge>{suggestion.reference_matched ? <StatusBadge tone="success">{t('statements.workspace.suggestions.reference')}</StatusBadge> : null}</div>
              <p className={cn('text-sm', textColors.secondary)}>{suggestion.reason_code ? t(`statements.workspace.suggestions.reasons.${suggestion.reason_code}`, suggestion.reason_params) : suggestion.reason}</p>
              <p className={cn('font-medium tabular-nums', textColors.primary)}>{formatCurrency(suggestion.amount, { currency })}</p>
            </div>
            <Button size="sm" disabled={disabled} onClick={() => { onConfirm(suggestion) }}><Check className="me-2 h-4 w-4" />{t('statements.workspace.suggestions.confirm')}</Button>
          </div>
        </article>
      ))}
    </section>
  )
}
