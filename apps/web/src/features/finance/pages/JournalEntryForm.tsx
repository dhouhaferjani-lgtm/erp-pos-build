import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Plus, Trash2, ArrowLeft, Check, AlertCircle } from 'lucide-react'
import { useAccounts } from '../hooks/useAccounts'
import { useCreateJournalEntry } from '../hooks/useJournalEntryMutations'
import { useCurrency } from '../../../hooks/useCurrency'
import { cn } from '../../../lib/utils'
import { tokens, textColors, borderColors } from '../../../lib/designTokens'
import { PageHeader } from '../../../components/molecules/PageHeader'
import { StickyFormFooter } from '../../../components/molecules/StickyFormFooter/StickyFormFooter'
import { Button } from '../../../components/atoms/Button/Button'
import { FormField } from '../../../components/atoms/FormField/FormField'
import { Input } from '../../../components/atoms/Input/Input'
import { MoneyInput } from '../../../components/atoms/MoneyInput/MoneyInput'
import { Select } from '../../../components/atoms/Select/Select'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

interface JournalLineForm {
  id: string
  account_id: string
  debit: string
  credit: string
  description: string
}

function createEmptyLine(): JournalLineForm {
  return {
    id: crypto.randomUUID(),
    account_id: '',
    debit: '',
    credit: '',
    description: '',
  }
}

