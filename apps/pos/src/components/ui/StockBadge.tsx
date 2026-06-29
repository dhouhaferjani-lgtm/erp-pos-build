import type { HTMLAttributes, ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * StockBadge — product stock state (En stock / Stock faible / Rupture).
 *
 * Uses the dedicated `stock-*` token family (NOT success/warning/danger): the
 * colour grammar reserves those for money/sync/errors. Visually green/amber/red
 * but semantically separate. See docs/design-language.md "Stock-badge
 * exception". Label text is supplied by the caller (i18n stays at the call
 * site). 14px text + AAA-targeted `-strong` ink for a glanceable read at a till.
 */
export type StockStatus = 'ok' | 'low' | 'out';

const WRAP: Record<StockStatus, string> = {
  ok: 'border-stock-ok/40 bg-stock-ok-surface text-stock-ok-strong',
  low: 'border-stock-low/40 bg-stock-low-surface text-stock-low-strong',
  out: 'border-stock-out/40 bg-stock-out-surface text-stock-out-strong',
};

const DOT: Record<StockStatus, string> = {
  ok: 'bg-stock-ok',
  low: 'bg-stock-low',
  out: 'bg-stock-out',
};

export interface StockBadgeProps extends HTMLAttributes<HTMLSpanElement> {
  status: StockStatus;
  children: ReactNode;
  /** Hide the leading status dot (e.g. when space is tight). */
  hideDot?: boolean;
}

export function StockBadge({ status, children, hideDot, className, ...rest }: StockBadgeProps) {
  return (
    <span
      data-status={status}
      className={cn(
        'inline-flex items-center gap-1.5 whitespace-nowrap rounded-pill border px-2.5 py-0.5 text-sm font-medium',
        WRAP[status],
        className,
      )}
      {...rest}
    >
      {!hideDot && <span className={cn('h-2 w-2 shrink-0 rounded-full', DOT[status])} aria-hidden />}
      {children}
    </span>
  );
}
