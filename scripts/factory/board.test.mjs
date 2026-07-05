// Tests for the factory board CLI (scripts/factory/board.mjs).
// Run: node --test scripts/factory/board.test.mjs
// Uses temp git fixtures (bare origin + clones) — no network, no real board.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { mkdtempSync, writeFileSync, mkdirSync, existsSync, readdirSync, readFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

// Fixtures use plain `master` clones, not the real factory/board branch (A8).
process.env.FACTORY_BOARD_BRANCH = 'master'
process.env.NODE_ENV = 'test'

const {
  validateTask, nextId, loadTasks, syncBoard, claimTask, updateTask, resetStale, newTask,
  sweepRows, newSweep, sweepStatus,
} = await import('./board.mjs')
const { load: yamlLoad } = await import('js-yaml')

const VALID = {
  id: 'T-0001', title: 'x', type: 'bug', track: 'vps',
  status: 'ready', priority: 'P1',
}

const SEED_YAML =
  'id: T-0001\ntitle: x\ntype: chore\ntrack: vps\nstatus: ready\npriority: P1\n'

function gitf(dir, ...args) {
  return execFileSync('git', ['-C', dir, ...args], { encoding: 'utf8' })
}

function makeClone(root, bare, name) {
  const c = join(root, name)
  execFileSync('git', ['clone', bare, c], { stdio: 'ignore' })
  gitf(c, 'config', 'user.email', 't@t')
  gitf(c, 'config', 'user.name', 't')
  gitf(c, 'config', 'commit.gpgsign', 'false')
  gitf(c, 'symbolic-ref', 'HEAD', 'refs/heads/master')
  return c
}

/**
 * Bare origin + clones A/B (+C when withC). Clone A seeds tasks/ and pushes
 * to master; B (and C) pull so all clones start converged.
 */
function fixture({ seed = SEED_YAML, extraFiles = {}, withC = false } = {}) {
  const root = mkdtempSync(join(tmpdir(), 'boardgit-'))
  const bare = join(root, 'origin.git')
  execFileSync('git', ['init', '--bare', '-b', 'master', bare], { stdio: 'ignore' })
  const A = makeClone(root, bare, 'A')
  const B = makeClone(root, bare, 'B')
  const C = withC ? makeClone(root, bare, 'C') : null
  mkdirSync(join(A, 'tasks'), { recursive: true })
  writeFileSync(join(A, 'tasks', 'T-0001-x.yaml'), seed)
  for (const [f, content] of Object.entries(extraFiles)) {
    writeFileSync(join(A, 'tasks', f), content)
  }
  gitf(A, 'add', '.')
  gitf(A, 'commit', '-m', 'seed')
  gitf(A, 'push', 'origin', 'HEAD:master')
  gitf(B, 'pull', 'origin', 'master')
  if (C) gitf(C, 'pull', 'origin', 'master')
  return { root, bare, A, B, C }
}

// ---------------------------------------------------------------- Task 2 ---

test('validateTask accepts a minimal valid task', () => {
  assert.deepEqual(validateTask(VALID), [])
})

test('validateTask rejects missing required, bad enum, unknown key', () => {
  assert.ok(validateTask({ ...VALID, id: undefined }).length > 0)
  assert.ok(validateTask({ ...VALID, status: 'done' }).length > 0)
  assert.ok(validateTask({ ...VALID, surprise: 1 }).length > 0)
})

test('validateTask rejects bad id format and non-mapping input', () => {
  assert.ok(validateTask({ ...VALID, id: 'X-1' }).length > 0)
  assert.ok(validateTask(null).length > 0)
  assert.ok(validateTask('nope').length > 0)
})

test('nextId returns T-0001 for empty dir and increments past max', () => {
  const d = mkdtempSync(join(tmpdir(), 'board-'))
  mkdirSync(join(d, 'tasks'), { recursive: true })
  assert.equal(nextId(join(d, 'tasks')), 'T-0001')
  writeFileSync(join(d, 'tasks', 'T-0009-x.yaml'), 'id: T-0009\n')
  assert.equal(nextId(join(d, 'tasks')), 'T-0010')
})

