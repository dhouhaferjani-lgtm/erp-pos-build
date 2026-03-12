import { useState, useEffect, useMemo, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { Modal } from '@/components/pos/Modal';
import { cn } from '@/lib/utils';
import { Check, Circle } from 'lucide-react';
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
  const [activeGroupIndex, setActiveGroupIndex] = useState(0);

  // Reset selections when product changes or modal opens
  useEffect(() => {
    if (isOpen && groups.length > 0) {
      setSelections(getDefaultSelections(groups));
      setActiveGroupIndex(0);
    }
  }, [isOpen, product?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  const activeGroup = groups[activeGroupIndex];

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
    <Modal isOpen={isOpen} onClose={onClose} title={t('modifiers.customize')} size="lg">
      <div className="flex min-h-[500px] flex-col">
        {/* Product header */}
        <div className="mb-4 rounded-xl bg-gray-50 px-4 py-3">
          <h3 className="text-lg font-bold text-gray-900">{product.name}</h3>
          <p className="text-sm text-gray-500">
            {t('modifiers.basePrice')}: {format(product.sale_price ?? '0')}
          </p>
        </div>

        {/* Group tabs */}
        {groups.length > 1 && (
          <div className="mb-4 flex gap-2 overflow-x-auto">
            {groups.map((group, index) => {
              const selected = selections[group.id] ?? new Set();
              const satisfied = isGroupSatisfied(group, selected);
              return (
                <button
                  key={group.id}
                  onClick={() => setActiveGroupIndex(index)}
                  className={cn(
                    'relative flex items-center gap-2 whitespace-nowrap rounded-full px-4 py-2 text-sm font-medium transition-colors',
                    index === activeGroupIndex
                      ? 'bg-primary-600 text-white'
                      : 'bg-gray-100 text-gray-700 hover:bg-gray-200',
                  )}
                >
                  {group.name}
                  {selected.size > 0 && (
                    <span className={cn(
                      'flex h-5 min-w-[20px] items-center justify-center rounded-full px-1 text-xs font-bold',
                      index === activeGroupIndex
                        ? 'bg-white/20 text-white'
                        : 'bg-primary-100 text-primary-700',
                    )}>
                      {selected.size}
                    </span>
                  )}
                  {/* Required indicator */}
                  {group.is_required && !satisfied && (
                    <span className="absolute -top-1 -right-1 h-2.5 w-2.5 rounded-full bg-red-500" />
                  )}
                </button>
              );
            })}
          </div>
        )}

        {/* Modifier list */}
        <div className="flex-1 overflow-y-auto">
          {activeGroup && (
            <div>
              {/* Group info */}
              <div className="mb-3 flex items-center justify-between">
                <span className="text-sm font-medium text-gray-700">
                  {activeGroup.name}
                  {activeGroup.is_required && (
                    <span className="ml-1 text-red-500">*</span>
                  )}
                </span>
                {activeGroup.selection_type === 'multiple' && (
                  <span className="text-xs text-gray-500">
                    {t('modifiers.selectUpTo', { max: activeGroup.max_selections })}
                  </span>
                )}
              </div>

              <div className="space-y-2">
                {activeGroup.modifiers
                  .filter((m) => m.is_active)
                  .sort((a, b) => a.position - b.position)
                  .map((modifier) => {
                    const isSelected = (selections[activeGroup.id] ?? new Set()).has(modifier.id);
                    const priceAdj = parseFloat(modifier.price_adjustment);

                    return (
                      <button
                        key={modifier.id}
                        onClick={() => handleToggleModifier(activeGroup, modifier)}
                        className={cn(
                          'flex min-h-[56px] w-full items-center justify-between rounded-xl border-2 px-4 py-3 text-left transition-all',
                          isSelected
                            ? 'border-primary-500 bg-primary-50'
                            : 'border-gray-200 bg-white hover:border-gray-300',
                        )}
                      >
                        <div className="flex items-center gap-3">
                          {/* Selection indicator */}
                          {activeGroup.selection_type === 'single' ? (
                            <div className={cn(
                              'flex h-5 w-5 items-center justify-center rounded-full border-2',
                              isSelected
                                ? 'border-primary-500 bg-primary-500'
                                : 'border-gray-300',
                            )}>
                              {isSelected && <Circle className="h-2 w-2 fill-white text-white" />}
                            </div>
                          ) : (
                            <div className={cn(
                              'flex h-5 w-5 items-center justify-center rounded border-2',
                              isSelected
                                ? 'border-primary-500 bg-primary-500'
                                : 'border-gray-300',
                            )}>
                              {isSelected && <Check className="h-3 w-3 text-white" />}
                            </div>
                          )}

                          <span className={cn(
                            'text-base font-medium',
                            isSelected ? 'text-primary-900' : 'text-gray-900',
                          )}>
                            {modifier.name}
                          </span>
                        </div>

                        {/* Price adjustment */}
                        {priceAdj !== 0 && (
                          <span className={cn(
                            'text-sm font-semibold',
                            priceAdj > 0 ? 'text-gray-600' : 'text-green-600',
                          )}>
                            {priceAdj > 0 ? '+' : ''}{format(modifier.price_adjustment)}
                          </span>
                        )}
                      </button>
                    );
                  })}
              </div>
            </div>
          )}
        </div>

        {/* Footer: total + add to cart */}
        <div className="mt-4 border-t border-gray-200 pt-4">
          <div className="mb-3 flex items-center justify-between">
            <span className="text-sm text-gray-500">
              {t('modifiers.selected')}: {Object.values(selections).reduce((sum, s) => sum + s.size, 0)}
            </span>
            <span className="text-xl font-bold text-gray-900">{format(totalPrice)}</span>
          </div>
          <button
            onClick={handleConfirm}
            disabled={!allValid}
            className="flex min-h-[56px] w-full items-center justify-center gap-2 rounded-xl bg-primary-600 px-6 py-4 text-lg font-semibold text-white transition-colors hover:bg-primary-700 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {t('modifiers.addToCart')}
          </button>
          {!allValid && (
            <p className="mt-2 text-center text-sm text-red-500">
              {t('modifiers.required')}
            </p>
          )}
        </div>
      </div>
    </Modal>
  );
}
