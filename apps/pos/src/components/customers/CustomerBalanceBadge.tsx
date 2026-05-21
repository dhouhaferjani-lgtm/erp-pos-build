import { AlertTriangle } from 'lucide-react';
import { useCurrency } from '@/lib/currency';
import { bccomp, bcformat, bcsub } from '@/lib/decimal';

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
  const { decimals, format } = useCurrency();
  const rawNetDue = bcsub(receivableBalance, creditBalance, decimals);
  const netDue = bccomp(rawNetDue, '0') < 0 ? bcformat('0', decimals) : rawNetDue;
  const updatedAt = balanceUpdatedAt === null ? 'Never synced' : balanceUpdatedAt;

  return (
    <div className="space-y-1 text-xs">
      <div className="flex flex-wrap items-center gap-1.5">
        <span className="rounded-md border border-amber-200 bg-amber-50 px-2 py-1 font-semibold text-amber-900">
          Due {format(receivableBalance)}
        </span>
        <span className="rounded-md border border-emerald-200 bg-emerald-50 px-2 py-1 font-semibold text-emerald-900">
          Credit {format(creditBalance)}
        </span>
        <span className="rounded-md border border-blue-200 bg-blue-50 px-2 py-1 font-semibold text-blue-900">
          Net due {format(netDue)}
        </span>
        <span
          aria-label={stale ? 'Customer balance is stale' : 'Customer balance is fresh'}
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
      <div className="text-[11px] font-medium text-gray-500">
        Balance updated {updatedAt}
      </div>
    </div>
  );
}