test('loadTasks parses every tasks/*.yaml and validates', () => {
  const d = mkdtempSync(join(tmpdir(), 'board-'))
  mkdirSync(join(d, 'tasks'))
  writeFileSync(join(d, 'tasks', 'T-0001-a.yaml'),
    'id: T-0001\ntitle: a\ntype: chore\ntrack: laptop\nstatus: ready\npriority: P2\n')
  const { tasks, invalid } = loadTasks(join(d, 'tasks'))
  assert.equal(tasks.length, 1)
  assert.equal(tasks[0].id, 'T-0001')
  assert.deepEqual(invalid, [])
})

// A2 — poisoned-file isolation: loadTasks never throws on a bad file.
test('loadTasks isolates invalid files instead of throwing (A2)', () => {
  const d = mkdtempSync(join(tmpdir(), 'board-'))
  mkdirSync(join(d, 'tasks'))
  writeFileSync(join(d, 'tasks', 'T-0001-a.yaml'),
    'id: T-0001\ntitle: a\ntype: chore\ntrack: laptop\nstatus: ready\npriority: P2\n')
  writeFileSync(join(d, 'tasks', 'T-0002-garbage.yaml'),
    'id: T-0002\nstatus: done\nsurprise: [unclosed\n')
  const { tasks, invalid } = loadTasks(join(d, 'tasks'))
  assert.equal(tasks.length, 1)
  assert.equal(tasks[0].id, 'T-0001')
  assert.equal(invalid.length, 1)
  assert.equal(invalid[0].file, 'T-0002-garbage.yaml')
  assert.ok(invalid[0].errors.length > 0)
})

// ---------------------------------------------------------------- Task 3 ---

test('claim succeeds and sets claimed_by/status/branch', () => {
  const { A } = fixture()
  const r = claimTask(A, 'T-0001', { host: 'vps', session: 's1', branch: 'feat/x' })
  assert.equal(r.ok, true)
  const t = loadTasks(join(A, 'tasks')).tasks[0]
  assert.equal(t.status, 'claimed')
  assert.equal(t.claimed_by.host, 'vps')
  assert.equal(t.branch, 'feat/x')
  assert.ok(t.log.length >= 1)
  // pushed: nothing ahead of origin
  assert.equal(gitf(A, 'log', 'origin/master..HEAD', '--oneline').trim(), '')
})

test('second claim of same task from another clone loses the race', () => {
  const { A, B } = fixture()
  assert.equal(claimTask(A, 'T-0001', { host: 'vps', session: 's1', branch: 'b1' }).ok, true)
  const r = claimTask(B, 'T-0001', { host: 'laptop', session: 's2', branch: 'b2' })
  assert.equal(r.ok, false) // sync/reset sees A's claim → task no longer ready
  const t = loadTasks(join(B, 'tasks')).tasks[0]
  assert.equal(t.claimed_by.host, 'vps')
})

test('claim refuses a non-ready task with not-claimable', () => {
  const { A } = fixture({
    seed: 'id: T-0001\ntitle: x\ntype: chore\ntrack: vps\nstatus: claimed\npriority: P1\n',
  })
  const r = claimTask(A, 'T-0001', { host: 'vps', session: 's1', branch: 'b1' })
  assert.deepEqual(r, { ok: false, reason: 'not-claimable' })
})

test('claim refuses unmet depends_on with not-claimable', () => {
  const { A } = fixture({
    seed: 'id: T-0001\ntitle: x\ntype: chore\ntrack: vps\nstatus: ready\npriority: P1\ndepends_on:\n  - T-0002\n',
    extraFiles: {
      'T-0002-dep.yaml':
        'id: T-0002\ntitle: dep\ntype: chore\ntrack: vps\nstatus: in-progress\npriority: P1\n',
    },
  })
  const r = claimTask(A, 'T-0001', { host: 'vps', session: 's1', branch: 'b1' })
  assert.deepEqual(r, { ok: false, reason: 'not-claimable' })
})

