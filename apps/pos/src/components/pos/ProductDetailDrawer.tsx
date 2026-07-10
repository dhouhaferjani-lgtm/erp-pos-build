import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Check, MapPin, PackagePlus, Plus, ShoppingCart, X } from 'lucide-react';
import { useCurrency } from '@/lib/currency';
import { addItemGated } from '@/lib/stock/cartIngress';
import { cn } from '@/lib/utils';
import { ProductThumb } from '@/components/ui/ProductThumb';
import { StockBadge } from '@/components/ui/StockBadge';
import { Button } from '@/components/ui';
import { RequestRefillSheet } from '@/components/organisms/RequestRefillSheet';
import { tokens } from '@/lib/designTokens';
import { useProductStore, hasModule } from '@/stores/productStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { CrossLocationStockSection } from '@/components/organisms/CrossLocationStockSection/CrossLocationStockSection';
import { useStockDisplay } from '@/components/organisms/ProductGrid/useStockDisplay';
import type { POSProduct } from '@/types/product';
import type { LocationStockDisplay } from '@/lib/stock/gridStock';

interface ProductDetailDrawerProps {
  isOpen: boolean;
  onClose: () => void;
  product: POSProduct | null;
  /** Same slice semantics as ProductCard: object -> location-aware; null -> exempt (no chrome); undefined -> legacy fallback. */
  locationStock?: LocationStockDisplay | null;
  /**
   * Task 18 — OOS-policy alignment with ProductCard/useStockDisplay: under
   * 'warn'/'off' the tap must reach the stock gate (which allows the add and
   * surfaces the warning toast), only 'block' pre-disables here. Defaults to
   * `true` (fail-safe) — HomePage threads `posStockPolicy === 'block'`, the
   * same value it passes to the grid.
   */
  hardBlockOutOfStock?: boolean;
}

export type DetailTab = 'details' | 'routine' | 'equivalents' | 'complements' | 'stock_lots' | 'other_branches';

interface RoutineStep {
  product: POSProduct;
  step_label: string;
  step_order: number;
  routine_id: string;
}

const DETAILS_TAB: DetailTab = 'details';

export function ProductDetailDrawer({
  isOpen,
  onClose,
  product,
  locationStock,
  hardBlockOutOfStock = true,
}: ProductDetailDrawerProps) {
  // Tab state lives HERE (not in the sheet) so it survives the sheet
  // unmounting while closed. Note it resets whenever product?.id changes —
  // including on close, since HomePage nulls the product (product → null) —
  // so in practice reopening always starts on Details.
  const [activeTab, setActiveTab] = useState<DetailTab>(DETAILS_TAB);
  const [prevProductId, setPrevProductId] = useState<string | undefined>(product?.id);
  if (product?.id !== prevProductId) {
    setPrevProductId(product?.id);
    setActiveTab(DETAILS_TAB);
  }

  if (!isOpen || !product) return null;

  return (
    <div className="fixed inset-0 z-[52] flex items-center justify-center bg-black/50 p-3 ez-fade-in" onClick={onClose}>
      <ProductDetailSheet
        product={product}
        onClose={onClose}
        locationStock={locationStock}
        hardBlockOutOfStock={hardBlockOutOfStock}
        activeTab={activeTab}
        onTabChange={setActiveTab}
      />
    </div>
  );
}

interface ProductDetailSheetProps {
  product: POSProduct;
  onClose: () => void;
  locationStock?: LocationStockDisplay | null;
  hardBlockOutOfStock?: boolean;
  activeTab: DetailTab;
  onTabChange: (tab: DetailTab) => void;
}

/**
 * The drawer's sheet content, extracted so /theme-preview can render it
 * inline (no overlay) for headless visual verification. The overlay host,
 * placement, and z-strategy stay in ProductDetailDrawer — this component is
 * purely the one-sheet surface.
 */
