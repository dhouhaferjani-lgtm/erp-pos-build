import { useEffect, useState } from 'react'

/**
 * Debounce a changing value. Returns the latest `value` only after `delay`
 * milliseconds have elapsed without a new update. Used by picker molecules
 * to throttle search requests while the user is typing.
 */
export function useDebouncedValue<T>(value: T, delay = 250): T {
  const [debounced, setDebounced] = useState<T>(value)

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebounced(value)
    }, delay)

    return () => {
      clearTimeout(timer)
    }
  }, [value, delay])

  return debounced
}
