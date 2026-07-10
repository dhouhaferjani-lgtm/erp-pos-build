import { type FormEvent, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'

import { MoneyInput, QuantityInput } from '@/components/atoms'
import { ProductLineSelect } from '@/components/molecules/line-items'
import { PartnerPicker } from '@/components/molecules/pickers/PartnerPicker'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { useCompanyStore } from '@/stores/companyStore'

import { useCreateQuoteRequestGroup } from './api'
import { mutationErrorMessage } from './errorMessage'
import type { QuoteRequestLinePayload } from './types'

interface SupplierFormState {
  key: string
  supplierId: string
}

interface LineFormState {
  key: string
  productId: string
  variantId: string
  description: string
  quantity: string
  unitPrice: string
}

let rowKeyCounter = 0

function nextRowKey(prefix: string): string {
  rowKeyCounter += 1
  return `${prefix}-${String(rowKeyCounter)}`
}

const emptySupplier = (): SupplierFormState => ({
  key: nextRowKey('supplier'),
  supplierId: '',
})

const emptyLine = (): LineFormState => ({
  key: nextRowKey('line'),
  productId: '',
  variantId: '',
  description: '',
  quantity: '1.0000',
  unitPrice: '0.000',
})

function toPayloadLine(line: LineFormState): QuoteRequestLinePayload {
  return {
    product_id: line.productId,
    variant_id: line.variantId.trim() === '' ? null : line.variantId.trim(),
    description: line.description.trim() === '' ? null : line.description.trim(),
    quantity: line.quantity,
    unit_price: line.unitPrice,
  }
}

export function QuoteRequestCreatePage() {
  const { t } = useTranslation(['common', 'purchases'])
  const navigate = useNavigate()
  const createGroup = useCreateQuoteRequestGroup()
  const activeCurrency = useCompanyStore((state) => state.getCurrentCompany()?.currency ?? '')

  const [suppliers, setSuppliers] = useState<SupplierFormState[]>([emptySupplier()])
  const [lines, setLines] = useState<LineFormState[]>([emptyLine()])
  const [validityDate, setValidityDate] = useState('')
  const [notes, setNotes] = useState('')

  function updateSupplier(index: number, value: string) {
    setSuppliers((current) => current.map((supplier, i) => (i === index ? { ...supplier, supplierId: value } : supplier)))
  }

  function addSupplier() {
    setSuppliers((current) => (current.length >= 10 ? current : [...current, emptySupplier()]))
  }

  function removeSupplier(index: number) {
    setSuppliers((current) => current.filter((_, i) => i !== index))
  }

  function updateLine(index: number, patch: Partial<LineFormState>) {
    setLines((current) => current.map((line, i) => (i === index ? { ...line, ...patch } : line)))
  }

  function addLine() {
    setLines((current) => [...current, emptyLine()])
  }

  function removeLine(index: number) {
    setLines((current) => current.filter((_, i) => i !== index))
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const partnerIds = suppliers.map((supplier) => supplier.supplierId).filter((supplierId) => supplierId !== '')
    try {
      const payload = await createGroup.mutateAsync({
        partner_ids: partnerIds,
        lines: lines.map(toPayloadLine),
        validity_date: validityDate === '' ? null : validityDate,
        notes: notes.trim() === '' ? null : notes.trim(),
      })

      if (payload.siblings.length === 1) {
        void navigate(`/purchases/quote-requests/${payload.siblings[0].id}`)
        return
      }

      void navigate(`/purchases/quote-requests/groups/${payload.group_id}`)
    } catch (error) {
      toast.error(mutationErrorMessage(error, t('common:errors.unexpected')))
    }
  }

  return (
    <form className="space-y-6" onSubmit={(event) => { void handleSubmit(event) }}>
      <div>
        <h1 className={`text-2xl font-bold ${textColors.primary}`}>
          {t('purchases:quoteRequests.create.title')}
        </h1>
        <p className={`mt-1 text-sm ${textColors.tertiary}`}>
          {t('purchases:quoteRequests.create.description')}
        </p>
      </div>

      <section className={`${tokens.card.base} space-y-4`}>
        <div className="flex items-center justify-between gap-4">
          <h2 className={tokens.heading.section}>{t('purchases:quoteRequests.create.suppliers')}</h2>
          <button
            type="button"
            data-testid="add-supplier"
            disabled={suppliers.length >= 10}
            className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.sm}`}
            onClick={addSupplier}
          >
            <Plus className="me-2 h-4 w-4" />
            {t('purchases:quoteRequests.actions.addSupplier')}
          </button>
        </div>

        <div className="space-y-3">
          {suppliers.map((supplier, index) => (
            <div key={supplier.key} className="flex items-start gap-3">
              <div className="min-w-0 flex-1">
                <label className={tokens.label.base}>
                  {t('purchases:quoteRequests.fields.supplier')}
                </label>
                <PartnerPicker
                  value={supplier.supplierId}
                  onChange={(next) => { updateSupplier(index, next?.id ?? '') }}
                  partnerType="supplier"
                  label=""
                  placeholder={t('purchases:quoteRequests.fields.supplier')}
                  testId="supplier-picker"
                />
              </div>
              {suppliers.length > 1 && (
                <button
                  type="button"
                  className={`${tokens.button.base} ${tokens.button.ghost} ${tokens.button.sizes.sm} mt-7`}
                  onClick={() => { removeSupplier(index) }}
                  aria-label={t('purchases:quoteRequests.actions.removeSupplier')}
                >
                  <Trash2 className="h-4 w-4" />
                </button>
              )}
            </div>
          ))}
        </div>
      </section>

      <section className={`${tokens.card.base} space-y-4`}>
        <div className="flex items-center justify-between gap-4">
          <h2 className={tokens.heading.section}>{t('purchases:quoteRequests.create.lines')}</h2>
          <button
            type="button"
            className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.sm}`}
            onClick={addLine}
          >
            <Plus className="me-2 h-4 w-4" />
            {t('purchases:quoteRequests.actions.addLine')}
          </button>
        </div>

        <div className={`grid grid-cols-[1fr_1.4fr_0.8fr_0.8fr_auto] gap-3 border-b ${borderColors.light} pb-2 text-xs font-medium uppercase ${textColors.tertiary}`}>
          <div>{t('purchases:quoteRequests.fields.product')}</div>
          <div>{t('purchases:quoteRequests.fields.description')}</div>
          <div>{t('purchases:quoteRequests.fields.quantity')}</div>
          <div>{t('purchases:quoteRequests.fields.unitPrice')}</div>
          <div>{t('purchases:quoteRequests.columns.actions')}</div>
        </div>

        <div className="space-y-3">
          {lines.map((line, index) => (
            <div key={line.key} className="grid grid-cols-[1fr_1.4fr_0.8fr_0.8fr_auto] items-center gap-3">
              <ProductLineSelect
                value={line.productId}
                onChange={(productId) => { updateLine(index, { productId }) }}
                placeholder={t('purchases:quoteRequests.fields.product')}
              />
              <input
                data-testid={`line-description-${String(index)}`}
                className={tokens.input.base}
                value={line.description}
                onChange={(event) => { updateLine(index, { description: event.target.value }) }}
                placeholder={t('purchases:quoteRequests.fields.description')}
              />
              <QuantityInput
                data-testid={`line-quantity-${String(index)}`}
                value={line.quantity}
                onChange={(quantity) => { updateLine(index, { quantity }) }}
                decimalPlaces={4}
              />
              <MoneyInput
                data-testid={`line-unit-price-${String(index)}`}
                value={line.unitPrice}
                onChange={(unitPrice) => { updateLine(index, { unitPrice }) }}
                currency={activeCurrency}
              />
              <button
                type="button"
                disabled={lines.length === 1}
                className={`${tokens.button.base} ${tokens.button.ghost} ${tokens.button.sizes.sm}`}
                onClick={() => { removeLine(index) }}
                aria-label={t('purchases:quoteRequests.actions.removeLine')}
              >
                <Trash2 className="h-4 w-4" />
              </button>
            </div>
          ))}
        </div>
      </section>

      <section className={`${tokens.card.base} grid grid-cols-1 gap-4 md:grid-cols-2`}>
        <div>
          <label className={tokens.label.base} htmlFor="rfq-validity">
            {t('purchases:quoteRequests.fields.validityDate')}
          </label>
          <input
            id="rfq-validity"
            type="date"
            className={tokens.input.base}
            value={validityDate}
            onChange={(event) => { setValidityDate(event.target.value) }}
          />
        </div>
        <div>
          <label className={tokens.label.base} htmlFor="rfq-notes">
            {t('purchases:quoteRequests.fields.notes')}
          </label>
          <input
            id="rfq-notes"
            className={tokens.input.base}
            value={notes}
            onChange={(event) => { setNotes(event.target.value) }}
          />
        </div>
      </section>

      <div className="flex justify-end">
        <button
          type="submit"
          data-testid="submit-rfq-group"
          disabled={createGroup.isPending}
          className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.md}`}
        >
          {t('purchases:quoteRequests.actions.create')}
        </button>
      </div>
    </form>
  )
}
