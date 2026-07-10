import { useState, useMemo, useCallback, useRef, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import {
  Combobox,
  ComboboxInput,
  ComboboxOptions,
  ComboboxOption,
} from '@headlessui/react'
import { Search, X, FolderTree } from 'lucide-react'
import { useCategories } from '../hooks/useCategories'
import type { CategoryApiResponse } from '../api'
import { cn } from '@/lib/utils'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface CategorySelectorProps {
  value: number[]
  onChange: (ids: number[]) => void
  label?: string
  helperText?: string
  disabled?: boolean
}

export function CategorySelector({
  value,
  onChange,
  label,
  helperText,
  disabled = false,
}: CategorySelectorProps) {
  const { t } = useTranslation(['categories', 'common'])
  const [query, setQuery] = useState('')

  // Cache selected category objects so they persist during search
  const selectedCacheRef = useRef<Map<number, CategoryApiResponse>>(new Map())

  const categoryParams = {
    ...(query.length > 0 ? { search: query } : {}),
    isActive: true,
    perPage: 100,
  }
  const { data: categoriesResponse, isLoading } = useCategories(categoryParams)

  const categories = categoriesResponse?.data ?? []

  // Update cache when new categories arrive
  useEffect(() => {
    for (const cat of categories) {
      if (value.includes(cat.id)) {
        selectedCacheRef.current.set(cat.id, cat)
      }
    }
  }, [categories, value])

  // Clean cache when items are deselected
  useEffect(() => {
    const currentIds = new Set(value)
    for (const id of selectedCacheRef.current.keys()) {
      if (!currentIds.has(id)) {
        selectedCacheRef.current.delete(id)
      }
    }
  }, [value])

  const availableCategories = useMemo(
    () => categories.filter((cat) => !value.includes(cat.id)),
    [categories, value]
  )

  const selectedCategories = useMemo(() => {
    const result: CategoryApiResponse[] = []
    for (const id of value) {
      const fromApi = categories.find((cat) => cat.id === id)
      const fromCache = selectedCacheRef.current.get(id)
      const item = fromApi ?? fromCache
      if (item) {
        result.push(item)
        selectedCacheRef.current.set(id, item)
      }
    }
    return result
  }, [categories, value])

  const handleToggleCategory = useCallback((categoryId: number) => {
    if (value.includes(categoryId)) {
      onChange(value.filter((id) => id !== categoryId))
    } else {
      onChange([...value, categoryId])
    }
  }, [value, onChange])

  const handleRemoveCategory = useCallback((categoryId: number) => {
    onChange(value.filter((id) => id !== categoryId))
  }, [value, onChange])

  const handleClearAll = useCallback(() => {
    onChange([])
  }, [onChange])

  const getBreadcrumbText = (cat: { breadcrumb: Array<{ name: string }> | null }) => {
    if (cat.breadcrumb && cat.breadcrumb.length > 0) {
      return cat.breadcrumb.map((b) => b.name).join(' > ')
    }
    return null
  }

  return (
    <div className="w-full space-y-4">
      {label && (
        <div className="flex items-center justify-between">
          <h3 className={`text-sm font-medium ${colorTokens.text.secondary}`}>{label}</h3>
          {value.length > 0 && (
            <button
              type="button"
              onClick={handleClearAll}
              className={`text-sm ${colorTokens.intent.danger.text} hover:${colorTokens.intent.danger.textStrong}`}
            >
              {t('common:clear')} ({value.length})
            </button>
          )}
        </div>
      )}

      <Combobox value={null} onChange={(categoryId: number | null) => {
        if (categoryId) {
          handleToggleCategory(categoryId)
          setQuery('')
        }
      }} disabled={disabled}>
        <div className="relative">
          <div className="relative">
            <Search className={`absolute start-3 top-1/2 -translate-y-1/2 h-5 w-5 ${colorTokens.text.disabled}`} />
            <ComboboxInput
              className={cn(
                `w-full ps-10 pe-10 py-3 border ${colorTokens.border.default} rounded-md`,
                `focus:outline-none focus:ring-2 focus:${colorTokens.intent.primary.ring} focus:${colorTokens.intent.primary.borderFocus}`,
                `disabled:${colorTokens.surface.muted} disabled:cursor-not-allowed`,
                'transition-colors'
              )}
              onChange={(e: React.ChangeEvent<HTMLInputElement>) => { setQuery(e.target.value); }}
              placeholder={t('categories:searchPlaceholder')}
              value={query}
            />
            {isLoading && (
              <div className="absolute end-3 top-1/2 -translate-y-1/2">
                <div className={`animate-spin h-4 w-4 border-2 ${colorTokens.border.default} border-t-blue-600 rounded-full`} />
              </div>
            )}
          </div>

          <ComboboxOptions
            className={cn(
              'absolute z-10 mt-1 w-full',
              'max-h-60 overflow-auto',
              'rounded-md bg-white shadow-lg',
              `border ${colorTokens.border.subtle}`,
              'py-1',
              'focus:outline-none'
            )}
          >
            {availableCategories.length === 0 ? (
              <div className={`px-4 py-3 text-sm ${colorTokens.text.subtle}`}>
                {query
                  ? t('categories:noCategoriesFound')
                  : t('categories:noCategories')}
              </div>
            ) : (
              availableCategories.map((category) => (
                <ComboboxOption
                  key={category.id}
                  value={category.id}
                  className={({ active }: { active: boolean }) =>
                    cn(
                      'cursor-pointer select-none px-4 py-2',
                      active ? `${colorTokens.intent.primary.bgSubtle} ${colorTokens.intent.primary.textStrongest}` : `${colorTokens.text.primary}`
                    )
                  }
                >
                  <div className="flex items-center gap-3">
                    <div className={`flex-shrink-0 h-10 w-10 rounded ${colorTokens.surface.muted} flex items-center justify-center`}>
                      <FolderTree className={`h-5 w-5 ${colorTokens.text.subtle}`} />
                    </div>
                    <div className="flex-1 min-w-0">
                      <div className="font-medium truncate">{category.name}</div>
                      {getBreadcrumbText(category) && (
                        <div className={`text-sm ${colorTokens.text.subtle} truncate`}>
                          {getBreadcrumbText(category)}
                        </div>
                      )}
                    </div>
                  </div>
                </ComboboxOption>
              ))
            )}
          </ComboboxOptions>
        </div>
      </Combobox>

      {value.length > 0 && (
        <div className={`border ${colorTokens.border.subtle} rounded-lg p-4 ${colorTokens.surface.page}`}>
          <div className="flex items-center justify-between mb-3">
            <span className={`text-sm font-medium ${colorTokens.text.secondary}`}>
              {t('categories:selectedCategories', { count: value.length })}
            </span>
          </div>

          <div className="space-y-2 max-h-60 overflow-y-auto">
            {selectedCategories.map((category) => (
              <div
                key={category.id}
                className={`flex items-center gap-3 p-2 bg-white rounded border ${colorTokens.border.subtle} hover:${colorTokens.border.default} transition-colors`}
              >
                <div className={`flex-shrink-0 h-8 w-8 rounded ${colorTokens.surface.muted} flex items-center justify-center`}>
                  <FolderTree className={`h-4 w-4 ${colorTokens.text.subtle}`} />
                </div>
                <div className="flex-1 min-w-0">
                  <div className="text-sm font-medium truncate">{category.name}</div>
                  {getBreadcrumbText(category) && (
                    <div className={`text-xs ${colorTokens.text.subtle} truncate`}>
                      {getBreadcrumbText(category)}
                    </div>
                  )}
                </div>
                <button
                  type="button"
                  onClick={() => { handleRemoveCategory(category.id); }}
                  className={`flex-shrink-0 p-1 ${colorTokens.text.disabled} hover:${colorTokens.intent.danger.text} transition-colors`}
                  title={t('common:actions.delete')}
                >
                  <X className="h-4 w-4" />
                </button>
              </div>
            ))}
          </div>
        </div>
      )}

      {helperText && (
        <p className={`text-sm ${colorTokens.text.subtle}`}>
          {helperText}
        </p>
      )}
    </div>
  )
}
