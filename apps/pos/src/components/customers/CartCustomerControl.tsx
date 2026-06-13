import { useTranslation } from 'react-i18next';
import { User, X } from 'lucide-react';
import { usePaymentStore, type AttachedCheckoutCustomer } from '@/stores/paymentStore';

export interface CartCustomerControlProps {
  onOpen: () => void;
}

export function CartCustomerControl({ onOpen }: CartCustomerControlProps) {
  const { t } = useTranslation();
  const selectedCustomer = usePaymentStore((s) => s.selectedCustomer) as AttachedCheckoutCustomer | null;
  const detachCustomer = usePaymentStore((s) => s.detachCustomer);

  if (selectedCustomer !== null) {
    return (
      <div className="flex min-w-0 items-center gap-2 rounded-md border border-action bg-action-subtle px-2 py-1">
        <User className="h-4 w-4 shrink-0 text-action-strong" aria-hidden />
        <button
          type="button"
          onClick={onOpen}
          className="truncate text-sm font-medium text-action-strong hover:underline"
        >
          {selectedCustomer.name}
        </button>
        <button
          type="button"
          onClick={detachCustomer}
          aria-label={t('customer.detach')}
          className="ml-1 shrink-0 rounded p-0.5 text-action-strong hover:bg-action-subtle"
        >
          <X className="h-4 w-4" />
        </button>
      </div>
    );
  }

  return (
    <button
      type="button"
      onClick={onOpen}
      aria-label={t('customer.attach')}
      className="flex items-center gap-1.5 rounded-md border border-border-strong bg-surface-raised px-3 py-1.5 text-sm font-medium text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink"
    >
      <User className="h-4 w-4" aria-hidden />
      {t('customer.attach')}
    </button>
  );
}
