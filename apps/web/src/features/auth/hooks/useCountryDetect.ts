import { useState, useEffect } from 'react'

interface UseCountryDetectResult {
  detectedCountry: string | null
  isDetecting: boolean
}

const IP_API_URL = 'https://ipapi.co/country_code/'
const TIMEOUT_MS = 2000
const DEFAULT_COUNTRY = 'FR'

export function useCountryDetect(): UseCountryDetectResult {
  const [detectedCountry, setDetectedCountry] = useState<string | null>(null)
  const [isDetecting, setIsDetecting] = useState(true)

  useEffect(() => {
    let cancelled = false
    const controller = new AbortController()
    const timeoutId = setTimeout(() => { controller.abort() }, TIMEOUT_MS)

    async function detect() {
      try {
        const response = await fetch(IP_API_URL, { signal: controller.signal })
        if (!cancelled && response.ok) {
          const code = (await response.text()).trim().toUpperCase()
          if (code.length === 2) {
            setDetectedCountry(code)
            setIsDetecting(false)
            return
          }
        }
      } catch {
        // Fall through to locale fallback
      } finally {
        clearTimeout(timeoutId)
      }

      if (cancelled) return

      // Fallback: navigator.language (e.g., fr-FR → FR)
      const locale = navigator.language
      if (locale && locale.includes('-')) {
        const countryFromLocale = locale.split('-')[1].toUpperCase()
        if (countryFromLocale.length === 2) {
          setDetectedCountry(countryFromLocale)
          setIsDetecting(false)
          return
        }
      }

      setDetectedCountry(DEFAULT_COUNTRY)
      setIsDetecting(false)
    }

    void detect()

    return () => {
      cancelled = true
      controller.abort()
    }
  }, [])

  return { detectedCountry, isDetecting }
}
