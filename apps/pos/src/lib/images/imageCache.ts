import type Database from '@tauri-apps/plugin-sql';

interface ImageManifestEntry {
  product_id: string;
  remote_url: string;
  local_path: string;
  etag: string | null;
  downloaded_at: string;
}

interface DownloadQueueItem {
  productId: string;
  remoteUrl: string;
  callbacks: Array<(localPath: string) => void>;
}

/** In-memory cache: productId -> asset URL (convertFileSrc result) */
const imageMap = new Map<string, string>();

/** Download queue: productId -> queue item */
const downloadQueue = new Map<string, DownloadQueueItem>();

/** Whether a download batch is currently running */
let isProcessing = false;

/**
 * Load all cached image manifest entries from SQLite into the in-memory Map.
 * Called once at app startup.
 */
export async function initImageCache(db: Database): Promise<void> {
  try {
    const rows = await db.select<ImageManifestEntry[]>(
      'SELECT product_id, local_path FROM product_images',
    );

    let convertFileSrc: ((path: string) => string) | null = null;
    try {
      const core = await import('@tauri-apps/api/core');
      convertFileSrc = core.convertFileSrc;
    } catch {
      // Not in Tauri environment
    }

    for (const row of rows) {
      const assetUrl = convertFileSrc
        ? convertFileSrc(row.local_path)
        : row.local_path;
      imageMap.set(row.product_id, assetUrl);
    }
  } catch {
    // Non-critical: image cache init failure doesn't break the app
  }
}

/**
 * Synchronous lookup of a cached image for a product.
 * Returns an asset URL the WebView can load, or null if not cached.
 */
export function getLocalImagePath(productId: string): string | null {
  return imageMap.get(productId) ?? null;
}

/**
 * Add a product image to the download queue.
 * Deduplicates by productId — multiple callers for the same product
 * will all be notified when the download completes.
 *
 * Returns an unsubscribe function to remove the callback.
 */
export function enqueueDownload(
  productId: string,
  remoteUrl: string,
  onComplete: (localPath: string) => void,
): () => void {
  const existing = downloadQueue.get(productId);
  if (existing) {
    existing.callbacks.push(onComplete);
    return () => {
      const idx = existing.callbacks.indexOf(onComplete);
      if (idx !== -1) existing.callbacks.splice(idx, 1);
    };
  }

  const item: DownloadQueueItem = {
    productId,
    remoteUrl,
    callbacks: [onComplete],
  };
  downloadQueue.set(productId, item);

  return () => {
    const current = downloadQueue.get(productId);
    if (!current) return;
    const idx = current.callbacks.indexOf(onComplete);
    if (idx !== -1) current.callbacks.splice(idx, 1);
    if (current.callbacks.length === 0) {
      downloadQueue.delete(productId);
    }
  };
}

/**
 * Process pending image downloads in batches.
 * Downloads images, saves to Tauri appDataDir/images/products/,
 * updates SQLite manifest, and notifies waiting callers.
 */
