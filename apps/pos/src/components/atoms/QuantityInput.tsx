import { clampQuantityDecimals } from '@/lib/quantity';

// Copied VERBATIM from RequestRefillSheet.tsx:165 (POS semantic tokens — rule 18).
const INPUT_CLASSES =
  'mt-1 w-full rounded-ctl border border-border-subtle bg-surface-raised px-3 py-2 text-ink outline-none focus:border-action focus:ring-2 focus:ring-action';

interface QuantityInputProps {
  value: string;
  onChange: (value: string) => void;
  decimalPlaces: number | null | undefined;
  id?: string;
  invalid?: boolean;
  ariaLabel?: string;
}

/**
 * QuantityInput — precision-aware quantity input for apps/pos.
 *
 * Derives its input affordances (pattern / step / inputMode) from the product
 * unit's precision. Emits the RAW string on change (never a JS number), so the
 * canonical quantity string is preserved end-to-end.
 */
export function QuantityInput({ value, onChange, decimalPlaces, id, invalid, ariaLabel }: QuantityInputProps) {
  const dp = clampQuantityDecimals(decimalPlaces);
  const pattern = dp === 0 ? '^\\d+$' : `^\\d+(\\.\\d{1,${dp}})?$`;
  const step = dp === 0 ? '1' : `0.${'0'.repeat(dp - 1)}1`;
  return (
    <input
      id={id}
      type="text"
      inputMode={dp === 0 ? 'numeric' : 'decimal'}
      pattern={pattern}
      step={step}
      value={value}
      aria-invalid={invalid || undefined}
      aria-label={ariaLabel}
      onChange={(e) => onChange(e.target.value)}
      className={INPUT_CLASSES}
    />
  );
}
