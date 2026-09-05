import { execFileSync } from 'node:child_process'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { expect, type ConsoleMessage, type Page, type Request, type Response, type Route } from '@playwright/test'

import { apiRequest, journeyState } from '../campaign/journey'

/* ------------------------------------------------------------------ */
/* Evidence sink                                                       */
/* ------------------------------------------------------------------ */

export const EVIDENCE_DIR = process.env['RH_EVIDENCE_DIR']
  ?? '/private/tmp/claude-501/-Users-houssamr-Projects-syneriva-apps-erp/e659976d-e163-429c-8afe-6aaef9cec86d/scratchpad/rh-browser/evidence'

export interface LegRecord {
  id: string
  lane: string
  scenario: string
  result: 'PASS' | 'FAIL' | 'BLOCKED'
  evidence: string
  fivexx: number
  consoleErrors: number
}

export const ledger: LegRecord[] = []

export function record(entry: LegRecord): void {
  ledger.push(entry)
  console.log(`[RH] ${entry.id} | ${entry.result} | ${entry.scenario} | ${entry.evidence} | 5xx=${String(entry.fivexx)} console-errors=${String(entry.consoleErrors)}`)
}

export function flushLedger(): void {
  mkdirSync(EVIDENCE_DIR, { recursive: true })
  writeFileSync(
    resolve(EVIDENCE_DIR, 'ledger.json'),
    JSON.stringify(ledger, null, 2),
    'utf8',
  )
}

/* ------------------------------------------------------------------ */
/* psql                                                                */
/* ------------------------------------------------------------------ */

export function sql(db: string, query: string): string[] {
  const output = execFileSync(
    'psql',
    ['-h', '127.0.0.1', '-p', '5433', '-U', 'autoerp', '-d', db, '-At', '-c', query],
    { encoding: 'utf8', env: { ...process.env, PGPASSWORD: 'autoerp_secret' } },
  )
  return output
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '')
    // psql -At still prints the command tag after RETURNING rows.
    .filter((line) => !/^(INSERT \d+ \d+|UPDATE \d+|DELETE \d+)$/.test(line))
}

export function tenantDb(): string {
  const tenantId = journeyState.tenantId
  if (tenantId === undefined || tenantId === '') {
    throw new Error('tenantDb: journeyState.tenantId missing — RH-SETUP must run first')
  }
  return `tenant${tenantId}`
}

export function quoted(value: string): string {
  return `'${value.replaceAll("'", "''")}'`
}

/* ------------------------------------------------------------------ */
/* 5xx + console-error guards (owner rule: every probe asserts zero)   */
/* ------------------------------------------------------------------ */

export interface GuardFinding { kind: 'console-error' | 'http-5xx'; message: string }

export interface CaptureGuard {
  findings: readonly GuardFinding[]
  tolerated: readonly string[]
  /**
   * Fulfil `route` with a deliberate failure AND register THAT REQUEST as the
   * only one whose 5xx this guard will tolerate. Gate r1 MAJ-5: a path-scoped
   * tolerance swallowed a genuine server 500 on the retry; the tolerance is now
   * scoped to the exact `Request` object this handler answered, plus a matching
   * budget of one console line for it.
   */
  forceFail: (route: Route, status: number, body: unknown) => Promise<void>
  /**
   * Tolerate ONE console-error line containing `fragment`, for this leg only.
   * Every call must name a signal the leg itself provokes; the entry dies with
   * the guard, so nothing leaks into the next leg (gate r1 MIN-4).
   */
  tolerateConsole: (fragment: string, reason: string) => void
  /** Detach the listeners and return the MEASURED counts. */
  stop: () => GuardCounts
  /** Detach and assert both measured counts are zero. */
  assertClean: () => GuardCounts
}

export interface GuardCounts { fivexx: number; consoleErrors: number; detail: string; tolerated: string }

/** Paths the harness itself fetched — Chromium logs an expected 4xx as a console error. */
export const harnessPaths = new Set<string>()