export function ProductDetailSheet({
  product,
  onClose,
  locationStock,
  hardBlockOutOfStock = true,
  activeTab,
  onTabChange,
}: ProductDetailSheetProps) {
  const { t } = useTranslation('pos');
  const { t: tSmart } = useTranslation('smart-prompts');
  const { format } = useCurrency();
  const [refillOpen, setRefillOpen] = useState(false);
  const allowCrossLocation = useProductStore(
    (s) => s.companyConfig?.allow_cross_location_stock_view === true,
  );
  const canViewCrossLocation = useOperatorStore(
    (s) => s.operator?.permissions?.includes('pos.view_cross_location_stock') ?? false,
  );
  const currentLocationId = useTerminalStore((s) => s.terminal?.location?.id ?? null);
  const currentLocationName = useTerminalStore((s) => s.terminal?.location?.name ?? null);
  const companyConfig = useProductStore((s) => s.companyConfig);
  const getByIds = useProductStore((s) => s.getByIds);
  const allProducts = useProductStore((s) => s.products);
  const hasMerchandising = hasModule(companyConfig, 'Merchandising');

  // Owner review defect 3 — unified stock treatment: reuse the EXACT grid
  // recipe (useStockDisplay → "Stock N" / "Rupture" + StockBadge status)
  // instead of the previous hand-rolled bare number. Also keeps the OOS
  // gating semantics aligned with ProductCard by construction (exempt slice
  // → no chrome, never gated; 'block'-only pre-disable).
  const { isOutOfStock, stockLabel, status, isActivationBlocked } = useStockDisplay(
    product,
    locationStock,
    hardBlockOutOfStock,
  );

  const meta = product.parapharmacy_metadata;
  const showMerchandising = hasMerchandising && meta != null;
  const equivalents = showMerchandising ? getByIds(meta.equivalent_product_ids) : [];
  const complements = showMerchandising ? getByIds(meta.complement_product_ids) : [];
  const routineSteps = showMerchandising ? buildRoutineSteps(product, allProducts) : [];
  const benefitLabels = meta?.suitable_skin_types ?? [];
  const ingredientLabels = [product.category, product.brand_name].filter((v): v is string => Boolean(v));
  // The formatter already renders the currency marker for every currency
  // (TND/fr-TN → "9,990 DT", EUR/fr-FR → "38,50 €") — never append one here.
  const priceText = format(product.sale_price ?? '0');
  const brand = product.brand_name ?? t('productDetail.brandFallback');

  const tabs: { id: DetailTab; label: string; count?: number }[] = [
    { id: 'details', label: t('productDetail.tabs.details') },
    { id: 'routine', label: t('productDetail.merchandising.routine'), count: routineSteps.length },
    { id: 'equivalents', label: t('productDetail.merchandising.equivalents'), count: equivalents.length },
    { id: 'complements', label: t('productDetail.merchandising.complements'), count: complements.length },
    // Task 18 — shells only (Spec 2 owns the batch/branch data + resolution).
    { id: 'stock_lots', label: t('productDetail.tabs.stockLots') },
    { id: 'other_branches', label: t('productDetail.tabs.otherBranches') },
  ];

  return (
    <section
      role="dialog"
      aria-modal="true"
      aria-label={t('productDetail.title')}
      data-testid="product-detail-modal"
      className="ez-sheet-rise relative flex h-[680px] max-h-[92vh] w-[1080px] max-w-[96vw] overflow-hidden rounded-panel bg-surface-overlay shadow-2xl"
      onClick={(event) => event.stopPropagation()}
    >
      <aside
        data-testid="product-detail-left-column"
        className="flex w-[344px] shrink-0 flex-col px-6 py-7"
      >
        <div className="relative flex h-[188px] shrink-0 items-center justify-center overflow-hidden rounded-card bg-surface-sunken">
          <ProductThumb
            name={product.name}
            category={product.category}
            imageUrl={product.image_url}
            size={148}
          />
          {isOutOfStock && (
            <span className="absolute top-3 left-3 rounded-pill border border-danger-subtle bg-surface-raised px-3 py-1.5 text-xs font-bold text-danger-strong">
              {t('products.outOfStock')}
            </span>
          )}
        </div>

        <div className="mt-5 text-xs font-bold tracking-[0.06em] text-ink-muted uppercase">{brand}</div>
        <h2 className="mt-1 font-display text-[21px] leading-tight font-bold text-ink-strong">
          {product.name}
        </h2>
        <div className="mt-3 flex flex-wrap items-center gap-2">
          {status !== null && stockLabel !== null && (
            <StockBadge data-testid="drawer-stock-row" status={status}>
              {stockLabel}
            </StockBadge>
          )}
          {product.category && (
            <span className="text-sm text-ink-faint">{product.category}</span>
          )}
        </div>

        <div className="mt-5 flex items-end justify-between border-t border-border-subtle pt-5">
          <div>
            <div className="text-sm text-ink-faint">{t('productDetail.priceTtc')}</div>
            <div className="font-mono text-[28px] leading-tight font-semibold tabular-nums text-ink-strong">
              {priceText}
            </div>
          </div>
          <div className="text-right font-mono text-[11px] leading-relaxed text-ink-faint">
            {product.sku && <div>{product.sku}</div>}
            {product.barcode && <div>{product.barcode}</div>}
          </div>
        </div>

        <button
          type="button"
          aria-label={t('productDetail.addToCart')}
          disabled={isActivationBlocked}
          onClick={() => { void addItemGated(product); }}
          className={cn(
            tokens.button.primary,
            // `shadow-sm` is a call-site addition on top of tokens.button.primary
            // (which has no shadow) — without disabled:shadow-none the disabled
            // state keeps looking elevated/tappable. A subtle border makes the
            // disabled look read as inert rather than a duller primary button.
            'mt-5 h-[54px] w-full text-base shadow-sm disabled:border disabled:border-border-subtle disabled:shadow-none',
          )}
        >
          <ShoppingCart className="h-5 w-5" aria-hidden="true" />
          {t('productDetail.addToCart')}
        </button>
        <Button
          className="mt-2"
          variant="secondary"
          fullWidth
          leftIcon={<PackagePlus className="h-5 w-5" aria-hidden="true" />}
          onClick={() => setRefillOpen(true)}
        >
          {t('replenishment.request_refill')}
        </Button>
      </aside>

      <RequestRefillSheet
        isOpen={refillOpen}
        onClose={() => setRefillOpen(false)}
        product={product}
      />

      {/* Owner review defect 4 — one coherent sheet: the pane separator is an
          INSET internal divider (not an edge-to-edge pane border), so the two
          panes read as zones of a single surface instead of two cards. */}
      <div aria-hidden="true" data-testid="drawer-pane-divider" className="my-6 w-px shrink-0 bg-border-subtle" />

      <div data-testid="product-detail-right-column" className="flex min-w-0 flex-1 flex-col">
        {/* Owner review defect 1 — the close X is a flex sibling of the tab
            strip with its own reserved gutter: it can never overlap a tab at
            any width. The strip itself scrolls horizontally (scrollbar-none,
            same recipe as the grid's category strip) and tabs never wrap
            mid-label. */}
        <div className="flex shrink-0 items-center gap-2 border-b border-border-subtle pt-4 pr-4 pl-5">
          <div
            role="tablist"
            aria-label={t('productDetail.merchandising.ariaLabel')}
            className="scrollbar-none flex min-w-0 flex-1 gap-0.5 overflow-x-auto"
          >
            {tabs.map((tab) => (
              <button
                key={tab.id}
                type="button"
                role="tab"
                aria-selected={activeTab === tab.id}
                onClick={() => onTabChange(tab.id)}
                className={cn(
                  'min-h-12 shrink-0 border-b-2 px-2.5 pt-2 pb-3 text-sm font-semibold whitespace-nowrap',
                  activeTab === tab.id
                    ? 'border-action text-ink-strong'
                    : 'border-transparent text-ink-muted active:text-ink',
                )}
              >
                {tab.label}
                {/* Owner review defect 2 — a zero count reads as a dead tab:
                    the badge only renders when there is something behind it. */}
                {tab.count !== undefined && tab.count > 0 && (
                  <span className="ml-1.5 font-mono text-xs text-ink-faint">{tab.count}</span>
                )}
              </button>
            ))}
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label={t('products.filtersClose')}
            className="flex h-12 w-12 shrink-0 items-center justify-center rounded-ctl border border-border-subtle bg-surface-raised text-ink-muted active:bg-surface-sunken"
          >
            <X className="h-5 w-5" aria-hidden="true" />
          </button>
        </div>

        <div className="min-h-0 flex-1 overflow-y-auto px-6 py-5">
          {activeTab === 'details' && (
            <DetailsPanel
              product={product}
              benefitLabels={benefitLabels}
              ingredientLabels={ingredientLabels}
              currentLocationName={currentLocationName}
              stockLabel={stockLabel}
              isOut={isOutOfStock}
              t={t}
              tSmart={tSmart}
            />
          )}
          {activeTab === 'routine' && (
            <RoutinePanel
              steps={routineSteps}
              currentProductId={product.id}
              emptyText={t('productDetail.merchandising.empty')}
              currentLabel={t('productDetail.currentSelection')}
            />
          )}
          {activeTab === 'equivalents' && (
            <RelatedPanel products={equivalents} emptyText={t('productDetail.merchandising.empty')} intro={t('productDetail.tabs.equivalentsIntro')} />
          )}
          {activeTab === 'complements' && (
            <RelatedPanel products={complements} emptyText={t('productDetail.merchandising.empty')} intro={t('productDetail.tabs.complementsIntro')} />
          )}
          {activeTab === 'stock_lots' && (
            <EmptyState>{t('productDetail.tabs.stockLotsComingSoon')}</EmptyState>
          )}
          {activeTab === 'other_branches' && (
            <EmptyState>{t('productDetail.tabs.otherBranchesComingSoon')}</EmptyState>
          )}

          <CrossLocationStockSection
            product={product}
            canView={allowCrossLocation && canViewCrossLocation}
            currentLocationId={currentLocationId}
          />
        </div>
      </div>
    </section>
  );
}

