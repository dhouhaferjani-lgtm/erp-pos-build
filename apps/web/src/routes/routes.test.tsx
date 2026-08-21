import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const routesSource = readFileSync(`${process.cwd()}/src/routes/index.tsx`, 'utf8')
const sidebarSource = readFileSync(`${process.cwd()}/src/components/organisms/Sidebar/Sidebar.tsx`, 'utf8')
const commandPaletteSource = readFileSync(`${process.cwd()}/src/components/organisms/CommandPalette/useCommandPalette.ts`, 'utf8')
const posHubSource = readFileSync(`${process.cwd()}/src/features/pos/pages/PosHubPage.tsx`, 'utf8')

function routeBranch(path: string): string {
  const start = routesSource.indexOf(`<Route path="${path}">`)
  expect(start).toBeGreaterThanOrEqual(0)

  const nextBranch = routesSource.indexOf('\n        {/*', start + 1)
  expect(nextBranch).toBeGreaterThan(start)

  return routesSource.slice(start, nextBranch)
}

describe('route module guards', () => {
  it('guards service routes with the Workshop module', () => {
    expect(routeBranch('services')).toContain('<ModuleGuard module="Workshop">')
  })

  it('guards workshop work-order routes with the Workshop module', () => {
    expect(routeBranch('workshop/work-orders')).toContain('<ModuleGuard module="Workshop">')
  })

  it('registers expiry-write-off route under inventory', () => {
    const idx = routesSource.indexOf('path="expiry-write-off"')
    expect(idx).toBeGreaterThanOrEqual(0)
  })

  it('registers placement management with inventory.view permission', () => {
    const idx = routesSource.indexOf('path="placement"')
    expect(idx).toBeGreaterThanOrEqual(0)
    const fragment = routesSource.slice(idx, idx + 350)
    expect(fragment).toContain('permission="inventory.view"')
    expect(fragment).toContain('<PlacementPage />')
  })

  it('guards expiry-write-off route with BatchExpiry module gate', () => {
    const idx = routesSource.indexOf('path="expiry-write-off"')
    const fragment = routesSource.slice(Math.max(0, idx - 10), idx + 400)
    expect(fragment).toContain('<ModuleGuard module="BatchExpiry">')
  })

  it('guards expiry-write-off route with batches.write-off permission', () => {
    const idx = routesSource.indexOf('path="expiry-write-off"')
    const fragment = routesSource.slice(Math.max(0, idx - 10), idx + 400)
    expect(fragment).toContain('permission="batches.write-off"')
  })

  // F&B leak: Table Management + Kitchen Display must be vertical-gated so they
  // are unreachable on non-F&B verticals (e.g. parapharmacy). Module keys mirror
  // the Sidebar nav (Sidebar.tsx: tables -> 'Tables', kitchen -> 'Menu').
  it('guards pos tables (Table Management) route with the Tables module', () => {
    const idx = routesSource.indexOf('path="tables"')
    expect(idx).toBeGreaterThanOrEqual(0)
    const fragment = routesSource.slice(Math.max(0, idx - 10), idx + 400)
    expect(fragment).toContain('<ModuleGuard module="Tables">')
  })

  it('guards pos kitchen (KDS) route with the Menu module', () => {
    const idx = routesSource.indexOf('path="/pos/kitchen"')
    expect(idx).toBeGreaterThanOrEqual(0)
    const fragment = routesSource.slice(Math.max(0, idx - 10), idx + 400)
    expect(fragment).toContain('<ModuleGuard module="Menu">')
  })

  it('keeps shift history reachable while the retired web shift console is absent', () => {
    const retiredShiftPath = ['/pos', '/shifts'].join('')
    const retiredShiftPage = ['POS', 'Shifts', 'Page'].join('')
    expect(routesSource).not.toContain(`path="${retiredShiftPath}"`)
    expect(routesSource).not.toContain(retiredShiftPage)
    expect(routesSource).toContain('path="shift-history"')
    expect(routesSource).toContain('<ShiftHistoryPage />')
    expect(routesSource).toContain('<Route path="*" element={<Navigate to="/dashboard" replace />} />')
    expect(sidebarSource).toContain("href: '/pos/shift-history'")
    expect(posHubSource).toContain("href: '/pos/shift-history'")
  })

  it('removes the duplicate marketing hub while retaining its six sidebar destinations', () => {
    const retiredMarketingPath = ['mark', 'eting'].join('')
    const retiredMarketingPage = ['Marketing', 'HubPage'].join('')
    expect(routesSource).not.toContain(`path="${retiredMarketingPath}"`)
    expect(routesSource).not.toContain(retiredMarketingPage)
    for (const destination of [
      '/crm/companies',
      '/crm/contacts',
      '/pos/loyalty/programs',
      '/pos/loyalty/members',
      '/pos/promotions',
      '/pos/coupons',
    ]) {
      expect(sidebarSource).toContain(`href: '${destination}'`)
    }
  })

  it('removes the finance index hub while retaining every finance child route', () => {
    const financeBranch = routeBranch('finance')
    const retiredFinancePage = ['Finance', 'HubPage'].join('')
    expect(financeBranch).not.toContain('<Route index')
    expect(financeBranch).not.toContain(retiredFinancePage)
    for (const childPath of [
      'overview',
      'lane-separation',
      'cash-movements',
      'chart-of-accounts',
      'ledger',
      'trial-balance',
      'profit-loss',
      'balance-sheet',
      'aged-receivables',
      'aged-payables',
      'journal-entries',
    ]) {
      expect(financeBranch).toContain(`path="${childPath}"`)
    }
  })

  it('keeps chart of accounts only under finance with its guard and inbound links', () => {
    const childPath = ['chart', 'of', 'accounts'].join('-')
    const canonicalHref = ['/finance', childPath].join('/')
    const settingsBranch = routeBranch('settings')
    const financeBranch = routeBranch('finance')
    expect(settingsBranch).not.toContain(`path="${childPath}"`)
    const canonicalIndex = financeBranch.indexOf(`path="${childPath}"`)
    expect(canonicalIndex).toBeGreaterThanOrEqual(0)
    expect(financeBranch.slice(canonicalIndex, canonicalIndex + 350)).toContain(
      'permission="accounts.view"',
    )
    expect(sidebarSource).toContain(`href: '${canonicalHref}'`)
    expect(commandPaletteSource).toContain(`href: '${canonicalHref}'`)
  })

  it('does not register the legacy bank reconciliation route', () => {
    expect(routesSource).not.toContain('path="reconciliation"')
    expect(routesSource).not.toContain('BankReconciliationPage')
  })

  it('guards treasury-native statement list with bank statement view permission', () => {
    const idx = routesSource.indexOf('path="statements"')
    expect(idx).toBeGreaterThanOrEqual(0)
    const fragment = routesSource.slice(Math.max(0, idx - 10), idx + 400)
    expect(fragment).toContain('permission="bank-statements.view"')
    expect(fragment).toContain('<StatementListPage />')
  })

  it('guards the statement workspace with bank statement view permission', () => {
    const idx = routesSource.indexOf('path="statements/:id"')
    expect(idx).toBeGreaterThanOrEqual(0)
    const fragment = routesSource.slice(Math.max(0, idx - 10), idx + 450)
    expect(fragment).toContain('permission="bank-statements.view"')
    expect(fragment).toContain('<ReconciliationWorkspacePage />')
  })

  it('registers treasury overview under finance with reports.operational permission', () => {
    const idx = routesSource.indexOf('path="overview"')
    expect(idx).toBeGreaterThanOrEqual(0)
    const fragment = routesSource.slice(Math.max(0, idx - 200), idx + 500)
    expect(fragment).toContain('TreasuryOverviewPage')
    expect(fragment).toContain('permission="reports.operational"')
  })

  it('lazy-loads recurring expenses under an exact view permission guard', () => {
    expect(routesSource).toContain("import('../features/expenses/pages/RecurringExpensesPage')")
    const branch = routeBranch('expenses')
    const idx = branch.indexOf('path="recurring"')
    expect(idx).toBeGreaterThanOrEqual(0)
    expect(branch.slice(idx, idx + 450)).toContain('permission="expense-recurrences.view"')
    expect(branch.slice(idx, idx + 450)).toContain('<RecurringExpensesPage />')
  })

  it('lazy-loads expense analytics before the dynamic id route under expenses.view', () => {
    expect(routesSource).toContain("import('../features/expenses/pages/ExpenseAnalyticsPage')")
    const branch = routeBranch('expenses')
    const analytics = branch.indexOf('path="analytics"')
    const dynamicId = branch.indexOf('path=":id"')
    expect(analytics).toBeGreaterThanOrEqual(0)
    expect(analytics).toBeLessThan(dynamicId)
    expect(branch.slice(analytics, analytics + 450)).toContain('permission="expenses.view"')
    expect(branch.slice(analytics, analytics + 450)).toContain('<ExpenseAnalyticsPage />')
  })
})

