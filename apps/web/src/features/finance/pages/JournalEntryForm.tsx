import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Plus, Trash2, ArrowLeft, Check, AlertCircle } from 'lucide-react'
import { useAccounts } from '../hooks/useAccounts'
import { useCreateJournalEntry } from '../hooks/useJournalEntryMutations'
import { useCurrency } from '../../../hooks/useCurrency'
import { MoneyInput } from '../../../components/atoms/MoneyInput'

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
        <p className="text-gray-500">{t('common:status.loading')}</p>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-4">
        <Link
          to="/finance/journal-entries"
          className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700"
        >
          <ArrowLeft className="h-4 w-4 me-1" />
          {t('common:back')}
        </Link>
        <h1 className="text-2xl font-bold text-gray-900">
          {t('finance:journalEntry.form.title')}
        </h1>
      </div>

      <div className="bg-white rounded-lg border border-gray-200 p-6 space-y-6">
        {/* Header fields */}
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label
              htmlFor="entry-date"
              className="block text-sm font-medium text-gray-700 mb-1"
            >
              {t('finance:journalEntry.entryDate')}
            </label>
            <input
              type="date"
              id="entry-date"
              value={entryDate}
              onChange={(e) => { setEntryDate(e.target.value); }}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              aria-label={t('finance:journalEntry.entryDate')}
            />
          </div>

          <div>
            <label
              htmlFor="description"
              className="block text-sm font-medium text-gray-700 mb-1"
            >
              {t('finance:journalEntry.description')}
            </label>
            <input
              type="text"
              id="description"
              value={description}
              onChange={(e) => { setDescription(e.target.value); }}
              placeholder={t('finance:journalEntry.form.descriptionPlaceholder')}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              aria-label={t('finance:journalEntry.description')}
            />
          </div>
        </div>

        {/* Journal lines */}
        <div className="space-y-4">
          <h3 className="text-sm font-medium text-gray-900">
            {t('finance:journalEntry.lines')}
          </h3>

          <div className="border rounded-lg overflow-hidden">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase">
                    {t('finance:journalEntry.account')}
                  </th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase w-36">
                    {t('finance:journalEntry.debit')}
                  </th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase w-36">
                    {t('finance:journalEntry.credit')}
                  </th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase">
                    {t('finance:journalEntry.lineDescription')}
                  </th>
                  <th className="px-4 py-3 w-12"></th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {lines.map((line, index) => (
                  <tr key={line.id} data-testid={`journal-line-${index}`}>
                    <td className="px-4 py-3">
                      <select
                        value={line.account_id}
                        onChange={(e) =>
                          { updateLine(line.id, 'account_id', e.target.value); }
                        }
                        className="w-full rounded border border-gray-300 px-2 py-1.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                        aria-label={t('finance:journalEntry.account')}
                      >
                        <option value="">{t('common:select')}</option>
                        {accounts?.map((account) => (
                          <option key={account.id} value={account.id}>
                            {account.code} - {account.name}
                          </option>
                        ))}
                      </select>
                    </td>
                    <td className="px-4 py-3">
                      <MoneyInput
                        currency={currency}
                        value={line.debit}
                        onChange={(v) => { updateLine(line.id, 'debit', v) }}
                        min="0"
                        className="w-full rounded border border-gray-300 px-2 py-1.5 text-sm text-end focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
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
                        className="w-full rounded border border-gray-300 px-2 py-1.5 text-sm text-end focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                        aria-label={t('finance:journalEntry.credit')}
                        placeholder={t('finance:journalEntry.credit')}
                      />
                    </td>
                    <td className="px-4 py-3">
                      <input
                        type="text"
                        value={line.description}
                        onChange={(e) =>
                          { updateLine(line.id, 'description', e.target.value); }
                        }
                        placeholder={t('finance:journalEntry.lineDescription')}
                        className="w-full rounded border border-gray-300 px-2 py-1.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                      />
                    </td>
                    <td className="px-4 py-3">
                      {lines.length > 2 && (
                        <button
                          type="button"
                          onClick={() => { removeLine(line.id); }}
                          className="text-red-600 hover:text-red-800"
                          aria-label={t('common:actions.remove')}
                        >
                          <Trash2 className="h-4 w-4" />
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot className="bg-gray-50">
                <tr>
                  <td className="px-4 py-3 text-sm font-medium text-gray-700">
                    {t('finance:journalEntry.totals')}
                  </td>
                  <td className="px-4 py-3 text-sm font-medium text-gray-900 text-end">
                    <span data-testid="total-debits">
                      {t('finance:journalEntry.totalDebits')}: {formatCurrency(totalDebits)}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-sm font-medium text-gray-900 text-end">
                    <span data-testid="total-credits">
                      {t('finance:journalEntry.totalCredits')}: {formatCurrency(totalCredits)}
                    </span>
                  </td>
                  <td className="px-4 py-3" colSpan={2}>
                    <span
                      className={`inline-flex items-center gap-1 text-sm font-medium ${
                        isBalanced ? 'text-green-600' : 'text-red-600'
                      }`}
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
            </table>
          </div>

          <button
            type="button"
            onClick={addLine}
            className="inline-flex items-center gap-2 text-sm font-medium text-blue-600 hover:text-blue-800"
          >
            <Plus className="h-4 w-4" />
            {t('finance:journalEntry.addLine')}
          </button>
        </div>

        {validationError && (
          <div className="rounded-lg bg-red-50 p-3 text-sm text-red-700 flex items-start gap-2">
            <AlertCircle className="h-4 w-4 mt-0.5 flex-shrink-0" />
            {validationError}
          </div>
        )}

        {createMutation.error && (
          <div className="rounded-lg bg-red-50 p-3 text-sm text-red-700 flex items-start gap-2">
            <AlertCircle className="h-4 w-4 mt-0.5 flex-shrink-0" />
            {t('finance:journalEntry.createError')}
          </div>
        )}

        <div className="flex justify-end gap-3 pt-4 border-t border-gray-200">
          <Link
            to="/finance/journal-entries"
            className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
          >
            {t('common:cancel')}
          </Link>
          <button
            type="button"
            onClick={handleSubmit}
            disabled={createMutation.isPending}
            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
          >
            {createMutation.isPending
              ? t('common:status.saving')
              : t('finance:journalEntry.saveDraft')}
          </button>
        </div>
      </div>
    </div>
  )
}
