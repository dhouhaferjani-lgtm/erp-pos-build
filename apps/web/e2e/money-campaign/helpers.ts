import { type Browser, type BrowserContext, type Page, expect } from '@playwright/test'

/**
 * Shared helpers for the pre-launch MONEY TEST CAMPAIGN (docs/qa/2026-08-01-money-test-plan.md).
 *
 * Targets the LIVE local stack (web http://localhost:5173, api http://127.0.0.1:8010),
 * tenant `demo-pharmacy-tn` (DemoPharmacySeeder). No mocks — every request hits the real API.
 */

export type Role = 'owner' | 'manager' | 'cashier' | 'accountant' | 'viewer' | 'technician'

interface Credentials {
  email: string
  password: string
  pin: string | null
}

// accountant/viewer/technician: added 2026-08-02 (W-1 reconciliation pass,
// plan §A.6/C-3) via DemoPharmacySeeder::seedRoleCoverageUsers() -- the
// Spatie roles already existed in RolesAndPermissionsSeeder.php; only the
// user rows were missing. None carry a POS pin (not POS-floor personas).
export const ROLE_CREDENTIALS: Record<Role, Credentials> = {
  owner: { email: 'owner@pharmabio.tn', password: 'password', pin: '1234' },
  manager: { email: 'manager@pharmabio.tn', password: 'password', pin: '5678' },
  cashier: { email: 'cashier@pharmabio.tn', password: 'password', pin: '0000' },
  accountant: { email: 'accountant@pharmabio.tn', password: 'password', pin: null },
  viewer: { email: 'viewer@pharmabio.tn', password: 'password', pin: null },
  technician: { email: 'technician@pharmabio.tn', password: 'password', pin: null },
}

/**
 * Pre-accept the cookie-consent bar (localStorage key `autoerp-cookie-consent`) via an
 * init script, so it never renders — including after a reload — and never intercepts
 * pointer events aimed at the TopBar dropdown underneath it.
 */
export async function dismissCookieConsent(page: Page): Promise<void> {
  await page.addInitScript(() => {
    window.localStorage.setItem('autoerp-cookie-consent', 'accepted')
  })
}

/**
 * Log in as one of the three seeded demo-pharmacy-tn roles via the real login form.
 * Waits until the app has left /login AND the authenticated shell (TopBar user menu)
 * has rendered — by that point CompanyProvider has resolved a current company, so
 * apiRequest() below can rely on the persisted company id being present.
 */
export async function loginAsRole(page: Page, role: Role): Promise<void> {
  const { email, password } = ROLE_CREDENTIALS[role]
  await dismissCookieConsent(page)
  await page.goto('/login')
  await page.getByLabel(/email address/i).fill(email)
  await page.getByLabel(/^password$/i).fill(password)
  await page.getByRole('button', { name: /sign in/i }).click()
  await expect(page).not.toHaveURL(/\/login/, { timeout: 15_000 })
  // TopBar user menu button — proof the authenticated shell (and CompanyProvider) mounted.
  await expect(page.getByRole('button', { name: /profile/i })).toBeVisible({ timeout: 15_000 })
}

/**
 * Log out.
 *
 * FORMER DEFECT (fixed — see docs/superpowers/tickets/2026-08-02-topbar-dropdown-unclickable-zindex.md
 * and AUTH-09 in auth-session.spec.ts, which now asserts the real UI click path
 * directly): TopBar's user-menu dropdown (src/components/organisms/TopBar/TopBar.tsx)
 * was `position: absolute` with NO z-index, inside a sibling that precedes
 * DashboardLayout's `<main className="relative ...">`
 * (src/components/templates/DashboardLayout/DashboardLayout.tsx:57). Per CSS
 * stacking rules, two positioned siblings with z-index:auto stack in DOM order —
 * <main> came later, so it won and silently swallowed pointer events aimed at the
 * part of the dropdown that visually overlapped it. Fixed by adding an explicit
 * z-index to the dropdown containers (matching the z-50 already used by
 * CompanySelector/ViewScopePicker/QuickCreateButton). The real UI click path
 * (Settings, Sign out, language switcher) now works for real mouse users.
 * This helper is KEPT as a fast, direct-API fallback for every OTHER spec in this
 * suite that just needs to log out as a setup step and doesn't care about
 * exercising the UI click path itself — it calls the exact same request the
 * button calls (`POST /auth/logout`) and clears the same client state
 * (`useLogout`/`clearAllAppState`), which still genuinely exercises server-side
 * session invalidation.
 */
export async function logout(page: Page): Promise<void> {
  await apiRequest(page, 'POST', '/auth/logout')
  await page.evaluate(() => {
    window.localStorage.removeItem('autoerp-auth')
  })
  await page.goto('/login')
  await expect(page).toHaveURL(/\/login/, { timeout: 15_000 })
}

export interface ApiResult {
  status: number
  body: unknown
}

/**
 * Issue a real API request FROM THE BROWSER CONTEXT (same-origin fetch), so it carries
 * the same Sanctum session cookie, XSRF token, Bearer token (autoerp-auth) and
 * X-Company-Id (autoerp-company) header that the SPA itself would send. This is what
 * lets PERM cases assert the API layer, not just the UI layer, per plan §I.4:
 * "Every PERM case must assert BOTH layers."
 */
