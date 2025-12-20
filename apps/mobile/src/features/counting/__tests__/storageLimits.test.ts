/**
 * Storage Limits Service Tests
 *
 * Regression tests for storage capacity monitoring and warnings.
 * Ensures AsyncStorage limits are not exceeded and users get warnings.
 */

import { StorageLimitsService } from '../services/storageLimits';

describe('Storage Limits Service', () => {
  let service: StorageLimitsService;

  beforeEach(() => {
    service = new StorageLimitsService();
  });

  describe('Usage Calculation', () => {
    it('should calculate zero usage for empty state', () => {
      const usage = service.calculateUsage([], []);

      expect(usage.drafts.count).toBe(0);
      expect(usage.drafts.usage).toBe(0);
      expect(usage.productsPerDraft.max).toBe(0);
      expect(usage.productsPerDraft.usage).toBe(0);
      expect(usage.pendingOperations.count).toBe(0);
      expect(usage.pendingOperations.usage).toBe(0);
      expect(usage.totalUsage).toBe(0);
    });

    it('should calculate draft count usage', () => {
      const drafts = Array(10).fill(null).map(() => ({ productIds: [] }));
      const usage = service.calculateUsage(drafts, []);

      expect(usage.drafts.count).toBe(10);
      expect(usage.drafts.limit).toBe(50);
      expect(usage.drafts.usage).toBe(0.2); // 10/50 = 0.2
    });

    it('should calculate maximum products per draft', () => {
      const drafts = [
        { productIds: Array(100).fill('uuid') },
        { productIds: Array(250).fill('uuid') },
        { productIds: Array(50).fill('uuid') },
      ];
      const usage = service.calculateUsage(drafts, []);

      expect(usage.productsPerDraft.max).toBe(250);
      expect(usage.productsPerDraft.limit).toBe(500);
      expect(usage.productsPerDraft.usage).toBe(0.5); // 250/500 = 0.5
    });

    it('should calculate pending operations usage', () => {
      const pendingCounts = Array(800).fill({});
      const usage = service.calculateUsage([], pendingCounts);

      expect(usage.pendingOperations.count).toBe(800);
      expect(usage.pendingOperations.limit).toBe(2000);
      expect(usage.pendingOperations.usage).toBe(0.4); // 800/2000 = 0.4
    });

    it('should calculate total usage as maximum of all usages', () => {
      const drafts = [
        { productIds: Array(400).fill('uuid') }, // 80% of limit
      ];
      const pendingCounts = Array(500).fill({}); // 25% of limit
      const usage = service.calculateUsage(drafts, pendingCounts);

      // Total should be the highest usage
      expect(usage.totalUsage).toBe(0.8); // Max of 0.8, 0.25, etc
    });

    it('should estimate storage size in bytes and megabytes', () => {
      const drafts = [
        { productIds: Array(100).fill('uuid') },
        { productIds: Array(200).fill('uuid') },
      ];
      const pendingCounts = Array(50).fill({});
      const usage = service.calculateUsage(drafts, pendingCounts);

      expect(usage.estimatedSize.bytes).toBeGreaterThan(0);
      expect(usage.estimatedSize.megabytes).toBeGreaterThan(0);
      expect(usage.estimatedSize.megabytes).toBe(
        usage.estimatedSize.bytes / (1024 * 1024)
      );
    });
  });

  describe('Warning Generation', () => {
    it('should generate no warnings for low usage', () => {
      const drafts = [
        { productIds: Array(50).fill('uuid') },
      ];
      const usage = service.calculateUsage(drafts, []);
      const warnings = service.getWarnings(usage);

      expect(warnings).toHaveLength(0);
    });

    it('should generate warning for draft count at 80%', () => {
      const drafts = Array(40).fill(null).map(() => ({ productIds: [] }));
      const usage = service.calculateUsage(drafts, []);
      const warnings = service.getWarnings(usage);

      expect(warnings).toHaveLength(1);
      expect(warnings[0].type).toBe('warning');
      expect(warnings[0].category).toBe('drafts');
      expect(warnings[0].message).toContain('40/50');
    });

    it('should generate error for draft count at 100%', () => {
      const drafts = Array(50).fill(null).map(() => ({ productIds: [] }));
      const usage = service.calculateUsage(drafts, []);
      const warnings = service.getWarnings(usage);

      expect(warnings.some(w => w.type === 'error' && w.category === 'drafts')).toBe(true);
      expect(warnings[0].message).toContain('50/50');
    });

    it('should generate warning for large draft (400+ products)', () => {
      const drafts = [
        { productIds: Array(420).fill('uuid') },
      ];
      const usage = service.calculateUsage(drafts, []);
      const warnings = service.getWarnings(usage);

      expect(warnings).toHaveLength(1);
      expect(warnings[0].type).toBe('warning');
      expect(warnings[0].category).toBe('products');
      expect(warnings[0].message).toContain('420 products');
    });

    it('should generate error for draft at product limit', () => {
      const drafts = [
        { productIds: Array(500).fill('uuid') },
      ];
      const usage = service.calculateUsage(drafts, []);
      const warnings = service.getWarnings(usage);

      expect(warnings.some(w => w.type === 'error' && w.category === 'products')).toBe(true);
    });

    it('should generate warning for high pending operations', () => {
      const pendingCounts = Array(1700).fill({});
      const usage = service.calculateUsage([], pendingCounts);
      const warnings = service.getWarnings(usage);

      expect(warnings).toHaveLength(1);
      expect(warnings[0].type).toBe('warning');
      expect(warnings[0].category).toBe('pending');
    });

    it('should generate multiple warnings when multiple limits exceeded', () => {
      const drafts = Array(45).fill(null).map(() => ({
        productIds: Array(450).fill('uuid'),
      }));
      const pendingCounts = Array(1800).fill({});
      const usage = service.calculateUsage(drafts, pendingCounts);
      const warnings = service.getWarnings(usage);

      expect(warnings.length).toBeGreaterThan(1);
      expect(warnings.map(w => w.category)).toContain('drafts');
      expect(warnings.map(w => w.category)).toContain('products');
      expect(warnings.map(w => w.category)).toContain('pending');
    });

    it('should provide actionable guidance in warnings', () => {
      const drafts = Array(45).fill(null).map(() => ({ productIds: [] }));
      const usage = service.calculateUsage(drafts, []);
      const warnings = service.getWarnings(usage);

      expect(warnings[0].action).toBeDefined();
      expect(warnings[0].action?.toLowerCase()).toContain('activating');
    });
  });

  describe('Operation Validation', () => {
    it('should allow creating draft when under limit', () => {
      const drafts = Array(30).fill(null).map(() => ({ productIds: [] }));
      const usage = service.calculateUsage(drafts, []);
      const result = service.canCreateDraft(usage);

      expect(result.allowed).toBe(true);
      expect(result.reason).toBeUndefined();
    });

    it('should prevent creating draft when at limit', () => {
      const drafts = Array(50).fill(null).map(() => ({ productIds: [] }));
      const usage = service.calculateUsage(drafts, []);
      const result = service.canCreateDraft(usage);

      expect(result.allowed).toBe(false);
      expect(result.reason).toContain('Maximum drafts reached');
    });

    it('should prevent creating draft when total storage nearly full', () => {
      const drafts = [
        { productIds: Array(490).fill('uuid') }, // 98% of product limit
      ];
      const usage = service.calculateUsage(drafts, []);
      const result = service.canCreateDraft(usage);

      expect(result.allowed).toBe(false);
      expect(result.reason).toContain('Storage nearly full');
    });

    it('should allow adding product when under limit', () => {
      const result = service.canAddProduct(200);

      expect(result.allowed).toBe(true);
    });

    it('should warn when draft has 400+ products', () => {
      const result = service.canAddProduct(420);

      expect(result.allowed).toBe(true);
      expect(result.reason).toContain('Warning');
      expect(result.reason).toContain('420 products');
    });

    it('should prevent adding product when at limit', () => {
      const result = service.canAddProduct(500);

      expect(result.allowed).toBe(false);
      expect(result.reason).toContain('Maximum products per draft');
    });
  });

  describe('Error Cleanup', () => {
    it('should identify old errors for cleanup', () => {
      const twentyFiveHoursAgo = new Date();
      twentyFiveHoursAgo.setHours(twentyFiveHoursAgo.getHours() - 25);

      const shouldCleanup = service.shouldCleanupErrors(twentyFiveHoursAgo);
      expect(shouldCleanup).toBe(true);
    });

    it('should not cleanup recent errors', () => {
      const oneHourAgo = new Date();
      oneHourAgo.setHours(oneHourAgo.getHours() - 1);

      const shouldCleanup = service.shouldCleanupErrors(oneHourAgo);
      expect(shouldCleanup).toBe(false);
    });

    it('should cleanup errors exactly 24 hours old', () => {
      const exactlyTwentyFourHoursAgo = new Date();
      exactlyTwentyFourHoursAgo.setHours(exactlyTwentyFourHoursAgo.getHours() - 24);
      exactlyTwentyFourHoursAgo.setMinutes(exactlyTwentyFourHoursAgo.getMinutes() - 1);

      const shouldCleanup = service.shouldCleanupErrors(exactlyTwentyFourHoursAgo);
      expect(shouldCleanup).toBe(true);
    });
  });

  describe('Health Status', () => {
    it('should report healthy status for low usage', () => {
      const drafts = Array(10).fill(null).map(() => ({
        productIds: Array(50).fill('uuid'),
      }));
      const usage = service.calculateUsage(drafts, []);
      const health = service.getHealthStatus(usage);

      expect(health.status).toBe('healthy');
      expect(health.message).toContain('normal');
    });

    it('should report warning status for high usage', () => {
      const drafts = Array(42).fill(null).map(() => ({ productIds: [] }));
      const usage = service.calculateUsage(drafts, []);
      const health = service.getHealthStatus(usage);

      expect(health.status).toBe('warning');
      expect(health.message).toContain('high');
    });

    it('should report critical status when limit reached', () => {
      const drafts = Array(50).fill(null).map(() => ({ productIds: [] }));
      const usage = service.calculateUsage(drafts, []);
      const health = service.getHealthStatus(usage);

      expect(health.status).toBe('critical');
      expect(health.message).toContain('limit reached');
    });
  });

  describe('Storage Size Estimation', () => {
    it('should estimate storage for typical warehouse scenario', () => {
      // 5 drafts with average 200 products each
      const drafts = Array(5).fill(null).map(() => ({
        productIds: Array(200).fill('uuid-36-chars-xxxxxxxxxxxxxxx'),
      }));
      const pendingCounts = Array(100).fill({});
      const usage = service.calculateUsage(drafts, pendingCounts);

      // Should be under 1 MB for this scenario
      expect(usage.estimatedSize.megabytes).toBeLessThan(1);
      expect(usage.estimatedSize.megabytes).toBeGreaterThan(0);
    });

    it('should estimate storage for large scenario', () => {
      // 30 drafts with 400 products each
      const drafts = Array(30).fill(null).map(() => ({
        productIds: Array(400).fill('uuid'),
      }));
      const pendingCounts = Array(1500).fill({});
      const usage = service.calculateUsage(drafts, pendingCounts);

      // Should be under 5 MB (AsyncStorage limit)
      expect(usage.estimatedSize.megabytes).toBeLessThan(5);
    });
  });
});
