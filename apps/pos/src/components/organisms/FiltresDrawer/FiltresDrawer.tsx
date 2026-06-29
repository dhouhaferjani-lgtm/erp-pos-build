import { useMemo, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { Drawer, Pill } from '@/components/ui';
import type { POSProduct } from '@/types/product';

export interface FiltresFilters {
  brands: string[];
  categories: string[];
  skinTypes: string[];
}

export const EMPTY_FILTRES_FILTERS: FiltresFilters = {
  brands: [],
  categories: [],
  skinTypes: [],
};

export interface FiltresDrawerProps {
  isOpen: boolean;
  onClose: () => void;
  products: POSProduct[];
  filters: FiltresFilters;
  onFiltersChange: (filters: FiltresFilters) => void;
  /** Count of products currently matching the active filters (live result count). */
  resultCount: number;
}

/**
 * FiltresDrawer — module-gated (Merchandising) multi-select filter panel.
 *
 * Facets derived from the loaded product set:
 *   - brand (brand_name)
 *   - category
 *   - skin type (parapharmacy_metadata.suitable_skin_types)
 *
 * Routine facet: DEFERRED. Products carry routine_refs ({routine_id, step_order, step_label}[])
 * but NOT routine display names. Surfacing routine_id UUIDs as facet keys without a name lookup
 * produces meaningless labels. Until a routine-name endpoint (or server-side denormalization) is
 * available, the routine facet is omitted.
 */
export function FiltresDrawer({
  isOpen,
  onClose,
  products,
  filters,
  onFiltersChange,
  resultCount,
}: FiltresDrawerProps) {
  const { t } = useTranslation('pos');

  // Derive deduplicated, sorted facet options from the in-memory product set.
  const facets = useMemo(() => {
    const brands = new Set<string>();
    const categories = new Set<string>();
    const skinTypes = new Set<string>();

    for (const p of products) {
      if (p.brand_name) brands.add(p.brand_name);
      if (p.category) categories.add(p.category);
      const sst = p.parapharmacy_metadata?.suitable_skin_types;
      if (sst) {
        for (const st of sst) skinTypes.add(st);
      }
    }

    return {
      brands: Array.from(brands).sort(),
      categories: Array.from(categories).sort(),
      skinTypes: Array.from(skinTypes).sort(),
    };
  }, [products]);

  const toggle = useCallback(
    (key: keyof FiltresFilters, value: string) => {
      const current = filters[key];
      const next = current.includes(value)
        ? current.filter((v) => v !== value)
        : [...current, value];
      onFiltersChange({ ...filters, [key]: next });
    },
    [filters, onFiltersChange],
  );

  const clearAll = useCallback(
    () => onFiltersChange(EMPTY_FILTRES_FILTERS),
    [onFiltersChange],
  );

  const hasActiveFilters =
    filters.brands.length > 0 ||
    filters.categories.length > 0 ||
    filters.skinTypes.length > 0;

  return (
    <Drawer
      isOpen={isOpen}
      onClose={onClose}
      title={t('products.filters')}
      closeLabel={t('products.filtersClose')}
      footer={
        <div className="flex items-center justify-between">
          <p className="text-sm text-ink-muted">
            {t('products.filtersResultCount', { count: resultCount })}
          </p>
          {hasActiveFilters && (
            <button
              type="button"
              onClick={clearAll}
              className="text-sm font-medium text-action hover:text-action-strong"
            >
              {t('products.filtersClearAll')}
            </button>
          )}
        </div>
      }
    >
      <div className="flex flex-col gap-6">
        {facets.brands.length > 0 && (
          <section aria-labelledby="filtres-brand-heading">
            <h3
              id="filtres-brand-heading"
              className="mb-3 text-sm font-semibold text-ink"
            >
              {t('products.filtersBrand')}
            </h3>
            <div className="flex flex-wrap gap-2">
              {facets.brands.map((brand) => (
                <Pill
                  key={brand}
                  selected={filters.brands.includes(brand)}
                  onClick={() => toggle('brands', brand)}
                >
                  {brand}
                </Pill>
              ))}
            </div>
          </section>
        )}

        {facets.categories.length > 0 && (
          <section aria-labelledby="filtres-category-heading">
            <h3
              id="filtres-category-heading"
              className="mb-3 text-sm font-semibold text-ink"
            >
              {t('products.filtersCategory')}
            </h3>
            <div className="flex flex-wrap gap-2">
              {facets.categories.map((cat) => (
                <Pill
                  key={cat}
                  selected={filters.categories.includes(cat)}
                  onClick={() => toggle('categories', cat)}
                >
                  {cat}
                </Pill>
              ))}
            </div>
          </section>
        )}

        {facets.skinTypes.length > 0 && (
          <section aria-labelledby="filtres-skin-heading">
            <h3
              id="filtres-skin-heading"
              className="mb-3 text-sm font-semibold text-ink"
            >
              {t('products.filtersSkinType')}
            </h3>
            <div className="flex flex-wrap gap-2">
              {facets.skinTypes.map((st) => (
                <Pill
                  key={st}
                  selected={filters.skinTypes.includes(st)}
                  onClick={() => toggle('skinTypes', st)}
                >
                  {t(`skin_type.${st}`, { ns: 'smart-prompts', defaultValue: st })}
                </Pill>
              ))}
            </div>
          </section>
        )}

        {facets.brands.length === 0 &&
          facets.categories.length === 0 &&
          facets.skinTypes.length === 0 && (
            <p className="text-sm text-ink-faint">{t('products.filtersNoFacets')}</p>
          )}
      </div>
    </Drawer>
  );
}
