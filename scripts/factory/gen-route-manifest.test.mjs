// Tests for the route manifest generator (scripts/factory/gen-route-manifest.mjs).
// Run: node --test scripts/factory/gen-route-manifest.test.mjs
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { extractRoutes } from './gen-route-manifest.mjs'

const FIXTURE = `
export function R() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/app" element={<Shell />}>
        <Route path="reports" element={
          <ModuleGuard module="Accounting">
            <RequirePermission permission="reports.view"><ReportsPage /></RequirePermission>
          </ModuleGuard>
        } />
      </Route>
    </Routes>
  )
}`

test('extractRoutes joins nested paths and captures guards', () => {
  const routes = extractRoutes(FIXTURE, 'fixture.tsx')
  assert.deepEqual(routes.find((r) => r.path === '/app/reports'), {
    path: '/app/reports', component: 'ReportsPage',
    module_gate: 'Accounting', permission: 'reports.view',
  })
  assert.equal(routes.find((r) => r.path === '/login').module_gate, null)
  assert.equal(routes.find((r) => r.path === '/login').permission, null)
})

// A4 — <RequirePermission moduleKey="X"> counts as a module gate.
test('extractRoutes treats RequirePermission moduleKey as module_gate (A4)', () => {
  const fixture = `
    <Routes>
      <Route path="/inv" element={
        <RequirePermission moduleKey="inventory"><InvPage /></RequirePermission>
      } />
    </Routes>`
  const routes = extractRoutes(fixture, 'fixture.tsx')
  assert.deepEqual(routes, [{
    path: '/inv', component: 'InvPage', module_gate: 'inventory', permission: null,
  }])
})

// A4 — when BOTH ModuleGuard and moduleKey wrap a route, ModuleGuard wins.
test('extractRoutes prefers ModuleGuard over moduleKey when both wrap (A4)', () => {
  const fixture = `
    <Routes>
      <Route path="/batches" element={
        <ModuleGuard module="BatchExpiry">
          <RequirePermission moduleKey="inventory"><BatchListPage /></RequirePermission>
        </ModuleGuard>
      } />
    </Routes>`
  const routes = extractRoutes(fixture, 'fixture.tsx')
  assert.equal(routes[0].module_gate, 'BatchExpiry')
  assert.equal(routes[0].component, 'BatchListPage')
})

test('extractRoutes emits index routes at the parent path and skips layout routes', () => {
  const fixture = `
    <Routes>
      <Route path="/" element={<RequireAuth><Layout /></RequireAuth>}>
        <Route index element={<DashboardLanding />} />
        <Route path="dashboard" element={<SuspenseWrapper><Dashboard /></SuspenseWrapper>} />
      </Route>
    </Routes>`
  const routes = extractRoutes(fixture, 'fixture.tsx')
  assert.deepEqual(routes.map((r) => r.path).sort(), ['/', '/dashboard'])
  assert.equal(routes.find((r) => r.path === '/').component, 'DashboardLanding')
  assert.equal(routes.find((r) => r.path === '/dashboard').component, 'Dashboard')
})

test('extractRoutes skips catch-all (*) routes', () => {
  const fixture = `
    <Routes>
      <Route path="/setup" element={<TerminalSetupPage />} />
      <Route path="*" element={<Navigate to="/setup" replace />} />
      <Route path="/*" element={<AppShell />} />
    </Routes>`
  const routes = extractRoutes(fixture, 'fixture.tsx')
  assert.deepEqual(routes.map((r) => r.path), ['/setup'])
})

test('extractRoutes resolves the component through a conditional element', () => {
  const fixture = `
    <Routes>
      <Route path="/reports" element={isManager ? <ReportsPage /> : <Navigate to="/" replace />} />
    </Routes>`
  const routes = extractRoutes(fixture, 'fixture.tsx')
  assert.deepEqual(routes, [{
    path: '/reports', component: 'ReportsPage', module_gate: null, permission: null,
  }])
})

test('extractRoutes looks through KeyedByRouteId to the routed page', () => {
  const fixture = `
    <Routes>
      <Route path="/invoices/:id" element={
        <KeyedByRouteId><InvoiceDetailPage /></KeyedByRouteId>
      } />
    </Routes>`
  const routes = extractRoutes(fixture, 'fixture.tsx')
  assert.deepEqual(routes, [{
    path: '/invoices/:id', component: 'InvoiceDetailPage', module_gate: null, permission: null,
  }])
})

test('extractRoutes keeps an unknown local wrapper as the routed component', () => {
  const fixture = `
    <Routes>
      <Route path="/wrapped" element={
        <UnknownLocalWrapper><InnerPage /></UnknownLocalWrapper>
      } />
    </Routes>`
  const routes = extractRoutes(fixture, 'fixture.tsx')
  assert.deepEqual(routes, [{
    path: '/wrapped', component: 'UnknownLocalWrapper', module_gate: null, permission: null,
  }])
})

test('extractRoutes inherits parent element guards into children, nearest wins', () => {
  const fixture = `
    <Routes>
      <Route path="/mod" element={<ModuleGuard module="Outer"><Layout /></ModuleGuard>}>
        <Route path="a" element={<PageA />} />
        <Route path="b" element={<ModuleGuard module="Inner"><PageB /></ModuleGuard>} />
      </Route>
    </Routes>`
  const routes = extractRoutes(fixture, 'fixture.tsx')
  assert.equal(routes.find((r) => r.path === '/mod/a').module_gate, 'Outer')
  assert.equal(routes.find((r) => r.path === '/mod/b').module_gate, 'Inner')
})

test('extractRoutes sorts output by path', () => {
  const fixture = `
    <Routes>
      <Route path="/z" element={<Z />} />
      <Route path="/a" element={<A />} />
    </Routes>`
  const routes = extractRoutes(fixture, 'fixture.tsx')
  assert.deepEqual(routes.map((r) => r.path), ['/a', '/z'])
})