// A9 — a depends_on id that exists in NO file is a distinct failure.
test('claim refuses a depends_on id that does not exist (A9)', () => {
  const { A } = fixture({
    seed: 'id: T-0001\ntitle: x\ntype: chore\ntrack: vps\nstatus: ready\npriority: P1\ndepends_on:\n  - T-0099\n',
  })
  const r = claimTask(A, 'T-0001', { host: 'vps', session: 's1', branch: 'b1' })
  assert.deepEqual(r, { ok: false, reason: 'unknown-dep:T-0099' })
})

test('claim of an unknown task id returns not-found', () => {
  const { A } = fixture()
  const r = claimTask(A, 'T-0042', { host: 'vps', session: 's1', branch: 'b1' })
  assert.deepEqual(r, { ok: false, reason: 'not-found' })
})

test('updateTask appends log entry and flips status', () => {
  const { A } = fixture()
  assert.equal(claimTask(A, 'T-0001', { host: 'vps', session: 's1', branch: 'b1' }).ok, true)
  const r = updateTask(A, 'T-0001', { status: 'in-progress', note: 'started', host: 'vps' })
  assert.equal(r.ok, true)
  const t = loadTasks(join(A, 'tasks')).tasks[0]
  assert.equal(t.status, 'in-progress')
  assert.ok(t.log.length >= 2)
  assert.equal(t.log.at(-1).note, 'started')
})

// A6 — the real push-rejection race, exercised end to end:
// B syncs, C claims+pushes, B claims with its internal sync skipped
// (FACTORY_SKIP_SYNC honored only under NODE_ENV=test) → B's push is
// rejected → fetch+reset discards B's commit → retry re-read sees C's
// claim → not-claimable, and B is left clean and converged on C's claim.
test('lost push race leaves no stranded state and converges (A6)', () => {
  const { B, C } = fixture({ withC: true })
  syncBoard(B)
  assert.equal(claimTask(C, 'T-0001', { host: 'vps-c', session: 'c1', branch: 'feat/c' }).ok, true)
  process.env.FACTORY_SKIP_SYNC = '1'
  let r
  try {
    r = claimTask(B, 'T-0001', { host: 'laptop-b', session: 'b1', branch: 'feat/b' })
  } finally {
    delete process.env.FACTORY_SKIP_SYNC
  }
  assert.deepEqual(r, { ok: false, reason: 'not-claimable' })
  assert.equal(gitf(B, 'status', '--porcelain').trim(), '')
  assert.equal(gitf(B, 'log', 'origin/master..HEAD', '--oneline').trim(), '')
  const tB = loadTasks(join(B, 'tasks')).tasks[0]
  assert.equal(tB.claimed_by.host, 'vps-c')
  const tC = loadTasks(join(C, 'tasks')).tasks[0]
  assert.equal(tC.claimed_by.host, 'vps-c')
})

// A8 — syncBoard refuses a worktree on the wrong branch or with dirt.
test('syncBoard asserts branch and cleanliness (A8/A1)', () => {
  const { A } = fixture()
  gitf(A, 'checkout', '-b', 'other')
  assert.throws(() => syncBoard(A), /not on master/)
  gitf(A, 'checkout', 'master')
  writeFileSync(join(A, 'stray.txt'), 'dirt')
  assert.throws(() => syncBoard(A), /dirty/)
})

