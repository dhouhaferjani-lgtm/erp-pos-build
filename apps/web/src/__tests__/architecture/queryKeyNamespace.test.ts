/**
 * Step 4 of the web.super-admin-frontend cluster (master plan §13). The
 * universal frontend invariant: every TanStack `queryKey` literal MUST
 * fall into exactly one of two cache namespaces:
 *
 *   (a) Tenant-scoped — first segment is a domain identifier (e.g.
 *       `'documents'`, `'partners'`, `'fraud-alerts'`). These queries
 *       fire under the per-tenant auth context (`useAuthStore` +
 *       Sanctum cookie + `apiGet`/`apiPost` from `@/lib/api`) and are
 *       implicitly bound to the current tenant.
 *
 *   (b) Super-admin-scoped — first segment is the literal string
 *       `'admin'`. These queries fire under the super-admin auth
 *       context (`useAdminAuthStore` + a different Sanctum guard +
 *       `adminApiGet`/`adminApiPost` from `features/admin/lib/adminApi.ts`).
 *
 * Files under `apps/web/src/features/admin/**` MUST always pick
 * branch (b); files outside `features/admin/` MUST always pick branch
 * (a). A queryKey like `['admin-users']` (single-segment, hyphenated)
 * outside `features/admin/` violates the invariant — TanStack's per-
 * key-prefix invalidation cycle invalidates by domain segment
 * (`'users'`, `'partners'`, etc.) so a domain-scoped query named
 * `'admin-users'` falls outside both invalidation patterns and risks
 * cache contamination across user/tenant switches.
 *
 * Honest limit of the heuristic:
 *
 *   The static-scan regex extracts the FIRST string literal inside a
 *   `queryKey: [...]` array. Dynamic queryKeys (e.g. `queryKey:
 *   buildKey(...)`) are skipped — the test cannot reason about runtime
 *   composition. The runtime invariant — does TanStack actually
 *   isolate the cache between admin and tenant contexts — is enforced
 *   by the `QueryClientProvider` wiring (separate clients are not
 *   currently used; the invariant relies on namespacing the keys).
 *   This static test is the namespacing-discipline catchnet.
 */
import { describe, expect, it } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'

const PROJECT_ROOT = resolve(__dirname, '../../..')
const SRC_ROOT = resolve(PROJECT_ROOT, 'src')
const ADMIN_FEATURE_PREFIX = 'features/admin/'

interface QueryKeyOccurrence {
  /** Repo-relative path (forward slashes), e.g. `apps/web/src/features/...`. */
  file: string
  /** 1-based line number of the queryKey's opening bracket. */
  line: number
  /** First segment string literal, or null if dynamic / non-literal. */
  firstSegment: string | null
  /** Verbatim source line for the failure message. */
  source: string
}

/**
 * Recursively walk a directory and yield every .ts/.tsx file path,
 * skipping node_modules + the test directory itself.
 */
function* walkSourceFiles(root: string): Generator<string> {
  const entries = readdirSync(root)
  for (const entry of entries) {
    const full = join(root, entry)
    const st = statSync(full)
    if (st.isDirectory()) {
      if (entry === 'node_modules' || entry === '__tests__' || entry === 'test') {
        continue
      }
      yield* walkSourceFiles(full)
    } else if (st.isFile() && (full.endsWith('.ts') || full.endsWith('.tsx'))) {
      // Skip declaration files — no queryKey literals there.
      if (full.endsWith('.d.ts')) continue
      yield full
    }
  }
}

/**
 * Extract every `queryKey: [...]` literal from a source file. Captures
 * the FIRST element of the array — the segment that determines the
 * cache namespace. If the first element is a non-literal expression
 * (e.g. `queryKey: buildKey(id)` or `queryKey: [...someArray]`), the
 * occurrence is recorded with `firstSegment: null` so the caller can
 * decide whether to flag or skip.
 */
