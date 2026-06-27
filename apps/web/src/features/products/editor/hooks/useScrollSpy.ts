import { useState, useEffect } from 'react'

export interface ScrollSpyOptions {
  rootMargin?: string
}

/**
 * useScrollSpy — observes the given element ids via IntersectionObserver and
 * returns the id of the element currently in view.
 *
 * When multiple elements are intersecting in the same callback batch, the
 * topmost one (smallest boundingClientRect.top) wins.
 *
 * @param ids       Array of element ids to observe.
 * @param options   Optional IntersectionObserver root margin.
 * @returns         The id of the currently intersecting element, or null if
 *                  no element is in view yet.
 */
export function useScrollSpy(ids: string[], options?: ScrollSpyOptions): string | null {
  const [activeId, setActiveId] = useState<string | null>(null)

  const idsKey = ids.join(',')
  const rootMargin = options?.rootMargin ?? '-10% 0px -80% 0px'

  useEffect(() => {
    const observedIds = idsKey ? idsKey.split(',') : []

    const observer = new IntersectionObserver(
      (entries) => {
        // Among all intersecting entries in this batch, pick the topmost one
        // (smallest boundingClientRect.top). Ties are broken by first-occurrence.
        let topmostEntry: IntersectionObserverEntry | null = null
        for (const entry of entries) {
          if (entry.isIntersecting) {
            if (
              topmostEntry === null ||
              entry.boundingClientRect.top < topmostEntry.boundingClientRect.top
            ) {
              topmostEntry = entry
            }
          }
        }
        if (topmostEntry !== null) {
          setActiveId((topmostEntry.target as Element).id)
        }
      },
      { rootMargin },
    )

    for (const id of observedIds) {
      const el = document.getElementById(id)
      if (el !== null) {
        observer.observe(el)
      }
    }

    return () => {
      observer.disconnect()
    }
  }, [idsKey, rootMargin])

  return activeId
}
