import { useState, useEffect } from 'react';
import { getLocalImagePath, enqueueDownload } from './imageCache';

/**
 * Hook that returns a local cached image URL for a product,
 * or enqueues a background download if not yet cached.
 *
 * Returns the local asset URL if cached, null otherwise.
 * The component re-renders once the download completes.
 */
export function useProductImage(
  productId: string,
  remoteUrl?: string | null,
): string | null {
  const [localPath, setLocalPath] = useState<string | null>(() =>
    getLocalImagePath(productId),
  );

  useEffect(() => {
    if (localPath || !remoteUrl) return;

    const unsubscribe = enqueueDownload(productId, remoteUrl, (path) => {
      setLocalPath(path);
    });

    return unsubscribe;
  }, [productId, remoteUrl, localPath]);

  return localPath;
}
