import { useTranslation } from 'react-i18next'
import { Button } from '@/components/atoms/Button'
import { DataTable, type DataTableColumn } from '@/components/molecules/DataTable/DataTable'
import { Modal } from '@/components/organisms/Modal'
import { bcadd, formatQuantity } from '@/lib/decimal'
import { semanticColorTokens as tokens } from '@/lib/designTokens'
import { refusalLineKey as keyOf } from '../lib/refusalLineKey'
import {
  narrowAvailabilityDetails,
  narrowStaleDetails,
  refusalMessageKey,
  type AcknowledgeableRefusalCode,
  type ApiErrorEnvelope,
  type StaleLine,
} from '../api/refusals'

/**
 * Where the refusal happened, and therefore what recovery is possible.
 *
 * NOT keyed by which page raised it: the branch is a fact about PERSISTENCE.
 * An immediate-post refusal rolls its draft back with the transaction, so there
 * is nothing to PATCH and `line_id` is null; only a document that already exists
 * can be re-anchored server-side.
 */
export type RefusalOrigin = 'persisted-draft' | 'unsaved-form'

interface Props {
  open: boolean
  code: AcknowledgeableRefusalCode
  refusal: ApiErrorEnvelope
  origin: RefusalOrigin
  /** Gated on `inventory.adjustments.post` — overriding a guard is a posting act. */
  canOverride: boolean
  busy?: boolean
  /**
   * The operator's authored delta per `(product, variant, lot)`, so the table can
   * show what they asked for and what it would produce. Optional: the payload
   * itself does not carry it, and a missing entry renders an em dash rather than
   * a guess.
   */
  deltaByKey?: Record<string, string> | undefined
  onDismiss: () => void
  /** Re-submit with `acknowledge_stale` / `ignore_reservations`. NEVER automatic. */
  onApplyAnyway: () => void
  /**
   * persisted-draft: PATCH the draft. unsaved-form: recompute client-side.
   *
   * OPTIONAL: re-anchoring only means something for a staleness refusal. For
   * ADJUSTMENT_EXCEEDS_AVAILABLE there is nothing to re-anchor to, and offering
   * it produced a no-op that cleared the dialog and read as success — so the
   * caller omits it and the button disappears.
   */
  onReAnchor?: (() => void) | undefined
}

/**
 * ONE dialog for BOTH acknowledgeable refusals (DPA V7 / F6).
 *
 * Two explicit actions, no default, and NO auto-retry: a guard the operator did
 * not knowingly override is a guard that silently does nothing.
 *
 * Every quantity is formatted with the product unit's precision taken from the
 * REFUSAL PAYLOAD itself — the server sends `quantity_decimals` on every
 * quantity-bearing code precisely so this component never needs a literal scale,
 * which is what the quantity-display ratchet forbids. (The detail page cannot
 * make the StockLevelData join the quick modal can, so the join is not an
 * option here.)
 */
