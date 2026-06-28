import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { Tag, SlidersHorizontal, X, ChevronDown, ChevronUp } from 'lucide-react';
import { bcadd, bccomp } from '@/lib/decimal';
import { ProductThumb } from '@/components/ui/ProductThumb';
import { Stepper } from '@/components/ui/Stepper';
import type { CartItem } from '@/types/cart';

export interface CartLineItemProps {
  item: CartItem;
  onUpdateQuantity: (itemId: string, newQuantity: number) => void;
  onRemove: (itemId: string) => void;
  onQuantityTap?: (itemId: string) => void;
  onDiscount?: (itemId: string) => void;
  onEditModifiers?: (itemId: string) => void;
  onRemoveDiscount?: (itemId: string) => void;
  /**
   * Expand/collapse (mock pattern — product decision 2026-06-28). When
   * `onToggleExpand` is provided the line collapses by default (tap the row body
   * to reveal the qty stepper + discount/modifiers), so more items fit on
   * screen. Accordion state is owned by the parent (TransactionCart). When
   * `onToggleExpand` is omitted the line renders fully expanded (legacy).
   */
  expanded?: boolean;
  onToggleExpand?: (itemId: string) => void;
}

export function CartLineItem({
  item,
  onUpdateQuantity,
  onRemove,
  onQuantityTap,
  onDiscount,
  onEditModifiers,
  onRemoveDiscount,
  expanded,
  onToggleExpand,
}: CartLineItemProps) {
  const { t } = useTranslation();
  const { format, decimals } = useCurrency();

  const collapsible = typeof onToggleExpand === 'function';
  const isOpen = collapsible ? expanded === true : true;

  const hasDiscount = !!item.discount_amount && bccomp(item.discount_amount, '0') > 0;
  const originalTotal = hasDiscount
    ? bcadd(item.line_total, item.discount_amount as string, decimals)
    : null;
  const hasMods = (item.product.selectedModifiers?.length ?? 0) > 0;

  const handleIncrement = () => onUpdateQuantity(item.id, item.quantity + 1);
  const handleDecrement = () => {
    if (item.quantity === 1) onRemove(item.id);
    else onUpdateQuantity(item.id, item.quantity - 1);
  };

  const name = item.product.variant_name ?? item.product.name;

  return (
    <div
      data-testid="cart-line"
      data-expanded={isOpen}
      className="rounded-card border border-border-subtle bg-surface-raised transition-all duration-150"
    >
      {/* Header row — tap toggles expand (when collapsible) */}
      <div className="flex items-center gap-2 p-2">
        <ProductThumb name={name} size={40} />

        <button
          type="button"
          onClick={collapsible ? () => onToggleExpand?.(item.id) : undefined}
          disabled={!collapsible}
          aria-expanded={collapsible ? isOpen : undefined}
          className="min-w-0 flex-1 text-left"
        >
          <div className="flex items-center gap-1.5">
            <span className="truncate text-[15px] font-semibold text-ink">{name}</span>
            <span className="shrink-0 rounded-pill bg-surface-sunken px-1.5 text-xs font-medium tabular-nums text-ink-muted">
              ×{item.quantity}
            </span>
          </div>
          {hasMods && (
            <p className="truncate text-xs text-ink-muted">
              {item.product.selectedModifiers?.map((m) => m.name).join(', ')}
            </p>
          )}
        </button>

        <div className="flex shrink-0 flex-col items-end leading-tight">
          {originalTotal && (
            <span className="font-mono text-xs tabular-nums text-ink-faint line-through">
              {format(originalTotal)}
            </span>
          )}
          <span className="font-mono text-[15px] font-bold tabular-nums text-ink">
            {format(item.line_total)}
          </span>
        </div>

        <button
          onClick={() => onRemove(item.id)}
          className="flex h-9 w-9 shrink-0 items-center justify-center rounded-ctl text-ink-faint hover:bg-danger-surface hover:text-danger-strong"
          aria-label={t('cart.removeItem')}
        >
          <X className="h-4 w-4" />
        </button>

        {collapsible && (
          <span className="shrink-0 text-ink-faint" aria-hidden>
            {isOpen ? <ChevronUp className="h-4 w-4" /> : <ChevronDown className="h-4 w-4" />}
          </span>
        )}
      </div>

      {/* Combo components (fixed bundle) */}
      {item.product.comboComponents && item.product.comboComponents.length > 0 && (
        <div className="space-y-0.5 px-2 pb-1">
          {item.product.comboComponents.map((c, idx) => (
            <p key={idx} className="pl-2 text-xs text-ink-muted">
              &bull; {c}
            </p>
          ))}
        </div>
      )}

      {/* Line-discount info (shown whenever discounted) */}
      {hasDiscount && (
        <div className="flex items-center gap-1 px-2 pb-1">
          <p className="text-xs tabular-nums text-danger-strong">
            {item.discount_type === 'percentage' && item.discount_percent
              ? `−${item.discount_percent}%`
              : `−${format(item.discount_amount as string)}`}
            {item.discount_reason ? ` (${item.discount_reason})` : ''}
          </p>
          {onRemoveDiscount && (
            <button
              onClick={() => onRemoveDiscount(item.id)}
              className="rounded-md bg-danger-surface px-1.5 py-0.5 text-danger-strong hover:opacity-90"
              aria-label={t('pos:discount.removeLineDiscount')}
            >
              <X className="h-3 w-3" />
            </button>
          )}
        </div>
      )}

      {/* Expanded controls — qty stepper + discount + modifiers */}
      {isOpen && (
        <div className="flex items-center gap-2 border-t border-border-subtle px-2 py-2">
          <span className="font-mono text-xs tabular-nums text-ink-muted">
            {format(item.unit_price)}
          </span>
          <div className="ml-auto flex items-center gap-2">
            <Stepper
              value={item.quantity}
              onIncrement={handleIncrement}
              onDecrement={handleDecrement}
              onValueClick={onQuantityTap ? () => onQuantityTap(item.id) : undefined}
              display={String(item.quantity)}
              decrementLabel={t('cart.decrementQty')}
              incrementLabel={t('cart.incrementQty')}
            />
            {onDiscount && (
              <button
                onClick={() => onDiscount(item.id)}
                className="flex h-12 w-12 items-center justify-center rounded-ctl bg-accent-tint text-accent-strong active:opacity-80"
                aria-label={t('cart.lineDiscount')}
                title={t('cart.lineDiscount')}
              >
                <Tag className="h-5 w-5" />
              </button>
            )}
            {onEditModifiers && hasMods && (
              <button
                onClick={() => onEditModifiers(item.id)}
                className="flex h-12 w-12 items-center justify-center rounded-ctl bg-surface-sunken text-ink active:opacity-80"
                aria-label={t('cart.editModifiers')}
                title={t('cart.editModifiers')}
              >
                <SlidersHorizontal className="h-5 w-5" />
              </button>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
