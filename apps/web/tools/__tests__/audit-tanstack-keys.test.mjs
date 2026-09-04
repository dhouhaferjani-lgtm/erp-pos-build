// @ts-check
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, it, expect } from 'vitest';

import { partitionViolationsByBaseline, scanCode } from '../audit-tanstack-keys.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PAIRING_FIXTURES = path.resolve(
  __dirname,
  '..',
  '__fixtures__',
  'audit-tanstack-keys',
  'placeholder-pairing',
);

/**
 * Scan one on-disk fixture through the same entrypoint the CLI uses.
 *
 * @param {string} name fixture basename, e.g. 'two-reads-one-guarded.tsx'
 * @returns {ReturnType<typeof scanCode>}
 */
function scanFixture(name) {
  const file = path.join(PAIRING_FIXTURES, name);
  return scanCode(readFileSync(file, 'utf8'), file);
}

/**
 * Unit coverage for the Architecture Gate C scanner. Codex 2026-05-03 review
 * C4 flagged that the prior version (a) missed nested `useQueries({ queries: [...] })`
 * keys entirely and (b) over-approved any property access ending in
 * companyId/tenantId. These tests pin the new behavior.
 */

describe('Gate C — TanStack queryKey scanner', () => {
  describe('useQuery', () => {
    it('flags bare array queryKey with no scope', () => {
      const v = scanCode(`
        useQuery({
          queryKey: ['users'],
          queryFn: () => fetch('/users'),
        });
      `, 'inline.ts');
      expect(v).toHaveLength(1);
      expect(v[0].reason).toContain('useQuery');
    });

    it('approves tenantScopedKey factory call', () => {
      const v = scanCode(`
        useQuery({
          queryKey: tenantScopedKey(['users']),
          queryFn: () => fetch('/users'),
        });
      `, 'inline.ts');
      expect(v).toEqual([]);
    });

    it('approves locationScopedKey factory call', () => {
      const v = scanCode(`
        useQuery({
          queryKey: locationScopedKey(['stock-levels'], scope),
          queryFn: () => fetch('/stock-levels'),
        });
      `, 'inline.ts');
      expect(v).toEqual([]);
    });

    it('approves array containing currentCompanyId identifier', () => {
      const v = scanCode(`
        useQuery({
          queryKey: ['users', currentCompanyId],
          queryFn: () => fetch('/users'),
        });
      `, 'inline.ts');
      expect(v).toEqual([]);
    });

    it('approves array starting with super-admin namespace string', () => {
      const v = scanCode(`
        useQuery({
          queryKey: ['admin', 'tenants'],
          queryFn: () => fetch('/admin/tenants'),
        });
      `, 'inline.ts');
      expect(v).toEqual([]);
    });

    it('approves companyStore.currentCompanyId member access', () => {
      const v = scanCode(`
        useQuery({
          queryKey: ['users', companyStore.currentCompanyId],
          queryFn: () => fetch('/users'),
        });
      `, 'inline.ts');
      expect(v).toEqual([]);
    });

    it('rejects bare props.companyId member access (Codex C4: tightened)', () => {
      // Before the C4 fix, the scanner accepted any property access whose
      // final segment was companyId/tenantId/currentCompanyId. After the
      // fix, only companyStore.<approved-name> auto-approves.
      const v = scanCode(`
        function Component(props) {
          useQuery({
            queryKey: ['users', props.companyId],
            queryFn: () => fetch('/users'),
          });
        }
      `, 'inline.tsx');
      expect(v).toHaveLength(1);
    });

    it('rejects payload.tenantId member access (Codex C4: tightened)', () => {
      const v = scanCode(`
        useQuery({
          queryKey: ['data', payload.tenantId],
          queryFn: () => fetch('/data'),
        });
      `, 'inline.ts');
      expect(v).toHaveLength(1);
    });

    it('handles queryKey wrapped in `as const`', () => {
      const v = scanCode(`
        useQuery({
          queryKey: ['users', currentCompanyId] as const,
          queryFn: () => fetch('/users'),
        });
      `, 'inline.ts');
      expect(v).toEqual([]);
    });

    it('handles queryKey wrapped in `satisfies QueryKey`', () => {
      const v = scanCode(`
        useQuery({
          queryKey: ['users', currentCompanyId] satisfies readonly unknown[],
          queryFn: () => fetch('/users'),
        });
      `, 'inline.ts');
      expect(v).toEqual([]);
    });

    // Codex 2026-05-09 review F1: parenthesized queryKey expressions were
    // not unwrapped by the prior version, so `(['users', x] as const)` was
    // misclassified as ast_kind: 'other' and falsely flagged. The shared
    // unwrapKeyExpression() helper now strips parentheses alongside as,
    // type-assertion, and satisfies wrappers in all three inspection sites.

    it('approves parenthesized + as-const scoped queryKey', () => {
      const v = scanCode(`
        useQuery({
          queryKey: (['users', currentCompanyId] as const),
          queryFn: () => fetch('/users'),
        });
      `, 'inline.ts');
      expect(v).toEqual([]);
    });

    it('flags parenthesized unscoped queryKey with correct resource + ast_kind', () => {
      const v = scanCode(`
        useQuery({
          queryKey: (['users'] as const),
          queryFn: () => fetch('/users'),
        });
      `, 'inline.ts');
      expect(v).toHaveLength(1);
      expect(v[0].resource).toBe('users');
      expect(v[0].ast_kind).toBe('array_literal');
    });

    it('approves parenthesized tenantScopedKey() factory call', () => {
      const v = scanCode(`
        useQuery({
          queryKey: (tenantScopedKey(['users'])),
          queryFn: () => fetch('/users'),
        });
      `, 'inline.ts');
      expect(v).toEqual([]);
    });

    it('approves nested parens around as-const', () => {
      const v = scanCode(`
        useQuery({
          queryKey: ((['users', currentCompanyId] as const)),
          queryFn: () => fetch('/users'),
        });
      `, 'inline.ts');
      expect(v).toEqual([]);
    });

    it('flags shorthand queryKey declarations with no tenant scope', () => {
      const v = scanCode(`
        function usePartnerSearch() {
          const queryKey = ['pickers', 'partner', search] as const;
          return useQuery({
            queryKey,
            queryFn: () => fetch('/partners'),
          });
        }
      `, 'inline.ts');
      expect(v).toHaveLength(1);
      expect(v[0].resource).toBe('pickers');
      expect(v[0].ast_kind).toBe('array_literal');
    });

    it('does not resolve shorthand queryKey declarations from sibling function scopes', () => {
      const v = scanCode(`
        function unrelated() {
          const queryKey = tenantScopedKey(['partners']);
          return queryKey;
        }

        function usePartnerSearch() {
          return useQuery({
            queryKey,
            queryFn: () => fetch('/partners'),
          });
        }
      `, 'inline.ts');

      expect(v).toHaveLength(1);
      expect(v[0].ast_kind).toBe('identifier');
    });
  });

  describe('useQueries (Codex C4: nested entries must be audited)', () => {
    it('flags an unscoped queryKey inside useQueries.queries[]', () => {
      const v = scanCode(`
        useQueries({
          queries: [
            { queryKey: ['articles', articleId], queryFn: () => f() },
            { queryKey: ['linkages', articleId], queryFn: () => g() },
          ],
        });
      `, 'inline.ts');
      expect(v).toHaveLength(2);
      expect(v.every((e) => e.reason.includes('useQueries.queries[]'))).toBe(true);
    });

    it('approves scoped queries inside useQueries.queries[]', () => {
      const v = scanCode(`
        useQueries({
          queries: [
            { queryKey: tenantScopedKey(['articles', articleId]), queryFn: () => f() },
            { queryKey: ['linkages', articleId, currentCompanyId], queryFn: () => g() },
          ],
        });
      `, 'inline.ts');
      expect(v).toEqual([]);
    });

    it('mixed scoped and unscoped useQueries entries are partially flagged', () => {
      const v = scanCode(`
        useQueries({
          queries: [
            { queryKey: tenantScopedKey(['a']), queryFn: () => f() },
            { queryKey: ['b'], queryFn: () => g() },
          ],
        });
      `, 'inline.ts');
      expect(v).toHaveLength(1);
    });
  });

  describe('queryClient methods', () => {
    it('approves a bare array-literal prefix for queryClient.invalidateQueries', () => {
      const v = scanCode(`
        queryClient.invalidateQueries({ queryKey: ['users'] });
      `, 'inline.ts');
      expect(v).toEqual([]);
    });

    it('flags an opaque key for queryClient.invalidateQueries', () => {
      const v = scanCode(`
        queryClient.invalidateQueries({ queryKey: dynamicKey });
      `, 'inline.ts');
      expect(v).toHaveLength(1);
    });

    it('still flags a bare array-literal key for queryClient.fetchQuery', () => {
      const v = scanCode(`
        queryClient.fetchQuery({ queryKey: ['users'] });
      `, 'inline.ts');
      expect(v).toHaveLength(1);
    });

    it('approves scoped queryClient.refetchQueries', () => {
      const v = scanCode(`
        queryClient.refetchQueries({ queryKey: ['users', currentCompanyId] });
      `, 'inline.ts');
      expect(v).toEqual([]);
    });
  });

  describe('cache-filter methods reject tenantScopedKey filters (proven no-op)', () => {
    // tenantScopedKey appends tenant/company as SUFFIXES; React Query matches
    // filter keys as positional PREFIXES. A tenantScopedKey(...) filter
    // therefore only matches when it happens to equal the FULL query key —
    // for every namespace-prefix intent it silently matches zero queries
    // (memory: project_tanstack_invalidation_suffix_noop). Filters must use
    // bare literal prefixes instead.
    const CACHE_FILTER_METHODS = [
      'invalidateQueries',
      'removeQueries',
      'resetQueries',
      'refetchQueries',
      'cancelQueries',
    ];

    for (const method of CACHE_FILTER_METHODS) {
      it(`flags queryClient.${method} with a tenantScopedKey(...) filter`, () => {
        const v = scanCode(`
          queryClient.${method}({ queryKey: tenantScopedKey(['stock-transfers']) });
        `, 'inline.ts');
        expect(v).toHaveLength(1);
        expect(v[0].reason).toContain('no-op');
        expect(v[0].factory).toBe(method);
      });

      it(`approves queryClient.${method} with a bare array-literal prefix`, () => {
        const v = scanCode(`
          queryClient.${method}({ queryKey: ['stock-transfers'] });
        `, 'inline.ts');
        expect(v).toEqual([]);
      });
    }

    it('flags queryClient.invalidateQueries with a locationScopedKey(...) filter', () => {
      const v = scanCode(`
        queryClient.invalidateQueries({ queryKey: locationScopedKey(['stock-levels'], scope) });
      `, 'inline.ts');
      expect(v).toHaveLength(1);
      expect(v[0].reason).toContain('no-op');
      expect(v[0].factory).toBe('invalidateQueries');
    });

    it('flags a parenthesized tenantScopedKey(...) filter', () => {
      const v = scanCode(`
        queryClient.invalidateQueries({ queryKey: (tenantScopedKey(['users'])) });
      `, 'inline.ts');
      expect(v).toHaveLength(1);
      expect(v[0].reason).toContain('no-op');
    });

    it('flags a shorthand queryKey resolving to tenantScopedKey(...)', () => {
      const v = scanCode(`
        function useThing() {
          const queryKey = tenantScopedKey(['users']);
          return () => queryClient.invalidateQueries({ queryKey });
        }
      `, 'inline.ts');
      expect(v).toHaveLength(1);
      expect(v[0].reason).toContain('no-op');
    });

    it('emits resource + ast_kind metadata for the no-op violation', () => {
      const v = scanCode(`
        function useCancelStockTransfer() {
          return () => queryClient.invalidateQueries({ queryKey: tenantScopedKey(['stock-transfers', id]) });
        }
      `, 'inline.ts');
      expect(v).toHaveLength(1);
      expect(v[0].resource).toBe('stock-transfers');
      expect(v[0].ast_kind).toBe('call_expression');
    });

    it('still approves tenantScopedKey for useQuery (full-key factory, not a filter)', () => {
      const v = scanCode(`
        useQuery({
          queryKey: tenantScopedKey(['stock-transfers', 'list']),
          queryFn: () => fetch('/stock-transfers'),
        });
      `, 'inline.ts');
      expect(v).toEqual([]);
    });

    it('still approves tenantScopedKey for queryClient.fetchQuery (full-key factory)', () => {
      const v = scanCode(`
        queryClient.fetchQuery({ queryKey: tenantScopedKey(['users']), queryFn: () => f() });
      `, 'inline.ts');
      expect(v).toEqual([]);
    });

    it('still flags a bare array-literal key for queryClient.prefetchQuery (not a filter method)', () => {
      const v = scanCode(`
        queryClient.prefetchQuery({ queryKey: ['users'], queryFn: () => f() });
      `, 'inline.ts');
      expect(v).toHaveLength(1);
    });
  });

  describe('useMutation (does NOT use queryKey)', () => {
    it('useMutation with mutationKey is not picked up', () => {
      // Codex C4 note: useMutation uses mutationKey, not queryKey. The
      // scanner should not flag mutationKey at all — the caller can
      // decide whether mutation cache scoping matters separately.
      const v = scanCode(`
        useMutation({
          mutationKey: ['create-user'],
          mutationFn: (payload) => fetch('/users', { method: 'POST', body: JSON.stringify(payload) }),
        });
      `, 'inline.ts');
      expect(v).toEqual([]);
    });
  });

  describe('violation metadata (sweep:inventory:generate wiring)', () => {
    // The TanstackKeysScanner.php (apps/api/.../Sweep/Scanners/) consumes
    // these fields to build a CallsiteRow with a stable_key, enclosing
    // symbol, and resource label. Pinning the shape here so any scanner
    // edit that drops a field surfaces immediately.

    it('emits factory + enclosing_symbol + resource + statement_fingerprint + ast_kind', () => {
      const v = scanCode(`
        function useUsers() {
          return useQuery({
            queryKey: ['users', 42],
            queryFn: () => fetch('/users'),
          });
        }
      `, 'inline.ts');
      expect(v).toHaveLength(1);
      const e = v[0];
      expect(e.factory).toBe('useQuery');
      expect(e.enclosing_symbol).toBe('useUsers');
      expect(e.resource).toBe('users');
      expect(e.ast_kind).toBe('array_literal');
      // Statement fingerprint is whitespace-normalized + suffixed with the
      // queryKey expression's byte offset for collision-free identity.
      expect(e.statement_fingerprint).toMatch(/^\['users', 42\]@\d+$/);
    });

    it('disambiguates two queryKey expressions sharing the same enclosing symbol via byte offset', () => {
      const v = scanCode(`
        function Component() {
          useMutation({
            mutationFn: () => f(),
            onSuccess: () => {
              queryClient.fetchQuery({ queryKey: ['orders'] });
            },
          });
          useMutation({
            mutationFn: () => g(),
            onSuccess: () => {
              queryClient.fetchQuery({ queryKey: ['orders'] });
            },
          });
        }
      `, 'inline.tsx');
      expect(v).toHaveLength(2);
      // Both have the same enclosing symbol (onSuccess) and same fingerprint
      // text (['orders']) — but byte offsets differ, so the full fingerprints
      // are distinct.
      expect(v[0].enclosing_symbol).toBe('onSuccess');
      expect(v[1].enclosing_symbol).toBe('onSuccess');
      expect(v[0].statement_fingerprint).not.toBe(v[1].statement_fingerprint);
    });

    it('extracts no resource when the queryKey is not an array literal', () => {
      // useQueries inside a `queries` array still flags entries whose key
      // is opaque (Codex C4 nested-entry path); the resource extractor
      // returns null for non-array-literal keys.
      const v = scanCode(`
        useQuery({
          queryKey: dynamicKey,
          queryFn: () => fetch('/x'),
        });
      `, 'inline.ts');
      expect(v).toHaveLength(1);
      expect(v[0].resource).toBeNull();
      expect(v[0].ast_kind).toBe('identifier');
    });

    it('classifies ast_kind as call_expression for unknown factory calls', () => {
      const v = scanCode(`
        useQuery({
          queryKey: someUnknownFactory(['x']),
          queryFn: () => fetch('/x'),
        });
      `, 'inline.ts');
      expect(v).toHaveLength(1);
      expect(v[0].ast_kind).toBe('call_expression');
    });

    it('extracts the first string-literal element from a multi-segment array', () => {
      const v = scanCode(`
        useQuery({
          queryKey: ['products', 'detail', someId],
          queryFn: () => fetch('/p'),
        });
      `, 'inline.ts');
      expect(v).toHaveLength(1);
      expect(v[0].resource).toBe('products');
    });
  });

  describe('baseline filtering for gate mode', () => {
    it('separates current baseline hits from new violations', () => {
      const baseline = new Set([
        'src/features/example.ts|useQuery|useExample|[\'legacy\']@101',
      ]);
      const violations = [
        {
          file: 'src/features/example.ts',
          line: 10,
          column: 5,
          reason: 'useQuery({ queryKey: ... }) lacks an approved tenant scope',
          factory: 'useQuery',
          enclosing_symbol: 'useExample',
          resource: 'legacy',
          statement_fingerprint: '[\'legacy\']@101',
          ast_kind: 'array_literal',
        },
        {
          file: 'src/features/new.ts',
          line: 20,
          column: 7,
          reason: 'useQuery({ queryKey: ... }) lacks an approved tenant scope',
          factory: 'useQuery',
          enclosing_symbol: 'useNew',
          resource: 'new',
          statement_fingerprint: '[\'new\']@202',
          ast_kind: 'array_literal',
        },
      ];

      const result = partitionViolationsByBaseline(violations, baseline);

      expect(result.baselined).toEqual([violations[0]]);
      expect(result.newViolations).toEqual([violations[1]]);
      expect(result.staleBaselineEntries).toEqual([]);
    });

    it('reports stale baseline entries that no longer match a current violation', () => {
      const result = partitionViolationsByBaseline([], new Set([
        'src/features/old.ts|useQuery|useOld|[\'old\']@303',
      ]));

      expect(result.baselined).toEqual([]);
      expect(result.newViolations).toEqual([]);
      expect(result.staleBaselineEntries).toEqual([
        'src/features/old.ts|useQuery|useOld|[\'old\']@303',
      ]);
    });
  });

  /**
   * `placeholderData: keepPreviousData` on a tenant-scoped key hands the
   * PREVIOUS company's payload back across the tenant/company suffix:
   * TanStack picks the placeholder from the observer's last query that had
   * data with no key-lineage check (`queryObserver.js` #lastQueryWithDefinedData),
   * and a company switch only invalidates, it never unmounts the page. It is
   * legitimate WITHIN one scope (paging), so the rule is pairing, not a ban:
   * the reader must be gated by `usePlaceholderScopeGuard`.
   */
  describe('placeholderData on a scoped key', () => {
    it('flags placeholderData on a tenantScopedKey read with no scope guard in the file', () => {
      const v = scanCode(`
        useQuery({
          queryKey: tenantScopedKey(['payments', page]),
          queryFn: () => fetch('/payments'),
          placeholderData: keepPreviousData,
        });
      `, 'inline.ts');
      expect(v).toHaveLength(1);
      expect(v[0].reason).toContain('usePlaceholderScopeGuard');
    });

    it('flags it on a locationScopedKey read too', () => {
      const v = scanCode(`
        useQuery({
          queryKey: locationScopedKey(['stock-movements', page], scope),
          queryFn: () => fetch('/stock-movements'),
          placeholderData: keepPreviousData,
        });
      `, 'inline.ts');
      expect(v).toHaveLength(1);
    });

    it('approves it when the file pairs the read with usePlaceholderScopeGuard', () => {
      const v = scanCode(`
        const { data, isPlaceholderData } = useQuery({
          queryKey: tenantScopedKey(['payments', page]),
          queryFn: () => fetch('/payments'),
          placeholderData: keepPreviousData,
        });
        const isStaleScopeData = usePlaceholderScopeGuard(isPlaceholderData, data !== undefined);
      `, 'inline.ts');
      expect(v).toEqual([]);
    });

    it('flags an inline placeholder function the same way as keepPreviousData', () => {
      const v = scanCode(`
        useQuery({
          queryKey: tenantScopedKey(['payments', page]),
          queryFn: () => fetch('/payments'),
          placeholderData: (previous) => previous,
        });
      `, 'inline.ts');
      expect(v).toHaveLength(1);
    });

    it('does not flag a scoped read without placeholderData', () => {
      const v = scanCode(`
        useQuery({
          queryKey: tenantScopedKey(['payments', page]),
          queryFn: () => fetch('/payments'),
        });
      `, 'inline.ts');
      expect(v).toEqual([]);
    });

    it('does not double-report when the key itself is already unscoped', () => {
      const v = scanCode(`
        useQuery({
          queryKey: ['payments', page],
          queryFn: () => fetch('/payments'),
          placeholderData: keepPreviousData,
        });
      `, 'inline.ts');
      expect(v).toHaveLength(1);
      expect(v[0].reason).not.toContain('usePlaceholderScopeGuard');
    });
  });
  /**
   * Gate-C-hardening lane (independent gate report 2026-09-04, MAJOR-A): the
   * first cut of the pairing rule had four false-negative classes. One fixture
   * per class lives in `tools/__fixtures__/audit-tanstack-keys/placeholder-pairing/`
   * and each of them produced ZERO findings before this lane.
   */
  describe('placeholderData pairing — hardened (MAJOR-A false-negative classes)', () => {
    it('class 1: flags an identifier-scoped key (currentCompanyId) with placeholderData and no guard', () => {
      const v = scanFixture('identifier-scoped-unguarded.ts');
      expect(v).toHaveLength(1);
      expect(v[0].reason).toContain('usePlaceholderScopeGuard');
      expect(v[0].factory).toBe('useQuery');
    });

    it('class 1b: flags a companyStore.currentCompanyId-scoped key the same way', () => {
      const v = scanFixture('store-object-scoped-unguarded.ts');
      expect(v).toHaveLength(1);
      expect(v[0].reason).toContain('usePlaceholderScopeGuard');
    });

    it('class 2: flags a useQueries entry that carries placeholderData', () => {
      const v = scanFixture('use-queries-entry-unguarded.ts');
      expect(v).toHaveLength(1);
      expect(v[0].reason).toContain('usePlaceholderScopeGuard');
      expect(v[0].factory).toBe('useQueries.queries[]');
    });

    it('class 3: a comment / string / dead-import mention of the guard does NOT count as pairing', () => {
      const v = scanFixture('comment-mention-only.ts');
      expect(v).toHaveLength(1);
      expect(v[0].reason).toContain('usePlaceholderScopeGuard');
    });

    it('class 4: two scoped reads, one guarded, reports exactly the unguarded one', () => {
      const v = scanFixture('two-reads-one-guarded.ts');
      expect(v).toHaveLength(1);
      expect(v[0].enclosing_symbol).toBe('UnguardedList');
      expect(v[0].statement_fingerprint).toContain('receipts');
    });

    it('stays silent on every legitimate pairing shape', () => {
      expect(scanFixture('paired-variants.ts')).toEqual([]);
    });
  });

  describe('placeholderData pairing — per-call-site linkage', () => {
    it('does not accept a guard wired to a DIFFERENT read in the same function', () => {
      const v = scanCode(`
        function Page() {
          const { data: a, isPlaceholderData: aPending } = useQuery({
            queryKey: tenantScopedKey(['a', page]),
            queryFn: fa,
            placeholderData: keepPreviousData,
          });
          const { data: b } = useQuery({
            queryKey: tenantScopedKey(['b', page]),
            queryFn: fb,
            placeholderData: keepPreviousData,
          });
          const stale = usePlaceholderScopeGuard(aPending, a !== undefined);
          return [stale, b];
        }
      `, 'inline.tsx');
      expect(v).toHaveLength(1);
      expect(v[0].statement_fingerprint).toContain("'b'");
    });

    it('flags a placeholder read whose result is never bound (no way to pair it)', () => {
      const v = scanCode(`
        function Page() {
          useQuery({
            queryKey: tenantScopedKey(['a', page]),
            queryFn: fa,
            placeholderData: keepPreviousData,
          });
          const stale = usePlaceholderScopeGuard(somethingElse, true);
          return stale;
        }
      `, 'inline.tsx');
      expect(v).toHaveLength(1);
    });

    it('accepts a guard consumed through the read\'s `enabled`/render gate in a nested callback', () => {
      const v = scanCode(`
        function Page() {
          const { data, isPlaceholderData } = useQuery({
            queryKey: tenantScopedKey(['a', page]),
            queryFn: fa,
            placeholderData: keepPreviousData,
          });
          const rows = useMemo(() => {
            const stale = usePlaceholderScopeGuard(isPlaceholderData, data !== undefined);
            return stale ? [] : data;
          }, [data, isPlaceholderData]);
          return rows;
        }
      `, 'inline.tsx');
      expect(v).toEqual([]);
    });
  });
});
