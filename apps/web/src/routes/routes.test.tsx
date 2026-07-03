import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const routesSource = readFileSync(`${process.cwd()}/src/routes/index.tsx`, 'utf8')

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

  it('guards bank reconciliation with repository view permission', () => {
    const idx = routesSource.indexOf('path="reconciliation"')
    expect(idx).toBeGreaterThanOrEqual(0)
    const fragment = routesSource.slice(Math.max(0, idx - 10), idx + 400)
    expect(fragment).toContain('permission="repositories.view"')
    expect(fragment).not.toContain('permission="repositories.manage"')
  })

  it('registers treasury overview under finance with reports.view permission', () => {
    const idx = routesSource.indexOf('path="overview"')
    expect(idx).toBeGreaterThanOrEqual(0)
    const fragment = routesSource.slice(Math.max(0, idx - 200), idx + 500)
    expect(fragment).toContain('TreasuryOverviewPage')
    expect(fragment).toContain('permission="reports.view"')
  })
})
