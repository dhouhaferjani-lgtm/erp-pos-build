/**
 * CreateCreditNoteForm Component
 * Form for creating credit notes from posted invoices
 * Supports both amount-based and line-based credit note creation
 */

import { useCallback, useRef, useState, useMemo } from 'react'
import { useForm, Controller, type Resolver } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useTranslation } from 'react-i18next'
import { AlertCircle, Check } from 'lucide-react'
import { useCurrency } from '@/hooks/useCurrency'
import { useCreateCreditNote } from '../hooks/useCreditNotes'
import type { InvoiceForCreditNote } from '@/types/creditNote'
import { MoneyInput } from '@/components/atoms/MoneyInput'
import { colorClasses } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'
import { formatQuantity } from '@/lib/decimal'
import { getQuantityDecimals } from '@/lib/quantityScale'

// Credit mode enum
type CreditMode = 'amount' | 'line'

const reasonSchema = z.enum([
  'return',
  'price_adjustment',
  'billing_error',
  'damaged_goods',
  'service_issue',
  'other',
])

// Zod validation schema for amount-based mode
const amountBasedSchema = z.object({
  amount: z
    .string()
    // i18n keys are resolved through `t()` at render time. `useTranslation`
    // here declares `sales` as the DEFAULT namespace, so the key must be
    // `creditNotes.…` — the old `sales.creditNotes.…` (dot, not colon) was
    // looked up verbatim inside the sales namespace, missed, and rendered the
    // raw key to the user.
    .min(1, 'creditNotes.form.amountRequired')
    .refine((val) => parseFloat(val) > 0, 'creditNotes.form.amountPositive'),
  reason: reasonSchema,
  notes: z.string().optional(),
})

/**
 * Line-based mode credits selected INVOICE LINES, not a typed amount — the
 * `#amount` input is not even rendered in that mode. Validating it with
 * `amountBasedSchema` (whose `amount` is `min(1)`-required) made the schema
 * permanently unsatisfiable there, so react-hook-form blocked `handleSubmit`
 * client-side and Save silently no-opped with zero network activity
 * (money-campaign W1b MTP-DOC-20). The selected-lines requirement is enforced
 * separately in `onSubmit` and by the Save button's disabled state.
 */
const lineBasedSchema = z.object({
  // Same shape as amountBasedSchema (so both resolvers agree on the form type),
  // minus the non-empty/positive constraints — the value is not collected here.
  amount: z.string(),
  reason: reasonSchema,
  notes: z.string().optional(),
})

type CreditNoteFormData = z.infer<typeof amountBasedSchema>

interface CreateCreditNoteFormProps {
  invoice: InvoiceForCreditNote & {
    lines?: Array<{
      id: string
      product_id: string
      product_code: string
      description: string
      quantity: number
      quantity_decimals?: number | null
      unit_price: string
      tax_rate: string
      total: string
    }>
  }
  onSuccess?: () => void
  onCancel?: () => void
}

/**
 * Form component for creating credit notes
 *
 * Features:
 * - Mode toggle: Amount-based vs Line-based
 * - Amount validation (positive, not exceeding invoice total/remaining)
 * - Line selection with quantity validation
 * - Reason selection (6 predefined reasons)
 * - Notes field
 * - Full refund quick action
 * - Pessimistic form submission
 */
