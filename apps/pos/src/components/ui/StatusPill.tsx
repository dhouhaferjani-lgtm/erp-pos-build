import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

/**
 * StatusPill — the session/connectivity status indicator. A single clean,
 * consistent pill shape (dot + label) used for the sync/online state.
 *
 * It is a STATUS INDICATOR, not a control — keep action buttons (e.g. the
 * manual sync trigger) as separate IconButtons beside it, so the pill never
 * becomes an odd nested-button shape.
 */
export type StatusTone = 'healthy' | 'warning' | 'danger';

const TONE: Record<StatusTone, { pill: string; dot: string }> = {
  healthy: { pill: 'bg-success-surface text-success-strong', dot: 'bg-success' },
  warning: { pill: 'bg-warning-surface text-warning-strong', dot: 'bg-warning' },
  danger: { pill: 'bg-danger-surface text-danger-strong', dot: 'bg-danger' },
};

export interface StatusPillProps extends HTMLAttributes<HTMLDivElement> {
  tone: StatusTone;
  label: string;
  /** Pulse the dot (e.g. while syncing). */
  pulse?: boolean;
}

export function StatusPill({ tone, label, pulse = false, className, ...rest }: StatusPillProps) {
  return (
    <div
      role="status"
      className={cn(
        'inline-flex h-8 min-w-0 items-center gap-2 rounded-full px-3 text-sm font-medium',
        TONE[tone].pill,
        className,
      )}
      {...rest}
    >
      <span
        className={cn(
          'inline-block h-2 w-2 shrink-0 rounded-full',
          TONE[tone].dot,
          pulse && 'animate-pulse',
        )}
        aria-hidden="true"
      />
      <span className="truncate">{label}</span>
    </div>
  );
}
