import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Tabs — underline tab strip for the product-detail sheet
 * (Description / Ingrédients / Équivalents / Compléments / Routine) and similar.
 * Distinct from `SegmentedControl` (pill "choose-one"): Tabs read as document
 * sections with an accent underline on the active tab. 44px touch height.
 */
export interface TabItem<T extends string> {
  id: T;
  label: ReactNode;
  /** Optional trailing count (e.g. number of équivalents). */
  count?: number;
}

export interface TabsProps<T extends string> {
  tabs: TabItem<T>[];
  value: T;
  onChange: (id: T) => void;
  ariaLabel?: string;
  className?: string;
}

export function Tabs<T extends string>({ tabs, value, onChange, ariaLabel, className }: TabsProps<T>) {
  return (
    <div
      role="tablist"
      aria-label={ariaLabel}
      className={cn('flex gap-1 border-b border-border-subtle', className)}
    >
      {tabs.map((tab) => {
        const active = tab.id === value;
        return (
          <button
            key={tab.id}
            type="button"
            role="tab"
            aria-selected={active}
            onClick={() => onChange(tab.id)}
            className={cn(
              'relative -mb-px inline-flex min-h-11 items-center gap-1.5 border-b-2 px-3.5 text-sm font-semibold transition-colors',
              active
                ? 'border-accent text-ink-strong'
                : 'border-transparent text-ink-muted hover:text-ink',
            )}
          >
            {tab.label}
            {typeof tab.count === 'number' && (
              <span
                className={cn(
                  'rounded-pill px-1.5 text-xs tabular-nums',
                  active ? 'bg-accent-tint text-accent-strong' : 'bg-surface-sunken text-ink-muted',
                )}
              >
                {tab.count}
              </span>
            )}
          </button>
        );
      })}
    </div>
  );
}
