import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * KpiCard — a single headline metric for the Rapports / Caisse-Shift pages
 * (Ventes, Transactions, Panier moyen). Value is mono+tabular for numeric reads.
 */
export interface KpiCardProps {
  label: ReactNode;
  value: ReactNode;
  /** Optional secondary line (e.g. period, sublabel). */
  hint?: ReactNode;
  icon?: ReactNode;
  className?: string;
}

export function KpiCard({ label, value, hint, icon, className }: KpiCardProps) {
  return (
    <div
      className={cn(
        'flex flex-col gap-1 rounded-card border border-border-subtle bg-surface-raised p-4 shadow-sm',
        className,
      )}
    >
      <div className="flex items-center justify-between">
        <span className="text-sm font-medium text-ink-muted">{label}</span>
        {icon && <span className="text-ink-faint">{icon}</span>}
      </div>
      <span className="font-mono text-2xl font-bold tabular-nums text-ink-strong">{value}</span>
      {hint && <span className="text-xs text-ink-faint">{hint}</span>}
    </div>
  );
}
