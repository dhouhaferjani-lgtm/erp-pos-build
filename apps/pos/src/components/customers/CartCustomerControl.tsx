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
      <div className="flex items-center gap-2 rounded-md border border-blue-200 bg-blue-50 px-2 py-1">
        <User className="h-4 w-4 text-blue-700 shrink-0" aria-hidden />
        <button
          type="button"
          onClick={onOpen}
          className="truncate text-sm font-medium text-blue-900 hover:underline"
        >
          {selectedCustomer.name}
        </button>
        <button
          type="button"
          onClick={detachCustomer}
          aria-label={t('customer.detach')}
          className="ml-1 rounded p-0.5 text-blue-700 hover:bg-blue-200"
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
      className="flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
    >
      <User className="h-4 w-4" aria-hidden />
      {t('customer.attach')}
    </button>
  );
}