function buildRoutineSteps(product: POSProduct, allProducts: POSProduct[]): RoutineStep[] {
  const refs = product.parapharmacy_metadata?.routine_refs ?? [];
  const routineIds = new Set(refs.map((r) => r.routine_id));
  if (routineIds.size === 0) return [];

  const steps: RoutineStep[] = [];
  for (const candidate of allProducts) {
    for (const ref of candidate.parapharmacy_metadata?.routine_refs ?? []) {
      if (routineIds.has(ref.routine_id)) {
        steps.push({
          product: candidate,
          step_label: ref.step_label,
          step_order: ref.step_order,
          routine_id: ref.routine_id,
        });
      }
    }
  }
  steps.sort((a, b) =>
    a.routine_id !== b.routine_id
      ? a.routine_id.localeCompare(b.routine_id)
      : a.step_order - b.step_order,
  );
  return steps;
}

function DetailsPanel({
  product,
  benefitLabels,
  ingredientLabels,
  currentLocationName,
  stockLabel,
  isOut,
  t,
  tSmart,
}: {
  product: POSProduct;
  benefitLabels: string[];
  ingredientLabels: string[];
  currentLocationName: string | null;
  /** Unified stock label from useStockDisplay; null when stock-exempt (no figure shown). */
  stockLabel: string | null;
  isOut: boolean;
  t: (key: string, options?: Record<string, unknown>) => string;
  tSmart: (key: string, options?: Record<string, unknown>) => string;
}) {
  return (
    <div>
      <p className="text-[15px] leading-relaxed text-ink">
        {(product as { description?: string }).description ?? t('productDetail.descriptionFallback')}
      </p>

      <SectionTitle>{t('productDetail.benefitsTitle')}</SectionTitle>
      <div className="flex flex-wrap gap-2">
        {(benefitLabels.length > 0 ? benefitLabels : ['normal']).map((label) => (
          <span
            key={label}
            className="inline-flex min-h-10 items-center gap-2 rounded-pill border border-success-subtle bg-success-surface px-3 text-sm font-semibold text-success-strong"
          >
            <Check className="h-4 w-4" aria-hidden="true" />
            {tSmart(`skin_type.${label}`, { defaultValue: label })}
          </span>
        ))}
      </div>

      <SectionTitle>{t('productDetail.ingredientsTitle')}</SectionTitle>
      <div className="flex flex-wrap gap-2">
        {(ingredientLabels.length > 0 ? ingredientLabels : [t('productDetail.defaultIngredient')]).map((label) => (
          <span
            key={label}
            className="inline-flex min-h-10 items-center rounded-ctl border border-border-subtle bg-surface-sunken px-3 text-sm font-medium text-ink"
          >
            {label}
          </span>
        ))}
      </div>

      <SectionTitle>{t('productDetail.availabilityTitle')}</SectionTitle>
      <div className="flex flex-col gap-2">
        <div className="flex min-h-12 items-center gap-3 rounded-ctl border border-action-subtle bg-action-subtle px-4">
          <MapPin className="h-4 w-4 shrink-0 text-action" aria-hidden="true" />
          <span className="min-w-0 flex-1 text-sm font-semibold text-ink">
            {currentLocationName ?? t('productDetail.currentBranch')}
            <span className="ml-2 rounded-pill bg-surface-raised px-2 py-0.5 text-xs font-bold text-action">
              {t('productDetail.here')}
            </span>
          </span>
          {stockLabel !== null && (
            <span className={cn('font-mono text-sm font-semibold', isOut ? 'text-danger-strong' : 'text-ink-strong')}>
              {stockLabel}
            </span>
          )}
        </div>
      </div>
    </div>
  );
}

