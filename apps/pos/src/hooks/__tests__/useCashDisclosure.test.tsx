/**
 * B-13 (iii) — policy-at-mount plumbing.
 *
 * The Header renders one term of the drawer expectation (the opening float) on
 * every route, so it needs the blind-count answer at mount rather than at
 * End-of-Day. These cases pin the fail-closed behaviour of that plumbing;
 * `cashDisclosurePolicy.test.ts` pins the resolution rules themselves.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';

const mocks = vi.hoisted(() => ({
  resolveCashDisclosure: vi.fn(),
  getDatabase: vi.fn(),
}));

vi.mock('@/lib/offline/cashDisclosurePolicy', () => ({
  resolveCashDisclosure: mocks.resolveCashDisclosure,
}));
vi.mock('@/lib/db', () => ({ getDatabase: mocks.getDatabase }));

import { useCashDisclosure } from '../useCashDisclosure';

describe('useCashDisclosure', () => {
  beforeEach(() => {
    mocks.resolveCashDisclosure.mockReset();
    mocks.getDatabase.mockReset();
    mocks.getDatabase.mockResolvedValue({});
  });

  it('starts CONCEALED before the policy has answered', () => {
    mocks.resolveCashDisclosure.mockReturnValue(new Promise(() => {}));
    const { result } = renderHook(() => useCashDisclosure('co-1'));
    expect(result.current).toBe('conceal');
  });

  it('discloses once the policy positively reads NOT blind', async () => {
    mocks.resolveCashDisclosure.mockResolvedValue('disclose');
    const { result } = renderHook(() => useCashDisclosure('co-1'));
    await waitFor(() => expect(result.current).toBe('disclose'));
    expect(mocks.resolveCashDisclosure).toHaveBeenCalledWith({}, 'co-1');
  });

  it('stays concealed when the policy resolves to conceal', async () => {
    mocks.resolveCashDisclosure.mockResolvedValue('conceal');
    const { result } = renderHook(() => useCashDisclosure('co-1'));
    await waitFor(() => expect(mocks.resolveCashDisclosure).toHaveBeenCalled());
    expect(result.current).toBe('conceal');
  });

  it('conceals when the device database will not open', async () => {
    mocks.getDatabase.mockRejectedValue(new Error('locked'));
    const { result } = renderHook(() => useCashDisclosure('co-1'));
    await waitFor(() => expect(mocks.getDatabase).toHaveBeenCalled());
    expect(result.current).toBe('conceal');
  });

  it('never resolves a policy with no company, and conceals', () => {
    const { result } = renderHook(() => useCashDisclosure(null));
    expect(result.current).toBe('conceal');
    expect(mocks.resolveCashDisclosure).not.toHaveBeenCalled();
  });

  it('RE-ARMS to conceal on a company switch before the new read lands', async () => {
    mocks.resolveCashDisclosure.mockResolvedValue('disclose');
    const { result, rerender } = renderHook(
      ({ companyId }: { companyId: string }) => useCashDisclosure(companyId),
      { initialProps: { companyId: 'co-1' } },
    );
    await waitFor(() => expect(result.current).toBe('disclose'));

    // The second company's policy never answers — the first company's
    // "disclose" must not carry across.
    mocks.resolveCashDisclosure.mockReturnValue(new Promise(() => {}));
    rerender({ companyId: 'co-2' });
    expect(result.current).toBe('conceal');
  });
});
