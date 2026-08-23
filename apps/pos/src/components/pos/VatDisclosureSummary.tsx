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
  /** i18n key prefix — `reports` on the X/Z modals, `reports.endOfDay` on EOD. */
  keyPrefix: string;
}

/**
 * B-6(ii) / Option A1 — the three-line VAT disclosure.
 *
 * Renders ONLY when the shift actually took refund VAT. On a refund-free shift
 * the sale-only headline and the net table are equal by construction (the F-4
 * tripwire asserts it exactly), so the caller keeps its single historical VAT
 * figure and this component renders nothing — no screen changes for the common
 * case, and the three lines mean something when they do appear.
 */
export function VatDisclosureSummary({
  disclosure,
  format,
  masked = false,
  keyPrefix,
}: VatDisclosureSummaryProps) {
  const { t } = useTranslation('pos');

  if (!disclosure.hasRefundVat) {
    return null;
  }

  const show = (amount: string) => (masked ? '—' : format(amount));

  return (
    <div className="rounded-tile bg-surface-sunken px-4 py-3">
      <div className="flex justify-between text-sm text-ink-muted">
        <span>{t(`${keyPrefix}.vatOnSales`)}</span>
        <span className="tabular-nums">{show(disclosure.salesVat)}</span>
      </div>
      <div className="flex justify-between text-sm text-ink-muted">
        <span>{t(`${keyPrefix}.vatOnRefunds`)}</span>
        <span className="tabular-nums">{masked ? '—' : `-${format(disclosure.refundVat)}`}</span>
      </div>
      <div className="mt-1 flex justify-between border-t border-border-subtle pt-1 text-sm font-semibold text-ink">
        <span>{t(`${keyPrefix}.netVat`)}</span>
        <span className="tabular-nums">{show(disclosure.netVat)}</span>
      </div>
      {!disclosure.isReconciled && (
        // Surfaced, never hidden: the three figures do not add up on this
        // shift, so the screen says so rather than implying they do.
        <p className="mt-2 text-xs text-warning-strong">{t(`${keyPrefix}.vatUnreconciled`)}</p>
      )}
    </div>
  );
}
