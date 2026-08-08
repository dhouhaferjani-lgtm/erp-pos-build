import { useCallback, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react'
import { Button } from '@/components/atoms/Button'
import { FormField } from '@/components/atoms/FormField'
import { Select } from '@/components/atoms/Select/Select'
import { StatusBadge } from '@/components/atoms/StatusBadge/StatusBadge'
import { Textarea } from '@/components/atoms/Textarea'
import { QuantityInput } from '@/components/atoms/QuantityInput/QuantityInput'
import { DataTable, type DataTableColumn } from '@/components/molecules/DataTable/DataTable'
import { PageHeader } from '@/components/molecules/PageHeader'
import { StickyFormFooter } from '@/components/molecules/StickyFormFooter'
import { api } from '@/lib/api'
import { bcadd, bccomp, formatQuantity } from '@/lib/decimal'
import { semanticColorTokens as tokens, textColors } from '@/lib/designTokens'
import { entityRoutes } from '@/lib/entityRoutes'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard'
import { usePermissions } from '@/hooks/usePermissions'
import { useCreateStockAdjustment } from '../api/queries'
import { stockAdjustmentApi } from '../api/stockAdjustmentApi'
import { extractRefusal, isAcknowledgeableRefusalCode, refusalMessageKey } from '../api/refusals'
import type { ApiErrorEnvelope } from '../api/refusals'
import { AcknowledgeableRefusalDialog } from '../components/AcknowledgeableRefusalDialog'
import {
  isAdjustmentReason,
  reasonDirection,
  reasonsForProduct,
  type AdjustmentReason,
} from '../types'

interface OptionResponse {
  data: { id: string; name: string; code?: string }[]
}

interface BatchOption {
  batch_uuid: string
  batch_number: string
}

/**
 * One authored line, held as STRINGS end to end.
 *
 * `magnitude` is the unsigned figure the operator types; the signed wire value
 * is derived from the reason at submit time by string concatenation, never by
 * negating a JS number.
 */
interface LineDraft {
  key: string
  productId: string
  reason: AdjustmentReason
  magnitude: string
  observedBefore: string
  quantityDecimals: number
  requiresBatchTracking: boolean
  hasLotsAtLocation: boolean
  batchUuid: string
  note: string
}

const NEW_LINE_KEY = (): string => Math.random().toString(36).slice(2)

/**
 * The multi-line stock-adjustment authoring page (DPA V7 / F3).
 *
 * Its Post is a SINGLE `post_immediately: true` request, so a refusal persists
 * nothing — this page is on the UNSAVED-FORM recovery branch and never PATCHes.
 * "Save draft" creates the draft and hands the operator to the detail page,
 * which is where the persisted-draft branch lives.
 */
export function CreateStockAdjustmentPage() {
  const { t } = useTranslation(['stock-adjustments', 'common'])
  const navigate = useNavigate()
  const { hasPermission } = usePermissions()
  const createMutation = useCreateStockAdjustment()

  const [locationId, setLocationId] = useState('')
  const [note, setNote] = useState('')
  const [lines, setLines] = useState<LineDraft[]>([])
  const [pendingProductId, setPendingProductId] = useState('')
  const [refusal, setRefusal] = useState<ApiErrorEnvelope | null>(null)
  const [lineError, setLineError] = useState<string | null>(null)

  const locationsQuery = useQuery({
    queryKey: tenantScopedKey(['stock-adjustments', 'locations']),
    queryFn: async () => {
      const response = await api.get<OptionResponse>('/locations')
      return response.data.data
    },
  })

  const productsQuery = useQuery({
    queryKey: tenantScopedKey(['stock-adjustments', 'products']),
    queryFn: async () => {
      const response = await api.get<OptionResponse>('/products?per_page=100')
      return response.data.data
    },
  })

  const isDirty = locationId !== '' || note !== '' || lines.length > 0
  useUnsavedChangesGuard({ isDirty })

  /**
   * Line-add authors `observed_before` from a FRESH read (D15b), not the list
   * cache — otherwise the staleness guard fires on cache age and operators learn
   * to click "apply anyway", inverting its value.
   */
  const addLine = useCallback(async (): Promise<void> => {
    if (pendingProductId === '' || locationId === '') {
      return
    }
    if (lines.some((line) => line.productId === pendingProductId && line.batchUuid === '')) {
      setLineError(t('create.duplicateLine', { defaultValue: t('create.emptyLines') }))
      return
    }

    const level = await stockAdjustmentApi.stockLevel(pendingProductId, locationId)

    setLines((current) => [
      ...current,
      {
        key: NEW_LINE_KEY(),
        productId: pendingProductId,
        reason: 'adjustment_positive',
        magnitude: '',
        observedBefore: level.quantity,
        quantityDecimals: level.quantity_decimals,
        requiresBatchTracking: level.requires_batch_tracking,
        hasLotsAtLocation: level.has_lots_at_location,
        batchUuid: '',
        note: '',
      },
    ])
    setPendingProductId('')
    setLineError(null)
  }, [pendingProductId, locationId, lines, t])

  const updateLine = useCallback((key: string, patch: Partial<LineDraft>): void => {
    setLines((current) => current.map((line) => (line.key === key ? { ...line, ...patch } : line)))
  }, [])

  const removeLine = useCallback((key: string): void => {
    setLines((current) => current.filter((line) => line.key !== key))
  }, [])

  /** The signed wire value, negated at STRING level. Never `-Number(x)`. */
  const signedDelta = (line: LineDraft): string =>
    line.magnitude === ''
      ? '0'
      : reasonDirection(line.reason) === 'in'
        ? line.magnitude
        : `-${line.magnitude}`

  const summary = useMemo(() => {
    let net = '0'
    let increases = 0
    let decreases = 0

    for (const line of lines) {
      const delta = signedDelta(line)
      net = bcadd(net, delta, 4)
      if (bccomp(delta, '0') > 0) {
        increases += 1
      } else if (bccomp(delta, '0') < 0) {
        decreases += 1
      }
    }

    return { net, increases, decreases }
  }, [lines])

  const lineIssues = useMemo(
    () =>
      lines.map((line) => {
        if (line.magnitude === '' || !/^\d+(\.\d{1,4})?$/.test(line.magnitude)) {
          return t('line.quantity')
        }
        // Inline mirror of the server's `not_in:0`, so a zero delta never reaches
        // the server as a surprise.
        if (bccomp(line.magnitude, '0') === 0) {
          return t('line.quantity')
        }
        // A negative line MUST name a lot when one holds stock here — the rule is
        // keyed on the LOTS, not on the product flag.
        if (
          reasonDirection(line.reason) === 'out' &&
          line.hasLotsAtLocation &&
          line.batchUuid === ''
        ) {
          return t('line.lotRequired')
        }
        return null
      }),
    [lines, t],
  )

  const canSubmit =
    locationId !== '' && lines.length > 0 && lineIssues.every((issue) => issue === null)

  const submit = async (postImmediately: boolean, acknowledge = false): Promise<void> => {
    try {
      const created = await createMutation.mutateAsync({
        location_id: locationId,
        note: note === '' ? null : note,
        post_immediately: postImmediately,
        ...(acknowledge ? { acknowledge_stale: true, ignore_reservations: true } : {}),
        lines: lines.map((line) => ({
          product_id: line.productId,
          batch_uuid: line.batchUuid === '' ? null : line.batchUuid,
          reason_code: line.reason,
          delta_quantity: signedDelta(line),
          observed_before: line.observedBefore,
          line_note: line.note === '' ? null : line.note,
        })),
      })
      setRefusal(null)
      void navigate(entityRoutes.stockAdjustment(created.id))
    } catch (error) {
      setRefusal(extractRefusal(error))
    }
  }

  /** Client-side re-anchor: there is nothing persisted to PATCH. */
  const recomputeFromFresh = async (): Promise<void> => {
    const refreshed = await Promise.all(
      lines.map(async (line) => {
        const level = await stockAdjustmentApi.stockLevel(line.productId, locationId)
        return {
          ...line,
          observedBefore: level.quantity,
          quantityDecimals: level.quantity_decimals,
          requiresBatchTracking: level.requires_batch_tracking,
          hasLotsAtLocation: level.has_lots_at_location,
        }
      }),
    )
    setLines(refreshed)
    setRefusal(null)
  }

  const productName = useCallback(
    (productId: string): string =>
      (productsQuery.data ?? []).find((product) => product.id === productId)?.name ?? productId,
    [productsQuery.data],
  )

  const lineColumns: DataTableColumn<LineDraft>[] = useMemo(
    () => [
      {
        // The reason is the FIRST cell: the direction is the line's primary
        // fact, and its labels carry the direction in words.
        key: 'reason',
        header: t('line.reason'),
        render: (line) => (
          <Select
            aria-label={t('line.reason')}
            value={line.reason}
            onChange={(event) => {
              // Guard, not cast: the <option> set is derived from
              // reasonsForProduct(), but the DOM value is a plain string and a
              // cast would let a stale option through unchecked.
              const raw = event.target.value
              if (isAdjustmentReason(raw)) {
                updateLine(line.key, { reason: raw })
              }
            }}
          >
            {reasonsForProduct(line.requiresBatchTracking).map((reason) => (
              <option key={reason} value={reason}>
                {t(`reason.${reason}`)}
              </option>
            ))}
          </Select>
        ),
      },
      {
        key: 'direction',
        header: t('line.direction.in'),
        render: (line) =>
          reasonDirection(line.reason) === 'in' ? (
            <StatusBadge tone="success">
              <ArrowUp className="me-1 inline h-3 w-3" />
              {t('line.direction.in')}
            </StatusBadge>
          ) : (
            <StatusBadge tone="warning">
              <ArrowDown className="me-1 inline h-3 w-3" />
              {t('line.direction.out')}
            </StatusBadge>
          ),
      },
      {
        key: 'product',
        header: t('line.product'),
        render: (line) => productName(line.productId),
      },
      {
        key: 'lot',
        header: t('line.lot'),
        render: (line) => (
          <LotSelect
            productId={line.productId}
            value={line.batchUuid}
            required={reasonDirection(line.reason) === 'out' && line.hasLotsAtLocation}
            onChange={(batchUuid) => {
              updateLine(line.key, { batchUuid })
            }}
          />
        ),
      },
      {
        key: 'magnitude',
        align: 'right',
        header: t('line.quantity'),
        render: (line) => (
          <QuantityInput
            value={line.magnitude}
            onChange={(magnitude) => {
              updateLine(line.key, { magnitude })
            }}
            decimalPlaces={line.quantityDecimals}
          />
        ),
      },
      {
        key: 'preview',
        align: 'right',
        header: t('line.resultingQuantity'),
        render: (line) => {
          const delta = signedDelta(line)
          const after = bcadd(line.observedBefore, delta, line.quantityDecimals)
          // formatQuantity preserves a leading '-' but never adds '+', so the
          // positive sign is rendered explicitly.
          const sign = bccomp(delta, '0') > 0 ? '+' : ''
          return (
            <span className="tabular-nums">
              {formatQuantity(line.observedBefore, line.quantityDecimals)} →{' '}
              {formatQuantity(after, line.quantityDecimals)}{' '}
              <span className={textColors.tertiary}>
                ({sign}
                {formatQuantity(delta, line.quantityDecimals)})
              </span>
            </span>
          )
        },
      },
      {
        key: 'remove',
        align: 'right',
        header: <span className="sr-only">{t('create.removeLine')}</span>,
        render: (line) => (
          <Button
            variant="ghost"
            size="sm"
            onClick={() => {
              removeLine(line.key)
            }}
            title={t('create.removeLine')}
          >
            <Trash2 className="h-4 w-4" />
          </Button>
        ),
      },
    ],
    [t, updateLine, removeLine, productName],
  )

  const acknowledgeableCode =
    refusal?.code !== undefined && isAcknowledgeableRefusalCode(refusal.code) ? refusal.code : null

  return (
    <div className="flex min-h-full flex-col space-y-6">
      <PageHeader title={t('create.title')} subtitle={t('create.subtitle')} className="mb-0" />

      <section className="space-y-4">
        <FormField label={t('create.locationLabel')} required helperText={t('create.locationHelp')}>
          <Select
            value={locationId}
            onChange={(event) => {
              setLocationId(event.target.value)
              // Every line's anchor belongs to the OLD location.
              setLines([])
            }}
          >
            <option value="">—</option>
            {(locationsQuery.data ?? []).map((location) => (
              <option key={location.id} value={location.id}>
                {location.name}
              </option>
            ))}
          </Select>
        </FormField>

        <FormField label={t('create.noteLabel')}>
          <Textarea
            value={note}
            onChange={(event) => {
              setNote(event.target.value)
            }}
            rows={2}
            placeholder={t('create.notePlaceholder')}
          />
        </FormField>
      </section>

      <section className="space-y-3">
        <div className="flex items-end gap-2">
          <FormField label={t('line.product')} className="flex-1">
            <Select
              value={pendingProductId}
              onChange={(event) => {
                setPendingProductId(event.target.value)
              }}
              disabled={locationId === ''}
            >
              <option value="">—</option>
              {(productsQuery.data ?? []).map((product) => (
                <option key={product.id} value={product.id}>
                  {product.name}
                </option>
              ))}
            </Select>
          </FormField>
          <Button
            variant="secondary"
            disabled={pendingProductId === '' || locationId === ''}
            onClick={() => {
              void addLine()
            }}
          >
            <Plus className="me-2 h-4 w-4" />
            {t('create.addLine')}
          </Button>
        </div>

        {lineError !== null && <p className={textColors.error}>{lineError}</p>}

        <DataTable
          columns={lineColumns}
          data={lines}
          keyExtractor={(line) => line.key}
          emptyState={<div className="py-6 text-center">{t('create.emptyLines')}</div>}
        />

        {lineIssues.some((issue) => issue !== null) && (
          <ul className={textColors.error}>
            {lineIssues.map((issue, index) =>
              issue === null ? null : (
                <li key={lines[index]?.key ?? index}>
                  {productName(lines[index]?.productId ?? '')}: {issue}
                </li>
              ),
            )}
          </ul>
        )}
      </section>

      {/* The document-level summary: a mixed-direction document must be legible
          as a whole BEFORE it is posted. */}
      <section>
        <dl className="grid grid-cols-3 gap-4">
          <div>
            <dt className={tokens.text.muted}>{t('create.summary.netDelta')}</dt>
            <dd className="tabular-nums text-lg font-medium">
              {bccomp(summary.net, '0') > 0 ? '+' : ''}
              {formatQuantity(summary.net, 4)}
            </dd>
          </div>
          <div>
            <dt className={tokens.text.muted}>{t('create.summary.increases')}</dt>
            <dd className="text-lg font-medium">{summary.increases}</dd>
          </div>
          <div>
            <dt className={tokens.text.muted}>{t('create.summary.decreases')}</dt>
            <dd className="text-lg font-medium">{summary.decreases}</dd>
          </div>
        </dl>
      </section>

      {refusal !== null && acknowledgeableCode === null && (
        <p className={textColors.error}>{t(refusalMessageKey(refusal.code))}</p>
      )}

      <StickyFormFooter>
        <Button
          variant="secondary"
          disabled={!canSubmit || createMutation.isPending}
          onClick={() => {
            void submit(false)
          }}
        >
          {t('create.saveDraft')}
        </Button>
        {hasPermission('inventory.adjustments.post') && (
          <Button
            variant="primary"
            disabled={!canSubmit || createMutation.isPending}
            onClick={() => {
              void submit(true)
            }}
          >
            {t('create.post')}
          </Button>
        )}
      </StickyFormFooter>

      {acknowledgeableCode !== null && refusal !== null && (
        <AcknowledgeableRefusalDialog
          open
          code={acknowledgeableCode}
          refusal={refusal}
          origin="unsaved-form"
          canOverride={hasPermission('inventory.adjustments.post')}
          busy={createMutation.isPending}
          onDismiss={() => {
            setRefusal(null)
          }}
          onApplyAnyway={() => {
            void submit(true, true)
          }}
          onReAnchor={() => {
            void recomputeFromFresh()
          }}
        />
      )}
    </div>
  )
}

/** The lot picker, fed by the batch-stock endpoint the write-off screen uses. */
function LotSelect({
  productId,
  value,
  required,
  onChange,
}: {
  productId: string
  value: string
  required: boolean
  onChange: (batchUuid: string) => void
}) {
  const { t } = useTranslation('stock-adjustments')

  const { data } = useQuery({
    queryKey: tenantScopedKey(['stock-adjustments', 'batch-stock', productId]),
    queryFn: async () => {
      const response = await api.get<{ data: BatchOption[] }>(`/products/${productId}/batch-stock`)
      return response.data.data
    },
    enabled: productId !== '',
  })

  const options = data ?? []

  if (options.length === 0) {
    return <span className={textColors.tertiary}>{t('line.lotEmpty')}</span>
  }

  return (
    <Select
      aria-label={t('line.lot')}
      value={value}
      onChange={(event) => {
        onChange(event.target.value)
      }}
      error={required && value === ''}
    >
      <option value="">—</option>
      {options.map((option) => (
        <option key={option.batch_uuid} value={option.batch_uuid}>
          {option.batch_number}
        </option>
      ))}
    </Select>
  )
}
