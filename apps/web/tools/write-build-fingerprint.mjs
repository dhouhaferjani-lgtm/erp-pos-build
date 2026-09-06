// @ts-check
/**
 * Emits `dist/build-fingerprint.json` AFTER `vite build`.
 *
 * Why this file exists: the staging web bundle is a separate Dokploy deploy with
 * `autoDeploy=false` and sat 78 commits stale undetected on 2026-07-04
 * (`docs/factory/WORKFLOW.md:220-227`). The staging push manifest §3 previously had to
 * diff asset hashes and grep the served bundle for a slice-unique string; with this file
 * the check becomes two `curl | jq` lines
 * (`docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md` §3).
 *
 * Deliberately written to `dist/` at build time and NEVER imported by the app: importing
 * it would bake the sha into the hashed asset graph, which is exactly the signal the
 * stale-bundle check relies on. It is served `no-store` by the nginx location added in
 * `apps/web/docker/entrypoint.sh`.
 *
 * Output shape (schema 1):
 *   {
 *     "schema": 1,
 *     "build_sha": "<40-hex or 'unknown'>",
 *     "build_time": "2026-09-07T10:20:30.000Z",
 *     "product": "izipos" | "otospex",
 *     "feature_fingerprint": "<16 hex or 'unknown'>",
 *     "route_count": 123
 *   }
 *
 * Usage:
 *   node tools/write-build-fingerprint.mjs                 # write dist/build-fingerprint.json
 *   node tools/write-build-fingerprint.mjs --out <path>    # write elsewhere
 *   node tools/write-build-fingerprint.mjs --print-fingerprint   # print the hash only, write nothing
 *
 * Environment:
 *   BUILD_SHA | VITE_BUILD_SHA | SOURCE_COMMIT | GIT_SHA  — commit sha, in that precedence.
 *     The Docker builder stage copies `apps/web/` only (no `.git`), so inside the image the
 *     sha MUST arrive as the `BUILD_SHA` build arg. Whether Dokploy passes it is tracked as
 *     UNVERIFIED U-9 in the staging push manifest §6; until it is closed the value is the
 *     explicit string "unknown" — never a fabricated sha.
 *   VITE_APP_PRODUCT                        — `izipos` (default) or `otospex`.
 *   BUILD_FINGERPRINT_ROUTES_MANIFEST       — override the route-manifest path (tests, CI).
 *   CI=true                                 — a missing route manifest becomes a hard failure.
 */
import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { mkdirSync, readFileSync, realpathSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

/** Bump only on a breaking change to the JSON shape; consumers pin on it. */
export const SCHEMA_VERSION = 1

/** Explicit "we do not know" marker — never fabricate a sha or a fingerprint. */
export const UNKNOWN = 'unknown'

/** Commit-sha env vars, highest precedence first. */
export const SHA_ENV_VARS = ['BUILD_SHA', 'VITE_BUILD_SHA', 'SOURCE_COMMIT', 'GIT_SHA']

/** Path of the checked-in web route manifest, relative to `apps/web`. */
export const ROUTES_MANIFEST_RELATIVE = '../../scripts/factory/manifests/routes-web.yaml'

/**
 * `git rev-parse HEAD` when a git dir is reachable, else null. Local builds only —
 * the Docker builder stage has no `.git`.
 *
 * @param {string} [cwd]
 * @returns {string | null}
 */
export function readGitSha(cwd) {
  try {
    const sha = execFileSync('git', ['rev-parse', 'HEAD'], {
      cwd,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'ignore'],
    }).trim()
    return sha === '' ? null : sha
  } catch {
    return null
  }
}

/**
 * First non-empty of BUILD_SHA, VITE_BUILD_SHA, SOURCE_COMMIT, GIT_SHA, then git,
 * else the literal "unknown".
 *
 * @param {Record<string, string | undefined>} env
 * @param {() => string | null} gitSha
 * @returns {string}
 */
export function resolveBuildSha(env, gitSha = readGitSha) {
  for (const name of SHA_ENV_VARS) {
    const value = (env[name] ?? '').trim()
    if (value !== '') return value
  }

  try {
    const fromGit = (gitSha() ?? '').trim()
    if (fromGit !== '') return fromGit
  } catch {
    // A build without a reachable git dir is the normal Docker case, not an error.
  }

  return UNKNOWN
}

/**
 * @param {Record<string, string | undefined>} env
 * @returns {string}
 */
export function resolveProduct(env) {
  const product = (env.VITE_APP_PRODUCT ?? '').trim()
  return product === '' ? 'izipos' : product
}

