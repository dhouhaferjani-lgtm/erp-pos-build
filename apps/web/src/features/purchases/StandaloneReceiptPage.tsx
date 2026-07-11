import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, ClipboardList, PackagePlus, Plus, Save, Send, Trash2 } from 'lucide-react'
import { toast } from 'sonner'


import { Button } from '../../components/atoms/Button/Button'
import { Input } from '../../components/atoms/Input/Input'
import { Select } from '../../components/atoms/Select/Select'
import { MoneyInput } from '../../components/atoms/MoneyInput/MoneyInput'
import { QuantityInput } from '../../components/atoms/QuantityInput/QuantityInput'
import { DataTable, type DataTableColumn } from '../../components/molecules/DataTable/DataTable'
import { PageHeader } from '../../components/molecules/PageHeader/PageHeader'
import { api } from '../../lib/api'
import { getDecimals } from '../../lib/currencyMeta'
import { formatQuantity } from '../../lib/decimal'
import { borderColors, colors, textColors, tokens, typography } from '../../lib/designTokens'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { usePermissions } from '../../hooks/usePermissions'
import { confirmDiscard, useUnsavedChangesGuard } from '../../hooks/useUnsavedChangesGuard'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'

interface OptionItem {
  id: string
  name: string
  sku?: string | null
  quantity_decimals?: number | null
}

interface OptionResponse {
  data: OptionItem[]
}

interface StandaloneReceiptResponse {
  data: {
    purchase_order: { id: string }
    goods_receipt: { id: string; status: string }
  }
}

interface ProcurementPolicyResponse {
  data: {
    allow_receipt_first: boolean
  }
}

interface ReceiptLineState {
  id: string
  productId: string
  quantity: string
  freeQuantity: string
  unitPrice: string
}

function newLine(): ReceiptLineState {
  return {
    id: crypto.randomUUID?.() ?? `line-${Date.now()}`,
    productId: '',
    quantity: '1.0000',
    freeQuantity: '0.0000',
    unitPrice: '0.000',
  }
}

function idempotencyKey(): string {
  return crypto.randomUUID?.() ?? String(Date.now())
}

function lineMatchesNewLineDefaults(line: ReceiptLineState): boolean {
  const defaultLine = newLine()
  return (
    line.productId === defaultLine.productId &&
    line.quantity === defaultLine.quantity &&
    line.freeQuantity === defaultLine.freeQuantity &&
    line.unitPrice === defaultLine.unitPrice
  )
}

