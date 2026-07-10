import { useEffect, useId, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { X } from 'lucide-react'
import { api } from '@/lib/api'
import { useDebouncedValue } from '@/lib/hooks'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { borderColors, colors, textColors, tokens } from '@/lib/designTokens'

// ─── Types ────────────────────────────────────────────────────────────────────

interface UserListItem {
  id: string
  name: string
  email: string | null
}

interface UserListResponse {
  data: UserListItem[]
}

interface UserPickerProps {
  /** The currently selected user UUID, or null if nothing is selected. */
  value: string | null
  /**
   * Called with `(uuid, displayName)` when a user is chosen, or `(null)` when
   * the selection is cleared. The second argument lets the parent cache the
   * display name without needing a second lookup.
   */
  onChange: (id: string | null, name?: string) => void
  /**
   * When `value` is non-null, this label is displayed inside the selected
   * chip. If you store only the UUID in form state, pass the name separately
   * so the picker can render it without an extra query.
   */
  selectedLabel?: string | null
  /** Filter results to a specific role slug, e.g. `'admin'`. */
  roleFilter?: string
  placeholder?: string
  disabled?: boolean
  label?: string
  'aria-label'?: string
  /** Optional data-testid override for automation. */
  testId?: string
}

// ─── Component ────────────────────────────────────────────────────────────────

export function UserPicker({
  value,
  onChange,
  selectedLabel,
  roleFilter,
  placeholder,
  disabled = false,
  label,
  'aria-label': ariaLabel,
  testId,
}: UserPickerProps) {
  const { t } = useTranslation('pickers')
  const containerRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)
  const listboxId = useId()

  const [query, setQuery] = useState('')
  const [isOpen, setIsOpen] = useState(false)
  const [activeIndex, setActiveIndex] = useState(-1)
  const debouncedQuery = useDebouncedValue(query, 250)
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const searchEnabled = isOpen && debouncedQuery.trim().length >= 2
  const queryKey = tenantScopedKey(['pickers', 'user', roleFilter ?? '', debouncedQuery] as const)

  const { data, isLoading, isError } = useQuery({
    queryKey,
    enabled: searchEnabled && !disabled && tenantId !== null && companyId !== null,
    queryFn: async () => {
      const params = new URLSearchParams({ per_page: '20' })
      params.set('search', debouncedQuery.trim())
      if (roleFilter) {
        params.set('role', roleFilter)
      }
      const response = await api.get<UserListResponse>(`/users?${params.toString()}`)
      return response.data.data
    },
  })

  // Reset highlighted row when the result set changes — -1 = no highlight.
  useEffect(() => {
    setActiveIndex(-1)
  }, [data])

  // Close the listbox on outside clicks.
  useEffect(() => {
    function onClick(e: MouseEvent) {
      if (containerRef.current !== null && !containerRef.current.contains(e.target as Node)) {
        setIsOpen(false)
      }
    }
    document.addEventListener('mousedown', onClick)
    return () => {
      document.removeEventListener('mousedown', onClick)
    }
  }, [])

  const results = useMemo<UserListItem[]>(() => data ?? [], [data])

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>): void => {
    if (!isOpen) {
      if (e.key === 'ArrowDown' || e.key === 'Enter') {
        setIsOpen(true)
      }
      return
    }
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      setActiveIndex((i) => Math.min(i + 1, Math.max(results.length - 1, 0)))
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setActiveIndex((i) => Math.max(i - 1, 0))
    } else if (e.key === 'Enter') {
      e.preventDefault()
      const choice = results[activeIndex]
      if (choice !== undefined) {
        onChange(choice.id, choice.name)
        setQuery('')
        setIsOpen(false)
      }
    } else if (e.key === 'Escape') {
      e.preventDefault()
      setIsOpen(false)
    }
  }

  const effectivePlaceholder = placeholder ?? t('user.searchPlaceholder')
  const effectiveLabel = label ?? t('user.label')
  const testIdAttr = testId ?? 'user-picker'

  // ─── Selected state ──────────────────────────────────────────────────────────

  if (value !== null) {
    const displayName = selectedLabel ?? value
    return (
      <div
        ref={containerRef}
        className={`flex items-center gap-2 rounded-md border ${borderColors.default} bg-white px-3 py-2`}
        data-testid={testIdAttr}
      >
        <div className="min-w-0 flex-1">
          <div className={`truncate text-sm font-medium ${textColors.primary}`}>{displayName}</div>
        </div>
        <button
          type="button"
          className={`${textColors.tertiary} ${textColors.hoverPrimary}`}
          aria-label={t('common.clear')}
          disabled={disabled}
          onClick={() => {
            onChange(null)
            setQuery('')
          }}
        >
          <X className="h-4 w-4" aria-hidden />
        </button>
      </div>
    )
  }

  // ─── Search state ────────────────────────────────────────────────────────────

  return (
    <div ref={containerRef} className="relative" data-testid={testIdAttr}>
      {effectiveLabel !== '' ? (
        <label className={tokens.label.base}>
          {effectiveLabel}
        </label>
      ) : null}
      <input
        ref={inputRef}
        type="text"
        role="combobox"
        aria-expanded={isOpen}
        aria-controls={listboxId}
        aria-autocomplete="list"
        aria-label={ariaLabel}
        className={tokens.input.base}
        placeholder={effectivePlaceholder}
        value={query}
        disabled={disabled}
        onChange={(e) => {
          setQuery(e.target.value)
          setIsOpen(true)
        }}
        onFocus={() => {
          setIsOpen(true)
        }}
        onKeyDown={handleKeyDown}
      />
      {isOpen ? (
        <div
          id={listboxId}
          role="listbox"
          className={`absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-md border ${borderColors.light} bg-white py-1 shadow-lg`}
        >
          {!searchEnabled ? (
            <div className={`px-3 py-2 text-xs ${textColors.tertiary}`}>
              {t('common.minCharacters')}
            </div>
          ) : isLoading ? (
            <div className={`px-3 py-2 text-sm ${textColors.tertiary}`}>
              {t('common.loading')}
            </div>
          ) : isError ? (
            <div className={`px-3 py-2 text-sm ${textColors.error}`}>{t('common.error')}</div>
          ) : results.length === 0 ? (
            <div className={`px-3 py-2 text-sm ${textColors.tertiary}`}>
              {t('user.empty')}
            </div>
          ) : (
            results.map((user, idx) => {
              const active = idx === activeIndex
              return (
                <button
                  key={user.id}
                  type="button"
                  role="option"
                  aria-selected={active}
                  className={`flex w-full items-start gap-2 px-3 py-2 text-left ${
                    active ? colors.primary[50] : `${colors.white} ${colors.hover.gray50}`
                  }`}
                  onMouseEnter={() => {
                    setActiveIndex(idx)
                  }}
                  onClick={() => {
                    onChange(user.id, user.name)
                    setQuery('')
                    setIsOpen(false)
                  }}
                >
                  <div className="min-w-0 flex-1">
                    <div className={`truncate text-sm font-medium ${textColors.primary}`}>
                      {user.name}
                    </div>
                    {user.email !== null ? (
                      <div className={`truncate text-xs ${textColors.tertiary}`}>
                        {user.email}
                      </div>
                    ) : null}
                  </div>
                </button>
              )
            })
          )}
        </div>
      ) : null}
    </div>
  )
}