/**
 * Route paths, in file order, from a `routes-web.yaml`. Line-scanned rather than
 * YAML-parsed on purpose: the manifest is machine-generated with a fixed two-space
 * `  - path: /x` shape (`scripts/factory/gen-route-manifest.mjs`) and this keeps the
 * build step dependency-free.
 *
 * @param {string | null} yamlText
 * @returns {string[]}
 */
export function parseRoutePaths(yamlText) {
  if (yamlText === null) return []

  const paths = []
  for (const line of yamlText.split('\n')) {
    const match = /^\s*-\s+path:\s*(\S.*?)\s*$/.exec(line)
    if (match !== null) paths.push(match[1])
  }
  return paths
}

/**
 * sha256 of the sorted route paths joined by "\n", truncated to 16 hex chars.
 * Sorting makes the value independent of manifest emission order, so it changes
 * only when the route SET changes.
 *
 * @param {string | null} yamlText
 * @returns {{ fingerprint: string, routeCount: number }}
 */
export function computeFeatureFingerprint(yamlText) {
  const paths = parseRoutePaths(yamlText)
  if (yamlText === null || paths.length === 0) {
    return { fingerprint: UNKNOWN, routeCount: paths.length }
  }

  const digest = createHash('sha256').update([...paths].sort().join('\n')).digest('hex')
  return { fingerprint: digest.slice(0, 16), routeCount: paths.length }
}

/**
 * @param {{
 *   env: Record<string, string | undefined>,
 *   manifestText: string | null,
 *   now?: Date,
 *   gitSha?: () => string | null,
 * }} options
 * @returns {{ schema: number, build_sha: string, build_time: string, product: string, feature_fingerprint: string, route_count: number }}
 */
export function buildFingerprintPayload({ env, manifestText, now = new Date(), gitSha = readGitSha }) {
  const { fingerprint, routeCount } = computeFeatureFingerprint(manifestText)

  return {
    schema: SCHEMA_VERSION,
    build_sha: resolveBuildSha(env, gitSha),
    build_time: now.toISOString(),
    product: resolveProduct(env),
    feature_fingerprint: fingerprint,
    route_count: routeCount,
  }
}

/**
 * @param {string[]} argv
 * @returns {{ printFingerprintOnly: boolean, out: string | null }}
 */
export function parseArgs(argv) {
  let printFingerprintOnly = false
  let out = null

  for (let i = 0; i < argv.length; i += 1) {
    if (argv[i] === '--print-fingerprint') {
      printFingerprintOnly = true
    } else if (argv[i] === '--out') {
      out = argv[i + 1] ?? null
      i += 1
    }
  }

  return { printFingerprintOnly, out }
}

/**
 * @param {string[]} argv
 * @param {Record<string, string | undefined>} env
 * @param {string} webRoot
 * @returns {number} process exit code
 */
export function main(argv, env, webRoot) {
  const { printFingerprintOnly, out } = parseArgs(argv)
  const manifestPath = (env.BUILD_FINGERPRINT_ROUTES_MANIFEST ?? '').trim() || resolve(webRoot, ROUTES_MANIFEST_RELATIVE)

  /** @type {string | null} */
  let manifestText = null
  try {
    manifestText = readFileSync(manifestPath, 'utf8')
  } catch {
    const message = `write-build-fingerprint: route manifest not found at ${manifestPath} (scripts/factory/manifests/routes-web.yaml); feature_fingerprint will be "${UNKNOWN}".`
    if ((env.CI ?? '').trim() !== '') {
      process.stderr.write(`${message} Failing because CI is set.\n`)
      return 1
    }
    process.stderr.write(`${message}\n`)
  }

  const payload = buildFingerprintPayload({ env, manifestText })

  if (printFingerprintOnly) {
    process.stdout.write(`${payload.feature_fingerprint}\n`)
    return 0
  }

  const target = out ?? resolve(webRoot, 'dist/build-fingerprint.json')
  mkdirSync(dirname(target), { recursive: true })
  writeFileSync(target, `${JSON.stringify(payload, null, 2)}\n`, 'utf8')
  process.stdout.write(`write-build-fingerprint: wrote ${target} (build_sha=${payload.build_sha}, feature_fingerprint=${payload.feature_fingerprint}, route_count=${payload.route_count})\n`)
  return 0
}

/**
 * True only when this file is the process entrypoint. Guarded because vitest's jsdom
 * environment rewrites `import.meta.url` to a browser URL, which `fileURLToPath` rejects.
 *
 * @returns {boolean}
 */
function invokedDirectly() {
  try {
    const entry = process.argv[1]
    if (entry === undefined) return false
    return realpathSync(entry) === realpathSync(fileURLToPath(import.meta.url))
  } catch {
    return false
  }
}

if (invokedDirectly()) {
  const webRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..')
  process.exit(main(process.argv.slice(2), process.env, webRoot))
}
