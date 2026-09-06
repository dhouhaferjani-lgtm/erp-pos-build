// @ts-check
import { execFileSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'

import { afterAll, describe, expect, it } from 'vitest'

import {
  SCHEMA_VERSION,
  buildFingerprintPayload,
  computeFeatureFingerprint,
  parseRoutePaths,
  resolveBuildSha,
  resolveProduct,
} from '../write-build-fingerprint.mjs'

// vitest runs with `environment: 'jsdom'`, which rewrites `import.meta.url` to a
// browser URL — resolve from the runner cwd (apps/web) like the sibling tool tests.
const webRoot = process.cwd()
const generator = resolve(webRoot, 'tools/write-build-fingerprint.mjs')
const sampleManifest = resolve(webRoot, 'tools/__fixtures__/routes-web.sample.yaml')
const sortedManifest = resolve(webRoot, 'tools/__fixtures__/routes-web.sorted.yaml')

// Computed independently of the implementation:
//   printf '/\n/inventory/lots\n/pos' | shasum -a 256 | cut -c1-16
const SAMPLE_FINGERPRINT = 'dceb1e43945c5997'

const tempDirs = []
function makeTempDir() {
  const dir = mkdtempSync(join(tmpdir(), 'build-fingerprint-'))
  tempDirs.push(dir)
  return dir
}

afterAll(() => {
  for (const dir of tempDirs) rmSync(dir, { recursive: true, force: true })
})

describe('resolveBuildSha — env precedence', () => {
  const gitSha = () => 'g1t5hafromrepo0000000000000000000000000a'

  it('prefers BUILD_SHA over every other source', () => {
    expect(
      resolveBuildSha(
        {
          BUILD_SHA: 'aaa111',
          VITE_BUILD_SHA: 'bbb222',
          SOURCE_COMMIT: 'ccc333',
          GIT_SHA: 'ddd444',
        },
        gitSha,
      ),
    ).toBe('aaa111')
  })

  it('falls through to VITE_BUILD_SHA, then SOURCE_COMMIT, then GIT_SHA', () => {
    expect(
      resolveBuildSha({ VITE_BUILD_SHA: 'bbb222', SOURCE_COMMIT: 'ccc333', GIT_SHA: 'ddd444' }, gitSha),
    ).toBe('bbb222')
    expect(resolveBuildSha({ SOURCE_COMMIT: 'ccc333', GIT_SHA: 'ddd444' }, gitSha)).toBe('ccc333')
    expect(resolveBuildSha({ GIT_SHA: 'ddd444' }, gitSha)).toBe('ddd444')
  })

  it('treats empty and whitespace-only env values as absent', () => {
    expect(resolveBuildSha({ BUILD_SHA: '', VITE_BUILD_SHA: '   ', SOURCE_COMMIT: 'ccc333' }, gitSha)).toBe(
      'ccc333',
    )
  })

  it('trims the surviving value', () => {
    expect(resolveBuildSha({ BUILD_SHA: '  aaa111\n' }, gitSha)).toBe('aaa111')
  })

  it('falls back to git when no env var is set', () => {
    expect(resolveBuildSha({}, gitSha)).toBe(gitSha())
  })

  it('returns "unknown" — never a fabricated value — when git is unreachable', () => {
    expect(resolveBuildSha({}, () => null)).toBe('unknown')
    expect(
      resolveBuildSha({}, () => {
        throw new Error('not a git repository')
      }),
    ).toBe('unknown')
  })
})

describe('resolveProduct', () => {
  it('defaults to izipos', () => {
    expect(resolveProduct({})).toBe('izipos')
    expect(resolveProduct({ VITE_APP_PRODUCT: '  ' })).toBe('izipos')
  })

  it('honours VITE_APP_PRODUCT', () => {
    expect(resolveProduct({ VITE_APP_PRODUCT: 'otospex' })).toBe('otospex')
  })
})

describe('parseRoutePaths', () => {
  it('reads every `- path:` entry under routes:', () => {
    expect(parseRoutePaths(readFileSync(sampleManifest, 'utf8'))).toEqual([
      '/pos',
      '/',
      '/inventory/lots',
    ])
  })

  it('ignores non-route list entries such as sources:', () => {
    const paths = parseRoutePaths(readFileSync(sampleManifest, 'utf8'))
    expect(paths).not.toContain('apps/web/src/routes/index.tsx')
    expect(paths).toHaveLength(3)
  })

  it('reads the real checked-in web route manifest', () => {
    const real = readFileSync(resolve(webRoot, '../../scripts/factory/manifests/routes-web.yaml'), 'utf8')
    const paths = parseRoutePaths(real)
    expect(paths.length).toBeGreaterThan(50)
    expect(paths).toContain('/')
  })
})

describe('computeFeatureFingerprint', () => {
  it('is a deterministic 16-hex digest of the sorted route paths', () => {
    const { fingerprint, routeCount } = computeFeatureFingerprint(readFileSync(sampleManifest, 'utf8'))
    expect(fingerprint).toBe(SAMPLE_FINGERPRINT)
    expect(fingerprint).toMatch(/^[0-9a-f]{16}$/)
    expect(routeCount).toBe(3)
  })

  it('is order-independent — the same routes in sorted order hash identically', () => {
    expect(computeFeatureFingerprint(readFileSync(sortedManifest, 'utf8')).fingerprint).toBe(
      SAMPLE_FINGERPRINT,
    )
  })

  it('changes when a route is added', () => {
    const mutated = `${readFileSync(sampleManifest, 'utf8')}  - path: /inventory/lots/new\n`
    const { fingerprint, routeCount } = computeFeatureFingerprint(mutated)
    expect(fingerprint).not.toBe(SAMPLE_FINGERPRINT)
    expect(routeCount).toBe(4)
  })

  it('reports unknown for a missing manifest (null text)', () => {
    expect(computeFeatureFingerprint(null)).toEqual({ fingerprint: 'unknown', routeCount: 0 })
  })
})

describe('buildFingerprintPayload', () => {
  it('emits the documented shape', () => {
    const payload = buildFingerprintPayload({
      env: { BUILD_SHA: 'abc123', VITE_APP_PRODUCT: 'otospex' },
      manifestText: readFileSync(sampleManifest, 'utf8'),
      now: new Date('2026-09-07T10:20:30.000Z'),
      gitSha: () => null,
    })

    expect(payload).toEqual({
      schema: SCHEMA_VERSION,
      build_sha: 'abc123',
      build_time: '2026-09-07T10:20:30.000Z',
      product: 'otospex',
      feature_fingerprint: SAMPLE_FINGERPRINT,
      route_count: 3,
    })
    expect(SCHEMA_VERSION).toBe(1)
  })

  it('emits build_time as an ISO 8601 UTC instant', () => {
    const payload = buildFingerprintPayload({
      env: {},
      manifestText: null,
      now: new Date('2026-09-07T10:20:30.000Z'),
      gitSha: () => null,
    })
    expect(payload.build_time).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/)
    expect(payload.build_sha).toBe('unknown')
    expect(payload.feature_fingerprint).toBe('unknown')
    expect(payload.route_count).toBe(0)
    expect(payload.product).toBe('izipos')
  })
})

