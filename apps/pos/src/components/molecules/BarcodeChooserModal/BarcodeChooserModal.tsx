import { useEffect, useRef, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { X } from 'lucide-react';
import type { POSProduct } from '@/types/product';

/**
 * T2.1 Step B — collision chooser modal for barcode/SKU scans that
 * resolve to MULTIPLE products (UPC overlap, internal renumbering,
 * weight-embedded barcodes — common in deli scales / produce).
 *
 * Cashier picks one → `onPick` fires; cashier dismisses (ESC, X, or
 * outside-click) → `onDismiss` fires (no add, no error).
 *
 * Matches the Toast / Shopify POS chooser pattern.
 */
export interface BarcodeChooserModalProps {
  isOpen: boolean;
  scannedCode: string;
  candidates: POSProduct[];
  onPick: (product: POSProduct) => void;
  onDismiss: () => void;
}

export function BarcodeChooserModal({
  isOpen,
  scannedCode,
  candidates,
  onPick,
  onDismiss,
}: BarcodeChooserModalProps) {
  const { t } = useTranslation('pos');
  const firstButtonRef = useRef<HTMLButtonElement>(null);

  // Trap focus on the first candidate when the modal opens.
  useEffect(() => {
    if (isOpen) {
      firstButtonRef.current?.focus();
    }
  }, [isOpen]);

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent) => {
      if (e.key === 'Escape') {
        onDismiss();
      }
    },
    [onDismiss],
  );

  if (!isOpen) return null;

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="barcode-chooser-title"
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
      onKeyDown={handleKeyDown}
      onClick={onDismiss}
    >
      <div
        className="flex max-h-[80vh] w-full max-w-md flex-col overflow-hidden rounded-xl bg-white shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between border-b border-gray-200 px-4 py-3">
          <div>
            <h2
              id="barcode-chooser-title"
              className="text-lg font-bold text-gray-900"
            >
              {t('barcodeChooser.title')}
            </h2>
            <p className="mt-1 text-sm text-gray-600">
              {t('barcodeChooser.subtitle', { code: scannedCode })}
            </p>
          </div>
          <button
            type="button"
            onClick={onDismiss}
            aria-label={t('barcodeChooser.dismiss')}
            className="rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-900"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="flex-1 overflow-y-auto">
          <ul className="divide-y divide-gray-200" data-testid="barcode-chooser-list">
            {candidates.map((product, idx) => (
              <li key={product.id}>
                <button
                  type="button"
                  ref={idx === 0 ? firstButtonRef : undefined}
                  onClick={() => onPick(product)}
                  className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-gray-50 focus:bg-gray-100 focus:outline-none"
                  data-testid={`barcode-chooser-row-${product.id}`}
                >
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-semibold text-gray-900">
                      {product.name}
                    </p>
                    <p className="text-xs text-gray-500">
                      <span className="font-mono">{product.sku}</span>
                      {product.barcode && (
                        <>
                          {' · '}
                          <span className="font-mono">{product.barcode}</span>
                        </>
                      )}
                    </p>
                  </div>
                  <div className="text-right">
                    <p className="font-mono text-sm font-semibold text-gray-900">
                      {product.sale_price}
                    </p>
                    <p className="text-xs text-gray-500">
                      {t('barcodeChooser.stock', { count: product.stock_quantity })}
                    </p>
                  </div>
                </button>
              </li>
            ))}
          </ul>
        </div>

        <div className="border-t border-gray-200 px-4 py-3">
          <button
            type="button"
            onClick={onDismiss}
            className="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
          >
            {t('barcodeChooser.cancel')}
          </button>
        </div>
      </div>
    </div>
  );
}
