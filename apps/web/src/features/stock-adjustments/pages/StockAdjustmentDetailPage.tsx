import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/atoms/Button'
import { FormField } from '@/components/atoms/FormField'
import { Textarea } from '@/components/atoms/Textarea'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { DataTable, type DataTableColumn } from '@/components/molecules/DataTable/DataTable'
import { Modal } from '@/components/organisms/Modal'
import { PageHeader } from '@/components/molecules/PageHeader'
import { bcadd, bcsub, formatQuantity } from '@/lib/decimal'
import { semanticColorTokens as tokens, textColors } from '@/lib/designTokens'
import { entityRoutes } from '@/lib/entityRoutes'
import { usePermissions } from '@/hooks/usePermissions'
import {
  useCancelStockAdjustment,
  useCorrectStockAdjustment,
  usePostStockAdjustment,
  useStockAdjustment,
  useUpdateStockAdjustment,
} from '../api/queries'
import {
  extractRefusal,
  isAcknowledgeableRefusalCode,
  overrideFlagFor,
  refusalMessageKey,
} from '../api/refusals'
import type { AcknowledgeableRefusalCode, ApiErrorEnvelope, StaleLine } from '../api/refusals'
import { AcknowledgeableRefusalDialog } from '../components/AcknowledgeableRefusalDialog'
import { refusalLineKey } from '../lib/refusalLineKey'
import { StockAdjustmentStatusBadge } from '../components/StockAdjustmentStatusBadge'
import { narrowStaleDetails } from '../api/refusals'
import { isAdjustmentReason, toStockAdjustmentStatus } from '../types'
import type { StockAdjustmentLine } from '../types'

/**
 * The stock-adjustment document detail page (DPA V7 / F4).
 *
 * F4 OWNS the persisted-draft recovery branch. Its Post acts on a document that
 * already exists, so on a staleness refusal there IS something to re-anchor —
 * `PATCH /stock-adjustments/{id}` with each line's `observed_before` moved to
 * the fresh reading and its delta rebased. The create page and the quick modal
 * post in ONE request and therefore persist nothing on refusal, so they never
 * PATCH.
 *
 * Affordances are gated on status AND permission, not status alone: the
 * transfers detail page gates on status only, behind a `.view` route, which
 * means a viewer sees buttons they cannot use.
 */