export function captureGuards(page: Page): CaptureGuard {
  const findings: GuardFinding[] = []
  const tolerated: string[] = []
  const forcedRequests = new Set<Request>()
  /** url -> how many console lines about that url this guard still tolerates. */
  const forcedConsoleBudget = new Map<string, number>()
  const consoleFragments = new Map<string, string>()

  function namedTolerance(message: ConsoleMessage): string | null {
    const url = message.location().url
    const text = message.text()

    // (a) K-10 class: the public auth pages probe /auth/me unauthenticated.
    if (/\/(login|register)(?:[/?#]|$)/.test(page.url()) && /\/auth\/me/i.test(url) && /401/.test(text)) {
      return `/auth/me 401 on ${new URL(page.url()).pathname} (K-10 class)`
    }
    // (b) L-ENV-1: no Reverb on this stack unless a leg mocks one; Echo logs a socket error.
    if (/^WebSocket connection to 'wss?:\/\/[^']*\/app\//.test(text)) {
      return `Echo websocket refused (L-ENV-1, no Reverb on the local stack)`
    }
    // (c) THIRD-PARTY ORIGIN, not the product: index.html:10-13 loads Public Sans /
    // IBM Plex Mono / Montserrat from fonts.googleapis.com. A transient
    // ERR_NETWORK_CHANGED / ERR_CONNECTION there says nothing about AutoERP.
    // Declared explicitly (gate r1 BLK-1) rather than folded into a wildcard.
    if (/^https:\/\/fonts\.(googleapis|gstatic)\.com\//.test(url)) {
      return `third-party font fetch failed (${text.slice(0, 60)}) — external origin, not a product signal`
    }
    // (d) A 4xx on a path the HARNESS itself probed with apiJson (the leg expects it).
    if (/^Failed to load resource/.test(text) && !/50\d/.test(text)) {
      try {
        const u = new URL(url)
        if (harnessPaths.has(`${u.pathname}${u.search}`)) return `harness API probe ${u.pathname} (expected non-2xx)`
      } catch { /* not a URL */ }
    }
    // (e) The console line for a failure THIS guard forced — budgeted 1:1 with the
    // responses it actually fulfilled, so a second, unforced failure is NOT tolerated.
    for (const [forcedUrl, budget] of forcedConsoleBudget) {
      if (budget <= 0) continue
      if (!url.includes(forcedUrl)) continue
      if (!/50\d/.test(text) && !/^Failed to load resource/.test(text)) continue
      forcedConsoleBudget.set(forcedUrl, budget - 1)
      return `console line for the failure this leg forced on ${forcedUrl}`
    }
    // (f) A component's own console.error for a failure this leg forced, named by the leg.
    for (const [fragment, reason] of consoleFragments) {
      if (text.includes(fragment)) return `${reason} ("${fragment}")`
    }
    return null
  }

  const onResponse = (response: Response): void => {
    if (response.status() < 500) return
    if (forcedRequests.has(response.request())) {
      tolerated.push(`forced ${String(response.status())} ${response.request().method()} ${response.url()}`)
      return
    }
    findings.push({ kind: 'http-5xx', message: `${String(response.status())} ${response.request().method()} ${response.url()}` })
  }
  const onConsole = (message: ConsoleMessage): void => {
    if (message.type() !== 'error') return
    const name = namedTolerance(message)
    if (name !== null) { tolerated.push(name); return }
    findings.push({ kind: 'console-error', message: `${message.text()} [url=${message.location().url} page=${page.url()}]` })
  }

  page.on('response', onResponse)
  page.on('console', onConsole)

  const stop = (): GuardCounts => {
    page.off('response', onResponse)
    page.off('console', onConsole)
    // Harness-probe tolerance is per leg: a 4xx path probed once must not stay
    // tolerated for the rest of the worker (gate r1 MIN-4).
    harnessPaths.clear()
    return {
      fivexx: findings.filter((f) => f.kind === 'http-5xx').length,
      consoleErrors: findings.filter((f) => f.kind === 'console-error').length,
      detail: JSON.stringify(findings),
      tolerated: JSON.stringify(tolerated),
    }
  }

  return {
    findings,
    tolerated,
    forceFail: async (route: Route, status: number, body: unknown): Promise<void> => {
      forcedRequests.add(route.request())
      let key = route.request().url()
      try { key = new URL(key).pathname } catch { /* keep raw */ }
      forcedConsoleBudget.set(key, (forcedConsoleBudget.get(key) ?? 0) + 1)
      await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
    },
    tolerateConsole: (fragment: string, reason: string): void => { consoleFragments.set(fragment, reason) },
    stop,
    assertClean: () => {
      const counts = stop()
      expect(findings, `leg emitted forbidden 5xx/console errors: ${JSON.stringify(findings, null, 2)}`).toEqual([])
      return counts
    },
  }
}

/* ------------------------------------------------------------------ */
/* Network recorder                                                    */
/* ------------------------------------------------------------------ */

export interface Wire {
  method: string
  url: string
  path: string
  search: string
  postData: string | null
  companyHeader: string | null
  startedAt: number
  finishedAt: number | null
  status: number | null
}

export interface Recorder {
  wires: Wire[]
  stop: () => void
  /** API calls matching a path substring, harness-initiated ones excluded. */
  matching: (needle: string, method?: string) => Wire[]
  reset: () => void
}

export function recordNetwork(page: Page): Recorder {
  const wires: Wire[] = []
  const byRequest = new Map<Request, Wire>()

  const onRequest = (request: Request): void => {
    const url = request.url()
    if (!url.includes('/api/v1/')) return
    let path = url
    let search = ''
    try { const u = new URL(url); path = u.pathname; search = u.search } catch { /* keep raw */ }
    const wire: Wire = {
      method: request.method(), url, path, search,
      postData: request.postData(),
      companyHeader: request.headers()['x-company-id'] ?? null,
      startedAt: Date.now(), finishedAt: null, status: null,
    }
    byRequest.set(request, wire)
    wires.push(wire)
  }
  const onResponse = (response: Response): void => {
    const wire = byRequest.get(response.request())
    if (wire === undefined) return
    wire.finishedAt = Date.now()
    wire.status = response.status()
  }

  page.on('request', onRequest)
  page.on('response', onResponse)

  return {
    wires,
    stop: () => { page.off('request', onRequest); page.off('response', onResponse) },
    matching: (needle: string, method?: string) => wires.filter(
      (w) => w.path.includes(needle) && (method === undefined || w.method === method),
    ),
    reset: () => { wires.length = 0; byRequest.clear() },
  }
}

export function bodyField(wire: Wire, field: string): string | null {
  if (wire.postData === null) return null
  try {
    const parsed = JSON.parse(wire.postData) as Record<string, unknown>
    const value = parsed[field]
    return typeof value === 'string' ? value : value === undefined ? null : JSON.stringify(value)
  } catch { return null }
}

/* ------------------------------------------------------------------ */
/* API convenience                                                     */
/* ------------------------------------------------------------------ */

export type ApiMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

export async function apiJson(
  page: Page,
  method: ApiMethod,
  path: string,
  body?: unknown,
  companyId?: string,
): Promise<{ status: number; body: unknown }> {
  harnessPaths.add(`/api/v1${path}`)
  const result = await apiRequest(page, method, path, body, companyId)
  return { status: result.status, body: result.body }
}

export function asRecord(value: unknown, description: string): Record<string, unknown> {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) {
    throw new Error(`${description}: expected an object, got ${JSON.stringify(value)}`)
  }
  return value as Record<string, unknown>
}

