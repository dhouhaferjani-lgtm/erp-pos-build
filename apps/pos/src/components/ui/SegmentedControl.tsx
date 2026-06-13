import { cn } from '@/lib/utils';
import type { ReactNode } from 'react';

/**
 * SegmentedControl — the single "choose-one" control voice (display mode,
 * timeout presets, view toggles). Use this everywhere a small set of mutually
 * exclusive options is picked; never hand-roll a second segmented/chip group.
 */
export interface SegmentedOption<T extends string> {
  value: T;
  label?: ReactNode;
  icon?: ReactNode;
  /** Accessible name when the option is icon-only. */
  ariaLabel?: string;
}

export interface SegmentedControlProps<T extends string> {
  options: SegmentedOption<T>[];
  value: T;
  onChange: (value: T) => void;
  /** Touch height for each segment. md = 44px default. */
  size?: 'sm' | 'md';
  className?: string;
  ariaLabel?: string;
}

export function SegmentedControl<T extends string>({
  options,
  value,
  onChange,
  size = 'md',
  className,
  ariaLabel,
}: SegmentedControlProps<T>) {
  const segHeight = size === 'sm' ? 'min-h-9' : 'min-h-11';
  return (
    <div
      role="radiogroup"
      aria-label={ariaLabel}
      className={cn('inline-flex rounded-lg bg-surface-sunken p-1', className)}
    >
      {options.map((opt) => {
        const active = opt.value === value;
        return (
          <button
            key={opt.value}
            type="button"
            role="radio"
            aria-checked={active}
            aria-label={opt.ariaLabel}
            onClick={() => onChange(opt.value)}
            className={cn(
              'inline-flex flex-1 items-center justify-center gap-1.5 rounded-md px-3 text-sm font-medium transition-colors',
              segHeight,
              active
                ? 'bg-surface-raised text-ink shadow-sm'
                : 'text-ink-muted hover:text-ink',
            )}
          >
            {opt.icon}
            {opt.label}
          </button>
        );
      })}
    </div>
  );
}
