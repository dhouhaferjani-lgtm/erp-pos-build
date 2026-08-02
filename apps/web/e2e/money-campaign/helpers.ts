import { type Page, expect } from '@playwright/test'

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
 * KNOWN DEFECT (recorded in the campaign results, NOT fixed here — this file must
 * not touch application code): TopBar's user-menu dropdown
 * (src/components/organisms/TopBar/TopBar.tsx) is `position: absolute` with NO
 * z-index, inside a sibling that precedes DashboardLayout's
 * `<main className="relative ...">` (src/components/templates/DashboardLayout/DashboardLayout.tsx:57).
 * Per CSS stacking rules, two positioned siblings with z-index:auto stack in DOM
 * order — <main> comes later, so it wins and silently swallows pointer events aimed
 * at the part of the dropdown that visually overlaps it. Confirmed two ways: (1) a
 * plain `.click()` times out after 30s reporting "<section> from <main> subtree
 * intercepts pointer events" even though the dropdown is plainly visible on top in
 * a screenshot; (2) even a synthetic `element.click()` dispatched directly on the
 * "Sign out" button node does not navigate away — something upstream of the
 * onClick handler never receives it either. Real mouse users cannot reliably use
 * this menu (Settings, Sign out, and — same pattern — the language switcher) on
 * any page tall enough for <main> to extend under the header, i.e. effectively
 * everywhere. This helper routes around it by calling the exact same request the
 * button calls (`POST /auth/logout`) and clearing the same client state
 * (`useLogout`/`clearAllAppState`), which still genuinely exercises server-side
 * session invalidation for every other spec that depends on being able to log out.
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

/** French grouping uses U+202F NARROW NO-BREAK SPACE, not ASCII space (plan §I.3). */
export const FR_NBSP = ' '

/** Normalizer for when a test deliberately wants to ignore the NBSP-vs-ASCII-space distinction. */
export function normalizeSpaces(s: string): string {
  return s.replace(/\s+/g, ' ')
}
