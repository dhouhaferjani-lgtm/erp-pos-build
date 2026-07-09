import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { ShoppingCart, Trash2, RotateCcw } from 'lucide-react';
import { CartLineItem } from '@/components/molecules/CartLineItem';
import { PaymentSummary } from '@/components/organisms/PaymentSummary';
import { QuickActions } from '@/components/molecules/QuickActions';
import { Button, IconButton } from '@/components/ui';
import { useCurrency } from '@/lib/currency';
import { bcabs } from '@/lib/decimal';
import { tokens } from '@/lib/designTokens';
import { cn } from '@/lib/utils';
import { useSettingsStore } from '@/stores/settingsStore';
import type { CartItem } from '@/types/cart';
import type { PaymentMethod, PaymentRepository } from '@/types/payment';

export interface TransactionCartProps {
  items: CartItem[];
  subtotal: number;
  /** Gross subtotal before any discount (Sous-total). */
  grossSubtotal?: number;
  /** Total of per-line discounts (Remises produits). */
  lineDiscountAmount?: number;
  taxAmount: number;
  discountAmount: number;
  total: number;
  itemCount: number;
  hasDiscount: boolean;
  onUpdateQuantity: (itemId: string, quantity: number) => void;
  onRemoveItem: (itemId: string) => void;
  onClearCart: () => void;
  onPayCash: () => void;
  onAdvancedPayments?: () => void;
  onQuantityTap?: (itemId: string) => void;
  onDiscount?: () => void;
  onHold?: () => void;
  onRecall?: () => void;
  /** Number of parked sales — shown as a count badge on the Recall quick action. */
  recallCount?: number;
  /** Opens the Returns / Exchange receipt-locator screen. */
  onReturns?: () => void;
  onLineDiscount?: (itemId: string) => void;
  onRemoveLineDiscount?: (itemId: string) => void;
  onEditModifiers?: (itemId: string) => void;
  onRemoveDiscount?: () => void;
  paymentMethods?: PaymentMethod[];
  paymentRepositories?: PaymentRepository[];
  checkoutDisabled?: boolean;
  /**
   * Customer-assignment control rendered inline in the cart header (right of
   * the title) as the sale's "who" context. Supplied by HomePage so the cart
   * owns a single unified header zone instead of a separate floating band.
   */
  customerControl?: ReactNode;
  /**
   * Net amount for the refund/exchange flow (sale total minus return total).
   * When provided, the footer shows the net amount and the confirm button label
   * adapts to "Charge X" / "Refund X" / "No payment due".
   * When absent, the normal `total` and "Cash payment" button are shown.
   */
  netTotal?: number;
}

