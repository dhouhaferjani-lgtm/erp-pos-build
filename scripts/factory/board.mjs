#!/usr/bin/env node
// Factory board CLI — the ONLY writer of ../erp.board task YAML.
// Commands: list | new | claim | update | render | reset-stale
// (sweep arrives in a later task)
//
// Concurrency model (plan A1): the board worktree is CLI-only-written and
// every mutation is commit+push immediately, so recovery is always
// "discard local, take origin" — fetch + reset --hard. NO rebase anywhere.
// Claim lock = git push atomicity: a rejected push means you lost the race.
import {
  readFileSync, writeFileSync, readdirSync, existsSync, mkdirSync,
} from 'node:fs'
import { execFileSync } from 'node:child_process'
import { join, dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { parseArgs } from 'node:util'
import { load as yamlLoad, dump as yamlDump } from 'js-yaml'

const REQUIRED = ['id', 'title', 'type', 'track', 'status', 'priority']
const ENUMS = {
  type: ['bug', 'feature', 'chore', 'sweep', 'decision'],
  track: ['vps', 'laptop', 'mobile'],
  status: ['backlog', 'ready', 'claimed', 'in-progress', 'ready-for-review',
           'merged', 'blocked', 'changes-requested'],
  priority: ['P0', 'P1', 'P2', 'P3'],
}
const OPTIONAL = ['claimed_by', 'branch', 'pr', 'spec', 'plan', 'reviewers',
                  'done_criteria', 'depends_on', 'blocked_on_owner', 'log']
const LIST_KEYS = ['reviewers', 'depends_on', 'log']

export function validateTask(t) {
  const errs = []
  if (t === null || typeof t !== 'object' || Array.isArray(t)) {
    return ['task is not a mapping']
  }
  for (const k of REQUIRED) if (t[k] === undefined) errs.push(`missing required: ${k}`)
  for (const [k, allowed] of Object.entries(ENUMS)) {
    if (t[k] !== undefined && !allowed.includes(t[k])) errs.push(`bad ${k}: ${t[k]}`)
  }
  for (const k of Object.keys(t)) {
    if (!REQUIRED.includes(k) && !OPTIONAL.includes(k)) errs.push(`unknown key: ${k}`)
  }
  if (t.id !== undefined && !/^T-\d{4}$/.test(t.id)) errs.push(`bad id format: ${t.id}`)
  if (t.title !== undefined && (typeof t.title !== 'string' || t.title.trim() === '')) {
    errs.push('title must be a non-empty string')
  }
  for (const k of LIST_KEYS) {
    if (t[k] !== undefined && !Array.isArray(t[k])) errs.push(`${k} must be a list`)
  }
  return errs
}

export function nextId(tasksDir) {
  const max = readdirSync(tasksDir).filter((f) => /^T-\d{4}.*\.ya?ml$/.test(f))
    .map((f) => Number(f.slice(2, 6))).reduce((a, b) => Math.max(a, b), 0)
  return `T-${String(max + 1).padStart(4, '0')}`
}

/**
 * Load every tasks/*.yaml. Never throws on a per-file problem (A2):
 * returns { tasks, invalid } where invalid = [{ file, errors }].
 */
export function loadTasks(tasksDir) {
  const tasks = []
  const invalid = []
  for (const f of readdirSync(tasksDir).filter((x) => /\.ya?ml$/.test(x)).sort()) {
    let t
    try {
      t = yamlLoad(readFileSync(join(tasksDir, f), 'utf8'))
    } catch (e) {
      invalid.push({ file: f, errors: [`yaml parse error: ${e.message.split('\n')[0]}`] })
      continue
    }
    const errs = validateTask(t)
    if (errs.length) {
      invalid.push({ file: f, errors: errs })
      continue
    }
    tasks.push({ ...t, _file: join(tasksDir, f) })
  }
  return { tasks, invalid }
}

export function boardDir() {
  if (process.env.FACTORY_BOARD_DIR) return resolve(process.env.FACTORY_BOARD_DIR)
  const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
  return resolve(repoRoot, '..', 'erp.board')
}

const git = (dir, ...args) =>
  execFileSync('git', ['-C', dir, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })

const boardBranch = () => process.env.FACTORY_BOARD_BRANCH ?? 'factory/board'
const currentBranch = (dir) => git(dir, 'rev-parse', '--abbrev-ref', 'HEAD').trim()
const now = () => new Date().toISOString()
const skipSync = () =>
  process.env.FACTORY_SKIP_SYNC === '1' && process.env.NODE_ENV === 'test'

/**
 * Bring the board worktree exactly to origin's tip (A1): assert we are on
 * the board branch and clean, then fetch + reset --hard. Never rebases.
 */
export function syncBoard(dir) {
  const branch = boardBranch()
  if (currentBranch(dir) !== branch) {
    throw new Error(`board worktree not on ${branch}`)
  }
  if (git(dir, 'status', '--porcelain').trim() !== '') {
    throw new Error('board worktree dirty — CLI is the only writer; resolve manually')
  }
  git(dir, 'fetch', 'origin', branch)
  git(dir, 'reset', '--hard', `origin/${branch}`)
}

function writeTaskFile(file, task) {
  writeFileSync(file, yamlDump(task, { lineWidth: 100 }))
}

function mutateAndPush(dir, id, mutate, retriesLeft) {
  const branch = boardBranch()
  if (!skipSync()) syncBoard(dir)
  const { tasks, invalid } = loadTasks(join(dir, 'tasks'))
  const t = tasks.find((x) => x.id === id)
  if (!t) {
    const poisoned = invalid.find((i) => i.file.startsWith(`${id}-`) || i.file === `${id}.yaml`)
    if (poisoned) return { ok: false, reason: `invalid-task-file: ${poisoned.errors.join('; ')}` }
    return { ok: false, reason: 'not-found' }
  }
  const guard = mutate(t, tasks) // returns error string or null; mutates t
  if (guard) return { ok: false, reason: guard }
  const { _file, ...clean } = t
  const errs = validateTask(clean)
  if (errs.length) return { ok: false, reason: errs.join('; ') }
  writeTaskFile(_file, clean)
  git(dir, 'add', _file)
  git(dir, 'commit', '-m', `board: ${id} ${clean.status}`)
  try {
    git(dir, 'push', 'origin', branch)
  } catch {
    // Discard OUR commit and take origin's truth; worktree stays clean.
    // fetch first so the retry (which may skip syncBoard) reads fresh state.
    git(dir, 'fetch', 'origin', branch)
    git(dir, 'reset', '--hard', `origin/${branch}`)
    if (retriesLeft > 0) return mutateAndPush(dir, id, mutate, retriesLeft - 1)
    return { ok: false, reason: 'lost-race' }
  }
  return { ok: true }
}

/** Error string when any depends_on id is missing from the board (A9). */
function unknownDep(dependsOn, tasks) {
  for (const dep of dependsOn ?? []) {
    if (!tasks.some((x) => x.id === dep)) return `unknown-dep:${dep}`
  }
  return null
}

export function claimTask(dir, id, { host, session, branch }) {
  return mutateAndPush(dir, id, (t, all) => {
    const missing = unknownDep(t.depends_on, all)
    if (missing) return missing
    if (t.status !== 'ready') return 'not-claimable'
    for (const dep of t.depends_on ?? []) {
      if (all.find((x) => x.id === dep)?.status !== 'merged') return 'not-claimable'
    }
    t.status = 'claimed'
    t.claimed_by = { host, session, at: now() }
    t.branch = branch
    t.log = [...(t.log ?? []), { at: now(), host, note: 'claimed' }]
    return null
  }, 1)
}

export function updateTask(dir, id, { status, note, pr, host = 'unknown' }) {
  return mutateAndPush(dir, id, (t) => {
    if (status) t.status = status
    if (pr) t.pr = pr
    t.log = [...(t.log ?? []), { at: now(), host, note: note ?? `status→${status}` }]
    return null
  }, 2)
}

function slugify(title) {
  return title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40)
}

