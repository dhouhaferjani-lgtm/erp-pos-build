import { X } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Pill — the chip voice used for category selection (multi-select toggle) and
 * active filter chips (removable). `selected` highlights with the accent tint;
 * pass `onRemove` to show the ✕ (filter-chip mode). 44px touch height.
 */
export interface PillProps {
  children: ReactNode;
  selected?: boolean;
  onClick?: () => void;
  /** When provided, renders a removable ✕ (filter-chip). */
  onRemove?: () => void;
  removeLabel?: string;
  className?: string;
}

export function Pill({ children, selected, onClick, onRemove, removeLabel = 'Retirer', className }: PillProps) {
  return (
    <span
      className={cn(
        'inline-flex min-h-11 items-center gap-1.5 rounded-pill border px-3.5 text-sm font-medium transition-colors',
        selected
          ? 'border-accent bg-accent-tint text-accent-strong'
          : 'border-border-subtle bg-surface-raised text-ink-muted',
        className,
      )}
    >
      {onClick ? (
        <button
          type="button"
          onClick={onClick}
          aria-pressed={selected}
          className="inline-flex items-center gap-1.5"
        >
          {children}
        </button>
      ) : (
        children
      )}
      {onRemove && (
        <button
          type="button"
          aria-label={removeLabel}
          onClick={onRemove}
          className="-mr-1 flex h-6 w-6 items-center justify-center rounded-full hover:bg-surface-sunken"
        >
          <X className="h-3.5 w-3.5" />
        </button>
      )}
    </span>
  );
}
