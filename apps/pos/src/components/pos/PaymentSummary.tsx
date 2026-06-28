import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { Banknote, Wallet, X } from 'lucide-react';
import { Button, IconButton } from '@/components/ui';
import type { PaymentMethod, PaymentRepository } from '@/types/payment';

interface PaymentSummaryProps {
  subtotal: number;
  taxAmount: number;
  discountAmount: number;
  total: number;
  onPayCash: () => void;
  onAdvancedPayments?: () => void;
  onRemoveDiscount?: () => void;
  hasDiscount: boolean;
  paymentMethods?: PaymentMethod[];
  paymentRepositories?: PaymentRepository[];
  disabled?: boolean;
}

export function PaymentSummary({
  subtotal,
  taxAmount,
  discountAmount,
  total,
  onPayCash,
  onAdvancedPayments,
  onRemoveDiscount,
  hasDiscount,
  paymentMethods = [],
  paymentRepositories = [],
  disabled = false,
}: PaymentSummaryProps) {
  const { t } = useTranslation(['common', 'pos']);
  const { format } = useCurrency();

  // T1.2 Step 2.3: gate the cash + advanced-payments buttons on payment
  // config readiness. Prior code allowed the cashier to press Cash
  // before T0.5's scheduler had refreshed the in-memory payment store
  // — pressing Cash then would call processCashCheckout, which would
  // hit `paymentRepositories.find(r => r.kind === 'cash')` returning
  // undefined (the recurring "no cash method" symptom from Phase 2).
  // The existing per-method validation in processCashCheckout remains
  // as a defense-in-depth backstop for any path that bypasses the gate.
  const paymentConfigReady =
    paymentMethods.length > 0 && paymentRepositories.length > 0;
  const cashButtonDisabled = disabled || !paymentConfigReady;

  // Show advanced payments button when there are 2+ active payment
  // methods AND the payment config has fully loaded. Without the
  // readiness gate the menu would render before paymentRepositories
  // resolved, exposing the same broken-checkout window.
  const hasMultipleMethods = paymentMethods.filter((m) => m.is_active).length >= 2;
  const showAdvancedPayments =
    paymentConfigReady && hasMultipleMethods && Boolean(onAdvancedPayments);

  return (
    <div className="space-y-1 border-t border-border-subtle pt-1.5">
      {/* Subtotal */}
      <div className="flex justify-between text-xs text-ink-muted">
        <span>{t('common:subtotal')}</span>
        <span className="font-mono tabular-nums text-ink">{format(subtotal)}</span>
      </div>

      {/* Tax */}
      <div className="flex justify-between text-xs text-ink-muted">
        <span>{t('common:tax')}</span>
        <span className="font-mono tabular-nums text-ink">{format(taxAmount)}</span>
      </div>

      {/* Discount */}
      {hasDiscount && (
        <div className="flex items-center justify-between text-sm">
          <span className="font-medium text-danger-strong">{t('common:discount')}</span>
          <div className="flex items-center gap-2">
            <span className="font-mono font-medium tabular-nums text-danger-strong">-{format(discountAmount)}</span>
            {onRemoveDiscount && (
              <IconButton
                variant="destructive"
                size="sm"
                onClick={onRemoveDiscount}
                aria-label={t('pos:discount.remove')}
                title={t('pos:discount.remove')}
                icon={<X className="h-3.5 w-3.5" />}
              />
            )}
          </div>
        </div>
      )}

      {/* Total */}
      <div className="rounded-lg bg-action px-3 py-2 text-ink-inverse">
        <div className="flex items-center justify-between">
          <span className="text-lg font-medium">{t('common:total')}</span>
          <span className="font-mono text-2xl font-bold tabular-nums">{format(total)}</span>
        </div>
      </div>

      {/* Payment buttons */}
      <div className="flex gap-2">
        <Button
          variant="confirm"
          size="lg"
          onClick={onPayCash}
          disabled={cashButtonDisabled}
          aria-disabled={cashButtonDisabled}
          title={
            !paymentConfigReady ? t('pos:payment.configNotLoaded') : undefined
          }
          leftIcon={<Banknote className="h-5 w-5" />}
          className="flex-[3]"
        >
          {t('pos:payment.cashPayment')}
        </Button>

        {showAdvancedPayments && (
          <Button
            variant="secondary"
            size="lg"
            onClick={onAdvancedPayments}
            disabled={disabled}
            leftIcon={<Wallet className="h-5 w-5" />}
            className="flex-1"
          >
            {t('pos:payment.advancedPayments')}
          </Button>
        )}
      </div>
    </div>
  );
}
