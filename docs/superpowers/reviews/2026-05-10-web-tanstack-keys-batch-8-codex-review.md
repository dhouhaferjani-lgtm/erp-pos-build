---
Commit reviewed: fc767fd3
Date: 2026-05-10
Batch: web.tanstack-keys batch 8
Scope: apps/web/src/features/progression/
Verdict: APPROVE
---

## Seven-Axis Analysis

### 1. Multi-predicate cascade isolation
All query invalidations target progression-scoped predicates only. No cross-feature cache keys are touched. Cascade isolation is clean — invalidating a milestone does not bleed into unrelated query namespaces.

### 2. Milestones not invalidated
Milestone query keys are read-only consumers in this batch. No invalidation of milestone keys is introduced. The batch correctly leaves milestone caching untouched, consistent with the established pattern from batches 1–7.

### 3. Minimal existing-test updates
Test updates are surgical: only counter-cascade assertions and key-predicate expectations are updated. No tests are rewritten from scratch. Net test delta is additive, not destructive.

### 4. Factory-only keys
All new query keys are constructed via the factory pattern established in batch 1. No raw string keys are introduced. Key composition is consistent with the shared factory module.

### 5. Enabled gate
The enabled/disabled gate pattern is correctly applied to all new hooks. Queries are not fired when the feature or tenant context is unavailable. Gate logic is co-located with the hook, not deferred to call sites.

### 6. No `any`
TypeScript strict mode is respected throughout. No `any` types introduced. All new types flow from backend-generated DTOs or explicit generic bounds.

### 7. Scope confined to apps/web/src/features/progression/
No files outside apps/web/src/features/progression/ are modified. The batch is fully self-contained within the declared scope.

---

Verdict: APPROVE
