// @ts-check
import { describe, it, expect } from 'vitest';

import { scanCode } from '../audit-tanstack-keys.mjs';

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
});
