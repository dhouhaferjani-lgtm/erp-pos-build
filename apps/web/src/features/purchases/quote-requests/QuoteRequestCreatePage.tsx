import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { zodResolver } from '@hookform/resolvers/zod'
import { ArrowLeft, Plus, Trash2 } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'

import { Button } from '@/components/atoms/Button/Button'
import { FormField } from '@/components/atoms/FormField/FormField'
import { Input } from '@/components/atoms/Input/Input'
import { MoneyInput } from '@/components/atoms/MoneyInput/MoneyInput'
import { QuantityInput } from '@/components/atoms/QuantityInput/QuantityInput'
import { PageHeader } from '@/components/molecules/PageHeader/PageHeader'
import { StickyFormFooter } from '@/components/molecules/StickyFormFooter/StickyFormFooter'
import { ProductLineSelect } from '@/components/molecules/line-items/ProductLineSelect'
import { PartnerPicker } from '@/components/molecules/pickers/PartnerPicker'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { confirmDiscard, useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard'
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

const QUOTE_REQUEST_CREATE_FORM_ID = 'quote-request-create-form'
const MAX_LENGTH_VALIDATION_KEY = 'validation.maxLength'
const NOTES_MAX_LENGTH = 2000

const quoteRequestCreateSchema = z.object({
  notes: z.string().max(NOTES_MAX_LENGTH, MAX_LENGTH_VALIDATION_KEY),
  validityDate: z.string(),
})

type QuoteRequestCreateFormValues = z.infer<typeof quoteRequestCreateSchema>

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

function quoteRequestValidationError(message: string | undefined, maxLengthMessage: string): string | undefined {
  if (message === undefined) return undefined
  if (message === MAX_LENGTH_VALIDATION_KEY) return maxLengthMessage
  return message
}

export function QuoteRequestCreatePage() {
  const { t } = useTranslation(['common', 'purchases'])
  const navigate = useNavigate()
  const createGroup = useCreateQuoteRequestGroup()
  const activeCurrency = useCompanyStore((state) => state.getCurrentCompany()?.currency ?? '')

  const [suppliers, setSuppliers] = useState<SupplierFormState[]>([emptySupplier()])
  const [lines, setLines] = useState<LineFormState[]>([emptyLine()])
  const form = useForm<QuoteRequestCreateFormValues>({
    defaultValues: {
      notes: '',
      validityDate: '',
    },
    resolver: zodResolver(quoteRequestCreateSchema),
  })
  const errors = form.formState.errors
  const hasSupplierChanges = suppliers.some((supplier) => supplier.supplierId !== '')
  const hasLineChanges = lines.some((line) => (
    line.productId !== '' ||
    line.variantId !== '' ||
    line.description !== '' ||
    line.quantity !== '1.0000' ||
    line.unitPrice !== '0.000'
  ))
  const isDirty = form.formState.isDirty || hasSupplierChanges || hasLineChanges
  useUnsavedChangesGuard({ isDirty })

  function confirmLeave(): boolean {
    return !isDirty || confirmDiscard(t('confirmation.unsavedChangesBody'))
  }

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

  async function handleValidSubmit(values: QuoteRequestCreateFormValues) {
    const partnerIds = suppliers.reduce<string[]>((ids, supplier) => {
      if (supplier.supplierId !== '') {
        ids.push(supplier.supplierId)
      }
      return ids
    }, [])
    try {
      const payload = await createGroup.mutateAsync({
        partner_ids: partnerIds,
        lines: lines.map(toPayloadLine),
        validity_date: values.validityDate === '' ? null : values.validityDate,
        notes: values.notes.trim() === '' ? null : values.notes.trim(),
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
    <div className="flex min-h-full flex-col gap-6">
      <PageHeader
        title={t('purchases:quoteRequests.create.title')}
        subtitle={t('purchases:quoteRequests.create.description')}
        breadcrumb={
          <Link
            to="/purchases/quote-requests"
            className={`inline-flex items-center gap-2 text-sm ${textColors.tertiary} ${textColors.hoverPrimary}`}
            onClick={(event) => {
              if (!confirmLeave()) {
                event.preventDefault()
              }
            }}
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:actions.back')}
          </Link>
        }
        className="mb-0"
      />

      <form
        id={QUOTE_REQUEST_CREATE_FORM_ID}
        className="flex flex-1 flex-col gap-6"
        onSubmit={(event) => { void form.handleSubmit(handleValidSubmit)(event) }}
      >

      <section className={`${tokens.card.base} space-y-4`}>
        <div className="flex items-center justify-between gap-4">
          <h2 className={tokens.heading.section}>{t('purchases:quoteRequests.create.suppliers')}</h2>
          <Button
            type="button"
            data-testid="add-supplier"
            disabled={suppliers.length >= 10}
            variant="secondary"
            size="sm"
            onClick={addSupplier}
          >
            <Plus className="me-2 h-4 w-4" />
            {t('purchases:quoteRequests.actions.addSupplier')}
          </Button>
        </div>

        <div className="space-y-3">
          {suppliers.map((supplier, index) => (
            <div key={supplier.key} className="flex items-start gap-3">
              <div className="min-w-0 flex-1">
                <PartnerPicker
                  value={supplier.supplierId}
                  onChange={(next) => { updateSupplier(index, next?.id ?? '') }}
                  partnerType="supplier"
                  label={t('purchases:quoteRequests.fields.supplier')}
                  placeholder={t('purchases:quoteRequests.fields.supplier')}
                  testId="supplier-picker"
                />
              </div>
              {suppliers.length > 1 && (
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="mt-7"
                  onClick={() => { removeSupplier(index) }}
                  aria-label={t('purchases:quoteRequests.actions.removeSupplier')}
                >
                  <Trash2 className="h-4 w-4" />
                </Button>
              )}
            </div>
          ))}
        </div>
      </section>

      <section className={`${tokens.card.base} space-y-4`}>
        <div className="flex items-center justify-between gap-4">
          <h2 className={tokens.heading.section}>{t('purchases:quoteRequests.create.lines')}</h2>
          <Button
            type="button"
            variant="secondary"
            size="sm"
            onClick={addLine}
          >
            <Plus className="me-2 h-4 w-4" />
            {t('purchases:quoteRequests.actions.addLine')}
          </Button>
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
              <Input
                data-testid={`line-description-${String(index)}`}
                value={line.description}
                onChange={(event) => { updateLine(index, { description: event.target.value }) }}
                placeholder={t('purchases:quoteRequests.fields.description')}
              />
              <QuantityInput
                data-testid={`line-quantity-${String(index)}`}
                value={line.quantity}
                onChange={(quantity) => { updateLine(index, { quantity }) }}
                // eslint-disable-next-line precision/no-literal-decimal-places -- RFQ free-text line — no bound product to derive precision from; see spec 2026-07-20 §3.3 pre-product exemption
                decimalPlaces={4}
              />
              <MoneyInput
                data-testid={`line-unit-price-${String(index)}`}
                value={line.unitPrice}
                onChange={(unitPrice) => { updateLine(index, { unitPrice }) }}
                currency={activeCurrency}
              />
              <Button
                type="button"
                disabled={lines.length === 1}
                variant="ghost"
                size="sm"
                onClick={() => { removeLine(index) }}
                aria-label={t('purchases:quoteRequests.actions.removeLine')}
              >
                <Trash2 className="h-4 w-4" />
              </Button>
            </div>
          ))}
        </div>
      </section>

      <section className={`${tokens.card.base} grid grid-cols-1 gap-4 md:grid-cols-2`}>
        <FormField
          label={t('purchases:quoteRequests.fields.validityDate')}
          htmlFor="rfq-validity"
          error={errors.validityDate?.message}
        >
          <Input
            id="rfq-validity"
            type="date"
            {...form.register('validityDate')}
            error={Boolean(errors.validityDate)}
          />
        </FormField>
        <FormField
          label={t('purchases:quoteRequests.fields.notes')}
          htmlFor="rfq-notes"
          error={quoteRequestValidationError(errors.notes?.message, t('common:validation.maxLength', { max: NOTES_MAX_LENGTH }))}
        >
          <Input
            id="rfq-notes"
            {...form.register('notes')}
            error={Boolean(errors.notes)}
          />
        </FormField>
      </section>

        <StickyFormFooter>
          <Button
            type="button"
            variant="secondary"
            onClick={() => {
              if (confirmLeave()) {
                void navigate('/purchases/quote-requests')
              }
            }}
          >
            {t('common:actions.cancel')}
          </Button>
          <Button
            type="submit"
            form={QUOTE_REQUEST_CREATE_FORM_ID}
            disabled={createGroup.isPending}
            variant="primary"
          >
            {t('purchases:quoteRequests.actions.create')}
          </Button>
        </StickyFormFooter>
      </form>
    </div>
  )
}