/**
 * Register G-9 — dead import links.
 *
 * The import wizard is registered as `settings/import/:type`. Several call
 * sites linked to `settings/import/wizard/<type>` instead, a path that has never
 * existed: the user landed on a blank/no-match route with no error. Nothing
 * caught it because no test ever compared a navigation target against the real
 * route table.
 */
describe('import navigation targets resolve against the route table', () => {
  // The closing quote anchors the match to a whole path segment, so a sibling
  // route such as `path="importers"` is not mistaken for an import route.
  const IMPORT_ROUTE_PATTERN = /path="(import(?:\/[^"]*)?)"/g

  const importPaths = [...routesSource.matchAll(IMPORT_ROUTE_PATTERN)].map((match) => match[1])

  it('matches whole path segments only, never an `importers`-style sibling', () => {
    const sample = 'path="import" path="import/history" path="importers" path="importers/new"'

    expect([...sample.matchAll(IMPORT_ROUTE_PATTERN)].map((match) => match[1])).toEqual([
      'import',
      'import/history',
    ])
  })

  it('lists every registered import route — update this array when adding an import route', () => {
    expect(importPaths).toEqual(['import', 'import/history', 'import/:type'])
  })

  it('has no source file linking to the non-existent import wizard path', () => {
    const deadPath = '/settings/import/wizard'
    const thisFile = fileURLToPath(import.meta.url)

    const offenders = sourceFilesUnder(`${process.cwd()}/src`)
      // This guard necessarily contains the string it forbids.
      .filter((file) => file !== thisFile)
      .filter((file) => readFileSync(file, 'utf8').includes(deadPath))

    expect(offenders).toEqual([])
  })
})

function sourceFilesUnder(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = join(dir, entry.name)

    if (entry.isDirectory()) {
      return sourceFilesUnder(full)
    }

    return /\.tsx?$/.test(entry.name) ? [full] : []
  })
}
