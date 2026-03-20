import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { Search } from 'lucide-react'
import { useCommandPalette } from './useCommandPalette'
import type { CommandItem } from './useCommandPalette'

interface CommandPaletteProps {
  isOpen: boolean
  onClose: () => void
}

function CommandPaletteItem({
  item,
  isSelected,
  onSelect,
  onMouseEnter,
}: {
  item: CommandItem
  isSelected: boolean
  onSelect: (item: CommandItem) => void
  onMouseEnter: () => void
}) {
  const { t } = useTranslation()
  const Icon = item.icon
  const ref = useRef<HTMLButtonElement>(null)

  useEffect(() => {
    if (isSelected && ref.current) {
      ref.current.scrollIntoView({ block: 'nearest' })
    }
  }, [isSelected])

  const sectionLabel = item.section === 'navigation'
    ? t('common:commandPalette.navigation')
    : t('common:commandPalette.actions')

  return (
    <button
      ref={ref}
      type="button"
      className={`flex w-full items-center gap-3 px-4 py-2.5 text-start text-sm transition-colors ${
        isSelected ? 'bg-blue-50 text-blue-900' : 'text-gray-700 hover:bg-gray-50'
      }`}
      onClick={() => { onSelect(item) }}
      onMouseEnter={onMouseEnter}
    >
      <Icon className={`h-4 w-4 shrink-0 ${isSelected ? 'text-blue-600' : 'text-gray-400'}`} />
      <span className="flex-1 truncate">{t(`common:${item.translationKey}`, { defaultValue: item.label })}</span>
      <span className={`shrink-0 rounded px-1.5 py-0.5 text-xs ${
        isSelected ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-500'
      }`}>
        {sectionLabel}
      </span>
    </button>
  )
}

export function CommandPalette({ isOpen, onClose }: CommandPaletteProps) {
  const { t } = useTranslation()
  const inputRef = useRef<HTMLInputElement>(null)
  const {
    query,
    setQuery,
    filteredItems,
    selectedIndex,
    setSelectedIndex,
    recentItems,
    handleKeyDown,
    executeItem,
  } = useCommandPalette(onClose)

  // Focus input when opened
  useEffect(() => {
    if (!isOpen) return
    // Small delay to ensure the element is rendered
    const timer = setTimeout(() => {
      inputRef.current?.focus()
    }, 0)
    return () => { clearTimeout(timer) }
  }, [isOpen])

  if (!isOpen) return null

  const showRecent = query.trim() === '' && recentItems.length > 0
  const showFiltered = query.trim() !== ''
  const hasNoResults = showFiltered && filteredItems.length === 0

  // Group filtered items by section
  const navigationItems = filteredItems.filter((item) => item.section === 'navigation')
  const actionItems = filteredItems.filter((item) => item.section === 'actions')

  // Build a flat list for indexing that matches the render order
  const displayItems: CommandItem[] = showFiltered
    ? [...navigationItems, ...actionItems]
    : recentItems

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center pt-[15vh]"
      onClick={onClose}
      role="presentation"
    >
      {/* Backdrop */}
      <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" />

      {/* Dialog */}
      <div
        className="relative w-full max-w-lg overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl"
        onClick={(e) => { e.stopPropagation() }}
        role="dialog"
        aria-modal="true"
        aria-label={t('common:commandPalette.placeholder')}
      >
        {/* Search Input */}
        <div className="flex items-center gap-3 border-b border-gray-200 px-4 py-3">
          <Search className="h-5 w-5 shrink-0 text-gray-400" />
          <input
            ref={inputRef}
            type="text"
            value={query}
            onChange={(e) => { setQuery(e.target.value) }}
            onKeyDown={handleKeyDown}
            placeholder={t('common:commandPalette.placeholder')}
            className="flex-1 bg-transparent text-sm text-gray-900 placeholder-gray-400 outline-none"
          />
        </div>

        {/* Results */}
        <div className="max-h-80 overflow-y-auto">
          {showRecent && (
            <div>
              <div className="px-4 py-2 text-xs font-semibold uppercase tracking-wider text-gray-500">
                {t('common:commandPalette.recent')}
              </div>
              {recentItems.map((item, index) => (
                <CommandPaletteItem
                  key={item.id}
                  item={item}
                  isSelected={selectedIndex === index}
                  onSelect={executeItem}
                  onMouseEnter={() => { setSelectedIndex(index) }}
                />
              ))}
            </div>
          )}

          {showFiltered && navigationItems.length > 0 && (
            <div>
              <div className="px-4 py-2 text-xs font-semibold uppercase tracking-wider text-gray-500">
                {t('common:commandPalette.navigation')}
              </div>
              {navigationItems.map((item) => {
                const flatIndex = displayItems.indexOf(item)
                return (
                  <CommandPaletteItem
                    key={item.id}
                    item={item}
                    isSelected={selectedIndex === flatIndex}
                    onSelect={executeItem}
                    onMouseEnter={() => { setSelectedIndex(flatIndex) }}
                  />
                )
              })}
            </div>
          )}

          {showFiltered && actionItems.length > 0 && (
            <div>
              <div className="px-4 py-2 text-xs font-semibold uppercase tracking-wider text-gray-500">
                {t('common:commandPalette.actions')}
              </div>
              {actionItems.map((item) => {
                const flatIndex = displayItems.indexOf(item)
                return (
                  <CommandPaletteItem
                    key={item.id}
                    item={item}
                    isSelected={selectedIndex === flatIndex}
                    onSelect={executeItem}
                    onMouseEnter={() => { setSelectedIndex(flatIndex) }}
                  />
                )
              })}
            </div>
          )}

          {hasNoResults && (
            <div className="px-4 py-8 text-center text-sm text-gray-500">
              {t('common:commandPalette.noResults')}
            </div>
          )}

          {!showRecent && !showFiltered && (
            <div className="px-4 py-8 text-center text-sm text-gray-500">
              {t('common:commandPalette.placeholder')}
            </div>
          )}
        </div>

        {/* Footer hint */}
        <div className="border-t border-gray-200 px-4 py-2 text-center text-xs text-gray-400">
          {t('common:commandPalette.hint')}
        </div>
      </div>
    </div>
  )
}