// A7 — reset-stale: stale claimed/in-progress tasks go back to ready.
test('resetStale resets stale claims and leaves fresh ones alone (A7)', () => {
  const freshAt = new Date().toISOString()
  const { A, bare } = fixture({
    seed: [
      'id: T-0001', 'title: x', 'type: chore', 'track: vps',
      'status: claimed', 'priority: P1',
      'claimed_by:', '  host: vps', '  session: s1', "  at: '2020-01-01T00:00:00.000Z'",
      'branch: feat/x',
      'log:', "  - at: '2020-01-01T00:00:00.000Z'", '    host: vps', '    note: claimed', '',
    ].join('\n'),
    extraFiles: {
      'T-0002-y.yaml': [
        'id: T-0002', 'title: y', 'type: chore', 'track: vps',
        'status: in-progress', 'priority: P1',
        'claimed_by:', '  host: laptop', '  session: s2', `  at: '${freshAt}'`,
        'log:', `  - at: '${freshAt}'`, '    host: laptop', '    note: claimed', '',
      ].join('\n'),
    },
  })
  const r = resetStale(A, { hours: 12, host: 'janitor' })
  assert.equal(r.ok, true)
  assert.deepEqual(r.reset, ['T-0001'])
  const { tasks } = loadTasks(join(A, 'tasks'))
  const t1 = tasks.find((t) => t.id === 'T-0001')
  assert.equal(t1.status, 'ready')
  assert.equal(t1.claimed_by, undefined)
  assert.equal(t1.branch, undefined)
  assert.match(t1.log.at(-1).note, /reset-stale \(was: vps\/s1\)/)
  const t2 = tasks.find((t) => t.id === 'T-0002')
  assert.equal(t2.status, 'in-progress')
  assert.equal(t2.claimed_by.host, 'laptop')
  // one commit for all resets, pushed
  assert.equal(gitf(A, 'log', 'origin/master..HEAD', '--oneline').trim(), '')
  assert.match(gitf(bare, 'log', '-1', '--format=%s', 'master'), /reset-stale/)
})

test('resetStale with nothing stale makes no commit', () => {
  const freshAt = new Date().toISOString()
  const { A } = fixture({
    seed: [
      'id: T-0001', 'title: x', 'type: chore', 'track: vps',
      'status: claimed', 'priority: P1',
      'claimed_by:', '  host: vps', '  session: s1', `  at: '${freshAt}'`,
      'log:', `  - at: '${freshAt}'`, '    host: vps', '    note: claimed', '',
    ].join('\n'),
  })
  const before = gitf(A, 'rev-parse', 'HEAD').trim()
  const r = resetStale(A, { hours: 12, host: 'janitor' })
  assert.equal(r.ok, true)
  assert.deepEqual(r.reset, [])
  assert.equal(gitf(A, 'rev-parse', 'HEAD').trim(), before)
})

// A9 — new refuses unknown depends_on; happy path writes, commits, pushes.
test('newTask assigns next id, writes file, commits and pushes', () => {
  const { A } = fixture()
  const r = newTask(A, {
    title: 'New shiny thing', type: 'feature', track: 'vps',
    priority: 'P2', status: 'ready',
  })
  assert.equal(r.ok, true)
  assert.equal(r.id, 'T-0002')
  assert.ok(existsSync(join(A, 'tasks', 'T-0002-new-shiny-thing.yaml')))
  const { tasks } = loadTasks(join(A, 'tasks'))
  assert.equal(tasks.length, 2)
  assert.equal(gitf(A, 'log', 'origin/master..HEAD', '--oneline').trim(), '')
})

test('newTask refuses an unknown depends_on id (A9)', () => {
  const { A } = fixture()
  const r = newTask(A, {
    title: 'z', type: 'chore', track: 'vps', priority: 'P3',
    depends_on: ['T-0099'],
  })
  assert.deepEqual(r, { ok: false, reason: 'unknown-dep:T-0099' })
  assert.equal(readdirSync(join(A, 'tasks')).filter((f) => f.endsWith('.yaml')).length, 1)
})

// --------------------------------------------------------------- Task 10 ---

const WEB_MANIFEST = {
  app: 'web',
  routes: [
    { path: '/a', component: 'A', module_gate: null, permission: null },
    { path: '/b', component: 'B', module_gate: 'sales', permission: null },
  ],
}
const POS_MANIFEST = {
  app: 'pos',
  routes: [
    { path: '/', component: 'HomePage', module_gate: null, permission: null },
  ],
  phase_screens: [
    { screen: 'pin-entry', route: null },
    { screen: 'theme-preview', route: '/theme-preview' },
  ],
}