describe('CLI', () => {
  function run(args, env, expectFailure = false) {
    try {
      const stdout = execFileSync('node', [generator, ...args], {
        cwd: webRoot,
        encoding: 'utf8',
        env: { PATH: process.env.PATH, ...env },
      })
      if (expectFailure) throw new Error(`expected a non-zero exit, got success:\n${stdout}`)
      return { status: 0, stdout, stderr: '' }
    } catch (error) {
      if (!expectFailure) throw error
      return {
        status: typeof error.status === 'number' ? error.status : -1,
        stdout: String(error.stdout ?? ''),
        stderr: String(error.stderr ?? ''),
      }
    }
  }

  it('--print-fingerprint prints only the hash and writes nothing', () => {
    const out = join(makeTempDir(), 'build-fingerprint.json')
    const { stdout } = run(['--print-fingerprint', '--out', out], {
      BUILD_FINGERPRINT_ROUTES_MANIFEST: sampleManifest,
    })
    expect(stdout).toBe(`${SAMPLE_FINGERPRINT}\n`)
    expect(() => readFileSync(out, 'utf8')).toThrow()
  })

  it('writes the JSON file at --out', () => {
    const out = join(makeTempDir(), 'build-fingerprint.json')
    run(['--out', out], {
      BUILD_SHA: 'deadbeef',
      VITE_APP_PRODUCT: 'otospex',
      BUILD_FINGERPRINT_ROUTES_MANIFEST: sampleManifest,
    })
    const written = JSON.parse(readFileSync(out, 'utf8'))
    expect(written.build_sha).toBe('deadbeef')
    expect(written.product).toBe('otospex')
    expect(written.feature_fingerprint).toBe(SAMPLE_FINGERPRINT)
    expect(written.route_count).toBe(3)
    expect(written.schema).toBe(SCHEMA_VERSION)
  })

  it('with a missing manifest: writes "unknown" and exits 0 locally', () => {
    const dir = makeTempDir()
    const out = join(dir, 'build-fingerprint.json')
    const { status } = run(['--out', out], {
      BUILD_SHA: 'deadbeef',
      BUILD_FINGERPRINT_ROUTES_MANIFEST: join(dir, 'nope.yaml'),
    })
    expect(status).toBe(0)
    expect(JSON.parse(readFileSync(out, 'utf8')).feature_fingerprint).toBe('unknown')
  })

  it('with a PRESENT but unparseable manifest: exits 1 under CI=true', () => {
    // Gate r1 finding 2: the CI guard originally lived only in the readFileSync catch, so a
    // manifest that exists but yields zero routes (e.g. gen-route-manifest.mjs starts quoting
    // or reindenting paths) shipped feature_fingerprint "unknown" with exit 0.
    const dir = makeTempDir()
    const badManifest = join(dir, 'routes-web.yaml')
    writeFileSync(badManifest, 'app: web\nroutes:\n  - "path": "/pos"\n', 'utf8')
    const out = join(dir, 'build-fingerprint.json')

    const { status, stderr } = run(
      ['--out', out],
      { CI: 'true', BUILD_SHA: 'deadbeef', BUILD_FINGERPRINT_ROUTES_MANIFEST: badManifest },
      true,
    )
    expect(status).toBe(1)
    expect(stderr).toContain('0 routes')
  })

  it('with a PRESENT but unparseable manifest: exits 0 locally (no CI)', () => {
    const dir = makeTempDir()
    const badManifest = join(dir, 'routes-web.yaml')
    writeFileSync(badManifest, 'app: web\nroutes:\n  - "path": "/pos"\n', 'utf8')
    const out = join(dir, 'build-fingerprint.json')

    const { status } = run(['--out', out], {
      BUILD_SHA: 'deadbeef',
      BUILD_FINGERPRINT_ROUTES_MANIFEST: badManifest,
    })
    expect(status).toBe(0)
    expect(JSON.parse(readFileSync(out, 'utf8')).feature_fingerprint).toBe('unknown')
  })

  it('--print-fingerprint on an unparseable manifest also exits 1 under CI=true', () => {
    const dir = makeTempDir()
    const badManifest = join(dir, 'routes-web.yaml')
    writeFileSync(badManifest, 'routes: []\n', 'utf8')

    const { status } = run(
      ['--print-fingerprint'],
      { CI: 'true', BUILD_FINGERPRINT_ROUTES_MANIFEST: badManifest },
      true,
    )
    expect(status).toBe(1)
  })

  it('with a missing manifest: exits 1 under CI=true', () => {
    const dir = makeTempDir()
    const out = join(dir, 'build-fingerprint.json')
    const { status, stderr } = run(
      ['--out', out],
      { CI: 'true', BUILD_SHA: 'deadbeef', BUILD_FINGERPRINT_ROUTES_MANIFEST: join(dir, 'nope.yaml') },
      true,
    )
    expect(status).toBe(1)
    expect(stderr).toContain('routes-web')
  })
})
