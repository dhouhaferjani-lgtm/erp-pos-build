import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Send, ShoppingCart } from 'lucide-react'
import { toast } from 'sonner'

import { Button } from '@/components/atoms/Button/Button'
import { Input } from '@/components/atoms/Input/Input'
import { MoneyInput } from '@/components/atoms/MoneyInput/MoneyInput'
import { QuantityInput } from '@/components/atoms/QuantityInput/QuantityInput'
import { StatusBadge, type StatusTone } from '@/components/atoms/StatusBadge/StatusBadge'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { formatCurrency, formatQuantity } from '@/lib/decimal'

import {
  useAwardQuoteRequest,
  useQuoteRequest,
  useSendQuoteRequest,
  useUpdateQuoteRequest,
} from './api'
import { mutationErrorMessage } from './errorMessage'
import type { QuoteRequestLine, QuoteRequestLinePayload } from './types'

interface EditableLine {
  key: string
  productId: string
  variantId: string | null
  description: string | null
  quantity: string
  unitPrice: string
}

function toEditableLine(line: QuoteRequestLine): EditableLine {
  return {
    key: [
      line.product_id ?? '',
      line.variant_id ?? '',
      line.description ?? '',
    ].join('::'),
    productId: line.product_id ?? '',
    variantId: line.variant_id,
    description: line.description ?? null,
    quantity: line.quantity,
    unitPrice: line.unit_price,
  }
}

function toPayloadLine(line: EditableLine): QuoteRequestLinePayload {
  return {
    product_id: line.productId,
    variant_id: line.variantId,
    description: line.description,
    quantity: line.quantity,
    unit_price: line.unitPrice,
  }
}

function statusTone(isLostSibling: boolean): StatusTone {
  return isLostSibling ? 'neutral' : 'success'
}