test('sweepRows emits one pending row per route AND per POS phase screen (A3)', () => {
  const rows = sweepRows({ web: WEB_MANIFEST, pos: POS_MANIFEST }, ['web', 'pos'])
  assert.equal(rows.length, 5) // 2 web + 1 pos route + 2 pos phase screens
  assert.ok(rows.every((r) => r.status === 'pending' && r.notes === null))
  assert.deepEqual(rows.filter((r) => r.app === 'web').map((r) => r.path), ['/a', '/b'])
  const posPaths = rows.filter((r) => r.app === 'pos').map((r) => r.path)
  assert.deepEqual(posPaths, ['/', 'screen:pin-entry', '/theme-preview'])
})

test('sweepRows respects the apps filter', () => {
  const rows = sweepRows({ web: WEB_MANIFEST, pos: POS_MANIFEST }, ['web'])
  assert.equal(rows.length, 2)
  assert.ok(rows.every((r) => r.app === 'web'))
})

function manifestsFixture() {
  const d = mkdtempSync(join(tmpdir(), 'manifests-'))
  writeFileSync(join(d, 'routes-web.yaml'),
    'app: web\nroutes:\n  - path: /a\n    component: A\n    module_gate: null\n    permission: null\n')
  writeFileSync(join(d, 'routes-pos.yaml'), [
    'app: pos', 'routes:',
    '  - path: /', '    component: HomePage', '    module_gate: null', '    permission: null',
    'phase_screens:', '  - screen: pin-entry', '    route: null', '',
  ].join('\n'))
  return d
}

test('newSweep writes the sweep file + a sweep task, commits and pushes', () => {
  const { A } = fixture()
  const manifestsDir = manifestsFixture()
  const r = newSweep(A, 'design-token audit', {
    apps: ['web', 'pos'], date: '2026-07-05', manifestsDir, manifestCommit: 'deadbeef',
  })
  assert.equal(r.ok, true)
  assert.equal(r.id, 'T-0002')
  // sweep file
  const sweepFile = join(A, 'sweeps', '2026-07-05-design-token-audit.yaml')
  assert.ok(existsSync(sweepFile))
  const yaml = yamlLoad(readFileSync(sweepFile, 'utf8'))
  assert.equal(yaml.description, 'design-token audit')
  assert.equal(yaml.created, '2026-07-05')
  assert.equal(yaml.manifest_commit, 'deadbeef')
  assert.equal(yaml.routes.length, 3) // 1 web route + 1 pos route + 1 phase screen
  assert.ok(yaml.routes.every((row) => row.status === 'pending'))
  // task file
  const { tasks } = loadTasks(join(A, 'tasks'))
  const task = tasks.find((t) => t.id === 'T-0002')
  assert.equal(task.type, 'sweep')
  assert.equal(task.status, 'ready')
  assert.equal(task.spec, 'sweeps/2026-07-05-design-token-audit.yaml')
  assert.equal(task.depends_on, undefined) // no deps
  // committed + pushed, clean worktree
  assert.equal(gitf(A, 'status', '--porcelain').trim(), '')
  assert.equal(gitf(A, 'log', 'origin/master..HEAD', '--oneline').trim(), '')
})

test('newSweep rejects an unknown app', () => {
  const { A } = fixture()
  const manifestsDir = manifestsFixture()
  const r = newSweep(A, 'x', { apps: ['web', 'nope'], date: '2026-07-05', manifestsDir })
  assert.equal(r.ok, false)
  assert.match(r.reason, /unknown app/)
})

test('sweepStatus counts pending/done/na rows', () => {
  const d = mkdtempSync(join(tmpdir(), 'sweep-'))
  const f = join(d, 's.yaml')
  writeFileSync(f, [
    'description: x', 'created: 2026-07-05', 'manifest_commit: abc',
    'routes:',
    '  - {path: /a, app: web, status: pending, notes: null}',
    '  - {path: /b, app: web, status: done, notes: ok}',
    '  - {path: /c, app: web, status: n-a, notes: dynamic}',
    '  - {path: /d, app: web, status: done, notes: null}', '',
  ].join('\n'))
  assert.deepEqual(sweepStatus(f), { pending: 1, done: 2, na: 1 })
})
