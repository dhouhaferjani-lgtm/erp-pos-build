// @ts-check
import { describe, it, expect } from 'vitest';

import { partitionViolationsByBaseline, scanCode } from '../audit-tanstack-keys.mjs';

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
    it('flags unscoped queryClient.invalidateQueries', () => {
      const v = scanCode(`
        queryClient.invalidateQueries({ queryKey: ['users'] });
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
              queryClient.invalidateQueries({ queryKey: ['orders'] });
            },
          });
          useMutation({
            mutationFn: () => g(),
            onSuccess: () => {
              queryClient.invalidateQueries({ queryKey: ['orders'] });
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
});
