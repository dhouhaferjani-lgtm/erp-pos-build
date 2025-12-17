/**
 * Storage Limits Service
 *
 * Manages storage capacity constraints for offline operations.
 * Prevents exceeding AsyncStorage limits and provides warnings.
 *
 * LIMITS:
 * - 500 products per draft (warn at 400)
 * - 2,000 total pending operations
 * - 50 drafts maximum
 */

export interface StorageUsage {
  drafts: {
    count: number;
    limit: number;
    usage: number; // 0-1 scale
  };
  productsPerDraft: {
    max: number;
    limit: number;
    usage: number; // 0-1 scale
  };
  pendingOperations: {
    count: number;
    limit: number;
    usage: number; // 0-1 scale
  };
  totalUsage: number; // 0-1 scale (highest of all)
  estimatedSize: {
    bytes: number;
    megabytes: number;
  };
}

export interface StorageWarning {
  type: 'warning' | 'error';
  category: 'drafts' | 'products' | 'pending';
  message: string;
  action?: string;
}

// Storage limits (conservative for AsyncStorage)
const LIMITS = {
  MAX_DRAFTS: 50,
  MAX_PRODUCTS_PER_DRAFT: 500,
  WARN_PRODUCTS_PER_DRAFT: 400,
  MAX_PENDING_OPERATIONS: 2000,
  WARN_PENDING_OPERATIONS: 1600,
  MAX_STORAGE_MB: 5, // Conservative for Android
} as const;

// Size estimates (bytes)
const SIZE_ESTIMATES = {
  DRAFT_BASE: 500, // Base draft object
  PRODUCT_UUID: 36, // UUID string
  PENDING_COUNT: 250, // Average pending count
} as const;

export class StorageLimitsService {
  /**
   * Calculate current storage usage
   */
  calculateUsage(
    drafts: Array<{ productIds: string[] }>,
    pendingCounts: Array<any>
  ): StorageUsage {
    const draftCount = drafts.length;
    const maxProductsInAnyDraft = Math.max(
      0,
      ...drafts.map(d => d.productIds.length)
    );
    const pendingCount = pendingCounts.length;

    // Calculate usage percentages
    const draftUsage = draftCount / LIMITS.MAX_DRAFTS;
    const productUsage = maxProductsInAnyDraft / LIMITS.MAX_PRODUCTS_PER_DRAFT;
    const pendingUsage = pendingCount / LIMITS.MAX_PENDING_OPERATIONS;

    // Estimate storage size
    const draftSize = draftCount * SIZE_ESTIMATES.DRAFT_BASE;
    const productSize = drafts.reduce(
      (sum, d) => sum + d.productIds.length * SIZE_ESTIMATES.PRODUCT_UUID,
      0
    );
    const pendingSize = pendingCount * SIZE_ESTIMATES.PENDING_COUNT;
    const totalBytes = draftSize + productSize + pendingSize;

    return {
      drafts: {
        count: draftCount,
        limit: LIMITS.MAX_DRAFTS,
        usage: draftUsage,
      },
      productsPerDraft: {
        max: maxProductsInAnyDraft,
        limit: LIMITS.MAX_PRODUCTS_PER_DRAFT,
        usage: productUsage,
      },
      pendingOperations: {
        count: pendingCount,
        limit: LIMITS.MAX_PENDING_OPERATIONS,
        usage: pendingUsage,
      },
      totalUsage: Math.max(draftUsage, productUsage, pendingUsage),
      estimatedSize: {
        bytes: totalBytes,
        megabytes: totalBytes / (1024 * 1024),
      },
    };
  }