export function AcknowledgeableRefusalDialog({
  open,
  code,
  refusal,
  origin,
  canOverride,
  busy = false,
  deltaByKey,
  onDismiss,
  onApplyAnyway,
  onReAnchor,
}: Props) {
  const { t } = useTranslation('stock-adjustments')

  const staleLines = code === 'STOCK_MOVED_SINCE_AUTHORING' ? narrowStaleDetails(refusal.details) : null

  const staleColumns: DataTableColumn<StaleLine>[] = [
    {
      key: 'product',
      header: t('refusal.table.product'),
      render: (line) => line.product_id,
    },
    {
      key: 'lot',
      header: t('refusal.table.lot'),
      render: (line) => line.batch_uuid ?? '—',
    },
    {
      key: 'observed',
      align: 'right',
      header: t('refusal.table.observedBefore'),
      render: (line) => formatQuantity(line.observed_before, line.quantity_decimals),
    },
    {
      key: 'current',
      align: 'right',
      header: t('refusal.table.quantityBefore'),
      render: (line) => formatQuantity(line.quantity_before, line.quantity_decimals),
    },
    {
      key: 'delta',
      align: 'right',
      header: t('refusal.table.delta'),
      render: (line) => {
        const delta = deltaByKey?.[keyOf(line)]
        return delta === undefined ? '—' : formatQuantity(delta, line.quantity_decimals)
      },
    },
    {
      // The resulting ON-HAND. Distinct from the availability block's resulting
      // AVAILABLE below: the two are different quantities and used to share one
      // label, which made the number ambiguous on the one screen whose job is to
      // explain a refusal.
      key: 'resulting',
      align: 'right',
      header: t('refusal.table.resultingOnHand'),
      render: (line) => {
        const delta = deltaByKey?.[keyOf(line)]
        return delta === undefined
          ? '—'
          : formatQuantity(
              bcadd(line.quantity_before, delta, line.quantity_decimals),
              line.quantity_decimals,
            )
      },
    },
  ]
  const availability =
    code === 'ADJUSTMENT_EXCEEDS_AVAILABLE' ? narrowAvailabilityDetails(refusal.details) : null

  return (
    <Modal isOpen={open} onClose={onDismiss} size="lg">
      <Modal.Header title={t('refusal.title')} onClose={onDismiss} />
      <Modal.Content>
        <p className={tokens.text.secondary}>{t(refusalMessageKey(code))}</p>

        {staleLines !== null && (
          <div className="mt-4">
            <DataTable
              columns={staleColumns}
              data={staleLines}
              // Keyed on (product, variant, lot) — NEVER on line_id, which is
              // null whenever nothing was persisted (D15a).
              keyExtractor={keyOf}
            />
          </div>
        )}

        {availability !== null && (
          <dl className="mt-4 grid grid-cols-2 gap-2 text-sm">
            <dt className={tokens.text.muted}>{t('refusal.table.quantityBefore')}</dt>
            <dd className="text-right tabular-nums">
              {formatQuantity(availability.quantity_before, availability.quantity_decimals)}
            </dd>
            {/* `reserved` and `available` are the WHOLE explanation of this
                refusal — on-hand alone would have allowed the change. Labelling
                `available` as "As observed" made the one screen whose job is to
                explain a refusal say the wrong thing. */}
            <dt className={tokens.text.muted}>{t('refusal.table.reserved')}</dt>
            <dd className="text-right tabular-nums">
              {formatQuantity(availability.reserved, availability.quantity_decimals)}
            </dd>
            <dt className={tokens.text.muted}>{t('refusal.table.available')}</dt>
            <dd className="text-right tabular-nums">
              {formatQuantity(availability.available, availability.quantity_decimals)}
            </dd>
            <dt className={tokens.text.muted}>{t('refusal.table.delta')}</dt>
            <dd className="text-right tabular-nums">
              {formatQuantity(availability.delta_quantity, availability.quantity_decimals)}
            </dd>
            <dt className={tokens.text.muted}>{t('refusal.table.resultingAvailable')}</dt>
            <dd className="text-right tabular-nums">
              {formatQuantity(
                bcadd(availability.available, availability.delta_quantity, availability.quantity_decimals),
                availability.quantity_decimals,
              )}
            </dd>
          </dl>
        )}
      </Modal.Content>
      <Modal.Footer>
        <Button variant="ghost" onClick={onDismiss} disabled={busy}>
          {t('refusal.dismiss')}
        </Button>
        {onReAnchor !== undefined && (
          <Button variant="secondary" onClick={onReAnchor} disabled={busy}>
            {origin === 'persisted-draft' ? t('refusal.reAnchor') : t('refusal.recompute')}
          </Button>
        )}
        {canOverride && (
          <Button variant="primary" onClick={onApplyAnyway} disabled={busy}>
            {t('refusal.applyAnyway')}
          </Button>
        )}
      </Modal.Footer>
    </Modal>
  )
}
