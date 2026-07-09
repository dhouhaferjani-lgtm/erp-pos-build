import { useState, useEffect, useMemo, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { Modal } from '@/components/pos/Modal';
import { cn } from '@/lib/utils';
import { Check } from 'lucide-react';
import type { POSProduct } from '@/types/product';
import type { ModifierGroup, Modifier } from '@/types/modifier';
import type { SelectedModifier } from '@/types/cart';

export interface ModifierSelectionModalProps {
  isOpen: boolean;
  onClose: () => void;
  product: POSProduct | null;
  onConfirm: (selectedModifiers: SelectedModifier[]) => void;
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

export function ModifierSelectionModal({
  isOpen,
  onClose,
  product,
  onConfirm,
}: ModifierSelectionModalProps) {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();
  const groups = product?.modifier_groups ?? [];

  const [selections, setSelections] = useState<SelectionMap>({});

  // Reset selections when product changes or modal opens
  useEffect(() => {
    if (isOpen && groups.length > 0) {
      setSelections(getDefaultSelections(groups));
    }
  }, [isOpen, product?.id]); // eslint-disable-line react-hooks/exhaustive-deps

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

  const totalPrice = useMemo(() => {
    const basePrice = parseFloat(product?.sale_price ?? '0');
    let adjustment = 0;
    for (const group of groups) {
      const selected = selections[group.id] ?? new Set();
      for (const mod of group.modifiers) {
        if (selected.has(mod.id)) {
          adjustment += parseFloat(mod.price_adjustment);
        }
      }
    }
    return basePrice + adjustment;
  }, [product?.sale_price, groups, selections]);

  const handleConfirm = useCallback(() => {
    if (!allValid || !product) return;

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
  }, [allValid, product, groups, selections, onConfirm]);

  if (!product) return null;

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={t('modifiers.customize')} size="full">
      <div className="flex flex-col">
        {/* Product header */}
        <div className="mb-4 rounded-card bg-surface-sunken px-4 py-3">
          <h3 className="text-lg font-bold text-ink">{product.name}</h3>
          <p className="text-sm text-ink-muted">
            {t('modifiers.basePrice')}: {format(product.sale_price ?? '0')}
          </p>
        </div>

        {/* All groups visible */}
        <div className="flex-1 overflow-y-auto">
          {groups.map((group) => {
            const selected = selections[group.id] ?? new Set();
            const satisfied = isGroupSatisfied(group, selected);

            return (
              <div key={group.id} className="mb-4">
                {/* Group header */}
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

                {/* Chip grid */}
                <div className="flex flex-wrap gap-2">
                  {group.modifiers
                    .filter((m) => m.is_active)
                    .sort((a, b) => a.position - b.position)
                    .map((modifier) => {
                      const isSelected = selected.has(modifier.id);
                      const priceAdj = parseFloat(modifier.price_adjustment);

                      return (
                        <button
                          key={modifier.id}
                          onClick={() => handleToggleModifier(group, modifier)}
                          className={cn(
                            'flex items-center gap-2 rounded-pill border-2 px-4 py-2 text-sm font-medium transition-all',
                            isSelected
                              ? 'border-accent bg-accent-tint text-accent-strong'
                              : 'border-border-subtle bg-surface-raised text-ink-muted hover:border-border-strong',
                          )}
                        >
                          {isSelected && (
                            <Check className="h-3.5 w-3.5 text-accent-strong" />
                          )}
                          {modifier.name}
                          {priceAdj !== 0 && (
                            <span className={cn(
                              'text-xs',
                              priceAdj > 0 ? 'text-ink-muted' : 'text-success-strong',
                            )}>
                              {priceAdj > 0 ? '+' : ''}{format(modifier.price_adjustment)}
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
        <div className="mt-2 border-t border-border-subtle pt-2">
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
    </Modal>
  );
}
