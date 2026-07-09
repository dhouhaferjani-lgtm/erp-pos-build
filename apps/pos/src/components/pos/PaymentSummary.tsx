import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { Banknote, Wallet, X } from 'lucide-react';
import { Button, Divider, IconButton } from '@/components/ui';
import { tokens } from '@/lib/designTokens';
import { cn } from '@/lib/utils';
import type { PaymentMethod, PaymentRepository } from '@/types/payment';

interface PaymentSummaryProps {
  subtotal: number;
  /** Gross subtotal before any discount (Sous-total). Falls back to `subtotal`. */
  grossSubtotal?: number;
  /** Total of per-line discounts (Remises produits). */
  lineDiscountAmount?: number;
  taxAmount: number;
  /** Cart-level transaction discount (Remise panier). */
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
  grossSubtotal,
  lineDiscountAmount = 0,
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
    // Navy footer card — echoes the header's navy chrome so header/footer
    // "bookend" the cart (design spec §4). Breakdown lines use
    // `text-pay-navy-fg` / `tokens.inverseOnNavy.muted` (theme-constant,
    // verified >=7:1 against `--pay-navy` — see task-6-report.md); the two
    // discount rows keep their own self-contained `bg-danger-surface`
    // treatment (like a Badge/StatusPill) so their red signal stays
    // legible regardless of the navy parent.
    <div className={cn(tokens.section.footer, 'space-y-1.5 rounded-card px-3 py-2 shadow-sm')}>
      {/* Sous-total — GROSS (before any discount). Falls back to net subtotal
       * when the caller doesn't supply a gross value. */}
      <div className={cn('flex justify-between text-xs', tokens.inverseOnNavy.muted)}>
        <span>{t('common:subtotal')}</span>
        <span className="font-mono tabular-nums text-pay-navy-fg">{format(grossSubtotal ?? subtotal)}</span>
      </div>

      {/* Remises produits — total of per-line discounts (only when present). */}
      {lineDiscountAmount > 0.0005 && (
        <div className="flex items-center justify-between rounded-sm border border-danger-subtle bg-danger-surface px-2 py-1 text-xs font-medium text-danger-strong">
          <span>{t('pos:cart.lineDiscountsTotal')}</span>
          <span className="font-mono tabular-nums">−{format(lineDiscountAmount)}</span>
        </div>
      )}

      {/* Remise panier — cart-level transaction discount (removable). */}
      {hasDiscount && (
        <div className="flex items-center justify-between text-sm">
          <div className="flex items-center gap-2 rounded-sm border border-danger-subtle bg-danger-surface px-2 py-1">
            <span className="font-medium text-danger-strong">{t('pos:cart.cartDiscount')}</span>
            <span className="font-mono font-medium tabular-nums text-danger-strong">-{format(discountAmount)}</span>
          </div>
          {onRemoveDiscount && (
            <IconButton
              variant="destructive"
              size="md"
              onClick={onRemoveDiscount}
              aria-label={t('pos:discount.remove')}
              title={t('pos:discount.remove')}
              icon={<X className="h-4 w-4" />}
            />
          )}
        </div>
      )}

      {/* dont TVA — VAT is INCLUDED in the TTC prices (B2C POS), so this is an
       * "of which" line, not an addition. Generic "TVA" label keeps it correct
       * for mixed VAT-rate carts (owner decision — not "dont TVA 19%"). */}
      <div className={cn('flex justify-between text-xs', tokens.inverseOnNavy.muted)}>
        <span>{t('common:tax')}</span>
        <span className="font-mono tabular-nums text-pay-navy-fg">{format(taxAmount)}</span>
      </div>

      <Divider className={tokens.inverseOnNavy.divider} />

      {/* Total — sits flat on the navy card (no nested bg-action pill); mono
       * amount-due in `text-pay-navy-fg` clears AAA (>=7:1) on `--pay-navy`. */}
      <div className="flex items-center justify-between">
        <span className="text-lg font-medium text-pay-navy-fg">{t('common:total')}</span>
        <span className="font-mono text-2xl font-bold tabular-nums text-pay-navy-fg">{format(total)}</span>
      </div>

      {/* Payment action row — cash is the dominant real-world action and
       * visually OWNS the row (icon + full label, all remaining width).
       * "Autres paiements" is demoted to a compact 64px icon-only control
       * (matches the cash button's row height, ≥48px touch floor) so it can
       * NEVER clip — its label lives on aria-label + title. The previous
       * two-equal-width layout clipped "Autres paiements" → "Autres paieme…"
       * at real cart width (~300-360px, owner-reported on Tauri). */}
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
          className="min-w-0 flex-1"
          truncate
        >
          {t('pos:payment.cashPayment')}
        </Button>

        {showAdvancedPayments && (
          <IconButton
            variant="secondary"
            onClick={onAdvancedPayments}
            disabled={disabled}
            aria-label={t('pos:payment.advancedPayments')}
            title={t('pos:payment.advancedPayments')}
            icon={<Wallet className="h-6 w-6" />}
            className="h-16 w-16"
          />
        )}
      </div>
    </div>
  );
}
