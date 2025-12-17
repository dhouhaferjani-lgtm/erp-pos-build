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
            <Label className="block text-sm font-medium text-gray-700 mb-2">
              {label}
              {required && <span className="text-red-500 ms-1">*</span>}
            </Label>

            <div className="relative">
              <ComboboxInput
                className={cn(
                  'w-full px-3 py-2 pe-10 border border-gray-300 rounded-md',
                  'focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500',
                  'disabled:bg-gray-100 disabled:cursor-not-allowed',
                  'transition-colors'
                )}
                displayValue={() => selectedUser?.name ?? ''}
                onChange={(e: React.ChangeEvent<HTMLInputElement>) => { setQuery(e.target.value); }}
                placeholder={t('users:selectUser')}
              />

              {/* Icon container */}
              <div className="absolute inset-y-0 end-0 flex items-center pe-2 pointer-events-none">
                {isLoading ? (
                  <div className="animate-spin h-4 w-4 border-2 border-gray-300 border-t-blue-600 rounded-full" />
                ) : (
                  <ChevronDown
                    className={cn(
                      'h-5 w-5 text-gray-400 transition-transform',
                      open && 'transform rotate-180'
                    )}
                  />
                )}
              </div>

              <ComboboxOptions
                className={cn(
                  'absolute z-10 mt-1 w-full',
                  'max-h-60 overflow-auto',
                  'rounded-md bg-white shadow-lg',
                  'border border-gray-200',
                  'py-1',
                  'text-base',
                  'focus:outline-none'
                )}
              >
                {filteredUsers.length === 0 ? (
                  <div className="px-4 py-2 text-sm text-gray-500">
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
                          active ? 'bg-blue-50 text-blue-900' : 'text-gray-900'
                        )
                      }
                    >
                      {({ selected }: { selected: boolean }) => (
                        <div className="flex items-center gap-3">
                          {/* Avatar placeholder */}
                          <div className="flex-shrink-0 h-8 w-8 rounded-full bg-gray-200 flex items-center justify-center">
                            <UserIcon className="h-4 w-4 text-gray-500" />
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
                                <span className="text-xs text-gray-500 px-2 py-0.5 bg-gray-100 rounded">
                                  {user.roles[0]}
                                </span>
                              )}
                            </div>
                            <div className="text-sm text-gray-500 truncate">
                              {user.email}
                            </div>
                          </div>

                          {/* Selected indicator */}
                          {selected && (
                            <Check className="h-5 w-5 text-blue-600 flex-shrink-0" />
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
              <p className="mt-1 text-sm text-gray-500">
                {helperText}
              </p>
            )}
          </>
        )}
      </Combobox>
    </div>
  )
}
