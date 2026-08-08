import { useMemo, useState } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Link } from 'react-router-dom'
import { Button } from '@/components/atoms/Button'
import { FormField } from '@/components/atoms/FormField'
import { QuantityInput } from '@/components/atoms/QuantityInput/QuantityInput'
import { Select } from '@/components/atoms/Select/Select'
import { Textarea } from '@/components/atoms/Textarea'
import { Modal } from '@/components/organisms/Modal'
import { bcadd, formatQuantity } from '@/lib/decimal'
import { semanticColorTokens as tokens, textColors } from '@/lib/designTokens'
import { entityRoutes } from '@/lib/entityRoutes'
import { usePermissions } from '@/hooks/usePermissions'
import { useCreateStockAdjustment, useFreshStockLevel } from '../api/queries'
import {
  extractRefusal,
  isAcknowledgeableRefusalCode,
  overrideFlagFor,
  refusalMessageKey,
} from '../api/refusals'
import type { AcknowledgeableRefusalCode, ApiErrorEnvelope } from '../api/refusals'
import { reasonDirection, reasonsForProduct, type AdjustmentReason, type StockLevel } from '../types'
import { AcknowledgeableRefusalDialog } from './AcknowledgeableRefusalDialog'
import { LotSelect } from './LotSelect'

interface Props {
  open: boolean
  productId: string
  productName: string
  locationId: string
  onClose: () => void
}

interface QuickAdjustmentValues {
  reason_code: AdjustmentReason
  magnitude: string
  note?: string | undefined
  batch_uuid?: string | undefined
}

/**
 * The one-line quick correction from the stock-levels table (DPA V7 / F7).
 *
 * Lives in `features/stock-adjustments/`, not `features/inventory/`, and both
 * halves of that are load-bearing: this path is inside the strict ESLint block
 * (where the refusal narrowing and the Controller wiring belong), and the
 * `stock-adjustments` i18n namespace is where its keys are registered.
 *
 * It posts `{post_immediately: true}` — ONE request — so a refusal persists
 * NOTHING. That is why it is on the UNSAVED-FORM recovery branch: there is no
 * draft to PATCH, and "recompute" is a client-side re-anchor against a fresh
 * read.
 */
export function QuickStockAdjustmentModal({ open, ...props }: Props) {
  // Unmount when closed instead of resetting form state in an effect: a fresh
  // mount per open is the same guarantee with no setState-in-effect, and it also
  // guarantees the FRESH stock-level read re-runs for every open.
  if (!open) {
    return null
  }

  return <QuickStockAdjustmentGate {...props} />
}

/**
 * The D15b preflight, FAILING CLOSED.
 *
 * The form must not exist before the fresh read lands. Rendering it early meant
 * a submit inside the fetch window posted a FABRICATED anchor of `'0'`, an error
 * left a fully usable form authoring against `'0'` (the endpoint is a
 * `firstOrFail()`, so a product with no stock row at this location 404s), and
 * `damage`/`write_off` were offered on a lot-tracked product until the read
 * landed — the `USE_BATCH_WRITE_OFF` surprise `reasonsForProduct` exists to
 * prevent. All three are the same bug: the form outliving its own precondition.
 */
function QuickStockAdjustmentGate({ productId, productName, locationId, onClose }: Omit<Props, 'open'>) {
  const { t } = useTranslation('stock-adjustments')
  const { data: level, isPending, isError } = useFreshStockLevel(productId, locationId)

  if (isPending || isError) {
    return (
      <Modal isOpen onClose={onClose} size="md">
        <Modal.Header title={t('quickModal.title')} onClose={onClose} />
        <Modal.Content>
          <p className={isError ? textColors.error : tokens.text.muted}>
            {isError ? t('quickModal.loadFailed') : t('quickModal.loading')}
          </p>
        </Modal.Content>
        <Modal.Footer>
          <Button variant="ghost" onClick={onClose}>
            {t('quickModal.cancel')}
          </Button>
        </Modal.Footer>
      </Modal>
    )
  }

  return (
    <QuickStockAdjustmentForm
      productId={productId}
      productName={productName}
      locationId={locationId}
      level={level}
      onClose={onClose}
    />
  )
}

