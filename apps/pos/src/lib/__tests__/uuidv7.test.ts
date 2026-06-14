import { afterEach, describe, expect, it, vi } from 'vitest';
import { uuidv7 } from '@/lib/uuidv7';

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

describe('uuidv7', () => {
  afterEach(() => {
    vi.useRealTimers();
  });

  it('produces a canonical lowercase hyphenated UUID', () => {
    expect(uuidv7()).toMatch(UUID_RE);
  });

  it('sets the version nibble to 7', () => {
    // 13th hex char (index 14, after two hyphens) is the version.
    const id = uuidv7();
    expect(id[14]).toBe('7');
  });

  it('sets the RFC 4122 variant (10xx → 8/9/a/b)', () => {
    const id = uuidv7();
    expect(['8', '9', 'a', 'b']).toContain(id[19]);
  });

  it('is unique across many calls', () => {
    const seen = new Set<string>();
    for (let i = 0; i < 2000; i++) seen.add(uuidv7());
    expect(seen.size).toBe(2000);
  });

  it('embeds the unix-millisecond timestamp so ids are time-ordered', () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-06-14T00:00:00.000Z'));
    const earlier = uuidv7();
    vi.setSystemTime(new Date('2026-06-14T00:00:01.000Z'));
    const later = uuidv7();
    // Lexicographic order matches chronological order (the leading 48 bits
    // are the big-endian millisecond timestamp).
    expect(earlier < later).toBe(true);
  });
});
