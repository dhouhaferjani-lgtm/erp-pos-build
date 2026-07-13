import { useState } from 'react'
import { CalendarClock, Pause, Pencil, Play, Plus, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'

import { Button, StatusBadge } from '@/components/atoms'
import { PageHeader } from '@/components/molecules/PageHeader'
import { usePermissions } from '@/hooks/usePermissions'
import { useCurrency } from '@/hooks/useCurrency'
import { formatCurrency, formatDate } from '@/lib/format'
import { cn } from '@/lib/utils'
import { semanticColorTokens as colorTokens, tokens } from '@/lib/designTokens'

import { ExpenseRecurrenceForm } from '../components/organisms/ExpenseRecurrenceForm'
import {
  useDeleteExpenseRecurrence,
  useExpenseRecurrences,
  usePauseExpenseRecurrence,
  useResumeExpenseRecurrence,
} from '../hooks/useExpenseRecurrences'
import type { ExpenseRecurrenceTemplate, RecurrenceStatus } from '../types'

const statusTones: Record<RecurrenceStatus, 'success' | 'warning' | 'neutral'> = {
  active: 'success',
  paused: 'warning',
  ended: 'neutral',
}

export function RecurringExpensesPage() {
  const { t } = useTranslation(['expenses', 'common'])
  const { currency } = useCurrency()
  const { hasPermission } = usePermissions()
  const { data: templates = [], isLoading, isError } = useExpenseRecurrences()
  const deleteTemplate = useDeleteExpenseRecurrence()
  const pauseTemplate = usePauseExpenseRecurrence()
  const resumeTemplate = useResumeExpenseRecurrence()
  const [editor, setEditor] = useState<ExpenseRecurrenceTemplate | null | undefined>(undefined)

  const removeTemplate = async (template: ExpenseRecurrenceTemplate) => {
    if (!window.confirm(t('expenses:recurrences.confirmDelete', { name: template.name }))) return
    try {
      await deleteTemplate.mutateAsync(template.id)
    } catch {
      // The mutation hook owns user-facing error reporting.
    }
  }

  return (
    <main className="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
      <PageHeader
        title={t('expenses:recurrences.title')}
        subtitle={t('expenses:recurrences.description')}
        actions={hasPermission('expense-recurrences.create') ? (
          <Button type="button" onClick={() => {
            setEditor(null)
          }}>
            <Plus className="me-2 h-4 w-4" />
            {t('expenses:recurrences.create')}
          </Button>
        ) : undefined}
      />

      <section aria-label={t('expenses:recurrences.timelineLabel')}>
        {isLoading && (
          <div role="status" className={cn(tokens.card.base, colorTokens.text.subtle)}>
            {t('common:loading')}
          </div>
        )}
        {isError && (
          <div role="alert" className={cn(tokens.alert.base, tokens.alert.error)}>
            {t('expenses:recurrences.loadError')}
          </div>
        )}
        {!isLoading && !isError && templates.length === 0 && (
          <div className={cn(tokens.card.base, 'py-12 text-center')}>
            <CalendarClock className={cn('mx-auto h-10 w-10', colorTokens.text.disabled)} />
            <h2 className={cn('mt-3 font-semibold', colorTokens.text.primary)}>
              {t('expenses:recurrences.empty')}
            </h2>
            <p className={cn('mt-1 text-sm', colorTokens.text.subtle)}>
              {t('expenses:recurrences.emptyDescription')}
            </p>
          </div>
        )}
        {templates.length > 0 && (
          <ul className="space-y-3">
            {templates.map((template) => (
              <li
                key={template.id}
                aria-label={template.name}
                className={cn(
                  tokens.card.base,
                  'grid gap-4 border-s-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center',
                  colorTokens.intent.primary.borderFocus,
                )}
              >
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <h2 className={cn('truncate text-base font-semibold', colorTokens.text.primary)}>
                      {template.name}
                    </h2>
                    <StatusBadge tone="info">
                      {t(`expenses:recurrences.frequency.${template.frequency}`)}
                    </StatusBadge>
                    <StatusBadge tone={statusTones[template.status]}>
                      {t(`expenses:recurrences.status.${template.status}`)}
                    </StatusBadge>
                  </div>
                  <div className="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:flex sm:items-end">
                    <div>
                      <span className={cn('block text-xs font-medium uppercase tracking-wide', colorTokens.text.subtle)}>
                        {t('expenses:recurrences.nextDue')}
                      </span>
                      <time
                        dateTime={template.next_due_date}
                        className={cn('mt-0.5 block font-semibold tabular-nums', colorTokens.text.primary)}
                      >
                        {formatDate(template.next_due_date)}
                      </time>
                    </div>
                    <div>
                      <span className={cn('block text-xs font-medium uppercase tracking-wide', colorTokens.text.subtle)}>
                        {t('expenses:recurrences.amount')}
                      </span>
                      <span className={cn('mt-0.5 block font-semibold tabular-nums', colorTokens.text.primary)}>
                        {formatCurrency(template.amount, { currency })}
                      </span>
                    </div>
                  </div>
                </div>

                <div className="flex flex-wrap items-center gap-1 sm:justify-end">
                  {hasPermission('expense-recurrences.update') && (
                    <>
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        aria-label={t('expenses:recurrences.editNamed', { name: template.name })}
                        onClick={() => {
                          setEditor(template)
                        }}
                      >
                        <Pencil className="h-4 w-4" />
                      </Button>
                      {template.status === 'active' && (
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          aria-label={t('expenses:recurrences.pauseNamed', { name: template.name })}
                          onClick={() => {
                            void pauseTemplate.mutateAsync(template.id).catch(() => undefined)
                          }}
                        >
                          <Pause className="h-4 w-4" />
                        </Button>
                      )}
                      {template.status === 'paused' && (
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          aria-label={t('expenses:recurrences.resumeNamed', { name: template.name })}
                          onClick={() => {
                            void resumeTemplate.mutateAsync(template.id).catch(() => undefined)
                          }}
                        >
                          <Play className="h-4 w-4" />
                        </Button>
                      )}
                    </>
                  )}
                  {hasPermission('expense-recurrences.delete') && (
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      aria-label={t('expenses:recurrences.deleteNamed', { name: template.name })}
                      onClick={() => {
                        void removeTemplate(template)
                      }}
                    >
                      <Trash2 className="h-4 w-4" />
                    </Button>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>

      {editor !== undefined && (
        <ExpenseRecurrenceForm
          editing={editor}
          onClose={() => {
            setEditor(undefined)
          }}
        />
      )}
    </main>
  )
}
