import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { cn } from '@/lib/utils';
import { Check } from 'lucide-react';
import type { POSProductVariant } from '@/types/product';

export interface ProductVariantStockViewProps {
  /** Product base price; used when a variant has no `price_override`. */
  basePrice: string | null;
  variants: POSProductVariant[];
  selectedVariantId: string | null;
  onSelect: (variantId: string) => void;
}

/**
 * Presentational list of a product's variants, each row showing the variant
 * label, its effective price (variant `price_override` ?? product base price)
 * and the variant's on-hand stock. Out-of-stock variants are disabled so the
 * cashier cannot oversell at the device. Selecting a row emits its variant id.
 *
 * Pure display + selection: it never mutates the cart, fiscal payload, or any
 * cost — inventory WAC stays product-grain (spec §6.7).
 */
export function ProductVariantStockView({
  basePrice,
  variants,
  selectedVariantId,
  onSelect,
}: ProductVariantStockViewProps) {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();

  if (variants.length === 0) {
    return (
      <p className="py-6 text-center text-sm text-gray-600">{t('variants.empty')}</p>
    );
  }

  return (
    <ul className="flex flex-col gap-2">
      {variants.map((variant) => {
        const isSelected = variant.id === selectedVariantId;
        const isOutOfStock = variant.stock_quantity <= 0;
        const effectivePrice =
          variant.price_override != null && variant.price_override !== ''
            ? variant.price_override
            : basePrice ?? '0';

        return (
          <li key={variant.id}>
            <button
              type="button"
              onClick={() => !isOutOfStock && onSelect(variant.id)}
              disabled={isOutOfStock}
              aria-pressed={isSelected}
              className={cn(
                'flex w-full items-center justify-between gap-3 rounded-card border-2 px-4 py-3 text-left transition-all',
                isOutOfStock
                  ? 'cursor-not-allowed border-gray-200 bg-gray-50 opacity-70'
                  : isSelected
                    ? 'border-primary-500 bg-primary-50'
                    : 'border-gray-200 bg-white hover:border-primary-300',
              )}
            >
              <span className="flex min-w-0 items-center gap-2">
                {isSelected && (
                  <Check className="h-4 w-4 shrink-0 text-primary-600" />
                )}
                <span className="min-w-0">
                  <span className="block truncate text-sm font-semibold text-gray-900">
                    {variant.name_suffix.trim() || variant.variant_code}
                  </span>
                  <span
                    className={cn(
                      'block text-xs',
                      isOutOfStock ? 'font-medium text-red-600' : 'text-green-600',
                    )}
                  >
                    {isOutOfStock
                      ? t('variants.outOfStock')
                      : t('variants.inStock', { count: variant.stock_quantity })}
                  </span>
                </span>
              </span>
              <span className="shrink-0 text-base font-bold text-primary-600">
                {format(effectivePrice)}
              </span>
            </button>
          </li>
        );
      })}
    </ul>
  );
}
