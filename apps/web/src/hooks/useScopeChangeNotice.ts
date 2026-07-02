import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { useCompanyStore } from '../stores/companyStore'
import { useLocationStore } from '../stores/locationStore'

/**
 * Surfaces a visible confirmation whenever the active scope (company or
 * location) changes — for ANY reason: a user clicking a switcher, a cross-tab
 * `storage`-event adoption, or a deterministic auto-select fallback.
 *
 * It observes the resolved store state rather than hooking each call site, so a
 * single mount covers every switch path. The very first settled render (initial
 * load) is intentionally silent — we only announce genuine transitions away from
 * an already-established scope, never the initial "Now viewing …" on every page
 * load.
 */
export function useScopeChangeNotice(): void {
  const { t } = useTranslation('common')

  const companyId = useCompanyStore((state) => state.currentCompanyId)
  const companyName = useCompanyStore((state) => state.getCurrentCompany()?.name ?? null)
  const locationId = useLocationStore((state) => state.currentLocationId)
  const locationName = useLocationStore((state) => state.getCurrentLocation()?.name ?? null)

  const previous = useRef<{ companyId: string | null; locationId: string | null }>({
    companyId: null,
    locationId: null,
  })

  useEffect(() => {
    const prev = previous.current
    // Only treat it as a switch when a previously-set value actually changes.
    // null -> value (initial resolution) stays silent.
    const companyChanged = prev.companyId !== null && companyId !== prev.companyId
    const locationChanged = prev.locationId !== null && locationId !== prev.locationId

    previous.current = { companyId, locationId }

    if ((companyChanged || locationChanged) && companyId !== null) {
      const message = locationName
        ? t('scope.nowViewing', { company: companyName ?? '', location: locationName })
        : t('scope.nowViewingCompany', { company: companyName ?? '' })
      toast.info(message)
    }
  }, [companyId, companyName, locationId, locationName, t])
}