function loadTemplate() {
  const templatePath = join(dirname(fileURLToPath(import.meta.url)), 'task-template.yaml')
  return yamlLoad(readFileSync(templatePath, 'utf8'))
}

/**
 * Create a new task from the template + fields, assign the next id,
 * validate, commit, push. Retries once on a lost push race (re-reads the
 * board, so the id is re-assigned past any competing new task).
 */
export function newTask(dir, fields, retriesLeft = 1) {
  const branch = boardBranch()
  if (!skipSync()) syncBoard(dir)
  const tasksDir = join(dir, 'tasks')
  if (!existsSync(tasksDir)) mkdirSync(tasksDir, { recursive: true })
  const { tasks } = loadTasks(tasksDir)
  const missing = unknownDep(fields.depends_on, tasks)
  if (missing) return { ok: false, reason: missing }
  const task = { ...loadTemplate(), ...fields, id: nextId(tasksDir) }
  for (const k of Object.keys(task)) if (task[k] === undefined) delete task[k]
  const errs = validateTask(task)
  if (errs.length) return { ok: false, reason: errs.join('; ') }
  const file = join(tasksDir, `${task.id}-${slugify(task.title)}.yaml`)
  writeTaskFile(file, task)
  git(dir, 'add', file)
  git(dir, 'commit', '-m', `board: new ${task.id} ${task.title}`)
  try {
    git(dir, 'push', 'origin', branch)
  } catch {
    git(dir, 'fetch', 'origin', branch)
    git(dir, 'reset', '--hard', `origin/${branch}`)
    if (retriesLeft > 0) return newTask(dir, fields, retriesLeft - 1)
    return { ok: false, reason: 'lost-race' }
  }
  return { ok: true, id: task.id, file }
}

