import { useEffect, useId, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { X } from 'lucide-react'
import { Input } from '@/components/atoms/Input'
import { useDebouncedValue } from '@/lib/hooks'
import { borderColors, colors, textColors } from '@/lib/designTokens'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { useBanks, type Bank } from '@/hooks/useBanks'

interface BankPickerProps {
  id?: string
  country: string
  value: Bank | null
  onChange: (bank: Bank | null) => void
  isFallback: boolean
  fallbackValue: string
  onFallbackChange: (isFallback: boolean) => void
  onFallbackValueChange: (value: string) => void
  allowFallback?: boolean
  disabled?: boolean
  'aria-label'?: string
}

export function BankPicker({
  id,
  country,
  value,
  onChange,
  isFallback,
  fallbackValue,
  onFallbackChange,
  onFallbackValueChange,
  allowFallback = true,
  disabled = false,
  'aria-label': ariaLabel,
}: BankPickerProps) {
  const { t } = useTranslation('pickers')
  const generatedId = useId()
  const listboxId = useId()
  const inputId = id ?? generatedId
  const containerRef = useRef<HTMLDivElement>(null)
  const [query, setQuery] = useState('')
  const [isOpen, setIsOpen] = useState(false)
  const [activeIndex, setActiveIndex] = useState(-1)
  const debouncedQuery = useDebouncedValue(query, 250)
  const { data, isLoading, isError } = useBanks({
    country,
    query: debouncedQuery,
    enabled: isOpen && !disabled && !isFallback,
  })
  const results = useMemo(() => data ?? [], [data])
  const boundedActiveIndex = activeIndex >= results.length ? -1 : activeIndex
  const activeOption = boundedActiveIndex >= 0 ? results.at(boundedActiveIndex) : undefined

  useEffect(() => {
    function closeOnOutsideClick(event: MouseEvent): void {
      if (
        containerRef.current !== null
        && event.target instanceof Node
        && !containerRef.current.contains(event.target)
      ) {
        setIsOpen(false)
      }
    }
    document.addEventListener('mousedown', closeOnOutsideClick)
    return () => {
      document.removeEventListener('mousedown', closeOnOutsideClick)
    }
  }, [])

  function selectBank(bank: Bank): void {
    onChange(bank)
    setQuery('')
    setIsOpen(false)
  }

  function handleKeyDown(event: React.KeyboardEvent<HTMLInputElement>): void {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setIsOpen(true)
      setActiveIndex((index) => Math.min(index + 1, Math.max(results.length - 1, 0)))
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      setActiveIndex((index) => Math.max(index - 1, 0))
    } else if (event.key === 'Enter') {
      const bank = boundedActiveIndex >= 0 ? results.at(boundedActiveIndex) : undefined
      if (isOpen && bank !== undefined) {
        event.preventDefault()
        selectBank(bank)
      }
    } else if (event.key === 'Escape') {
      setIsOpen(false)
    }
  }

  if (isFallback) {
    return (
      <div ref={containerRef} className="space-y-2">
        <Input
          id={inputId}
          value={fallbackValue}
          disabled={disabled}
          aria-label={ariaLabel}
          placeholder={t('bank.fallbackPlaceholder')}
          onChange={(event) => {
            onFallbackValueChange(event.target.value)
          }}
        />
        <button
          type="button"
          className={`text-sm ${textColors.brand} ${textColors.hoverPrimary}`}
          disabled={disabled}
          onClick={() => {
            onFallbackChange(false)
            onFallbackValueChange('')
          }}
        >
          {t('bank.chooseDirectory')}
        </button>
      </div>
    )
  }

  if (value !== null) {
    return (
      <div
        ref={containerRef}
        className={`flex items-center gap-3 rounded-md border ${borderColors.default} ${colorTokens.surface.base} px-3 py-2`}
      >
        <div className="min-w-0 flex-1">
          <div className={`truncate text-sm font-medium ${textColors.primary}`}>{value.name}</div>
          <div className={`flex gap-2 truncate text-xs ${textColors.tertiary}`}>
            {value.short_name !== null ? <span>{value.short_name}</span> : null}
            {value.bic !== null ? <span>{value.bic}</span> : null}
          </div>
        </div>
        <button
          type="button"
          className={`${textColors.tertiary} ${textColors.hoverPrimary}`}
          aria-label={t('common.clear')}
          disabled={disabled}
          onClick={() => {
            onChange(null)
          }}
        >
          <X className="h-4 w-4" aria-hidden />
        </button>
      </div>
    )
  }

  return (
    <div ref={containerRef} className="relative space-y-2">
      <Input
        id={inputId}
        type="search"
        role="combobox"
        aria-label={ariaLabel}
        aria-expanded={isOpen}
        aria-controls={listboxId}
        aria-activedescendant={activeOption === undefined ? undefined : `${inputId}-option-${activeOption.id}`}
        aria-autocomplete="list"
        value={query}
        disabled={disabled}
        placeholder={t('bank.searchPlaceholder')}
        onFocus={() => {
          setIsOpen(true)
        }}
        onChange={(event) => {
          setQuery(event.target.value)
          setIsOpen(true)
        }}
        onKeyDown={handleKeyDown}
      />
      {isOpen ? (
        <div
          id={listboxId}
          role="listbox"
          className={`absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-md border ${borderColors.light} ${colorTokens.surface.base} py-1 shadow-lg`}
        >
          {isLoading ? (
            <div className={`px-3 py-2 text-sm ${textColors.tertiary}`}>{t('common.loading')}</div>
          ) : isError ? (
            <div className={`px-3 py-2 text-sm ${textColors.error}`}>{t('common.error')}</div>
          ) : results.length === 0 ? (
            <div className={`px-3 py-2 text-sm ${textColors.tertiary}`}>{t('bank.empty')}</div>
          ) : (
            results.map((bank, index) => {
              const isActive = index === boundedActiveIndex
              return (
                <button
                  key={bank.id}
                  id={`${inputId}-option-${bank.id}`}
                  type="button"
                  role="option"
                  aria-selected={isActive}
                  aria-label={`${bank.name}${bank.bic !== null ? ` ${bank.bic}` : ''}`}
                  className={`flex w-full items-start gap-2 px-3 py-2 text-left ${
                    isActive ? colors.primary[50] : `${colors.white} ${colors.hover.gray50}`
                  }`}
                  onMouseEnter={() => {
                    setActiveIndex(index)
                  }}
                  onClick={() => {
                    selectBank(bank)
                  }}
                >
                  <span className="min-w-0 flex-1">
                    <span className={`block truncate text-sm font-medium ${textColors.primary}`}>{bank.name}</span>
                    {bank.bic !== null ? (
                      <span className={`block truncate text-xs ${textColors.tertiary}`}>{bank.bic}</span>
                    ) : null}
                  </span>
                </button>
              )
            })
          )}
          {allowFallback ? (
            <div className={`border-t ${borderColors.light} px-3 py-2`}>
              <button
                type="button"
                className={`text-sm ${textColors.brand} ${textColors.hoverPrimary}`}
                onClick={() => {
                  setIsOpen(false)
                  onFallbackChange(true)
                }}
              >
                {t('bank.notListed')}
              </button>
            </div>
          ) : null}
        </div>
      ) : null}
    </div>
  )
}
