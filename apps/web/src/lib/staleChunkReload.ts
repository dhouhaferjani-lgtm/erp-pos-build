/**
 * Vite emits a `vite:preloadError` event on `window` when a dynamic
 * `import()` (route-level code splitting) fails to fetch its chunk. This
 * happens when a browser tab was loaded before a deploy: the auto-deploy
 * pipeline rebuilds with new hashed chunk filenames, so a stale tab's
 * lazy route imports 404 against the new `index.html`/asset manifest.
 *
 * Without handling this, the dynamic import rejects and bubbles up to the
 * router's ErrorBoundary, which shows a generic error until the user
 * manually refreshes. The fix: reload the tab once (fetching the new
 * `index.html` picks up the current chunk manifest), guarded against a
 * reload loop if the app is somehow broken for another reason.
 */

const STORAGE_KEY = 'autoerp-chunk-reload-at'
const RELOAD_SUPPRESSION_WINDOW_MS = 30_000

export interface StaleChunkReloadDeps {
  /** Triggers the actual page reload (injected for testability). */
  reload: () => void
  /** Returns the current time in ms (injected for testability). */
  now: () => number
  /** Storage used to remember the last reload attempt (defaults to sessionStorage). */
  storage?: Pick<Storage, 'getItem' | 'setItem'>
}

/**
 * Handles a single `vite:preloadError` event: reloads the page once, then
 * suppresses further reloads for `RELOAD_SUPPRESSION_WINDOW_MS` so a
 * persistently broken deploy falls through to the ErrorBoundary instead of
 * reload-looping the tab.
 */
export function handleStalePreloadError(event: Event, deps: StaleChunkReloadDeps): void {
  const { reload, now, storage = sessionStorage } = deps
  const last = Number(storage.getItem(STORAGE_KEY) ?? 0)

  if (now() - last < RELOAD_SUPPRESSION_WINDOW_MS) {
    // Already reloaded recently and the import is still failing — let the
    // ErrorBoundary show rather than loop forever.
    return
  }

  storage.setItem(STORAGE_KEY, String(now()))
  event.preventDefault()
  reload()
}

/**
 * Registers the `vite:preloadError` listener on `window`. Call once from
 * the app entry point (`main.tsx`).
 */
export function registerStaleChunkReload(): void {
  window.addEventListener('vite:preloadError', (event) => {
    handleStalePreloadError(event, {
      reload: () => {
        window.location.reload()
      },
      now: () => Date.now(),
    })
  })
}
