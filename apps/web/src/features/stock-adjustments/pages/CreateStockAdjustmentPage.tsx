import { useCallback, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { Controller, useFieldArray, useForm, useWatch } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react'
import { Button } from '@/components/atoms/Button'
import { FormField } from '@/components/atoms/FormField'
import { Select } from '@/components/atoms/Select/Select'
import { StatusBadge } from '@/components/atoms/StatusBadge/StatusBadge'
import { Textarea } from '@/components/atoms/Textarea'
import { QuantityInput } from '@/components/atoms/QuantityInput/QuantityInput'
import { DataTable, type DataTableColumn } from '@/components/molecules/DataTable/DataTable'
import { PageHeader } from '@/components/molecules/PageHeader'
import { ProductPicker, type ProductPickerValue } from '@/components/molecules/pickers/ProductPicker'
import { StickyFormFooter } from '@/components/molecules/StickyFormFooter'
import { api } from '@/lib/api'
import { bcadd, bccomp, formatQuantity } from '@/lib/decimal'
import { semanticColorTokens as tokens, textColors } from '@/lib/designTokens'
import { entityRoutes } from '@/lib/entityRoutes'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard'
import { useIdempotencyKey } from '@/hooks/useIdempotencyKey'
import { usePermissions } from '@/hooks/usePermissions'
import { useCreateStockAdjustment } from '../api/queries'
import { stockAdjustmentApi } from '../api/stockAdjustmentApi'
import {
  extractRefusal,
  isAcknowledgeableRefusalCode,
  overrideFlagFor,
  refusalMessageKey,
} from '../api/refusals'
import type { AcknowledgeableRefusalCode, ApiErrorEnvelope } from '../api/refusals'
import { AcknowledgeableRefusalDialog } from '../components/AcknowledgeableRefusalDialog'
import { refusalLineKey } from '../lib/refusalLineKey'
import { LotSelect } from '../components/LotSelect'
import {
  isAdjustmentReason,
  reasonDirection,
  reasonsForProduct,
  type AdjustmentReason,
} from '../types'

interface OptionResponse {
  data: { id: string; name: string; code?: string }[]
}

/**
 * One authored line, held as STRINGS end to end.
 *
 * `magnitude` is the unsigned figure the operator types; the signed wire value
 * is derived from the reason at submit time by string concatenation, never by
 * negating a JS number. The per-line metadata (`quantityDecimals`,
 * `hasLotsAtLocation`) comes from the FRESH read taken at line-add and travels
 * with the row so validation and display never guess.
 */
interface LineValues {
  productId: string
  productName: string
  reason: AdjustmentReason
  magnitude: string
  observedBefore: string
  quantityDecimals: number
  requiresBatchTracking: boolean
  hasLotsAtLocation: boolean
  batchUuid: string
  note: string
}

/**
 * `note` is a required STRING (empty when unset), not an optional one: under
 * `exactOptionalPropertyTypes` an optional here makes the zod output type
 * diverge from FormValues, and the mismatch surfaces as an unreadable
 * `Resolver<>` error rather than as anything about notes.
 */
interface FormValues {
  locationId: string
  note: string
  lines: LineValues[]
}

/** The signed wire value, negated at STRING level. Never `-Number(x)`. */
function signedDelta(line: Pick<LineValues, 'reason' | 'magnitude'>): string {
  if (line.magnitude === '') {
    return '0'
  }
  return reasonDirection(line.reason) === 'in' ? line.magnitude : `-${line.magnitude}`
}

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

  // ID-3: one key per logical submit attempt, held at PAGE scope so it survives
  // a failed request (including a refusal the operator then acknowledges and
  // resubmits). Rotated only after an awaited success (see submit).
  //
  // FE gate r1 MAJOR-3: ONE KEY PER INTENT, because the server replays on
  // `(tenant, company, idempotency_key)` alone and never compares the body. A
  // shared key would let a lost draft response be replayed as "Save & post":
  // the pre-check would find the committed DRAFT and return it 200, and the
  // page would navigate as if it had posted while nothing moved.
  const { key: draftIdempotencyKey, reset: resetDraftIdempotencyKey } = useIdempotencyKey()
  const { key: postIdempotencyKey, reset: resetPostIdempotencyKey } = useIdempotencyKey()

  // FE gate r1 MAJOR-2: `createMutation.isPending` is async state — it only
  // disables the buttons on a render that happens AFTER the click handler
  // returns, so two clicks in one task both reach mutateAsync. This ref is set
  // SYNCHRONOUSLY before the awaited call, so the second submit sees it. It
  // matters more here than on the transfer page: the adjustment backend has no
  // collision replay, so the losing duplicate is a 500, not a replay.
  const submitLockRef = useRef<boolean>(false)
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId)

  const [pendingProduct, setPendingProduct] = useState<ProductPickerValue | null>(null)
  const [refusal, setRefusal] = useState<ApiErrorEnvelope | null>(null)
  const [lineError, setLineError] = useState<string | null>(null)

  const schema = useMemo(
    () =>
      z.object({
        // Explicitly namespaced. The unprefixed form resolved only through the
        // ns-array fallback into `common`, so if `common.validation.required`
        // ever moved, the defaultValue would have rendered the FIELD LABEL as the
        // error message.
        locationId: z.string().min(1, t('common:validation.required')),
        note: z.string().max(2000),
        lines: z
          .array(
            z
              .object({
                productId: z.string().min(1),
                productName: z.string(),
                reason: z.enum(['adjustment_positive', 'adjustment_negative', 'damage', 'write_off']),
                // Validated as a STRING against a decimal regex, then compared
                // with bcmath — never Number()/parseFloat, which is the rule-19
                // breach this whole lane exists to remove.
                magnitude: z
                  .string()
                  .regex(/^\d+(\.\d{1,4})?$/, t('validation.quantity'))
                  .refine((value) => /[1-9]/.test(value), { message: t('validation.nonZero') }),
                observedBefore: z.string(),
                quantityDecimals: z.number(),
                requiresBatchTracking: z.boolean(),
                hasLotsAtLocation: z.boolean(),
                batchUuid: z.string(),
                note: z.string().max(255),
              })
              // A NEGATIVE line must name the lot it draws down whenever one
              // holds stock here. Mirrored inline so the operator never meets
              // BATCH_REQUIRED_FOR_LINE as a surprise 422.
              .refine(
                (line) =>
                  !(
                    reasonDirection(line.reason) === 'out' &&
                    line.hasLotsAtLocation &&
                    line.batchUuid === ''
                  ),
                { path: ['batchUuid'], message: t('validation.lotRequired') },
              ),
          )
          .min(1, t('create.emptyLines')),
      }),
    [t],
  )

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { locationId: '', note: '', lines: [] },
    mode: 'onChange',
  })

  const { fields, append, remove, replace } = useFieldArray({ control: form.control, name: 'lines' })

  const locationId = useWatch({ control: form.control, name: 'locationId' })
  const watchedLines = useWatch({ control: form.control, name: 'lines' })
  // Memoised: `useWatch` returns a fresh array reference each render, and every
  // derived useMemo/useCallback below depends on it.
  const lines = useMemo(() => watchedLines, [watchedLines])

  const isDirty = form.formState.isDirty
  useUnsavedChangesGuard({ isDirty })

  const locationsQuery = useQuery({
    // Keyed under its OWN resource literal: keying it under `stock-adjustments`
    // made the feature's invalidation predicate refetch locations, products and
    // every lot list on each create/post/cancel/correct.
    queryKey: tenantScopedKey(['locations', 'options']),
    queryFn: async () => {
      const response = await api.get<OptionResponse>('/locations')
      return response.data.data
    },
    enabled: !!tenantId && !!companyId,
  })

  /**
   * Line-add authors `observed_before` from a FRESH read (D15b), not the list
   * cache — otherwise the staleness guard fires on cache age and operators learn
   * to click "apply anyway", inverting its value.
   *
   * The read can fail: `/stock-levels/{p}/{l}` is a `firstOrFail()`, so a
   * product with no stock row at this location 404s. Unhandled, the line simply
   * never appeared — no message, no spinner change.
   */
  const addLine = useCallback(async (): Promise<void> => {
    if (pendingProduct === null || locationId === '') {
      return
    }
    const productId = pendingProduct.id
    if (lines.some((line) => line.productId === productId && line.batchUuid === '')) {
      setLineError(t('create.duplicateLine'))
      return
    }

    try {
      const level = await stockAdjustmentApi.stockLevel(productId, locationId)

      append({
        productId,
        productName: pendingProduct.name,
        reason: 'adjustment_positive',
        magnitude: '',
        observedBefore: level.quantity,
        quantityDecimals: level.quantity_decimals,
        requiresBatchTracking: level.requires_batch_tracking,
        hasLotsAtLocation: level.has_lots_at_location,
        batchUuid: '',
        note: '',
      })
      setPendingProduct(null)
      setLineError(null)
    } catch {
      setLineError(t('create.loadFailed'))
    }
  }, [pendingProduct, locationId, lines, append, t])

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

  /**
   * The net is a SUM ACROSS UNITS — a document may correct kilograms and pieces
   * in one go — so there is no single product unit to format it at. The storage
   * scale is the honest choice for a cross-unit figure, and it is stated here
   * rather than left as a bare literal for a reviewer to wonder about.
   */
  const netDecimals = useMemo(() => {
    const distinct = new Set(lines.map((line) => line.quantityDecimals))
    return distinct.size === 1 ? (lines[0]?.quantityDecimals ?? STORAGE_SCALE) : STORAGE_SCALE
  }, [lines])

  const submit = async (
    values: FormValues,
    postImmediately: boolean,
    acknowledgeCode: AcknowledgeableRefusalCode | null = null,
  ): Promise<void> => {
    if (submitLockRef.current) {
      return
    }

    try {
      submitLockRef.current = true
      const created = await createMutation.mutateAsync({
        idempotency_key: postImmediately ? postIdempotencyKey : draftIdempotencyKey,
        location_id: values.locationId,
        note: values.note === '' ? null : values.note,
        post_immediately: postImmediately,
        // ONLY the flag the operator actually confirmed (D15a.3).
        ...(acknowledgeCode !== null ? overrideFlagFor(acknowledgeCode) : {}),
        lines: values.lines.map((line) => ({
          product_id: line.productId,
          batch_uuid: line.batchUuid === '' ? null : line.batchUuid,
          reason_code: line.reason,
          delta_quantity: signedDelta(line),
          observed_before: line.observedBefore,
          line_note: line.note === '' ? null : line.note,
        })),
      })
      // Only THIS intent's attempt is over; the other intent keeps its key.
      if (postImmediately) {
        resetPostIdempotencyKey()
      } else {
        resetDraftIdempotencyKey()
      }
      setRefusal(null)
      void navigate(entityRoutes.stockAdjustment(created.id))
    } catch (error) {
      setRefusal(extractRefusal(error))
    } finally {
      submitLockRef.current = false
    }
  }

  /** Client-side re-anchor: there is nothing persisted to PATCH. */
  const recomputeFromFresh = async (): Promise<void> => {
    try {
      const current = form.getValues('lines')
      const refreshed = await Promise.all(
        current.map(async (line) => {
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
      replace(refreshed)
      setRefusal(null)
    } catch {
      setLineError(t('create.loadFailed'))
    }
  }

  // NOTE: the `lines.${row.index}.x` Controller names below trip
  // `restrict-template-expressions` (a number in a template literal). It is
  // accepted rather than worked around: react-hook-form's field paths are typed
  // as `lines.${number}.x`, so String(index) makes the name un-assignable — the
  // rule and the library disagree, and the library wins.
  const lineColumns: DataTableColumn<LineValues & { id: string; index: number }>[] = useMemo(
    () => [
      {
        // The reason is the FIRST cell: the direction is the line's primary
        // fact, and its labels carry the direction in words.
        key: 'reason',
        header: t('line.reason'),
        render: (row) => (
          <FormField error={form.formState.errors.lines?.[row.index]?.reason?.message}>
            <Controller
              control={form.control}
              name={`lines.${row.index}.reason`}
              render={({ field }) => (
                <Select
                  aria-label={t('line.reason')}
                  value={field.value}
                  onChange={(event) => {
                    // Guard, not cast: the DOM value is a plain string.
                    const raw = event.target.value
                    if (isAdjustmentReason(raw)) {
                      field.onChange(raw)
                    }
                  }}
                >
                  {reasonsForProduct(row.requiresBatchTracking).map((reason) => (
                    <option key={reason} value={reason}>
                      {t(`reason.${reason}`)}
                    </option>
                  ))}
                </Select>
              )}
            />
          </FormField>
        ),
      },
      {
        key: 'direction',
        header: t('line.direction.in'),
        render: (row) =>
          reasonDirection(row.reason) === 'in' ? (
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
        render: (row) => row.productName,
      },
      {
        key: 'lot',
        header: t('line.lot'),
        render: (row) => (
          <FormField error={form.formState.errors.lines?.[row.index]?.batchUuid?.message}>
            <Controller
              control={form.control}
              name={`lines.${row.index}.batchUuid`}
              render={({ field }) => (
                <LotSelect
                  productId={row.productId}
                  value={field.value ?? ''}
                  required={reasonDirection(row.reason) === 'out' && row.hasLotsAtLocation}
                  onChange={field.onChange}
                />
              )}
            />
          </FormField>
        ),
      },
      {
        key: 'magnitude',
        align: 'right',
        header: t('line.quantity'),
        render: (row) => (
          <FormField error={form.formState.errors.lines?.[row.index]?.magnitude?.message}>
            {/* Controller, not register(): QuantityInput's onChange emits a
                STRING, so register's event handler cannot bind it. */}
            <Controller
              control={form.control}
              name={`lines.${row.index}.magnitude`}
              render={({ field }) => (
                <QuantityInput
                  aria-label={t('line.quantity')}
                  value={field.value ?? ''}
                  onChange={field.onChange}
                  decimalPlaces={row.quantityDecimals}
                  error={form.formState.errors.lines?.[row.index]?.magnitude !== undefined}
                />
              )}
            />
          </FormField>
        ),
      },
      {
        key: 'preview',
        align: 'right',
        header: t('line.resultingQuantity'),
        render: (row) => {
          const delta = signedDelta(row)
          const after = bcadd(row.observedBefore, delta, row.quantityDecimals)
          // formatQuantity preserves a leading '-' but never adds '+', so the
          // positive sign is rendered explicitly.
          const sign = bccomp(delta, '0') > 0 ? '+' : ''
          return (
            <span className="tabular-nums">
              {formatQuantity(row.observedBefore, row.quantityDecimals)} →{' '}
              {formatQuantity(after, row.quantityDecimals)}{' '}
              <span className={textColors.tertiary}>
                ({sign}
                {formatQuantity(delta, row.quantityDecimals)})
              </span>
            </span>
          )
        },
      },
      {
        key: 'remove',
        align: 'right',
        header: <span className="sr-only">{t('create.removeLine')}</span>,
        render: (row) => (
          <Button
            variant="ghost"
            size="sm"
            onClick={() => {
              remove(row.index)
            }}
            title={t('create.removeLine')}
          >
            <Trash2 className="h-4 w-4" />
          </Button>
        ),
      },
    ],
    [t, form, remove],
  )

  const rows = fields.map((field, index) => ({
    ...(lines[index] ?? {
      productId: '',
      productName: '',
      reason: 'adjustment_positive' as AdjustmentReason,
      magnitude: '',
      observedBefore: '0',
      quantityDecimals: STORAGE_SCALE,
      requiresBatchTracking: false,
      hasLotsAtLocation: false,
      batchUuid: '',
      note: '',
    }),
    id: field.id,
    index,
  }))

  const acknowledgeableCode =
    refusal?.code !== undefined && isAcknowledgeableRefusalCode(refusal.code) ? refusal.code : null

  const deltaByKey = useMemo(() => {
    const map: Record<string, string> = {}
    for (const line of lines) {
      map[refusalLineKey({
        product_id: line.productId,
        variant_id: null,
        batch_uuid: line.batchUuid === '' ? null : line.batchUuid,
      })] = signedDelta(line)
    }
    return map
  }, [lines])

  return (
    <div className="flex min-h-full flex-col space-y-6">
      <PageHeader title={t('create.title')} subtitle={t('create.subtitle')} className="mb-0" />

      <section className="space-y-4">
        <FormField
          label={t('create.locationLabel')}
          required
          helperText={t('create.locationHelp')}
          error={form.formState.errors.locationId?.message}
        >
          <Controller
            control={form.control}
            name="locationId"
            render={({ field }) => (
              <Select
                aria-label={t('create.locationLabel')}
                value={field.value}
                onChange={(event) => {
                  field.onChange(event.target.value)
                  // Every line's anchor belongs to the OLD location.
                  replace([])
                }}
              >
                <option value="">—</option>
                {(locationsQuery.data ?? []).map((location) => (
                  <option key={location.id} value={location.id}>
                    {location.name}
                  </option>
                ))}
              </Select>
            )}
          />
        </FormField>

        <FormField label={t('create.noteLabel')}>
          <Controller
            control={form.control}
            name="note"
            render={({ field }) => (
              <Textarea
                aria-label={t('create.noteLabel')}
                value={field.value}
                onChange={field.onChange}
                rows={2}
                placeholder={t('create.notePlaceholder')}
              />
            )}
          />
        </FormField>
      </section>

      <section className="space-y-3">
        <div className="flex items-end gap-2">
          {/* The house picker, not a parallel one: a raw <Select> over
              `/products?per_page=100` was both a duplicate implementation and a
              hard dead end for any catalogue past 100 SKUs — on the only
              multi-line authoring page. `all` because a correction can apply to
              any stocked product, not just parts. */}
          <div className="flex-1">
            <ProductPicker
              label={t('line.product')}
              value={pendingProduct}
              onChange={setPendingProduct}
              disabled={locationId === ''}
              productType="all"
              testId="stock-adjustment-product-picker"
            />
          </div>
          <Button
            variant="secondary"
            disabled={pendingProduct === null || locationId === ''}
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
          data={rows}
          keyExtractor={(row) => row.id}
          emptyState={<div className="py-6 text-center">{t('create.emptyLines')}</div>}
        />
      </section>

      {/* The document-level summary: a mixed-direction document must be legible
          as a whole BEFORE it is posted. */}
      <section>
        <dl className="grid grid-cols-3 gap-4">
          <div>
            <dt className={tokens.text.muted}>{t('create.summary.netDelta')}</dt>
            <dd className="tabular-nums text-lg font-medium">
              {bccomp(summary.net, '0') > 0 ? '+' : ''}
              {formatQuantity(summary.net, netDecimals)}
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
          disabled={createMutation.isPending}
          onClick={() => {
            void form.handleSubmit((values) => submit(values, false))()
          }}
        >
          {t('create.saveDraft')}
        </Button>
        {hasPermission('inventory.adjustments.post') && (
          <Button
            variant="primary"
            disabled={createMutation.isPending}
            onClick={() => {
              void form.handleSubmit((values) => submit(values, true))()
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
          deltaByKey={deltaByKey}
          onDismiss={() => {
            setRefusal(null)
          }}
          onApplyAnyway={() => {
            void form.handleSubmit((values) => submit(values, true, acknowledgeableCode))()
          }}
          onReAnchor={
            acknowledgeableCode === 'STOCK_MOVED_SINCE_AUTHORING'
              ? () => {
                  void recomputeFromFresh()
                }
              : undefined
          }
        />
      )}
    </div>
  )
}

/** The canonical `decimal(15,4)` quantity scale. */
const STORAGE_SCALE = 4
