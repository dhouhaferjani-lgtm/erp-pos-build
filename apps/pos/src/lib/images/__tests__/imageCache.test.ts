import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock Tauri APIs before importing the module
vi.mock('@tauri-apps/plugin-fs', () => ({
  writeFile: vi.fn().mockResolvedValue(undefined),
  mkdir: vi.fn().mockResolvedValue(undefined),
  exists: vi.fn().mockResolvedValue(true),
  remove: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@tauri-apps/api/path', () => ({
  appDataDir: vi.fn().mockResolvedValue('/mock/app/data'),
}));

vi.mock('@tauri-apps/api/core', () => ({
  convertFileSrc: vi.fn((path: string) => `https://asset.localhost/${path}`),
}));

import {
  initImageCache,
  getLocalImagePath,
  enqueueDownload,
  getDownloadQueueSize,
  processDownloadQueue,
  _resetForTesting,
} from '../imageCache';

function makeMockDb() {
  return {
    select: vi.fn().mockResolvedValue([]),
    execute: vi.fn().mockResolvedValue(undefined),
  } as unknown as import('@tauri-apps/plugin-sql').default;
}

describe('imageCache', () => {
  let db: ReturnType<typeof makeMockDb>;

  beforeEach(() => {
    vi.clearAllMocks();
    _resetForTesting();
    db = makeMockDb();
  });

  describe('initImageCache', () => {
    it('loads manifest entries into in-memory map', async () => {
      vi.mocked(db.select as ReturnType<typeof vi.fn>).mockResolvedValue([
        { product_id: 'p1', local_path: '/data/images/p1.jpg' },
        { product_id: 'p2', local_path: '/data/images/p2.png' },
      ]);

      await initImageCache(db);

      expect(getLocalImagePath('p1')).toBe(
        'https://asset.localhost//data/images/p1.jpg',
      );
      expect(getLocalImagePath('p2')).toBe(
        'https://asset.localhost//data/images/p2.png',
      );
    });

    it('returns null for uncached products', async () => {
      await initImageCache(db);

      expect(getLocalImagePath('nonexistent')).toBeNull();
    });
  });

  describe('getLocalImagePath', () => {
    it('returns null when cache is empty', () => {
      expect(getLocalImagePath('p1')).toBeNull();
    });
  });

  describe('enqueueDownload', () => {
    it('adds an item to the download queue', () => {
      enqueueDownload('p1', 'https://example.com/img.jpg', vi.fn());

      expect(getDownloadQueueSize()).toBe(1);
    });

    it('deduplicates by productId', () => {
      enqueueDownload('p1', 'https://example.com/img.jpg', vi.fn());
      enqueueDownload('p1', 'https://example.com/img.jpg', vi.fn());

      expect(getDownloadQueueSize()).toBe(1);
    });

    it('tracks separate products independently', () => {
      enqueueDownload('p1', 'https://example.com/img1.jpg', vi.fn());
      enqueueDownload('p2', 'https://example.com/img2.jpg', vi.fn());

      expect(getDownloadQueueSize()).toBe(2);
    });

    it('returns an unsubscribe function that removes the callback', () => {
      const cb = vi.fn();
      const unsub = enqueueDownload('p1', 'https://example.com/img.jpg', cb);

      expect(getDownloadQueueSize()).toBe(1);

      unsub();

      // When the only callback is removed, the queue entry is cleaned up
      expect(getDownloadQueueSize()).toBe(0);
    });

    it('keeps queue entry when unsubscribing one of multiple callbacks', () => {
      const cb1 = vi.fn();
      const cb2 = vi.fn();
      const unsub1 = enqueueDownload('p1', 'https://example.com/img.jpg', cb1);
      enqueueDownload('p1', 'https://example.com/img.jpg', cb2);

      unsub1();

      // Still has one callback, so entry stays
      expect(getDownloadQueueSize()).toBe(1);
    });
  });

  describe('processDownloadQueue', () => {
    it('does nothing when queue is empty', async () => {
      await processDownloadQueue(db);

      expect(db.execute).not.toHaveBeenCalled();
    });

    it('downloads images and updates manifest', async () => {
      const mockResponse = new Response(new Uint8Array([0xff, 0xd8, 0xff]), {
        status: 200,
        headers: { etag: '"abc123"' },
      });
      vi.spyOn(globalThis, 'fetch').mockResolvedValue(mockResponse);

      const onComplete = vi.fn();
      enqueueDownload('p1', 'https://example.com/product.jpg', onComplete);

      await processDownloadQueue(db);

      // Should have written the manifest to DB
      expect(db.execute).toHaveBeenCalledWith(
        expect.stringContaining('INSERT OR REPLACE INTO product_images'),
        expect.arrayContaining(['p1', 'https://example.com/product.jpg']),
      );

      // Should notify the callback
      expect(onComplete).toHaveBeenCalledWith(
        expect.stringContaining('https://asset.localhost/'),
      );

      // Queue should be empty after processing
      expect(getDownloadQueueSize()).toBe(0);

      // In-memory map should be updated
      expect(getLocalImagePath('p1')).not.toBeNull();

      vi.restoreAllMocks();
    });

    it('removes failed downloads from queue without crashing', async () => {
      vi.spyOn(globalThis, 'fetch').mockResolvedValue(
        new Response(null, { status: 404 }),
      );

      const onComplete = vi.fn();
      enqueueDownload('p1', 'https://example.com/missing.jpg', onComplete);

      await processDownloadQueue(db);

      expect(onComplete).not.toHaveBeenCalled();
      expect(getDownloadQueueSize()).toBe(0);

      vi.restoreAllMocks();
    });
  });
});
