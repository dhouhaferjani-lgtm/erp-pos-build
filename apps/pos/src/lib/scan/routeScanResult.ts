import type { ResolveScannedCodeResult } from '@/lib/scan/resolveScannedCode';
import type { POSProduct, POSProductVariant } from '@/types/product';

export interface ScanResultHandlers {
  addProductToCartWithToast: (product: POSProduct) => void;
  addVariantToCart: (product: POSProduct, variant: POSProductVariant) => void;
  openVariantPicker: (product: POSProduct) => void;
  showChooser: (scannedCode: string, candidates: POSProduct[]) => void;
  showNotFound: (scannedCode: string) => void;
}

/**
 * Route a resolved scan to the right cart/UI action.
 *
 * - variant-hit  → add the exact variant directly (the barcode identified the
 *   specific variant; no picker needed).
 * - hit + has_variants=true → open the picker; fixes the bug where scanning a
 *   parent product's barcode added the base product instead of letting the
 *   cashier pick a variant (matches the tile-tap path in handleAddToCart).
 * - hit (no variants) → add the product via the standard toast path.
 * - choose → mount the BarcodeChooserModal for the cashier to resolve.
 * - miss  → show a "not found" error message.
 */
export function routeScanResult(
  result: ResolveScannedCodeResult,
  scannedCode: string,
  h: ScanResultHandlers,
): void {
  switch (result.kind) {
    case 'variant-hit':
      h.addVariantToCart(result.product, result.variant);
      return;
    case 'hit':
      if (result.product.has_variants) {
        h.openVariantPicker(result.product);
        return;
      }
      h.addProductToCartWithToast(result.product);
      return;
    case 'choose':
      h.showChooser(scannedCode, result.candidates);
      return;
    case 'miss':
      h.showNotFound(scannedCode);
      return;
  }
}
