import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import {
  Combobox,
  ComboboxInput,
  ComboboxOptions,
  ComboboxOption,
  Label,
} from '@headlessui/react'
import { Check, ChevronDown, User as UserIcon } from 'lucide-react'
import { useUsers } from '../hooks/useUsers'
import { cn } from '@/lib/utils'
import { tokens, textColors, borderColors, colors } from '@/lib/designTokens'
import { StatusBadge } from '../../../components/atoms'

interface UserSelectorProps {
  /**
   * Currently selected user ID
   */
  value: string | null

  /**
   * Callback when user selection changes
   */
  onChange: (userId: string | null) => void

  /**
   * Label for the selector
   */
  label: string

  /**
   * Whether the field is required
   */
  required?: boolean

  /**
   * User IDs to exclude from the list
   * Useful for preventing selection of already-assigned users
   */
  excludeUserIds?: string[]

  /**
   * Helper text to display below the selector
   */
  helperText?: string

  /**
   * Whether the selector is disabled
   */
  disabled?: boolean
}

/**
 * UserSelector Component
 *
 * Accessible, searchable dropdown for selecting users.
 * Uses Headless UI Combobox for accessibility and keyboard navigation.
 *
 * Features:
 * - Real-time search filtering
 * - Active user filtering
 * - Keyboard navigation
 * - Loading state
 * - Exclusion list support
 *
 * @example
 * ```tsx
 * <UserSelector
 *   value={selectedUserId}
 *   onChange={setSelectedUserId}
 *   label="Primary Counter"
 *   required
 *   excludeUserIds={[user2Id, user3Id]}
 * />
 * ```
 */
export function UserSelector({
  value,
  onChange,
  label,
  required = false,
  excludeUserIds = [],
  helperText,
  disabled = false,
}: UserSelectorProps) {
  const { t } = useTranslation(['common', 'users'])
  const [query, setQuery] = useState('')

  // Fetch active users
  const { data: usersResponse, isLoading } = useUsers({
    status: 'active',
    search: query.length > 0 ? query : undefined,
    per_page: 50,
  })

  const users = usersResponse?.data ?? []

  // Filter out excluded users
  const filteredUsers = useMemo(
    () => users.filter((user) => !excludeUserIds.includes(user.id)),
    [users, excludeUserIds]
  )

  // Find selected user
  const selectedUser = users.find((user) => user.id === value) ?? null

  return (
    <div className="w-full">
      <Combobox
        value={value}
        onChange={onChange}
        disabled={disabled}
      >
        {({ open }: { open: boolean }) => (
          <>
            <Label className={cn(tokens.label.base, 'mb-2')}>
              {label}
              {required && <span className={cn(tokens.label.required, 'ms-1')}>*</span>}
            </Label>

            <div className="relative">
              <ComboboxInput
                className={cn(tokens.input.base, 'pe-10')}
                displayValue={() => selectedUser?.name ?? ''}
                onChange={(e: React.ChangeEvent<HTMLInputElement>) => { setQuery(e.target.value); }}
                placeholder={t('users:selectUser')}
              />

              {/* Icon container */}
              <div className="absolute inset-y-0 end-0 flex items-center pe-2 pointer-events-none">
                {isLoading ? (
                  <div className={cn('animate-spin h-4 w-4 border-2 rounded-full', borderColors.default, 'border-t-blue-600')} />
                ) : (
                  <ChevronDown
                    className={cn(
                      'h-5 w-5 transition-transform',
                      textColors.disabled,
                      open && 'transform rotate-180'
                    )}
                  />
                )}
              </div>

              <ComboboxOptions
                className={cn(
                  'absolute z-10 mt-1 w-full',
                  'max-h-60 overflow-auto',
                  'rounded-md shadow-lg',
                  colors.white,
                  'border',
                  borderColors.light,
                  'py-1',
                  'text-base',
                  'focus:outline-none'
                )}
              >
                {filteredUsers.length === 0 ? (
                  <div className={cn('px-4 py-2 text-sm', textColors.tertiary)}>
                    {query
                      ? t('users:noUsersFound')
                      : t('users:noActiveUsers')}
                  </div>
                ) : (
                  filteredUsers.map((user) => (
                    <ComboboxOption
                      key={user.id}
                      value={user.id}
                      className={({ active }: { active: boolean }) =>
                        cn(
                          'cursor-pointer select-none px-4 py-2',
                          active
                            ? tokens.alert.info
                            : textColors.primary
                        )
                      }
                    >
                      {({ selected }: { selected: boolean }) => (
                        <div className="flex items-center gap-3">
                          {/* Avatar placeholder */}
                          <div className={cn('flex-shrink-0 h-8 w-8 rounded-full flex items-center justify-center', colors.neutral[200])}>
                            <UserIcon className={cn('h-4 w-4', textColors.tertiary)} />
                          </div>

                          {/* User info */}
                          <div className="flex-1 min-w-0">
                            <div className="flex items-center gap-2">
                              <span className={cn(
                                'font-medium truncate',
                                selected && 'font-semibold'
                              )}>
                                {user.name}
                              </span>
                              {user.roles.length > 0 && (
                                <StatusBadge tone="neutral">
                                  {user.roles[0]}
                                </StatusBadge>
                              )}
                            </div>
                            <div className={cn('text-sm truncate', textColors.tertiary)}>
                              {user.email}
                            </div>
                          </div>

                          {/* Selected indicator */}
                          {selected && (
                            <Check className={cn('h-5 w-5 flex-shrink-0', textColors.brand)} />
                          )}
                        </div>
                      )}
                    </ComboboxOption>
                  ))
                )}
              </ComboboxOptions>
            </div>

            {/* Helper text */}
            {helperText && (
              <p className={cn('mt-1 text-sm', textColors.tertiary)}>
                {helperText}
              </p>
            )}
          </>
        )}
      </Combobox>
    </div>
  )
}