export function CreateCreditNoteForm({
  invoice,
  onSuccess,
  onCancel,
}: CreateCreditNoteFormProps) {
  const { t } = useTranslation(['sales', 'common'])
  const createCreditNote = useCreateCreditNote()
  const { currency, toFixed } = useCurrency()

  // Credit mode state
  const [creditMode, setCreditMode] = useState<CreditMode>('amount')

  // Line selections for line-based mode
  const [selectedLines, setSelectedLines] = useState<Map<string, number>>(new Map())

  // Mode-aware resolver. Read through a ref so the resolver identity stays
  // stable (react-hook-form snapshots `resolver` on the form instance) while
  // still validating against whichever schema the CURRENT mode requires.
  const creditModeRef = useRef<CreditMode>(creditMode)
  creditModeRef.current = creditMode
  const resolver = useCallback<Resolver<CreditNoteFormData>>(
    (values, context, options) =>
      zodResolver(creditModeRef.current === 'amount' ? amountBasedSchema : lineBasedSchema)(
        values,
        context,
        options,
      ),
    [],
  )

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    control,
    formState: { errors },
  } = useForm<CreditNoteFormData>({
    resolver,
    defaultValues: {
      amount: '',
      notes: '',
    },
  })

  const amountValue = watch('amount')
  const reasonValue = watch('reason')

  // Calculate remaining creditable amount
  const remainingCreditable = parseFloat(invoice.balance_due || invoice.total)
  const invoiceTotal = parseFloat(invoice.total)

  // Validate amount against invoice total and remaining creditable
  const validateAmount = (amount: string): string | null => {
    const numAmount = parseFloat(amount)

    if (isNaN(numAmount) || numAmount <= 0) {
      return t('sales:creditNotes.form.amountPositive')
    }

    if (numAmount > invoiceTotal) {
      return t('sales:creditNotes.errors.exceedsInvoiceTotal')
    }

    if (numAmount > remainingCreditable) {
      return t('sales:creditNotes.errors.exceedsRemainingBalance')
    }

    return null
  }

  const customAmountError = amountValue ? validateAmount(amountValue) : null

  // Calculate line-based credit total
  const lineBasedTotal = useMemo(() => {
    if (!invoice.lines || selectedLines.size === 0) return 0

    let total = 0
    selectedLines.forEach((quantity, lineId) => {
      const line = invoice.lines?.find(l => l.id === lineId)
      if (line && quantity > 0) {
        const unitPrice = parseFloat(line.unit_price)
        const taxRate = parseFloat(line.tax_rate)
        const subtotal = unitPrice * quantity
        const tax = subtotal * (taxRate / 100)
        total += subtotal + tax
      }
    })

    return total
  }, [invoice.lines, selectedLines])

  // Toggle line selection
  const handleToggleLine = (lineId: string, maxQuantity: number, _unitPrice: number) => {
    setSelectedLines(prev => {
      const newMap = new Map(prev)
      if (newMap.has(lineId)) {
        newMap.delete(lineId)
      } else {
        newMap.set(lineId, maxQuantity)
      }
      return newMap
    })
  }

  // Update line quantity
  const handleUpdateLineQuantity = (lineId: string, quantity: number, maxQuantity: number) => {
    if (quantity < 0 || quantity > maxQuantity) return

    setSelectedLines(prev => {
      const newMap = new Map(prev)
      if (quantity === 0) {
        newMap.delete(lineId)
      } else {
        newMap.set(lineId, quantity)
      }
      return newMap
    })
  }

  // Handle form submission
  const onSubmit = (data: CreditNoteFormData) => {
    if (creditMode === 'amount') {
      // Amount-based credit note
      const amountError = validateAmount(data.amount)
      if (amountError) {
        return
      }

      createCreditNote.mutate(
        {
          source_invoice_id: invoice.id,
          amount: data.amount,
          reason: data.reason as any,
          ...(data.notes ? { notes: data.notes } : {}),
        },
        {
          onSuccess: () => {
            onSuccess?.()
          },
        }
      )
    } else {
      // Line-based credit note
      if (selectedLines.size === 0) {
        return
      }

      // Build lines array for API
      const lines = Array.from(selectedLines.entries()).map(([lineId, quantity]) => ({
        line_id: lineId,
        quantity,
      }))

      createCreditNote.mutate(
        {
          source_invoice_id: invoice.id,
          reason: data.reason as any,
          lines,
          ...(data.notes ? { notes: data.notes } : {}),
        } as any,
        {
          onSuccess: () => {
            onSuccess?.()
          },
        }
      )
    }
  }

  // Handle full refund button
  const handleFullRefund = () => {
    setValue('amount', toFixed(remainingCreditable))
  }

  const isSubmitting = createCreditNote.isPending

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
      {/* Header */}
      <div className={`border-b ${colorClasses.borderGray200} pb-4`}>
        <h2 className={`text-lg font-medium ${colorClasses.textGray900}`}>
          {t('sales:creditNotes.createFromInvoice')}
        </h2>
        <div className={`mt-2 text-sm ${colorClasses.textGray600}`}>
          <p>
            <span className="font-medium">{t('sales:documents.number')}:</span> {invoice.document_number}
          </p>
          <p>
            <span className="font-medium">{t('sales:documents.total')}:</span>{' '}
            {toFixed(invoiceTotal)}
          </p>
          <p className={`${colorClasses.textBlue700}`}>
            {t('sales:creditNotes.form.remainingCreditable', {
              amount: toFixed(remainingCreditable),
            })}
          </p>
        </div>
      </div>

      {/* Credit Mode Selector */}
      {invoice.lines && invoice.lines.length > 0 && (
        <div>
          <label className={`block text-sm font-medium ${colorClasses.textGray700} mb-2`}>
            {t('sales:creditNotes.form.mode')}
          </label>
          <div className="flex gap-4">
            <button
              type="button"
              onClick={() => { setCreditMode('amount'); }}
              className={`flex-1 rounded-lg border px-4 py-3 text-sm font-medium transition-colors ${
                creditMode === 'amount'
                  ? `${colorClasses.borderBlue500} ${colorClasses.bgBlue50} ${colorClasses.textBlue700}`
                  : `${colorClasses.borderGray300} bg-white ${colorClasses.textGray700} ${colorClasses.hoverBgGray50}`
              }`}
              disabled={isSubmitting}
            >
              <div className="flex items-center justify-center gap-2">
                {creditMode === 'amount' && <Check className="h-4 w-4" />}
                {t('sales:creditNotes.form.amountBased')}
              </div>
            </button>
            <button
              type="button"
              onClick={() => { setCreditMode('line'); }}
              className={`flex-1 rounded-lg border px-4 py-3 text-sm font-medium transition-colors ${
                creditMode === 'line'
                  ? `${colorClasses.borderBlue500} ${colorClasses.bgBlue50} ${colorClasses.textBlue700}`
                  : `${colorClasses.borderGray300} bg-white ${colorClasses.textGray700} ${colorClasses.hoverBgGray50}`
              }`}
              disabled={isSubmitting}
            >
              <div className="flex items-center justify-center gap-2">
                {creditMode === 'line' && <Check className="h-4 w-4" />}
                {t('sales:creditNotes.form.lineBased')}
              </div>
            </button>
          </div>
        </div>
      )}

      {/* Amount-Based Mode */}
      {creditMode === 'amount' && (
        <div>
          <label htmlFor="amount" className={`block text-sm font-medium ${colorClasses.textGray700}`}>
            {t('sales:creditNotes.amount')}
          </label>
          <div className="mt-1 flex gap-2">
            <Controller
              name="amount"
              control={control}
              render={({ field }) => (
                <MoneyInput
                  id="amount"
                  currency={currency}
                  value={field.value ?? ''}
                  onChange={field.onChange}
                  onBlur={field.onBlur}
                  ref={field.ref}
                  error={errors.amount != null || customAmountError != null}
                  disabled={isSubmitting}
                  className={`flex-1 rounded-md border ${
                    errors.amount || customAmountError
                      ? `${colorClasses.borderRed300} ${colorClasses.focusBorderRed500} ${colorClasses.focusRingRed500}`
                      : `${colorClasses.borderGray300} ${colorClasses.focusBorderBlue500} ${colorClasses.focusRingBlue500}`
                  } px-3 py-2 text-sm`}
                />
              )}
            />
            <button
              type="button"
              onClick={handleFullRefund}
              className={`rounded-md border ${colorClasses.borderGray300} bg-white px-3 py-2 text-sm font-medium ${colorClasses.textGray700} ${colorClasses.hoverBgGray50}`}
              disabled={isSubmitting}
            >
              {t('sales:creditNotes.messages.fullRefund', 'Full Refund')}
            </button>
          </div>
          {(errors.amount || customAmountError) && (
            <p className={`mt-1 text-sm ${colorClasses.textRed600}`}>
              {errors.amount ? t(errors.amount.message!) : customAmountError}
            </p>
          )}
          <p className={`mt-1 text-xs ${colorClasses.textGray500}`}>
            {t('sales:creditNotes.form.maxAmount', { amount: toFixed(remainingCreditable) })}
          </p>
        </div>
      )}

      {/* Line-Based Mode */}
      {creditMode === 'line' && invoice.lines && (
        <div>
          <label className={`block text-sm font-medium ${colorClasses.textGray700} mb-2`}>
            {t('sales:creditNotes.form.selectLines')}
          </label>

          <div className={`rounded-md border ${colorClasses.borderGray300} overflow-hidden`}>
            <DataTable className={`min-w-full divide-y ${colorClasses.divideGray200}`}>
              <thead className={`${colorClasses.bgGray50}`}>
                <tr>
                  <th className="w-10 px-3 py-2"></th>
                  <th className={`px-3 py-2 text-start text-xs font-medium ${colorClasses.textGray500}`}>
                    {t('sales:lineItems.item')}
                  </th>
                  <th className={`px-3 py-2 text-end text-xs font-medium ${colorClasses.textGray500}`}>
                    {t('sales:lineItems.quantity')}
                  </th>
                  <th className={`px-3 py-2 text-end text-xs font-medium ${colorClasses.textGray500}`}>
                    {t('sales:creditNotes.form.creditQuantity')}
                  </th>
                  <th className={`px-3 py-2 text-end text-xs font-medium ${colorClasses.textGray500}`}>
                    {t('sales:lineItems.unitPrice')}
                  </th>
                  <th className={`px-3 py-2 text-end text-xs font-medium ${colorClasses.textGray500}`}>
                    {t('sales:lineItems.total')}
                  </th>
                </tr>
              </thead>
              <tbody className={`divide-y ${colorClasses.divideGray200} bg-white`}>
                {invoice.lines.map((line) => {
                  const isSelected = selectedLines.has(line.id)
                  const creditQty = selectedLines.get(line.id) || line.quantity
                  const unitPrice = parseFloat(line.unit_price)
                  const taxRate = parseFloat(line.tax_rate)
                  const subtotal = unitPrice * creditQty
                  const tax = subtotal * (taxRate / 100)
                  const total = subtotal + tax

                  return (
                    <tr key={line.id} className={isSelected ? `${colorClasses.bgBlue50}` : ''}>
                      <td className="px-3 py-2 text-center">
                        <input
                          type="checkbox"
                          checked={isSelected}
                          onChange={() => { handleToggleLine(line.id, line.quantity, unitPrice); }}
                          className={`h-4 w-4 rounded ${colorClasses.borderGray300} ${colorClasses.textBlue600} ${colorClasses.focusRingBlue500}`}
                          disabled={isSubmitting}
                        />
                      </td>
                      <td className="px-3 py-2 text-sm">
                        <div className={`font-medium ${colorClasses.textGray900}`}>{line.product_code}</div>
                        <div className={`${colorClasses.textGray500}`}>{line.description}</div>
                      </td>
                      <td className={`px-3 py-2 text-end text-sm ${colorClasses.textGray900}`}>
                        {formatQuantity(line.quantity, getQuantityDecimals(line))}
                      </td>
                      <td className="px-3 py-2 text-end">
                        {isSelected ? (
                          <input
                            type="number"
                            min="1"
                            max={line.quantity}
                            value={creditQty}
                            onChange={(e) => { handleUpdateLineQuantity(
                              line.id,
                              parseInt(e.target.value) || 0,
                              line.quantity
                            ); }}
                            className={`w-20 rounded ${colorClasses.borderGray300} px-2 py-1 text-sm text-end`}
                            disabled={isSubmitting}
                          />
                        ) : (
                          <span className={`text-sm ${colorClasses.textGray400}`}>-</span>
                        )}
                      </td>
                      <td className={`px-3 py-2 text-end text-sm ${colorClasses.textGray900}`}>
                        {toFixed(unitPrice)}
                      </td>
                      <td className={`px-3 py-2 text-end text-sm font-medium ${colorClasses.textGray900}`}>
                        {isSelected ? toFixed(total) : '-'}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
              <tfoot className={`${colorClasses.bgGray50}`}>
                <tr>
                  <td colSpan={5} className={`px-3 py-2 text-end text-sm font-medium ${colorClasses.textGray900}`}>
                    {t('sales:documents.total')}
                  </td>
                  <td className={`px-3 py-2 text-end text-sm font-bold ${colorClasses.textGray900}`}>
                    {toFixed(lineBasedTotal)}
                  </td>
                </tr>
              </tfoot>
            </DataTable>
          </div>

          {selectedLines.size === 0 && (
            <p className={`mt-1 text-sm ${colorClasses.textRed600}`}>
              {t('sales:creditNotes.form.noLinesSelected')}
            </p>
          )}
        </div>
      )}

      {/* Reason Field */}
      <div>
        <label htmlFor="reason" className={`block text-sm font-medium ${colorClasses.textGray700}`}>
          {t('sales:creditNotes.reason.title')}
        </label>
        <select
          {...register('reason')}
          id="reason"
          className={`mt-1 block w-full rounded-md border ${
            errors.reason
              ? `${colorClasses.borderRed300} ${colorClasses.focusBorderRed500} ${colorClasses.focusRingRed500}`
              : `${colorClasses.borderGray300} ${colorClasses.focusBorderBlue500} ${colorClasses.focusRingBlue500}`
          } px-3 py-2 text-sm`}
          disabled={isSubmitting}
        >
          <option value="">{t('common:select', 'Select...')}</option>
          <option value="return">{t('sales:creditNotes.reason.return')}</option>
          <option value="price_adjustment">{t('sales:creditNotes.reason.priceAdjustment')}</option>
          <option value="billing_error">{t('sales:creditNotes.reason.billingError')}</option>
          <option value="damaged_goods">{t('sales:creditNotes.reason.damagedGoods')}</option>
          <option value="service_issue">{t('sales:creditNotes.reason.serviceIssue')}</option>
          <option value="other">{t('sales:creditNotes.reason.other')}</option>
        </select>
        {errors.reason && (
          <p className={`mt-1 text-sm ${colorClasses.textRed600}`}>{t(errors.reason.message!)}</p>
        )}
      </div>

      {/* Notes Field */}
      <div>
        <label htmlFor="notes" className={`block text-sm font-medium ${colorClasses.textGray700}`}>
          {t('sales:creditNotes.notes')}
        </label>
        <textarea
          {...register('notes')}
          id="notes"
          rows={3}
          className={`mt-1 block w-full rounded-md border ${colorClasses.borderGray300} px-3 py-2 text-sm ${colorClasses.focusBorderBlue500} ${colorClasses.focusRingBlue500}`}
          placeholder={t('sales:creditNotes.notesPlaceholder')}
          disabled={isSubmitting}
        />
      </div>

      {/* Error Display */}
      {createCreditNote.isError && (
        <div className={`rounded-md ${colorClasses.bgRed50} p-4`}>
          <div className="flex">
            <AlertCircle className={`h-5 w-5 ${colorClasses.textRed400}`} />
            <div className="ms-3">
              <p className={`text-sm ${colorClasses.textRed800}`}>
                {createCreditNote.error?.message ||
                  t('sales:creditNotes.messages.createFailed')}
              </p>
            </div>
          </div>
        </div>
      )}

      {/* Action Buttons */}
      <div className="flex justify-end gap-3">
        <button
          type="button"
          onClick={onCancel}
          className={`rounded-md border ${colorClasses.borderGray300} bg-white px-4 py-2 text-sm font-medium ${colorClasses.textGray700} ${colorClasses.hoverBgGray50}`}
          disabled={isSubmitting}
        >
          {t('common:actions.cancel')}
        </button>
        <button
          type="submit"
          className={`rounded-md ${colorClasses.bgBlue600} px-4 py-2 text-sm font-medium text-white ${colorClasses.hoverBgBlue700} ${colorClasses.disabledBgGray400}`}
          disabled={
            isSubmitting ||
            (creditMode === 'amount' && !!customAmountError) ||
            (creditMode === 'line' && selectedLines.size === 0) ||
            !reasonValue
          }
        >
          {isSubmitting ? t('common:status.saving', 'Saving...') : t('common:actions.save')}
        </button>
      </div>
    </form>
  )
}