export function StockAdjustmentDetailPage() {
  const { t } = useTranslation(['stock-adjustments', 'common'])
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { hasPermission } = usePermissions()

  const { data: adjustment, isLoading, isError } = useStockAdjustment(id)
  const postMutation = usePostStockAdjustment()
  const cancelMutation = useCancelStockAdjustment()
  const correctMutation = useCorrectStockAdjustment()
  const updateMutation = useUpdateStockAdjustment()

  const [confirmPost, setConfirmPost] = useState(false)
  const [cancelOpen, setCancelOpen] = useState(false)
  const [cancelReason, setCancelReason] = useState('')
  const [refusal, setRefusal] = useState<ApiErrorEnvelope | null>(null)

  if (isLoading) {
    return <div className={tokens.text.muted}>{t('common:status.loading')}</div>
  }

  // A failed fetch used to render the loading text forever, because the guard
  // keyed on `adjustment === undefined` rather than on the error.
  if (isError || adjustment === undefined) {
    return <p className={textColors.error}>{t('detail.loadFailed')}</p>
  }

  // NULL for a status this frontend does not know: every affordance below is
  // status-derived, so an unrecognised state must enable nothing.
  const status = toStockAdjustmentStatus(adjustment.status)
  const canPost = status === 'draft' && hasPermission('inventory.adjustments.post')
  const canCancel = status === 'draft' && hasPermission('inventory.adjustments.cancel')
  const canCorrect =
    status === 'posted' &&
    adjustment.corrects_adjustment_id === null &&
    adjustment.correction_id === null &&
    hasPermission('inventory.adjustments.create')

  const post = async (acknowledgeCode: AcknowledgeableRefusalCode | null): Promise<void> => {
    if (id === undefined) {
      return
    }

    try {
      await postMutation.mutateAsync({
        id,
        // ONLY the flag the operator actually confirmed (D15a.3). Sending both
        // silently disables the other guard AND forges a permanent header claim
        // that they overrode it.
        options: acknowledgeCode !== null ? overrideFlagFor(acknowledgeCode) : {},
      })
      setRefusal(null)
      setConfirmPost(false)
    } catch (error) {
      setConfirmPost(false)
      setRefusal(extractRefusal(error))
    }
  }

  /**
   * The RE-ANCHOR (§2's PATCH contract / D15a's persisted-draft branch).
   *
   * The operator authored a RESULTING QUANTITY, not a delta — "count it to 12"
   * expressed as "+2 from the 10 I can see". So re-anchoring preserves the
   * TARGET and recomputes the delta against the fresh reading:
   *
   *     target = observed_before + delta_quantity      (what they meant)
   *     delta_quantity = target − fresh_before         (how to get there now)
   *     observed_before = fresh_before
   *
   * Keeping the old delta and only moving the anchor would apply +2 on top of
   * the NEW value and land somewhere the operator never authored — precisely the
   * silent-wrong-number outcome the staleness guard exists to prevent.
   *
   * The server treats `lines` as a full replacement, and a draft line always has
   * `movement_id IS NULL`, so this cannot orphan a movement.
   */
  const reAnchor = async (staleLines: StaleLine[]): Promise<void> => {
    if (id === undefined) {
      return
    }

    const freshByKey = new Map(
      staleLines.map((line) => [
        refusalLineKey(line),
        line.quantity_before,
      ]),
    )

    try {
      await updateMutation.mutateAsync({
        id,
        input: {
          lines: adjustment.lines.map((line) => {
            const key = refusalLineKey(line)
            const freshBefore = freshByKey.get(key)
            // Guard, not cast: a reason this frontend cannot represent must not
            // be silently re-sent as if it were one it can.
            const reason = isAdjustmentReason(line.reason_code)
              ? line.reason_code
              : 'adjustment_negative'

            const base = {
              product_id: line.product_id,
              variant_id: line.variant_id,
              batch_uuid: line.batch_uuid,
              reason_code: reason,
              line_note: line.line_note,
            }

            // Untouched lines keep their own values verbatim.
            if (freshBefore === undefined) {
              return {
                ...base,
                delta_quantity: line.delta_quantity,
                observed_before: line.observed_before,
              }
            }

            // All string arithmetic — never a Number() round-trip on a quantity.
            const decimals = line.quantity_decimals
            const target = bcadd(line.observed_before, line.delta_quantity, decimals)

            return {
              ...base,
              delta_quantity: bcsub(target, freshBefore, decimals),
              observed_before: freshBefore,
            }
          }),
        },
      })
      setRefusal(null)
    } catch (error) {
      setRefusal(extractRefusal(error))
    }
  }

  /**
   * Cancel and correct route their failures through the SAME surface as post.
   * Without this, ADJUSTMENT_ALREADY_CORRECTED, CANNOT_CORRECT_A_CORRECTION and
   * INVALID_ADJUSTMENT_STATE — three codes the refusal map dutifully translates —
   * could never reach a user. `correct` also navigates to the contra draft it
   * creates, which is the whole point of pressing it.
   */
  const correct = async (): Promise<void> => {
    if (id === undefined) {
      return
    }

    try {
      const contra = await correctMutation.mutateAsync(id)
      setRefusal(null)
      void navigate(entityRoutes.stockAdjustment(contra.id))
    } catch (error) {
      setRefusal(extractRefusal(error))
    }
  }

  const cancel = async (): Promise<void> => {
    if (id === undefined) {
      return
    }

    try {
      await cancelMutation.mutateAsync({ id, reason: cancelReason })
      setRefusal(null)
      setCancelOpen(false)
    } catch (error) {
      setCancelOpen(false)
      setRefusal(extractRefusal(error))
    }
  }

  const lineColumns: DataTableColumn<StockAdjustmentLine>[] = [
    {
      key: 'product',
      header: t('line.product'),
      render: (line) => line.product_name ?? line.product_id,
    },
    { key: 'reason', header: t('line.reason'), render: (line) => t(`reason.${line.reason_code}`) },
    { key: 'lot', header: t('line.lot'), render: (line) => line.batch_number ?? '—' },
    {
      key: 'delta',
      align: 'right',
      header: t('line.quantity'),
      render: (line) => formatQuantity(line.delta_quantity, line.quantity_decimals),
    },
    {
      key: 'observed',
      align: 'right',
      header: t('line.observedBefore'),
      render: (line) => formatQuantity(line.observed_before, line.quantity_decimals),
    },
    {
      key: 'after',
      align: 'right',
      header: t('line.resultingQuantity'),
      render: (line) =>
        line.quantity_after === null
          ? '—'
          : formatQuantity(line.quantity_after, line.quantity_decimals),
    },
    {
      key: 'movement',
      header: t('detail.movement'),
      render: (line) => line.movement_id ?? '—',
    },
  ]

  const acknowledgeableCode =
    refusal?.code !== undefined && isAcknowledgeableRefusalCode(refusal.code) ? refusal.code : null
  const staleLines = refusal === null ? null : narrowStaleDetails(refusal.details)

  return (
    <div className="space-y-6">
      <PageHeader
        title={
          adjustment.adjustment_number === null
            ? t('detail.draftTitle')
            : t('detail.title', { number: adjustment.adjustment_number })
        }
        actions={
          <div className="flex items-center gap-2">
            {status !== null && <StockAdjustmentStatusBadge status={status} />}
            {canPost && (
              <Button
                variant="primary"
                onClick={() => {
                  setConfirmPost(true)
                }}
              >
                {t('detail.post')}
              </Button>
            )}
            {canCancel && (
              <Button
                variant="secondary"
                onClick={() => {
                  setCancelOpen(true)
                }}
              >
                {t('detail.cancel')}
              </Button>
            )}
            {canCorrect && (
              <Button
                variant="secondary"
                disabled={correctMutation.isPending}
                onClick={() => {
                  void correct()
                }}
              >
                {t('detail.correct')}
              </Button>
            )}
          </div>
        }
        className="mb-0"
      />

      <dl className="grid grid-cols-2 gap-x-6 gap-y-2 md:grid-cols-4">
        <SummaryRow label={t('detail.location')} value={adjustment.location_name ?? '—'} />
        <SummaryRow
          label={t('detail.occurredAt')}
          value={new Date(adjustment.occurred_at).toLocaleString()}
        />
        <SummaryRow label={t('detail.createdBy')} value={adjustment.created_by_name ?? '—'} />
        <SummaryRow label={t('detail.postedBy')} value={adjustment.posted_by_name ?? '—'} />
        {adjustment.cancellation_reason !== null && (
          <SummaryRow
            label={t('detail.cancellationReason')}
            value={adjustment.cancellation_reason}
          />
        )}
        {adjustment.corrects_adjustment_id !== null && (
          <SummaryRow label={t('detail.corrects')} value={adjustment.corrects_adjustment_id} />
        )}
        {adjustment.correction_id !== null && (
          <SummaryRow label={t('detail.correctedBy')} value={adjustment.correction_id} />
        )}
        {/* Overriding an integrity guard is never invisible. */}
        {adjustment.stale_acknowledged_at !== null && (
          <SummaryRow
            label={t('detail.staleAcknowledged')}
            value={new Date(adjustment.stale_acknowledged_at).toLocaleString()}
          />
        )}
        {adjustment.reservations_ignored_at !== null && (
          <SummaryRow
            label={t('detail.reservationsIgnored')}
            value={new Date(adjustment.reservations_ignored_at).toLocaleString()}
          />
        )}
      </dl>

      <DataTable
        columns={lineColumns}
        data={adjustment.lines}
        keyExtractor={(line) => line.id}
      />

      {refusal !== null && acknowledgeableCode === null && (
        <p className={textColors.error}>{t(refusalMessageKey(refusal.code))}</p>
      )}

      <ConfirmDialog
        isOpen={confirmPost}
        title={t('detail.postConfirmTitle')}
        message={t('detail.postConfirmBody')}
        confirmText={t('detail.post')}
        cancelText={t('common:actions.cancel')}
        variant="warning"
        isLoading={postMutation.isPending}
        onConfirm={() => {
          void post(null)
        }}
        onClose={() => {
          setConfirmPost(false)
        }}
      />

      {/* Canonical Modal + FormField + Textarea, not a hand-rolled tokens.modal.*
          block — the transfers detail page's inline modal is the anti-pattern
          this replaces. */}
      <Modal
        isOpen={cancelOpen}
        onClose={() => {
          setCancelOpen(false)
        }}
        size="sm"
      >
        <Modal.Header
          title={t('detail.cancelTitle')}
          onClose={() => {
            setCancelOpen(false)
          }}
        />
        <Modal.Content>
          <FormField label={t('detail.cancelReasonLabel')}>
            <Textarea
              value={cancelReason}
              onChange={(event) => {
                setCancelReason(event.target.value)
              }}
              rows={3}
            />
          </FormField>
        </Modal.Content>
        <Modal.Footer>
          <Button
            variant="ghost"
            onClick={() => {
              setCancelOpen(false)
            }}
          >
            {t('detail.cancelDismiss')}
          </Button>
          <Button
            variant="primary"
            disabled={cancelMutation.isPending}
            onClick={() => {
              void cancel()
            }}
          >
            {t('detail.cancelConfirm')}
          </Button>
        </Modal.Footer>
      </Modal>

      {acknowledgeableCode !== null && refusal !== null && (
        <AcknowledgeableRefusalDialog
          open
          code={acknowledgeableCode}
          refusal={refusal}
          // The document EXISTS, so the re-anchor is a real server-side PATCH.
          origin="persisted-draft"
          canOverride={hasPermission('inventory.adjustments.post')}
          busy={postMutation.isPending || updateMutation.isPending}
          onDismiss={() => {
            setRefusal(null)
          }}
          onApplyAnyway={() => {
            void post(acknowledgeableCode)
          }}
          // Re-anchor only means something for a staleness refusal. Offering it
          // for ADJUSTMENT_EXCEEDS_AVAILABLE produced a no-op PATCH that cleared
          // the dialog and read as success (gate M-4).
          onReAnchor={
            acknowledgeableCode === 'STOCK_MOVED_SINCE_AUTHORING' && staleLines !== null
              ? () => {
                  void reAnchor(staleLines)
                }
              : undefined
          }
        />
      )}
    </div>
  )
}

function SummaryRow({ label, value }: { label: string; value: string }) {
  return (
    <>
      <dt className={tokens.text.muted}>{label}</dt>
      <dd className={tokens.text.primary}>{value}</dd>
    </>
  )
}
