import { Minus, Plus } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Stepper — touch −/＋ quantity control. 48px touch-floor keys (ergonomics §6),
 * value read in the middle. The value area can itself be tappable (opens the
 * numpad) via `onValueClick`. Decrement disables at `min`.
 */
export interface StepperProps {
  value: number;
  onDecrement: () => void;
  onIncrement: () => void;
  onValueClick?: () => void;
  min?: number;
  disabled?: boolean;
  /** Rendered value (e.g. formatted quantity). Defaults to the number. */
  display?: string;
  decrementLabel?: string;
  incrementLabel?: string;
  className?: string;
}

export function Stepper({
  value,
  onDecrement,
  onIncrement,
  onValueClick,
  min = 0,
  disabled,
  display,
  decrementLabel = 'Diminuer',
  incrementLabel = 'Augmenter',
  className,
}: StepperProps) {
  const atMin = value <= min;
  const keyCls =
    'flex h-12 w-12 shrink-0 items-center justify-center rounded-ctl border border-border-subtle bg-surface-raised text-ink transition-colors hover:bg-surface-sunken active:scale-95 disabled:cursor-not-allowed disabled:opacity-40';
  return (
    <div className={cn('inline-flex items-center gap-2', className)}>
      <button
        type="button"
        aria-label={decrementLabel}
        onClick={onDecrement}
        disabled={disabled || atMin}
        className={keyCls}
      >
        <Minus className="h-5 w-5" />
      </button>
      <button
        type="button"
        onClick={onValueClick}
        disabled={disabled || !onValueClick}
        aria-label={onValueClick ? 'Modifier la quantité' : undefined}
        className={cn(
          'min-w-12 px-2 text-center font-mono text-lg font-semibold text-ink tabular-nums',
          onValueClick && 'rounded-ctl hover:bg-surface-sunken',
        )}
      >
        {display ?? value}
      </button>
      <button
        type="button"
        aria-label={incrementLabel}
        onClick={onIncrement}
        disabled={disabled}
        className={keyCls}
      >
        <Plus className="h-5 w-5" />
      </button>
    </div>
  );
}
