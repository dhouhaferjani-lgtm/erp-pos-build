import { cn } from '@/lib/utils';

/**
 * BreakdownBar — a single labelled proportion row for the payment-method
 * breakdown (Espèces / Carte / Chèque / Mixte) in Rapports. Token-driven fill
 * colour. `pct` is 0–100; caller computes it (precision-safe upstream).
 */
export interface BreakdownBarProps {
  label: string;
  /** Right-aligned formatted amount/value text. */
  valueText: string;
  /** Fill percentage 0–100. */
  pct: number;
  /** Tailwind bg token class for the fill (default accent). */
  fillClass?: string;
  className?: string;
}

export function BreakdownBar({ label, valueText, pct, fillClass = 'bg-accent', className }: BreakdownBarProps) {
  const clamped = Math.max(0, Math.min(100, pct));
  return (
    <div className={cn('flex flex-col gap-1', className)}>
      <div className="flex items-baseline justify-between text-sm">
        <span className="font-medium text-ink">{label}</span>
        <span className="font-mono tabular-nums text-ink-muted">{valueText}</span>
      </div>
      <div
        className="h-2 w-full overflow-hidden rounded-pill bg-surface-sunken"
        role="progressbar"
        aria-valuenow={Math.round(clamped)}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-label={label}
      >
        <div className={cn('h-full rounded-pill', fillClass)} style={{ width: `${clamped}%` }} />
      </div>
    </div>
  );
}