export function StandaloneReceiptPage() {
  const { t } = useTranslation(['purchases', 'common', 'documentIngestions'])
  const navigate = useNavigate()
  const { hasPermission } = usePermissions()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const company = useCompanyStore((state) => state.companies.find((candidate) => candidate.id === state.currentCompanyId))
  const currency = company?.currency ?? 'TND'
  const hasTenantScope = tenantId !== null && companyId !== null

  const [supplierId, setSupplierId] = useState('')
  const [locationId, setLocationId] = useState('')
  const [externalReference, setExternalReference] = useState('')
  const [externalDate, setExternalDate] = useState('')
  const [lines, setLines] = useState<ReceiptLineState[]>([newLine()])
  const [submitMode, setSubmitMode] = useState<'draft' | 'post'>('draft')
  const [currentIdempotencyKey, setCurrentIdempotencyKey] = useState(idempotencyKey)

  const policyQuery = useQuery({
    queryKey: tenantScopedKey(['procurement-policy']),
    queryFn: async () => {
      const response = await api.get<ProcurementPolicyResponse>('/procurement-policies')
      return response.data.data
    },
    enabled: hasTenantScope,
  })

  const receiptFirstDisabled = policyQuery.data?.allow_receipt_first === false
  const optionQueriesEnabled = hasTenantScope && !receiptFirstDisabled

  const suppliersQuery = useQuery({
    queryKey: tenantScopedKey(['standalone-receipt', 'suppliers']),
    queryFn: async () => {
      const response = await api.get<OptionResponse>('/partners?type=supplier')
      return response.data.data
    },
    enabled: optionQueriesEnabled,
  })

  const productsQuery = useQuery({
    queryKey: tenantScopedKey(['standalone-receipt', 'products']),
    queryFn: async () => {
      const response = await api.get<OptionResponse>('/products?type=part&per_page=100')
      return response.data.data
    },
    enabled: optionQueriesEnabled,
  })

  const locationsQuery = useQuery({
    queryKey: tenantScopedKey(['standalone-receipt', 'locations']),
    queryFn: async () => {
      const response = await api.get<OptionResponse>('/locations')
      return response.data.data
    },
    enabled: optionQueriesEnabled,
  })

  const productById = useMemo(() => {
    return new Map((productsQuery.data ?? []).map((product) => [product.id, product]))
  }, [productsQuery.data])

  const canSubmit = supplierId !== '' && locationId !== '' && lines.every((line) =>
    line.productId !== '' && line.quantity !== '' && line.unitPrice !== '',
  )
  const linesDirty = lines.length !== 1 || lines.some((line) => !lineMatchesNewLineDefaults(line))
  const isDirty =
    supplierId !== '' ||
    locationId !== '' ||
    externalReference !== '' ||
    externalDate !== '' ||
    linesDirty
  useUnsavedChangesGuard({ isDirty })

  function confirmLeave(): boolean {
    return !isDirty || confirmDiscard(t('confirmation.unsavedChangesBody'))
  }

  const createMutation = useMutation({
    mutationFn: async (postImmediately: boolean) => {
      const response = await api.post<StandaloneReceiptResponse>('/goods-receipts/standalone', {
        supplier_id: supplierId,
        location_id: locationId,
        idempotency_key: currentIdempotencyKey,
        external_reference: externalReference.trim() === '' ? null : externalReference.trim(),
        external_date: externalDate === '' ? null : externalDate,
        post_immediately: postImmediately,
        lines: lines.map((line) => {
          const quantityDecimals = productById.get(line.productId)?.quantity_decimals ?? 4

          return {
            product_id: line.productId,
            variant_id: null,
            qty: formatQuantity(line.quantity, quantityDecimals),
            free_qty: formatQuantity(line.freeQuantity, quantityDecimals),
            unit_price: formatQuantity(line.unitPrice, getDecimals(currency)),
          }
        }),
      })
      return response.data.data
    },
    onSuccess: async (data) => {
      toast.success(submitMode === 'post'
        ? t('purchases:standaloneReceipt.toast.posted')
        : t('purchases:standaloneReceipt.toast.draftCreated'))
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['purchase-orders']) }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['goods-receipts']) }),
      ])
      setCurrentIdempotencyKey(idempotencyKey())
      void navigate(`/purchases/orders/${data.purchase_order.id}`)
    },
    onError: (error: Error & { response?: { data?: { error?: { message?: string }; message?: string } } }) => {
      toast.error(error.response?.data?.error?.message ?? error.response?.data?.message ?? error.message)
    },
  })

  function updateLine(id: string, patch: Partial<ReceiptLineState>) {
    setLines((current) => current.map((line) => line.id === id ? { ...line, ...patch } : line))
  }

  function submit(mode: 'draft' | 'post') {
    setSubmitMode(mode)
    createMutation.mutate(mode === 'post')
  }

  const lineColumns: DataTableColumn<ReceiptLineState>[] = [
    {
      key: 'product',
      header: t('purchases:standaloneReceipt.fields.product'),
      render: (line) => (
        <>
          <label className="sr-only">{t('purchases:standaloneReceipt.fields.product')}</label>
          <Select
            aria-label={t('purchases:standaloneReceipt.fields.product')}
            value={line.productId}
            onChange={(event) => { updateLine(line.id, { productId: event.target.value }) }}
          >
            <option value="">{t('purchases:standaloneReceipt.placeholders.product')}</option>
            {(productsQuery.data ?? []).map((item) => (
              <option key={item.id} value={item.id}>{item.sku ? `${item.sku} - ${item.name}` : item.name}</option>
            ))}
          </Select>
        </>
      ),
    },
    {
      key: 'quantity',
      header: t('purchases:standaloneReceipt.fields.quantity'),
      width: '10rem',
      render: (line) => {
        const product = productById.get(line.productId)
        return (
          <>
            <label className="sr-only">{t('purchases:standaloneReceipt.fields.quantity')}</label>
            <QuantityInput
              aria-label={t('purchases:standaloneReceipt.fields.quantity')}
              value={line.quantity}
              onChange={(value) => { updateLine(line.id, { quantity: value }) }}
              decimalPlaces={product?.quantity_decimals ?? 4}
            />
          </>
        )
      },
    },
    {
      key: 'freeQuantity',
      header: t('purchases:standaloneReceipt.fields.freeQuantity'),
      width: '10rem',
      render: (line) => {
        const product = productById.get(line.productId)
        return (
          <>
            <label className="sr-only">{t('purchases:standaloneReceipt.fields.freeQuantity')}</label>
            <QuantityInput
              aria-label={t('purchases:standaloneReceipt.fields.freeQuantity')}
              value={line.freeQuantity}
              onChange={(value) => { updateLine(line.id, { freeQuantity: value }) }}
              decimalPlaces={product?.quantity_decimals ?? 4}
            />
          </>
        )
      },
    },
    {
      key: 'unitPrice',
      header: t('purchases:standaloneReceipt.fields.unitPrice'),
      width: '11rem',
      render: (line) => (
        <>
          <label className="sr-only">{t('purchases:standaloneReceipt.fields.unitPrice')}</label>
          <MoneyInput
            aria-label={t('purchases:standaloneReceipt.fields.unitPrice')}
            value={line.unitPrice}
            onChange={(value) => { updateLine(line.id, { unitPrice: value }) }}
            currency={currency}
          />
        </>
      ),
    },
    {
      key: 'actions',
      header: '',
      align: 'right',
      width: '3.5rem',
      render: (line) => (
        <button
          type="button"
          aria-label={t('purchases:standaloneReceipt.actions.removeLine')}
          disabled={lines.length === 1}
          onClick={() => { setLines((current) => current.filter((candidate) => candidate.id !== line.id)) }}
          className={`rounded-md p-2 ${textColors.disabled} ${colors.hover.red50} ${textColors.hoverError} disabled:opacity-40`}
        >
          <Trash2 className="h-4 w-4" />
        </button>
      ),
    },
  ]

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('purchases:standaloneReceipt.title')}
        subtitle={t('purchases:standaloneReceipt.description')}
        breadcrumb={
          <Link
            to="/purchases/receipts"
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
        actions={(
          <div className="flex flex-wrap items-center gap-2">
            {hasPermission('document-ingestions.view') ? (
            <Link
              to="/purchases/scans/new?kind=supplier_delivery_note"
              className={`${typography.fontSize.sm} ${textColors.brand} hover:underline`}
            >
              {t('documentIngestions:actions.scanInstead')}
            </Link>
            ) : null}
        {!receiptFirstDisabled ? (
          <>
          <Button
            type="button"
            variant="secondary"
            disabled={!canSubmit || createMutation.isPending}
            onClick={() => { submit('draft') }}
            className="gap-2"
          >
            <Save className="h-4 w-4" />
            {t('purchases:standaloneReceipt.actions.saveDraft')}
          </Button>
          <Button
            type="button"
            disabled={!canSubmit || createMutation.isPending}
            onClick={() => { submit('post') }}
            className="gap-2"
          >
            <Send className="h-4 w-4" />
            {t('purchases:standaloneReceipt.actions.postNow')}
          </Button>
          </>
        ) : null}
          </div>
        )}
        className="mb-0"
      />

      {receiptFirstDisabled ? (
        <div className={`${tokens.alert.base} ${tokens.alert.warning} flex items-center gap-2`}>
          <PackagePlus className="h-4 w-4 shrink-0" />
          {t('purchases:standaloneReceipt.policyDisabled')}
        </div>
      ) : <>
      <section className={`grid gap-4 border-b ${borderColors.light} pb-6 md:grid-cols-2 xl:grid-cols-4`}>
        <label className={`space-y-1 ${typography.fontSize.sm} ${typography.fontWeight.medium} ${textColors.secondary}`}>
          {t('purchases:standaloneReceipt.fields.supplier')}
          <Select
            value={supplierId}
            onChange={(event) => { setSupplierId(event.target.value) }}
          >
            <option value="">{t('purchases:standaloneReceipt.placeholders.supplier')}</option>
            {(suppliersQuery.data ?? []).map((supplier) => (
              <option key={supplier.id} value={supplier.id}>{supplier.name}</option>
            ))}
          </Select>
        </label>
        <label className={`space-y-1 ${typography.fontSize.sm} ${typography.fontWeight.medium} ${textColors.secondary}`}>
          {t('purchases:standaloneReceipt.fields.location')}
          <Select
            value={locationId}
            onChange={(event) => { setLocationId(event.target.value) }}
          >
            <option value="">{t('purchases:standaloneReceipt.placeholders.location')}</option>
            {(locationsQuery.data ?? []).map((location) => (
              <option key={location.id} value={location.id}>{location.name}</option>
            ))}
          </Select>
        </label>
        <label className={`space-y-1 ${typography.fontSize.sm} ${typography.fontWeight.medium} ${textColors.secondary}`}>
          {t('purchases:standaloneReceipt.fields.blNumber')}
          <Input
            value={externalReference}
            onChange={(event) => { setExternalReference(event.target.value) }}
          />
        </label>
        <label className={`space-y-1 ${typography.fontSize.sm} ${typography.fontWeight.medium} ${textColors.secondary}`}>
          {t('purchases:standaloneReceipt.fields.blDate')}
          <Input
            type="date"
            value={externalDate}
            onChange={(event) => { setExternalDate(event.target.value) }}
          />
        </label>
      </section>

      <section className="space-y-3">
        <div className="flex items-center justify-between">
          <h2 className={`flex items-center gap-2 ${typography.fontSize.base} ${typography.fontWeight.semibold} ${textColors.primary}`}>
            <ClipboardList className={`h-4 w-4 ${textColors.brand}`} />
            {t('purchases:standaloneReceipt.lines.title')}
          </h2>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => { setLines((current) => [...current, newLine()]) }}
            className="gap-2"
          >
            <Plus className="h-4 w-4" />
            {t('purchases:standaloneReceipt.actions.addLine')}
          </Button>
        </div>

        <div className={`${borderColors.light} rounded-lg border`}>
          <DataTable
            columns={lineColumns}
            data={lines}
            keyExtractor={(line) => line.id}
          />
        </div>
      </section>

      <div className={`flex items-center gap-2 rounded-lg border ${borderColors.primary} ${colors.primary[50]} px-4 py-3 ${typography.fontSize.sm} ${textColors.brand}`}>
        <PackagePlus className="h-4 w-4 shrink-0" />
        {t('purchases:standaloneReceipt.footerHint')}
      </div>
      </>}
    </div>
  )
}