export async function apiRequest(
  page: Page,
  method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE',
  path: string,
  body?: unknown
): Promise<ApiResult> {
  return page.evaluate(
    async ({ method, path, body }) => {
      function readPersisted(key: string): unknown {
        const raw = localStorage.getItem(key)
        if (!raw) return null
        try {
          return JSON.parse(raw)?.state ?? null
        } catch {
          return null
        }
      }
      const auth = readPersisted('autoerp-auth') as { token?: string } | null
      const company = readPersisted('autoerp-company') as { currentCompanyId?: string } | null
      const xsrfMatch = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)

      const headers: Record<string, string> = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      }
      if (auth?.token) headers['Authorization'] = `Bearer ${auth.token}`
      if (company?.currentCompanyId) headers['X-Company-Id'] = company.currentCompanyId
      if (xsrfMatch) headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrfMatch[1])

      const res = await fetch(`/api/v1${path}`, {
        method,
        headers,
        credentials: 'include',
        body: body !== undefined ? JSON.stringify(body) : undefined,
      })
      let parsed: unknown = null
      try {
        parsed = await res.json()
      } catch {
        parsed = null
      }
      return { status: res.status, body: parsed }
    },
    { method, path, body }
  )
}

// ---------------------------------------------------------------------------
// C-13 — concurrency harness (added by W-7, campaign plan §C debt item C-13:
// "A two-`browser.newContext()` helper in `helpers.ts` + a request-replay
// helper for simultaneous POSTs. Nothing in the repo does this today.").
//
// Two shapes, deliberately kept apart because they answer different questions:
//
//  * `withTwoSessions()` — two REAL, fully independent browser contexts (own
//    cookie jar, own localStorage, own auth token). This is the only way to
//    model a STALE EDIT: session B must have loaded the document BEFORE
//    session A changed it, which requires two separate client states. Use it
//    for CONC-01 / CONC-06.
//
//  * `raceTwo()` — fires two thunks with `Promise.all` and classifies the two
//    outcomes. For a genuine simultaneous-POST replay this must be driven at
//    the API layer (two `fetch`es land within microseconds of each other);
//    driving two BROWSERS through a UI flow serialises on render and proves
//    nothing about the server's race window. Use it for CONC-02..05.
// ---------------------------------------------------------------------------

export interface TwoSessions {
  contextA: BrowserContext
  contextB: BrowserContext
  pageA: Page
  pageB: Page
}

/**
 * Opens two independent browser contexts, logs each in (optionally as
 * different roles), hands both pages to `body`, and ALWAYS closes both
 * contexts — including when `body` throws, so a failing concurrency case
 * never leaks a context into the next test.
 *
 * Both contexts are created from the SAME `browser` fixture, so nothing here
 * depends on the worker count; the specs that assert a shared-state delta
 * carry their own `workers === 1` runtime guard.
 */
export async function withTwoSessions<T>(
  browser: Browser,
  roles: { a: Role; b: Role },
  body: (sessions: TwoSessions) => Promise<T>
): Promise<T> {
  const contextA = await browser.newContext()
  const contextB = await browser.newContext()
  try {
    const pageA = await contextA.newPage()
    const pageB = await contextB.newPage()
    await loginAsRole(pageA, roles.a)
    await loginAsRole(pageB, roles.b)
    return await body({ contextA, contextB, pageA, pageB })
  } finally {
    await contextA.close()
    await contextB.close()
  }
}

export interface RaceOutcome<T> {
  /** Both settled results, in submission order. */
  results: [PromiseSettledResult<T>, PromiseSettledResult<T>]
  /** The fulfilled values only (a thunk that THREW is not a value). */
  fulfilled: T[]
  /** The rejection reasons only. */
  rejected: unknown[]
}

/**
 * Fires two thunks as close to simultaneously as this runtime allows and
 * returns BOTH outcomes without ever throwing. `Promise.allSettled`, not
 * `Promise.all`: `all` rejects on the first failure and DISCARDS the other
 * outcome, which is exactly the observation a concurrency case needs (the
 * loser's error IS the evidence). Callers assert the money effect
 * afterwards — "exactly one row", never "the second call failed", because a
 * duplicate-suppressing product may legitimately answer 200 twice while
 * writing once.
 */
export async function raceTwo<T>(first: () => Promise<T>, second: () => Promise<T>): Promise<RaceOutcome<T>> {
  const settled = await Promise.allSettled([first(), second()])
  return {
    results: settled as [PromiseSettledResult<T>, PromiseSettledResult<T>],
    fulfilled: settled.flatMap((r) => (r.status === 'fulfilled' ? [r.value] : [])),
    rejected: settled.flatMap((r) => (r.status === 'rejected' ? [r.reason] : [])),
  }
}

/** French grouping uses U+202F NARROW NO-BREAK SPACE, not ASCII space (plan §I.3). */
export const FR_NBSP = ' '

/** Normalizer for when a test deliberately wants to ignore the NBSP-vs-ASCII-space distinction. */
export function normalizeSpaces(s: string): string {
  return s.replace(/\s+/g, ' ')
}
