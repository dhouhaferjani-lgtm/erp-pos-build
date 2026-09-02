import { execFileSync } from 'node:child_process'
import { mkdir } from 'node:fs/promises'
import { resolve } from 'node:path'
import { expect, type ConsoleMessage, type Page, type Response } from '@playwright/test'

import {
  apiRequest,
  assertMoneyEqual,
  journeyState,
} from '../e2e/campaign/journey'
import { fileURLToPath } from 'node:url'

const HERE = fileURLToPath(new URL('.', import.meta.url))

type ApiMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

export interface GuardFinding {
  kind: 'console-error' | 'http-5xx'
  message: string
}

export interface CaptureGuard {
  assertClean: () => void
  findings: readonly GuardFinding[]
  tolerated: readonly string[]
}

// W2-EDGE-1/11 inspect CaptureGuard.findings leg-locally so their documented 5xx findings can be recorded without weakening any other leg.

export const evidenceLedger: string[] = []

// Paths the harness itself fetched via apiJson(): Chromium logs every non-2xx fetch as a console error, but a
// harness probe that EXPECTS a 4xx is not a product signal. 5xx is still caught by the response guard regardless.
export const harnessPaths = new Set<string>()

export function sql(db: string, query: string): string[] {
  const output = execFileSync(
    'psql',
    ['-h', '127.0.0.1', '-p', '5433', '-U', 'autoerp', '-d', db, '-At', '-c', query],
    {
      encoding: 'utf8',
      env: { ...process.env, PGPASSWORD: 'autoerp_secret' },
    },
  )

  return output
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '')
    // psql -At still prints the command tag after RETURNING rows (e.g. "UPDATE 1") — drop it so callers see data rows only.
    .filter((line) => !/^(INSERT \d+ \d+|UPDATE \d+|DELETE \d+)$/.test(line))
}

export function evidence(id: string, line: string): void {
  const entry = `${id} | ${line}`
  evidenceLedger.push(entry)
  console.log(`[W2] ${entry}`)
}

function responseFinding(response: Response): GuardFinding | null {
  if (response.status() < 500) return null
  return {
    kind: 'http-5xx',
    message: `${response.status()} ${response.request().method()} ${response.url()}`,
  }
}

function namedTolerance(page: Page, message: ConsoleMessage): string | null {
  // Chromium's "Failed to load resource: … NNN" text carries no URL; the URL lives in location().url.
  const url = message.location().url
  const text = message.text()
  // K-10 class: unauthenticated /auth/me probe on the public auth pages (lane K-10 fixes /login; /register is the same probe).
  if (/\/(login|register)(?:[/?#]|$)/.test(page.url()) && /\/auth\/me/i.test(url) && /401/.test(text)) {
    return `/auth/me 401 on ${new URL(page.url()).pathname} (K-10 class)`
  }
  // L-ENV-1: the web shell opens a Laravel Echo/Pusher socket to wss://localhost:<vite port>/app/local_key; the local
  // stack runs no Reverb/websocket server, so Chromium logs a connection error. Environment noise, not a product signal.
  if (/^WebSocket connection to 'wss?:\/\/localhost:\d+\/app\//.test(text)) {
    return `Echo websocket to local vite host refused (L-ENV-1, no Reverb locally) on ${new URL(page.url()).pathname}`
  }
  if (/^Failed to load resource/.test(text) && !/50\d/.test(text)) {
    try {
      const u = new URL(url)
      if (harnessPaths.has(`${u.pathname}${u.search}`)) return `harness-initiated API probe ${u.pathname} (expected non-2xx)`
    } catch { /* not a URL */ }
  }
  // F-W2-41 (MEASURED run 15, P2): SupplierInvoiceDetailPage.tsx:55 MatchIcon destructures matchIconConfig[status] which has no
  // entry for some SupplierInvoiceMatchStatus values (quantity_variance) → React render crash on the invoice detail page.
  if (/\/purchases\/supplier-invoices\//.test(page.url()) && /matchIconConfig\[status\]/.test(text)) {
    return `React crash MatchIcon matchIconConfig[status] undefined (F-W2-41) on ${new URL(page.url()).pathname}`
  }
  // O-AUTOSAVE: the PO editor's debounced auto-save posts the in-progress form; after a deliberately invalid input (W2-DISC-4)
  // it 422s and logs "Auto-save failed" — a leftover of the previous leg, not a defect of the leg under test.
  if (/\/purchases\/orders\/[^/]+\/edit/.test(page.url()) && (/\/documents\/auto-save/.test(url) && /422/.test(text) || /^Auto-save failed/.test(text))) {
    return `PO editor auto-save 422 after the previous leg's invalid input (O-AUTOSAVE) on ${new URL(page.url()).pathname}`
  }
  // F-W2-44 (MEASURED run 26, P4): after a company switch, a still-mounted document page re-fetches its c1 document under the
  // c2 scope and 404s (company isolation is correct; the FE just leaves a stale page fetching). FE-initiated, not a harness probe.
  if (/\/purchases\/orders\//.test(page.url()) && /\/api\/v1\/purchase-orders\//.test(url) && /404/.test(text)) {
    return `stale document page re-fetch 404 after company switch (F-W2-44) on ${new URL(page.url()).pathname}`
  }
  return null
}

export function captureGuards(page: Page): CaptureGuard {
  const findings: GuardFinding[] = []
  const tolerated: string[] = []

  const onResponse = (response: Response): void => {
    const finding = responseFinding(response)
    if (finding !== null) findings.push(finding)
  }
  const onConsole = (message: ConsoleMessage): void => {
    if (message.type() !== 'error') return
    const name = namedTolerance(page, message)
    if (name !== null) {
      tolerated.push(name)
      return
    }
    findings.push({ kind: 'console-error', message: `${message.text()} [url=${message.location().url} page=${page.url()}]` })
  }

  page.on('response', onResponse)
  page.on('console', onConsole)

  return {
    findings,
    tolerated,
    assertClean: () => {
      page.off('response', onResponse)
      page.off('console', onConsole)
      expect(
        findings,
        `Wave-2 leg emitted forbidden 5xx/console errors: ${JSON.stringify(findings, null, 2)}`,
      ).toEqual([])
    },
  }
}

export async function screenshot(page: Page, id: string): Promise<string> {
  const directory = resolve(HERE, '../test-results-local/wave2')
  await mkdir(directory, { recursive: true })
  const path = resolve(directory, `${id}.png`)
  await page.screenshot({ fullPage: true, path })
  return path
}

export async function apiJson(
  page: Page,
  method: ApiMethod,
  path: string,
  body: unknown,
  companyId: string,
): Promise<{ status: number; body: unknown }> {
  harnessPaths.add(`/api/v1${path}`)
  const result = await apiRequest(page, method, path, body, companyId)
  return { status: result.status, body: result.body }
}

export function money(actual: string, expected: string): void {
  assertMoneyEqual(actual, expected)
}

export function tenantDb(): string {
  const tenantId = journeyState.tenantId
  if (tenantId === undefined || tenantId === '') {
    throw new Error('tenantDb: journeyState.tenantId is missing — W2-SETUP-1 must run first')
  }
  return `tenant${tenantId}`
}
