import { useEffect, useRef, useState } from 'react'
import { getEnrichmentResults } from '@/features/enrichment/api/enrichmentApi'
import type { EnrichmentResult } from '@/features/enrichment/types/enrichment'
import { refreshEnrichment } from '../api/platformApi'

export type FastPathState =
  | { phase: 'idle' | 'polling' | 'timeout' }
  | { phase: 'ready'; result: EnrichmentResult }

const POLL_DELAYS_MS = [3_000, 5_000, 8_000, 13_000, 21_000] as const

export function useEnrichmentFastPath(opts: {
  productId: string
  enabled: boolean
}): FastPathState {
  const { productId, enabled } = opts
  const [state, setState] = useState<FastPathState>({ phase: 'idle' })
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    if (timerRef.current !== null) {
      clearTimeout(timerRef.current)
      timerRef.current = null
    }

    if (!enabled) {
      setState({ phase: 'idle' })
      return undefined
    }

    let cancelled = false
    setState({ phase: 'polling' })

    const schedule = (attempt: number): void => {
      timerRef.current = setTimeout(() => {
        void (async () => {
          try {
            const refreshed = await refreshEnrichment(productId)

            if (cancelled) return

            if (refreshed.enrichment_status === 'completed') {
              const results = await getEnrichmentResults({
                product_id: productId,
                status: 'pending_review',
              })

              if (cancelled) return

              const result = results.data[0]
              setState(result !== undefined ? { phase: 'ready', result } : { phase: 'timeout' })
              return
            }

            if (
              (refreshed.enrichment_status === 'pending' || refreshed.enrichment_status === 'enriching')
              && attempt < POLL_DELAYS_MS.length - 1
            ) {
              schedule(attempt + 1)
              return
            }

            setState({ phase: 'timeout' })
          } catch {
            if (!cancelled) {
              setState({ phase: 'timeout' })
            }
          }
        })()
      }, POLL_DELAYS_MS[attempt])
    }

    schedule(0)

    return () => {
      cancelled = true
      if (timerRef.current !== null) {
        clearTimeout(timerRef.current)
        timerRef.current = null
      }
    }
  }, [enabled, productId])

  return state
}
