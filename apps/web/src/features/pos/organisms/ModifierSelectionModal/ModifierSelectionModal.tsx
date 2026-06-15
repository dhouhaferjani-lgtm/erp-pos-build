import { useState, useMemo, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { Modal, ModalContent, ModalFooter, ModalHeader } from '@/components/organisms/Modal'
import { FilterTabs } from '@/components/molecules/FilterTabs/FilterTabs'
import { cn } from '@/lib/utils'
import { tokens, textColors, colors, borderColors } from '@/lib/designTokens'
import { useCurrency } from '@/hooks/useCurrency'
import { bcadd, bccomp } from '@/lib/decimal'
import { POSButton } from '../../atoms'
import type { Product } from '../../molecules/ProductCard/ProductCard'
import type { SelectedModifier } from '../../molecules/CartLineItem/CartLineItem'
import type { MenuModifierGroup } from '../../hooks/useActiveMenu'

export interface ModifierSelectionModalProps {
  isOpen: boolean
  onClose: () => void
  product: Product
  onConfirm: (selectedModifiers: SelectedModifier[]) => void
}

export function ModifierSelectionModal({
  isOpen,
  onClose,
  product,
  onConfirm,
}: ModifierSelectionModalProps) {
  const { t } = useTranslation(['pos'])
  const { currency, decimals } = useCurrency()
  const groups = product.modifierGroups ?? []

  // Active tab — defaults to first group
  const [activeTab, setActiveTab] = useState<string>(groups[0]?.id ?? '')

  // Track selections: { [groupId]: Set<modifierId> }
  const [selections, setSelections] = useState<Record<string, Set<string>>>(() => {
    const initial: Record<string, Set<string>> = {}
    for (const group of groups) {
      const defaults = (group.modifiers ?? [])
        .filter((m) => m.is_default && m.is_active)
        .map((m) => m.id)
      initial[group.id] = new Set(defaults)
    }
    return initial
  })

  const handleSelect = useCallback(
    (groupId: string, modifierId: string, selectionType: string) => {
      setSelections((prev) => {
        const current = new Set(prev[groupId] ?? [])

        if (selectionType === 'single') {
          // Radio behavior: replace selection
          if (current.has(modifierId)) {
            current.clear()
          } else {
            current.clear()
            current.add(modifierId)
          }
        } else {
          // Checkbox behavior: toggle
          if (current.has(modifierId)) {
            current.delete(modifierId)
          } else {
            // Check max_selections
            const group = groups.find((g) => g.id === groupId)
            if (group && current.size < group.max_selections) {
              current.add(modifierId)
            }
          }
        }

        return { ...prev, [groupId]: current }
      })
    },
    [groups],
  )

  // Check if a specific group is satisfied
  const isGroupSatisfied = useCallback(
    (group: MenuModifierGroup) => {
      if (!group.is_required) return true
      const selected = selections[group.id]?.size ?? 0
      return selected >= group.min_selections
    },
    [selections],
  )

  // Check if all required groups are satisfied
  const isValid = useMemo(() => {
    return groups.every(isGroupSatisfied)
  }, [groups, isGroupSatisfied])

  // Calculate total price
  const totalPrice = useMemo(() => {
    let total = product.sale_price ?? '0'

    for (const group of groups) {
      const selectedIds = selections[group.id]
      if (!selectedIds) continue

      for (const mod of group.modifiers ?? []) {
        if (selectedIds.has(mod.id)) {
          total = bcadd(total, mod.price_adjustment, decimals)
        }
      }
    }

    return total
  }, [product.sale_price, groups, selections, decimals])

  // Build tabs for FilterTabs
  const tabs = useMemo(() => {
    return groups.map((group) => ({
      value: group.id,
      label: group.name,
      count: selections[group.id]?.size ?? 0,
    }))
  }, [groups, selections])

  const activeGroup = groups.find((g) => g.id === activeTab)

  const handleConfirm = () => {
    const selectedModifiers: SelectedModifier[] = []

    for (const group of groups) {
      const selectedIds = selections[group.id]
      if (!selectedIds) continue

      for (const mod of group.modifiers ?? []) {
        if (selectedIds.has(mod.id)) {
          selectedModifiers.push({
            modifier_id: mod.id,
            modifier_group_id: group.id,
            name: mod.name,
            group_name: group.name,
            price_adjustment: mod.price_adjustment,
          })
        }
      }
    }

    onConfirm(selectedModifiers)
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="lg">
      <ModalHeader title={product.name} onClose={onClose} />
      <ModalContent>
        <div className="space-y-5">
          {/* Base price */}
          <div className={cn('flex items-center justify-between text-sm', textColors.tertiary)}>
            <span>{t('pos:modifiers.basePrice')}</span>
            <span className="font-medium">
              {product.sale_price} {currency}
            </span>
          </div>

          {/* Tab navigation */}
          {groups.length > 1 && (
            <div className="relative">
              <FilterTabs
                tabs={tabs}
                value={activeTab}
                onChange={setActiveTab}
              />
              {/* Validation dots on tabs */}
              <div className="absolute inset-0 pointer-events-none flex gap-1 rounded-lg p-1">
                {groups.map((group) => {
                  const isSatisfied = isGroupSatisfied(group)
                  if (!group.is_required || isSatisfied) return null
                  return (
                    <div
                      key={group.id}
                      className="relative flex-1"
                      style={{ visibility: group.id === activeTab ? 'visible' : 'visible' }}
                    >
                      <span className={cn('absolute -top-1 -end-1 w-2.5 h-2.5 rounded-full', colors.error[600])} />
                    </div>
                  )
                })}
              </div>
            </div>
          )}

          {/* Active group content (tabbed view) */}
          {groups.length > 1 && activeGroup && (
            <ModifierGroupSection
              group={activeGroup}
              selectedIds={selections[activeGroup.id] ?? new Set()}
              onSelect={handleSelect}
              currency={currency}
              decimals={decimals}
              isSatisfied={isGroupSatisfied(activeGroup)}
            />
          )}

          {/* Single group — no tabs needed */}
          {groups.length === 1 && groups[0] && (
            <ModifierGroupSection
              group={groups[0]}
              selectedIds={selections[groups[0].id] ?? new Set()}
              onSelect={handleSelect}
              currency={currency}
              decimals={decimals}
              isSatisfied={isGroupSatisfied(groups[0])}
            />
          )}
        </div>
      </ModalContent>
      <ModalFooter>
        <div className="flex w-full items-center justify-between">
          <div className={cn('text-lg font-bold tabular-nums', textColors.primary)}>
            {totalPrice} {currency}
          </div>
          <POSButton
            variant="primary"
            size="lg"
            onClick={handleConfirm}
            disabled={!isValid}
          >
            {t('pos:modifiers.addToCart')}
          </POSButton>
        </div>
      </ModalFooter>
    </Modal>
  )
}

interface ModifierGroupSectionProps {
  group: MenuModifierGroup
  selectedIds: Set<string>
  onSelect: (groupId: string, modifierId: string, selectionType: string) => void
  currency: string
  decimals: number
  isSatisfied: boolean
}

function ModifierGroupSection({
  group,
  selectedIds,
  onSelect,
  currency,
  decimals,
  isSatisfied,
}: ModifierGroupSectionProps) {
  const { t } = useTranslation(['pos'])
  const activeModifiers = (group.modifiers ?? []).filter((m) => m.is_active)

  return (
    <div>
      <div className="flex items-center gap-2 mb-3">
        <h3 className={cn('text-sm font-semibold', textColors.primary)}>{group.name}</h3>
        {group.is_required && (
          <span className={cn(tokens.badge.base, isSatisfied ? tokens.badge.green : tokens.badge.red)}>
            {t('pos:modifiers.required')}
          </span>
        )}
        {group.selection_type === 'multiple' && group.max_selections > 1 && (
          <span className={cn('text-xs', textColors.tertiary)}>
            ({t('pos:modifiers.selectUpTo', { max: group.max_selections })})
          </span>
        )}
      </div>

      <div className="space-y-2">
        {activeModifiers.map((mod) => {
          const isSelected = selectedIds.has(mod.id)
          const adjustmentCmp = bccomp(mod.price_adjustment, '0')

          return (
            <label
              key={mod.id}
              className={cn(
                'w-full flex items-center justify-between rounded-lg border-2 transition-all cursor-pointer',
                // Touch-friendly sizing
                'px-5 py-4 min-h-[56px]',
                isSelected
                  ? cn(borderColors.primary, colors.primary[50])
                  : cn(borderColors.light, colors.white, borderColors.hover),
              )}
            >
              <div className="flex items-center gap-3">
                <input
                  type={group.selection_type === 'single' ? 'radio' : 'checkbox'}
                  name={`modifier-group-${group.id}`}
                  checked={isSelected}
                  onChange={() => { onSelect(group.id, mod.id, group.selection_type); }}
                  className={group.selection_type === 'single' ? tokens.radio.base : tokens.checkbox.base}
                />
                <span className={cn('text-sm', isSelected ? cn('font-medium', textColors.primary) : textColors.secondary)}>
                  {mod.name}
                </span>
              </div>

              {adjustmentCmp !== 0 && (
                <span
                  className={cn(
                    'text-sm font-medium',
                    adjustmentCmp > 0 ? textColors.tertiary : textColors.success,
                  )}
                >
                  {adjustmentCmp > 0 ? '+' : ''}
                  {parseFloat(mod.price_adjustment).toFixed(decimals)} {currency}
                </span>
              )}
            </label>
          )
        })}
      </div>
    </div>
  )
}
