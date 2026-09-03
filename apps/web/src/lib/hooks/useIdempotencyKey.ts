import { useCallback, useState } from 'react'

/**
 * Client-generated idempotency key for a single logical submit attempt.
 *
 * The key deliberately SURVIVES a failed request so a retry of the same
 * intent is deduplicated server-side. Only a consumer that has awaited a
 * successful response calls `reset()` to start a new logical attempt.
 */
export function useIdempotencyKey(): { key: string; reset: () => void } {
  const [key, setKey] = useState(() => crypto.randomUUID())
  const reset = useCallback(() => {
    setKey(crypto.randomUUID())
  }, [])

  return { key, reset }
}
