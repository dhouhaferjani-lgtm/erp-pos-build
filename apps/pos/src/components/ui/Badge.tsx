import type { HTMLAttributes, ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Badge — small status/label pill. One shape for every badge in the app
 * (terminal name, shift number, stock status, counts). Tone encodes the
 * color grammar.
 */
export type BadgeTone = 'neutral' | 'success' | 'warning' | 'danger' | 'action';

const TONE: Record<BadgeTone, string> = {
  neutral: 'border-border-subtle bg-surface-sunken text-ink-muted',
  success: 'border-success-subtle bg-success-surface text-success-strong',
  warning: 'border-warning-subtle bg-warning-surface text-warning-strong',
  danger: 'border-danger-subtle bg-danger-surface text-danger-strong',
  action: 'border-action bg-action-subtle text-action-strong',
};

export interface BadgeProps extends HTMLAttributes<HTMLSpanElement> {
  tone?: BadgeTone;
  children: ReactNode;
}

export function Badge({ tone = 'neutral', className, children, ...rest }: BadgeProps) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 whitespace-nowrap rounded-pill border px-2.5 py-0.5 text-xs font-medium tabular-nums',
        TONE[tone],
        className,
      )}
      {...rest}
    >
      {children}
    </span>
  );
}