export function TransactionCart({
  items,
  subtotal,
  grossSubtotal,
  lineDiscountAmount,
  taxAmount,
  discountAmount,
  total,
  itemCount,
  hasDiscount,
  onUpdateQuantity,
  onRemoveItem,
  onClearCart,
  onPayCash,
  onAdvancedPayments,
  onQuantityTap,
  onDiscount,
  onHold,
  onRecall,
  recallCount = 0,
  onReturns,
  onLineDiscount,
  onRemoveLineDiscount,
  onEditModifiers,
  onRemoveDiscount,
  paymentMethods,
  paymentRepositories,
  checkoutDisabled = false,
  customerControl,
  netTotal,
}: TransactionCartProps) {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();
  const confirmLineDelete = useSettingsStore((s) => s.confirmLineDelete);
  const cartPosition = useSettingsStore((s) => s.cartPosition);

  // Cart-panel seam border must face the CANVAS, mirroring NavRail's
  // seamSide handling (Task 5): the cart flips side with `cartPosition`
  // (AppShell's `railOnLeft = cartPosition === 'end'`). cart-on-right
  // (cartPosition 'end') -> canvas is to the left -> border-l; cart-on-left
  // (default 'start') -> canvas is to the right -> border-r.
  // `tokens.section.cartPanel` bakes in a fixed `border-l`, so the surface
  // is rebuilt here (same bg/border-color/shadow) with a dynamic side —
  // the same approach NavRail.tsx takes for `tokens.section.rail`.
  const cartSeamSide = cartPosition === 'end' ? 'border-l' : 'border-r';

  // Cart-line accordion (mock pattern): collapsed by default so more items fit;
  // tapping a line expands it (and collapses any previously-open line).
  const [expandedLineId, setExpandedLineId] = useState<string | null>(null);
  const toggleLine = (id: string) =>
    setExpandedLineId((prev) => (prev === id ? null : id));

  const returnItems = items.filter((i) => (i.kind ?? 'sale') === 'return');
  const saleItems = items.filter((i) => (i.kind ?? 'sale') === 'sale');
  const isRefundMode = returnItems.length > 0;
  const hasQuickActions = Boolean(onDiscount && onHold && onRecall);
  const hasReturns = Boolean(onReturns);

  // Determine confirm button label when in refund/exchange mode
  function getNetLabel(): string {
    if (netTotal === undefined) return '';
    const formatted = format(Math.abs(netTotal));
    if (netTotal > 0.005) return t('refundFlow.confirm.charge', { amount: formatted });
    if (netTotal < -0.005) return t('refundFlow.confirm.refund', { amount: formatted });
    return t('refundFlow.confirm.noPayment');
  }

  return (
    <div
      className={cn(
        'flex h-full min-h-0 flex-col overflow-hidden bg-surface-raised shadow-sm border-border-strong',
        cartSeamSide,
      )}
    >
      {/* ── Unified cart header zone ──────────────────────────────────────
       * Row A = context (what + who): title + count on the left, the
       * customer-assignment control inline on the right. Row B = operations
       * toolbar. This replaces the previous two disconnected bands (a
       * standalone customer row above the cart + a separate quick-actions
       * strip) with one coherent header that reads who → what → operate. */}
      <div className="shrink-0 border-b border-subtle">
        {/* Row A — context. px-4 (not px-3) so the cart icon always has
         * clearance from the panel's outer edge — it sat flush against the
         * screen bezel at px-3 (the reported clip). */}
        <div className="flex items-center justify-between gap-2 px-4 pt-2 pb-1.5">
          <div className="flex min-w-0 items-center gap-2">
            <ShoppingCart className="h-5 w-5 shrink-0 text-ink-muted" />
            <h2 className="text-lg font-bold text-ink">{t('cart.title')}</h2>
            {itemCount > 0 && (
              <span className="flex h-6 min-w-[24px] items-center justify-center rounded-pill bg-action px-2 text-sm font-medium text-ink-inverse tabular-nums">
                {itemCount}
              </span>
            )}
          </div>
          {customerControl != null && (
            // No shrink-0 here: a long name + loyalty points + wallet balance
            // must be able to cede width back to this row rather than force
            // the header to overflow. The chip itself (CartCustomerControl)
            // carries min-w-0 + a truncating name so it degrades gracefully.
            <div className="flex min-w-0 justify-end">{customerControl}</div>
          )}
        </div>

        {/* Row B — operations toolbar. The three labelled sale quick actions
         * (Remise · En attente | Reprendre, mock §5.1) flex to fill, with the
         * SECONDARY actions — Returns/Exchange (its own flow, §5.4) and Clear —
         * pinned at the end as compact icons so the row never crowds/truncates.
         * Renders when quick actions are wired, Returns is wired, OR there are
         * items to clear. */}
        {(hasQuickActions || hasReturns || items.length > 0) && (
          <div className="flex items-center gap-1.5 px-2 pb-2">
            {hasQuickActions && (
              <div className="min-w-0 flex-1">
                <QuickActions
                  onDiscount={onDiscount!}
                  onHold={onHold!}
                  onRecall={onRecall!}
                  recallCount={recallCount}
                  hasItems={items.length > 0}
                  hasDiscount={hasDiscount}
                />
              </div>
            )}
            {(hasReturns || items.length > 0) && (
              <div className="flex shrink-0 items-center gap-1.5">
                {hasQuickActions && (
                  <span className="h-6 w-px shrink-0 bg-border-subtle" aria-hidden="true" />
                )}
                {hasReturns && (
                  <IconButton
                    variant="secondary"
                    size="md"
                    onClick={onReturns!}
                    aria-label={t('receiptLocator.entryButton')}
                    title={t('receiptLocator.entryButton')}
                    icon={<RotateCcw className="h-4 w-4" />}
                  />
                )}
                {items.length > 0 && (
                  <IconButton
                    variant="destructive"
                    size="md"
                    onClick={onClearCart}
                    aria-label={t('cart.clear')}
                    title={t('cart.clear')}
                    icon={<Trash2 className="h-4 w-4" />}
                  />
                )}
              </div>
            )}
          </div>
        )}
      </div>

      {/* Cart items */}
      <div className="min-h-0 flex-1 overflow-y-auto px-2 py-0.5">
        {items.length === 0 ? (
          <div className="flex h-full flex-col items-center justify-center text-center text-ink-muted">
            <ShoppingCart className="mb-3 h-12 w-12 text-ink-faint" />
            <p className="text-base font-medium text-ink-muted">{t('cart.empty')}</p>
            <p className="mt-1 text-sm text-ink-faint">{t('cart.addProducts')}</p>
          </div>
        ) : isRefundMode ? (
          // ── Refund / Exchange mode: two-section layout ──────────────────────
          <div>
            {/* ── Returning section ─────────────────────────────────────── */}
            <div className="mb-1 flex items-center gap-1.5 border-b border-danger-subtle bg-danger-surface px-2 py-1">
              <RotateCcw className="h-4 w-4 text-danger-strong" aria-hidden="true" />
              <span className="text-sm font-semibold text-danger-strong">
                {t('refundFlow.returningSection')}
              </span>
            </div>
            <div className="divide-y divide-danger-subtle">
              {returnItems.map((item) => (
                <div key={item.id} className="bg-danger-surface/50">
                  <ReturnLineItem
                    item={item}
                    onUpdateQuantity={onUpdateQuantity}
                    onRemove={onRemoveItem}
                    onQuantityTap={onQuantityTap}
                    format={format}
                    t={t}
                  />
                </div>
              ))}
            </div>

            {/* ── Buying new section (only when sale items exist) ────────── */}
            {saleItems.length > 0 && (
              <>
                <div className="mb-1 mt-2 flex items-center gap-1.5 border-b border-subtle bg-surface-sunken px-2 py-1">
                  <ShoppingCart className="h-4 w-4 text-ink-muted" aria-hidden="true" />
                  <span className="text-sm font-semibold text-ink-muted">
                    {t('refundFlow.buyingNewSection')}
                  </span>
                </div>
                <div className="divide-y divide-border-subtle">
                  {saleItems.map((item) => (
                    <CartLineItem
                      key={item.id}
                      item={item}
                      onUpdateQuantity={onUpdateQuantity}
                      onRemove={onRemoveItem}
                      onQuantityTap={onQuantityTap}
                      onDiscount={onLineDiscount}
                      onEditModifiers={onEditModifiers}
                      onRemoveDiscount={onRemoveLineDiscount}
                      expanded={expandedLineId === item.id}
                      onToggleExpand={toggleLine}
                      confirmDelete={confirmLineDelete}
                    />
                  ))}
                </div>
              </>
            )}
          </div>
        ) : (
          // ── Normal sale mode ───────────────────────────────────────────────
          <div className="divide-y divide-border-subtle">
            {items.map((item) => (
              <CartLineItem
                key={item.id}
                item={item}
                onUpdateQuantity={onUpdateQuantity}
                onRemove={onRemoveItem}
                onQuantityTap={onQuantityTap}
                onDiscount={onLineDiscount}
                onEditModifiers={onEditModifiers}
                onRemoveDiscount={onRemoveLineDiscount}
                expanded={expandedLineId === item.id}
                onToggleExpand={toggleLine}
                confirmDelete={confirmLineDelete}
              />
            ))}
          </div>
        )}
      </div>

      {/* Payment summary + actions */}
      <div className="shrink-0 px-3 pb-2">
        {items.length > 0 && isRefundMode && netTotal !== undefined ? (
          // ── Net footer for refund/exchange ─────────────────────────────────
          // T1.2 Codex round-1 finding (unpreempted): the refund/exchange
          // path renders its OWN cash button rather than going through
          // PaymentSummary, so the Step 2.3 paymentConfigReady gate has
          // to be applied here too. Without this, a cashier in refund
          // mode hitting Cash before payment config is loaded would
          // reach processCashCheckout and throw on the no-cash-method
          // backstop — the same recurring symptom Step 2.3 fixed for
          // the sale path.
          (() => {
            const paymentConfigReady =
              (paymentMethods?.length ?? 0) > 0 &&
              (paymentRepositories?.length ?? 0) > 0;
            const netButtonDisabled = checkoutDisabled || !paymentConfigReady;
            return (
              // Navy footer card — restyled to match PaymentSummary's sale-mode
              // footer (both echo the header's navy chrome, "bookending" the
              // cart). Total sits flat on the navy (no nested bg-action pill)
              // in `text-pay-navy-fg`, verified >=7:1 against `--pay-navy`
              // (see task-6-report.md contrast table).
              <div className={cn(tokens.section.footer, 'space-y-1.5 rounded-card px-3 py-2 shadow-sm')}>
                <div className="flex items-center justify-between">
                  <span className="text-lg font-medium">{t('common.total')}</span>
                  <span className="font-mono text-2xl font-bold tabular-nums text-pay-navy-fg">
                    {netTotal < -0.005 ? '−' : ''}{format(Math.abs(netTotal))}
                  </span>
                </div>
                <Button
                  variant="confirm"
                  size="lg"
                  fullWidth
                  onClick={onPayCash}
                  disabled={netButtonDisabled}
                  aria-disabled={netButtonDisabled}
                  title={
                    !paymentConfigReady
                      ? t('payment.configNotLoaded')
                      : undefined
                  }
                >
                  {getNetLabel()}
                </Button>
              </div>
            );
          })()
        ) : (
          // Persistent checkout footer: in normal sale mode the totals +
          // Charge button are ALWAYS anchored at the bottom, even when the
          // cart is empty. The Charge button is disabled (grayed out) until
          // there is at least one item, so the footer reads as a stable,
          // ever-present checkout surface rather than appearing/disappearing.
          <PaymentSummary
            subtotal={subtotal}
            grossSubtotal={grossSubtotal}
            lineDiscountAmount={lineDiscountAmount}
            taxAmount={taxAmount}
            discountAmount={discountAmount}
            total={total}
            hasDiscount={hasDiscount}
            onPayCash={onPayCash}
            onAdvancedPayments={onAdvancedPayments}
            onRemoveDiscount={onRemoveDiscount}
            paymentMethods={paymentMethods}
            paymentRepositories={paymentRepositories}
            disabled={checkoutDisabled || items.length === 0}
          />
        )}
      </div>
    </div>
  );
}

