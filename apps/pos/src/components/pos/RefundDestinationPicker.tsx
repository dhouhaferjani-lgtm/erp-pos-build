/**
 * RefundDestinationPicker
 *
 * Shown at the refund-confirm step when the cart net total is negative
 * (the customer is owed money). Renders three destination options:
 * Original Payment / Cash / Store Voucher.
 *
 * Server-side gating seam:
 *   The `allowedDestinations` prop is the plug-in point for backend gating.
 *   When the server attaches `allowed_destinations` to the refund-validate API
 *   response, the parent component should pass that value here. Until that wiring
 *   lands (deferred — see API exception handler follow-up), omit the prop to show
 *   all three as enabled.
 *
 * Default selection (spec §6.3):
 *   OriginalPayment if it's in allowedDestinations (or all are allowed),
 *   else StoreVoucher,
 *   else Cash.
 */

import { useState } from 'react';
import { useTranslation } from 'react-i18next';

// ─── Types ────────────────────────────────────────────────────────────────────

export type RefundDestination = 'original' | 'cash' | 'store_voucher';

export interface ProrationRow {
  /** Human-readable instrument label (e.g. "Card •••• 1234", "Cash") */
  instrument: string;
  /** Formatted amount string with currency symbol, e.g. "12.50 EUR" */
  amount: string;
}

export interface RefundDestinationPickerProps {
  /**
   * Subset of destinations the server permits for this refund.
   * When undefined, all three destinations are enabled.
   * Seam for future API gating: pass `response.data.allowed_destinations` here.
   */
  allowedDestinations?: RefundDestination[];

  /**
   * Per-tender proration breakdown for the Original Payment option.
   * Rendered below the radio when the option is selected (or always, when provided).
   * Optional — the picker renders gracefully when absent.
   */
  prorationBreakdown?: ProrationRow[];

  /** Currently selected destination. */
  value: RefundDestination;

  /** Called whenever the user picks a different option. */
  onChange: (destination: RefundDestination) => void;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Returns the priority-ordered default destination given the allowed set.
 * Spec §6.3: OriginalPayment → StoreVoucher → Cash.
 */
export function defaultDestination(
  allowedDestinations: RefundDestination[] | undefined,
): RefundDestination {
  const all: RefundDestination[] = ['original', 'store_voucher', 'cash'];
  if (allowedDestinations === undefined) return 'original';

  for (const d of all) {
    if (allowedDestinations.includes(d)) return d;
  }
  // Fallback: nothing allowed (shouldn't happen in practice — server guards this)
  return 'original';
}

// ─── Component ────────────────────────────────────────────────────────────────

const DESTINATION_ORDER: RefundDestination[] = ['original', 'cash', 'store_voucher'];

export function RefundDestinationPicker({
  allowedDestinations,
  prorationBreakdown,
  value,
  onChange,
}: RefundDestinationPickerProps) {
  const { t } = useTranslation('pos');

  const labelFor = (d: RefundDestination): string => {
    switch (d) {
      case 'original':
        return t('refundFlow.destination.original', { defaultValue: 'Original Payment' });
      case 'cash':
        return t('refundFlow.destination.cash', { defaultValue: 'Cash' });
      case 'store_voucher':
        return t('refundFlow.destination.store_voucher', { defaultValue: 'Store Voucher' });
    }
  };

  // When allowedDestinations is undefined, all are enabled.
  const isAllowed = (d: RefundDestination): boolean =>
    allowedDestinations === undefined || allowedDestinations.includes(d);

  const visibleDestinations =
    allowedDestinations === undefined
      ? DESTINATION_ORDER
      : DESTINATION_ORDER.filter((d) => allowedDestinations.includes(d));

  return (
    <div className="space-y-3" data-testid="refund-destination-picker">
      <p className="text-sm font-medium text-gray-700">
        {t('refundFlow.destination.label', { defaultValue: 'Refund destination' })}
      </p>

      <div role="radiogroup" aria-label={t('refundFlow.destination.label', { defaultValue: 'Refund destination' })} className="space-y-2">
        {DESTINATION_ORDER.map((d) => {
          const allowed = isAllowed(d);
          const visible = allowedDestinations === undefined || visibleDestinations.includes(d);
          if (!visible) return null;

          return (
            <label
              key={d}
              data-testid={`refund-destination-option-${d}`}
              className={[
                'flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors',
                allowed
                  ? value === d
                    ? 'border-blue-500 bg-blue-50'
                    : 'border-gray-200 hover:border-gray-300'
                  : 'cursor-not-allowed border-gray-200 opacity-50',
              ].join(' ')}
            >
              <input
                type="radio"
                name="refund-destination"
                value={d}
                checked={value === d}
                disabled={!allowed}
                onChange={() => {
                  if (allowed) onChange(d);
                }}
                className="mt-0.5"
                data-testid={`refund-destination-radio-${d}`}
              />
              <span className="text-sm font-medium text-gray-900">{labelFor(d)}</span>
            </label>
          );
        })}
      </div>

      {/* Proration breakdown for Original Payment — shown when provided */}
      {prorationBreakdown !== undefined && prorationBreakdown.length > 0 && value === 'original' && (
        <div
          data-testid="proration-breakdown"
          className="rounded-md border border-blue-200 bg-blue-50 p-3 text-sm"
        >
          <p className="mb-2 font-medium text-blue-800">
            {t('refundFlow.destination.proration_title', { defaultValue: 'Refund breakdown' })}
          </p>
          <ul className="space-y-1">
            {prorationBreakdown.map((row, idx) => (
              <li
                key={idx}
                className="flex justify-between text-blue-900"
                data-testid={`proration-row-${String(idx)}`}
              >
                <span>{row.instrument}</span>
                <span className="font-medium">{row.amount}</span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}

/**
 * Stateful wrapper that owns the selected destination and provides the
 * correctly defaulted initial value. Convenience for use in modals that
 * need to know the current selection without lifting state.
 */
export function RefundDestinationPickerStateful({
  allowedDestinations,
  prorationBreakdown,
  onConfirm,
}: {
  allowedDestinations?: RefundDestination[];
  prorationBreakdown?: ProrationRow[];
  onConfirm: (destination: RefundDestination) => void;
}) {
  const { t } = useTranslation('pos');
  const [selected, setSelected] = useState<RefundDestination>(
    defaultDestination(allowedDestinations),
  );

  return (
    <div className="space-y-4">
      <RefundDestinationPicker
        allowedDestinations={allowedDestinations}
        prorationBreakdown={prorationBreakdown}
        value={selected}
        onChange={setSelected}
      />
      <button
        type="button"
        onClick={() => onConfirm(selected)}
        data-testid="refund-destination-confirm"
        className="w-full rounded-md bg-blue-600 py-2 text-sm font-semibold text-white hover:bg-blue-700"
      >
        {t('refundFlow.destination.confirm', { defaultValue: 'Confirm destination' })}
      </button>
    </div>
  );
}
