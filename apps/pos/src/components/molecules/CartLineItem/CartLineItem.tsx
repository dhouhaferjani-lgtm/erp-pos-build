import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { Tag, SlidersHorizontal, Trash2, X, ChevronDown, ChevronUp } from 'lucide-react';
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
  /**
   * Mis-tap guard (owner feedback 2026-06-28). When `true` the remove control
   * requires a second confirming tap before it calls `onRemove` (the first tap
   * "arms" it; it auto-disarms after a few seconds or when the line collapses).
   * Driven by `settingsStore.confirmLineDelete` so a store can opt out for speed.
   */
  confirmDelete?: boolean;
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
  confirmDelete = false,
}: CartLineItemProps) {
  const { t } = useTranslation();
  const { format, decimals } = useCurrency();

  const collapsible = typeof onToggleExpand === 'function';
  const isOpen = collapsible ? expanded === true : true;

  // Mis-tap guard: when confirmDelete is on, the first delete tap "arms" the
  // control and a second tap confirms. Disarm when the line collapses (the
  // controls are no longer visible) and auto-disarm after a short window.
  const [removeArmed, setRemoveArmed] = useState(false);

  // Reset the guard when the line collapses (React "adjust state on prop change"
  // pattern — done during render, not in an effect, to avoid cascading renders).
  // prevOpen is render-bookkeeping only (never displayed), so a ref avoids an
  // extra state slot/render; the real visible reset is setRemoveArmed.
  const prevOpenRef = useRef(isOpen);
  if (prevOpenRef.current !== isOpen) {
    prevOpenRef.current = isOpen;
    if (!isOpen && removeArmed) setRemoveArmed(false);
  }

  useEffect(() => {
    if (!removeArmed) return;
    const id = setTimeout(() => setRemoveArmed(false), 3000);
    return () => clearTimeout(id);
  }, [removeArmed]);

  const handleRemoveClick = () => {
    if (!confirmDelete) {
      onRemove(item.id);
      return;
    }
    if (!removeArmed) {
      setRemoveArmed(true);
      return;
    }
    setRemoveArmed(false);
    onRemove(item.id);
  };

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
      {/* Header row — the whole row is the expand/collapse target (when
       * collapsible). The remove control lives in the expanded controls below
       * so it can never be mis-tapped while reaching for the expand affordance
       * (owner feedback 2026-06-28). */}
      {(() => {
        const headerInner = (
          <>
            <ProductThumb name={name} size={40} />

            <div className="min-w-0 flex-1 text-left">
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
            </div>

            <div className="flex shrink-0 flex-col items-end leading-tight">
              {originalTotal !== null && (
                <span className="font-mono text-xs tabular-nums text-ink-faint line-through">
                  {format(originalTotal)}
                </span>
              )}
              <span className="font-mono text-[15px] font-bold tabular-nums text-ink">
                {format(item.line_total)}
              </span>
            </div>

            {collapsible && (
              <span className="shrink-0 text-ink-faint" aria-hidden>
                {isOpen ? <ChevronUp className="h-4 w-4" /> : <ChevronDown className="h-4 w-4" />}
              </span>
            )}
          </>
        );

        return collapsible ? (
          <button
            type="button"
            onClick={() => onToggleExpand?.(item.id)}
            aria-expanded={isOpen}
            className="flex min-h-[48px] w-full items-center gap-2 p-2 text-left"
          >
            {headerInner}
          </button>
        ) : (
          <div className="flex min-h-[48px] items-center gap-2 p-2">{headerInner}</div>
        );
      })()}

      {/* Combo components (fixed bundle) */}
      {item.product.comboComponents && item.product.comboComponents.length > 0 && (
        <div className="space-y-0.5 px-2 pb-1">
          {item.product.comboComponents.map((c, idx) => (
            <p key={`${idx}-${c}`} className="pl-2 text-xs text-ink-muted">
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

      {/* Expanded controls — remove (left, separated from positive actions) +
       * qty stepper + discount + modifiers (right). */}
      {isOpen && (
        <div className="flex items-center gap-2 border-t border-border-subtle px-2 py-2">
          <button
            type="button"
            onClick={handleRemoveClick}
            className={
              removeArmed
                ? 'flex h-12 items-center gap-1.5 rounded-ctl bg-danger-strong px-3 text-sm font-semibold text-ink-inverse active:opacity-80'
                : 'flex h-12 w-12 items-center justify-center rounded-ctl text-danger-strong hover:bg-danger-surface active:opacity-80'
            }
            aria-label={removeArmed ? t('cart.confirmRemoveItem') : t('cart.removeItem')}
            title={removeArmed ? t('cart.confirmRemoveItem') : t('cart.removeItem')}
          >
            <Trash2 className="h-5 w-5" />
            {removeArmed && <span>{t('cart.confirmRemoveItem')}</span>}
          </button>
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
