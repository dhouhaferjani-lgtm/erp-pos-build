# Dark-Factory Phases 1–2 (+B2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the `factory/board` YAML task board + CLI, execute the repo-cleanup pass, build the page-coverage system (route manifest + drift check + sweep generator + screenshot sweep), and ship the B2 auth-401 fix as the first PR through the new flow.

**Architecture:** Board = orphan git branch `factory/board` checked out at `../erp.board`; a dependency-light Node CLI (`scripts/factory/board.mjs`, lives on `dev`) operates on it; git push atomicity is the claim lock. Route manifests are **generated into the code repo** (`scripts/factory/manifests/`) so the preflight drift check fails any PR that adds a route without regenerating — this deviates from the spec's board-branch placement, deliberately, for commit atomicity (spec updated in Task 12).

**Tech Stack:** Node ≥20 (v25 local), `js-yaml` (root devDep), `node:test` for CLI tests, TypeScript compiler API (from `apps/web` node_modules) for route parsing, `playwright-core` for the screenshot sweep, Vitest for the B2 tests.

## Global Constraints

- Laptop host: PHPUnit BY PATH only; never full suite (CLAUDE.md / WORKFLOW.md).
- Never `git add -A` in the main worktree; stage explicit paths (rule from HANDOVER).
- Commit and push are SEPARATE Bash invocations (dev-push-guard).
- No `// TODO` / placeholder code (rule 1). Strict TS, no `any` (rule 3). i18n `t()` for user-facing strings (rule 11) — applies to B2 only if it adds UI text (it doesn't).
- Never push `origin/dev` from this plan; feature branches + PRs only. Local `dev` commits are allowed for factory scripts/docs (they'll be promoted by the orchestrator in a batch).
- Do NOT touch `apps/erp.procurement-v2`, `apps/erp.p2p-flow`, or procurement/P2P files.
- Board branch: never force-push; one file per task; CLI is the only writer.

## File Structure (end state)

```
# on dev (code repo)
scripts/factory/board.mjs                 # board CLI (list|new|claim|update|render|sweep)
scripts/factory/board.test.mjs            # node:test suite (temp git fixtures)
scripts/factory/task-template.yaml        # schema-complete template
scripts/factory/gen-route-manifest.mjs    # routes/index.tsx + pos App.tsx → manifests
scripts/factory/manifests/routes-web.yaml # committed, drift-checked
scripts/factory/manifests/routes-pos.yaml
scripts/factory/check-manifest-drift.sh   # regen→diff, wired into preflight.sh
scripts/factory/screenshot-sweep.mjs      # playwright-core route walker
scripts/factory/vps-boot-prompt.md        # Phase 3 artifact (authored now, used later)
docs/factory/LAUNCH-PLAN-2026-07.md       # track-split living doc
.gitignore                                # + screenshots / tenant storage / codex scratch
# on factory/board (orphan branch, worktree ../erp.board)
README.md  tasks/T-*.yaml  sweeps/  BOARD.md
```

---

### Task 1: Bootstrap `factory/board` orphan branch + worktree

**Files:**
- Create (on new branch): `README.md`, `tasks/.gitkeep`, `sweeps/.gitkeep`
- Worktree: `../erp.board`

**Interfaces:**
- Produces: worktree path `/Users/houssamr/Projects/syneriva/apps/erp.board` (all later tasks); branch `factory/board` on origin.

- [ ] **Step 1: Create the orphan branch in a detached temp worktree**

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
git worktree add --detach ../erp.board
cd ../erp.board
git checkout --orphan factory/board
git rm -rf --cached . 2>/dev/null; rm -rf ./* 2>/dev/null; rm -f .gitignore
mkdir -p tasks sweeps
touch tasks/.gitkeep sweeps/.gitkeep
cat > README.md <<'EOF'
# Factory Board (orphan branch — NO CODE EVER)
Task board for the dark factory. One YAML file per task under tasks/.
Write ONLY via scripts/factory/board.mjs (on dev). Claim lock = git push
atomicity: rejected push ⇒ you lost the race ⇒ `git pull --rebase`, pick next.
Spec: docs/superpowers/specs/2026-07-05-dark-factory-coordination-design.md (on dev).
EOF
```

- [ ] **Step 2: Commit and push (separate commands)**

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp.board && git add -A && git commit -m "factory: bootstrap board orphan branch"
```
```bash
cd /Users/houssamr/Projects/syneriva/apps/erp.board && git push -u origin factory/board
```
Expected: `* [new branch] factory/board -> factory/board`. (`git add -A` is safe HERE — fresh orphan worktree, not the main worktree.)

- [ ] **Step 3: Verify** `git log --oneline` shows exactly 1 root commit; `git ls-tree -r HEAD --name-only` = README.md, sweeps/.gitkeep, tasks/.gitkeep.

### Task 2: Board CLI — schema validation, `new`, `list` (TDD)

**Files:**
- Create: `scripts/factory/board.mjs`, `scripts/factory/board.test.mjs`, `scripts/factory/task-template.yaml`
- Modify: root `package.json` (add `js-yaml` devDep)

**Interfaces:**
- Produces: `node scripts/factory/board.mjs <cmd>`; exported functions `validateTask(obj) -> string[]` (error list), `nextId(tasksDir) -> "T-0007"`, `loadTasks(tasksDir) -> Task[]`. Board dir from `FACTORY_BOARD_DIR` env, default `<repoRoot>/../erp.board`.
- Schema (authoritative, from spec §1): required `id,title,type,track,status,priority`; enums `type∈{bug,feature,chore,sweep,decision}`, `track∈{vps,laptop,mobile}`, `status∈{backlog,ready,claimed,in-progress,ready-for-review,merged,blocked,changes-requested}`, `priority∈{P0,P1,P2,P3}`; optional `claimed_by,branch,pr,spec,plan,reviewers,done_criteria,depends_on,blocked_on_owner,log`; **unknown keys rejected**.

- [ ] **Step 1:** `pnpm add -Dw js-yaml` (root workspace). Verify `node -e "import('js-yaml').then(m=>console.log('ok'))"` prints ok.
- [ ] **Step 2: Write failing tests** (`scripts/factory/board.test.mjs`, `node:test`):

```js
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { mkdtempSync, writeFileSync, mkdirSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { validateTask, nextId, loadTasks } from './board.mjs'

const VALID = {
  id: 'T-0001', title: 'x', type: 'bug', track: 'vps',
  status: 'ready', priority: 'P1',
}

test('validateTask accepts a minimal valid task', () => {
  assert.deepEqual(validateTask(VALID), [])
})
test('validateTask rejects missing required, bad enum, unknown key', () => {
  assert.ok(validateTask({ ...VALID, id: undefined }).length > 0)
  assert.ok(validateTask({ ...VALID, status: 'done' }).length > 0)
  assert.ok(validateTask({ ...VALID, surprise: 1 }).length > 0)
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
  const tasks = loadTasks(join(d, 'tasks'))
  assert.equal(tasks.length, 1)
  assert.equal(tasks[0].id, 'T-0001')
})
```

- [ ] **Step 3:** Run `node --test scripts/factory/board.test.mjs` — expect FAIL (module not found).
- [ ] **Step 4: Implement** `scripts/factory/board.mjs` (core):

```js
#!/usr/bin/env node
// Factory board CLI — the ONLY writer of ../erp.board task YAML.
// Commands: list | new | claim | update | render | sweep
import { readFileSync, writeFileSync, readdirSync, existsSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { join, dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import yaml from 'js-yaml'

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

export function validateTask(t) {
  const errs = []
  if (t === null || typeof t !== 'object') return ['task is not a mapping']
  for (const k of REQUIRED) if (t[k] === undefined) errs.push(`missing required: ${k}`)
  for (const [k, allowed] of Object.entries(ENUMS))
    if (t[k] !== undefined && !allowed.includes(t[k])) errs.push(`bad ${k}: ${t[k]}`)
  for (const k of Object.keys(t))
    if (!REQUIRED.includes(k) && !OPTIONAL.includes(k)) errs.push(`unknown key: ${k}`)
  if (t.id !== undefined && !/^T-\d{4}$/.test(t.id)) errs.push(`bad id format: ${t.id}`)
  return errs
}

export function nextId(tasksDir) {
  const max = readdirSync(tasksDir).filter((f) => /^T-\d{4}.*\.ya?ml$/.test(f))
    .map((f) => Number(f.slice(2, 6))).reduce((a, b) => Math.max(a, b), 0)
  return `T-${String(max + 1).padStart(4, '0')}`
}

export function loadTasks(tasksDir) {
  return readdirSync(tasksDir).filter((f) => /\.ya?ml$/.test(f)).sort().map((f) => {
    const t = yaml.load(readFileSync(join(tasksDir, f), 'utf8'))
    const errs = validateTask(t)
    if (errs.length) throw new Error(`${f}: ${errs.join('; ')}`)
    return { ...t, _file: join(tasksDir, f) }
  })
}

export function boardDir() {
  if (process.env.FACTORY_BOARD_DIR) return resolve(process.env.FACTORY_BOARD_DIR)
  const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
  return resolve(repoRoot, '..', 'erp.board')
}
const git = (dir, ...args) =>
  execFileSync('git', ['-C', dir, ...args], { encoding: 'utf8' })
```

(CLI command dispatch — appended in the same file; `list` prints a table filtered by `--track`/`--status`; `new` copies `task-template.yaml`, assigns `nextId`, writes `tasks/<id>-<slug>.yaml`, validates, commits+pushes. Full dispatch code in Task 3 where claim/update need it — implement `list`+`new` now.)

- [ ] **Step 5:** Run tests — expect PASS. Also create `scripts/factory/task-template.yaml` mirroring the schema (all required keys with placeholder values + commented optional keys).
- [ ] **Step 6: Commit** (local dev): `git add scripts/factory/board.mjs scripts/factory/board.test.mjs scripts/factory/task-template.yaml package.json pnpm-lock.yaml && git commit -m "feat(factory): board CLI — schema, new, list"`

### Task 3: Board CLI — `claim` / `update` with push-atomicity lock (TDD)

**Files:**
- Modify: `scripts/factory/board.mjs`, `scripts/factory/board.test.mjs`

**Interfaces:**
- Produces: `claimTask(dir, id, {host, session, branch}) -> {ok, reason?}`; `updateTask(dir, id, {status?, note?, pr?}) -> {ok}`. Both: mutate YAML → validate → `git add <file>` → commit → push; on push rejection: `git pull --rebase` and return `{ok:false, reason:'lost-race'}` for claim (caller picks next) / retry once for update (notes don't conflict thanks to one-file-per-task; a true conflict aborts with `{ok:false}`).
- Claim guard: task must be `status: ready` and every `depends_on` id must be `merged`, else `{ok:false, reason:'not-claimable'}`.

- [ ] **Step 1: Failing tests** — fixture helper builds a bare repo + two clones (A, B) with a seeded ready task:

```js
function fixture() {
  const root = mkdtempSync(join(tmpdir(), 'boardgit-'))
  const bare = join(root, 'origin.git'); const A = join(root, 'A'); const B = join(root, 'B')
  execFileSync('git', ['init', '--bare', bare])
  for (const c of [A, B]) {
    execFileSync('git', ['clone', bare, c])
    execFileSync('git', ['-C', c, 'config', 'user.email', 't@t']); execFileSync('git', ['-C', c, 'config', 'user.name', 't'])
  }
  mkdirSync(join(A, 'tasks'))
  writeFileSync(join(A, 'tasks', 'T-0001-x.yaml'),
    'id: T-0001\ntitle: x\ntype: chore\ntrack: vps\nstatus: ready\npriority: P1\n')
  execFileSync('git', ['-C', A, 'add', '.']); execFileSync('git', ['-C', A, 'commit', '-m', 'seed'])
  execFileSync('git', ['-C', A, 'push', 'origin', 'HEAD:master'])
  execFileSync('git', ['-C', B, 'pull', 'origin', 'master'])
  return { A, B }
}
test('claim succeeds and sets claimed_by/status/branch', () => {
  const { A } = fixture()
  const r = claimTask(A, 'T-0001', { host: 'vps', session: 's1', branch: 'feat/x' })
  assert.equal(r.ok, true)
  const t = loadTasks(join(A, 'tasks'))[0]
  assert.equal(t.status, 'claimed'); assert.equal(t.claimed_by.host, 'vps')
})
test('second claim of same task from another clone loses the race', () => {
  const { A, B } = fixture()
  assert.equal(claimTask(A, 'T-0001', { host: 'vps', session: 's1', branch: 'b1' }).ok, true)
  const r = claimTask(B, 'T-0001', { host: 'laptop', session: 's2', branch: 'b2' })
  assert.equal(r.ok, false)  // push rejected → rebase → task no longer ready
})
test('claim refuses non-ready and unmet depends_on', () => { /* seed status: claimed and a depends_on: [T-0099]; expect {ok:false, reason:'not-claimable'} for both */ })
test('updateTask appends log entry and flips status', () => { /* claim, then updateTask(..., {status:'in-progress', note:'started'}); expect log.length 1+, status in-progress */ })
```
(The two commented tests must be written out in full — same fixture pattern, explicit asserts.)

- [ ] **Step 2:** Run — FAIL (functions not exported).
- [ ] **Step 3: Implement** `claimTask`/`updateTask` in board.mjs:

```js
function mutateAndPush(dir, id, mutate, retryOnRace) {
  git(dir, 'pull', '--rebase', 'origin', currentBranch(dir))
  const tasks = loadTasks(join(dir, 'tasks'))
  const t = tasks.find((x) => x.id === id)
  if (!t) return { ok: false, reason: 'not-found' }
  const guard = mutate(t, tasks)          // returns error string or null; mutates t
  if (guard) return { ok: false, reason: guard }
  const { _file, ...clean } = t
  const errs = validateTask(clean)
  if (errs.length) return { ok: false, reason: errs.join('; ') }
  writeFileSync(_file, yaml.dump(clean, { lineWidth: 100 }))
  git(dir, 'add', _file)
  git(dir, 'commit', '-m', `board: ${id} ${clean.status}`)
  try { git(dir, 'push', 'origin', currentBranch(dir)) } catch {
    git(dir, 'pull', '--rebase', 'origin', currentBranch(dir))
    return retryOnRace ? mutateAndPush(dir, id, mutate, false) : { ok: false, reason: 'lost-race' }
  }
  return { ok: true }
}
const currentBranch = (dir) => git(dir, 'rev-parse', '--abbrev-ref', 'HEAD').trim()
const now = () => new Date().toISOString()