function latestActivity(t) {
  const stamps = [...(t.log ?? []).map((e) => e.at), t.claimed_by?.at]
    .filter((x) => x !== undefined)
    .map((x) => new Date(x).getTime())
    .filter((x) => !Number.isNaN(x))
  return stamps.length ? Math.max(...stamps) : 0
}

/**
 * A7: any task in claimed/in-progress whose newest activity is older than
 * `hours` goes back to ready (claimed_by/branch cleared, log appended).
 * One commit for all resets; normal push protocol (retry once on race).
 */
export function resetStale(dir, { hours = 12, host = 'unknown' } = {}, retriesLeft = 1) {
  const branch = boardBranch()
  if (!skipSync()) syncBoard(dir)
  const { tasks } = loadTasks(join(dir, 'tasks'))
  const cutoff = Date.now() - hours * 3600 * 1000
  const reset = []
  for (const t of tasks) {
    if (!['claimed', 'in-progress'].includes(t.status)) continue
    if (latestActivity(t) >= cutoff) continue
    const was = t.claimed_by ? `${t.claimed_by.host}/${t.claimed_by.session}` : 'unknown/unknown'
    const { _file, ...clean } = t
    clean.status = 'ready'
    delete clean.claimed_by
    delete clean.branch
    clean.log = [...(clean.log ?? []), { at: now(), host, note: `reset-stale (was: ${was})` }]
    const errs = validateTask(clean)
    if (errs.length) return { ok: false, reason: `${t.id}: ${errs.join('; ')}` }
    writeTaskFile(_file, clean)
    git(dir, 'add', _file)
    reset.push(t.id)
  }
  if (reset.length === 0) return { ok: true, reset }
  git(dir, 'commit', '-m', `board: reset-stale ${reset.join(' ')}`)
  try {
    git(dir, 'push', 'origin', branch)
  } catch {
    git(dir, 'fetch', 'origin', branch)
    git(dir, 'reset', '--hard', `origin/${branch}`)
    if (retriesLeft > 0) return resetStale(dir, { hours, host }, retriesLeft - 1)
    return { ok: false, reason: 'lost-race' }
  }
  return { ok: true, reset }
}

