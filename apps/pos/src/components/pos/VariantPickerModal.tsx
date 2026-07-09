import { useState, useRef, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/pos/Modal';
import { ProductVariantStockView } from '@/components/pos/ProductVariantStockView';
import { useProductVariants } from '@/hooks/useProductVariants';
import type { POSProduct, POSProductVariant } from '@/types/product';

export interface VariantPickerModalProps {
  isOpen: boolean;
  onClose: () => void;
  /** The variant-bearing product the cashier tapped. */
  product: POSProduct | null;
  /** Fired with the chosen variant once the cashier confirms. */
  onConfirm: (variant: POSProductVariant) => void;
}

/**
 * Cashier-facing modal that lets a specific VARIANT of a product be selected
 * before it is sold. Reads variants from local SQLite first (local-first via
 * useProductVariants / FV3), lists them with their per-variant stock via
 * {@link ProductVariantStockView}, and emits the chosen variant on confirm.
 * The caller stamps the variant identity onto the cart line — this modal never
 * touches the cart, cost, or fiscal payload.
 */
export function VariantPickerModal({
  isOpen,
  onClose,
  product,
  onConfirm,
}: VariantPickerModalProps) {
  const { t } = useTranslation('pos');
  const productId = isOpen && product ? product.id : null;
  const { variants, isLoading, status } = useProductVariants(productId);

  const [selectedVariantId, setSelectedVariantId] = useState<string | null>(null);

  // Reset the selection whenever a different product opens the picker. Using
  // the "adjust state during render" pattern (tracking the last product id in
  // a ref) instead of an effect avoids a synchronous setState-in-effect.
  const lastProductIdRef = useRef<string | null>(null);
  const currentProductId = isOpen ? (product?.id ?? null) : null;
  if (lastProductIdRef.current !== currentProductId) {
    lastProductIdRef.current = currentProductId;
    if (selectedVariantId !== null) {
      setSelectedVariantId(null);
    }
  }

  const handleConfirm = useCallback(() => {
    if (selectedVariantId === null) return;
    const variant = variants.find((v) => v.id === selectedVariantId);
    if (!variant) return;
    onConfirm(variant);
  }, [variants, selectedVariantId, onConfirm]);

  if (!product) return null;

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={`${product.name} — ${t('variants.chooseVariant')}`}
      size="md"
    >
      <div className="flex flex-col">
        <div className="min-h-[120px] flex-1 overflow-y-auto">
          {isLoading ? (
            <p className="py-6 text-center text-sm text-ink-muted">
              {t('variants.loading')}
            </p>
          ) : status === 'offline-empty' ? (
            <p className="py-6 text-center text-sm text-ink-muted">
              {t('variants.offlineNoCache')}
            </p>
          ) : status === 'error' ? (
            <p className="py-6 text-center text-sm text-danger-strong">
              {t('variants.loadError')}
            </p>
          ) : (
            <ProductVariantStockView
              basePrice={product.sale_price}
              variants={variants}
              selectedVariantId={selectedVariantId}
              onSelect={setSelectedVariantId}
            />
          )}
        </div>

        <div className="mt-3 border-t border-border-subtle pt-3">
          <button
            type="button"
            onClick={handleConfirm}
            disabled={selectedVariantId === null}
            className="flex min-h-[48px] w-full items-center justify-center rounded-ctl bg-action px-6 py-3 text-base font-semibold text-ink-inverse transition-colors hover:bg-action-hover disabled:cursor-not-allowed disabled:opacity-50"
          >
            {t('variants.addToCart')}
          </button>
        </div>
      </div>
    </Modal>
  );
}
