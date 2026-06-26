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
})