export function dataOf(body: unknown, description: string): unknown {
  const envelope = asRecord(body, description)
  if (!('data' in envelope)) throw new Error(`${description}: no data envelope — ${JSON.stringify(body)}`)
  return envelope['data']
}

export function rowsOf(body: unknown, description: string): Record<string, unknown>[] {
  const data = dataOf(body, description)
  if (!Array.isArray(data)) throw new Error(`${description}: data is not an array`)
  return data.map((row, index) => asRecord(row, `${description}[${String(index)}]`))
}

export function str(row: Record<string, unknown>, key: string): string {
  const value = row[key]
  if (typeof value !== 'string') throw new Error(`field ${key} is not a string: ${JSON.stringify(value)}`)
  return value
}

export async function shot(page: Page, id: string): Promise<string> {
  mkdirSync(EVIDENCE_DIR, { recursive: true })
  const path = resolve(EVIDENCE_DIR, `${id}.png`)
  await page.screenshot({ fullPage: true, path })
  return `${id}.png`
}

export function stamp(): string {
  return new Date().toISOString()
}

/* ------------------------------------------------------------------ */
/* Cross-run state (the register route is throttle:register 5/15min)   */
/* ------------------------------------------------------------------ */

const STATE_PATH = resolve(EVIDENCE_DIR, 'state.json')

export function saveState(state: Record<string, unknown>): void {
  mkdirSync(EVIDENCE_DIR, { recursive: true })
  writeFileSync(STATE_PATH, JSON.stringify(state, null, 2), 'utf8')
}

export function loadState(): Record<string, unknown> | null {
  if (process.env['RH_FRESH'] === '1') return null
  if (!existsSync(STATE_PATH)) return null
  try {
    return JSON.parse(readFileSync(STATE_PATH, 'utf8')) as Record<string, unknown>
  } catch { return null }
}

/** Write a verbatim artefact next to the screenshots and return its file name. */
export function writeArtefact(name: string, content: string): string {
  mkdirSync(EVIDENCE_DIR, { recursive: true })
  writeFileSync(resolve(EVIDENCE_DIR, name), content, 'utf8')
  return name
}
