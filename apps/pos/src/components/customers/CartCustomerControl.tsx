import { useTranslation } from 'react-i18next';
import { User, Wallet, X } from 'lucide-react';
import { Button, IconButton } from '@/components/ui';
import { useCurrency } from '@/lib/currency';
import { bccomp } from '@/lib/decimal';
import { usePaymentStore, type AttachedCheckoutCustomer } from '@/stores/paymentStore';
import { CustomerLoyaltyBadge } from './CustomerLoyaltyBadge';

export interface CartCustomerControlProps {
  onOpen: () => void;
}

export function CartCustomerControl({ onOpen }: CartCustomerControlProps) {
  const { t } = useTranslation();
  const selectedCustomer = usePaymentStore((s) => s.selectedCustomer) as AttachedCheckoutCustomer | null;
  const detachCustomer = usePaymentStore((s) => s.detachCustomer);
  const { format } = useCurrency();

  if (selectedCustomer !== null) {
    // Deposit-discoverability affordance (owner could not find the account
    // feature): the modal auto-closes on attach (intentional, PR #193), so the
    // chip must advertise the account itself. `credit_balance` is a decimal
    // STRING — compare via bccomp (never parseFloat on money); bccomp treats
    // empty/absent as 0, so a missing balance simply hides the amount.
    const hasCredit = bccomp(selectedCustomer.credit_balance, '0') > 0;
    // Attached "selected" treatment: a chip carrying the action tone (this is
    // the sale's chosen customer = a selected state, per the color grammar).
    // Kept as a chip rather than a nested Button so the detach control can be
    // its own real <button> (no invalid button-in-button), while the
    // UNATTACHED trigger below is a plain secondary Button identical to the
    // toolbar actions.
    return (
      <div className="flex min-h-11 min-w-0 items-center gap-1 rounded-xl border border-action bg-action-subtle pr-1 pl-3">
        <User className="h-4 w-4 shrink-0 text-action-strong" aria-hidden />
        <button
          type="button"
          onClick={onOpen}
          className="min-w-0 truncate text-sm font-semibold text-action-strong hover:underline"
        >
          {selectedCustomer.name}
        </button>
        <CustomerLoyaltyBadge customer={selectedCustomer} />
        <button
          type="button"
          onClick={onOpen}
          aria-label={t('customer.accountAndDeposit')}
          title={t('customer.accountAndDeposit')}
          className="flex h-8 shrink-0 items-center gap-1 rounded-lg px-1.5 text-action-strong hover:bg-action-subtle"
        >
          <Wallet className="h-4 w-4" aria-hidden />
          {hasCredit && (
            <span className="text-xs font-semibold tabular-nums">
              {format(selectedCustomer.credit_balance)}
            </span>
          )}
        </button>
        <IconButton
          variant="ghost"
          size="sm"
          onClick={detachCustomer}
          aria-label={t('customer.detach')}
          icon={<X className="h-4 w-4" />}
          className="text-action-strong hover:bg-action-subtle"
        />
      </div>
    );
  }

  return (
    <Button
      variant="secondary"
      size="md"
      onClick={onOpen}
      aria-label={t('customer.attach')}
      leftIcon={<User className="h-4 w-4" aria-hidden />}
    >
      {t('customer.attach')}
    </Button>
  );
}