function RoutinePanel({
  steps,
  currentProductId,
  emptyText,
  currentLabel,
}: {
  steps: RoutineStep[];
  currentProductId: string;
  emptyText: string;
  currentLabel: string;
}) {
  if (steps.length === 0) return <EmptyState>{emptyText}</EmptyState>;
  return (
    <div className="flex flex-col gap-2">
      {steps.map((step, index) => (
        <button
          key={`${step.routine_id}-${step.product.id}-${index}`}
          type="button"
          data-testid="routine-step-row"
          onClick={() => { void addItemGated(step.product); }}
          className="flex min-h-[64px] w-full items-center gap-3 rounded-card border border-border-subtle bg-surface-raised px-3 text-left active:bg-surface-sunken"
        >
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-pill bg-action text-sm font-bold text-ink-inverse">
            {step.step_order}
          </span>
          <ProductThumb name={step.product.name} category={step.product.category} imageUrl={step.product.image_url} size={48} />
          <span className="min-w-0 flex-1">
            <span className="block text-xs font-bold tracking-wide text-ink-muted uppercase">{step.step_label}</span>
            <span className="block truncate text-sm font-semibold text-ink">{step.product.name}</span>
          </span>
          {step.product.id === currentProductId ? (
            <span className="rounded-pill bg-action-subtle px-3 py-1 text-xs font-bold text-action">{currentLabel}</span>
          ) : (
            <span data-testid="merch-add-btn" className="flex h-10 w-10 items-center justify-center rounded-ctl bg-action-subtle text-action">
              <Plus className="h-5 w-5" aria-hidden="true" />
            </span>
          )}
        </button>
      ))}
    </div>
  );
}

