import { useTranslation } from 'react-i18next';
import type { VatDisclosure } from '@/lib/reports/vatDisclosure';

interface VatDisclosureSummaryProps {
  disclosure: VatDisclosure;
  format: (amount: string) => string;
  /**
   * Blind-count / concealment mask. When true every amount renders as an em
   * dash. B-13 cross-check (scoping doc §3.3): VAT is not a tender figure, so
   * this block does not widen the blind-count leak — but it MUST honour the
   * same mask the surface around it uses, because B-13(i) established that a
   * concealed cash figure can be arithmetically re-derived from visible
   * siblings, and these are new siblings.
   */
  masked?: boolean;
  /**
   * Which of the two POS report namespaces the labels come from — `reports` on
   * the X/Z modals, `reports.endOfDay` on the EOD modal.
   *
   * gate r1 m-1: a TYPED UNION, not a free string, and every `t()` call below is
   * a LITERAL key rather than a template. Runtime-composed keys are invisible to
   * static extraction, and POS has no key-existence audit to catch a typo.
   */
  keyPrefix: 'reports' | 'reports.endOfDay';
}

/** Literal key sets per surface — statically greppable, exhaustively typed. */
const LABEL_KEYS = {
  reports: {
    vatOnSales: 'reports.vatOnSales',
    vatOnRefunds: 'reports.vatOnRefunds',
    netVat: 'reports.netVat',
    vatUnreconciled: 'reports.vatUnreconciled',
  },
  'reports.endOfDay': {
    vatOnSales: 'reports.endOfDay.vatOnSales',
    vatOnRefunds: 'reports.endOfDay.vatOnRefunds',
    netVat: 'reports.endOfDay.netVat',
    vatUnreconciled: 'reports.endOfDay.vatUnreconciled',
  },
} as const;

/**
 * B-6(ii) / Option A1 — the VAT disclosure block.
 *
 * Renders in two situations, and is silent otherwise:
 *
 * 1. **The shift took refund VAT.** Three lines — sale-only, refund, net — so
 *    the headline and the per-rate table visibly bridge instead of contradicting.
 * 2. **The figures do not reconcile.** The two real figures plus a warning, and
 *    NO refund line, because there is no refund magnitude to state.
 *
 * On a clean refund-free shift the sale-only headline and the net table are
 * equal by construction (the F-4 tripwire asserts it exactly), so the caller
 * keeps its single historical VAT figure and this renders nothing.
 *
 * GATE r1 F-1/B-1: case 2 used to be unreachable. The guard was
 * `if (!hasRefundVat) return null`, and the derivation made
 * `!isReconciled ⟹ !hasRefundVat`, so the anomaly branch could never render on
 * any input — the modal fell back to the bare sale-only `tax_amount` beside a
 * net table, i.e. the exact defect this lane exists to close, undisclosed. The
 * guard now tests BOTH flags and the derivation keeps them independent.
 */
export function VatDisclosureSummary({
  disclosure,
  format,
  masked = false,
  keyPrefix,
}: VatDisclosureSummaryProps) {
  const { t } = useTranslation('pos');

  if (!disclosure.hasRefundVat && disclosure.isReconciled) {
    return null;
  }

  const show = (amount: string) => (masked ? '—' : format(amount));
  const keys = LABEL_KEYS[keyPrefix];

  return (
    <div className="rounded-tile bg-surface-sunken px-4 py-3">
      <div className="flex justify-between text-sm text-ink-muted">
        <span>{t(keys.vatOnSales)}</span>
        <span className="tabular-nums">{show(disclosure.salesVat)}</span>
      </div>
      {/* Only when there is a magnitude to state. Rendering "-0.00" in the
          anomaly case would assert a refund that did not happen. */}
      {disclosure.hasRefundVat && (
        <div className="flex justify-between text-sm text-ink-muted">
          <span>{t(keys.vatOnRefunds)}</span>
          <span className="tabular-nums">{masked ? '—' : `-${format(disclosure.refundVat)}`}</span>
        </div>
      )}
      <div className="mt-1 flex justify-between border-t border-border-subtle pt-1 text-sm font-semibold text-ink">
        <span>{t(keys.netVat)}</span>
        <span className="tabular-nums">{show(disclosure.netVat)}</span>
      </div>
      {!disclosure.isReconciled && (
        // Surfaced, never hidden: the figures on this shift do not add up, so
        // the screen says so rather than implying they do.
        <p className="mt-2 text-xs text-warning-strong">{t(keys.vatUnreconciled)}</p>
      )}
    </div>
  );
}
