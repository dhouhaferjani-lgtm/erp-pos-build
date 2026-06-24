import { useState, useEffect } from 'react'

export interface ScrollSpyOptions {
  rootMargin?: string
}

/**
 * useScrollSpy — observes the given element ids via IntersectionObserver and
 * returns the id of the element currently in view.
 *
 * @param ids       Array of element ids to observe.
 * @param options   Optional IntersectionObserver root margin.
 * @returns         The id of the currently intersecting element, or null if
 *                  no element is in view yet.
 */
export function useScrollSpy(ids: string[], options?: ScrollSpyOptions): string | null {
  const [activeId, setActiveId] = useState<string | null>(null)

  useEffect(() => {
    const rootMargin = options?.rootMargin ?? '-10% 0px -80% 0px'

    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            setActiveId((entry.target as Element).id)
          }
        }
      },
      { rootMargin },
    )

    for (const id of ids) {
      const el = document.getElementById(id)
      if (el !== null) {
        observer.observe(el)
      }
    }

    return () => {
      observer.disconnect()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ids.join(','), options?.rootMargin])

  return activeId
}