function QuickStockAdjustmentForm({
  productId,
  productName,
  locationId,
  level,
  onClose,
}: Omit<Props, 'open'> & { level: StockLevel }) {
  const { t } = useTranslation('stock-adjustments')
  const { hasPermission } = usePermissions()
  const [refusal, setRefusal] = useState<ApiErrorEnvelope | null>(null)

  const { refetch } = useFreshStockLevel(productId, locationId)
  const createMutation = useCreateStockAdjustment()

  // No `?? 4` / `?? '0'` fallbacks: the gate above guarantees `level`, so every
  // one of these is the REAL value or the form does not exist.
  const decimals = level.quantity_decimals
  const observedBefore = level.quantity
  const availableReasons = useMemo(
    () => reasonsForProduct(level.requires_batch_tracking),
    [level.requires_batch_tracking],
  )

  const schema = useMemo(
    () =>
      z.object({
        reason_code: z.enum(['adjustment_positive', 'adjustment_negative', 'damage', 'write_off']),
        // Validated as a STRING against a decimal regex, then compared with
        // bcmath — never `Number()`/`parseFloat`, which is the rule-19 breach
        // the exemplar this form replaces was making.
        magnitude: z
          .string()
          .regex(/^\d+(\.\d{1,4})?$/, t('validation.quantity'))
          .refine((value) => /[1-9]/.test(value), { message: t('validation.nonZero') }),
        note: z.string().max(2000).optional(),
        batch_uuid: z.string().optional(),
      }),
    [t],
  )

  const form = useForm<QuickAdjustmentValues>({
    resolver: zodResolver(schema),
    defaultValues: { reason_code: 'adjustment_positive', magnitude: '', note: '', batch_uuid: '' },
  })

  // useWatch, not form.watch(): the latter cannot be memoized safely and the
  // lint rule that says so is right — watch() re-subscribes on every render.
  const reason = useWatch({ control: form.control, name: 'reason_code' })
  const magnitude = useWatch({ control: form.control, name: 'magnitude' })
  const batchUuid = useWatch({ control: form.control, name: 'batch_uuid' }) ?? ''

  /**
   * The SIGNED wire value, negated at STRING level.
   *
   * Never numerically: a `-Number(x)` round-trip on a decimal(15,4) is precisely
   * what rule 19 forbids.
   */
  const signedDelta = reason === 'adjustment_positive' ? magnitude : `-${magnitude}`
  // A NEGATIVE line must name the lot it draws down whenever one holds stock
  // here — the backend refuses it otherwise with BATCH_REQUIRED_FOR_LINE, a
  // NON-acknowledgeable refusal whose own message tells the operator to name a
  // lot. Without this field that instruction was unfollowable and lot-tracked
  // stock could not be decremented from this screen at all.
  const lotRequired = reasonDirection(reason) === 'out' && level.has_lots_at_location
  const resultingQuantity =
    magnitude === '' ? observedBefore : bcadd(observedBefore, signedDelta, decimals)

  const submit = async (
    values: QuickAdjustmentValues,
    acknowledgeCode: AcknowledgeableRefusalCode | null,
  ): Promise<void> => {
    const delta =
      values.reason_code === 'adjustment_positive' ? values.magnitude : `-${values.magnitude}`

    try {
      await createMutation.mutateAsync({
        location_id: locationId,
        note: values.note === undefined || values.note === '' ? null : values.note,
        post_immediately: true,
        // ONLY the flag the operator actually confirmed (D15a.3). Sending both
        // silently disables the other guard AND forges a permanent header claim
        // that they overrode it.
        ...(acknowledgeCode !== null ? overrideFlagFor(acknowledgeCode) : {}),
        lines: [
          {
            product_id: productId,
            reason_code: values.reason_code,
            batch_uuid:
              values.batch_uuid === undefined || values.batch_uuid === '' ? null : values.batch_uuid,
            delta_quantity: delta,
            observed_before: observedBefore,
          },
        ],
      })
      setRefusal(null)
      onClose()
    } catch (error) {
      setRefusal(extractRefusal(error))
    }
  }

  const acknowledgeableCode =
    refusal?.code !== undefined && isAcknowledgeableRefusalCode(refusal.code)
      ? refusal.code
      : null

  return (
    <>
      <Modal isOpen={acknowledgeableCode === null} onClose={onClose} size="md">
        <Modal.Header onClose={onClose}>
          <div>
            <h2 className={`text-lg font-semibold ${tokens.text.primary}`}>{t('quickModal.title')}</h2>
            <p className={tokens.text.muted}>{productName}</p>
          </div>
        </Modal.Header>
        <Modal.Content>
          <form
            id="quick-stock-adjustment-form"
            className="space-y-4"
            onSubmit={(event) => {
              void form.handleSubmit((values) => {
                void submit(values, null)
              })(event)
            }}
          >
            <div>
              <span className={tokens.text.muted}>{t('quickModal.currentQuantity')}</span>{' '}
              <span className="tabular-nums font-medium">
                {formatQuantity(observedBefore, decimals)}
              </span>
            </div>

            <FormField label={t('line.reason')} required error={form.formState.errors.reason_code?.message}>
              <Controller
                control={form.control}
                name="reason_code"
                render={({ field }) => (
                  <Select
                    aria-label={t('line.reason')}
                    value={field.value}
                    onChange={(event) => {
                      field.onChange(event.target.value)
                    }}
                  >
                    {availableReasons.map((value) => (
                      <option key={value} value={value}>
                        {t(`reason.${value}`)}
                      </option>
                    ))}
                  </Select>
                )}
              />
            </FormField>

            {level.has_lots_at_location && (
              <FormField
                label={t('line.lot')}
                required={lotRequired}
                error={form.formState.errors.batch_uuid?.message}
                helperText={lotRequired ? t('line.lotRequired') : undefined}
              >
                <Controller
                  control={form.control}
                  name="batch_uuid"
                  render={({ field }) => (
                    <LotSelect
                      productId={productId}
                      value={field.value ?? ''}
                      required={lotRequired}
                      onChange={field.onChange}
                    />
                  )}
                />
              </FormField>
            )}

            <FormField
              label={t('line.quantity')}
              required
              error={form.formState.errors.magnitude?.message}
            >
              {/* Controller, not register(): QuantityInput's onChange emits a
                  STRING, so it cannot be bound by register's event handler. */}
              <Controller
                control={form.control}
                name="magnitude"
                render={({ field }) => (
                  <QuantityInput
                    aria-label={t('line.quantity')}
                    value={field.value ?? ''}
                    onChange={field.onChange}
                    decimalPlaces={decimals}
                    error={form.formState.errors.magnitude !== undefined}
                  />
                )}
              />
            </FormField>

            <div className={tokens.text.muted}>
              {t('line.resultingQuantity')}{' '}
              <span className="tabular-nums">{formatQuantity(resultingQuantity, decimals)}</span>
            </div>

            <FormField label={t('create.noteLabel')}>
              <Controller
                control={form.control}
                name="note"
                render={({ field }) => (
                  <Textarea
                    aria-label={t('create.noteLabel')}
                    value={field.value ?? ''}
                    onChange={field.onChange}
                    rows={2}
                    placeholder={t('create.notePlaceholder')}
                  />
                )}
              />
            </FormField>

            {refusal !== null && acknowledgeableCode === null && (
              <p className={textColors.error}>{t(refusalMessageKey(refusal.code))}</p>
            )}

            {/* The opening-balance dead end, closed. The editor is where
                opening_qty / opening_unit_cost live — NOT the product detail
                page, which has no opening-balance affordance at all, and NOT
                /inventory/opening-balances, which is settings.view-gated. */}
            {hasPermission('products.update') ? (
              <p className={tokens.text.muted}>
                {t('create.openingBalanceHint')}{' '}
                <Link
                  to={entityRoutes.productEdit(productId, { section: 'section-inventory' })}
                  className="underline"
                >
                  {t('create.openingBalanceLink')}
                </Link>
              </p>
            ) : (
              <p className={tokens.text.muted}>{t('create.openingBalanceHint')}</p>
            )}
          </form>
        </Modal.Content>
        <Modal.Footer>
          <Button variant="ghost" onClick={onClose} disabled={createMutation.isPending}>
            {t('quickModal.cancel')}
          </Button>
          <Button
            variant="primary"
            type="submit"
            form="quick-stock-adjustment-form"
            disabled={createMutation.isPending || (lotRequired && batchUuid === '')}
          >
            {t('quickModal.submit')}
          </Button>
        </Modal.Footer>
      </Modal>

      {acknowledgeableCode !== null && refusal !== null && (
        <AcknowledgeableRefusalDialog
          open
          code={acknowledgeableCode}
          refusal={refusal}
          // ONE request, so nothing persisted: the recovery is client-side.
          origin="unsaved-form"
          canOverride={hasPermission('inventory.adjustments.post')}
          busy={createMutation.isPending}
          onDismiss={() => {
            setRefusal(null)
          }}
          onApplyAnyway={() => {
            void submit(form.getValues(), acknowledgeableCode)
          }}
          onReAnchor={() => {
            void refetch()
            setRefusal(null)
          }}
        />
      )}
    </>
  )
}