// ── Returning-section line item ────────────────────────────────────────────────
// Displays with red "−" prefix on total + red text. NO strike-through (spec §6.2).
// Removing (via Trash2) = keeping the item (don't refund) — cashier intent is preserved.

interface ReturnLineItemProps {
  item: CartItem;
  onUpdateQuantity: (itemId: string, quantity: number) => void;
  onRemove: (itemId: string) => void;
  onQuantityTap?: (itemId: string) => void;
  format: (val: string | number) => string;
  t: (key: string) => string;
}

function ReturnLineItem({
  item,
  onUpdateQuantity,
  onRemove,
  onQuantityTap,
  format,
  t,
}: ReturnLineItemProps) {
  const absQty = Math.abs(item.quantity);
  // `item.line_total` is a canonical decimal string (never a float) — use
  // the decimal-safe `bcabs` helper (Big.js) instead of
  // `Math.abs(parseFloat(...))`, which would round-trip the value through
  // an IEEE-754 float and trips the `no-parsefloat-on-money` ESLint rule.
  // `format()` re-parses the string for display exactly as it already does
  // for `item.unit_price` below, so the displayed value is unchanged.
  const absTotal = bcabs(item.line_total);

  // Decrement on a return line means reducing the return qty (closer to 0)
  const handleDecrement = () => {
    const newAbsQty = absQty - 1;
    if (newAbsQty === 0) {
      // Removing the return line = keeping the item (not returning it)
      onRemove(item.id);
    } else {
      onUpdateQuantity(item.id, -newAbsQty);
    }
  };

  const handleIncrement = () => {
    onUpdateQuantity(item.id, -(absQty + 1));
  };

  return (
    <div className="px-1 py-1.5">
      {/* Row 1: Name + Line Total (danger with − prefix) */}
      <div className="flex items-center justify-between gap-2">
        <h4 className="truncate text-sm font-semibold text-danger-strong">
          {item.product.variant_name ?? item.product.name}
        </h4>
        <span className="shrink-0 text-sm font-bold text-danger-strong tabular-nums">
          −{format(absTotal)}
        </span>
      </div>

      {/* Row 2: unit price × qty | controls */}
      <div className="mt-1 flex items-center justify-between">
        <span className="text-xs text-danger-strong tabular-nums">
          {format(item.unit_price)} × {absQty}
        </span>

        <div className="flex items-center gap-1.5">
          {/* Decrement (reduce return qty or keep item) */}
          <button
            onClick={handleDecrement}
            className="flex h-12 w-12 items-center justify-center rounded-ctl border border-danger-subtle bg-danger-surface text-danger-strong active:opacity-80"
            aria-label={t('cart.decrementQty')}
          >
            <span className="text-base font-bold leading-none">−</span>
          </button>

          <button
            onClick={() => onQuantityTap?.(item.id)}
            className="flex h-12 min-w-[2.5rem] items-center justify-center text-base font-bold text-danger-strong tabular-nums"
            type="button"
            aria-label={t('cart.editQty')}
          >
            {absQty}
          </button>

          {/* Increment (return more) */}
          <button
            onClick={handleIncrement}
            className="flex h-12 w-12 items-center justify-center rounded-ctl border border-danger-subtle bg-danger-surface text-danger-strong active:opacity-80"
            aria-label={t('cart.incrementQty')}
          >
            <span className="text-base font-bold leading-none">+</span>
          </button>

          {/* Remove = keep item, don't refund */}
          <button
            onClick={() => onRemove(item.id)}
            className="flex h-7 w-7 items-center justify-center rounded-ctl bg-danger-surface text-danger-strong active:opacity-80"
            aria-label={t('cart.removeItem')}
          >
            <Trash2 className="h-4 w-4" />
          </button>
        </div>
      </div>
    </div>
  );
}
