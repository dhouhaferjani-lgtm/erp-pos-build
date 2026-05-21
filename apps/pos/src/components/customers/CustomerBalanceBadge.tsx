import { AlertTriangle } from 'lucide-react';
import { useCurrency } from '@/lib/currency';

export interface CustomerBalanceBadgeProps {
  receivableBalance: string;
  creditBalance: string;
  balanceUpdatedAt: string | null;
  stale: boolean;
}

export function CustomerBalanceBadge({
  receivableBalance,
  creditBalance,
  balanceUpdatedAt,
  stale,
}: CustomerBalanceBadgeProps) {
  const { format } = useCurrency();

  return (
    <div className="flex flex-wrap items-center gap-1.5 text-xs">
      <span className="rounded-md border border-amber-200 bg-amber-50 px-2 py-1 font-semibold text-amber-900">
        Due {format(receivableBalance)}
      </span>
      <span className="rounded-md border border-emerald-200 bg-emerald-50 px-2 py-1 font-semibold text-emerald-900">
        Credit {format(creditBalance)}
      </span>
      <span
        aria-label={stale ? 'Customer balance is stale' : 'Customer balance is fresh'}
        title={balanceUpdatedAt ?? undefined}
        className={
          stale
            ? 'inline-flex items-center gap-1 rounded-md border border-red-200 bg-red-50 px-2 py-1 font-semibold text-red-700'
            : 'rounded-md border border-gray-200 bg-gray-50 px-2 py-1 font-semibold text-gray-600'
        }
      >
        {stale && <AlertTriangle className="h-3.5 w-3.5" aria-hidden="true" />}
        {stale ? 'Stale' : 'Fresh'}
      </span>
    </div>
  );
}