export async function processDownloadQueue(db: Database): Promise<void> {
  if (isProcessing || downloadQueue.size === 0) return;
  isProcessing = true;

  try {
    let writeFile: ((path: string, data: Uint8Array, options?: { baseDir?: number }) => Promise<void>) | null = null;
    let mkdir: ((path: string, options?: { baseDir?: number; recursive?: boolean }) => Promise<void>) | null = null;
    let exists: ((path: string, options?: { baseDir?: number }) => Promise<boolean>) | null = null;
    let appDataDirFn: (() => Promise<string>) | null = null;
    let convertFileSrc: ((path: string) => string) | null = null;

    try {
      const fs = await import('@tauri-apps/plugin-fs');
      writeFile = fs.writeFile as unknown as (path: string, data: Uint8Array, options?: { baseDir?: number }) => Promise<void>;
      mkdir = fs.mkdir as unknown as (path: string, options?: { baseDir?: number; recursive?: boolean }) => Promise<void>;
      exists = fs.exists as unknown as (path: string, options?: { baseDir?: number }) => Promise<boolean>;
    } catch {
      isProcessing = false;
      return; // No fs plugin = no caching
    }

    try {
      const pathModule = await import('@tauri-apps/api/path');
      appDataDirFn = pathModule.appDataDir;
    } catch {
      isProcessing = false;
      return;
    }

    try {
      const core = await import('@tauri-apps/api/core');
      convertFileSrc = core.convertFileSrc;
    } catch {
      isProcessing = false;
      return;
    }

    const appData = await appDataDirFn();
    const imagesDir = `${appData}/images/products`;

    // Ensure directory exists
    try {
      const dirExists = await exists(imagesDir);
      if (!dirExists) {
        await mkdir(imagesDir, { recursive: true });
      }
    } catch {
      await mkdir(imagesDir, { recursive: true });
    }

    // Take a batch of up to 10 items
    const batch = Array.from(downloadQueue.entries()).slice(0, 10);

    for (const [productId, item] of batch) {
      try {
        // Determine file extension from URL
        const ext = getExtensionFromUrl(item.remoteUrl) || 'jpg';
        const fileName = `${productId}.${ext}`;
        const filePath = `${imagesDir}/${fileName}`;

        // Download the image
        const response = await fetch(item.remoteUrl);
        if (!response.ok) {
          downloadQueue.delete(productId);
          continue;
        }

        const arrayBuffer = await response.arrayBuffer();
        const data = new Uint8Array(arrayBuffer);

        // Write to filesystem
        await writeFile(filePath, data);

        // Get the etag if available
        const etag = response.headers.get('etag');

        // Update SQLite manifest
        await db.execute(
          `INSERT OR REPLACE INTO product_images (product_id, remote_url, local_path, etag, downloaded_at)
           VALUES (?, ?, ?, ?, ?)`,
          [productId, item.remoteUrl, filePath, etag, new Date().toISOString()],
        );

        // Update in-memory map
        const assetUrl = convertFileSrc(filePath);
        imageMap.set(productId, assetUrl);

        // Notify callbacks
        for (const cb of item.callbacks) {
          try {
            cb(assetUrl);
          } catch {
            // Callback errors are non-critical
          }
        }

        downloadQueue.delete(productId);
      } catch {
        // Single image failure: remove from queue, continue with others
        downloadQueue.delete(productId);
      }
    }
  } finally {
    isProcessing = false;
  }
}

/**
 * Remove cached images for products no longer in the catalog.
 */
export async function cleanupOrphanedImages(
  db: Database,
  currentProductIds: string[],
): Promise<void> {
  if (currentProductIds.length === 0) return;

  try {
    let remove: ((path: string) => Promise<void>) | null = null;
    try {
      const fs = await import('@tauri-apps/plugin-fs');
      remove = fs.remove as unknown as (path: string) => Promise<void>;
    } catch {
      return;
    }

    // Find orphaned entries
    const placeholders = currentProductIds.map(() => '?').join(',');
    const orphaned = await db.select<ImageManifestEntry[]>(
      `SELECT product_id, local_path FROM product_images WHERE product_id NOT IN (${placeholders})`,
      currentProductIds,
    );

    for (const entry of orphaned) {
      try {
        await remove(entry.local_path);
      } catch {
        // File may already be deleted
      }
      imageMap.delete(entry.product_id);
    }

    if (orphaned.length > 0) {
      await db.execute(
        `DELETE FROM product_images WHERE product_id NOT IN (${placeholders})`,
        currentProductIds,
      );
    }
  } catch {
    // Non-critical cleanup
  }
}

/**
 * Extract file extension from a URL.
 */
function getExtensionFromUrl(url: string): string | null {
  try {
    const pathname = new URL(url).pathname;
    const lastDot = pathname.lastIndexOf('.');
    if (lastDot === -1) return null;
    const ext = pathname.slice(lastDot + 1).toLowerCase();
    // Only allow image extensions
    if (['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'].includes(ext)) {
      return ext;
    }
    return null;
  } catch {
    return null;
  }
}

/**
 * Get the current download queue size (for testing/debugging).
 */
export function getDownloadQueueSize(): number {
  return downloadQueue.size;
}

/**
 * Clear all in-memory state (for testing).
 */
export function _resetForTesting(): void {
  imageMap.clear();
  downloadQueue.clear();
  isProcessing = false;
}
