import { useEffect, type ReactNode } from 'react';
import { cn } from '@/lib/utils';
import type { POSProduct } from '@/types/product';

/**
 * Cart-always-foreground v1 (spec §1-2, Approach A: pane replacement).
 *
 * The product area is a switched pane — grid | detail | customize — derived
 * from HomePage's existing `detailProduct` / `modifierProduct` state (no new
 * duplicated state; mutual exclusion is enforced in HomePage's setters, this
 * host only derives). The grid stays MOUNTED but hidden while a pane view is
 * active so scroll position and TanStack Virtual state survive; the cart
 * column is a sibling flex child and can never be occluded — there is no
 * overlay, no z-index, no `fixed inset-0` on this path, by construction.
 */
export type ProductPaneView = 'grid' | 'detail' | 'customize';

export function deriveProductPaneView(
  detailProduct: POSProduct | null,
  modifierProduct: POSProduct | null,
): ProductPaneView {
  // Customize wins defensively; HomePage's setters keep the two mutually
  // exclusive so both-set only happens if a future caller regresses.
  if (modifierProduct !== null) return 'customize';
  if (detailProduct !== null) return 'detail';
  return 'grid';
}

export interface ProductPaneHostProps {
  detailProduct: POSProduct | null;
  modifierProduct: POSProduct | null;
  /** Esc / X close for the DETAIL pane. Customize closes ONLY via its explicit confirm/cancel (spec §4). */
  onCloseDetail: () => void;
  renderDetail: (product: POSProduct) => ReactNode;
  renderCustomize: (product: POSProduct) => ReactNode;
  /** The grid view (TableSelector + ProductGrid). Stays mounted (hidden) while a pane is active. */
  children: ReactNode;
}

export function ProductPaneHost({
  detailProduct,
  modifierProduct,
  onCloseDetail,
  renderDetail,
  renderCustomize,
  children,
}: ProductPaneHostProps) {
  const paneView = deriveProductPaneView(detailProduct, modifierProduct);

  // Esc closes the detail pane only (spec §4): inspecting is a context you can
  // dismiss; composing is a task with an explicit confirm/cancel.
  useEffect(() => {
    if (paneView !== 'detail') return;
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return;
      // Esc-stacking guard (Rev 2, U1): every Modal binds its own window Esc
      // listener (Modal.tsx:31-39). While a dialog is stacked above the pane
      // (variant picker, held, customer search…), that Esc belongs to the
      // dialog — bail so one press doesn't close both surfaces.
      if (document.querySelector('[aria-modal="true"]') !== null) return;
      onCloseDetail();
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [paneView, onCloseDetail]);

  return (
    <div data-testid="product-pane-host" className="flex min-h-0 flex-1 flex-col">
      <div
        data-testid="product-pane-grid"
        className={cn(
          'min-h-0 flex-1 flex-col',
          // Explicit flex/hidden branch (never both classes at once) so the
          // display outcome cannot depend on Tailwind's utility output order.
          paneView === 'grid' ? 'flex' : 'hidden',
        )}
      >
        {children}
      </div>
      {/* overflow-x-auto (Rev 2, U8): the sheets carry min-w-[680px]; at
          degenerate pane widths the wrapper scrolls horizontally instead of
          clipping the tab strip and close X inside overflow-hidden parents. */}
      {paneView === 'detail' && detailProduct !== null && (
        <div data-testid="product-pane-detail" className="flex min-h-0 flex-1 flex-col overflow-x-auto">
          {renderDetail(detailProduct)}
        </div>
      )}
      {paneView === 'customize' && modifierProduct !== null && (
        <div data-testid="product-pane-customize" className="flex min-h-0 flex-1 flex-col overflow-x-auto">
          {renderCustomize(modifierProduct)}
        </div>
      )}
    </div>
  );
}
