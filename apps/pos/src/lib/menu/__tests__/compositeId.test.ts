import { describe, it, expect } from 'vitest';

import {
  buildMenuCompositeId,
  parseMenuCompositeId,
} from '@/lib/menu/compositeId';

describe('buildMenuCompositeId', () => {
  it('joins sellable_id and category_id with a colon delimiter', () => {
    const composite = buildMenuCompositeId(
      '11111111-1111-1111-1111-111111111111',
      '22222222-2222-2222-2222-222222222222',
    );
    expect(composite).toBe(
      '11111111-1111-1111-1111-111111111111_22222222-2222-2222-2222-222222222222',
    );
  });
});

describe('parseMenuCompositeId', () => {
  it('round-trips a built composite back to the same parts', () => {
    const sellableId = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    const categoryId = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
    const composite = buildMenuCompositeId(sellableId, categoryId);

    const parsed = parseMenuCompositeId(composite);

    expect(parsed.sellableId).toBe(sellableId);
    expect(parsed.categoryId).toBe(categoryId);
  });

  it('treats a bare UUID (no colon) as a legacy / standard-retail id with null category', () => {
    const bare = 'cccccccc-cccc-cccc-cccc-cccccccccccc';

    const parsed = parseMenuCompositeId(bare);

    expect(parsed.sellableId).toBe(bare);
    expect(parsed.categoryId).toBeNull();
  });

  it('returns null categoryId when the input has more than two parts (defensive)', () => {
    // Defensive — UUIDs do not contain underscores. If a malformed value
    // ever reaches the parser, fall back to "treat as legacy bare id"
    // rather than throwing, so a corrupt cart line does not crash the
    // cashier UI.
    const malformed = 'a_b_c';

    const parsed = parseMenuCompositeId(malformed);

    expect(parsed.sellableId).toBe(malformed);
    expect(parsed.categoryId).toBeNull();
  });

  it('treats an empty string as a legacy id with null category', () => {
    const parsed = parseMenuCompositeId('');

    expect(parsed.sellableId).toBe('');
    expect(parsed.categoryId).toBeNull();
  });

  it('Codex r3 P2: composite IDs are filename-safe (no `:` characters that break Windows image-cache filenames)', () => {
    // The image cache writes files as `${productId}.${ext}` at
    // apps/pos/src/lib/images/imageCache.ts:172. Windows forbids `:`
    // in filenames. UUIDs are `[0-9a-f-]+` so a delimiter outside that
    // alphabet (and outside Windows-forbidden chars) keeps both the
    // parser unambiguous and the filename writeable.
    const composite = buildMenuCompositeId(
      'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
      'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
    );
    // Forbidden Windows filename characters: \ / : * ? " < > |
    expect(composite).not.toMatch(/[\\/:*?"<>|]/);
  });
});