export function QuoteRequestDetailPage() {
  const { t } = useTranslation(['common', 'purchases'])
  const { id = '' } = useParams<{ id: string }>()
  const navigate = useNavigate()

  const { data: quoteRequest, isLoading } = useQuoteRequest(id)
  const updateQuoteRequest = useUpdateQuoteRequest(id)
  const sendQuoteRequest = useSendQuoteRequest(id)
  const awardQuoteRequest = useAwardQuoteRequest(id, quoteRequest?.group_id)

  const [editingResponse, setEditingResponse] = useState(false)
  const [editableLines, setEditableLines] = useState<EditableLine[]>([])
  const [validityDate, setValidityDate] = useState('')
  const [supplierReference, setSupplierReference] = useState('')
  const [leadTimeDays, setLeadTimeDays] = useState('')

  if (isLoading || quoteRequest === undefined) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className={textColors.tertiary}>{t('common:status.loading')}</div>
      </div>
    )
  }

  const currentQuoteRequest = quoteRequest
  const hasResponse = currentQuoteRequest.responded_at !== null && currentQuoteRequest.responded_at !== undefined
  const isLostSibling = currentQuoteRequest.closed_reason === 'lost'
  const statusLabelKey = isLostSibling
    ? 'purchases:quoteRequests.status.lost'
    : currentQuoteRequest.status === 'confirmed' && !hasResponse
      ? 'purchases:quoteRequests.status.sent'
      : `purchases:quoteRequests.status.${currentQuoteRequest.status}`
  const canConvert = !isLostSibling && currentQuoteRequest.status === 'confirmed' && hasResponse

  function startEditing() {
    setEditableLines(currentQuoteRequest.lines.map(toEditableLine))
    setValidityDate(currentQuoteRequest.validity_date ?? '')
    setSupplierReference(currentQuoteRequest.supplier_reference ?? '')
    setLeadTimeDays(currentQuoteRequest.lead_time_days === null || currentQuoteRequest.lead_time_days === undefined ? '' : String(currentQuoteRequest.lead_time_days))
    setEditingResponse(true)
  }

  function updateLine(index: number, patch: Partial<EditableLine>) {
    setEditableLines((current) => current.map((line, i) => (i === index ? { ...line, ...patch } : line)))
  }

  async function saveResponse() {
    try {
      await updateQuoteRequest.mutateAsync({
        validity_date: validityDate === '' ? null : validityDate,
        supplier_reference: supplierReference.trim() === '' ? null : supplierReference.trim(),
        lead_time_days: leadTimeDays === '' ? null : parseInt(leadTimeDays, 10),
        lines: editableLines.map(toPayloadLine),
      })
      setEditingResponse(false)
    } catch (error) {
      toast.error(mutationErrorMessage(error, t('common:errors.unexpected')))
    }
  }

  async function sendRfq() {
    try {
      await sendQuoteRequest.mutateAsync()
    } catch (error) {
      toast.error(mutationErrorMessage(error, t('common:errors.unexpected')))
    }
  }

  async function convertToPurchaseOrder() {
    try {
      const purchaseOrder = await awardQuoteRequest.mutateAsync()
      void navigate(`/purchases/orders/${purchaseOrder.id}`)
    } catch (error) {
      toast.error(mutationErrorMessage(error, t('common:errors.unexpected')))
    }
  }

  return (
    <div className="space-y-6">
      <Link
        to="/purchases/quote-requests"
        className={`inline-flex items-center gap-2 text-sm ${textColors.brand} hover:underline`}
      >
        <ArrowLeft className="h-4 w-4" />
        {t('purchases:quoteRequests.title')}
      </Link>

      <section className={`${tokens.card.base} space-y-4`}>
        <div className="flex items-start justify-between gap-4">
          <div>
            <h1 className={`text-xl font-semibold ${textColors.primary}`}>
              {currentQuoteRequest.number}
            </h1>
            <p className={`mt-1 text-sm ${textColors.tertiary}`}>
              {currentQuoteRequest.partner.name}
            </p>
          </div>
          <div className="flex flex-wrap items-center justify-end gap-2">
            <StatusBadge tone={statusTone(isLostSibling)}>
              {t(statusLabelKey)}
            </StatusBadge>
            {!isLostSibling && (
              <>
                <Button
                  type="button"
                  data-testid="send-rfq"
                  variant="secondary"
                  onClick={() => { void sendRfq() }}
                >
                  <Send className="me-2 h-4 w-4" />
                  {t('purchases:quoteRequests.actions.send')}
                </Button>
                <Button
                  type="button"
                  data-testid="edit-response"
                  variant="secondary"
                  onClick={startEditing}
                >
                  {t('purchases:quoteRequests.actions.recordResponse')}
                </Button>
                {canConvert && (
                  <Button
                    type="button"
                    data-testid="convert-rfq"
                    onClick={() => { void convertToPurchaseOrder() }}
                  >
                    <ShoppingCart className="me-2 h-4 w-4" />
                    {t('purchases:quoteRequests.actions.convert')}
                  </Button>
                )}
              </>
            )}
          </div>
        </div>

        <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
          <div>
            <p className={`text-xs font-medium uppercase ${textColors.tertiary}`}>
              {t('purchases:quoteRequests.fields.validityDate')}
            </p>
            <p className={`mt-1 text-sm ${textColors.primary}`}>{currentQuoteRequest.validity_date ?? '-'}</p>
          </div>
          <div>
            <p className={`text-xs font-medium uppercase ${textColors.tertiary}`}>
              {t('purchases:quoteRequests.fields.leadTimeDays')}
            </p>
            <p className={`mt-1 text-sm ${textColors.primary}`}>{currentQuoteRequest.lead_time_days ?? '-'}</p>
          </div>
          <div>
            <p className={`text-xs font-medium uppercase ${textColors.tertiary}`}>
              {t('purchases:quoteRequests.fields.total')}
            </p>
            <p className={`mt-1 text-sm font-semibold ${textColors.primary}`}>
              {formatCurrency(currentQuoteRequest.total, true, currentQuoteRequest.currency)}
            </p>
          </div>
          <div>
            <p className={`text-xs font-medium uppercase ${textColors.tertiary}`}>
              {t('purchases:quoteRequests.fields.respondedAt')}
            </p>
            <p className={`mt-1 text-sm ${textColors.primary}`}>{currentQuoteRequest.responded_at ?? '-'}</p>
          </div>
        </div>
      </section>

      {editingResponse && (
        <section className={`${tokens.card.base} space-y-4`}>
          <h2 className={tokens.heading.section}>{t('purchases:quoteRequests.detail.response')}</h2>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div>
              <label className={tokens.label.base} htmlFor="response-validity-date">
                {t('purchases:quoteRequests.fields.validityDate')}
              </label>
              <Input
                id="response-validity-date"
                data-testid="response-validity-date"
                type="date"
                value={validityDate}
                onChange={(event) => { setValidityDate(event.target.value) }}
              />
            </div>
            <div>
              <label className={tokens.label.base} htmlFor="response-supplier-reference">
                {t('purchases:quoteRequests.fields.supplierReference')}
              </label>
              <Input
                id="response-supplier-reference"
                data-testid="response-supplier-reference"
                value={supplierReference}
                onChange={(event) => { setSupplierReference(event.target.value) }}
              />
            </div>
            <div>
              <label className={tokens.label.base} htmlFor="response-lead-time">
                {t('purchases:quoteRequests.fields.leadTimeDays')}
              </label>
              <Input
                id="response-lead-time"
                data-testid="response-lead-time"
                type="number"
                value={leadTimeDays}
                onChange={(event) => { setLeadTimeDays(event.target.value) }}
              />
            </div>
          </div>
        </section>
      )}

      <section className={`${tokens.card.base} space-y-4`}>
        <h2 className={tokens.heading.section}>{t('purchases:quoteRequests.detail.lines')}</h2>
        <div className={`grid grid-cols-[1.5fr_0.8fr_0.8fr] gap-4 border-b ${borderColors.light} pb-2 text-xs font-medium uppercase ${textColors.tertiary}`}>
          <div>{t('purchases:quoteRequests.fields.product')}</div>
          <div>{t('purchases:quoteRequests.fields.quantity')}</div>
          <div>{t('purchases:quoteRequests.fields.unitPrice')}</div>
        </div>
        {(editingResponse ? editableLines : currentQuoteRequest.lines.map(toEditableLine)).map((line, index) => (
          <div key={line.key} className="grid grid-cols-[1.5fr_0.8fr_0.8fr] items-center gap-4">
            <div className={`text-sm ${textColors.primary}`}>
              {line.description ?? line.productId}
            </div>
            {editingResponse ? (
              <QuantityInput
                value={line.quantity}
                onChange={(quantity) => { updateLine(index, { quantity }) }}
                // eslint-disable-next-line precision/no-literal-decimal-places -- RFQ free-text line — no bound product to derive precision from; see spec 2026-07-20 §3.3 pre-product exemption
                decimalPlaces={4}
              />
            ) : (
              <div className={`text-sm ${textColors.secondary}`}>{formatQuantity(line.quantity)}</div>
            )}
            {editingResponse ? (
              <MoneyInput
                data-testid={`response-unit-price-${String(index)}`}
                value={line.unitPrice}
                onChange={(unitPrice) => { updateLine(index, { unitPrice }) }}
                currency={currentQuoteRequest.currency}
              />
            ) : (
              <div className={`text-sm ${textColors.secondary}`}>{formatCurrency(line.unitPrice, true, currentQuoteRequest.currency)}</div>
            )}
          </div>
        ))}
        {editingResponse && (
          <div className="flex justify-end">
            <Button
              type="button"
              data-testid="save-response"
              onClick={() => { void saveResponse() }}
            >
              {t('purchases:quoteRequests.actions.saveResponse')}
            </Button>
          </div>
        )}
      </section>
    </div>
  )
}