  /**
   * Get all active warnings
   */
  getWarnings(usage: StorageUsage): StorageWarning[] {
    const warnings: StorageWarning[] = [];

    // Draft count warnings
    if (usage.drafts.usage >= 1) {
      warnings.push({
        type: 'error',
        category: 'drafts',
        message: `Maximum drafts reached (${usage.drafts.count}/${usage.drafts.limit})`,
        action: 'Delete old drafts or activate them',
      });
    } else if (usage.drafts.usage >= 0.8) {
      warnings.push({
        type: 'warning',
        category: 'drafts',
        message: `Approaching draft limit (${usage.drafts.count}/${usage.drafts.limit})`,
        action: 'Consider activating or deleting drafts',
      });
    }

    // Products per draft warnings
    if (usage.productsPerDraft.usage >= 1) {
      warnings.push({
        type: 'error',
        category: 'products',
        message: `Maximum products in a draft (${usage.productsPerDraft.max}/${usage.productsPerDraft.limit})`,
        action: 'Split into multiple drafts or activate this one',
      });
    } else if (
      usage.productsPerDraft.max >= LIMITS.WARN_PRODUCTS_PER_DRAFT
    ) {
      warnings.push({
        type: 'warning',
        category: 'products',
        message: `Large draft detected (${usage.productsPerDraft.max} products)`,
        action: 'Consider activating soon',
      });
    }

    // Pending operations warnings
    if (usage.pendingOperations.usage >= 1) {
      warnings.push({
        type: 'error',
        category: 'pending',
        message: `Maximum pending operations (${usage.pendingOperations.count}/${usage.pendingOperations.limit})`,
        action: 'Wait for sync to complete',
      });
    } else if (
      usage.pendingOperations.count >= LIMITS.WARN_PENDING_OPERATIONS
    ) {
      warnings.push({
        type: 'warning',
        category: 'pending',
        message: `High pending operations (${usage.pendingOperations.count})`,
        action: 'Ensure stable network connection',
      });
    }

    return warnings;
  }

  /**
   * Check if a new draft can be created
   */
  canCreateDraft(usage: StorageUsage): { allowed: boolean; reason?: string } {
    if (usage.drafts.usage >= 1) {
      return {
        allowed: false,
        reason: `Maximum drafts reached (${usage.drafts.limit}). Delete or activate existing drafts.`,
      };
    }

    if (usage.totalUsage >= 0.95) {
      return {
        allowed: false,
        reason: 'Storage nearly full. Clean up old data before creating more drafts.',
      };
    }

    return { allowed: true };
  }

  /**
   * Check if a product can be added to a draft
   */
  canAddProduct(
    draftProductCount: number
  ): { allowed: boolean; reason?: string } {
    if (draftProductCount >= LIMITS.MAX_PRODUCTS_PER_DRAFT) {
      return {
        allowed: false,
        reason: `Maximum products per draft (${LIMITS.MAX_PRODUCTS_PER_DRAFT}). Create a new draft or activate this one.`,
      };
    }

    if (draftProductCount >= LIMITS.WARN_PRODUCTS_PER_DRAFT) {
      return {
        allowed: true,
        reason: `Warning: Draft is large (${draftProductCount} products). Consider activating soon.`,
      };
    }

    return { allowed: true };
  }

  /**
   * Clean up old sync errors (>24 hours)
   */
  shouldCleanupErrors(errorTimestamp: Date): boolean {
    const twentyFourHoursAgo = new Date();
    twentyFourHoursAgo.setHours(twentyFourHoursAgo.getHours() - 24);
    return errorTimestamp < twentyFourHoursAgo;
  }

  /**
   * Get storage health status
   */
  getHealthStatus(usage: StorageUsage): {
    status: 'healthy' | 'warning' | 'critical';
    message: string;
  } {
    if (usage.totalUsage >= 1) {
      return {
        status: 'critical',
        message: 'Storage limit reached. Clean up required.',
      };
    }

    if (usage.totalUsage >= 0.8) {
      return {
        status: 'warning',
        message: 'Storage usage high. Consider cleaning up.',
      };
    }

    return {
      status: 'healthy',
      message: 'Storage usage normal.',
    };
  }
}

// Singleton instance
let instance: StorageLimitsService | null = null;

export function getStorageLimitsService(): StorageLimitsService {
  if (!instance) {
    instance = new StorageLimitsService();
  }
  return instance;
}

// React hook for storage monitoring
import { useMemo } from 'react';
import { useDraftCountingStore } from '../store/draftCountingStore';
import { useCountingStore } from '../store/countingStore';

export function useStorageLimits() {
  const drafts = useDraftCountingStore(s => s.drafts);
  const pendingCounts = useCountingStore(s => s.pendingCounts);
  const service = useMemo(() => getStorageLimitsService(), []);

  const usage = useMemo(
    () => service.calculateUsage(drafts, pendingCounts),
    [service, drafts, pendingCounts]
  );

  const warnings = useMemo(() => service.getWarnings(usage), [service, usage]);

  const health = useMemo(
    () => service.getHealthStatus(usage),
    [service, usage]
  );

  return {
    usage,
    warnings,
    health,
    canCreateDraft: () => service.canCreateDraft(usage),
    canAddProduct: (draftProductCount: number) =>
      service.canAddProduct(draftProductCount),
  };
}