// ------------------------------------------------------------------- render

const STATUS_ORDER = ['in-progress', 'claimed', 'ready-for-review', 'changes-requested',
                      'ready', 'blocked', 'backlog', 'merged']

export function renderBoard(dir) {
  const { tasks, invalid } = loadTasks(join(dir, 'tasks'))
  const lines = ['# Factory Board', '', `> Generated ${now()} by scripts/factory/board.mjs render — do not edit by hand.`, '']
  lines.push(`Tasks: ${tasks.length}` + (invalid.length ? ` — WARNING: ${invalid.length} invalid file(s) skipped` : ''), '')
  if (invalid.length) {
    lines.push('## Invalid files', '')
    for (const i of invalid) lines.push(`- \`${i.file}\`: ${i.errors.join('; ')}`)
    lines.push('')
  }
  for (const status of STATUS_ORDER) {
    const group = tasks.filter((t) => t.status === status)
      .sort((a, b) => a.priority.localeCompare(b.priority) || a.id.localeCompare(b.id))
    if (group.length === 0) continue
    lines.push(`## ${status} (${group.length})`, '')
    lines.push('| ID | Pri | Track | Type | Title | Claimed by | Branch / PR | Deps |')
    lines.push('|---|---|---|---|---|---|---|---|')
    for (const t of group) {
      const claimed = t.claimed_by ? `${t.claimed_by.host}/${t.claimed_by.session}` : ''
      const ref = t.pr ?? t.branch ?? ''
      const deps = (t.depends_on ?? []).map((d) =>
        tasks.some((x) => x.id === d) ? d : `${d} (UNKNOWN)`).join(', ')
      lines.push(`| ${t.id} | ${t.priority} | ${t.track} | ${t.type} | ${t.title} | ${claimed} | ${ref} | ${deps} |`)
    }
    lines.push('')
  }
  const out = join(dir, 'BOARD.md')
  writeFileSync(out, lines.join('\n'))
  return out
}

// ---------------------------------------------------------------------- CLI

function warnInvalid(invalid) {
  if (invalid.length === 0) return
  console.warn(`⚠ skipped ${invalid.length} invalid file(s):`)
  for (const i of invalid) console.warn(`  - ${i.file}: ${i.errors.join('; ')}`)
}

function cmdList(args) {
  const { values } = parseArgs({
    args,
    options: { track: { type: 'string' }, status: { type: 'string' } },
  })
  const { tasks, invalid } = loadTasks(join(boardDir(), 'tasks'))
  warnInvalid(invalid)
  const allIds = new Set(tasks.map((t) => t.id))
  const rows = tasks
    .filter((t) => (!values.track || t.track === values.track)
                && (!values.status || t.status === values.status))
    .sort((a, b) => a.priority.localeCompare(b.priority) || a.id.localeCompare(b.id))
  if (rows.length === 0) {
    console.log('(no matching tasks)')
    return
  }
  for (const t of rows) {
    const claimed = t.claimed_by ? ` [${t.claimed_by.host}/${t.claimed_by.session}]` : ''
    const badDeps = (t.depends_on ?? []).filter((d) => !allIds.has(d))
    const depFlag = badDeps.length ? ` !unknown-dep:${badDeps.join(',')}` : ''
    console.log(`${t.id}  ${t.priority}  ${t.track.padEnd(6)}  ${t.type.padEnd(8)}  ${t.status.padEnd(17)}  ${t.title}${claimed}${depFlag}`)
  }
}

function listFlag(v) {
  return v === undefined ? undefined : v.split(',').map((s) => s.trim()).filter(Boolean)
}