function RelatedPanel({
  products,
  emptyText,
  intro,
}: {
  products: POSProduct[];
  emptyText: string;
  intro: string;
}) {
  if (products.length === 0) return <EmptyState>{emptyText}</EmptyState>;
  return (
    <div>
      <p className="mb-4 text-sm text-ink-muted">{intro}</p>
      <div className="grid grid-cols-2 gap-3">
        {products.map((product) => (
          <button
            key={product.id}
            type="button"
            data-testid="merch-add-btn"
            onClick={() => { void addItemGated(product); }}
            className="flex min-h-[74px] items-center gap-3 rounded-card border border-border-subtle bg-surface-raised p-3 text-left active:bg-surface-sunken"
          >
            <ProductThumb name={product.name} category={product.category} imageUrl={product.image_url} size={48} />
            <span className="min-w-0 flex-1">
              <span className="block truncate text-[11px] font-bold tracking-wide text-ink-faint uppercase">
                {product.brand_name}
              </span>
              <span className="block truncate text-sm font-semibold text-ink">{product.name}</span>
              <span className="block font-mono text-sm font-semibold text-ink-strong">{product.sale_price}</span>
            </span>
            <Plus className="h-5 w-5 shrink-0 text-action" aria-hidden="true" />
          </button>
        ))}
      </div>
    </div>
  );
}

function SectionTitle({ children }: { children: ReactNode }) {
  return (
    <h3 className="mt-6 mb-3 text-xs font-bold tracking-[0.05em] text-ink-faint uppercase">
      {children}
    </h3>
  );
}

function EmptyState({ children }: { children: ReactNode }) {
  return (
    <p className="flex h-44 items-center justify-center rounded-card border border-border-subtle bg-surface-sunken text-sm font-medium text-ink-muted">
      {children}
    </p>
  );
}
