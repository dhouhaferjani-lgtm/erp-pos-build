import { useCallback, useState } from 'react'

/**
 * Client-generated idempotency key for a single logical submit attempt.
 *
 * The key deliberately SURVIVES a failed request so a retry of the same
 * intent is deduplicated server-side. `reset()` is called only at an
 * intent BOUNDARY — after a consumer has awaited a successful response, or
 * when a long-lived surface starts a new intent (a modal that stays mounted
 * across close/reopen rotates on open). Never call it from an error path:
 * that would let a retry of a request that already committed create a
 * duplicate.
 */
export function useIdempotencyKey(): { key: string; reset: () => void } {
  const [key, setKey] = useState(() => crypto.randomUUID())
  const reset = useCallback(() => {
    setKey(crypto.randomUUID())
  }, [])

  return { key, reset }
}
