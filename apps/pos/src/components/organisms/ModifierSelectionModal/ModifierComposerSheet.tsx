import { useState, useMemo, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { Check, X } from 'lucide-react';
import { useCurrency } from '@/lib/currency';
import { bcadd, bccomp, bcsum } from '@/lib/decimal';
import { cn } from '@/lib/utils';
import type { POSProduct } from '@/types/product';
import type { ModifierGroup, Modifier } from '@/types/modifier';
import type { SelectedModifier } from '@/types/cart';

/**
 * Cart-always-foreground v1 (spec §2.3) — the modifier composition UI as a
 * pure pane-hosted content component, extracted from the retired
 * ModifierSelectionModal (same pattern as ProductDetailSheet). Gating,
 * pricing, and selection semantics are unchanged; the <Modal> shell is gone.
 * Confirm → onConfirm (host adds + closes); the header X / footer → onClose.
 * NO Esc handling here: composing is a task, closed only explicitly (spec §4).
 */
export interface ModifierComposerSheetProps {
  product: POSProduct;
  onConfirm: (selectedModifiers: SelectedModifier[]) => void;
  onClose: () => void;
}

type SelectionMap = Record<string, Set<string>>;

function getDefaultSelections(groups: ModifierGroup[]): SelectionMap {
  const selections: SelectionMap = {};
  for (const group of groups) {
    const defaults = new Set<string>();
    for (const mod of group.modifiers) {
      if (mod.is_default && mod.is_active) {
        defaults.add(mod.id);
      }
    }
    selections[group.id] = defaults;
  }
  return selections;
}

function isGroupSatisfied(group: ModifierGroup, selected: Set<string>): boolean {
  return selected.size >= group.min_selections;
}

export function ModifierComposerSheet({ product, onConfirm, onClose }: ModifierComposerSheetProps) {
  const { t } = useTranslation('pos');
  const { format, decimals } = useCurrency();
  const groups = product.modifier_groups ?? [];

  const [selections, setSelections] = useState<SelectionMap>(() => getDefaultSelections(groups));

  // Reset to defaults when the product swaps in place (pane host keeps this
  // component mounted across a product change). Render-time reset — the same
  // prev-id pattern the detail sheet's old host used.
  const [prevProductId, setPrevProductId] = useState(product.id);
  if (product.id !== prevProductId) {
    setPrevProductId(product.id);
    setSelections(getDefaultSelections(groups));
  }

  const handleToggleModifier = useCallback(
    (group: ModifierGroup, modifier: Modifier) => {
      setSelections((prev) => {
        const current = new Set(prev[group.id] ?? []);

        if (group.selection_type === 'single') {
          // Radio behavior: replace
          const next = new Set<string>();
          if (!current.has(modifier.id)) {
            next.add(modifier.id);
          }
          return { ...prev, [group.id]: next };
        }

        // Multiple: toggle with max cap
        if (current.has(modifier.id)) {
          current.delete(modifier.id);
        } else if (current.size < group.max_selections) {
          current.add(modifier.id);
        }
        return { ...prev, [group.id]: new Set(current) };
      });
    },
    [],
  );

  const allValid = useMemo(() => {
    return groups.every((g) => isGroupSatisfied(g, selections[g.id] ?? new Set()));
  }, [groups, selections]);

  // Decimal-string total (rule 19: never parseFloat money). Same displayed
  // value as the old float math for every currency-scale input.
  const totalPrice = useMemo(() => {
    const adjustments: string[] = [];
    for (const group of groups) {
      const selected = selections[group.id] ?? new Set<string>();
      for (const mod of group.modifiers) {
        if (selected.has(mod.id)) {
          adjustments.push(mod.price_adjustment);
        }
      }
    }
    return bcadd(product.sale_price ?? '0', bcsum(adjustments, decimals), decimals);
  }, [product.sale_price, groups, selections, decimals]);

  const handleConfirm = useCallback(() => {
    if (!allValid) return;

    const selectedModifiers: SelectedModifier[] = [];
    for (const group of groups) {
      const selected = selections[group.id] ?? new Set();
      for (const mod of group.modifiers) {
        if (selected.has(mod.id)) {
          selectedModifiers.push({
            modifier_id: mod.id,
            modifier_group_id: group.id,
            name: mod.name,
            group_name: group.name,
            price_adjustment: mod.price_adjustment,
          });
        }
      }
    }

    onConfirm(selectedModifiers);
  }, [allValid, groups, selections, onConfirm]);

  return (
    <section
      role="region"
      aria-label={t('modifiers.customize')}
      data-testid="modifier-composer-sheet"
      className="relative flex h-full w-full min-w-[680px] flex-col overflow-hidden rounded-panel bg-surface-overlay shadow-sm"
    >
      {/* Own header — the Modal shell used to provide title + close. */}
      <div className="flex shrink-0 items-center justify-between border-b border-border-subtle px-6 py-4">
        <h2 className="text-xl font-bold text-ink">{t('modifiers.customize')}</h2>
        <button
          type="button"
          onClick={onClose}
          aria-label={t('modifiers.cancel')}
          className="flex h-12 w-12 shrink-0 items-center justify-center rounded-ctl border border-border-subtle bg-surface-raised text-ink-muted active:bg-surface-sunken"
        >
          <X className="h-5 w-5" aria-hidden="true" />
        </button>
      </div>

      <div className="flex min-h-0 flex-1 flex-col px-6 py-4">
        {/* Product header */}
        <div className="mb-4 shrink-0 rounded-card bg-surface-sunken px-4 py-3">
          <h3 className="text-lg font-bold text-ink">{product.name}</h3>
          <p className="text-sm text-ink-muted">
            {t('modifiers.basePrice')}: {format(product.sale_price ?? '0')}
          </p>
        </div>

        {/* All groups visible */}
        <div className="min-h-0 flex-1 overflow-y-auto">
          {groups.map((group) => {
            const selected = selections[group.id] ?? new Set();
            const satisfied = isGroupSatisfied(group, selected);

            return (
              <div key={group.id} className="mb-4">
                <div className="mb-2 flex items-center justify-between">
                  <span className="text-sm font-semibold text-ink">
                    {group.name}
                    {group.is_required && !satisfied && (
                      <span className="ml-1 text-danger">*</span>
                    )}
                  </span>
                  {group.selection_type === 'multiple' && (
                    <span className="text-xs text-ink-muted">
                      {t('modifiers.selectUpTo', { max: group.max_selections })}
                      {' '}({selected.size}/{group.max_selections})
                    </span>
                  )}
                </div>

                <div className="flex flex-wrap gap-2">
                  {group.modifiers
                    .filter((m) => m.is_active)
                    .sort((a, b) => a.position - b.position)
                    .map((modifier) => {
                      const isSelected = selected.has(modifier.id);
                      const priceAdjSign = bccomp(modifier.price_adjustment, '0');

                      return (
                        <button
                          key={modifier.id}
                          onClick={() => handleToggleModifier(group, modifier)}
                          className={cn(
                            'flex min-h-12 items-center gap-2 rounded-pill border-2 px-4 py-2 text-sm font-medium transition-all',
                            isSelected
                              ? 'border-accent bg-accent-tint text-accent-strong'
                              : 'border-border-subtle bg-surface-raised text-ink-muted hover:border-border-strong',
                          )}
                        >
                          {isSelected && (
                            <Check className="h-3.5 w-3.5 text-accent-strong" />
                          )}
                          {modifier.name}
                          {priceAdjSign !== 0 && (
                            <span className={cn(
                              'text-xs',
                              priceAdjSign > 0 ? 'text-ink-muted' : 'text-success-strong',
                            )}>
                              {priceAdjSign > 0 ? '+' : ''}{format(modifier.price_adjustment)}
                            </span>
                          )}
                        </button>
                      );
                    })}
                </div>
              </div>
            );
          })}
        </div>

        {/* Footer: total + add to cart */}
        <div className="mt-2 shrink-0 border-t border-border-subtle pt-2">
          <div className="mb-3 flex items-center justify-between">
            <span className="text-sm text-ink-muted">
              {t('modifiers.selected')}: {Object.values(selections).reduce((sum, s) => sum + s.size, 0)}
            </span>
            <span className="text-xl font-bold text-ink">{format(totalPrice)}</span>
          </div>
          <button
            onClick={handleConfirm}
            disabled={!allValid}
            className="flex min-h-[48px] w-full items-center justify-center gap-2 rounded-ctl bg-action px-6 py-3 text-base font-semibold text-ink-inverse transition-colors hover:bg-action-hover disabled:cursor-not-allowed disabled:opacity-50"
          >
            {t('modifiers.addToCart')}
          </button>
          {!allValid && (
            <p className="mt-2 text-center text-sm text-danger">
              {t('modifiers.required')}
            </p>
          )}
        </div>
      </div>
    </section>
  );
}