export function claimTask(dir, id, { host, session, branch }) {
  return mutateAndPush(dir, id, (t, all) => {
    if (t.status !== 'ready') return 'not-claimable'
    for (const dep of t.depends_on ?? [])
      if (all.find((x) => x.id === dep)?.status !== 'merged') return 'not-claimable'
    t.status = 'claimed'
    t.claimed_by = { host, session, at: now() }
    t.branch = branch
    t.log = [...(t.log ?? []), { at: now(), host, note: 'claimed' }]
    return null
  }, false)
}
export function updateTask(dir, id, { status, note, pr, host = 'unknown' }) {
  return mutateAndPush(dir, id, (t) => {
    if (status) t.status = status
    if (pr) t.pr = pr
    t.log = [...(t.log ?? []), { at: now(), host, note: note ?? `status→${status}` }]
    return null
  }, true)
}
```
Important subtlety the implementer must preserve: after a lost-race rebase, `mutateAndPush` re-reads the task file (the loop in claim: the re-read shows `status: claimed` → guard returns `not-claimable`); never reuse the pre-rebase in-memory object.

- [ ] **Step 4:** `node --test scripts/factory/board.test.mjs` — PASS (all).
- [ ] **Step 5:** Wire the CLI dispatch (argv parsing: `board.mjs claim T-0001 --host laptop --session $USER --branch fix/x`, `board.mjs update T-0001 --status in-progress --note "..."`, `board.mjs list --track vps --status ready`, `board.mjs new --title ... --type ... --track ... --priority ...`) + `render` (BOARD.md table grouped by status). Manual check: run `list` against `../erp.board`.
- [ ] **Step 6: Commit** explicit paths, message `feat(factory): board CLI — claim/update with push-race lock + render`.

### Task 4: Seed the board with initial tasks

**Files:**
- Create (board worktree): ~12 `tasks/T-00NN-*.yaml` via `board.mjs new`

**Interfaces:**
- Consumes: `board.mjs new`. Produces: a claimable backlog for both hosts.

- [ ] **Step 1:** Seed (each via `board.mjs new`, then edit the generated file to add details, then `board.mjs` validates on `render`): B1 POS add-customer bug (`laptop`, P0, reviewers fiscal-pos); loyalty completion session (`laptop`, P0, spec=PROMPT-loyalty-completion-roadmap.md); dashboard-demo rebase onto dev + PR (`vps`, P1); `feat/db-per-tenant-deploy` rebase-and-PR (`vps`, P1); double-earn loyalty race fix (`vps`, P2, reviewers fiscal-pos); remote branch bulk-prune approval (`decision`, `laptop`, P2, blocked_on_owner with the ledger list); platform-integration-bundle salvage (`decision`, P3); focus-traps PR verification (`decision`, P3); khalil-pos ask (`decision`, P3); screenshot-sweep first full run (`vps`, P2, depends_on manifest task); PG-suite `ReturnNoteService` diff inspection (`vps`, P3); Playwright/Codex VPS install verification (`vps`, P1).
- [ ] **Step 2:** `board.mjs render`; commit+push board (separate commands). Verify `board.mjs list --status ready` shows the set.

### Task 5: `.gitignore` + root junk cleanup (main worktree)

**Files:**
- Modify: `.gitignore`, `pnpm-workspace.yaml`
- Move: `*.png` at repo root → `docs/sessions/screenshots/`

- [ ] **Step 1:** Append to `.gitignore`:

```gitignore
# session artifacts (dark-factory cleanup 2026-07-05)
/*.png
docs/sessions/
apps/api/storage/tenant*/
.codex-*.md
/anchor
apps/api/phpunit-pgsql-triage.xml
```

- [ ] **Step 2:** `mkdir -p docs/sessions/screenshots && mv ./*.png docs/sessions/screenshots/` (root-level only — do NOT touch pngs inside apps/). `rm -f anchor` (scratch file, verified content first with `head`).
- [ ] **Step 3:** `git diff pnpm-workspace.yaml` — if the only change is the `allowBuilds`/`better-sqlite3` placeholder stub ("set this to true or false"), restore: `git checkout -- pnpm-workspace.yaml`. If it contains anything else, STOP and leave a note in the morning report instead.
- [ ] **Step 4:** `git status` root should show no stray pngs; commit `.gitignore` alone: `git add .gitignore && git commit -m "chore: gitignore session artifacts (screenshots, tenant storage, codex scratch)"`.

### Task 6: docs/handoff + superpowers keeper commit & archive

**Files:**
- Create: `docs/handoff/archive/` (git mv of completed handoffs)
- Add: untracked keeper docs in main worktree + salvaged docs from merged worktrees

- [ ] **Step 1:** Enumerate: `git status --porcelain docs/ | grep '^??'`. Keep-criteria: handoffs/audits/specs/plans referenced by MEMORY.md, the 2026-07-05 handover, or covering unmerged work. Everything matching completed work (per the 2026-07-05 unmerged-work ledger) goes to `docs/handoff/archive/` in the SAME commit that first adds it (add → `git mv`).
- [ ] **Step 2:** Salvage from merged worktrees (single `cp -n` per file — no overwrites): `erp.treasury-be`, `erp.treasury-c67`, `erp.treasury-c7`, `erp.treasury-fe`, `erp.pos-stock` (1 review doc), `erp.demo-pharmacy` (1 review doc), `erp.procurement` (80 docs — cp the whole `docs/superpowers/` subtree with `-n`). Dedupe: identical basenames already tracked on dev are skipped by `-n`; verify with `git status`.
- [ ] **Step 3:** Stage explicit paths (`git add docs/handoff docs/superpowers`), commit `docs: commit stranded session docs from merged worktrees + archive completed handoffs`.

### Task 7: Local branch + worktree pruning (NON-destructive scope only)

**Files:** none (git refs)

- [ ] **Step 1:** Remove merged worktrees AFTER Task 6 salvage: `git worktree remove ../erp.pos-stock ../erp.treasury-be ../erp.treasury-c67 ../erp.treasury-c7 ../erp.treasury-fe ../erp.procurement ../erp.unified-imports` (one command each; `--force` only if the only dirt is the salvaged/untracked artifacts listed in the ledger). Also prune stale `.claude/worktrees/*` EXCEPT any currently in use.
- [ ] **Step 2:** Delete local branches verified 0-ahead ancestors (ledger list): `feat/parapharmacy-merchandising feat/procurement-to-pay feat/treasury-c7-income feat/treasury-cash-movements-income feat/unified-imports fix/bulk-import-repairs fix/finance-summary-income fix/role-controller-authz fix/treasury-demo-backend fix/treasury-demo-c5 fix/pos-variant-stock-decrement feat/izipos-theme-product-editor factory/audit-agent-bench factory/bible-refresh factory/margin-hier-rebased factory/workflow-codification` via `git branch -d` (NOT `-D` — `-d` refuses if not merged, our safety net). Keep: `feat/owner-dashboard-demo` (pushed, pending rebase), `feat/accounting-gl-go-live`, `feat/db-per-tenant-deploy`, `feat/demo-pharmacy-account` (until decision), `post-demo`, `feat/p2p-entry-points`, `feat/procurement-wave3` (other session's call), `triage/pg-suite-98-failures` (until ReturnNoteService inspection task done).
- [ ] **Step 3:** REMOTE branches: NOT tonight — that's the seeded `decision` task for the owner. `git worktree list` + `git branch` output goes in the morning report.

### Task 8: Route manifest generator (TDD)

**Files:**
- Create: `scripts/factory/gen-route-manifest.mjs`, `scripts/factory/gen-route-manifest.test.mjs`, `scripts/factory/manifests/routes-web.yaml`, `scripts/factory/manifests/routes-pos.yaml`

**Interfaces:**
- Produces: `extractRoutes(sourceText, filePath) -> Array<{path, component, module_gate, permission}>` (exported for tests); CLI `node scripts/factory/gen-route-manifest.mjs [--check]` writes/verifies both manifests. Consumed by Task 9 (drift check) and Task 10 (sweeps).
- Parsing: use the TypeScript compiler API (`import ts from 'typescript'` resolved from `apps/web/node_modules/typescript`) — walk JSX, collect `<Route path=...>` with full path join of nested routes; capture nearest enclosing `<ModuleGuard module="X">` and `<RequirePermission permission="Y">`. Web source: `apps/web/src/routes/index.tsx`; POS source: `apps/pos/src/App.tsx`.

- [ ] **Step 1: Failing test** with an inline fixture:

```js
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
})
```

- [ ] **Step 2:** Run `node --test scripts/factory/gen-route-manifest.test.mjs` — FAIL.
- [ ] **Step 3: Implement** — `ts.createSourceFile(name, text, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX)`; recursive visitor keeps a stack of `{pathSegment, module, permission}` from JsxElement/JsxSelfClosingElement nodes named Route/ModuleGuard/RequirePermission (string-literal attributes only; dynamic paths recorded with `path` as the raw expression text and `dynamic: true` — schema allows it via a 5th optional field... NO: keep schema fixed; emit the raw text in `path` and note in component `null` if unresolvable). Sort by path. YAML dump with header comment `# GENERATED by gen-route-manifest.mjs — do not edit; run node scripts/factory/gen-route-manifest.mjs`.
- [ ] **Step 4:** Tests PASS; then run the real generation, eyeball `routes-web.yaml` (expect ~150-250 entries from 252 `path=` occurrences; POS ~10-20).
- [ ] **Step 5: Commit** generator + tests + both manifests.

### Task 9: Manifest drift check in preflight

**Files:**
- Create: `scripts/factory/check-manifest-drift.sh`
- Modify: `scripts/preflight.sh` (add one guard invocation next to the existing guard scripts, e.g. after the TanStack key audit)

- [ ] **Step 1:** Script: regenerate to `mktemp -d`, `diff -ru` against `scripts/factory/manifests/`; on diff → exit 1 with message `Route manifest drift — run: node scripts/factory/gen-route-manifest.mjs && commit the manifests`.
- [ ] **Step 2:** Wire into `scripts/preflight.sh` (read it first; add in the unconditional-guards section, matching the style of `audit-tanstack-keys` invocation).
- [ ] **Step 3:** Verify: run the check (clean = exit 0); hand-edit a manifest → exits 1 → revert.
- [ ] **Step 4: Commit.**

### Task 10: Sweep generator (`board.mjs sweep new`)

**Files:**
- Modify: `scripts/factory/board.mjs`, `scripts/factory/board.test.mjs`

**Interfaces:**
- Produces: `board.mjs sweep new "<description>" --apps web,pos [--priority P2]` → creates `sweeps/<yyyy-mm-dd>-<slug>.yaml` on the board AND a `type: sweep` task referencing it. Sweep file: `{description, created, manifest_commit: <dev sha>, routes: [{path, app, status: pending, notes: null}]}` — one row per manifest route. `sweepStatus(file) -> {pending, done, na}` counts.

- [ ] **Step 1: Failing test:** `sweepRows(manifests, apps)` returns one row per route with `status: 'pending'`; `sweep new` writes the file + task and task `depends_on` is empty; date comes from `--date` flag (tests pass a fixed date; CLI defaults to today).
- [ ] **Step 2:** Implement (reads manifests from the CODE repo — `scripts/factory/manifests/` — not the board).
- [ ] **Step 3:** Tests PASS. Manual: `board.mjs sweep new "design-token audit" --apps web --date 2026-07-05` against the real board, inspect, then delete the trial sweep + task (commit the deletion).
- [ ] **Step 4: Commit** (code repo file changes only; board trial reverted).

### Task 11: Screenshot sweep script

**Files:**
- Create: `scripts/factory/screenshot-sweep.mjs`

**Interfaces:**
- CLI: `node scripts/factory/screenshot-sweep.mjs --base http://localhost:5173 --email owner@pharmabio.tn --password password --out docs/sessions/screenshots/sweep-<date> [--routes-filter <regex>] [--app web]`. Uses `playwright-core` (add root devDep if `node -e "import('playwright-core')"` fails) + system Chrome via `channel: 'chrome'` fallback to bundled chromium if installed.
- Behavior: login via the UI once (fill email/password, submit, wait for post-login nav), then for each manifest route: `goto`, wait `networkidle` (10s cap), full-page screenshot `<out>/<sanitized-path>.png`; parameterized segments (`:id`) SKIPPED with a `skipped-dynamic.txt` list; final `index.html` gallery (plain grid of `<figure><img><figcaption>`) written to `<out>/`.

- [ ] **Step 1:** Implement (no test framework — this is an ops script; its "test" is the smoke run). Keep under ~120 lines, no `any`-equivalent sloppiness: JSDoc types.
- [ ] **Step 2: Smoke:** with the local stack up (memory recipe `reference_local_db_per_tenant_demo_launch`), run with `--routes-filter '^/(login|dashboard|products)$'` — expect 2-3 pngs + index.html. If the stack isn't running tonight, START it per the recipe; if that fails, mark the board task for the VPS and note in the morning report (do NOT silently skip).
- [ ] **Step 3: Commit** script.

### Task 12: Spec addendum + VPS boot prompt artifact

**Files:**
- Modify: `docs/superpowers/specs/2026-07-05-dark-factory-coordination-design.md` (§1/§4: manifests live in code repo — atomicity rationale)
- Create: `scripts/factory/vps-boot-prompt.md` (spec §2 contract, written as the literal prompt: boot order, claim command, loop stages, PR body template with task id + gate evidence, blocked protocol, hard rails)

- [ ] **Step 1:** Edit spec (move `manifests/` lines out of board layout; add one-paragraph deviation note dated 2026-07-05).
- [ ] **Step 2:** Author the boot prompt (complete, paste-ready; references `board.mjs` commands from Tasks 2-3).
- [ ] **Step 3: Commit** both.

### Task 13: B2 — auth 401 loop fix (worktree + PR; tenancy-authz-reviewer gate)

**Files:**
- Worktree: `git worktree add ../erp.auth-401 origin/dev -b fix/auth-401-loop` (VERIFY base = `6b356be6d` or newer origin/dev tip)
- Modify: `apps/web/src/lib/api.ts:142-155`
- Test: `apps/web/src/lib/__tests__/api.unauthorized.test.ts` (new)

**Interfaces:**
- Produces: exported `handleUnauthorized(url: string, sentToken: string | null): void` from `api.ts` (pure-ish, testable); interceptor calls it.
- Contract (from live diagnosis + code reading):
  1. 401 on non-`/auth/me`: `logout()` (existing) **and** hard-redirect `window.location.assign('/login')` when `!isPublicPath(window.location.pathname)` — component-level `<Navigate>` cannot be relied on when the failing tree isn't under `RequireAuth`. Redirect fires ONCE (module flag).
  2. 401 on `/auth/me`: if the request's Bearer token === the CURRENT store token, that token is proven stale → `logout()` (clears persisted `autoerp-auth` token). If it differs (registration race the `wasAuthenticated` guard protects — see AuthProvider.tsx:39-42), do nothing. This closes the "stale localStorage token survives hard-refresh" root cause WITHOUT reintroducing the registration wipe.
  3. `isPublicPath`: `/login`, `/register`, `/verify-email`, `/forgot-password`, `/reset-password`, `/privacy`, `/terms`, `/admin/login`.

- [ ] **Step 1: Failing tests** (Vitest, jsdom; follow `apps/web/src/features/auth/__tests__/AuthProvider.test.tsx` setup conventions; mock `window.location.assign` via `vi.spyOn`; drive `useAuthStore` directly — no axios mocking of payloads, we test the exported handler):

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { useAuthStore } from '../../stores/authStore'
import { handleUnauthorized, __resetRedirectGuard } from '../api'

describe('handleUnauthorized', () => {
  beforeEach(() => {
    __resetRedirectGuard()
    useAuthStore.setState({ user: null, token: 'stale-token', isAuthenticated: true, isLoading: false })
    window.history.pushState({}, '', '/dashboard')
  })

  it('non-auth/me 401 logs out and hard-redirects to /login once', () => {
    const assign = vi.spyOn(window.location, 'assign').mockImplementation(() => {})
    handleUnauthorized('/user/companies', 'stale-token')
    handleUnauthorized('/products', 'stale-token')
    expect(useAuthStore.getState().token).toBeNull()
    expect(assign).toHaveBeenCalledTimes(1)
    expect(assign).toHaveBeenCalledWith('/login')
  })

  it('does not redirect when already on a public path', () => {
    window.history.pushState({}, '', '/login')
    const assign = vi.spyOn(window.location, 'assign').mockImplementation(() => {})
    handleUnauthorized('/user/companies', 'stale-token')
    expect(assign).not.toHaveBeenCalled()
  })

  it('auth/me 401 with the CURRENT token clears it (stale-token cold load)', () => {
    handleUnauthorized('/auth/me', 'stale-token')
    expect(useAuthStore.getState().token).toBeNull()
  })

  it('auth/me 401 with a DIFFERENT token is ignored (registration race)', () => {
    useAuthStore.setState({ token: 'fresh-token' })
    handleUnauthorized('/auth/me', 'old-token')
    expect(useAuthStore.getState().token).toBe('fresh-token')
  })
})
```
Note for the implementer: jsdom may make `window.location.assign` non-configurable — if `vi.spyOn` throws, inject the redirect as a module-level `let redirect = (p) => window.location.assign(p)` with an exported `__setRedirectForTests`.

- [ ] **Step 2:** `pnpm --filter web test -- run src/lib/__tests__/api.unauthorized.test.ts` — FAIL (no export).
- [ ] **Step 3: Implement** in `api.ts` (replace lines 142-155's 401 branch body):

```ts
const PUBLIC_PATHS = ['/login', '/register', '/verify-email', '/forgot-password',
  '/reset-password', '/privacy', '/terms', '/admin/login']
let redirectedToLogin = false
export function __resetRedirectGuard(): void { redirectedToLogin = false }

export function handleUnauthorized(url: string, sentToken: string | null): void {
  const store = useAuthStore.getState()
  if (url.includes('/auth/me')) {
    // A 401 on /auth/me proves the token we SENT is bad. Only clear if it is
    // still the current token — protects the registration race (AuthProvider
    // wasAuthenticated guard) where a fresh token landed while we were in flight.
    if (sentToken !== null && sentToken === store.token) store.logout()
    return
  }
  console.warn('Unauthorized request:', url)
  store.logout()
  const path = window.location.pathname
  if (!redirectedToLogin && !PUBLIC_PATHS.some((p) => path === p || path.startsWith(`${p}/`))) {
    redirectedToLogin = true
    window.location.assign('/login')
  }
}
```
Interceptor call site (inside the existing `if (response.status === 401)`): extract the sent token from `error.config?.headers?.Authorization` (`Bearer x` → `x`, else null) and call `handleUnauthorized(url, sentToken)`. Delete the old inline logout block; KEEP the existing explanatory comment about queryClient.clear() (still true).

- [ ] **Step 4:** Tests PASS. Also run the existing suites touching this area: `pnpm --filter web test -- run src/features/auth src/features/company/__tests__` — expect no regressions.
- [ ] **Step 5:** `pnpm --filter web typecheck && pnpm --filter web lint` (scoped preflight for a FE-only diff).
- [ ] **Step 6:** Commit; push branch; `gh pr create --base dev --title "fix(auth): break 401 infinite loop — clear stale token + hard redirect to login" --body` with task id, contract summary, test evidence.
- [ ] **Step 7:** Dispatch `tenancy-authz-reviewer` on the diff; CHANGES-REQUESTED loops back here; record verdict in the PR + board task.

### Task 14: LAUNCH-PLAN-2026-07.md

**Files:**
- Create: `docs/factory/LAUNCH-PLAN-2026-07.md`

- [ ] **Step 1:** Write the track-split living doc per handover §Dark-Factory: VPS-autonomous / laptop-owner / mobile tracks; feed items from the unmerged-work ledger, the owner brief decision gates, the board seed set, and the loyalty trace. Each item: owner? / VPS-safe? / deps / reviewer(s) / done-criteria. Mark "Bill the Standard" customer-name spelling as UNCONFIRMED (owner to confirm).
- [ ] **Step 2:** Commit (local dev).

---

## Self-Review (done at write time)

- **Spec coverage:** §1 board→Tasks 1-4; §2 VPS loop→Task 12 artifact (installs are Phase 3, out of scope tonight by design); §3 laptop loop→process (no code) + Task 4 seeds; §4 page-coverage→Tasks 8-11; §5 cleanup→Tasks 5-7; B2→Task 13; LAUNCH-PLAN→Task 14. CI widening/branch protection = Phase 4, deliberately not planned tonight (owner-visible change).
- **Placeholders:** two test stubs in Task 3 Step 1 are flagged with explicit "must be written out in full" instructions + fixture pattern — acceptable compression; no TBDs elsewhere.
- **Type consistency:** `validateTask/nextId/loadTasks/claimTask/updateTask/boardDir` names consistent across Tasks 2-3-10; `extractRoutes` consistent 8-9; `handleUnauthorized/__resetRedirectGuard` consistent in Task 13.