export function JournalEntryForm() {
  const { t } = useTranslation(['finance', 'common'])
  const { currency } = useCurrency()
  const { data: accounts, isLoading: accountsLoading } = useAccounts()
  const createMutation = useCreateJournalEntry()

  const [entryDate, setEntryDate] = useState(new Date().toISOString().split('T')[0])
  const [description, setDescription] = useState('')
  const [lines, setLines] = useState<JournalLineForm[]>([
    createEmptyLine(),
    createEmptyLine(),
  ])
  const [validationError, setValidationError] = useState<string | null>(null)

  const totalDebits = lines.reduce((sum, line) => sum + (parseFloat(line.debit) || 0), 0)
  const totalCredits = lines.reduce((sum, line) => sum + (parseFloat(line.credit) || 0), 0)
  const isBalanced = Math.abs(totalDebits - totalCredits) < 0.01

  const addLine = () => {
    setLines([...lines, createEmptyLine()])
    setValidationError(null)
  }

  const removeLine = (id: string) => {
    if (lines.length > 2) {
      setLines(lines.filter((line) => line.id !== id))
      setValidationError(null)
    }
  }

  const updateLine = (id: string, field: keyof JournalLineForm, value: string) => {
    setLines(
      lines.map((line) => (line.id === id ? { ...line, [field]: value } : line))
    )
    setValidationError(null)
  }

  const handleSubmit = () => {
    // Validation
    if (!entryDate) {
      setValidationError(t('finance:journalEntry.validation.dateRequired'))
      return
    }

    if (!isBalanced) {
      setValidationError(t('finance:journalEntry.validation.unbalanced'))
      return
    }

    const invalidLines = lines.filter((line) => !line.account_id)
    if (invalidLines.length > 0) {
      setValidationError(t('finance:journalEntry.validation.accountRequired'))
      return
    }

    const linesWithBothAmounts = lines.filter(
      (line) =>
        parseFloat(line.debit) > 0 && parseFloat(line.credit) > 0
    )
    if (linesWithBothAmounts.length > 0) {
      setValidationError(t('finance:journalEntry.validation.debitOrCredit'))
      return
    }

    const linesWithNoAmounts = lines.filter(
      (line) => !parseFloat(line.debit) && !parseFloat(line.credit)
    )
    if (linesWithNoAmounts.length > 0) {
      setValidationError(t('finance:journalEntry.validation.amountRequired'))
      return
    }

    const data: import('../api').CreateJournalEntryData = {
      entry_date: entryDate,
      lines: lines.map((line) => {
        const lineData: import('../api').CreateJournalLineData = {
          account_id: line.account_id,
          debit: line.debit || '0',
          credit: line.credit || '0',
        }
        if (line.description) {
          lineData.description = line.description
        }
        return lineData
      }),
    }
    if (description) {
      data.description = description
    }

    createMutation.mutate(data)
  }

  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', {
      style: 'decimal',
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(amount)
  }

  if (accountsLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <p className={textColors.tertiary}>{t('common:status.loading')}</p>
      </div>
    )
  }

  return (
    <div className="flex min-h-full flex-col gap-6">
      <PageHeader
        title={t('finance:journalEntry.form.title')}
        breadcrumb={
          <Link
            to="/finance/journal-entries"
            className={cn(
              'inline-flex items-center gap-2 text-sm',
              textColors.tertiary,
              textColors.hoverPrimary,
            )}
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:back')}
          </Link>
        }
        className="mb-0"
      />

      <form
        onSubmit={(e) => {
          e.preventDefault()
          handleSubmit()
        }}
        className="flex flex-1 flex-col gap-6"
      >
        <div className={tokens.card.base}>
          {/* Header fields */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <FormField
              label={t('finance:journalEntry.entryDate')}
              htmlFor="entry-date"
            >
              <Input
                type="date"
                id="entry-date"
                value={entryDate}
                onChange={(e) => { setEntryDate(e.target.value); }}
                aria-label={t('finance:journalEntry.entryDate')}
              />
            </FormField>

            <FormField
              label={t('finance:journalEntry.description')}
              htmlFor="description"
            >
              <Input
                type="text"
                id="description"
                value={description}
                onChange={(e) => { setDescription(e.target.value); }}
                placeholder={t('finance:journalEntry.form.descriptionPlaceholder')}
                aria-label={t('finance:journalEntry.description')}
              />
            </FormField>
          </div>

          {/* Journal lines */}
          <div className="space-y-4 mt-6">
            <h2 className={cn(tokens.heading.section, 'mb-4')}>
              {t('finance:journalEntry.lines')}
            </h2>

            <div className={cn('border rounded-lg overflow-hidden', borderColors.light)}>
              <DataTable className={cn('min-w-full divide-y', borderColors.divideDefault)}>
                <thead className={tokens.table.header}>
                  <tr>
                    <th className={cn('px-4 py-3 text-start text-xs font-medium uppercase', textColors.tertiary)}>
                      {t('finance:journalEntry.account')}
                    </th>
                    <th className={cn('px-4 py-3 text-start text-xs font-medium uppercase w-36', textColors.tertiary)}>
                      {t('finance:journalEntry.debit')}
                    </th>
                    <th className={cn('px-4 py-3 text-start text-xs font-medium uppercase w-36', textColors.tertiary)}>
                      {t('finance:journalEntry.credit')}
                    </th>
                    <th className={cn('px-4 py-3 text-start text-xs font-medium uppercase', textColors.tertiary)}>
                      {t('finance:journalEntry.lineDescription')}
                    </th>
                    <th className="px-4 py-3 w-12"></th>
                  </tr>
                </thead>
                <tbody className={cn('bg-white divide-y', borderColors.divideDefault)}>
                  {lines.map((line, index) => (
                    <tr key={line.id} data-testid={`journal-line-${index}`}>
                      <td className="px-4 py-3">
                        <Select
                          value={line.account_id}
                          onChange={(e) =>
                            { updateLine(line.id, 'account_id', e.target.value); }
                          }
                          aria-label={t('finance:journalEntry.account')}
                        >
                          <option value="">{t('common:select')}</option>
                          {accounts?.map((account) => (
                            <option key={account.id} value={account.id}>
                              {account.code} - {account.name}
                            </option>
                          ))}
                        </Select>
                      </td>
                      <td className="px-4 py-3">
                        <MoneyInput
                          currency={currency}
                          value={line.debit}
                          onChange={(v) => { updateLine(line.id, 'debit', v) }}
                          min="0"
                          className="text-end"
                          aria-label={t('finance:journalEntry.debit')}
                          placeholder={t('finance:journalEntry.debit')}
                        />
                      </td>
                      <td className="px-4 py-3">
                        <MoneyInput
                          currency={currency}
                          value={line.credit}
                          onChange={(v) => { updateLine(line.id, 'credit', v) }}
                          min="0"
                          className="text-end"
                          aria-label={t('finance:journalEntry.credit')}
                          placeholder={t('finance:journalEntry.credit')}
                        />
                      </td>
                      <td className="px-4 py-3">
                        <Input
                          type="text"
                          value={line.description}
                          onChange={(e) =>
                            { updateLine(line.id, 'description', e.target.value); }
                          }
                          placeholder={t('finance:journalEntry.lineDescription')}
                        />
                      </td>
                      <td className="px-4 py-3">
                        {lines.length > 2 && (
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => { removeLine(line.id); }}
                            className={cn(textColors.error, textColors.hoverError)}
                            aria-label={t('common:actions.remove')}
                          >
                            <Trash2 className="h-4 w-4" />
                          </Button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
                <tfoot className={tokens.table.header}>
                  <tr>
                    <td className={cn('px-4 py-3 text-sm font-medium', textColors.secondary)}>
                      {t('finance:journalEntry.totals')}
                    </td>
                    <td className={cn('px-4 py-3 text-sm font-medium text-end', textColors.primary)}>
                      <span data-testid="total-debits">
                        {t('finance:journalEntry.totalDebits')}: {formatCurrency(totalDebits)}
                      </span>
                    </td>
                    <td className={cn('px-4 py-3 text-sm font-medium text-end', textColors.primary)}>
                      <span data-testid="total-credits">
                        {t('finance:journalEntry.totalCredits')}: {formatCurrency(totalCredits)}
                      </span>
                    </td>
                    <td className="px-4 py-3" colSpan={2}>
                      <span
                        className={cn(
                          'inline-flex items-center gap-1 text-sm font-medium',
                          isBalanced ? textColors.success : textColors.error,
                        )}
                      >
                        {isBalanced ? (
                          <>
                            <Check className="h-4 w-4" />
                            {t('finance:journalEntry.balanced')}
                          </>
                        ) : (
                          <>
                            <AlertCircle className="h-4 w-4" />
                            {t('finance:journalEntry.unbalanced')}
                          </>
                        )}
                      </span>
                    </td>
                  </tr>
                </tfoot>
              </DataTable>
            </div>

            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={addLine}
              className={cn(textColors.brand, 'gap-2')}
            >
              <Plus className="h-4 w-4" />
              {t('finance:journalEntry.addLine')}
            </Button>
          </div>

          {validationError && (
            <div className={cn(tokens.alert.base, tokens.alert.error, 'mt-6 flex items-start gap-2')}>
              <AlertCircle className="h-4 w-4 mt-0.5 flex-shrink-0" />
              {validationError}
            </div>
          )}

          {createMutation.error && (
            <div className={cn(tokens.alert.base, tokens.alert.error, 'mt-6 flex items-start gap-2')}>
              <AlertCircle className="h-4 w-4 mt-0.5 flex-shrink-0" />
              {t('finance:journalEntry.createError')}
            </div>
          )}
        </div>

        <StickyFormFooter>
          <Link to="/finance/journal-entries">
            <Button type="button" variant="secondary">
              {t('common:cancel')}
            </Button>
          </Link>
          <Button
            type="submit"
            variant="primary"
            disabled={createMutation.isPending}
          >
            {createMutation.isPending
              ? t('common:status.saving')
              : t('finance:journalEntry.saveDraft')}
          </Button>
        </StickyFormFooter>
      </form>
    </div>
  )
}