function cmdNew(args) {
  const { values } = parseArgs({
    args,
    options: {
      title: { type: 'string' }, type: { type: 'string' }, track: { type: 'string' },
      priority: { type: 'string' }, status: { type: 'string' }, spec: { type: 'string' },
      plan: { type: 'string' }, reviewers: { type: 'string' }, 'depends-on': { type: 'string' },
      'blocked-on-owner': { type: 'string' }, 'done-criteria': { type: 'string' },
      note: { type: 'string' }, host: { type: 'string' },
    },
  })
  for (const req of ['title', 'type', 'track', 'priority']) {
    if (!values[req]) {
      console.error(`new: --${req} is required`)
      process.exit(1)
    }
  }
  const fields = {
    title: values.title, type: values.type, track: values.track, priority: values.priority,
    status: values.status, spec: values.spec, plan: values.plan,
    reviewers: listFlag(values.reviewers), depends_on: listFlag(values['depends-on']),
    blocked_on_owner: values['blocked-on-owner'], done_criteria: values['done-criteria'],
  }
  if (values.note) {
    fields.log = [{ at: now(), host: values.host ?? 'cli', note: values.note }]
  }
  for (const k of Object.keys(fields)) if (fields[k] === undefined) delete fields[k]
  const r = newTask(boardDir(), fields)
  if (!r.ok) {
    console.error(`new: ${r.reason}`)
    process.exit(1)
  }
  console.log(`${r.id} created: ${r.file}`)
}

function cmdClaim(args) {
  const { values, positionals } = parseArgs({
    args,
    allowPositionals: true,
    options: {
      host: { type: 'string' }, session: { type: 'string' }, branch: { type: 'string' },
    },
  })
  const id = positionals[0]
  if (!id || !values.host || !values.session || !values.branch) {
    console.error('usage: board.mjs claim T-0001 --host <h> --session <s> --branch <b>')
    process.exit(1)
  }
  const r = claimTask(boardDir(), id, {
    host: values.host, session: values.session, branch: values.branch,
  })
  if (!r.ok) {
    console.error(`claim ${id}: ${r.reason}`)
    process.exit(1)
  }
  console.log(`claimed ${id}`)
}

function cmdUpdate(args) {
  const { values, positionals } = parseArgs({
    args,
    allowPositionals: true,
    options: {
      status: { type: 'string' }, note: { type: 'string' },
      pr: { type: 'string' }, host: { type: 'string' },
    },
  })
  const id = positionals[0]
  if (!id || (!values.status && !values.note && !values.pr)) {
    console.error('usage: board.mjs update T-0001 [--status s] [--note n] [--pr url] [--host h]')
    process.exit(1)
  }
  const r = updateTask(boardDir(), id, {
    status: values.status, note: values.note, pr: values.pr, host: values.host ?? 'cli',
  })
  if (!r.ok) {
    console.error(`update ${id}: ${r.reason}`)
    process.exit(1)
  }
  console.log(`updated ${id}`)
}

function cmdRender() {
  const out = renderBoard(boardDir())
  console.log(`wrote ${out}`)
}

function cmdResetStale(args) {
  const { values } = parseArgs({
    args,
    options: { hours: { type: 'string' }, host: { type: 'string' } },
  })
  const hours = values.hours ? Number(values.hours) : 12
  if (Number.isNaN(hours) || hours <= 0) {
    console.error('reset-stale: --hours must be a positive number')
    process.exit(1)
  }
  const r = resetStale(boardDir(), { hours, host: values.host ?? 'cli' })
  if (!r.ok) {
    console.error(`reset-stale: ${r.reason}`)
    process.exit(1)
  }
  console.log(r.reset.length ? `reset: ${r.reset.join(' ')}` : 'nothing stale')
}

const isMain = process.argv[1] !== undefined
  && resolve(process.argv[1]) === fileURLToPath(import.meta.url)

if (isMain) {
  const [cmd, ...rest] = process.argv.slice(2)
  const commands = {
    list: cmdList, new: cmdNew, claim: cmdClaim,
    update: cmdUpdate, render: cmdRender, 'reset-stale': cmdResetStale,
  }
  const fn = commands[cmd]
  if (!fn) {
    console.error('usage: board.mjs <list|new|claim|update|render|reset-stale> [options]')
    process.exit(1)
  }
  fn(rest)
}