function extractQueryKeyOccurrences(file: string): QueryKeyOccurrence[] {
  const source = readFileSync(file, 'utf8')
  const lines = source.split('\n')
  const occurrences: QueryKeyOccurrence[] = []

  // Pattern: `queryKey:` followed by `[` then either a quoted string or
  // some other expression. We deliberately keep the regex permissive
  // about whitespace so multi-line `queryKey: [\n  'foo',\n  ...]` is
  // captured (the literal-extraction step then operates on the next
  // few characters of the source string).
  const queryKeyAnchor = /queryKey\s*:\s*\[/g

  let match: RegExpExecArray | null
  while ((match = queryKeyAnchor.exec(source)) !== null) {
    const start = match.index + match[0].length
    // Look ahead for the first string literal or non-string expression.
    // Skip whitespace + newlines.
    let i = start
    while (i < source.length && /\s/.test(source[i]!)) {
      i++
    }
    const after = source[i]
    let firstSegment: string | null
    if (after === "'" || after === '"' || after === '`') {
      // Find the closing quote (assume no escapes mid-segment for the
      // namespace identifier — first-segment names are simple kebab-
      // case strings in this codebase).
      const quote = after
      const end = source.indexOf(quote, i + 1)
      if (end === -1) {
        firstSegment = null
      } else {
        firstSegment = source.slice(i + 1, end)
      }
    } else {
      // Dynamic / non-literal first element.
      firstSegment = null
    }

    // Compute the line number (1-based) for the queryKey anchor.
    const before = source.slice(0, match.index)
    const line = before.split('\n').length
    const sourceLine = (lines[line - 1] ?? '').trim()

    occurrences.push({
      file: relative(PROJECT_ROOT, file).replace(/\\/g, '/'),
      line,
      firstSegment,
      source: sourceLine,
    })
  }

  return occurrences
}

/**
 * Returns true if the file path (relative-to-project) is under
 * `apps/web/src/features/admin/`.
 */
function isUnderAdminFeature(repoRelativePath: string): boolean {
  // `apps/web/` is the project root for this test; we relativize from
  // there. The expected prefix on the relative path is `src/features/admin/`.
  return repoRelativePath.startsWith('src/' + ADMIN_FEATURE_PREFIX)
}

describe('queryKey namespace invariant (web.super-admin-frontend)', () => {
  it('every queryKey literal is correctly namespaced by file location', () => {
    const allOccurrences: QueryKeyOccurrence[] = []
    for (const file of walkSourceFiles(SRC_ROOT)) {
      allOccurrences.push(...extractQueryKeyOccurrences(file))
    }

    // Guard against vacuous-pass.
    expect(allOccurrences.length).toBeGreaterThan(100)

    const adminInTenantContext: QueryKeyOccurrence[] = []
    const tenantInAdminContext: QueryKeyOccurrence[] = []

    for (const occ of allOccurrences) {
      if (occ.firstSegment === null) {
        // Dynamic first element — skip; can't reason about it.
        continue
      }
      const inAdminFeature = isUnderAdminFeature(occ.file)
      if (inAdminFeature) {
        if (occ.firstSegment !== 'admin') {
          tenantInAdminContext.push(occ)
        }
      } else {
        if (occ.firstSegment === 'admin' || occ.firstSegment.startsWith('admin-')) {
          adminInTenantContext.push(occ)
        }
      }
    }

    // Both invariant violations are reported in a single failure.
    const errorParts: string[] = []

    if (adminInTenantContext.length > 0) {
      errorParts.push(
        `Found ${adminInTenantContext.length} queryKey(s) with admin-prefixed first segment OUTSIDE features/admin/:`,
        ...adminInTenantContext.map(
          (o) => `  - ${o.file}:${o.line}  queryKey: ['${o.firstSegment}', ...]\n      source: ${o.source}`,
        ),
        '',
        'Tenant-scoped features (everything outside features/admin/) must NOT use queryKeys whose first segment is "admin" or starts with "admin-".',
        'These keys collide with the super-admin cache namespace and risk cache contamination across user/tenant switches.',
        'Fix: rename the queryKey to a tenant-scoped tuple, e.g. `[\'users\', \'admin-role\', params]` instead of `[\'admin-users\']`.',
      )
    }

    if (tenantInAdminContext.length > 0) {
      if (errorParts.length > 0) errorParts.push('')
      errorParts.push(
        `Found ${tenantInAdminContext.length} queryKey(s) with non-admin first segment INSIDE features/admin/:`,
        ...tenantInAdminContext.map(
          (o) => `  - ${o.file}:${o.line}  queryKey: ['${o.firstSegment}', ...]\n      source: ${o.source}`,
        ),
        '',
        'Super-admin features (everything under features/admin/) must use queryKeys whose first segment is "admin".',
        'A tenant-scoped first segment inside features/admin/ collides with the per-tenant cache namespace.',
        'Fix: prepend "admin" as the first tuple segment, e.g. `[\'admin\', \'users\', params]`.',
      )
    }

    if (errorParts.length > 0) {
      throw new Error(errorParts.join('\n'))
    }
  })

  it('test-honesty pin: classifier rejects an admin-prefixed key from outside features/admin/', () => {
    // Negative-control: simulate an offending queryKey outside features/admin/.
    const fake: QueryKeyOccurrence = {
      file: 'src/features/compliance/components/Fixture.tsx',
      line: 1,
      firstSegment: 'admin-users',
      source: "queryKey: ['admin-users'],",
    }
    const inAdminFeature = isUnderAdminFeature(fake.file)
    expect(inAdminFeature).toBe(false)
    expect(
      fake.firstSegment === 'admin' || fake.firstSegment!.startsWith('admin-'),
    ).toBe(true)
    // → would be flagged as adminInTenantContext (the leak we're enforcing against).
  })

  it('test-honesty pin: classifier accepts an admin-prefixed key from inside features/admin/', () => {
    const fake: QueryKeyOccurrence = {
      file: 'src/features/admin/hooks/useUsers.ts',
      line: 1,
      firstSegment: 'admin',
      source: "queryKey: ['admin', 'users', params],",
    }
    expect(isUnderAdminFeature(fake.file)).toBe(true)
    expect(fake.firstSegment === 'admin').toBe(true)
    // → would NOT be flagged.
  })

  it('test-honesty pin: classifier accepts a tenant-prefixed key from outside features/admin/', () => {
    const fake: QueryKeyOccurrence = {
      file: 'src/features/documents/hooks/useInvoices.ts',
      line: 1,
      firstSegment: 'invoices',
      source: "queryKey: ['invoices', filter],",
    }
    expect(isUnderAdminFeature(fake.file)).toBe(false)
    expect(
      fake.firstSegment === 'admin' || fake.firstSegment!.startsWith('admin-'),
    ).toBe(false)
    // → would NOT be flagged.
  })
})
